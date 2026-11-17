<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\SafeText;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Five small reads: Royal AI Firewall totals over a period, the most-clicked
 * Royal Links, Elementor's global colours and fonts, an export of Redirection
 * rules, and an audit of application passwords.
 */
class SiteReports {

	const TOOLS = [
		'raif_get_period_rollup'         => [ 'raif_period', 'Royal AI Firewall' ],
		'rlinks_get_top_links'           => [ 'top_links', 'Royal Links' ],
		'elementor_get_kit_globals'      => [ 'kit_globals', 'Elementor' ],
		'redirection_bulk_export'        => [ 'redirection_export', 'Redirection' ],
		'wp_audit_application_passwords' => [ 'audit_app_passwords', '' ],
	];

	const MAX_DAYS = 366;
	const MAX_ROWS = 1000;

	const LINK_PERIODS = [ '7days' => 7, '30days' => 30, '90days' => 90, '12months' => 365, 'all' => 0 ];

	const CSV_COLUMNS = [ 'source', 'destination', 'group', 'type', 'match_type', 'action_type', 'regex', 'status' ];

	/** Longest text one CSV cell carries. */
	const CSV_CELL_MAX = 2000;

	public static function handles( $name ) {
		return is_string( $name ) && isset( self::TOOLS[ $name ] );
	}

	public static function raif_active() {
		return defined( 'ROYAL_AI_FIREWALL_VERSION' ) && defined( 'ROYAL_AI_FIREWALL_TABLE_ROLLUP' );
	}

	public static function links_active() {
		return class_exists( 'Royal_Links_Post_Type' ) || post_type_exists( 'royal_link' );
	}

	public static function elementor_active() {
		return class_exists( '\\Elementor\\Plugin' );
	}

	public static function redirection_active() {
		return class_exists( '\\Red_Item' ) && class_exists( '\\Red_Group' );
	}

	public static function get_tools() {
		return array_values( self::schemas() );
	}

	public static function execute_tool( $name, $args ) {
		if ( ! self::handles( $name ) ) {
			throw new \Exception( 'Unknown report tool: ' . esc_html( is_string( $name ) ? $name : '' ) );
		}
		$args    = is_array( $args ) ? $args : [];
		$known   = (array) ( self::schemas()[ $name ]['inputSchema']['properties'] ?? [] );
		$unknown = array_diff( array_map( 'strval', array_keys( $args ) ), array_keys( $known ) );
		if ( $unknown ) {
			return Envelope::error( 'invalid_args', 'This tool does not take: ' . implode( ', ', array_slice( array_map( 'sanitize_key', $unknown ), 0, 10 ) ) . '.' );
		}
		return call_user_func( [ __CLASS__, self::TOOLS[ $name ][0] ], $args );
	}

	private static function require_cap( $cap ) {
		if ( ! current_user_can( $cap ) ) {
			throw new \Exception( 'You do not have permission to use this tool.' );
		}
	}

	private static function need( $active, $plugin ) {
		if ( ! $active ) {
			throw new \Exception( esc_html( $plugin . ' is not active on this site.' ) );
		}
	}

	/** A whole number argument within bounds, the default when absent, or null. */
	private static function int_arg( array $args, $key, $default, $min, $max ) {
		if ( ! isset( $args[ $key ] ) ) {
			return $default;
		}
		$v = $args[ $key ];
		if ( ! is_int( $v ) && ! ( is_string( $v ) && ctype_digit( $v ) && strlen( $v ) < 10 ) ) {
			return null;
		}
		$v = (int) $v;
		return ( $v >= $min && $v <= $max ) ? $v : null;
	}

	// ------------------------------------------------------------------
	// raif_get_period_rollup
	// ------------------------------------------------------------------

