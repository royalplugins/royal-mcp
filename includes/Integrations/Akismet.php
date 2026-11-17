<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Tool_Refusal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Akismet: connection status, the "Check for Spam" recheck of the pending
 * comment queue, and Akismet's spam statistics.
 *
 * The plugin's own class is always referenced as \Akismet (global namespace);
 * this class shares its short name inside the Royal_MCP\Integrations namespace.
 */
class Akismet {

	/** Default and largest number of pending comments one recheck call sends to Akismet. */
	const DEFAULT_BATCH = 20;
	const MAX_BATCH     = 100;

	/** Stats periods Akismet's stats service accepts. */
	const INTERVALS = [ '60-days', '6-months', 'all' ];

	/** Most breakdown periods returned by the stats tool. */
	const MAX_BREAKDOWN = 120;

	/**
	 * Option used as a lock so two recheck calls never work through the same queue at once.
	 * A lock older than LOCK_SECONDS was left by a request that ended early and is taken over.
	 */
	const LOCK_OPTION  = 'royal_mcp_akismet_recheck_lock';
	const LOCK_SECONDS = 300;

	public static function is_available() {
		return defined( 'AKISMET_VERSION' ) && class_exists( '\Akismet' );
	}

	public static function get_tools() {
		return [
			[
				'name'        => 'akismet_get_status',
				'description' => 'Akismet status: whether an API key is set (shown masked, last 4 characters only) and where it comes from, the key status Akismet last stored (valid, invalid or not_checked; no request is made to Akismet), any alert Akismet reported for the account, total spam caught on this site, how many comments are in the spam queue and in the pending queue, how many pending comments are still waiting for an Akismet check, when Akismet\'s next automatic recheck runs, and the settings: strictness (discard the worst spam or keep all spam in the spam folder), the approved-comment count shown to moderators, the comment form privacy notice, and which active form plugins that work with Akismet are known. Akismet has no contact-form switch of its own: form plugins that support it send their entries through it. Read-only. Administrators only.',
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				],
			],
			[
				'name'        => 'akismet_recheck_queue',
				'description' => 'Check pending comments with Akismet, like the "Check for Spam" button on the Comments screen: each pending comment (oldest first) is sent to Akismet\'s service with Akismet\'s own recheck. Comments Akismet calls spam move to the spam queue; the others stay pending (they are not approved). Up to ' . self::MAX_BATCH . ' comments per call (default ' . self::DEFAULT_BATCH . '). Returns the counts (processed, spam, not_spam, error), each checked comment ID with its result, next_after_id to continue with, and how many pending comments remain after it. Stops at the first comment Akismet could not answer. Comment content, author details and IP address are sent to Akismet. There is no undo; a comment moved to spam can be approved again with the comment tools. Needs an Akismet API key. Refused in read-only mode. Users who can moderate comments.',
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => [
						'limit'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_BATCH, 'description' => 'Pending comments to check in this call, 1-' . self::MAX_BATCH . '. Default ' . self::DEFAULT_BATCH . '.' ],
						'after_id' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Only check pending comments with an ID above this one: pass next_after_id from the previous call to continue. Default 0 (from the start of the queue).' ],
					],
					'additionalProperties' => false,
				],
			],
			[
				'name'        => 'akismet_get_stats',
				'description' => 'Akismet\'s spam statistics for this site, fetched from Akismet\'s service the way its stats page does: spam blocked, legitimate comments, missed spam, false positives, accuracy (percent), estimated time saved (seconds) and a breakdown per period (up to ' . self::MAX_BREAKDOWN . ' periods). interval: 60-days, 6-months (default) or all. Makes one request to Akismet; when the service cannot be reached the reply says reachable: false. Needs an Akismet API key. Read-only. Users who can moderate comments.',
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => [
						'interval' => [ 'type' => 'string', 'enum' => self::INTERVALS, 'description' => 'Period to report. Default 6-months.' ],
					],
					'additionalProperties' => false,
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			throw new \Exception( 'You do not have permission to use Akismet tools.' );
		}
		if ( is_multisite() && ! is_super_admin() && ! is_user_member_of_blog( get_current_user_id(), get_current_blog_id() ) ) {
			throw new \Exception( 'You do not have permission to use Akismet tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Akismet is not active' );
		}
		$args = is_array( $args ) ? $args : [];

		switch ( $name ) {
			case 'akismet_get_status':
				self::only_args( $args, [] );
				if ( ! current_user_can( 'manage_options' ) ) {
					throw new Tool_Refusal( 'insufficient_caps', 'You do not have permission to view the Akismet status: it needs an administrator.' );
				}
				return self::get_status();

			case 'akismet_recheck_queue':
				self::only_args( $args, [ 'limit', 'after_id' ] );
				if ( \Royal_MCP\MCP\Server::read_only_mode() ) {
					throw new Tool_Refusal( 'read_only_mode', 'This site is in read-only mode: tools that change the site are switched off.' );
				}
				$limit    = self::int_arg( $args, 'limit', self::DEFAULT_BATCH, 1, self::MAX_BATCH );
				$after_id = self::int_arg( $args, 'after_id', 0, 0, PHP_INT_MAX );
				return self::recheck_queue( $limit, $after_id );

			case 'akismet_get_stats':
				self::only_args( $args, [ 'interval' ] );
				$interval = '6-months';
				if ( array_key_exists( 'interval', $args ) ) {
					if ( ! is_string( $args['interval'] ) || ! in_array( $args['interval'], self::INTERVALS, true ) ) {
						throw new Tool_Refusal( 'invalid_argument', 'interval must be one of: ' . implode( ', ', self::INTERVALS ) . '.' );
					}
					$interval = $args['interval'];
				}
				return self::get_stats( $interval );
		}
		throw new \Exception( 'Unknown Akismet tool: ' . esc_html( $name ) );
	}

	// ============ Argument checks ============

	/** Refuses any argument the tool does not take. */
	private static function only_args( array $args, array $allowed ) {
		$unknown = array_diff( array_map( 'strval', array_keys( $args ) ), $allowed );
		if ( $unknown ) {
			throw new Tool_Refusal( 'invalid_argument', 'Unknown argument: ' . esc_html( implode( ', ', $unknown ) ) . '.' );
		}
	}

	/** A whole number within a range; strings, decimals and booleans are refused. */
	private static function int_arg( array $args, $key, $default, $min, $max ) {
		if ( ! array_key_exists( $key, $args ) ) {
			return $default;
		}
		$v = $args[ $key ];
		if ( is_float( $v ) && floor( $v ) === $v && abs( $v ) < 9.0E15 ) {
			$v = (int) $v;
		}
		if ( ! is_int( $v ) || $v < $min || $v > $max ) {
			throw new Tool_Refusal( 'invalid_argument', sprintf( '%s must be a whole number from %d to %s.', $key, $min, PHP_INT_MAX === $max ? 'any higher value' : (string) $max ) );
		}
		return $v;
	}

	/** Refuses a tool that talks to Akismet's service when no API key is set. */
	private static function require_key() {
		$key = \Akismet::get_api_key();
		if ( ! is_string( $key ) || '' === $key ) {
			throw new Tool_Refusal( 'akismet_not_configured', 'Akismet has no API key: add one under Settings > Akismet Anti-spam first.' );
		}
		return $key;
	}

	// ============ Tools ============

	private static function get_status() {
		$key    = \Akismet::get_api_key();
		$key    = is_string( $key ) ? $key : '';
		$source = 'none';
		if ( '' !== $key ) {
			if ( defined( 'WPCOM_API_KEY' ) ) {
				$source = 'constant';
			} elseif ( (string) get_option( 'wordpress_api_key', '' ) === $key ) {
				$source = 'option';
			} else {
				$source = 'filter';
			}
		}

		// The status Akismet itself keeps: its own shortcut for keys of the wrong length,
		// then the cached result of its last key check. No request is made here.
		if ( '' === $key ) {
			$key_status = 'not_set';
		} elseif ( 12 !== strlen( $key ) ) {
			$key_status = \Akismet::KEY_STATUS_INVALID;
		} else {
			$cached     = get_transient( 'akismet_connector_key_status' );
			$key_status = in_array( $cached, [ \Akismet::KEY_STATUS_VALID, \Akismet::KEY_STATUS_INVALID ], true ) ? $cached : 'not_checked';
		}

		$counts  = wp_count_comments();
		$waiting = (int) get_comments(
			[
				'status'   => 'hold',
				'meta_key' => 'akismet_error', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'count'    => true,
			]
		);

		$alert_code = (int) get_option( 'akismet_alert_code', 0 );
		$next       = wp_next_scheduled( 'akismet_schedule_cron_recheck' );

		return [
			'akismet_version'                => (string) AKISMET_VERSION,
			'api_key_set'                    => '' !== $key,
			'key_last_four'                 => '' !== $key ? str_repeat( '*', 8 ) . ( strlen( $key ) >= 12 ? substr( $key, -4 ) : '' ) : null,
			'key_from'                 => $source,
			'key_status'                     => $key_status,
			'key_status_note'                => 'not_checked' === $key_status ? 'Akismet has no recent key check stored; it checks the key when it is saved and when its settings screen is opened.' : null,
			'alert'                          => $alert_code ? [
				'code'    => $alert_code,
				'message' => sanitize_text_field( (string) get_option( 'akismet_alert_msg', '' ) ),
			] : null,
			'spam_caught_total'              => (int) get_option( 'akismet_spam_count', 0 ),
			'spam_queue'                     => (int) $counts->spam,
			'pending_queue'                  => (int) $counts->moderated,
			'pending_waiting_for_check'      => $waiting,
			'next_automatic_recheck'         => $next ? gmdate( 'c', (int) $next ) : null,
			'strictness'                     => '1' === (string) get_option( 'akismet_strictness', '1' ) ? 'discard_worst_spam' : 'keep_all_spam_in_spam_folder',
			'show_approved_comment_counts'   => in_array( get_option( 'akismet_show_user_comments_approved' ), [ false, '1', 'true' ], true ),
			'comment_form_privacy_notice'    => 'display' === get_option( 'akismet_comment_form_privacy_notice', 'hide' ) ? 'display' : 'hide',
			'akismet_abilities_public_to_mcp' => '1' === (string) get_option( 'akismet_enable_mcp_access', '0' ),
			'test_mode'                      => (bool) \Akismet::is_test_mode(),
			'compatible_form_plugins_active' => self::compatible_form_plugins(),
			'contact_forms_note'             => 'Akismet has no contact-form setting of its own; form plugins that support Akismet send their entries to it while it has an API key.',
		];
	}

	/**
	 * Active plugins on Akismet's own compatible-plugin list, from the list Akismet
	 * already stored. Null when Akismet has not stored the list yet (no request is made).
	 */
	private static function compatible_form_plugins() {
		$list = get_transient( 'akismet_compatible_plugin_list_' . (int) get_current_blog_id() );
		if ( ! is_array( $list ) ) {
			return null;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = [];
		foreach ( $list as $plugin ) {
			if ( ! is_array( $plugin ) || empty( $plugin['path'] ) || ! is_string( $plugin['path'] ) ) {
				continue;
			}
			$path = $plugin['path'];
			if ( is_plugin_active( $path ) || ( is_multisite() && is_plugin_active_for_network( $path ) ) ) {
				$out[] = sanitize_text_field( (string) ( $plugin['name'] ?? $path ) );
			}
		}
		return $out;
	}

	private static function recheck_queue( $limit, $after_id ) {
		global $wpdb;
		self::require_key();

		$lock = self::take_lock( self::LOCK_OPTION, self::LOCK_SECONDS );
		if ( ! $lock ) {
			throw new Tool_Refusal( 'recheck_running', 'Another Akismet recheck is still running. Try again in a few minutes.' );
		}

		try {
			$ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = '0' AND comment_ID > %d ORDER BY comment_ID ASC LIMIT %d",
						$after_id,
						$limit
					)
				)
			);

			$counts  = [ 'processed' => 0, 'spam' => 0, 'not_spam' => 0, 'error' => 0 ];
			$results = [];
			$last    = $after_id;
			$stopped = null;

			foreach ( $ids as $id ) {
				// Akismet's own recheck: it sends the comment, moves spam to the spam queue
				// and records the result in the comment's Akismet history.
				$response = \Akismet::recheck_comment( $id, 'recheck_queue' );
				++$counts['processed'];
				$last = $id;
				if ( 'true' === $response ) {
					++$counts['spam'];
					$results[] = [ 'comment_id' => $id, 'result' => 'spam' ];
				} elseif ( 'false' === $response ) {
					++$counts['not_spam'];
					$results[] = [ 'comment_id' => $id, 'result' => 'not_spam' ];
				} else {
					++$counts['error'];
					$results[] = [ 'comment_id' => $id, 'result' => 'error' ];
					if ( ! is_wp_error( $response ) ) {
						$stopped = 'Akismet could not be reached or gave no answer for comment ' . $id . '; the remaining comments were not sent.';
						break;
					}
				}
			}

			$remaining = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = '0' AND comment_ID > %d", $last )
			);
		} finally {
			self::release_lock( self::LOCK_OPTION, $lock );
		}

		if ( $counts['spam'] ) {
			wp_cache_delete( 'akismet_spam_count', 'widget' );
		}

		return [
			'counts'          => $counts,
			'comments'        => $results,
			'next_after_id'   => $last,
			'remaining_after' => $remaining,
			'done'            => null === $stopped && 0 === $remaining,
			'stopped_early'   => $stopped,
		];
	}

	/**
	 * Takes a lock row in the options table. The insert only succeeds when no row exists, and
	 * a row older than $stale seconds is taken over only while it still holds the value read,
	 * so two requests never both get the lock. Returns the value to release it with, or 0.
	 */
	private static function take_lock( $name, $stale ) {
		global $wpdb;
		$now = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data
		$got = 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", $name, (string) $now ) );
		if ( ! $got ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data
			$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( null !== $held && ( ! ctype_digit( (string) $held ) || (int) $held < $now - (int) $stale ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data
				$got = 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $now, $name, (string) $held ) );
			}
		}
		self::forget_cached_option( $name );
		return $got ? $now : 0;
	}

	/** Removes the lock row, only while it still holds this request's value. */
	private static function release_lock( $name, $value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $value ) );
		self::forget_cached_option( $name );
	}

	/** Drops any cached copy of an option written directly, so get_option() reads the table. */
	private static function forget_cached_option( $name ) {
		wp_cache_delete( $name, 'options' );
		$not = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $not ) && isset( $not[ $name ] ) ) {
			unset( $not[ $name ] );
			wp_cache_set( 'notoptions', $not, 'options' );
		}
	}

	private static function get_stats( $interval ) {
		self::require_key();
		$data = \Akismet::get_stats( $interval );
		if ( ! is_object( $data ) ) {
			return [
				'reachable' => false,
				'interval'  => $interval,
				'message'   => 'Akismet\'s stats service could not be reached or sent no statistics. Try again later.',
			];
		}

		$num = static function ( $obj, $field, $float = false ) {
			if ( ! isset( $obj->$field ) || ! is_scalar( $obj->$field ) ) {
				return null;
			}
			return $float ? (float) $obj->$field : (int) $obj->$field;
		};

		$breakdown = [];
		if ( isset( $data->breakdown ) && ( is_object( $data->breakdown ) || is_array( $data->breakdown ) ) ) {
			foreach ( (array) $data->breakdown as $period => $row ) {
				if ( count( $breakdown ) >= self::MAX_BREAKDOWN ) {
					break;
				}
				if ( ! is_object( $row ) ) {
					continue;
				}
				$breakdown[] = [
					'period'          => sanitize_text_field( (string) $period ),
					'date'            => isset( $row->da ) && is_scalar( $row->da ) ? sanitize_text_field( (string) $row->da ) : null,
					'spam'            => $num( $row, 'spam' ),
					'ham'             => $num( $row, 'ham' ),
					'missed_spam'     => $num( $row, 'missed_spam' ),
					'false_positives' => $num( $row, 'false_positives' ),
				];
			}
		}

		return [
			'reachable'          => true,
			'interval'           => $interval,
			'spam'               => $num( $data, 'spam' ),
			'ham'                => $num( $data, 'ham' ),
			'missed_spam'        => $num( $data, 'missed_spam' ),
			'false_positives'    => $num( $data, 'false_positives' ),
			'accuracy_percent'   => $num( $data, 'accuracy', true ),
			'time_saved_seconds' => $num( $data, 'time_saved' ),
			'breakdown'          => $breakdown,
		];
	}
}
