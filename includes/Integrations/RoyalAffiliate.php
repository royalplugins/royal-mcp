<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Form_Entry_Privacy;
use Royal_MCP\MCP\Support\SafeText;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Royal Affiliate Pro, read tools: affiliates, referrals, campaigns, creatives,
 * payouts, landing pages, fraud incidents, the activity log and period totals.
 *
 * Every list returns a named set of fields. Visitors' and affiliates' own
 * details (IP addresses, browsers, customer ids, payment addresses, free-text
 * notes) are left out. Fields that hold a code (a status, a type, a currency)
 * are returned only when they look like one. Text that people wrote (names,
 * references, descriptions, log messages) has email addresses, phone numbers,
 * network addresses, links, paths, browser strings and long codes replaced;
 * a person's name written in such text cannot be recognised and stays.
 */
class RoyalAffiliate {

	const TOOLS = [
		'rafp_list_affiliates'           => 'list_affiliates',
		'rafp_get_affiliate_performance' => 'get_affiliate_performance',
		'rafp_get_referral_records'      => 'get_referral_records',
		'rafp_get_period_summary'        => 'get_period_summary',
		'rafp_list_campaigns'            => 'list_campaigns',
		'rafp_get_campaign'              => 'get_campaign',
		'rafp_get_campaign_performance'  => 'get_campaign_performance',
		'rafp_list_creatives'            => 'list_creatives',
		'rafp_list_landing_pages'        => 'list_landing_pages',
		'rafp_list_payouts'              => 'list_payouts',
		'rafp_get_fraud_incidents'       => 'get_fraud_incidents',
		'rafp_get_activity_log'          => 'get_activity_log',
	];

	const MAX_PER_PAGE     = 100;
	const DEFAULT_PER_PAGE = 50;

	/** Sort columns each list accepts. */
	const ORDERBY = [
		'referrals' => [ 'referral_id', 'affiliate_id', 'status', 'commission_amount', 'date_created' ],
		'campaigns' => [ 'campaign_id', 'name', 'slug', 'commission_rate', 'status', 'start_date', 'end_date', 'date_created' ],
		'creatives' => [ 'creative_id', 'name', 'type', 'status', 'campaign_id', 'clicks', 'conversions', 'date_created' ],
		// Royal Affiliate sorts by affiliate or assignment date inside one page of results only, so those are not offered.
		'landing'   => [ 'date_modified', 'title', 'page_id' ],
		'payouts'   => [ 'payout_id', 'affiliate_id', 'amount', 'status', 'date_created', 'date_paid' ],
		'fraud'     => [ 'log_id', 'affiliate_id', 'severity', 'fraud_type', 'date_created' ],
		'activity'  => [ 'date_created', 'id', 'action', 'user_id' ],
	];

	/**
	 * Fields returned per row, by list: 'int', 'money', 'date', 'url', 'code' (a status, type or
	 * currency: returned only when it looks like one), 'label' (a short text someone wrote) or
	 * 'free' (longer text someone wrote); the last two are cleaned.
	 */
	const FIELDS = [
		'referrals' => [ 'referral_id' => 'int', 'affiliate_id' => 'int', 'parent_referral_id' => 'int', 'reference' => 'label', 'reference_type' => 'code', 'amount' => 'money', 'commission_amount' => 'money', 'commission_rate' => 'money', 'commission_type' => 'code', 'status' => 'code', 'campaign_id' => 'int', 'currency' => 'code', 'context' => 'code', 'visit_id' => 'int', 'payout_id' => 'int', 'date_created' => 'date', 'date_approved' => 'date' ],
		'campaigns' => [ 'campaign_id' => 'int', 'name' => 'label', 'slug' => 'code', 'description' => 'free', 'commission_rate' => 'money', 'commission_type' => 'code', 'status' => 'code', 'start_date' => 'date', 'end_date' => 'date', 'date_created' => 'date' ],
		'creatives' => [ 'creative_id' => 'int', 'name' => 'label', 'description' => 'free', 'type' => 'code', 'size' => 'code', 'attachment_id' => 'int', 'file_url' => 'url', 'target_url' => 'url', 'text_content' => 'free', 'campaign_id' => 'int', 'clicks' => 'int', 'conversions' => 'int', 'status' => 'code', 'date_created' => 'date' ],
		'landing'   => [ 'page_id' => 'int', 'affiliate_id' => 'int', 'page_title' => 'label', 'page_url' => 'url', 'page_status' => 'code', 'date_assigned' => 'date', 'date_modified' => 'date' ],
		'payouts'   => [ 'payout_id' => 'int', 'affiliate_id' => 'int', 'amount' => 'money', 'currency' => 'code', 'payment_method' => 'code', 'status' => 'code', 'referrals_count' => 'int', 'date_created' => 'date', 'date_paid' => 'date' ],
		'fraud'     => [ 'log_id' => 'int', 'affiliate_id' => 'int', 'visit_id' => 'int', 'referral_id' => 'int', 'fraud_type' => 'code', 'severity' => 'code', 'details' => 'free', 'date_created' => 'date' ],
		'activity'  => [ 'id' => 'int', 'user_id' => 'int', 'action' => 'code', 'message' => 'free', 'date_created' => 'date' ],
	];

	/** Last parts of a host name that mark a dotted word as a host. */
	const HOST_ENDINGS = 'com|net|org|io|co|uk|de|fr|es|it|nl|eu|us|ca|au|info|biz|dev|app|cloud|shop|store|site|online|local|localhost|internal|lan|test|example|invalid';

	public static function handles( $name ) {
		return is_string( $name ) && isset( self::TOOLS[ $name ] );
	}

