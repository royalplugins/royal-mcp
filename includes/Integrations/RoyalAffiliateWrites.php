<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Form_Entry_Privacy;
use Royal_MCP\MCP\Support\SafeText;
use Royal_MCP\MCP\Support\Tool_Refusal;
use Royal_MCP\MCP\Undo_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Royal Affiliate Pro, write tools: referral status, a new campaign, a new
 * challenge, a landing page for an affiliate, and a fraud scan of past visits
 * and referrals.
 *
 * Each one previews with dry_run and changes nothing then. The first four
 * return an undo token; the fraud scan only adds log entries and has no undo.
 */
class RoyalAffiliateWrites {

	const TOOLS = [
		'rafp_update_referral_status' => 'update_referral_status',
		'rafp_create_campaign'        => 'create_campaign',
		'rafp_create_challenge'       => 'create_challenge',
		'rafp_assign_landing_page'    => 'assign_landing_page',
		'rafp_scan_fraud'             => 'scan_fraud',
	];

	const MAX_REFERRALS = 25;

	/** Highest percentage a new campaign gets without allow_high_rate. */
	const CAMPAIGN_RATE_CAP = 50;

	/** Highest flat commission per referral (campaign rate or challenge reward) without allow_high_rate. */
	const FLAT_RATE_CAP = 100;

	/** Lowest challenge goal accepted without allow_low_goal. */
	const CHALLENGE_MIN_GOAL = 2;

	/** Largest flat_bonus a challenge grants; the royal_mcp_rafp_max_flat_bonus filter may raise it. */
	const FLAT_BONUS_CAP = 1000;

	/** Statuses this tool sets. "paid" is set by Royal Affiliate when a payout is paid, never from here. */
	const REFERRAL_TARGETS = [ 'pending', 'approved', 'rejected' ];

	const COMMISSION_TYPES = [ 'percentage', 'flat' ];
	const ENTITY_STATUSES  = [ 'active', 'inactive', 'ended' ];
	const METRICS          = [ 'referral_count', 'earnings', 'revenue' ];
	const REWARD_TYPES     = [ 'commission_rate', 'commission_bonus', 'flat_bonus', 'tier_upgrade', 'custom' ];
	/** Royal Affiliate counts a challenge for everyone or for the affiliates with one tag. */
	const SCOPES           = [ 'all', 'tag' ];

	/** Rows of each kind a dry run of the fraud scan looks at. */
	const SCAN_SAMPLE = 25;

	public static function handles( $name ) {
		return is_string( $name ) && isset( self::TOOLS[ $name ] );
	}

	public static function get_tools() {
		return array_values( self::schemas() );
	}

	public static function execute_tool( $name, $args ) {
		if ( ! self::handles( $name ) ) {
			throw new \Exception( 'Unknown Royal Affiliate tool: ' . esc_html( is_string( $name ) ? $name : '' ) );
		}
		self::require_cap();
		$args    = is_array( $args ) ? $args : [];
		$known   = (array) ( self::schemas()[ $name ]['inputSchema']['properties'] ?? [] );
		$unknown = array_diff( array_map( 'strval', array_keys( $args ) ), array_keys( $known ) );
		if ( $unknown ) {
			return Envelope::error( 'invalid_args', 'This tool does not take: ' . implode( ', ', array_slice( array_map( 'sanitize_key', $unknown ), 0, 10 ) ) . '.' );
		}
		return call_user_func( [ __CLASS__, self::TOOLS[ $name ] ], $args );
	}

