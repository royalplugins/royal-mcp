<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Solid Security MCP Integration
 *
 * Class detection uses ITSEC_Core because Solid Security / iThemes Security
 * / Kadence Security Basic all ship the same internal class lineage. A
 * single availability probe covers every rebrand.
 */
class SolidSecurity {

	public static function is_available() {
		return class_exists( 'ITSEC_Core' );
	}

	public static function get_tools() {
		return [
			[
				'name'        => 'solid_get_security_status',
				'description' => 'Get a summary of Solid Security state — plugin version, count of active protection modules, current lockout count, total permanent bans, and event log volume over the last 24 hours.',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
			[
				'name'        => 'solid_list_blocked_ips',
				'description' => 'List currently locked-out IP addresses tracked by Solid Security. Returns lockout host, start/expire timestamps (legacy + ISO 8601), triggering module, and any associated user/username. Supports pagination via limit + offset.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'        => [ 'type' => 'integer', 'description' => 'Max entries to return (default 50, max 200).' ],
						'offset'       => [ 'type' => 'integer', 'description' => 'Row offset for pagination (default 0).' ],
						'current_only' => [ 'type' => 'boolean', 'description' => 'When true (default), only return unexpired lockouts. Set false to include historical lockouts.' ],
					],
				],
			],
			[
				'name'        => 'solid_list_events',
				'description' => 'Read the Solid Security event log. Returns event id, module, code, type, severity, IP address, timestamp (legacy + ISO 8601), and stored event data. Supports pagination and filtering by event type or severity.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'    => [ 'type' => 'integer', 'description' => 'Max entries to return (default 50, max 200).' ],
						'offset'   => [ 'type' => 'integer', 'description' => 'Row offset for pagination (default 0).' ],
						'type'    => [ 'type' => 'string', 'description' => 'Optional event type filter (e.g. "notice", "warning", "error", "critical-issue", "action", "fatal-error").' ],
					],
				],
			],
			[
				'name'        => 'solid_block_ip',
				'description' => 'Permanently ban an IP address in Solid Security. Additive — the IP is added to the ban list. Whitelisted IPs are refused. Callers should provide an optional reason comment. Requires manage_options.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'ip'      => [ 'type' => 'string', 'description' => 'IPv4 or IPv6 address to ban.' ],
						'comment' => [ 'type' => 'string', 'description' => 'Optional note explaining why the ban was applied.' ],
					],
					'required'   => [ 'ip' ],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		// cap check before availability check so a Subscriber Bearer token
		// cannot use "Solid Security not active" to probe plugin presence.
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use Solid Security tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Solid Security is not active' );
		}

		switch ( $name ) {
			case 'solid_get_security_status':
				return self::handle_get_security_status();
			case 'solid_list_blocked_ips':
				return self::handle_list_blocked_ips( $args );
			case 'solid_list_events':
				return self::handle_list_events( $args );
			case 'solid_block_ip':
				return self::handle_block_ip( $args );
			default:
				throw new \Exception( 'Unknown Solid Security tool: ' . esc_html( $name ) );
		}
	}

	private static function handle_get_security_status() {
		global $itsec_lockout;

		$plugin_version = method_exists( '\\ITSEC_Core', 'get_plugin_version' )
			? \ITSEC_Core::get_plugin_version()
			: 'unknown';

		$active_modules = method_exists( '\\ITSEC_Modules', 'get_active_modules' )
			? \ITSEC_Modules::get_active_modules()
			: [];

		$current_lockouts = 0;
		if ( $itsec_lockout && method_exists( $itsec_lockout, 'get_lockouts' ) ) {
			$current_lockouts = (int) $itsec_lockout->get_lockouts( 'all', [ 'return' => 'count', 'current' => true ] );
		}

		$total_bans = self::count_bans();

		$events_24h = 0;
		if ( method_exists( '\\ITSEC_Log', 'get_number_of_entries' ) ) {
			$events_24h = (int) \ITSEC_Log::get_number_of_entries( [
				'__min_timestamp' => time() - DAY_IN_SECONDS,
			] );
		}

		return [
			'plugin_version'         => $plugin_version,
			'active_modules_count'   => count( $active_modules ),
			'active_modules'         => array_values( $active_modules ),
			'current_lockouts_count' => $current_lockouts,
			'total_bans_count'       => $total_bans,
			'events_last_24h_count'  => $events_24h,
		];
	}

	private static function handle_list_blocked_ips( $args ) {
		global $itsec_lockout;
		if ( ! $itsec_lockout || ! method_exists( $itsec_lockout, 'get_lockouts' ) ) {
			throw new \Exception( 'Lockout handler not available' );
		}

		$limit        = min( max( intval( $args['limit'] ?? 50 ), 1 ), 200 );
		$offset       = max( intval( $args['offset'] ?? 0 ), 0 );
		$current_only = array_key_exists( 'current_only', $args ) ? (bool) $args['current_only'] : true;

		$query_args = [
			'current' => $current_only,
			'limit'   => $limit,
		];
		if ( $offset > 0 ) {
			$query_args['offset'] = $offset;
		}

		$rows = $itsec_lockout->get_lockouts( 'all', $query_args );

		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[] = self::format_lockout_row( $row );
		}

		return [
			'limit'   => $limit,
			'offset'  => $offset,
			'count'   => count( $out ),
			'entries' => $out,
		];
	}

	private static function handle_list_events( $args ) {
		if ( ! method_exists( '\\ITSEC_Log', 'get_entries' ) ) {
			throw new \Exception( 'Log query API not available' );
		}

		$limit  = min( max( intval( $args['limit'] ?? 50 ), 1 ), 200 );
		$offset = max( intval( $args['offset'] ?? 0 ), 0 );
		$page   = (int) floor( $offset / $limit ) + 1;

		$filters = [];
		if ( ! empty( $args['type'] ) ) {
			$filters['type'] = sanitize_text_field( $args['type'] );
		}

		$rows = \ITSEC_Log::get_entries( $filters, $limit, $page );

		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[] = self::format_log_row( $row );
		}

		return [
			'limit'   => $limit,
			'offset'  => $offset,
			'count'   => count( $out ),
			'entries' => $out,
		];
	}

	private static function handle_block_ip( $args ) {
		global $itsec_lockout;

		$ip = trim( sanitize_text_field( $args['ip'] ?? '' ) );
		if ( '' === $ip ) {
			throw new \Exception( 'ip is required' );
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			throw new \Exception( 'Invalid IP address: ' . esc_html( $ip ) );
		}

		if ( class_exists( '\\ITSEC_Lib' ) && method_exists( '\\ITSEC_Lib', 'is_ip_whitelisted' ) && \ITSEC_Lib::is_ip_whitelisted( $ip ) ) {
			throw new \Exception( 'Cannot ban a whitelisted IP: ' . esc_html( $ip ) );
		}

		$was_already_banned = class_exists( '\\ITSEC_Lib' ) && method_exists( '\\ITSEC_Lib', 'is_ip_banned' )
			? \ITSEC_Lib::is_ip_banned( $ip )
			: false;

		$comment = isset( $args['comment'] ) ? sanitize_text_field( $args['comment'] ) : '';

		// Preferred path: repository via ITSEC container. Falls back to
		// legacy blacklist_ip() when the container / Ban_Users module isn't
		// wired (older builds or when the module is disabled).
		$persisted = false;
		if ( class_exists( '\\ITSEC_Modules' ) && method_exists( '\\ITSEC_Modules', 'get_container' ) ) {
			try {
				$c    = \ITSEC_Modules::get_container();
				$repo = isset( $c[ \iThemesSecurity\Ban_Users\Database_Repository::class ] )
					? $c[ \iThemesSecurity\Ban_Users\Database_Repository::class ]
					: null;
				if ( $repo && class_exists( '\\iThemesSecurity\\Ban_Users\\Ban' ) ) {
					$ban = new \iThemesSecurity\Ban_Users\Ban( $ip, null, $comment, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
					$repo->persist( $ban );
					$persisted = true;
				}
			} catch ( \Throwable $e ) {
				// Fall through to legacy path.
			}
		}
		if ( ! $persisted && $itsec_lockout && method_exists( $itsec_lockout, 'blacklist_ip' ) ) {
			$persisted = (bool) $itsec_lockout->blacklist_ip( $ip );
		}

		if ( ! $persisted ) {
			throw new \Exception( 'Failed to persist ban (no supported ban repository available)' );
		}

		return [
			'blocked'            => true,
			'ip'                 => $ip,
			'was_already_banned' => (bool) $was_already_banned,
			'comment'            => $comment,
		];
	}

	/**
	 * Normalize a lockout DB row (stdClass or array) into the response shape.
	 * Includes both legacy 'Y-m-d H:i:s' and ISO 8601 timestamps so callers
	 * that already parse the legacy shape keep working (same back-compat
	 * pattern as WooCommerce read tools in 1.4.45 C2).
	 */
	private static function format_lockout_row( $row ) {
		$r          = is_array( $row ) ? (object) $row : $row;
		$start_gmt  = $r->lockout_start_gmt  ?? null;
		$expire_gmt = $r->lockout_expire_gmt ?? null;

		// Module identity lives inside the serialized Lockout\Context.
		// Rows created directly (test seeds, migrations) may have no context.
		$module      = null;
		$raw_context = $r->lockout_context ?? '';
		if ( is_string( $raw_context ) && '' !== $raw_context ) {
			$decoded = @unserialize( $raw_context );
			if ( $decoded instanceof \iThemesSecurity\Lib\Lockout\Context && method_exists( $decoded, 'get_lockout_module' ) ) {
				$module = $decoded->get_lockout_module();
			}
		}

		return [
			'id'              => isset( $r->lockout_id ) ? (int) $r->lockout_id : null,
			'type'            => $r->lockout_type ?? null,
			'module'          => $module,
			'ip'              => $r->lockout_host ?? '',
			'user_id'         => isset( $r->lockout_user ) ? (int) $r->lockout_user : 0,
			'username'        => $r->lockout_username ?? '',
			'active'          => ! empty( $r->lockout_active ),
			'blocked_at'      => $start_gmt,
			'blocked_at_iso'  => self::to_iso( $start_gmt ),
			'expires_at'      => $expire_gmt,
			'expires_at_iso'  => self::to_iso( $expire_gmt ),
		];
	}

	private static function format_log_row( $row ) {
		$r         = is_array( $row ) ? (object) $row : $row;
		$timestamp = $r->timestamp ?? null;

		$data = $r->data ?? null;
		if ( is_string( $data ) ) {
			$decoded = @unserialize( $data );
			if ( false !== $decoded || 'b:0;' === $data ) {
				$data = $decoded;
			}
		}

		return [
			'id'            => isset( $r->id ) ? (int) $r->id : null,
			'module'        => $r->module ?? null,
			'code'          => $r->code ?? null,
			'type'          => $r->type ?? null,
			'severity'      => isset( $r->severity ) ? (int) $r->severity : null,
			'ip'            => $r->remote_ip ?? '',
			'user_id'       => isset( $r->user_id ) ? (int) $r->user_id : 0,
			'url'           => $r->url ?? '',
			'timestamp'     => $timestamp,
			'timestamp_iso' => self::to_iso( $timestamp ),
			'data'          => $data,
		];
	}

	private static function count_bans() {
		if ( ! class_exists( '\\ITSEC_Modules' ) || ! method_exists( '\\ITSEC_Modules', 'get_container' ) ) {
			return 0;
		}
		try {
			$c = \ITSEC_Modules::get_container();
			if ( ! isset( $c[ \iThemesSecurity\Ban_Users\Database_Repository::class ] ) ) {
				return 0;
			}
			$repo = $c[ \iThemesSecurity\Ban_Users\Database_Repository::class ];
			if ( ! class_exists( '\\iThemesSecurity\\Ban_Hosts\\Filters' ) ) {
				return 0;
			}
			$filters = new \iThemesSecurity\Ban_Hosts\Filters();
			return (int) $repo->count_bans( $filters );
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	private static function to_iso( $mysql_datetime ) {
		if ( empty( $mysql_datetime ) ) {
			return null;
		}
		$ts = strtotime( $mysql_datetime . ' UTC' );
		return $ts ? gmdate( 'c', $ts ) : null;
	}
}

/**
 * Manifest declaration for host discovery (any consumer of the
 * royal_mcp_manifests filter).
 *
 * supports_undo is false because the single write tool (solid_block_ip) is
 * purely additive — the IP is added to the ban list and prior state is not
 * mutated. Reversal (unbanning) is out of scope for the Free tier.
 */
add_filter( 'royal_mcp_manifests', function ( $manifests ) {
	if ( ! class_exists( '\\ITSEC_Core' ) ) {
		return $manifests;
	}
	$manifests[] = [
		'royal_mcp_manifest_version' => '1.0',
		'plugin_slug'                => 'solid-security',
		'plugin_display_name'        => 'Solid Security',
		'plugin_version'             => method_exists( '\\ITSEC_Core', 'get_plugin_version' ) ? \ITSEC_Core::get_plugin_version() : 'unknown',
		'vendor_name'                => 'SolidWP',
		'mcp_endpoint'               => rest_url( 'royal-mcp/v1/mcp' ),
		'auth_methods'               => [ 'oauth2.1' ],
		'capabilities'               => [ 'read', 'additive-write' ],
		'manifest_updated_at'        => gmdate( 'c' ),
		'trust_signals'              => [
			'supports_dry_run'                => false,
			'supports_undo'                   => false,
			'supports_snapshots'              => false,
			'requires_review_for_destructive' => true,
		],
	];
	return $manifests;
} );