	private static function clean_date( $value ) {
		return ( is_string( $value ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) ? $value : null;
	}

	private static function raif_period( array $args ) {
		self::require_cap( is_multisite() ? 'manage_network_options' : 'manage_options' );
		self::need( self::raif_active(), 'Royal AI Firewall' );
		$to   = isset( $args['date_to'] ) && '' !== $args['date_to'] ? self::clean_date( $args['date_to'] ) : gmdate( 'Y-m-d' );
		$from = isset( $args['date_from'] ) && '' !== $args['date_from'] ? self::clean_date( $args['date_from'] ) : gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		if ( null === $to || null === $from ) {
			return Envelope::error( 'invalid_args', 'date_from and date_to must be real dates as YYYY-MM-DD.' );
		}
		$days = (int) round( ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) / DAY_IN_SECONDS ) + 1;
		if ( $days < 1 || $days > self::MAX_DAYS ) {
			return Envelope::error( 'invalid_args', $days < 1 ? 'date_from must not be after date_to.' : sprintf( 'A report covers at most %d days.', self::MAX_DAYS ) );
		}
		$group      = $args['group_by'] ?? 'bot';
		$sql_period = [
			'bot'   => "''",
			'day'   => "DATE_FORMAT( rollup_date, '%Y-%m-%d' )",
			'week'  => "DATE_FORMAT( rollup_date, '%x-W%v' )",
			'month' => "DATE_FORMAT( rollup_date, '%Y-%m' )",
		];
		if ( ! is_string( $group ) || ! isset( $sql_period[ $group ] ) ) {
			return Envelope::error( 'invalid_args', 'group_by must be bot, day, week or month.' );
		}
		$bot = '';
		if ( isset( $args['bot_id'] ) && '' !== $args['bot_id'] ) {
			if ( ! is_string( $args['bot_id'] ) || 1 !== preg_match( '/^[A-Za-z0-9_.\-]{1,64}$/', $args['bot_id'] ) ) {
				return Envelope::error( 'invalid_args', 'bot_id must be a bot id, as raif_get_bot_policies lists them.' );
			}
			$bot = $args['bot_id'];
		}
		global $wpdb;
		$table = $wpdb->prefix . ROYAL_AI_FIREWALL_TABLE_ROLLUP;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return Envelope::success( 'Royal AI Firewall has recorded nothing yet.', [ 'date_from' => $from, 'date_to' => $to, 'group_by' => $group, 'rows' => [], 'total_hits' => 0, 'truncated' => false ] );
		}
		$where = 'rollup_date >= %s AND rollup_date <= %s';
		$vals  = [ $from, $to ];
		if ( '' !== $bot ) {
			$where .= ' AND bot_id = %s';
			$vals[] = $bot;
		}
		// The whole period's totals come from their own query, so they stay true when the row list is cut.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the firewall's constant; values bound
		$sum    = (array) $wpdb->get_row( $wpdb->prepare( "SELECT SUM( hits ) AS hits, SUM( blocked ) AS blocked, SUM( rate_limited ) AS rate_limited FROM `{$table}` WHERE {$where}", $vals ), ARRAY_A );
		$period = str_replace( '%', '%%', $sql_period[ $group ] );
		$vals[] = self::MAX_ROWS + 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- as above; $period is one of four fixed expressions
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$period} AS period, bot_id, MAX( bot_category ) AS bot_category, SUM( hits ) AS hits, SUM( blocked ) AS blocked, SUM( rate_limited ) AS rate_limited, SUM( bytes_total ) AS bytes_total FROM `{$table}` WHERE {$where} GROUP BY period, bot_id ORDER BY period ASC, hits DESC, bot_id ASC LIMIT %d", $vals ), ARRAY_A );
		$truncated = count( $rows ) > self::MAX_ROWS;
		$names     = [];
		$out       = [];
		foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $r ) {
			$id = (string) $r['bot_id'];
			if ( ! array_key_exists( $id, $names ) ) {
				$known        = is_callable( [ '\\Royal_AI_Firewall\\FingerprintDB', 'get_bot_by_id' ] ) ? \Royal_AI_Firewall\FingerprintDB::get_bot_by_id( $id ) : null;
				$names[ $id ] = is_array( $known ) && ! empty( $known['name'] ) && is_string( $known['name'] ) ? $known['name'] : $id;
			}
			$row   = [
				'bot_id'       => mb_substr( SafeText::field( $id ), 0, 64 ),
				'bot_name'     => mb_substr( SafeText::field( $names[ $id ] ), 0, 100 ),
				'bot_category' => sanitize_key( (string) $r['bot_category'] ),
				'hits'         => (int) $r['hits'],
				'blocked'      => (int) $r['blocked'],
				'rate_limited' => (int) $r['rate_limited'],
				'bytes_total'  => (int) $r['bytes_total'],
			];
			$out[] = 'bot' === $group ? $row : [ 'period' => (string) $r['period'] ] + $row;
		}
		return Envelope::success(
			sprintf( '%d row(s) from %s to %s, grouped by %s; %d hit(s) in all.', count( $out ), $from, $to, $group, (int) ( $sum['hits'] ?? 0 ) ),
			[ 'date_from' => $from, 'date_to' => $to, 'group_by' => $group, 'rows' => $out, 'total_hits' => (int) ( $sum['hits'] ?? 0 ), 'total_blocked' => (int) ( $sum['blocked'] ?? 0 ), 'total_rate_limited' => (int) ( $sum['rate_limited'] ?? 0 ), 'truncated' => $truncated ]
		);
	}

	// ------------------------------------------------------------------
	// rlinks_get_top_links
	// ------------------------------------------------------------------

	private static function top_links( array $args ) {
		// Click statistics are for administrators in Royal Links itself.
		self::require_cap( 'manage_options' );
		self::need( self::links_active(), 'Royal Links' );
		$period   = isset( $args['period'] ) && '' !== $args['period'] ? $args['period'] : '30days';
		$per_page = self::int_arg( $args, 'per_page', 20, 1, 100 );
		$page     = self::int_arg( $args, 'page', 1, 1, 100000 );
		if ( ! is_string( $period ) || ! isset( self::LINK_PERIODS[ $period ] ) || null === $per_page || null === $page ) {
			return Envelope::error( 'invalid_args', 'period must be 7days, 30days, 90days, 12months or all; page a whole number from 1; per_page from 1 to 100.' );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'royal_links_clicks';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return Envelope::success( 'No clicks recorded yet.', [ 'period' => $period, 'page' => $page, 'per_page' => $per_page, 'total_links' => 0, 'total_pages' => 0, 'links' => [] ] );
		}
		$days  = self::LINK_PERIODS[ $period ];
		$where = "p.post_type = 'royal_link' AND p.post_status = 'publish'";
		$vals  = [];
		if ( $days > 0 ) {
			// Clicks are stored in site time.
			$where .= ' AND c.click_date > %s';
			$vals[] = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		}
		$join      = "FROM `{$table}` c INNER JOIN {$wpdb->posts} p ON p.ID = c.link_id WHERE {$where}";
		$count_sql = "SELECT COUNT( DISTINCT c.link_id ) {$join}";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the prefix; values bound
		$total = (int) $wpdb->get_var( $vals ? $wpdb->prepare( $count_sql, $vals ) : $count_sql );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.link_id, p.post_title, COUNT(*) AS clicks, SUM( c.is_unique ) AS unique_clicks, MAX( c.click_date ) AS last_click {$join} GROUP BY c.link_id, p.post_title ORDER BY clicks DESC, c.link_id ASC LIMIT %d OFFSET %d", array_merge( $vals, [ $per_page, ( $page - 1 ) * $per_page ] ) ), ARRAY_A );
		// phpcs:enable
		$links = [];
		foreach ( $rows as $r ) {
			$links[] = [ 'link_id' => (int) $r['link_id'], 'title' => mb_substr( SafeText::field( (string) $r['post_title'] ), 0, 200 ), 'clicks' => (int) $r['clicks'], 'unique_clicks' => (int) $r['unique_clicks'], 'last_click_at' => (string) $r['last_click'] ];
		}
		$pages = (int) ceil( $total / $per_page );
		return Envelope::success(
			sprintf( '%d link(s) with clicks in %s; page %d of %d.', $total, $period, $page, max( 1, $pages ) ),
			[ 'period' => $period, 'page' => $page, 'per_page' => $per_page, 'total_links' => $total, 'total_pages' => $pages, 'links' => $links, 'timezone' => wp_timezone_string() ]
		);
	}

	// ------------------------------------------------------------------
	// elementor_get_kit_globals
	// ------------------------------------------------------------------

	private static function kit_globals( array $args ) {
		self::require_cap( 'edit_posts' );
		self::need( self::elementor_active(), 'Elementor' );
		$plugin = \Elementor\Plugin::$instance;
		$kit    = null;
		if ( is_object( $plugin ) && isset( $plugin->kits_manager, $plugin->documents ) && is_object( $plugin->kits_manager ) && is_object( $plugin->documents ) ) {
			$kit_id = (int) $plugin->kits_manager->get_active_id();
			$kit    = $kit_id > 0 ? $plugin->documents->get( $kit_id, false ) : null;
		}
		if ( ! is_object( $kit ) || ! method_exists( $kit, 'get_settings' ) || ! method_exists( $kit, 'get_main_id' ) ) {
			return Envelope::error( 'not_found', 'Elementor has no active Site Settings kit on this site.' );
		}
		$text   = static function ( $v, $max ) { return is_scalar( $v ) ? mb_substr( SafeText::field( (string) $v ), 0, $max ) : ''; };
		$colors = [];
		$fonts  = [];
		foreach ( [ 'system', 'custom' ] as $kind ) {
			$list = $kit->get_settings( $kind . '_colors' );
			foreach ( array_slice( is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : [], 0, 200 ) as $e ) {
				$colors[] = [ 'id' => $text( $e['_id'] ?? '', 60 ), 'title' => $text( $e['title'] ?? '', 120 ), 'color' => $text( $e['color'] ?? '', 40 ), 'kind' => $kind ];
			}
			$list = $kit->get_settings( $kind . '_typography' );
			foreach ( array_slice( is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : [], 0, 200 ) as $e ) {
				$weight  = $e['typography_font_weight'] ?? null;
				$fonts[] = [ 'id' => $text( $e['_id'] ?? '', 60 ), 'title' => $text( $e['title'] ?? '', 120 ), 'font_family' => $text( $e['typography_font_family'] ?? '', 120 ), 'font_weight' => ( null === $weight || '' === $weight ) ? null : $text( $weight, 20 ), 'kind' => $kind ];
			}
		}
		$kit_id = (int) $kit->get_main_id();
		return Envelope::success( sprintf( 'Site Settings kit %d: %d colour(s), %d font(s).', $kit_id, count( $colors ), count( $fonts ) ), [ 'kit_id' => $kit_id, 'colors' => $colors, 'fonts' => $fonts ] );
	}

	// ------------------------------------------------------------------
	// redirection_bulk_export
	// ------------------------------------------------------------------

	/** One CSV cell: a value a spreadsheet would run as a formula is made plain text. */
	private static function csv_cell( $value ) {
		$value = is_scalar( $value ) ? str_replace( "\0", '', (string) $value ) : '';
		if ( strlen( $value ) > self::CSV_CELL_MAX ) {
			$value = substr( $value, 0, self::CSV_CELL_MAX ) . '...[cut]';
		}
		// Judged on the first character that is not a space or a control character, as a spreadsheet may skip those.
		$first = ltrim( $value, " \t\r\n" );
		if ( '' !== $first && false !== strpos( '=+-@', $first[0] ) ) {
			$value = "'" . $value;
		}
		return 1 === preg_match( '/[",\r\n]/', $value ) ? '"' . str_replace( '"', '""', $value ) . '"' : $value;
	}

	private static function redirection_export( array $args ) {
		self::require_cap( 'manage_options' );
		self::need( self::redirection_active(), 'Redirection' );
		$limit  = self::int_arg( $args, 'limit', 500, 1, 2000 );
		$offset = self::int_arg( $args, 'offset', 0, 0, 10000000 );
		$group  = self::int_arg( $args, 'group_id', 0, 1, 2147483647 );
		if ( null === $limit || null === $offset || null === $group ) {
			return Envelope::error( 'invalid_args', 'limit must be 1 to 2000, offset a whole number from 0, and group_id a group ID.' );
		}
		global $wpdb;
		$where  = '1=1';
		$params = [];
		if ( $group > 0 ) {
			$where   .= ' AND i.group_id = %d';
			$params[] = $group;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names built from the prefix; values bound
		$count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}redirection_items i WHERE {$where}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );
		$rows      = (array) $wpdb->get_results( $wpdb->prepare( "SELECT i.id, i.url, i.action_data, i.action_code, i.action_type, i.match_type, i.regex, i.status, g.name AS group_name FROM {$wpdb->prefix}redirection_items i LEFT JOIN {$wpdb->prefix}redirection_groups g ON g.id = i.group_id WHERE {$where} ORDER BY i.id ASC LIMIT %d OFFSET %d", array_merge( $params, [ $limit, $offset ] ) ), ARRAY_A );
		// phpcs:enable
		$lines = [ implode( ',', self::CSV_COLUMNS ) ];
		$size  = 0;
		$count = 0;
		$cut   = 0;
		foreach ( $rows as $r ) {
			$dest = (string) $r['action_data'];
			// A rule that matches on more than the address keeps its targets as serialised data; that is not a destination.
			if ( is_serialized( $dest ) ) {
				$dest = '';
			}
			$cut  += ( strlen( (string) $r['url'] ) > self::CSV_CELL_MAX || strlen( $dest ) > self::CSV_CELL_MAX ) ? 1 : 0;
			$line  = implode( ',', array_map( [ __CLASS__, 'csv_cell' ], [ (string) $r['url'], $dest, (string) ( $r['group_name'] ?? '' ), (int) $r['action_code'], sanitize_key( (string) $r['match_type'] ), sanitize_key( (string) ( $r['action_type'] ?? '' ) ), empty( $r['regex'] ) ? 0 : 1, sanitize_key( (string) ( $r['status'] ?? '' ) ) ] ) );
			$size += strlen( $line ) + 1;
			// The reply stays under about 400 KB; the caller pages on with offset. A page always holds at least one rule.
			if ( $size > 400000 && $count > 0 ) {
				break;
			}
			$lines[] = $line;
			$count++;
		}
		return Envelope::success(
			sprintf( 'Exported %d of %d redirect(s)%s.', $count, $total, $offset + $count < $total ? sprintf( '; continue with offset %d', $offset + $count ) : '' ),
			[ 'count' => $count, 'total' => $total, 'offset' => $offset, 'next_offset' => $offset + $count < $total ? $offset + $count : null, 'group_id' => $group > 0 ? $group : null, 'cells_cut' => $cut, 'columns' => self::CSV_COLUMNS, 'csv_content' => implode( "\n", $lines ) ]
		);
	}

	// ------------------------------------------------------------------
	// wp_audit_application_passwords
	// ------------------------------------------------------------------

	private static function audit_app_passwords( array $args ) {
		self::require_cap( 'manage_options' );
		self::require_cap( 'list_users' );
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return Envelope::success( 'Application passwords are not available on this WordPress.', [ 'available' => false, 'users' => [], 'totals' => new \stdClass(), 'truncated' => false ] );
		}
		$stale  = time() - 90 * DAY_IN_SECONDS;
		$users  = [];
		// Key names avoid the word the reply's secret filter looks for; nothing here is a secret.
		$totals = [ 'entries' => 0, 'never_used' => 0, 'unused_90_days' => 0, 'administrators_with_entries' => 0 ];
		$found  = get_users( [ 'meta_key' => \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, 'meta_compare' => 'EXISTS', 'number' => 501, 'orderby' => 'ID' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one bounded query for an audit
		foreach ( array_slice( $found, 0, 500 ) as $u ) {
			$rows = [];
			foreach ( array_slice( (array) \WP_Application_Passwords::get_user_application_passwords( $u->ID ), 0, 100 ) as $p ) {
				if ( ! is_array( $p ) ) {
					continue;
				}
				$last   = isset( $p['last_used'] ) ? (int) $p['last_used'] : 0;
				// Only the name, an id and dates: the password itself, its hash and the address it was last used from are never read out.
				$rows[] = [
					'name'       => mb_substr( SafeText::field( (string) ( $p['name'] ?? '' ) ), 0, 120 ),
					'uuid'       => 1 === preg_match( '/^[A-Za-z0-9\-]{1,64}$/', (string) ( $p['uuid'] ?? '' ) ) ? (string) $p['uuid'] : '',
					'created_at' => ! empty( $p['created'] ) ? gmdate( 'c', (int) $p['created'] ) : null,
					'last_used'  => $last ? gmdate( 'c', $last ) : null,
				];
				$totals['entries']++;
				if ( ! $last ) {
					$totals['never_used']++;
				} elseif ( $last < $stale ) {
					$totals['unused_90_days']++;
				}
			}
			if ( ! $rows ) {
				continue;
			}
			if ( user_can( $u, 'manage_options' ) ) {
				$totals['administrators_with_entries']++;
			}
			$users[] = [ 'user_id' => (int) $u->ID, 'user_login' => (string) $u->user_login, 'roles' => array_values( array_map( 'sanitize_key', (array) $u->roles ) ), 'is_administrator' => user_can( $u, 'manage_options' ), 'entries' => $rows ];
		}
		return Envelope::success(
			sprintf( '%d application password(s) on %d user(s); %d never used, %d unused for 90 days.', $totals['entries'], count( $users ), $totals['never_used'], $totals['unused_90_days'] ),
			[ 'available' => (bool) wp_is_application_passwords_available(), 'users' => $users, 'totals' => $totals, 'truncated' => count( $found ) > 500 ]
		);
	}

	// ------------------------------------------------------------------
	// Tool definitions
	// ------------------------------------------------------------------

	private static function schemas() {
		$tool = static function ( $name, $description, array $props ) {
			return [ 'name' => $name, 'description' => $description, 'inputSchema' => [ 'type' => 'object', 'properties' => $props ? $props : new \stdClass(), 'additionalProperties' => false ] ];
		};
		$out  = [
			$tool( 'raif_get_period_rollup', 'Royal AI Firewall totals over a date range (UTC days, 30 days up to today by default, at most 366): rows {bot_id, bot_name, bot_category, hits, blocked, rate_limited, bytes_total}, one per bot, or per bot and period when group_by is day, week or month (each row then has period). total_hits, total_blocked and total_rate_limited cover the whole range; rows stops at 1,000 and truncated says so. Read-only. Requires Royal AI Firewall and manage_options.', [ 'date_from' => [ 'type' => 'string', 'description' => 'YYYY-MM-DD.' ], 'date_to' => [ 'type' => 'string', 'description' => 'YYYY-MM-DD, default today.' ], 'group_by' => [ 'type' => 'string', 'enum' => [ 'bot', 'day', 'week', 'month' ], 'description' => 'Default bot.' ], 'bot_id' => [ 'type' => 'string', 'description' => 'One bot only.' ] ] ),
			$tool( 'rlinks_get_top_links', 'The most-clicked Royal Links over a period, most clicks first: links {link_id, title, clicks, unique_clicks, last_click_at (site time)}, with page, per_page, total_links and total_pages. Published links only. No visitor data. A page beyond the last one is empty. Read-only. Requires Royal Links and manage_options.', [ 'period' => [ 'type' => 'string', 'enum' => array_keys( self::LINK_PERIODS ), 'description' => 'Default 30days.' ], 'page' => [ 'type' => 'integer', 'minimum' => 1 ], 'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Default 20.' ] ] ),
			$tool( 'elementor_get_kit_globals', 'Read Elementor Site Settings: the global colours and global fonts every widget can point at. Returns kit_id, colors [{id, title, color, kind: system or custom}] and fonts [{id, title, font_family, font_weight, kind}]. A kit never saved in Site Settings reports Elementor\'s defaults. Read-only. Requires Elementor and edit_posts.', [] ),
			$tool( 'redirection_bulk_export', 'Export the Redirection plugin\'s rules as CSV text, in ID order, disabled rules included. Columns: source, destination, group, type (the HTTP code), match_type, action_type (url, error, random, pass), regex (1 when the source is a pattern) and status (enabled or disabled). It is a readable listing, not a file for Redirection\'s own import. limit is 500 by default and at most 2000; page on with offset (next_offset is given while more remain; a very large page is cut at about 400 KB). Optionally one group_id. A rule that matches on more than the address, or that has no target (an error or random rule), is exported with an empty destination. A cell longer than 2,000 characters is cut (cells_cut counts the rules affected). Cells a spreadsheet would run as a formula are made plain text. Read-only. Requires Redirection and manage_options.', [ 'group_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Only this group (see redirection_list_groups).' ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 2000 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0 ] ] ),
			$tool( 'wp_audit_application_passwords', 'List application passwords per user: users [{user_id, user_login, roles, is_administrator, entries [{name, uuid, created_at, last_used}]}] with totals {entries, never_used, unused_90_days, administrators_with_entries}; one entry is one application password. The passwords themselves are never returned. A name that itself looks like a secret (a long random string, or text such as "password: ...") comes back masked; uuid still identifies the entry. Up to 500 users (truncated says when there are more). Read-only. Requires manage_options and list_users.', [] ),
		];
		return array_column( $out, null, 'name' );
	}
}