	/** Commissions and affiliate records are the site owner's business: administrators only. */
	private static function require_cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use the Royal Affiliate tools.' );
		}
		if ( ! RoyalAffiliate::is_available() ) {
			throw new \Exception( 'Royal Affiliate Pro is not active on this site.' );
		}
	}

	// ------------------------------------------------------------------
	// Arguments
	// ------------------------------------------------------------------

	/**
	 * dry_run: true, 1, "1", "true" preview; false, 0, "0", "false", "" and absent apply.
	 *
	 * @return bool|null null when the value is neither.
	 */
	private static function flag( array $args, $key ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			return false;
		}
		$v = is_string( $args[ $key ] ) ? strtolower( trim( $args[ $key ] ) ) : $args[ $key ];
		if ( in_array( $v, [ true, 1, '1', 'true' ], true ) ) {
			return true;
		}
		return in_array( $v, [ false, 0, '0', 'false', '' ], true ) ? false : null;
	}

	/** A switch that must be a real boolean: $default when absent, null for anything but true or false. */
	private static function strict_bool( array $args, $key, $default ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			return $default;
		}
		return is_bool( $args[ $key ] ) ? $args[ $key ] : null;
	}

	/** A name: plain text of 1 to $max characters, or null. */
	private static function name_arg( array $args, $max ) {
		if ( ! isset( $args['name'] ) || ! is_string( $args['name'] ) || mb_strlen( $args['name'] ) > $max ) {
			return null;
		}
		$name = trim( (string) preg_replace( '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', SafeText::field( $args['name'] ) ) );
		return '' === $name ? null : $name;
	}

	/** A description: plain multi-line text of at most 2,000 characters ('' when absent), or null. */
	private static function description_arg( array $args ) {
		if ( ! isset( $args['description'] ) || '' === $args['description'] ) {
			return '';
		}
		return ( is_string( $args['description'] ) && mb_strlen( $args['description'] ) <= 2000 ) ? sanitize_textarea_field( $args['description'] ) : null;
	}

	/** One of a fixed list, the default when absent, or null. */
	private static function choice( array $args, $key, array $allowed, $default ) {
		if ( ! isset( $args[ $key ] ) || '' === $args[ $key ] ) {
			return $default;
		}
		return ( is_string( $args[ $key ] ) && in_array( strtolower( $args[ $key ] ), $allowed, true ) ) ? strtolower( $args[ $key ] ) : null;
	}

	/**
	 * A start or end moment in site time, as Royal Affiliate stores it: '' when absent, false when unusable.
	 * A day alone means its first second (start) or its last (end).
	 */
	private static function moment( array $args, $key, $end ) {
		if ( ! isset( $args[ $key ] ) || '' === $args[ $key ] ) {
			return '';
		}
		$v = $args[ $key ];
		if ( ! is_string( $v ) || strlen( $v ) > 30 ) {
			return false;
		}
		$v = trim( $v );
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
			$d = \DateTime::createFromFormat( '!Y-m-d', $v, wp_timezone() );
			return ( $d && $d->format( 'Y-m-d' ) === $v ) ? $v . ( $end ? ' 23:59:59' : ' 00:00:00' ) : false;
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(Z|[+\-]\d{2}:?\d{2})?$/', $v ) ) {
			return false;
		}
		try {
			$d = new \DateTime( $v, wp_timezone() );
		} catch ( \Exception $e ) {
			return false;
		}
		$d->setTimezone( wp_timezone() );
		return $d->format( 'Y-m-d H:i:s' );
	}

	/** A non-negative amount with at most 2 decimals (what the plugin's tables keep), or null. */
	private static function amount( $v, $max ) {
		if ( is_string( $v ) ) {
			$v = 1 === preg_match( '/^\d{1,12}(\.\d{1,2})?$/', trim( $v ) ) ? (float) trim( $v ) : null;
		}
		if ( ! is_int( $v ) && ! is_float( $v ) ) {
			return null;
		}
		$v = (float) $v;
		return ( is_finite( $v ) && $v >= 0 && $v <= $max && abs( $v * 100 - round( $v * 100 ) ) < 0.000001 ) ? round( $v, 2 ) : null;
	}

	private static function table_has( $table, $where_col, $id ) {
		global $wpdb;
		$name = $wpdb->prefix . $table;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $name !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table and column names from this class
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$name}` WHERE `{$where_col}` = %d", (int) $id ) );
	}

	// ------------------------------------------------------------------
	// rafp_update_referral_status
	// ------------------------------------------------------------------

	/** Why a referral must be left alone: it is paid, or already on a payout. null when it may change. */
	private static function lock_reason( array $row ) {
		if ( 'paid' === strtolower( trim( (string) ( $row['status'] ?? '' ) ) ) ) {
			return 'paid';
		}
		return (int) ( $row['payout_id'] ?? 0 ) > 0 ? 'on_a_payout' : null;
	}

	private static function referral_row( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, payout_id, affiliate_id, date_approved FROM {$wpdb->prefix}rafp_referrals WHERE referral_id = %d", (int) $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * The referrals Royal Affiliate changes together with one: the commissions its upline affiliates
	 * earn on it (multi-tier). referral id => [ status, payout_id, affiliate_id ].
	 */
	private static function tier_referrals( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT referral_id, status, payout_id, affiliate_id, date_approved FROM {$wpdb->prefix}rafp_referrals WHERE parent_referral_id = %d ORDER BY referral_id LIMIT 50", (int) $id ), ARRAY_A );
		return array_column( $rows, null, 'referral_id' );
	}

	/** Challenge progress of some affiliates: "challenge:affiliate" => [ value, completed ]. */
	private static function progress_of( array $affiliate_ids ) {
		global $wpdb;
		$ids   = array_values( array_unique( array_filter( array_map( 'intval', $affiliate_ids ) ) ) );
		$table = $wpdb->prefix . 'rafp_challenge_progress';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $ids || $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return [];
		}
		$out = [];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- ids cast to int above
		foreach ( (array) $wpdb->get_results( "SELECT challenge_id, affiliate_id, current_value, completed FROM `{$table}` WHERE affiliate_id IN (" . implode( ',', $ids ) . ') LIMIT 5000', ARRAY_A ) as $r ) {
			$out[ (int) $r['challenge_id'] . ':' . (int) $r['affiliate_id'] ] = [ (float) $r['current_value'], (int) $r['completed'] ];
		}
		return $out;
	}

	/**
	 * Runs $fn with Royal Affiliate's challenge counting switched off, for a status that was already
	 * counted once (putting a referral back to approved must not count it a second time).
	 */
	/**
	 * Runs $fn with two of Royal Affiliate's status listeners switched off while an undo puts
	 * statuses back: challenge counting (a status already counted once must not count again) and
	 * the multi-tier cascade (each upline referral is put back to its own earlier status and
	 * approval date instead, without the cascade's new dates and emails).
	 */
	private static function without_status_listeners( callable $fn ) {
		$hook = 'rafp_referral_status_changed';
		$off  = [];
		foreach ( [ [ 'Royal_Affiliate_Challenge_Progress', 'on_referral_status_changed' ], [ 'Royal_Affiliate_Multi_Tier', 'process_status_change' ] ] as $cb ) {
			$prio = has_action( $hook, $cb );
			if ( false !== $prio ) {
				remove_action( $hook, $cb, $prio );
				$off[] = [ $cb, $prio ];
			}
		}
		try {
			return $fn();
		} finally {
			foreach ( $off as $was ) {
				add_action( $hook, $was[0], $was[1], 2 );
			}
		}
	}

	/** A date_approved value from a snapshot (a MySQL date-time or null), or false when it is not one. */
	private static function snapshot_date( $value ) {
		if ( null === $value ) {
			return null;
		}
		return ( is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) ? $value : false;
	}

	/** Runs $fn and returns [ its result, how many emails WordPress was asked to send meanwhile ]. */
	private static function counting_mail( callable $fn ) {
		$sent  = 0;
		$count = static function ( $pre ) use ( &$sent ) {
			$sent++;
			return $pre;
		};
		add_filter( 'pre_wp_mail', $count, 0 );
		try {
			return [ $fn(), $sent ];
		} finally {
			remove_filter( 'pre_wp_mail', $count, 0 );
		}
	}

	private static function update_referral_status( array $args ) {
		$dry = self::flag( $args, 'dry_run' );
		$new = self::choice( $args, 'new_status', self::REFERRAL_TARGETS, null );
		$raw    = $args['referral_ids'] ?? null;
		$reopen = self::strict_bool( $args, 'reopen_rejected', false );
		if ( null === $dry || null === $new || null === $reopen || ! is_array( $raw ) || ! $raw || count( $raw ) > self::MAX_REFERRALS || array_keys( $raw ) !== range( 0, count( $raw ) - 1 ) ) {
			return Envelope::error( 'invalid_args', sprintf( 'referral_ids must be a list of 1 to %d referral IDs, new_status one of %s (paid is set by Royal Affiliate when a payout is paid), and dry_run and reopen_rejected true or false.', self::MAX_REFERRALS, implode( ', ', self::REFERRAL_TARGETS ) ) );
		}
		$ids = [];
		foreach ( $raw as $one ) {
			$id = Form_Entry_Privacy::positive_int( $one );
			if ( $id <= 0 ) {
				return Envelope::error( 'invalid_args', 'referral_ids must hold positive whole numbers only.' );
			}
			$ids[ $id ] = $id;
		}
		$change     = [];
		$rows_of    = [];
		$tiers      = [];
		$same       = [];
		$skipped    = [];
		$affiliates = [];
		foreach ( $ids as $id ) {
			$row = self::referral_row( $id );
			$why = null === $row ? 'not_found' : self::lock_reason( $row );
			// A rejected referral moves again only when the caller says so in so many words.
			if ( null === $why && ! $reopen && 'rejected' === strtolower( trim( (string) $row['status'] ) ) && 'rejected' !== $new ) {
				$why = 'rejected_needs_reopen_rejected';
			}
			$kin = null === $why ? self::tier_referrals( $id ) : [];
			// Royal Affiliate gives a referral's upline commissions the same status. One of those being paid or on a payout locks the whole set.
			foreach ( $kin as $k ) {
				if ( null === $why && null !== self::lock_reason( $k ) ) {
					$why = 'tier_referral_paid_or_on_a_payout';
				}
			}
			if ( null !== $why ) {
				$skipped[] = [ 'referral_id' => $id, 'reason' => $why ];
			} elseif ( $new === (string) $row['status'] ) {
				$same[] = $id;
			} else {
				$change[ $id ]  = (string) $row['status'];
				$rows_of[ $id ] = $row;
				$affiliates[]   = (int) $row['affiliate_id'];
				foreach ( $kin as $kid => $k ) {
					$rows_of[ (int) $kid ]      = $k;
					$tiers[ $id ][ (int) $kid ] = (string) $k['status'];
					$affiliates[]               = (int) $k['affiliate_id'];
				}
			}
		}
		$listing = static function ( array $set ) use ( $tiers ) {
			$out = [];
			foreach ( $set as $id => $from ) {
				$out[] = [ 'referral_id' => $id, 'from' => $from, 'tier_referral_ids' => array_keys( $tiers[ $id ] ?? [] ) ];
			}
			return $out;
		};
		$note = 'approved' === $new ? ' Approving can make Royal Affiliate email the affiliate and counts toward its challenges; an undo recalls neither an email nor a challenge reward already granted.' : '';
		$also = $tiers ? sprintf( ' %d upline (multi-tier) referral(s) change with them.', array_sum( array_map( 'count', $tiers ) ) ) : '';
		if ( $dry ) {
			return Envelope::success(
				sprintf( 'Preview: %d referral(s) would become %s, %d already are, %d would be left alone.%s Nothing was changed.%s', count( $change ), $new, count( $same ), count( $skipped ), $also, $note ),
				[ 'dry_run' => true, 'new_status' => $new, 'would_change' => $listing( $change ), 'unchanged_ids' => $same, 'skipped' => $skipped ]
			);
		}
		// Challenge progress and rewards are measured around each referral's own change, so an undo
		// can take off exactly what the referrals it puts back added. Royal Affiliate grants a reward
		// each time it reports a challenge completed; a recurring challenge keeps doing so past its goal.
		$progress_by_ref = [];
		$progress_floor  = [];
		$rewards_by_ref  = [];
		$completions     = 0;
		$on_completed    = static function () use ( &$completions ) {
			$completions++;
		};
		add_action( 'rafp_challenge_completed', $on_completed, 1, 0 );
		try {
			list( $done, $mails ) = self::counting_mail( static function () use ( $change, $new, $tiers, $rows_of, &$skipped, &$progress_by_ref, &$progress_floor, &$rewards_by_ref, &$completions ) {
				$done = [];
				foreach ( $change as $id => $from ) {
					$watch = [ (int) ( $rows_of[ $id ]['affiliate_id'] ?? 0 ) ];
					foreach ( (array) ( $tiers[ $id ] ?? [] ) as $kid => $unused ) {
						$watch[] = (int) ( $rows_of[ $kid ]['affiliate_id'] ?? 0 );
					}
					$before      = self::progress_of( $watch );
					$completions = 0;
					if ( ! \Royal_Affiliate_Referral::update_status( $id, $new ) ) {
						$skipped[] = [ 'referral_id' => $id, 'reason' => 'not_saved' ];
						continue;
					}
					$done[ $id ] = $from;
					$flips       = 0;
					foreach ( self::progress_of( $watch ) as $key => $now ) {
						$was   = $before[ $key ] ?? [ 0.0, 0 ];
						$delta = round( $now[0] - $was[0], 2 );
						if ( $delta > 0 ) {
							$progress_by_ref[ $id ][ $key ] = $delta;
							if ( ! array_key_exists( $key, $progress_floor ) ) {
								$progress_floor[ $key ] = $was[0];
							}
						}
						if ( 1 === $now[1] && 1 !== $was[1] ) {
							$flips++;
						}
					}
					// A completion seen only in the progress table counts as well.
					$granted = max( $completions, $flips );
					if ( $granted > 0 ) {
						$rewards_by_ref[ $id ] = $granted;
					}
				}
				return $done;
			} );
		} finally {
			remove_action( 'rafp_challenge_completed', $on_completed, 1 );
		}
		$rewards = (int) array_sum( $rewards_by_ref );
		$kept    = array_intersect_key( $tiers, $done );
		$dates   = [];
		$kdates  = [];
		foreach ( array_keys( $done ) as $id ) {
			$dates[ $id ] = $rows_of[ $id ]['date_approved'] ?? null;
			foreach ( array_keys( (array) ( $kept[ $id ] ?? [] ) ) as $kid ) {
				$kdates[ (int) $kid ] = $rows_of[ $kid ]['date_approved'] ?? null;
			}
		}
		$undo = $done ? Undo_Store::store( [
			'op'           => 'rafp_update_referral_status',
			'summary'      => sprintf( 'Put %d referral(s) back to their earlier status', count( $done ) ),
			'target'       => [ 'referral_ids' => array_keys( $done ) ],
			'pre_op_state' => [
				'rows'            => $done,
				'written'         => $new,
				'tiers'           => $kept,
				'dates'           => $dates,
				'tier_dates'      => $kdates,
				'progress_by_ref' => array_intersect_key( $progress_by_ref, $done ),
				'progress_floor'  => $progress_floor,
				'rewards_by_ref'  => array_intersect_key( $rewards_by_ref, $done ),
			],
		] ) : null;
		return Envelope::success(
			sprintf( '%d referral(s) set to %s, %d already were, %d left alone.%s%s%s', count( $done ), $new, count( $same ), count( $skipped ), $done ? $also : '', $rewards ? sprintf( ' %d challenge(s) were completed by this and their reward granted; an undo does not take a reward back.', $rewards ) : '', $done ? $note : '' ),
			[ 'dry_run' => false, 'new_status' => $new, 'changed' => $listing( $done ), 'unchanged_ids' => $same, 'skipped' => $skipped, 'emails_sent' => $mails, 'challenge_progress_rows_changed' => count( $progress_floor ), 'challenge_rewards_granted' => $rewards ],
			$undo ?: null
		);
	}

	private static function undo_referral_status( array $snapshot, $force ) {
		global $wpdb;
		$pre      = (array) ( $snapshot['pre_op_state'] ?? [] );
		$rows     = (array) ( $pre['rows'] ?? [] );
		$written  = (string) ( $pre['written'] ?? '' );
		$tiers    = (array) ( $pre['tiers'] ?? [] );
		$by_ref   = isset( $pre['progress_by_ref'] ) && is_array( $pre['progress_by_ref'] ) ? $pre['progress_by_ref'] : null;
		$floor    = (array) ( $pre['progress_floor'] ?? [] );
		$dates    = (array) ( $pre['dates'] ?? [] );
		$kdates   = (array) ( $pre['tier_dates'] ?? [] );
		// An older snapshot carries one progress total per challenge instead of a share per referral.
		$progress = null === $by_ref ? (array) ( $pre['progress'] ?? [] ) : [];
		if ( ! $rows || ! in_array( $written, self::REFERRAL_TARGETS, true ) ) {
			throw new \Exception( 'Undo snapshot missing the referrals.' );
		}
		$plan    = [];
		$skipped = [];
		$drifted = [];
		foreach ( $rows as $id => $prior ) {
			$id  = (int) $id;
			$row = self::referral_row( $id );
			$why = null === $row ? 'not_found' : self::lock_reason( $row );
			foreach ( null === $why ? self::tier_referrals( $id ) : [] as $k ) {
				if ( null === $why && null !== self::lock_reason( $k ) ) {
					$why = 'tier_referral_paid_or_on_a_payout';
				}
			}
			if ( null === $why && ! in_array( (string) $prior, self::REFERRAL_TARGETS, true ) ) {
				$why = 'earlier_status_cannot_be_set';
			}
			if ( null !== $why ) {
				$skipped[] = [ 'referral_id' => $id, 'reason' => $why ];
				continue;
			}
			if ( $written !== (string) $row['status'] ) {
				$drifted[] = $id;
			}
			$plan[ $id ] = (string) $prior;
		}
		if ( $drifted && ! $force ) {
			throw new Tool_Refusal( 'edited_since_write', esc_html( sprintf( '%d of these referrals changed status again after this write, so the undo changed nothing. Pass force: true to put the ones that are not paid or on a payout back to their earlier status anyway.', count( $drifted ) ) ), [ 'changed_since' => array_slice( $drifted, 0, 100 ) ] );
		}
		$won = null !== $by_ref
			? (int) array_sum( array_map( 'intval', (array) ( $pre['rewards_by_ref'] ?? [] ) ) )
			: count( array_filter( $progress, static function ( $p ) { return ! empty( $p['completed'] ); } ) );
		if ( $won && ! $force ) {
			throw new Tool_Refusal( 'reward_granted', esc_html( sprintf( 'That change completed %d challenge(s) and Royal Affiliate granted the reward, which an undo cannot take back. Nothing was changed. Pass force: true to put the referrals back anyway; the reward stays and has to be removed in Royal Affiliate by hand.', $won ) ) );
		}
		list( $restored, $mails ) = self::counting_mail( static function () use ( $plan, $tiers, $dates, $kdates, &$skipped, $wpdb ) {
			// These statuses were counted toward challenges before the write; putting them back must not
			// count them again, nor run the multi-tier cascade (upline referrals are put back below).
			return self::without_status_listeners( static function () use ( $plan, $tiers, $dates, $kdates, &$skipped, $wpdb ) {
				$restored = [];
				foreach ( $plan as $id => $prior ) {
					if ( ! \Royal_Affiliate_Referral::update_status( $id, $prior ) ) {
						$skipped[] = [ 'referral_id' => $id, 'reason' => 'not_saved' ];
						continue;
					}
					// Putting a referral back to approved stamps it with now; it gets its own approval date back.
					$date = array_key_exists( $id, $dates ) ? self::snapshot_date( $dates[ $id ] ) : false;
					if ( false !== $date ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Royal Affiliate's own table
						$wpdb->update( $wpdb->prefix . 'rafp_referrals', [ 'date_approved' => $date ], [ 'referral_id' => (int) $id ], [ '%s' ], [ '%d' ] );
					}
					$kids = [];
					// Each upline referral goes back to its own earlier status and approval date.
					foreach ( (array) ( $tiers[ $id ] ?? [] ) as $kid => $own ) {
						$now = self::referral_row( (int) $kid );
						$kids[] = (int) $kid;
						if ( ! $now || null !== self::lock_reason( $now ) || ! in_array( (string) $own, self::REFERRAL_TARGETS, true ) ) {
							continue;
						}
						$set = [];
						if ( (string) $own !== (string) $now['status'] ) {
							$set['status'] = (string) $own;
						}
						$kdate = array_key_exists( (int) $kid, $kdates ) ? self::snapshot_date( $kdates[ (int) $kid ] ) : false;
						if ( false !== $kdate && $kdate !== ( isset( $now['date_approved'] ) ? (string) $now['date_approved'] : null ) ) {
							$set['date_approved'] = $kdate;
						}
						if ( $set ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Royal Affiliate's own table, written the way its cascade writes it
							$wpdb->update( $wpdb->prefix . 'rafp_referrals', $set, [ 'referral_id' => (int) $kid ], array_fill( 0, count( $set ), '%s' ), [ '%d' ] );
						}
					}
					$restored[] = [ 'referral_id' => $id, 'restored_to' => $prior, 'tier_referral_ids' => $kids ];
				}
				return $restored;
			} );
		} );
		// Take off the challenge progress the restored referrals' changes added (recurring challenges
		// included), never below the value captured when the status changed.
		$taken = 0;
		if ( null !== $by_ref ) {
			$sum = [];
			foreach ( $restored as $r ) {
				foreach ( (array) ( $by_ref[ (int) $r['referral_id'] ] ?? [] ) as $key => $delta ) {
					$sum[ (string) $key ] = ( $sum[ (string) $key ] ?? 0.0 ) + (float) $delta;
				}
			}
			foreach ( $sum as $key => $delta ) {
				$parts = array_map( 'intval', explode( ':', $key ) );
				$delta = round( $delta, 2 );
				if ( 2 === count( $parts ) && $delta > 0 ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$taken += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}rafp_challenge_progress SET current_value = GREATEST( current_value - %f, LEAST( current_value, %f ) ) WHERE challenge_id = %d AND affiliate_id = %d", $delta, (float) ( $floor[ $key ] ?? 0 ), $parts[0], $parts[1] ) );
				}
			}
		} elseif ( $restored && 'approved' === $written ) {
			foreach ( $progress as $key => $p ) {
				$parts = array_map( 'intval', explode( ':', (string) $key ) );
				$delta = round( (float) ( $p['delta'] ?? 0 ), 2 );
				if ( 2 === count( $parts ) && $delta > 0 && empty( $p['completed'] ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$taken += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}rafp_challenge_progress SET current_value = GREATEST( 0, current_value - %f ) WHERE challenge_id = %d AND affiliate_id = %d AND completed = 0", $delta, $parts[0], $parts[1] ) );
				}
			}
		}
		return Envelope::success(
			sprintf( '%d referral(s) put back to their earlier status, %d left alone.%s%s', count( $restored ), count( $skipped ), $mails ? sprintf( ' Royal Affiliate sent %d email(s) while doing so.', $mails ) : '', $won ? sprintf( ' %d challenge reward(s) granted by the original change stay.', $won ) : '' ),
			[ 'undone' => (bool) $restored, 'op' => 'rafp_update_referral_status', 'target' => (array) ( $snapshot['target'] ?? [] ), 'restored' => $restored, 'skipped' => $skipped, 'emails_sent' => $mails, 'challenge_progress_rows_corrected' => $taken, 'challenge_rewards_kept' => $won ]
		);
	}

	// ------------------------------------------------------------------
	// rafp_create_campaign
	// ------------------------------------------------------------------

	private static function create_campaign( array $args ) {
		$dry    = self::flag( $args, 'dry_run' );
		$name   = self::name_arg( $args, 100 );
		$desc   = self::description_arg( $args );
		$type   = self::choice( $args, 'commission_type', self::COMMISSION_TYPES, 'percentage' );
		$status = self::choice( $args, 'status', self::ENTITY_STATUSES, 'inactive' );
		$start  = self::moment( $args, 'start_date', false );
		$end    = self::moment( $args, 'end_date', true );
		$high   = self::strict_bool( $args, 'allow_high_rate', false );
		$rate   = null;
		if ( null === $high ) {
			return Envelope::error( 'invalid_args', 'allow_high_rate must be true or false.' );
		}
		if ( isset( $args['commission_rate'] ) && '' !== $args['commission_rate'] ) {
			$rate = null === $type ? null : self::amount( $args['commission_rate'], 'percentage' === $type ? 100 : 999.99 );
			if ( null === $rate ) {
				return Envelope::error( 'invalid_args', 'commission_rate must be a number from 0 to 100 for a percentage, or from 0 to 999.99 for a flat amount (the most Royal Affiliate stores for a campaign), with at most 2 decimals.' );
			}
			if ( 'percentage' === $type && $rate > self::CAMPAIGN_RATE_CAP && ! $high ) {
				return Envelope::error( 'rate_above_cap', sprintf( 'A percentage commission above %d%% needs allow_high_rate: true. Nothing was created.', self::CAMPAIGN_RATE_CAP ) );
			}
			if ( 'flat' === $type && $rate > self::FLAT_RATE_CAP && ! $high ) {
				return Envelope::error( 'rate_above_cap', sprintf( 'A flat commission above %d per referral needs allow_high_rate: true. Nothing was created.', self::FLAT_RATE_CAP ) );
			}
		}
		$slug = '';
		if ( isset( $args['slug'] ) && '' !== $args['slug'] ) {
			$slug = is_string( $args['slug'] ) && strlen( $args['slug'] ) <= 100 ? sanitize_title( $args['slug'] ) : '';
			if ( '' === $slug ) {
				return Envelope::error( 'invalid_args', 'slug must be a short text that leaves letters or digits once cleaned.' );
			}
		}
		if ( null === $dry || null === $name || null === $desc || null === $type || null === $status || false === $start || false === $end || ( '' !== $start && '' !== $end && $start > $end ) ) {
			return Envelope::error( 'invalid_args', 'name is required (1 to 100 characters); description at most 2,000 characters; commission_type percentage or flat; status active, inactive or ended; start_date and end_date as 2026-10-01 or 2026-10-01T09:00 in site time, start not after end; dry_run true or false.' );
		}
		$data = array_filter( [ 'name' => $name, 'slug' => $slug, 'description' => $desc, 'commission_rate' => $rate, 'commission_type' => $type, 'status' => $status, 'start_date' => '' !== $start ? $start : null, 'end_date' => '' !== $end ? $end : null ], static function ( $v ) { return null !== $v; } );
		$taken = '' !== $slug && \Royal_Affiliate_Campaign::get_by_slug( $slug );
		if ( $dry ) {
			return Envelope::success( sprintf( 'Preview: campaign "%s" would be created (%s, status %s). Nothing was changed.', $name, $type, $status ), [ 'dry_run' => true, 'campaign' => $data, 'slug_taken' => (bool) $taken, 'timezone' => wp_timezone_string() ] );
		}
		$id  = (int) \Royal_Affiliate_Campaign::create( $data );
		$row = $id > 0 ? \Royal_Affiliate_Campaign::get( $id ) : null;
		if ( ! is_object( $row ) ) {
			return Envelope::error( 'write_failed', 'Royal Affiliate did not save the campaign.' );
		}
		$undo = Undo_Store::store( [
			'op'           => 'rafp_create_campaign',
			'summary'      => sprintf( 'Delete campaign %d ("%s") made by this call', $id, $name ),
			'target'       => [ 'campaign_id' => $id ],
			'pre_op_state' => [ 'fingerprint' => self::fingerprint( $row ) ],
		] );
		// What Royal Affiliate stored, not what was asked for.
		$stored = [ 'name' => (string) $row->name, 'slug' => sanitize_title( (string) $row->slug ), 'description' => (string) $row->description, 'commission_rate' => null === $row->commission_rate ? null : round( (float) $row->commission_rate, 2 ), 'commission_type' => (string) $row->commission_type, 'status' => (string) $row->status, 'start_date' => $row->start_date ? (string) $row->start_date : null, 'end_date' => $row->end_date ? (string) $row->end_date : null ];
		return Envelope::success(
			sprintf( 'Created campaign %d "%s" (slug %s, status %s).', $id, $stored['name'], $stored['slug'], $stored['status'] ),
			[ 'dry_run' => false, 'campaign_id' => $id, 'slug' => $stored['slug'], 'slug_changed' => '' !== $slug && $slug !== $stored['slug'], 'campaign' => $stored, 'timezone' => wp_timezone_string() ],
			$undo ?: null
		);
	}

	/** What tells an untouched row from one edited after it was made. */
	private static function fingerprint( $row ) {
		$row = (array) $row;
		unset( $row['date_created'], $row['date_modified'] );
		ksort( $row );
		return md5( (string) wp_json_encode( array_map( 'strval', array_filter( $row, 'is_scalar' ) ) ) );
	}

	private static function undo_created( array $snapshot, $force, $what ) {
		$is_campaign = 'campaign' === $what;
		$class       = $is_campaign ? 'Royal_Affiliate_Campaign' : 'Royal_Affiliate_Challenge';
		$key         = $what . '_id';
		$op          = 'rafp_create_' . $what;
		$id          = (int) ( $snapshot['target'][ $key ] ?? 0 );
		if ( $id <= 0 || ! class_exists( $class ) ) {
			throw new \Exception( 'Undo snapshot missing the ' . $what . ', or this Royal Affiliate version has none.' );
		}
		$target = [ $key => $id ];
		$row    = call_user_func( [ $class, 'get' ], $id );
		if ( ! is_object( $row ) ) {
			return Envelope::success( sprintf( 'The %s was already deleted; nothing to undo.', $what ), [ 'undone' => false, 'op' => $op, 'target' => $target, 'reason' => 'deleted_since_write' ] );
		}
		$used = $is_campaign
			? [ 'referrals' => self::table_has( 'rafp_referrals', 'campaign_id', $id ), 'visits' => self::table_has( 'rafp_visits', 'campaign_id', $id ), 'creatives' => self::table_has( 'rafp_creatives', 'campaign_id', $id ) ]
			: [ 'affiliates_with_progress' => self::table_has( 'rafp_challenge_progress', 'challenge_id', $id ) ];
		if ( array_sum( $used ) > 0 && ! $force ) {
			throw new Tool_Refusal( 'in_use', esc_html( sprintf( 'The %s is in use since it was made (%s), so the undo left it in place. Pass force: true to delete it anyway%s.', $what, implode( ', ', array_map( static function ( $k, $n ) { return $n . ' ' . str_replace( '_', ' ', $k ); }, array_keys( array_filter( $used ) ), array_filter( $used ) ) ), $is_campaign ? '; its referrals, visits and creatives stay, pointing at a campaign that no longer exists' : ', with that progress' ) ), [ 'in_use' => $used ] );
		}
		$was = (string) ( $snapshot['pre_op_state']['fingerprint'] ?? '' );
		if ( ! $force && ( '' === $was || ! hash_equals( $was, self::fingerprint( $row ) ) ) ) {
			throw new Tool_Refusal( 'edited_since_write', esc_html( sprintf( 'The %s was edited after it was made, so the undo left it in place. Pass force: true to delete it anyway.', $what ) ) );
		}
		if ( ! call_user_func( [ $class, 'delete' ], $id ) ) {
			throw new \Exception( 'Royal Affiliate could not delete the ' . $what . '; nothing was undone.' );
		}
		$kept = ( ! $is_campaign && array_sum( $used ) > 0 ) ? ' Rewards it already granted (bonus commissions, rate changes, tier changes) stay and have to be removed in Royal Affiliate by hand.' : '';
		return Envelope::success( sprintf( 'Deleted %s %d ("%s").%s', $what, $id, mb_substr( SafeText::field( (string) ( $row->name ?? '' ) ), 0, 100 ), $kept ), [ 'undone' => true, 'op' => $op, 'target' => $target, 'was_in_use' => $used ] );
	}

	// ------------------------------------------------------------------
	// rafp_create_challenge
	// ------------------------------------------------------------------

	private static function create_challenge( array $args ) {
		if ( ! class_exists( 'Royal_Affiliate_Challenge' ) ) {
			return Envelope::error( 'not_supported', 'This Royal Affiliate version has no challenges.' );
		}
		// A challenge grants money by itself, so the call previews unless dry_run is false.
		$dry    = array_key_exists( 'dry_run', $args ) && null !== $args['dry_run'] ? self::flag( $args, 'dry_run' ) : true;
		$rec    = self::strict_bool( $args, 'recurring', false );
		$low    = self::strict_bool( $args, 'allow_low_goal', false );
		$high_rate = self::strict_bool( $args, 'allow_high_rate', false );
		if ( null === $high_rate ) {
			return Envelope::error( 'invalid_args', 'allow_high_rate must be true or false.' );
		}
		$name   = self::name_arg( $args, 200 );
		$desc   = self::description_arg( $args );
		$metric = self::choice( $args, 'metric', self::METRICS, 'referral_count' );
		$reward = self::choice( $args, 'reward_type', self::REWARD_TYPES, 'custom' );
		$scope  = self::choice( $args, 'scope', self::SCOPES, 'all' );
		$status = self::choice( $args, 'status', self::ENTITY_STATUSES, 'inactive' );
		$start  = self::moment( $args, 'start_date', false );
		$end    = self::moment( $args, 'end_date', true );
		$goal   = isset( $args['goal'] ) ? self::amount( $args['goal'], 1000000000 ) : null;
		if ( null === $dry || null === $rec || null === $low || null === $name || null === $desc || null === $metric || null === $reward || null === $scope || null === $status || false === $start || false === $end || ( '' !== $start && '' !== $end && $start > $end ) || null === $goal || $goal < 0.01 || ( 'referral_count' === $metric && floor( $goal ) !== $goal ) ) {
			return Envelope::error( 'invalid_args', 'name (1 to 200 characters) and goal are required; goal is at least 0.01 with at most 2 decimals, and a whole number for referral_count; metric is one of ' . implode( ', ', self::METRICS ) . '; reward_type one of ' . implode( ', ', self::REWARD_TYPES ) . '; scope all or tag; status active, inactive or ended; start_date and end_date in site time, start not after end; dry_run true or false; recurring and allow_low_goal true or false (booleans, not text).' );
		}
		if ( $goal < self::CHALLENGE_MIN_GOAL && ! $low ) {
			return Envelope::error( 'goal_too_low', sprintf( 'A goal below %d is met almost at once; pass allow_low_goal: true to create it anyway. Nothing was created.', self::CHALLENGE_MIN_GOAL ) );
		}
		$bonus_cap = (float) apply_filters( 'royal_mcp_rafp_max_flat_bonus', self::FLAT_BONUS_CAP );
		$bonus_cap = is_finite( $bonus_cap ) && $bonus_cap > 0 ? min( $bonus_cap, 100000 ) : self::FLAT_BONUS_CAP;
		// Only the values Royal Affiliate reads for that kind of reward, each of the kind it expects.
		$rv  = isset( $args['reward_value'] ) ? json_decode( (string) wp_json_encode( $args['reward_value'] ), true ) : [];
		$num = static function ( $v, $max ) {
			$n = self::amount( $v, $max );
			return ( null !== $n && $n > 0 && ! is_string( $v ) ) ? $n : null;
		};
		$bad = ! is_array( $rv ) || ( $rv && array_keys( $rv ) === range( 0, count( $rv ) - 1 ) );
		$out = [];
		if ( ! $bad && in_array( $reward, [ 'commission_rate', 'commission_bonus' ], true ) ) {
			$kind = $rv['type'] ?? 'percentage';
			$rate = in_array( $kind, self::COMMISSION_TYPES, true ) ? $num( $rv['rate'] ?? null, 'percentage' === $kind ? 100 : 999.99 ) : null;
			$bad  = null === $rate || array_diff( array_keys( $rv ), [ 'rate', 'type' ] );
			$out  = [ 'rate' => $rate, 'type' => $kind ];
		} elseif ( ! $bad && 'flat_bonus' === $reward ) {
			$amount = $num( $rv['amount'] ?? null, $bonus_cap );
			$bad    = null === $amount || array_diff( array_keys( $rv ), [ 'amount' ] );
			$out    = [ 'amount' => $amount ];
		} elseif ( ! $bad && 'tier_upgrade' === $reward ) {
			$tier = isset( $rv['tier_id'] ) && is_int( $rv['tier_id'] ) ? $rv['tier_id'] : 0;
			$bad  = $tier <= 0 || ! self::table_has( 'rafp_tiers', 'tier_id', $tier ) || array_diff( array_keys( $rv ), [ 'tier_id' ] );
			$out  = [ 'tier_id' => $tier ];
		} elseif ( ! $bad ) {
			$text = $rv['text'] ?? '';
			$bad  = ! is_string( $text ) || mb_strlen( $text ) > 500 || array_diff( array_keys( $rv ), [ 'text' ] );
			$out  = '' !== trim( (string) $text ) ? [ 'text' => sanitize_text_field( (string) $text ) ] : [];
		}
		if ( ! $bad && isset( $out['rate'], $out['type'] ) && ! $high_rate ) {
			$cap = 'percentage' === $out['type'] ? self::CAMPAIGN_RATE_CAP : self::FLAT_RATE_CAP;
			if ( $out['rate'] > $cap ) {
				return Envelope::error( 'rate_above_cap', sprintf( 'A %s reward rate above %s needs allow_high_rate: true. Nothing was created.', $out['type'], 'percentage' === $out['type'] ? $cap . '%' : (string) $cap ) );
			}
		}
		if ( $bad ) {
			return Envelope::error( 'invalid_args', 'reward_value must fit reward_type: commission_rate and commission_bonus take {"rate": number above 0, "type": "percentage" (up to 100) or "flat" (up to 999.99)}; flat_bonus takes {"amount": number above 0, up to ' . rtrim( rtrim( number_format( $bonus_cap, 2, '.', '' ), '0' ), '.' ) . '}; tier_upgrade takes {"tier_id": an existing tier}; custom takes {"text": up to 500 characters} or nothing. Numbers must be numbers, not text.' );
		}
		$value       = $out ? (string) wp_json_encode( $out ) : '{}';
		$scope_value = null;
		$scope_note  = null;
		if ( 'tag' === $scope ) {
			global $wpdb;
			$sid = Form_Entry_Privacy::positive_int( $args['scope_value'] ?? null );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$tag = $sid > 0 && self::table_has( 'rafp_tags', 'tag_id', $sid ) ? $wpdb->get_row( $wpdb->prepare( "SELECT tag_id, slug, name FROM {$wpdb->prefix}rafp_tags WHERE tag_id = %d", $sid ), ARRAY_A ) : null;
			if ( ! is_array( $tag ) || '' === (string) $tag['slug'] ) {
				return Envelope::error( 'invalid_args', 'scope_value must be the ID of an existing tag when scope is tag.' );
			}
			// Royal Affiliate looks the tag up by its slug first, so the slug is what is stored.
			$scope_value = (string) $tag['slug'];
			$scope_note  = [ 'tag_id' => (int) $tag['tag_id'], 'tag_slug' => sanitize_title( (string) $tag['slug'] ) ];
		} elseif ( isset( $args['scope_value'] ) && '' !== $args['scope_value'] ) {
			return Envelope::error( 'invalid_args', 'scope_value is only used with scope tag.' );
		}
		$data = [ 'name' => $name, 'description' => $desc, 'metric' => $metric, 'goal' => $goal, 'reward_type' => $reward, 'reward_value' => $value, 'scope' => $scope, 'scope_value' => $scope_value, 'recurring' => $rec ? 1 : 0, 'status' => $status, 'start_date' => '' !== $start ? $start : null, 'end_date' => '' !== $end ? $end : null ];
		$show = [ 'reward_value' => json_decode( $value, true ) ?: new \stdClass(), 'recurring' => (bool) $rec, 'scope_tag' => $scope_note ] + $data;
		$warn = 'active' === $status ? ' It is active: Royal Affiliate counts approvals toward it from now on and grants the reward by itself when an affiliate reaches the goal.' : '';
		if ( $dry ) {
			return Envelope::success( sprintf( 'Preview: challenge "%s" would be created (%s, goal %s, reward %s, status %s). Nothing was changed; pass dry_run: false to create it.%s', $name, $metric, rtrim( rtrim( number_format( $goal, 4, '.', '' ), '0' ), '.' ), $reward, $status, $warn ), [ 'dry_run' => true, 'challenge' => $show, 'timezone' => wp_timezone_string() ] );
		}
		$id  = (int) \Royal_Affiliate_Challenge::create( $data );
		$row = $id > 0 ? \Royal_Affiliate_Challenge::get( $id ) : null;
		if ( ! is_object( $row ) ) {
			return Envelope::error( 'write_failed', 'Royal Affiliate did not save the challenge.' );
		}
		$undo = Undo_Store::store( [
			'op'           => 'rafp_create_challenge',
			'summary'      => sprintf( 'Delete challenge %d ("%s") made by this call', $id, $name ),
			'target'       => [ 'challenge_id' => $id ],
			'pre_op_state' => [ 'fingerprint' => self::fingerprint( $row ) ],
		] );
		return Envelope::success( sprintf( 'Created challenge %d "%s" (status %s).%s', $id, $name, $status, $warn ), [ 'dry_run' => false, 'challenge_id' => $id, 'challenge' => $show, 'timezone' => wp_timezone_string() ], $undo ?: null );
	}

	// ------------------------------------------------------------------
	// rafp_assign_landing_page
	// ------------------------------------------------------------------

	private static function assign_landing_page( array $args ) {
		if ( ! class_exists( 'Royal_Affiliate_Landing_Page' ) || ! is_callable( [ 'Royal_Affiliate_Landing_Page', 'assign' ] ) || ! is_callable( [ 'Royal_Affiliate_Landing_Page', 'unassign' ] ) ) {
			return Envelope::error( 'not_supported', 'This Royal Affiliate version has no landing pages.' );
		}
		$dry     = self::flag( $args, 'dry_run' );
		$page_id = Form_Entry_Privacy::positive_int( $args['page_id'] ?? null );
		$aff_id  = Form_Entry_Privacy::positive_int( $args['affiliate_id'] ?? null );
		if ( null === $dry || $page_id <= 0 || $aff_id <= 0 ) {
			return Envelope::error( 'invalid_args', 'page_id and affiliate_id must be positive whole numbers, and dry_run true or false.' );
		}
		$page = get_post( $page_id );
		// One answer for "no such page", "not a page" and "not yours to edit".
		if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status || ! current_user_can( 'edit_post', $page_id ) ) {
			return Envelope::error( 'not_found', 'No page you can edit has that ID. A landing page must be a WordPress page.' );
		}
		$affiliate = \Royal_Affiliate_Affiliate::get( $aff_id );
		if ( ! is_object( $affiliate ) ) {
			return Envelope::error( 'not_found', 'Affiliate not found.' );
		}
		// Every visit to a landing page is credited to its affiliate, so some pages deserve a second look.
		$warnings = [];
		if ( 'publish' !== $page->post_status || '' !== (string) $page->post_password ) {
			$warnings[] = 'The page is not public (' . sanitize_key( (string) $page->post_status ) . ( '' !== (string) $page->post_password ? ', password-protected' : '' ) . '), so visitors do not reach it and Royal Affiliate does not list it.';
		}
		if ( 'active' !== strtolower( (string) ( $affiliate->status ?? '' ) ) ) {
			$warnings[] = 'The affiliate is not active (' . sanitize_key( (string) ( $affiliate->status ?? '' ) ) . '), so Royal Affiliate ignores this page until they are.';
		}
		// Checkout, cart and account pages are where every buyer passes: crediting them to one affiliate is refused outright.
		foreach ( [ 'cart' => 'the cart page', 'checkout' => 'the checkout page', 'myaccount' => 'the customer account page' ] as $wc => $label ) {
			$wc_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( $wc ) : 0;
			if ( $wc_id > 0 && $wc_id === $page_id ) {
				return Envelope::error( 'page_not_allowed', 'This is ' . $label . ': every buyer passes through it, so it cannot be an affiliate landing page. Nothing was changed.' );
			}
		}
		$body = (string) $page->post_content;
		if ( ( function_exists( 'has_block' ) && ( has_block( 'woocommerce/checkout', $body ) || has_block( 'woocommerce/cart', $body ) ) ) || false !== strpos( $body, '[woocommerce_checkout' ) || false !== strpos( $body, '[woocommerce_cart' ) || false !== strpos( $body, '[woocommerce_my_account' ) ) {
			return Envelope::error( 'page_not_allowed', 'This page holds the store checkout, cart or account area, so it cannot be an affiliate landing page. Nothing was changed.' );
		}
		$special = [ 'the site\'s front page' => (int) get_option( 'page_on_front' ), 'the posts page' => (int) get_option( 'page_for_posts' ), 'the shop page' => function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0 ];
		foreach ( $special as $label => $special_id ) {
			if ( $special_id > 0 && $special_id === $page_id ) {
				$warnings[] = 'This is ' . $label . ': every visitor who lands on it would be credited to this one affiliate.';
			}
		}
		$key_a = \Royal_Affiliate_Landing_Page::META_KEY;
		$key_d = \Royal_Affiliate_Landing_Page::META_KEY_DATE_ASSIGNED;
		$prior = (int) get_post_meta( $page_id, $key_a, true );
		$date  = $prior > 0 ? (string) get_post_meta( $page_id, $key_d, true ) : '';
		$info  = [ 'page_id' => $page_id, 'page_title' => mb_substr( SafeText::field( (string) $page->post_title ), 0, 200 ), 'page_status' => sanitize_key( (string) $page->post_status ), 'affiliate_id' => $aff_id, 'previous_affiliate_id' => $prior > 0 ? $prior : null, 'warnings' => $warnings ];
		$heads = $warnings ? ' Note: ' . implode( ' ', $warnings ) : '';
		if ( $prior === $aff_id ) {
			return Envelope::success( sprintf( 'Page %d is already the landing page of affiliate %d; nothing to change.', $page_id, $aff_id ), [ 'dry_run' => (bool) $dry, 'changed' => false ] + $info );
		}
		$verb = $prior > 0 ? sprintf( 'moved from affiliate %d to affiliate %d', $prior, $aff_id ) : sprintf( 'assigned to affiliate %d', $aff_id );
		if ( $dry ) {
			return Envelope::success( sprintf( 'Preview: page %d ("%s") would be %s. Nothing was changed.%s', $page_id, $info['page_title'], $verb, $heads ), [ 'dry_run' => true, 'changed' => false ] + $info );
		}
		if ( ! \Royal_Affiliate_Landing_Page::assign( $aff_id, $page_id ) || (int) get_post_meta( $page_id, $key_a, true ) !== $aff_id ) {
			return Envelope::error( 'write_failed', 'Royal Affiliate did not save the assignment.' );
		}
		$undo = Undo_Store::store( [
			'op'           => 'rafp_assign_landing_page',
			'summary'      => $prior > 0 ? sprintf( 'Give page %d back to affiliate %d', $page_id, $prior ) : sprintf( 'Take page %d away from affiliate %d', $page_id, $aff_id ),
			'target'       => [ 'page_id' => $page_id ],
			'pre_op_state' => [ 'written_affiliate_id' => $aff_id, 'previous_affiliate_id' => $prior, 'previous_date_assigned' => $date ],
		] );
		return Envelope::success( sprintf( 'Page %d ("%s") %s.%s', $page_id, $info['page_title'], $verb, $heads ), [ 'dry_run' => false, 'changed' => true ] + $info, $undo ?: null );
	}

	private static function undo_landing_page( array $snapshot, $force ) {
		$page_id = (int) ( $snapshot['target']['page_id'] ?? 0 );
		$pre     = (array) ( $snapshot['pre_op_state'] ?? [] );
		$written = (int) ( $pre['written_affiliate_id'] ?? 0 );
		$prior   = (int) ( $pre['previous_affiliate_id'] ?? 0 );
		if ( $page_id <= 0 || $written <= 0 || ! class_exists( 'Royal_Affiliate_Landing_Page' ) ) {
			throw new \Exception( 'Undo snapshot missing the page, or this Royal Affiliate version has no landing pages.' );
		}
		$target = [ 'page_id' => $page_id ];
		$page   = get_post( $page_id );
		if ( ! $page || ! current_user_can( 'edit_post', $page_id ) ) {
			throw new \Exception( 'The page no longer exists or is not yours to edit; nothing was undone.' );
		}
		$key_a = \Royal_Affiliate_Landing_Page::META_KEY;
		$key_d = \Royal_Affiliate_Landing_Page::META_KEY_DATE_ASSIGNED;
		$now   = (int) get_post_meta( $page_id, $key_a, true );
		if ( $now !== $written && ! $force ) {
			throw new Tool_Refusal( 'edited_since_write', esc_html( sprintf( 'Page %d was given to someone else (or to no one) after this write, so the undo left it as it is. Pass force: true to put the earlier assignment back anyway.', $page_id ) ) );
		}
		if ( $now > 0 ) {
			\Royal_Affiliate_Landing_Page::unassign( $now, $page_id );
		}
		if ( $prior > 0 ) {
			if ( ! is_object( \Royal_Affiliate_Affiliate::get( $prior ) ) || ! \Royal_Affiliate_Landing_Page::assign( $prior, $page_id ) ) {
				return Envelope::success( sprintf( 'Page %d was taken away from affiliate %d, but its earlier affiliate %d no longer exists, so it now belongs to no one.', $page_id, $written, $prior ), [ 'undone' => true, 'op' => 'rafp_assign_landing_page', 'target' => $target, 'affiliate_id' => null, 'earlier_affiliate_missing' => true ] );
			}
			if ( '' !== (string) ( $pre['previous_date_assigned'] ?? '' ) ) {
				update_post_meta( $page_id, $key_d, sanitize_text_field( (string) $pre['previous_date_assigned'] ) );
			}
			return Envelope::success( sprintf( 'Page %d is the landing page of affiliate %d again.', $page_id, $prior ), [ 'undone' => true, 'op' => 'rafp_assign_landing_page', 'target' => $target, 'affiliate_id' => $prior ] );
		}
		return Envelope::success( sprintf( 'Page %d is no longer a landing page.', $page_id ), [ 'undone' => true, 'op' => 'rafp_assign_landing_page', 'target' => $target, 'affiliate_id' => null ] );
	}

	/** Undo of any of the four tools that have one. */
	public static function undo( array $snapshot, $force = false ) {
		self::require_cap();
		switch ( (string) ( $snapshot['op'] ?? '' ) ) {
			case 'rafp_update_referral_status':
				return self::undo_referral_status( $snapshot, (bool) $force );
			case 'rafp_create_campaign':
				return self::undo_created( $snapshot, (bool) $force, 'campaign' );
			case 'rafp_create_challenge':
				return self::undo_created( $snapshot, (bool) $force, 'challenge' );
			case 'rafp_assign_landing_page':
				return self::undo_landing_page( $snapshot, (bool) $force );
		}
		throw new \Exception( 'Unknown Royal Affiliate undo.' );
	}

	// ------------------------------------------------------------------
	// rafp_scan_fraud
	// ------------------------------------------------------------------

	private static function scan_fraud( array $args ) {
		$fd = 'Royal_Affiliate_Fraud_Detector';
		if ( ! is_callable( [ $fd, 'scan_history' ] ) || ! is_callable( [ $fd, 'check_visit' ] ) || ! is_callable( [ $fd, 'check_referral' ] ) || ! defined( 'RAFP_VERSION' ) || version_compare( (string) RAFP_VERSION, '2.6.20', '<' ) ) {
			return Envelope::error( 'not_supported', 'Scanning past visits and referrals needs Royal Affiliate Pro 2.6.20 or later.' );
		}
		$dry   = self::flag( $args, 'dry_run' );
		$ack   = self::flag( $args, 'acknowledge_mass_flag' );
		$days  = isset( $args['days_back'] ) ? Form_Entry_Privacy::positive_int( $args['days_back'] ) : 30;
		$batch = isset( $args['batch_size'] ) ? Form_Entry_Privacy::positive_int( $args['batch_size'] ) : 500;
		$types = $args['scan_types'] ?? [ 'visits', 'referrals' ];
		if ( null === $dry || null === $ack || $days < 1 || $days > 365 || $batch < 1 || $batch > 2000 || ! is_array( $types ) || ! $types || array_diff( $types, [ 'visits', 'referrals' ] ) || count( $types ) !== count( array_filter( $types, 'is_string' ) ) ) {
			return Envelope::error( 'invalid_args', 'days_back must be 1 to 365, batch_size 1 to 2000, scan_types a list of visits and / or referrals, and dry_run and acknowledge_mass_flag true or false.' );
		}
		$types  = array_values( array_unique( $types ) );
		$sample = self::scan_sample( $days, $types, $dry ? self::SCAN_SAMPLE : $batch );
		$looked = $sample['visits'] + $sample['referrals'];
		if ( $dry ) {
			return Envelope::success(
				sprintf( 'Preview on the newest %d row(s) of each kind from the last %d day(s): %d would be logged as fraud incidents, %d are logged already. Nothing was written. The real run looks at up to %d of each kind.', self::SCAN_SAMPLE, $days, $sample['would_log'], $sample['already_logged'], $batch ),
				[ 'dry_run' => true, 'days_back' => $days, 'scan_types' => $types, 'batch_size' => $batch, 'sample_visits' => $sample['visits'], 'sample_referrals' => $sample['referrals'], 'would_log' => $sample['would_log'], 'would_log_by_type' => $sample['by_type'] ? $sample['by_type'] : new \stdClass(), 'already_logged' => $sample['already_logged'] ]
			);
		}
		// A scan that would flag most of what it looks at is more likely a setting gone wrong than fraud.
		if ( $looked >= 20 && $sample['would_log'] * 2 > $looked && ! $ack ) {
			return Envelope::error( 'mass_flag_refused', sprintf( 'This scan would log %d of the %d rows it looked at as fraud, which usually means a fraud setting is too strict. Nothing was written. Check Royal Affiliate\'s fraud settings, or pass acknowledge_mass_flag: true to log them anyway.', $sample['would_log'], $looked ), [ 'would_log' => $sample['would_log'], 'looked_at' => $looked, 'would_log_by_type' => $sample['by_type'] ] );
		}
		$own   = (array) call_user_func( [ $fd, 'scan_history' ], [ 'days_back' => $days, 'scan_types' => $types, 'batch_size' => $batch ] );
		$by    = [];
		foreach ( is_array( $own['incidents_by_type'] ?? null ) ? $own['incidents_by_type'] : [] as $k => $n ) {
			$by[ sanitize_key( (string) $k ) ] = (int) $n;
		}
		$logged = (int) ( $own['incidents_logged'] ?? 0 );
		return Envelope::success(
			sprintf( 'Scanned %d visit(s) and %d referral(s) from the last %d day(s): %d incident(s) logged, %d were logged already.%s%s', (int) ( $own['scanned_visits'] ?? 0 ), (int) ( $own['scanned_referrals'] ?? 0 ), $days, $logged, (int) ( $own['already_logged'] ?? 0 ), ! empty( $own['truncated'] ) ? ' The batch limit was reached: older rows in the period were not looked at.' : '', $logged ? ' Log entries stay until removed in Royal Affiliate; this has no undo.' : '' ),
			[ 'dry_run' => false, 'days_back' => $days, 'scan_types' => $types, 'batch_size' => $batch, 'scanned_visits' => (int) ( $own['scanned_visits'] ?? 0 ), 'scanned_referrals' => (int) ( $own['scanned_referrals'] ?? 0 ), 'incidents_logged' => $logged, 'incidents_by_type' => $by ? $by : new \stdClass(), 'already_logged' => (int) ( $own['already_logged'] ?? 0 ), 'truncated' => ! empty( $own['truncated'] ) ]
		);
	}

	/** What a scan of the newest $limit rows of each kind would log, without writing anything. */
	private static function scan_sample( $days, array $types, $limit ) {
		global $wpdb;
		$out    = [ 'visits' => 0, 'referrals' => 0, 'would_log' => 0, 'already_logged' => 0, 'by_type' => [] ];
		// Royal Affiliate stores its dates in site time.
		$cutoff = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$log    = $wpdb->prefix . 'rafp_fraud_log';
		$count  = static function ( array &$out, $check, $col, $id ) use ( $wpdb, $log ) {
			if ( ! is_array( $check ) || empty( $check['is_fraud'] ) ) {
				return;
			}
			$type = sanitize_key( (string) ( $check['type'] ?? 'unknown' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed column names from this method
			if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT log_id FROM {$log} WHERE {$col} = %d AND fraud_type = %s LIMIT 1", (int) $id, (string) ( $check['type'] ?? 'unknown' ) ) ) ) {
				$out['already_logged']++;
				return;
			}
			$out['would_log']++;
			$out['by_type'][ $type ] = ( $out['by_type'][ $type ] ?? 0 ) + 1;
		};
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( in_array( 'visits', $types, true ) ) {
			$rows          = (array) $wpdb->get_results( $wpdb->prepare( "SELECT visit_id, affiliate_id, ip_address, user_agent, date_created FROM {$wpdb->prefix}rafp_visits WHERE date_created >= %s ORDER BY visit_id DESC LIMIT %d", $cutoff, $limit ), ARRAY_A );
			$out['visits'] = count( $rows );
			foreach ( $rows as $v ) {
				$count( $out, \Royal_Affiliate_Fraud_Detector::check_visit( $v ), 'visit_id', $v['visit_id'] );
			}
		}
		if ( in_array( 'referrals', $types, true ) ) {
			$rows             = (array) $wpdb->get_results( $wpdb->prepare( "SELECT referral_id, affiliate_id, customer_id, reference, reference_type, context, ip_address, date_created FROM {$wpdb->prefix}rafp_referrals WHERE date_created >= %s ORDER BY referral_id DESC LIMIT %d", $cutoff, $limit ), ARRAY_A );
			$out['referrals'] = count( $rows );
			foreach ( $rows as $r ) {
				$count( $out, \Royal_Affiliate_Fraud_Detector::check_referral( $r ), 'referral_id', $r['referral_id'] );
			}
		}
		// phpcs:enable
		return $out;
	}

	// ------------------------------------------------------------------
	// Tool definitions
	// ------------------------------------------------------------------

	private static function schemas() {
		$id   = static function ( $what ) { return [ 'type' => 'integer', 'minimum' => 1, 'description' => $what ]; };
		$dry  = [ 'type' => 'boolean', 'description' => 'true previews and changes nothing. Default false.' ];
		$when = static function ( $what ) { return [ 'type' => 'string', 'description' => $what . ' in site time: 2026-10-01 or 2026-10-01T09:00.' ]; };
		$tool = static function ( $name, $description, array $props, array $required ) {
			return [ 'name' => $name, 'description' => $description . ' Requires Royal Affiliate Pro and manage_options.', 'inputSchema' => [ 'type' => 'object', 'required' => $required, 'properties' => $props, 'additionalProperties' => false ] ];
		};
		$out  = [
			$tool( 'rafp_update_referral_status', 'Set up to 25 referrals to pending, approved or rejected (paid is set by Royal Affiliate when a payout is paid). Paid referrals, ones on a payout and unknown IDs are left alone and listed in skipped; a rejected referral moves only with reopen_rejected: true. Upline (multi-tier) referrals get the same status (tier_referral_ids); one of them being paid or on a payout skips the referral. Approving can email the affiliate (emails_sent) and count toward challenges, which can grant a reward (challenge_rewards_granted). dry_run true shows what would change. Returns an undo token that puts each referral and its upline back and removes the added challenge progress; refused with edited_since_write after a later change, and with reward_granted when a challenge was completed, unless forced. It recalls neither emails nor rewards.', [ 'referral_ids' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_REFERRALS, 'items' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'new_status' => [ 'type' => 'string', 'enum' => self::REFERRAL_TARGETS ], 'reopen_rejected' => [ 'type' => 'boolean', 'description' => 'true lets rejected referrals move to pending or approved. Default false.' ], 'dry_run' => $dry ], [ 'referral_ids', 'new_status' ] ),
			$tool( 'rafp_create_campaign', 'Create a Royal Affiliate campaign: name (up to 100 characters), optional slug (Royal Affiliate adds a number when it is taken), description, commission_type (percentage, default, or flat), commission_rate (percentage up to 50, flat up to 100; with allow_high_rate true up to 100 percent or 999.99 flat; 2 decimals), status (inactive by default, active or ended), start_date and end_date. dry_run true shows what would be created. Returns campaign_id and an undo token; undo deletes the campaign while it has no referrals, visits or creatives and has not been edited (force: true deletes it anyway and leaves those records without a campaign).', [ 'name' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ], 'slug' => [ 'type' => 'string', 'maxLength' => 100 ], 'description' => [ 'type' => 'string', 'maxLength' => 2000 ], 'commission_type' => [ 'type' => 'string', 'enum' => self::COMMISSION_TYPES ], 'commission_rate' => [ 'type' => 'number', 'minimum' => 0 ], 'allow_high_rate' => [ 'type' => 'boolean', 'description' => 'true allows a percentage above 50 or a flat amount above 100. Default false.' ], 'status' => [ 'type' => 'string', 'enum' => self::ENTITY_STATUSES ], 'start_date' => $when( 'First moment' ), 'end_date' => $when( 'Last moment' ), 'dry_run' => $dry ], [ 'name' ] ),
			$tool( 'rafp_create_challenge', 'Create a Royal Affiliate challenge: name, goal (at least 2, or 0.01 with allow_low_goal: true; whole for referral_count) of a metric (referral_count by default, earnings or revenue), reward_type with its reward_value (custom, the default: {"text": ...} or nothing; commission_rate or commission_bonus: {"rate": number, "type": "percentage" or "flat"}; flat_bonus: {"amount": up to 1000 by default}; tier_upgrade: {"tier_id": existing tier}), scope (all, or tag with scope_value the tag ID), recurring (boolean), status (inactive by default), start_date and end_date. An active challenge grants its reward by itself when an affiliate reaches the goal. Previews and creates nothing unless dry_run is false. Returns challenge_id and an undo token that deletes it while no affiliate has progress and it is unedited (force: true deletes it with that progress; granted rewards stay).', [ 'name' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ], 'goal' => [ 'type' => 'number', 'exclusiveMinimum' => 0 ], 'description' => [ 'type' => 'string', 'maxLength' => 2000 ], 'metric' => [ 'type' => 'string', 'enum' => self::METRICS ], 'reward_type' => [ 'type' => 'string', 'enum' => self::REWARD_TYPES ], 'reward_value' => [ 'type' => 'object' ], 'scope' => [ 'type' => 'string', 'enum' => self::SCOPES ], 'scope_value' => $id( 'Tag ID, with scope tag.' ), 'recurring' => [ 'type' => 'boolean' ], 'allow_low_goal' => [ 'type' => 'boolean', 'description' => 'true allows a goal below 2. Default false.' ], 'allow_high_rate' => [ 'type' => 'boolean', 'description' => 'commission_rate and commission_bonus rewards are capped at 50 percent or 100 flat; true allows up to 100 percent or 999.99 flat. Default false.' ], 'status' => [ 'type' => 'string', 'enum' => self::ENTITY_STATUSES ], 'start_date' => $when( 'First moment' ), 'end_date' => $when( 'Last moment' ), 'dry_run' => [ 'type' => 'boolean', 'description' => 'false creates the challenge. Default true (preview only).' ] ], [ 'name', 'goal' ] ),
			$tool( 'rafp_assign_landing_page', 'Make a WordPress page the landing page of an affiliate, so visits to it count for that affiliate. A page has one affiliate: assigning it moves it away from the affiliate it had. The page must be a page you can edit. Every visit to it is then credited to that affiliate: the cart, checkout and customer account pages (and pages holding those) are refused; the reply carries warnings when the page is the front page, the shop page, not public, or the affiliate is not active. dry_run true shows what would change. Returns an undo token; undo gives the page back to its earlier affiliate, or to no one, and is refused with edited_since_write when the page was reassigned since unless forced.', [ 'page_id' => $id( 'The page.' ), 'affiliate_id' => $id( 'The affiliate.' ), 'dry_run' => $dry ], [ 'page_id', 'affiliate_id' ] ),
			$tool( 'rafp_scan_fraud', 'Run Royal Affiliate\'s fraud checks over past visits and referrals (days_back, 30 by default, up to 365; the newest batch_size rows of each kind, 500 by default, up to 2000) and log what they find as fraud incidents. Royal Affiliate emails the site administrator when a scan logs high-severity incidents, and sends its fraud webhooks when any are set up. dry_run true runs the checks on the newest 25 rows of each kind and writes nothing. A run that would flag more than half of at least 20 rows is refused as mass_flag_refused unless acknowledge_mass_flag is true. Log entries stay until removed in Royal Affiliate: there is no undo. Not available in read-only mode. Needs Royal Affiliate Pro 2.6.20 or later.', [ 'days_back' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 365 ], 'batch_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 2000 ], 'scan_types' => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => [ 'visits', 'referrals' ] ] ], 'acknowledge_mass_flag' => [ 'type' => 'boolean' ], 'dry_run' => $dry ], [] ),
		];
		$out[4]['inputSchema'] = array_diff_key( $out[4]['inputSchema'], [ 'required' => 1 ] );
		return array_column( $out, null, 'name' );
	}
}