	public static function is_available() {
		return class_exists( 'Royal_Affiliate_Affiliate' ) && class_exists( 'Royal_Affiliate_Referral' ) && class_exists( 'Royal_Affiliate_Campaign' ) && class_exists( 'Royal_Affiliate_Fraud_Detector' );
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

	/** Commissions, payouts and fraud records are the site owner's business: administrators only. */
	private static function require_cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use the Royal Affiliate tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Royal Affiliate Pro is not active on this site.' );
		}
	}

	// ------------------------------------------------------------------
	// Arguments
	// ------------------------------------------------------------------

	/** An optional id argument: 0 when absent, the id, or null when it is not a positive whole number. */
	private static function opt_id( array $args, $key ) {
		if ( ! isset( $args[ $key ] ) || 0 === $args[ $key ] || '0' === $args[ $key ] || '' === $args[ $key ] ) {
			return 0;
		}
		$id = Form_Entry_Privacy::positive_int( $args[ $key ] );
		return $id > 0 ? $id : null;
	}

	/** An optional short word (a status, a type): '' when absent, null when it is not one. */
	private static function opt_word( array $args, $key ) {
		if ( ! isset( $args[ $key ] ) || '' === $args[ $key ] ) {
			return '';
		}
		$v = $args[ $key ];
		// "0" is refused: Royal Affiliate reads it as "no filter" while a count would filter on it.
		return ( is_string( $v ) && '0' !== $v && strlen( $v ) <= 40 && 1 === preg_match( '/^[A-Za-z0-9_:\-]+$/', $v ) ) ? strtolower( $v ) : null;
	}

	/** An optional search text: '' when absent, null when it is not usable. */
	private static function opt_search( array $args ) {
		if ( ! isset( $args['search'] ) || '' === $args['search'] ) {
			return '';
		}
		return ( is_string( $args['search'] ) && strlen( $args['search'] ) <= 100 ) ? trim( sanitize_text_field( $args['search'] ) ) : null;
	}

	/**
	 * page, per_page, orderby and order of a list.
	 *
	 * @return array{0:int,1:int,2:string,3:string}|string The values, or what is wrong.
	 */
	private static function paging( array $args, $list, $default_orderby ) {
		$page = isset( $args['page'] ) ? Form_Entry_Privacy::positive_int( $args['page'] ) : 1;
		$per  = isset( $args['per_page'] ) ? Form_Entry_Privacy::positive_int( $args['per_page'] ) : self::DEFAULT_PER_PAGE;
		if ( $page < 1 || $page > 100000 ) {
			return 'page must be a positive whole number.';
		}
		if ( $per < 1 || $per > self::MAX_PER_PAGE ) {
			return sprintf( 'per_page must be between 1 and %d.', self::MAX_PER_PAGE );
		}
		$orderby = $args['orderby'] ?? $default_orderby;
		if ( ! is_string( $orderby ) || ! in_array( $orderby, self::ORDERBY[ $list ], true ) ) {
			return 'orderby must be one of: ' . implode( ', ', self::ORDERBY[ $list ] ) . '.';
		}
		$order = isset( $args['order'] ) && is_string( $args['order'] ) ? strtoupper( $args['order'] ) : ( isset( $args['order'] ) ? '' : 'DESC' );
		if ( ! in_array( $order, [ 'ASC', 'DESC' ], true ) ) {
			return 'order must be ASC or DESC.';
		}
		return [ $page, $per, $orderby, $order ];
	}

	/**
	 * date_from and date_to as the site-time date-times Royal Affiliate stores.
	 *
	 * @return array{0:string,1:string}|string The two bounds ('' when not given), or what is wrong.
	 */
	private static function date_range( array $args, $required ) {
		$parse = static function ( $v, $end ) {
			if ( null === $v || '' === $v ) {
				return '';
			}
			if ( ! is_string( $v ) || strlen( $v ) > 30 ) {
				return false;
			}
			$v = trim( $v );
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
				$d = \DateTime::createFromFormat( '!Y-m-d', $v, wp_timezone() );
				return ( $d && $d->format( 'Y-m-d' ) === $v ) ? $v . ( $end ? ' 23:59:59' : ' 00:00:00' ) : false;
			}
			if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?(Z|[+\-]\d{2}:?\d{2})?$/', $v, $m )
				|| ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) ( $m[6] ?? 0 ) > 59 ) {
				return false;
			}
			try {
				$d = new \DateTime( $v, wp_timezone() );
			} catch ( \Exception $e ) {
				return false;
			}
			$d->setTimezone( wp_timezone() );
			return $d->format( 'Y-m-d H:i:s' );
		};
		$a = $parse( $args['date_from'] ?? null, false );
		$b = $parse( $args['date_to'] ?? null, true );
		if ( false === $a || false === $b ) {
			return 'date_from and date_to must be dates (2026-10-01) or date-times (2026-10-01T09:00), in the site time zone.';
		}
		if ( $required && ( '' === $a || '' === $b ) ) {
			return 'date_from and date_to are required.';
		}
		if ( '' !== $a && '' !== $b && $a > $b ) {
			return 'date_from is after date_to.';
		}
		return [ $a, $b ];
	}

	// ------------------------------------------------------------------
	// Output
	// ------------------------------------------------------------------

	/**
	 * Text someone wrote, made plain and shortened, with email addresses, phone numbers, network
	 * addresses, links, paths, browser strings, host names and long codes replaced. A value that is
	 * JSON is not returned: only the names of what it holds are.
	 */
	private static function clean_text( $value, $max = 300 ) {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return '';
		}
		$text = trim( (string) wp_check_invalid_utf8( wp_strip_all_tags( (string) $value ), true ) );
		if ( '' !== $text && ( '{' === $text[0] || '[' === $text[0] ) ) {
			$data = json_decode( $text, true );
			if ( is_array( $data ) ) {
				$keys = self::plain_keys( $data );
				return $keys ? '[data: ' . implode( ', ', $keys ) . ']' : '[data hidden]';
			}
		}
		$text = Form_Entry_Privacy::hide_emails( $text );
		$subs = [
			'#\b[a-z][a-z0-9+.\-]*://\S+#i'                                                        => '[link hidden]',
			'#\b[A-Za-z]+/\d[\w.]*(?:\s*\([^)]{0,200}\))?(?:\s+[A-Za-z]+/\d[\w.]*(?:\s*\([^)]{0,200}\))?)*#' => '[browser hidden]',
			'#(?<![\w.])(?:[A-Za-z]:)?(?:[\\/][\w.\-]+){2,}[\\/]?#'                             => '[path hidden]',
			'#(?<![\d.])\d{1,3}(?:\s*[.\-]\s*\d{1,3}){3}(?![\d.])#'                             => '[address hidden]',
			'#(?<![\w:])(?:[0-9a-f]{1,4})?(?::[0-9a-f]{0,4}){2,7}(?![\w:])#i'                       => '[address hidden]',
			'#\b(?:[a-z0-9\-]+\.)+(?:' . self::HOST_ENDINGS . ')\b#i'                              => '[host hidden]',
			'#\b(?=[A-Za-z0-9]*[A-Za-z])(?=[A-Za-z0-9]*\d)[A-Za-z0-9]{16,}\b#'                      => '[code hidden]',
		];
		foreach ( $subs as $pattern => $with ) {
			$text = (string) preg_replace( $pattern, $with, $text );
		}
		$text = Form_Entry_Privacy::hide_form_phones( $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		if ( mb_strlen( $text ) > $max ) {
			$text = rtrim( (string) preg_replace( '/\s+\S*$/u', '', mb_substr( $text, 0, $max + 1 ) ) ) . '…';
		}
		return '' === $text ? '[hidden]' : $text;
	}

	/** Names of what a decoded value holds: plain lower-case words only, none for a plain list. */
	private static function plain_keys( array $data ) {
		$out = [];
		foreach ( array_keys( $data ) as $k ) {
			if ( is_string( $k ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $k ) && count( $out ) < 20 ) {
				$out[] = $k;
			}
		}
		return $out;
	}

	/** A code as the plugin stores it (a status, a type, a currency, "lead_wpforms:12", "728x90"), or '' when the value is not one. */
	private static function code( $v ) {
		return ( is_scalar( $v ) && 1 === preg_match( '/^[A-Za-z0-9_:\-]{1,60}$/', (string) $v ) ) ? (string) $v : '';
	}

	/** A link without any login, query string or fragment; '' when it is not http or https. */
	private static function plain_url( $v ) {
		$parts = is_string( $v ) && strlen( $v ) <= 500 ? wp_parse_url( $v ) : false;
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) ) {
			return '';
		}
		return esc_url_raw( strtolower( $parts['scheme'] ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . ( $parts['path'] ?? '/' ), [ 'http', 'https' ] );
	}

	/** One row with only the named fields of its list. */
	private static function row( $row, $list ) {
		$row = (array) $row;
		$out = [];
		foreach ( self::FIELDS[ $list ] as $key => $kind ) {
			$v = $row[ $key ] ?? null;
			if ( null === $v ) {
				$out[ $key ] = null;
			} elseif ( 'int' === $kind ) {
				$out[ $key ] = (int) $v;
			} elseif ( 'money' === $kind ) {
				$out[ $key ] = is_numeric( $v ) ? round( (float) $v, 2 ) : null;
			} elseif ( 'date' === $kind ) {
				$out[ $key ] = ( is_string( $v ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/', $v ) && 0 !== strpos( $v, '0000' ) ) ? $v : null;
			} elseif ( 'url' === $kind ) {
				$out[ $key ] = self::plain_url( $v );
			} elseif ( 'code' === $kind ) {
				$out[ $key ] = self::code( $v );
			} else {
				$out[ $key ] = self::clean_text( $v, 'label' === $kind ? 120 : 300 );
			}
		}
		return $out;
	}

	private static function page_meta( $page, $per, $total ) {
		$pages = $per > 0 ? (int) ceil( $total / $per ) : 0;
		return [ 'page' => $page, 'per_page' => $per, 'total_count' => (int) $total, 'total_pages' => $pages, 'has_next' => $page < $pages ];
	}

	/** A name to show for an affiliate: the display name, unless it is empty or holds contact details. */
	private static function affiliate_label( $affiliate_id, $display_name ) {
		$name = trim( SafeText::field( (string) $display_name ) );
		// A name that holds an email address (however it is written), a phone number or a link is not shown.
		return ( '' === $name || false !== strpos( $name, '@' ) || self::clean_text( $name, 200 ) !== (string) preg_replace( '/\s+/u', ' ', $name ) ) ? sprintf( 'Affiliate #%d', (int) $affiliate_id ) : mb_substr( $name, 0, 100 );
	}

	// ------------------------------------------------------------------
	// Affiliates
	// ------------------------------------------------------------------

	private static function list_affiliates( array $args ) {
		$status = self::opt_word( $args, 'status' );
		$search = self::opt_search( $args );
		$paging = self::paging( [ 'page' => $args['page'] ?? 1, 'per_page' => $args['per_page'] ?? self::DEFAULT_PER_PAGE ], 'referrals', 'referral_id' );
		if ( null === $status || null === $search || is_string( $paging ) || false !== strpos( (string) $search, '@' ) ) {
			return Envelope::error( 'invalid_args', is_string( $paging ) ? $paging : 'status must be one word and search a text of at most 100 characters without @ (email addresses are not searched).' );
		}
		list( $page, $per ) = $paging;
		global $wpdb;
		$where = [ '1=1' ];
		if ( '' !== $status ) {
			$where[] = $wpdb->prepare( 'a.status = %s', $status );
		}
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			// A display name that is an email address is never shown, so it is not searched either.
			$where[] = $wpdb->prepare( "( ( u.display_name LIKE %s AND u.display_name NOT LIKE '%%@%%' ) OR a.referral_code LIKE %s )", $like, $like );
		}
		$from = "FROM {$wpdb->prefix}rafp_affiliates a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id WHERE " . implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- every value in $from is bound above
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) {$from}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every value in $from is bound above
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT a.affiliate_id, a.status, a.date_registered, u.display_name {$from} ORDER BY a.affiliate_id ASC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ), ARRAY_A );
		$list = [];
		foreach ( $rows as $r ) {
			$list[] = [ 'affiliate_id' => (int) $r['affiliate_id'], 'label' => self::affiliate_label( (int) $r['affiliate_id'], $r['display_name'] ?? '' ), 'status' => sanitize_key( (string) $r['status'] ), 'date_registered' => (string) $r['date_registered'] ];
		}
		return Envelope::success( sprintf( '%d affiliate(s) of %d.', count( $list ), $total ), [ 'affiliates' => $list, 'pagination' => self::page_meta( $page, $per, $total ), 'timezone' => wp_timezone_string() ] );
	}

	private static function get_affiliate_performance( array $args ) {
		$id = Form_Entry_Privacy::positive_int( $args['affiliate_id'] ?? null );
		if ( $id <= 0 ) {
			return Envelope::error( 'invalid_args', 'affiliate_id must be a positive whole number.' );
		}
		if ( ! is_object( \Royal_Affiliate_Affiliate::get( $id ) ) ) {
			return Envelope::error( 'not_found', 'Affiliate not found.' );
		}
		$stats = \Royal_Affiliate_Affiliate::get_stats( $id );
		if ( ! is_object( $stats ) ) {
			return Envelope::error( 'not_supported', 'Royal Affiliate returned no statistics for this affiliate.' );
		}
		$ref   = isset( $stats->referrals ) ? (array) $stats->referrals : [];
		$earn  = isset( $stats->earnings ) ? (array) $stats->earnings : [];
		$money = static function ( $v ) { return is_numeric( $v ) ? round( (float) $v, 2 ) : 0.0; };
		$out   = [
			'affiliate_id'    => $id,
			'visits'          => (int) ( $stats->visits ?? 0 ),
			'conversion_rate' => is_numeric( str_replace( ',', '', (string) ( $stats->conversion_rate ?? '0' ) ) ) ? round( (float) str_replace( ',', '', (string) $stats->conversion_rate ), 2 ) : 0.0,
			'referrals'       => [ 'total' => (int) ( $ref['total'] ?? 0 ), 'approved' => (int) ( $ref['approved'] ?? 0 ), 'pending' => (int) ( $ref['pending'] ?? 0 ), 'rejected' => (int) ( $ref['rejected'] ?? 0 ) ],
			'earnings'        => [ 'paid' => $money( $earn['paid'] ?? 0 ), 'unpaid' => $money( $earn['unpaid'] ?? 0 ), 'pending' => $money( $earn['pending'] ?? 0 ), 'total' => $money( $earn['total'] ?? 0 ) ],
		];
		return Envelope::success( sprintf( 'Affiliate %d: %d visit(s), %d referral(s) (%d approved), %.2f total earnings.', $id, $out['visits'], $out['referrals']['total'], $out['referrals']['approved'], $out['earnings']['total'] ), $out );
	}

	// ------------------------------------------------------------------
	// Referrals
	// ------------------------------------------------------------------

	private static function get_referral_records( array $args ) {
		$aid     = self::opt_id( $args, 'affiliate_id' );
		$status  = self::opt_word( $args, 'status' );
		$context = self::opt_word( $args, 'context' );
		$range   = self::date_range( $args, false );
		$paging  = self::paging( $args, 'referrals', 'date_created' );
		foreach ( [ $range, $paging ] as $problem ) {
			if ( is_string( $problem ) ) {
				return Envelope::error( 'invalid_args', $problem );
			}
		}
		if ( null === $aid || null === $status || null === $context ) {
			return Envelope::error( 'invalid_args', 'affiliate_id must be a positive whole number; status and context must each be one word.' );
		}
		list( $from, $to )                    = $range;
		list( $page, $per, $orderby, $order ) = $paging;
		$rows = (array) \Royal_Affiliate_Referral::get_all( [ 'affiliate_id' => $aid, 'status' => $status, 'context' => $context, 'date_from' => $from, 'date_to' => $to, 'orderby' => $orderby, 'order' => $order, 'limit' => $per, 'offset' => ( $page - 1 ) * $per ] );
		global $wpdb;
		$where = [ '1=1' ];
		foreach ( [ [ 'affiliate_id = %d', $aid, $aid > 0 ], [ 'status = %s', $status, '' !== $status ], [ 'context = %s', $context, '' !== $context ], [ 'date_created >= %s', $from, '' !== $from ], [ 'date_created <= %s', $to, '' !== $to ] ] as $c ) {
			if ( $c[2] ) {
				$where[] = $wpdb->prepare( $c[0], $c[1] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed fragments from the list above
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- every value is bound above
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rafp_referrals WHERE " . implode( ' AND ', $where ) );
		$list  = [];
		foreach ( $rows as $row ) {
			$list[] = self::row( $row, 'referrals' );
		}
		return Envelope::success(
			sprintf( '%d referral(s) of %d.', count( $list ), $total ),
			[ 'referrals' => $list, 'pagination' => self::page_meta( $page, $per, $total ), 'filters' => [ 'affiliate_id' => $aid, 'status' => $status, 'context' => $context, 'date_from' => $from, 'date_to' => $to, 'orderby' => $orderby, 'order' => $order ], 'timezone' => wp_timezone_string() ]
		);
	}

	private static function get_period_summary( array $args ) {
		$range = self::date_range( $args, true );
		if ( is_string( $range ) ) {
			return Envelope::error( 'invalid_args', $range );
		}
		list( $from, $to ) = $range;
		global $wpdb;
		$ref = $wpdb->prefix . 'rafp_referrals';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names built from the prefix; values bound
		$by_status = (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, currency, COUNT(*) AS n, SUM(amount) AS amount, SUM(commission_amount) AS commission FROM {$ref} WHERE date_created BETWEEN %s AND %s GROUP BY status, currency LIMIT 200", $from, $to ), ARRAY_A );
		$visits    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}rafp_visits WHERE date_created BETWEEN %s AND %s", $from, $to ) );
		$top       = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT r.affiliate_id, COUNT(*) AS n, SUM(r.commission_amount) AS commission, MAX(u.display_name) AS display_name
			 FROM {$ref} r LEFT JOIN {$wpdb->prefix}rafp_affiliates a ON a.affiliate_id = r.affiliate_id LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
			 WHERE r.date_created BETWEEN %s AND %s AND r.status IN ( 'approved', 'paid' )
			 GROUP BY r.affiliate_id ORDER BY commission DESC, n DESC LIMIT 10",
			$from,
			$to
		), ARRAY_A );
		$fraud = (array) $wpdb->get_results( $wpdb->prepare( "SELECT severity, COUNT(*) AS n FROM {$wpdb->prefix}rafp_fraud_log WHERE date_created BETWEEN %s AND %s GROUP BY severity LIMIT 20", $from, $to ), ARRAY_A );
		// phpcs:enable
		$referrals = [];
		foreach ( [ 'approved', 'pending', 'rejected', 'paid' ] as $s ) {
			$referrals[ $s ] = [ 'count' => 0, 'amount' => 0.0, 'commission' => 0.0 ];
		}
		$currencies = [];
		foreach ( $by_status as $r ) {
			$s = (string) $r['status'];
			if ( ! isset( $referrals[ $s ] ) || 'other' === $s ) {
				$s = 'other';
				if ( ! isset( $referrals[ $s ] ) ) {
					$referrals[ $s ] = [ 'count' => 0, 'amount' => 0.0, 'commission' => 0.0 ];
				}
			}
			$referrals[ $s ]['count']     += (int) $r['n'];
			$referrals[ $s ]['amount']     = round( $referrals[ $s ]['amount'] + (float) $r['amount'], 2 );
			$referrals[ $s ]['commission'] = round( $referrals[ $s ]['commission'] + (float) $r['commission'], 2 );
			$cur                           = 1 === preg_match( '/^[A-Za-z]{3}$/', (string) $r['currency'] ) ? strtoupper( (string) $r['currency'] ) : 'unknown';
			$currencies[ $cur ]            = ( $currencies[ $cur ] ?? 0 ) + (int) $r['n'];
		}
		arsort( $currencies );
		// Real currencies first, most used first; rows with no usable currency last.
		if ( isset( $currencies['unknown'] ) ) {
			$unknown = $currencies['unknown'];
			unset( $currencies['unknown'] );
			$currencies['unknown'] = $unknown;
		}
		$known    = array_diff( array_keys( $currencies ), [ 'unknown' ] );
		$currency = $known ? (string) reset( $known ) : ( function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'USD' );
		$severity = [ 'high' => 0, 'medium' => 0, 'low' => 0 ];
		foreach ( $fraud as $f ) {
			$k              = isset( $severity[ (string) $f['severity'] ] ) ? (string) $f['severity'] : 'other';
			$severity[ $k ] = ( $severity[ $k ] ?? 0 ) + (int) $f['n'];
		}
		$out = [
			'date_from'         => $from,
			'date_to'           => $to,
			'timezone'          => wp_timezone_string(),
			'currency'          => $currency,
			'referrals'         => $referrals,
			'visits'            => $visits,
			'top_affiliates'    => array_map( static function ( $r ) {
				return [ 'affiliate_id' => (int) $r['affiliate_id'], 'label' => self::affiliate_label( (int) $r['affiliate_id'], $r['display_name'] ?? '' ), 'referrals' => (int) $r['n'], 'commission' => round( (float) $r['commission'], 2 ) ];
			}, $top ),
			'fraud_by_severity' => $severity,
		];
		if ( count( $currencies ) > 1 ) {
			// Amounts in different currencies were added together above; the totals are only a count guide then.
			$out['mixed_currencies'] = array_keys( $currencies );
		}
		return Envelope::success( sprintf( '%s to %s: %d approved referral(s), %s %.2f commission approved, %d visit(s).', substr( $from, 0, 10 ), substr( $to, 0, 10 ), $referrals['approved']['count'], $currency, $referrals['approved']['commission'], $visits ), $out );
	}

	// ------------------------------------------------------------------
	// Campaigns, creatives, landing pages
	// ------------------------------------------------------------------

	private static function list_campaigns( array $args ) {
		$status = self::opt_word( $args, 'status' );
		$search = self::opt_search( $args );
		$paging = self::paging( $args, 'campaigns', 'date_created' );
		if ( null === $status || null === $search || is_string( $paging ) ) {
			return Envelope::error( 'invalid_args', is_string( $paging ) ? $paging : 'status must be one word and search a text of at most 100 characters.' );
		}
		list( $page, $per, $orderby, $order ) = $paging;
		$rows  = (array) \Royal_Affiliate_Campaign::get_all( [ 'status' => $status, 'search' => $search, 'orderby' => $orderby, 'order' => $order, 'limit' => $per, 'offset' => ( $page - 1 ) * $per ] );
		global $wpdb;
		$where = [ '1=1' ];
		if ( '' !== $status ) {
			$where[] = $wpdb->prepare( 'status = %s', $status );
		}
		if ( '' !== $search ) {
			// The same three columns Royal Affiliate's list searches.
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = $wpdb->prepare( '( name LIKE %s OR description LIKE %s OR slug LIKE %s )', $like, $like, $like );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- every value is bound above
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rafp_campaigns WHERE " . implode( ' AND ', $where ) );
		$list  = [];
		foreach ( $rows as $row ) {
			$list[] = self::row( $row, 'campaigns' );
		}
		return Envelope::success( sprintf( '%d campaign(s) of %d.', count( $list ), $total ), [ 'campaigns' => $list, 'pagination' => self::page_meta( $page, $per, $total ), 'filters' => [ 'status' => $status, 'search' => $search, 'orderby' => $orderby, 'order' => $order ] ] );
	}

	/** A campaign row, or an error reply. */
	private static function campaign( array $args ) {
		$id = Form_Entry_Privacy::positive_int( $args['campaign_id'] ?? null );
		if ( $id <= 0 ) {
			return Envelope::error( 'invalid_args', 'campaign_id must be a positive whole number.' );
		}
		$row = \Royal_Affiliate_Campaign::get( $id );
		return is_object( $row ) ? $row : Envelope::error( 'not_found', 'Campaign not found.' );
	}

	private static function get_campaign( array $args ) {
		$row = self::campaign( $args );
		if ( is_array( $row ) ) {
			return $row;
		}
		$campaign = self::row( $row, 'campaigns' );
		return Envelope::success( sprintf( 'Campaign %d "%s", status %s.', $campaign['campaign_id'], $campaign['name'], $campaign['status'] ), [ 'campaign' => $campaign ] );
	}

	private static function get_campaign_performance( array $args ) {
		$row = self::campaign( $args );
		if ( is_array( $row ) ) {
			return $row;
		}
		$campaign = self::row( $row, 'campaigns' );
		$stats    = \Royal_Affiliate_Campaign::get_stats( $campaign['campaign_id'] );
		if ( ! is_object( $stats ) ) {
			return Envelope::error( 'not_supported', 'Royal Affiliate returned no statistics for this campaign.' );
		}
		$ref = isset( $stats->referrals ) ? (array) $stats->referrals : [];
		$out = [
			'campaign_id'     => $campaign['campaign_id'],
			'campaign_name'   => $campaign['name'],
			'visits'          => (int) ( $stats->visits ?? 0 ),
			'conversion_rate' => is_numeric( $stats->conversion_rate ?? null ) ? round( (float) $stats->conversion_rate, 2 ) : 0.0,
			'referrals'       => [ 'total' => (int) ( $ref['total'] ?? 0 ), 'approved' => (int) ( $ref['approved'] ?? 0 ), 'total_commission' => is_numeric( $ref['total_commission'] ?? null ) ? round( (float) $ref['total_commission'], 2 ) : 0.0 ],
		];
		return Envelope::success( sprintf( 'Campaign %d: %d visit(s), %d referral(s) (%d approved), %.2f commission.', $out['campaign_id'], $out['visits'], $out['referrals']['total'], $out['referrals']['approved'], $out['referrals']['total_commission'] ), $out );
	}

	private static function list_creatives( array $args ) {
		if ( ! class_exists( 'Royal_Affiliate_Creative' ) ) {
			return Envelope::error( 'not_supported', 'This Royal Affiliate version has no creatives.' );
		}
		$type   = self::opt_word( $args, 'type' );
		$status = self::opt_word( $args, 'status' );
		$cid    = self::opt_id( $args, 'campaign_id' );
		$paging = self::paging( $args, 'creatives', 'date_created' );
		if ( null === $type || null === $status || null === $cid || is_string( $paging ) ) {
			return Envelope::error( 'invalid_args', is_string( $paging ) ? $paging : 'campaign_id must be a positive whole number; type and status must each be one word.' );
		}
		list( $page, $per, $orderby, $order ) = $paging;
		$rows = (array) \Royal_Affiliate_Creative::get_all( [ 'type' => $type, 'status' => $status, 'campaign_id' => $cid, 'orderby' => $orderby, 'order' => $order, 'limit' => $per, 'offset' => ( $page - 1 ) * $per ] );
		global $wpdb;
		$where = [ '1=1' ];
		foreach ( [ [ 'type = %s', $type, '' !== $type ], [ 'status = %s', $status, '' !== $status ], [ 'campaign_id = %d', $cid, $cid > 0 ] ] as $c ) {
			if ( $c[2] ) {
				$where[] = $wpdb->prepare( $c[0], $c[1] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed fragments from the list above
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- every value is bound above
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rafp_creatives WHERE " . implode( ' AND ', $where ) );
		$list  = [];
		foreach ( $rows as $row ) {
			$list[] = self::row( $row, 'creatives' );
		}
		return Envelope::success( sprintf( '%d creative(s) of %d.', count( $list ), $total ), [ 'creatives' => $list, 'pagination' => self::page_meta( $page, $per, $total ), 'filters' => [ 'type' => $type, 'status' => $status, 'campaign_id' => $cid, 'orderby' => $orderby, 'order' => $order ] ] );
	}

	private static function list_landing_pages( array $args ) {
		if ( ! class_exists( 'Royal_Affiliate_Landing_Page' ) || ! method_exists( 'Royal_Affiliate_Landing_Page', 'get_all' ) || ! method_exists( 'Royal_Affiliate_Landing_Page', 'count' ) ) {
			return Envelope::error( 'not_supported', 'Listing landing pages needs Royal Affiliate Pro 2.6.15 or later.' );
		}
		$aid    = self::opt_id( $args, 'affiliate_id' );
		$search = self::opt_search( $args );
		$state  = $args['page_status'] ?? 'publish';
		$paging = self::paging( $args, 'landing', 'date_modified' );
		if ( null === $aid || null === $search || is_string( $paging ) || ! is_string( $state ) || ! in_array( $state, [ 'publish', 'draft', 'pending', 'private', 'any' ], true ) ) {
			return Envelope::error( 'invalid_args', is_string( $paging ) ? $paging : 'affiliate_id must be a positive whole number, search a short text, and page_status one of publish, draft, pending, private, any.' );
		}
		list( $page, $per, $orderby, $order ) = $paging;
		$query = [ 'affiliate_id' => $aid, 'page_status' => $state, 'search' => $search ];
		$rows  = (array) \Royal_Affiliate_Landing_Page::get_all( $query + [ 'orderby' => $orderby, 'order' => $order, 'limit' => $per, 'offset' => ( $page - 1 ) * $per ] );
		$total = (int) \Royal_Affiliate_Landing_Page::count( $query );
		$list  = [];
		$kept  = 0;
		foreach ( $rows as $row ) {
			// A page the caller may not read (someone else's private page or draft) is not listed.
			if ( current_user_can( 'read_post', (int) ( ( (array) $row )['page_id'] ?? 0 ) ) ) {
				$list[] = self::row( $row, 'landing' );
			} else {
				++$kept;
			}
		}
		return Envelope::success( sprintf( '%d landing page(s) of %d.', count( $list ), $total ), [ 'landing_pages' => $list, 'not_shown' => $kept, 'pagination' => self::page_meta( $page, $per, $total ), 'filters' => $query + [ 'orderby' => $orderby, 'order' => $order ] ] );
	}

	// ------------------------------------------------------------------
	// Payouts, fraud, activity
	// ------------------------------------------------------------------

	private static function list_payouts( array $args ) {
		if ( ! class_exists( 'Royal_Affiliate_Payout' ) ) {
			return Envelope::error( 'not_supported', 'This Royal Affiliate version has no payouts.' );
		}
		$aid    = self::opt_id( $args, 'affiliate_id' );
		$status = self::opt_word( $args, 'status' );
		$paging = self::paging( $args, 'payouts', 'date_created' );
		if ( null === $aid || null === $status || is_string( $paging ) ) {
			return Envelope::error( 'invalid_args', is_string( $paging ) ? $paging : 'affiliate_id must be a positive whole number and status one word.' );
		}
		list( $page, $per, $orderby, $order ) = $paging;
		$rows  = (array) \Royal_Affiliate_Payout::get_all( [ 'affiliate_id' => $aid, 'status' => $status, 'orderby' => $orderby, 'order' => $order, 'limit' => $per, 'offset' => ( $page - 1 ) * $per ] );
		$total = (int) \Royal_Affiliate_Payout::count( [ 'affiliate_id' => $aid, 'status' => $status ] );
		$list  = [];
		foreach ( $rows as $row ) {
			$one                       = self::row( $row, 'payouts' );
			$one['has_transaction_id'] = '' !== trim( (string) ( ( (array) $row )['transaction_id'] ?? '' ) );
			$list[]                    = $one;
		}
		return Envelope::success( sprintf( '%d payout(s) of %d.', count( $list ), $total ), [ 'payouts' => $list, 'pagination' => self::page_meta( $page, $per, $total ), 'filters' => [ 'affiliate_id' => $aid, 'status' => $status, 'orderby' => $orderby, 'order' => $order ] ] );
	}

	private static function get_fraud_incidents( array $args ) {
		$severity = self::opt_word( $args, 'severity' );
		$aid      = self::opt_id( $args, 'affiliate_id' );
		$range    = self::date_range( $args, false );
		$paging   = self::paging( $args, 'fraud', 'date_created' );
		foreach ( [ $range, $paging ] as $problem ) {
			if ( is_string( $problem ) ) {
				return Envelope::error( 'invalid_args', $problem );
			}
		}
		if ( null === $aid || null === $severity || ( '' !== $severity && ! in_array( $severity, [ 'low', 'medium', 'high' ], true ) ) ) {
			return Envelope::error( 'invalid_args', 'severity must be low, medium or high, and affiliate_id a positive whole number.' );
		}
		list( $from, $to )                    = $range;
		list( $page, $per, $orderby, $order ) = $paging;
		global $wpdb;
		$where = [ '1=1' ];
		foreach ( [ [ 'severity = %s', $severity, '' !== $severity ], [ 'affiliate_id = %d', $aid, $aid > 0 ], [ 'date_created >= %s', $from, '' !== $from ], [ 'date_created <= %s', $to, '' !== $to ] ] as $c ) {
			if ( $c[2] ) {
				$where[] = $wpdb->prepare( $c[0], $c[1] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed fragments from the list above
			}
		}
		$sql_where = implode( ' AND ', $where );
		$cols      = implode( ', ', array_keys( self::FIELDS['fraud'] ) );
		// $orderby and $order come from fixed lists (paging()); every value in $sql_where is bound above.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$cols} FROM {$wpdb->prefix}rafp_fraud_log WHERE {$sql_where} ORDER BY {$orderby} {$order}, log_id DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ), ARRAY_A );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rafp_fraud_log WHERE {$sql_where}" );
		// phpcs:enable
		$list = [];
		foreach ( $rows as $row ) {
			$list[] = self::row( $row, 'fraud' );
		}
		return Envelope::success( sprintf( '%d fraud incident(s) of %d.', count( $list ), $total ), [ 'incidents' => $list, 'pagination' => self::page_meta( $page, $per, $total ), 'filters' => [ 'severity' => $severity, 'affiliate_id' => $aid, 'date_from' => $from, 'date_to' => $to, 'orderby' => $orderby, 'order' => $order ], 'timezone' => wp_timezone_string() ] );
	}

	private static function get_activity_log( array $args ) {
		if ( ! class_exists( 'Royal_Affiliate_Activity_Log' ) || ! method_exists( 'Royal_Affiliate_Activity_Log', 'get_all' ) || ! method_exists( 'Royal_Affiliate_Activity_Log', 'count' ) ) {
			return Envelope::error( 'not_supported', 'Reading the activity log needs Royal Affiliate Pro 2.6.15 or later.' );
		}
		$action = self::opt_word( $args, 'action' );
		$uid    = self::opt_id( $args, 'user_id' );
		$range  = self::date_range( $args, false );
		$paging = self::paging( $args, 'activity', 'date_created' );
		foreach ( [ $range, $paging ] as $problem ) {
			if ( is_string( $problem ) ) {
				return Envelope::error( 'invalid_args', $problem );
			}
		}
		if ( null === $action || null === $uid ) {
			return Envelope::error( 'invalid_args', 'action must be one word and user_id a positive whole number.' );
		}
		list( $from, $to )                    = $range;
		list( $page, $per, $orderby, $order ) = $paging;
		$query = [ 'action' => $action, 'user_id' => $uid, 'date_from' => $from, 'date_to' => $to ];
		$rows  = (array) \Royal_Affiliate_Activity_Log::get_all( $query + [ 'orderby' => $orderby, 'order' => $order, 'limit' => $per, 'offset' => ( $page - 1 ) * $per ] );
		$total = (int) \Royal_Affiliate_Activity_Log::count( $query );
		$list  = [];
		foreach ( $rows as $row ) {
			$one  = self::row( $row, 'activity' );
			$meta = ( (array) $row )['meta'] ?? '';
			$meta = is_string( $meta ) && '' !== $meta ? json_decode( $meta, true ) : $meta;
			// What the entry carries besides its message is named, not returned: it can hold anyone's details.
			$one['meta_keys'] = is_array( $meta ) ? self::plain_keys( $meta ) : [];
			$list[]           = $one;
		}
		return Envelope::success( sprintf( '%d log entr%s of %d.', count( $list ), 1 === count( $list ) ? 'y' : 'ies', $total ), [ 'entries' => $list, 'pagination' => self::page_meta( $page, $per, $total ), 'filters' => $query + [ 'orderby' => $orderby, 'order' => $order ], 'timezone' => wp_timezone_string() ] );
	}

	// ------------------------------------------------------------------
	// Tool definitions
	// ------------------------------------------------------------------

	private static function schemas() {
		$id    = static function ( $what ) { return [ 'type' => 'integer', 'minimum' => 1, 'description' => $what ]; };
		$word  = static function ( $what ) { return [ 'type' => 'string', 'maxLength' => 40, 'description' => $what ]; };
		$dates = [
			'date_from' => [ 'type' => 'string', 'description' => 'First day or moment, site time: 2026-10-01 or 2026-10-01T09:00.' ],
			'date_to'   => [ 'type' => 'string', 'description' => 'Last day or moment, site time. A day alone includes the whole day.' ],
		];
		$page  = static function ( $list ) {
			return [
				'page'     => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Default 1.' ],
				'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'description' => 'Default 50, up to 100.' ],
				'orderby'  => [ 'type' => 'string', 'enum' => self::ORDERBY[ $list ] ],
				'order'    => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'description' => 'Default DESC.' ],
			];
		};
		$tool  = static function ( $name, $description, array $props, array $required = [] ) {
			$schema = [ 'type' => 'object', 'properties' => $props ? $props : new \stdClass(), 'additionalProperties' => false ];
			if ( $required ) {
				$schema['required'] = $required;
			}
			return [ 'name' => $name, 'description' => $description . ' Read-only. Requires Royal Affiliate Pro and manage_options.', 'inputSchema' => $schema ];
		};
		$out   = [
			$tool( 'rafp_list_affiliates', 'List Royal Affiliate affiliates: affiliates [{affiliate_id, label, status, date_registered}] with pagination. label is the display name, or "Affiliate #id" when the name is empty or holds contact details; email and payment details are never returned. Filter by status (such as active or pending) or search the display name and referral code (email addresses are not searched).', [ 'status' => $word( 'Affiliate status.' ), 'search' => [ 'type' => 'string', 'maxLength' => 100 ], 'page' => [ 'type' => 'integer', 'minimum' => 1 ], 'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ] ] ),
			$tool( 'rafp_get_affiliate_performance', 'Get one affiliate\'s totals from Royal Affiliate: visits, conversion_rate (percent), referrals {total, approved, pending, rejected} and earnings {paid, unpaid, pending, total}.', [ 'affiliate_id' => $id( 'The affiliate ID.' ) ], [ 'affiliate_id' ] ),
			$tool( 'rafp_get_referral_records', 'List referrals (commissions): referrals [{referral_id, affiliate_id, reference, reference_type, amount, commission_amount, commission_rate, commission_type, status, campaign_id, currency, context, payout_id, date_created, date_approved, ...}] with pagination. Filter by affiliate_id, status (pending, approved, rejected, paid), context and a date range in site time. The customer, the visitor\'s IP address and browser, and the free-text description are never returned; a reference that is not a plain number or code has contact details replaced.', [ 'affiliate_id' => $id( 'One affiliate only.' ), 'status' => $word( 'Referral status.' ), 'context' => $word( 'Where the referral came from, such as woocommerce.' ) ] + $dates + $page( 'referrals' ) ),
			$tool( 'rafp_get_period_summary', 'Totals for a period, in site time: referrals by status {count, amount, commission} (approved does not include paid; statuses other than the four are counted under other), visits, the ten affiliates with the most commission on approved and paid referrals, and fraud incidents by severity. currency is the one most referrals used; mixed_currencies appears when there is more than one, and the amounts are then sums across currencies.', $dates, [ 'date_from', 'date_to' ] ),
			$tool( 'rafp_list_campaigns', 'List Royal Affiliate campaigns: campaigns [{campaign_id, name, slug, description, commission_rate, commission_type, status, start_date, end_date, date_created}] with pagination. Filter by status (active, inactive, ended) or search.', [ 'status' => $word( 'Campaign status.' ), 'search' => [ 'type' => 'string', 'maxLength' => 100 ] ] + $page( 'campaigns' ) ),
			$tool( 'rafp_get_campaign', 'Get one Royal Affiliate campaign by ID.', [ 'campaign_id' => $id( 'The campaign ID.' ) ], [ 'campaign_id' ] ),
			$tool( 'rafp_get_campaign_performance', 'Get one campaign\'s totals from Royal Affiliate: visits, conversion_rate (percent) and referrals {total, approved, total_commission}.', [ 'campaign_id' => $id( 'The campaign ID.' ) ], [ 'campaign_id' ] ),
			$tool( 'rafp_list_creatives', 'List the banners, links and texts affiliates can use: creatives [{creative_id, name, description, type, size, file_url, target_url, text_content, campaign_id, clicks, conversions, status, date_created}] with pagination. Filter by type, status and campaign_id.', [ 'type' => $word( 'Creative type, such as banner or text.' ), 'status' => $word( 'active or inactive.' ), 'campaign_id' => $id( 'One campaign only.' ) ] + $page( 'creatives' ) ),
			$tool( 'rafp_list_landing_pages', 'List the pages assigned to affiliates as landing pages: landing_pages [{page_id, affiliate_id, page_title, page_url, page_status, date_assigned, date_modified}] with pagination. page_status is publish by default; any also lists drafts and private pages. Pages the caller may not read are left out and counted in not_shown. Needs Royal Affiliate Pro 2.6.15 or later.', [ 'affiliate_id' => $id( 'One affiliate only.' ), 'search' => [ 'type' => 'string', 'maxLength' => 100 ], 'page_status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'any' ] ] ] + $page( 'landing' ) ),
			$tool( 'rafp_list_payouts', 'List affiliate payouts: payouts [{payout_id, affiliate_id, amount, currency, payment_method, status, referrals_count, date_created, date_paid, has_transaction_id}] with pagination. Filter by affiliate_id and status. Payment addresses, transaction ids and notes are never returned.', [ 'affiliate_id' => $id( 'One affiliate only.' ), 'status' => $word( 'Payout status.' ) ] + $page( 'payouts' ) ),
			$tool( 'rafp_get_fraud_incidents', 'List what Royal Affiliate\'s fraud checks recorded: incidents [{log_id, affiliate_id, visit_id, referral_id, fraud_type, severity, details, date_created}] with pagination. Filter by severity (low, medium, high), affiliate_id and a date range in site time. IP addresses and browsers are never returned. In details, email addresses, phone numbers, network addresses, links, paths, browser strings and long codes are replaced, and details stored as data are given as the names of what they hold; a person\'s name written in the text cannot be recognised and stays.', [ 'severity' => [ 'type' => 'string', 'enum' => [ 'low', 'medium', 'high' ] ], 'affiliate_id' => $id( 'One affiliate only.' ) ] + $dates + $page( 'fraud' ) ),
			$tool( 'rafp_get_activity_log', 'Read Royal Affiliate\'s activity log: entries [{id, user_id, action, message, date_created, meta_keys}] with pagination. Filter by action, user_id and a date range in site time. In messages, email addresses, phone numbers, network addresses, links, paths and long codes are replaced (a person\'s name stays); the extra data of an entry is named in meta_keys but not returned. Needs Royal Affiliate Pro 2.6.15 or later.', [ 'action' => $word( 'The action name.' ), 'user_id' => $id( 'Entries by one user.' ) ] + $dates + $page( 'activity' ) ),
		];
		return array_column( $out, null, 'name' );
	}
}
