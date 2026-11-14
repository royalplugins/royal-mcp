<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GuardPress: a one-call security summary, hardening and two-factor status,
 * malware and file-change findings, and blocking one address.
 *
 * The block goes through GuardPress's own public functions and is recorded in
 * GuardPress's audit log. The summary answers { active: false } when
 * GuardPress is not running; the other tools refuse with guardpress_inactive.
 */
class GuardPressControls {

	const DEFAULT_REASON = 'Blocked through Royal MCP';

	/** Hardening settings this class reads: key => [ label, option ]. */
	const HARDENING = [
		'hardening'                  => [ 'Security hardening (file editors blocked, WordPress version hidden, fingerprinting links removed)', 'guardpress_hardening_enabled' ],
		'xml_rpc_disabled'           => [ 'Disable XML-RPC', 'guardpress_xml_rpc_disabled' ],
		'force_strong_passwords'     => [ 'Enforce strong passwords', 'guardpress_force_strong_passwords' ],
		'disable_directory_browsing' => [ 'Disable directory listing (Apache)', 'guardpress_disable_directory_browsing' ],
		'uploads_php_block'          => [ 'Block PHP execution in uploads', 'guardpress_hardening_uploads_php_block_enabled' ],
	];

	/** The tools this class answers, by name. */
	const TOOLS = [
		'gp_get_dashboard_summary' => 'handle_get_dashboard_summary',
		'gp_get_hardening'         => 'handle_get_hardening',
		'gp_get_malware_results'   => 'handle_get_malware_results',
		'gp_get_file_integrity'    => 'handle_get_file_integrity',
		'gp_block_ip'              => 'handle_block_ip',
	];

	/** Whether a tool name belongs to this class. */
	public static function handles( $name ) {
		return is_string( $name ) && isset( self::TOOLS[ $name ] );
	}

	public static function get_tools() {
		return array_values( self::schemas() );
	}

	public static function execute_tool( $name, $args ) {
		self::require_cap();
		if ( ! self::handles( $name ) ) {
			throw new \Exception( 'Unknown GuardPress tool: ' . esc_html( is_string( $name ) ? $name : '' ) );
		}
		if ( 'gp_get_hardening' === $name && ! has_filter( 'royal_mcp_scrub_tool_opts', [ __CLASS__, 'keep_setting_keys' ] ) ) {
			add_filter( 'royal_mcp_scrub_tool_opts', [ __CLASS__, 'keep_setting_keys' ], 10, 2 );
		}
		return call_user_func( [ __CLASS__, self::TOOLS[ $name ] ], is_array( $args ) ? $args : [] );
	}

	/** Hardening rows are keyed by setting names from a fixed list, not credentials. */
	public static function keep_setting_keys( $opts, $name ) {
		if ( 'gp_get_hardening' === $name && is_array( $opts ) ) {
			$opts['exempt_keys'][] = 'key';
		}
		return $opts;
	}

	/** Security state is for administrators only. */
	private static function require_cap() {
		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use GuardPress security tools.' );
		}
	}

	private static function active() {
		return class_exists( 'GuardPress_Settings' ) && class_exists( 'GuardPress_Utilities' );
	}

	/** Refusal for a site without GuardPress, or null. The caller's permission is checked first. */
	private static function gate( $tool ) {
		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use GuardPress security tools.' );
		}
		if ( ! self::active() ) {
			return Envelope::error( 'guardpress_inactive', 'GuardPress is not active on this site.' );
		}
		return null;
	}

	/** The block tools apply unless dry_run is given with anything but false (or "false" / 0 / empty). */
	private static function block_preview( array $args ) {
		return array_key_exists( 'dry_run', $args ) && null !== $args['dry_run'] && '' !== $args['dry_run'] && ! self::is_false( $args['dry_run'] );
	}

	/** An explicit false (or "false" / 0). */
	private static function is_false( $value ) {
		return in_array( $value, [ false, 0, '0', 'false' ], true );
	}

	/** A single IPv4 or IPv6 address from the arguments, or ''. */
	private static function ip_arg( array $args ) {
		$ip = isset( $args['ip'] ) && is_string( $args['ip'] ) ? trim( $args['ip'] ) : '';
		return ( strlen( $ip ) <= 45 && filter_var( $ip, FILTER_VALIDATE_IP ) ) ? $ip : '';
	}

	/** An address in binary form, an IPv4-mapped IPv6 address as its IPv4 address; false when invalid. */
	private static function packed_ip( $ip ) {
		$packed = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false
		if ( false !== $packed && 16 === strlen( $packed ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
			return substr( $packed, 12 );
		}
		return $packed;
	}

	/** An address in its shortest standard spelling, an IPv4-mapped form as plain IPv4. */
	private static function plain_ip( $ip ) {
		$packed = self::packed_ip( $ip );
		$text   = false !== $packed ? inet_ntop( $packed ) : false;
		return false !== $text ? (string) $text : (string) $ip;
	}

	/**
	 * Whether $ip is in a private, shared or reserved range: a proxy, a load balancer or this
	 * server's own network, never a visitor. Covers carrier-grade NAT space and IPv6 forms that
	 * wrap an IPv4 address.
	 */
	private static function is_internal_ip( $ip ) {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}
		$packed = self::packed_ip( $ip );
		if ( false === $packed ) {
			return true;
		}
		if ( 4 === strlen( $packed ) ) {
			// 100.64.0.0/10, shared address space.
			return 100 === ord( $packed[0] ) && 64 === ( ord( $packed[1] ) & 0xC0 );
		}
		// fec0::/10, the old site-local range.
		if ( 0xFE === ord( $packed[0] ) && 0xC0 === ( ord( $packed[1] ) & 0xC0 ) ) {
			return true;
		}
		// 64:ff9b::/96 (NAT64) and 2002::/16 (6to4) carry an IPv4 address inside.
		$inner = null;
		if ( "\x00\x64\xff\x9b" . str_repeat( "\0", 8 ) === substr( $packed, 0, 12 ) ) {
			$inner = substr( $packed, 12, 4 );
		} elseif ( "\x20\x02" === substr( $packed, 0, 2 ) ) {
			$inner = substr( $packed, 2, 4 );
		}
		return null !== $inner && self::is_internal_ip( (string) inet_ntop( $inner ) );
	}

	/**
	 * Addresses the site's own host name resolves to (this server, or the service in front of it).
	 * Looked up at most once an hour: a name that does not resolve can take seconds to say so.
	 */
	private static function site_addresses() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? trim( $host, '[]' ) : '';
		if ( '' === $host ) {
			return [];
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return [ $host ];
		}
		// Names that only ever resolve on the machine itself are not looked up.
		if ( false === strpos( $host, '.' ) || 1 === preg_match( '/\.(?:local|localhost|test|invalid|example|internal|lan)$/i', $host ) ) {
			return [];
		}
		$key    = 'royal_mcp_site_ips_' . md5( $host );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$found = function_exists( 'gethostbynamel' ) ? gethostbynamel( $host ) : false;
		$found = is_array( $found ) ? array_values( array_filter( $found, 'is_string' ) ) : [];
		set_transient( $key, $found, HOUR_IN_SECONDS ); // audit:transient-ttl-only -- the site's own addresses, looked up again after an hour
		return $found;
	}

	/**
	 * An address in the form GuardPress will see it: on a host that reports clients as
	 * ::ffff:a.b.c.d a block has to be stored that way to match.
	 */
	private static function host_form( $ip ) {
		$caller = class_exists( 'GuardPress_Logger' ) ? (string) \GuardPress_Logger::get_client_ip() : '';
		return 0 === stripos( $caller, '::ffff:' ) && filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? '::ffff:' . $ip : (string) $ip;
	}

	private static function same_ip( $a, $b ) {
		$pa = self::packed_ip( $a );
		return false !== $pa && $pa === self::packed_ip( $b );
	}

	/** Whether $ip can only mean this machine: IPv4 loopback (127.0.0.0/8), ::1, or the unspecified 0.0.0.0 and ::. */
	private static function is_local_ip( $ip ) {
		$packed = self::packed_ip( $ip );
		if ( false === $packed ) {
			return false;
		}
		if ( 4 === strlen( $packed ) ) {
			return "\x7f" === $packed[0] || "\0\0\0\0" === $packed;
		}
		return str_repeat( "\0", 16 ) === $packed || str_repeat( "\0", 15 ) . "\x01" === $packed;
	}

	/** The block-list entry (an address or a range) that covers $ip, or null. */
	private static function list_entry_for( $ip ) {
		foreach ( \GuardPress_Settings::get_ip_blacklist() as $entry ) {
			if ( \GuardPress_Settings::ip_matches( $ip, (string) $entry ) ) {
				return (string) $entry;
			}
		}
		return null;
	}

	/** The active lockout row for $ip (brute-force and alert-email blocks), or null. */
	private static function lockout_for( $ip ) {
		if ( is_callable( [ 'GuardPress_Utilities', 'get_ip_block' ] ) ) {
			$row = \GuardPress_Utilities::get_ip_block( $ip );
			return is_array( $row ) ? $row : null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read of GuardPress's own block table
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT ip_address AS ip, reason, blocked_at, unblock_at, is_permanent FROM {$wpdb->prefix}gp_ip_blocklist WHERE ip_address = %s AND ( is_permanent = 1 OR unblock_at > %s )",
			$ip,
			gmdate( 'Y-m-d H:i:s' )
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private static function utc_iso( $mysql ) {
		return ( is_string( $mysql ) && '' !== $mysql ) ? gmdate( 'c', (int) strtotime( $mysql . ' UTC' ) ) : null;
	}

	private static function gp_audit( $action, $description ) {
		if ( class_exists( 'GuardPress_Logger' ) && is_callable( [ 'GuardPress_Logger', 'log_audit' ] ) ) {
			\GuardPress_Logger::log_audit( [
				'action'      => $action,
				'description' => $description,
				'severity'    => 'info',
				'user_id'     => get_current_user_id(),
			] );
		}
	}

	// ==================== gp_block_ip ====================

	public static function handle_block_ip( array $args ) {
		$refused = self::gate( 'gp_block_ip' );
		if ( $refused ) {
			return $refused;
		}
		$ip = self::ip_arg( $args );
		if ( '' === $ip ) {
			return Envelope::error( 'invalid_args', 'ip must be one IPv4 or IPv6 address (ranges are not blocked through this tool).' );
		}
		$ip = self::plain_ip( $ip );
		if ( \GuardPress_Settings::is_ip_whitelisted( $ip ) || \GuardPress_Settings::is_ip_whitelisted( self::host_form( $ip ) ) ) {
			return Envelope::error( 'ip_allowlisted', 'This address is on GuardPress\'s allowlist; remove it from the allowlist first.', [ 'ip' => $ip ] );
		}
		$caller = class_exists( 'GuardPress_Logger' ) ? (string) \GuardPress_Logger::get_client_ip() : '';
		if ( '' !== $caller && self::same_ip( $ip, $caller ) ) {
			return Envelope::error( 'own_address', 'This is the address this request came from; blocking it would lock the caller out.', [ 'ip' => $ip ] );
		}
		$server = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';
		$own    = self::is_local_ip( $ip ) || ( '' !== $server && self::same_ip( $ip, $server ) );
		foreach ( $own ? [] : self::site_addresses() as $site_ip ) {
			$own = $own || self::same_ip( $ip, $site_ip );
		}
		if ( $own ) {
			return Envelope::error( 'own_address', 'This is the server\'s own address; blocking it would stop the site calling itself (scheduled tasks, health checks).', [ 'ip' => $ip ] );
		}
		if ( self::is_internal_ip( $ip ) ) {
			return Envelope::error( 'internal_address', 'This is a private or reserved address: a proxy, a load balancer or the server\'s own network, not a visitor. Blocking it could cut off every visitor behind it. If the site owner wants it blocked, they can do that in GuardPress.', [ 'ip' => $ip ] );
		}
		$ip = self::host_form( $ip );
		$reason = isset( $args['reason'] ) && is_string( $args['reason'] ) ? sanitize_text_field( $args['reason'] ) : '';
		$reason = '' !== $reason ? ( function_exists( 'mb_strcut' ) ? mb_strcut( $reason, 0, 255 ) : (string) wp_check_invalid_utf8( substr( $reason, 0, 255 ), true ) ) : self::DEFAULT_REASON;
		$hours  = null;
		if ( isset( $args['duration_hours'] ) && '' !== $args['duration_hours'] && null !== $args['duration_hours'] ) {
			$raw = $args['duration_hours'];
			if ( ! ( is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ) || (int) $raw < 1 || (int) $raw > 8760 ) {
				return Envelope::error( 'invalid_args', 'duration_hours must be a whole number from 1 to 8760; leave it out for a permanent block.' );
			}
			$hours = (int) $raw;
			if ( ! is_callable( [ 'GuardPress_Utilities', 'block_ip_until' ] ) ) {
				return Envelope::error( 'guardpress_update_required', 'Blocks for a set time need a newer GuardPress; update GuardPress, or leave out duration_hours for a permanent block.', [ 'ip' => $ip ] );
			}
		}

		$entry   = self::list_entry_for( $ip );
		$lockout = self::lockout_for( $ip );
		if ( null !== $entry || ( $lockout && ! empty( $lockout['is_permanent'] ) ) ) {
			return Envelope::success( sprintf( '%s is already blocked permanently; nothing changed.', $ip ), [
				'blocked'             => true,
				'was_already_blocked' => true,
				'block'               => [
					'ip'         => $ip,
					'reason'     => null !== $entry ? null : ( $lockout['reason'] ?? null ),
					'blocked_at' => null !== $entry ? null : self::utc_iso( $lockout['blocked_at'] ?? '' ),
					'expires_at' => null,
					'source'     => null !== $entry ? ( $entry === $ip ? 'block_list' : 'block_list_range' ) : 'lockout',
					'range'      => ( null !== $entry && $entry !== $ip ) ? $entry : null,
				],
				'undo'                => null,
				'undo_reason'         => 'nothing_changed',
			] );
		}

		$protected = self::admin_refusal( $ip );
		if ( $protected ) {
			return $protected;
		}

		$now     = time();
		$expires = null === $hours ? null : $now + $hours * HOUR_IN_SECONDS;
		// A lockout already running at least as long covers this one: nothing to add, nothing to undo.
		$held_until = ( $lockout && ! empty( $lockout['unblock_at'] ) ) ? (int) strtotime( $lockout['unblock_at'] . ' UTC' ) : 0;
		if ( null !== $hours && $held_until >= $expires ) {
			return Envelope::success( sprintf( '%s is already blocked until %s; nothing changed.', $ip, gmdate( 'c', $held_until ) ), [
				'blocked'             => true,
				'was_already_blocked' => true,
				'block'               => [
					'ip'         => $ip,
					'reason'     => $lockout['reason'] ?? null,
					'blocked_at' => self::utc_iso( $lockout['blocked_at'] ?? '' ),
					'expires_at' => gmdate( 'c', $held_until ),
					'source'     => 'lockout',
					'range'      => null,
				],
				'undo'                => null,
				'undo_reason'         => 'nothing_changed',
			] );
		}
		$block   = [
			'ip'         => $ip,
			'reason'     => $reason,
			'blocked_at' => gmdate( 'c', $now ),
			'expires_at' => null === $expires ? null : gmdate( 'c', $expires ),
			'source'     => null === $hours ? 'block_list' : 'lockout',
		];
		if ( self::block_preview( $args ) ) {
			return Envelope::success( sprintf( '[dry-run] %s would be blocked %s.', $ip, null === $hours ? 'permanently' : 'for ' . $hours . ' hours' ), [
				'dry_run'             => true,
				'blocked'             => false,
				'was_already_blocked' => (bool) $lockout,
				'block'               => $block,
			] );
		}

		if ( null === $hours ) {
			\GuardPress_Settings::add_to_blacklist( $ip );
			\GuardPress_Utilities::flush_ip_block_cache( $ip );
			$stored = self::list_entry_for( $ip ) === $ip;
		} else {
			$stored = (bool) \GuardPress_Utilities::block_ip_until( $ip, $expires, $reason );
		}
		if ( ! $stored ) {
			return Envelope::error( 'block_not_stored', 'GuardPress did not store the block.', [ 'ip' => $ip ] );
		}
		self::gp_audit( 'ip_blocked_via_royal_mcp', sprintf( 'Blocked IP %s through Royal MCP (%s): %s', $ip, null === $hours ? 'permanent' : $hours . ' hours', $reason ) );
		$written = null === $hours ? null : self::lockout_for( $ip );
		$undo    = \Royal_MCP\MCP\Undo_Store::store( [
			'op'           => 'gp_block_ip',
			'summary'      => sprintf( 'Unblock %s in GuardPress', $ip ),
			'target'       => [ 'ip' => $ip ],
			'pre_op_state' => [
				'source'   => $block['source'],
				// What the lockout looked like right after this write, to tell a later change apart.
				'written'  => is_array( $written ) ? [ 'unblock_at' => (string) ( $written['unblock_at'] ?? '' ), 'is_permanent' => ! empty( $written['is_permanent'] ) ] : null,
				// A shorter lockout this block replaced, put back by the undo while it still has time to run.
				'previous' => ( null !== $hours && $lockout && $held_until > 0 ) ? [ 'unblock_at' => $held_until, 'reason' => (string) ( $lockout['reason'] ?? '' ) ] : null,
			],
		] );
		return Envelope::success( sprintf( 'Blocked %s %s.', $ip, null === $hours ? 'permanently' : 'for ' . $hours . ' hours' ), [
			'dry_run'             => false,
			'blocked'             => true,
			'was_already_blocked' => (bool) $lockout,
			'block'               => $block,
		], $undo ?: null );
	}

	/** Why GuardPress would refuse $ip as an administrator's address ('' when it would not, or cannot say). */
	private static function admin_address_reason( $ip ) {
		return is_callable( [ 'GuardPress_Settings', 'block_refusal_reason' ] ) ? (string) \GuardPress_Settings::block_refusal_reason( $ip ) : '';
	}

	/**
	 * The refusal for an administrator's address, or null when the block may go ahead.
	 * No argument passes it: blocking such an address is the site owner's call, in GuardPress.
	 *
	 * @param string $ip Address.
	 * @return array|null
	 */
	private static function admin_refusal( $ip ) {
		$reason = self::admin_address_reason( $ip );
		if ( '' === $reason ) {
			return null;
		}
		$texts = [
			'current_ip'    => 'GuardPress will not block this address: you are using it right now.',
			'session_ip'    => 'GuardPress will not block this address: one of your other signed-in sessions uses it.',
			'recent_admin'  => 'GuardPress will not block this address: an administrator used it in the last 24 hours.',
			'admin_session' => 'GuardPress will not block this address: another administrator is signed in from it.',
		];
		$text = $texts[ $reason ] ?? 'GuardPress will not block this address: it belongs to an administrator.';
		return Envelope::error( 'admin_address_protected', $text . ' If the site owner wants it blocked, they can do that in GuardPress.', [ 'ip' => $ip, 'reason' => $reason ] );
	}

	/**
	 * Undo of gp_block_ip: take the block off again. A timed block that was
	 * changed after the write (made permanent, or given another end time) is
	 * left alone unless force is passed.
	 *
	 * @param array $snapshot Stored undo snapshot.
	 * @param bool  $force    Undo even when the block changed after the write.
	 * @return array
	 */
	public static function undo( array $snapshot, $force = false ) {
		self::require_cap();
		$ip     = (string) ( $snapshot['target']['ip'] ?? '' );
		$pre    = (array) ( $snapshot['pre_op_state'] ?? [] );
		$source = (string) ( $pre['source'] ?? '' );
		$target = [ 'ip' => $ip ];
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) || ! in_array( $source, [ 'block_list', 'lockout' ], true ) ) {
			throw new \Exception( 'Undo snapshot missing the address.' );
		}
		if ( ! self::active() ) {
			throw new \Exception( 'GuardPress is no longer active, so this block cannot be undone from here.' );
		}
		$gone = Envelope::success( sprintf( '%s was already unblocked; nothing to undo.', $ip ), [ 'undone' => false, 'op' => 'gp_block_ip', 'target' => $target, 'reason' => 'unblocked_since_write' ] );
		if ( 'block_list' === $source ) {
			if ( ! \GuardPress_Settings::remove_from_blacklist( $ip ) ) {
				return $gone;
			}
		} else {
			$now = self::lockout_for( $ip );
			if ( ! $now ) {
				return $gone;
			}
			$written = is_array( $pre['written'] ?? null ) ? $pre['written'] : null;
			$same    = $written && (string) ( $now['unblock_at'] ?? '' ) === (string) $written['unblock_at'] && ! empty( $now['is_permanent'] ) === ! empty( $written['is_permanent'] );
			if ( ! $same && ! $force ) {
				throw new \Royal_MCP\MCP\Support\Tool_Refusal( 'edited_since_write', esc_html( sprintf( 'The block on %s was changed after this write (it now ends at a different time or is permanent), so the undo left it alone. Pass force: true with the same token to remove it anyway.', $ip ) ) );
			}
			if ( ! self::lift_lockout( $ip ) ) {
				return $gone;
			}
			$previous = is_array( $pre['previous'] ?? null ) ? $pre['previous'] : null;
			if ( $previous && (int) $previous['unblock_at'] > time() && is_callable( [ 'GuardPress_Utilities', 'block_ip_until' ] ) ) {
				// GuardPress may refuse to block the address again (it was allowlisted, or an administrator used it since).
				if ( \GuardPress_Utilities::block_ip_until( $ip, (int) $previous['unblock_at'], (string) $previous['reason'] ) ) {
					$target['restored_earlier_block_until'] = gmdate( 'c', (int) $previous['unblock_at'] );
				} else {
					$target['earlier_block_not_restored'] = true;
				}
			}
		}
		\GuardPress_Utilities::flush_ip_block_cache( $ip );
		self::gp_audit( 'ip_unblocked_via_royal_mcp', sprintf( 'Undid the Royal MCP block on IP %s', $ip ) );
		return Envelope::success( sprintf( 'Unblocked %s.', $ip ), [ 'undone' => true, 'op' => 'gp_block_ip', 'target' => $target ] );
	}

	/** Remove the lockout row for $ip through GuardPress's brute-force module. */
	private static function lift_lockout( $ip ) {
		if ( ! class_exists( 'GuardPress_Brute_Force_Protection' ) ) {
			return false;
		}
		$module = ( class_exists( 'GuardPress' ) && is_callable( [ 'GuardPress', 'get_instance' ] ) ) ? \GuardPress::get_instance()->get_module( 'brute-force' ) : null;
		if ( ! is_object( $module ) || ! is_callable( [ $module, 'unblock_ip' ] ) ) {
			$module = new \GuardPress_Brute_Force_Protection();
		}
		return (bool) $module->unblock_ip( $ip );
	}

	// ==================== gp_get_hardening ====================

	/** Whether a hardening setting is on, read the way GuardPress decides it. */
	private static function setting_on( $key ) {
		switch ( $key ) {
			case 'hardening':
				return \GuardPress_Settings::is_enabled( 'hardening', true );
			case 'xml_rpc_disabled':
				return ( class_exists( 'GuardPress_Hardening' ) && is_callable( [ 'GuardPress_Hardening', 'xmlrpc_disabled' ] ) ) ? (bool) \GuardPress_Hardening::xmlrpc_disabled() : (bool) get_option( self::HARDENING[ $key ][1] );
			default:
				return (bool) get_option( self::HARDENING[ $key ][1] );
		}
	}

	/** One hardening setting as reported. */
	private static function setting_row( $key ) {
		$on      = self::setting_on( $key );
		$bundled = in_array( $key, [ 'xml_rpc_disabled', 'force_strong_passwords' ], true );
		return [
			'key'         => $key,
			'label'       => self::HARDENING[ $key ][0],
			'enabled'     => $on,
			// XML-RPC and password rules run inside the hardening module, so they apply only while it is on.
			'in_effect'   => $on && ( ! $bundled || self::setting_on( 'hardening' ) ),
			'recommended' => true,
		];
	}

	/** 2FA counts (no user names). */
	private static function two_factor() {
		global $wpdb;
		$roles   = get_option( 'guardpress_2fa_enforced_roles', [] );
		$out     = [
			'feature_enabled'    => \GuardPress_Settings::is_enabled( 'two_factor' ),
			'enforced_roles'     => is_array( $roles ) ? array_values( array_map( 'strval', $roles ) ) : [],
			'users_with_2fa'     => null,
			'admins_without_2fa' => null,
		];
		$table = $wpdb->prefix . 'gp_two_factor';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table check
		if ( $table !== (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return $out;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is prefix + literal
		$enrolled = array_map( 'intval', (array) $wpdb->get_col( "SELECT user_id FROM {$table} WHERE enabled = 1" ) );
		$admins   = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
		$out['users_with_2fa']     = count( array_unique( $enrolled ) );
		$out['admins_without_2fa'] = count( array_diff( array_map( 'intval', $admins ), $enrolled ) );
		return $out;
	}

	public static function handle_get_hardening( array $args ) {
		$refused = self::gate( 'gp_get_hardening' );
		if ( $refused ) {
			return $refused;
		}
		$settings = [];
		foreach ( array_keys( self::HARDENING ) as $key ) {
			$settings[] = self::setting_row( $key );
		}
		$tfa = self::two_factor();
		$on  = count( array_filter( array_column( $settings, 'in_effect' ) ) );
		return Envelope::success( sprintf( '%d of %d GuardPress hardening settings in effect; %s administrator(s) without 2FA.', $on, count( $settings ), null === $tfa['admins_without_2fa'] ? 'unknown' : (string) $tfa['admins_without_2fa'] ), [
			'settings'   => $settings,
			'two_factor' => $tfa,
		] );
	}

	// ==================== gp_get_malware_results / gp_get_file_integrity ====================

	/** A file path as the reply shows it: relative to the WordPress root, never the server path. */
	private static function site_path( $path ) {
		$path  = wp_normalize_path( (string) $path );
		$roots = [ [ trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ), 'wp-content/' ], [ trailingslashit( wp_normalize_path( ABSPATH ) ), '' ] ];
		$fold  = '\\' === DIRECTORY_SEPARATOR;
		foreach ( $roots as $root ) {
			if ( 0 === ( $fold ? stripos( $path, $root[0] ) : strpos( $path, $root[0] ) ) ) {
				return $root[1] . ltrim( substr( $path, strlen( $root[0] ) ), '/' );
			}
		}
		return basename( $path );
	}

	/** Bounded paging arguments. */
	private static function paging( array $args, $max = 100 ) {
		$limit  = isset( $args['limit'] ) && is_numeric( $args['limit'] ) ? max( 1, min( $max, (int) $args['limit'] ) ) : 50;
		$offset = isset( $args['offset'] ) && is_numeric( $args['offset'] ) ? max( 0, min( 100000, (int) $args['offset'] ) ) : 0;
		return [ $limit, $offset ];
	}

	public static function handle_get_malware_results( array $args ) {
		$refused = self::gate( 'gp_get_malware_results' );
		if ( $refused ) {
			return $refused;
		}
		global $wpdb;
		$table    = $wpdb->prefix . 'gp_malware_scans';
		$statuses = [ 'detected', 'quarantined', 'cleaned', 'false_positive' ];
		$status   = isset( $args['status'] ) ? ( is_string( $args['status'] ) ? sanitize_key( $args['status'] ) : '?' ) : 'detected';
		if ( 'all' !== $status && ! in_array( $status, $statuses, true ) ) {
			return Envelope::error( 'invalid_status', 'status must be one of: all, ' . implode( ', ', $statuses ) . '.' );
		}
		$severity = isset( $args['severity'] ) ? ( is_string( $args['severity'] ) ? sanitize_key( $args['severity'] ) : '?' ) : '';
		if ( '' !== $severity && ! in_array( $severity, [ 'low', 'medium', 'high', 'critical' ], true ) ) {
			return Envelope::error( 'invalid_severity', 'severity must be one of: low, medium, high, critical.' );
		}
		list( $limit, $offset ) = self::paging( $args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is prefix + literal
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return Envelope::success( 'GuardPress has not run a malware scan on this site yet.', [ 'scanned' => false, 'summary' => [], 'items' => [], 'total' => 0 ] );
		}
		$where = [ '1=1' ];
		$vals  = [];
		if ( 'all' !== $status ) {
			$where[] = 'status = %s';
			$vals[]  = $status;
		}
		if ( '' !== $severity ) {
			$where[] = 'severity = %s';
			$vals[]  = $severity;
		}
		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table is prefix + literal; placeholders match $vals
		$total = (int) $wpdb->get_var( $vals ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $vals ) : "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table is prefix + literal; placeholders match
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, file_path, threat_type, threat_description, severity, status, detected_at, resolved_at FROM {$table} WHERE {$where_sql} ORDER BY detected_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $vals, [ $limit, $offset ] ) ), ARRAY_A );
		$items = [];
		foreach ( $rows as $r ) {
			$items[] = [
				'id'          => (int) $r['id'],
				'file'        => self::site_path( $r['file_path'] ),
				'threat_type' => (string) $r['threat_type'],
				'description' => mb_substr( wp_strip_all_tags( (string) $r['threat_description'] ), 0, 300 ),
				'severity'    => (string) $r['severity'],
				'status'      => (string) $r['status'],
				'detected_at' => (string) $r['detected_at'],
				'resolved_at' => null === $r['resolved_at'] ? null : (string) $r['resolved_at'],
			];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is prefix + literal
		$counts  = (array) $wpdb->get_results( "SELECT status, severity, COUNT(*) AS n FROM {$table} GROUP BY status, severity", ARRAY_A );
		$summary = [ 'detected' => 0, 'critical_detected' => 0, 'quarantined' => 0, 'cleaned' => 0, 'false_positive' => 0 ];
		foreach ( $counts as $c ) {
			if ( isset( $summary[ $c['status'] ] ) ) {
				$summary[ $c['status'] ] += (int) $c['n'];
			}
			if ( 'detected' === $c['status'] && 'critical' === $c['severity'] ) {
				$summary['critical_detected'] += (int) $c['n'];
			}
		}
		$state = get_option( 'guardpress_malware_scan_state', [] );
		$last  = [];
		if ( is_array( $state ) && $state ) {
			$at   = static function ( $v ) {
				return is_numeric( $v ) && (int) $v > 0 ? gmdate( 'c', (int) $v ) : null;
			};
			$last = [
				'phase'         => isset( $state['phase'] ) ? sanitize_key( (string) $state['phase'] ) : null,
				// A queued scan with no progress for an hour has stopped (GuardPress treats it as stale).
				'running'       => empty( $state['completed_at'] ) && empty( $state['cancelled_at'] ) && ! empty( $state['started_at'] )
					&& time() - (int) ( $state['last_tick_at'] ?? $state['started_at'] ) < HOUR_IN_SECONDS,
				'started_at'    => $at( $state['started_at'] ?? null ),
				'completed_at'  => $at( $state['completed_at'] ?? null ),
				'cancelled_at'  => $at( $state['cancelled_at'] ?? null ),
				'files_total'   => isset( $state['total'] ) ? (int) $state['total'] : null,
				'files_done'    => isset( $state['processed'] ) ? (int) $state['processed'] : null,
				'threats_found' => isset( $state['threats_found'] ) ? (int) $state['threats_found'] : null,
			];
		}
		// Small sites are scanned in one request, which records only the finish time.
		$finished = (int) get_option( 'guardpress_last_malware_scan', 0 );
		if ( $finished > 0 ) {
			$last['last_completed_at'] = gmdate( 'c', $finished );
		}
		return Envelope::success(
			sprintf( '%d GuardPress malware finding(s) match (%d detected now, %d critical).', $total, $summary['detected'], $summary['critical_detected'] ),
			[ 'scanned' => true, 'summary' => $summary, 'last_scan' => $last, 'items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset ]
		);
	}

	public static function handle_get_file_integrity( array $args ) {
		$refused = self::gate( 'gp_get_file_integrity' );
		if ( $refused ) {
			return $refused;
		}
		global $wpdb;
		$table   = $wpdb->prefix . 'gp_file_changes';
		$periods = [ 'day' => DAY_IN_SECONDS, 'week' => WEEK_IN_SECONDS, 'month' => MONTH_IN_SECONDS ];
		$period  = isset( $args['period'] ) ? ( is_string( $args['period'] ) ? sanitize_key( $args['period'] ) : '?' ) : 'week';
		if ( ! isset( $periods[ $period ] ) ) {
			return Envelope::error( 'invalid_period', 'period must be one of: day, week, month.' );
		}
		$status = isset( $args['status'] ) ? ( is_string( $args['status'] ) ? sanitize_key( $args['status'] ) : '?' ) : '';
		if ( '' !== $status && ! in_array( $status, [ 'added', 'modified', 'deleted' ], true ) ) {
			return Envelope::error( 'invalid_status', 'status must be one of: added, modified, deleted.' );
		}
		$suspicious = ! empty( $args['suspicious_only'] ) && in_array( $args['suspicious_only'], [ true, 1, '1', 'true' ], true );
		list( $limit, $offset ) = self::paging( $args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return Envelope::success( 'GuardPress has not checked file integrity on this site yet.', [ 'checked' => false, 'summary' => [], 'changes' => [], 'total' => 0 ] );
		}
		// GuardPress stores checked_at in the site's time zone.
		$since = wp_date( 'Y-m-d H:i:s', time() - $periods[ $period ] );
		$where = [ "status IN ('added','modified','deleted')", 'checked_at >= %s' ];
		$vals  = [ $since ];
		if ( '' !== $status ) {
			$where[] = 'status = %s';
			$vals[]  = $status;
		}
		if ( $suspicious ) {
			$where[] = 'suspicious_flag = 1';
		}
		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table is prefix + literal
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $vals ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table is prefix + literal
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT file_path, status, origin, suspicious_flag, file_size, last_modified, checked_at FROM {$table} WHERE {$where_sql} ORDER BY checked_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $vals, [ $limit, $offset ] ) ), ARRAY_A );
		$changes = [];
		foreach ( $rows as $r ) {
			$changes[] = [
				'file'          => self::site_path( $r['file_path'] ),
				'status'        => (string) $r['status'],
				'origin'        => null === $r['origin'] ? null : (string) $r['origin'],
				'suspicious'    => ! empty( $r['suspicious_flag'] ),
				'size'          => null === $r['file_size'] ? null : (int) $r['file_size'],
				'last_modified' => null === $r['last_modified'] ? null : (string) $r['last_modified'],
				'checked_at'    => (string) $r['checked_at'],
			];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is prefix + literal
		$by_status = (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n, SUM(suspicious_flag) AS s FROM {$table} WHERE status IN ('added','modified','deleted') AND checked_at >= %s GROUP BY status", $since ), ARRAY_A );
		$summary   = [ 'added' => 0, 'modified' => 0, 'deleted' => 0, 'suspicious' => 0 ];
		foreach ( $by_status as $b ) {
			$summary[ $b['status'] ] = (int) $b['n'];
			$summary['suspicious']  += (int) $b['s'];
		}
		return Envelope::success(
			sprintf( 'GuardPress saw %d file change(s) in the last %s (%d added, %d modified, %d deleted, %d suspicious).', array_sum( array_slice( $summary, 0, 3 ) ), $period, $summary['added'], $summary['modified'], $summary['deleted'], $summary['suspicious'] ),
			[ 'checked' => true, 'period' => $period, 'summary' => $summary, 'changes' => $changes, 'total' => $total, 'limit' => $limit, 'offset' => $offset ]
		);
	}

	// ==================== gp_get_dashboard_summary ====================

	/**
	* Composite read: failed logins (24h + 7d) + blocked IPs + 2FA
	* enrollment + KEV/Wordfence intel status. One call for a
	* dashboard "security posture at a glance" card. Graceful
	* degradation when GP inactive.
	*/
	public static function handle_get_dashboard_summary( array $args ) {
		self::require_cap();
		if ( ! class_exists( 'GuardPress_Logger' ) ) {
			return self::inactive_envelope();
		}

		$stats_24h = self::collect_stats( 'day' );
		$stats_7d  = self::collect_stats( 'week' );
		$two_fa    = self::collect_two_factor();
		$kev_intel = self::collect_kev_intel();
		$wf_intel  = self::collect_wordfence_intel();

		$summary = [
			'active'         => true,
			'failed_logins'  => [
				'last_24h' => (int) ( $stats_24h['failed_logins'] ?? 0 ),
				'last_7d'  => (int) ( $stats_7d['failed_logins']  ?? 0 ),
			],
			'blocked_ips'    => [
				'active_count'     => (int) ( $stats_7d['blocked_ips'] ?? 0 ),
				// Permanent entries (addresses and ranges) live on the block list, apart from the timed lockouts counted above.
				'block_list_count' => class_exists( 'GuardPress_Settings' ) ? count( (array) \GuardPress_Settings::get_ip_blacklist() ) : null,
			],
			'security_alerts' => [
				'last_24h_new' => (int) ( $stats_24h['security_alerts'] ?? 0 ),
				'last_7d_new'  => (int) ( $stats_7d['security_alerts']  ?? 0 ),
			],
			'two_factor'     => $two_fa,
			'threat_intel'   => [
				'kev'       => $kev_intel,
				'wordfence' => $wf_intel,
			],
		];

		return Envelope::success( self::format_summary_text( $summary ), $summary );
	}

	private static function collect_stats( $period ) {
		if ( ! is_callable( [ 'GuardPress_Logger', 'get_security_stats' ] ) ) {
			return [];
		}
		$stats = call_user_func( [ 'GuardPress_Logger', 'get_security_stats' ], $period );
		return is_array( $stats ) ? $stats : [];
	}

	private static function collect_two_factor() {
		global $wpdb;
		$default = [
			'users_enrolled'      => null,
			'users_total'         => null,
			'enrollment_rate_pct' => null,
			'feature_enabled'     => self::two_factor_on(),
		];

		$table = $wpdb->prefix . 'gp_two_factor';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is prefix+literal
		$table_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		if ( ! $table_exists ) {
			return $default;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is prefix+literal
		$enrolled = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1" );

		$user_counts = function_exists( 'count_users' ) ? count_users() : null;
		$users_total = is_array( $user_counts ) && isset( $user_counts['total_users'] ) ? (int) $user_counts['total_users'] : null;

		$rate = null;
		if ( $users_total !== null && $users_total > 0 ) {
			$rate = (int) round( ( $enrolled / $users_total ) * 100 );
		}

		return [
			'users_enrolled'      => $enrolled,
			'users_total'         => $users_total,
			'enrollment_rate_pct' => $rate,
			'feature_enabled'     => self::two_factor_on(),
		];
	}

	/** Two-factor switched on, read the way GuardPress decides it (current and older option names). */
	private static function two_factor_on() {
		return class_exists( 'GuardPress_Settings' ) ? (bool) \GuardPress_Settings::is_enabled( 'two_factor' ) : (bool) get_option( 'guardpress_2fa_enabled', false );
	}

	private static function collect_kev_intel() {
		$default = [ 'cache_populated' => false, 'cache_age_seconds' => null, 'entry_count' => null ];
		if ( ! class_exists( 'GuardPress_CISA_KEV' ) ) {
			return $default;
		}
		$populated = is_callable( [ 'GuardPress_CISA_KEV', 'is_cache_populated' ] )
			? (bool) call_user_func( [ 'GuardPress_CISA_KEV', 'is_cache_populated' ] )
			: false;
		$age = is_callable( [ 'GuardPress_CISA_KEV', 'cache_age_seconds' ] )
			? call_user_func( [ 'GuardPress_CISA_KEV', 'cache_age_seconds' ] )
			: null;
		$cache = is_callable( [ 'GuardPress_CISA_KEV', 'get_cache' ] )
			? call_user_func( [ 'GuardPress_CISA_KEV', 'get_cache' ] )
			: null;
		return [
			'cache_populated'   => $populated,
			'cache_age_seconds' => is_numeric( $age ) ? (int) $age : null,
			'entry_count'       => self::list_size( $cache ),
		];
	}

	private static function collect_wordfence_intel() {
		$default = [ 'cache_populated' => false, 'cache_age_seconds' => null, 'entry_count' => null ];
		if ( ! class_exists( 'GuardPress_Wordfence_Intel' ) ) {
			return $default;
		}
		$populated = is_callable( [ 'GuardPress_Wordfence_Intel', 'is_cache_populated' ] )
			? (bool) call_user_func( [ 'GuardPress_Wordfence_Intel', 'is_cache_populated' ] )
			: false;
		$age = is_callable( [ 'GuardPress_Wordfence_Intel', 'cache_age_seconds' ] )
			? call_user_func( [ 'GuardPress_Wordfence_Intel', 'cache_age_seconds' ] )
			: null;
		$cache = is_callable( [ 'GuardPress_Wordfence_Intel', 'get_cache' ] )
			? call_user_func( [ 'GuardPress_Wordfence_Intel', 'get_cache' ] )
			: null;
		return [
			'cache_populated'   => $populated,
			'cache_age_seconds' => is_numeric( $age ) ? (int) $age : null,
			'entry_count'       => self::list_size( $cache ),
		];
	}

	/** How many entries a cached threat list holds, or null when it does not say. */
	private static function list_size( $cache ) {
		if ( ! is_array( $cache ) ) {
			return null;
		}
		if ( isset( $cache['entry_count'] ) && is_numeric( $cache['entry_count'] ) ) {
			return (int) $cache['entry_count'];
		}
		return ( isset( $cache['entries'] ) && is_array( $cache['entries'] ) ) ? count( $cache['entries'] ) : null;
	}

	private static function format_summary_text( array $summary ) {
		if ( empty( $summary['active'] ) ) {
			return 'gp_get_dashboard_summary: GuardPress is not active on this site.';
		}
		$fl  = $summary['failed_logins'];
		$bi  = $summary['blocked_ips'];
		$tfa = $summary['two_factor'];
		return sprintf(
			'gp_get_dashboard_summary: %d failed logins (24h), %d failed logins (7d), %d blocked IPs active, 2FA %s.',
			(int) $fl['last_24h'],
			(int) $fl['last_7d'],
			(int) $bi['active_count'],
			( null === $tfa['enrollment_rate_pct'] ? 'n/a' : sprintf( '%d%% enrolled (%d/%d users)', $tfa['enrollment_rate_pct'], $tfa['users_enrolled'], $tfa['users_total'] ) )
		);
	}

	private static function inactive_envelope() {
		$summary = [
			'active'         => false,
			'failed_logins'  => null,
			'blocked_ips'    => null,
			'security_alerts' => null,
			'two_factor'     => null,
			'threat_intel'   => null,
		];
		return Envelope::success( 'gp_get_dashboard_summary: GuardPress is not active on this site.', $summary );
	}

	// ==================== Schemas ====================

	private static function schemas() {
		return [
			'gp_get_dashboard_summary' => [
				'name'        => 'gp_get_dashboard_summary',
				'description' => 'GuardPress security at a glance: failed logins (24 hours and 7 days), blocked addresses (active_count: lockouts running now; block_list_count: permanent entries), new security alerts (24 hours and 7 days), two-factor enrolment (users enrolled of total) and whether the threat-intelligence lists are loaded. Answers { active: false } when GuardPress is not installed or active. Read-only. Requires manage_options.',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
			'gp_get_hardening' => [
				'name'        => 'gp_get_hardening',
				'description' => 'GuardPress hardening settings and two-factor status. settings: [{key, label, enabled, in_effect, recommended}] for ' . implode( ', ', array_keys( self::HARDENING ) ) . ' (XML-RPC and password rules are in effect only while hardening is on). two_factor: feature_enabled, enforced_roles, users_with_2fa, admins_without_2fa (counts only, no user names). Read-only. Requires manage_options.',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
			'gp_get_malware_results' => [
				'name'        => 'gp_get_malware_results',
				'description' => 'GuardPress malware scan findings: summary {detected, critical_detected, quarantined, cleaned, false_positive}, last_scan, and items [{id, file (relative to the site root), threat_type, description, severity, status, detected_at, resolved_at}] newest first. Filter by status (default detected; all for every status) and severity; page with limit (max 100) and offset. Read-only. Requires manage_options.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'status'   => [ 'type' => 'string', 'enum' => [ 'all', 'detected', 'quarantined', 'cleaned', 'false_positive' ] ],
						'severity' => [ 'type' => 'string', 'enum' => [ 'low', 'medium', 'high', 'critical' ] ],
						'limit'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
						'offset'   => [ 'type' => 'integer', 'minimum' => 0 ],
					],
				],
			],
			'gp_get_file_integrity' => [
				'name'        => 'gp_get_file_integrity',
				'description' => 'Files GuardPress saw added, modified or deleted: summary {added, modified, deleted, suspicious} for the period (day, week default, month) and changes [{file (relative to the site root), status, origin (core, plugin, theme, uploads), suspicious, size, last_modified, checked_at}] newest first. Filter by status or suspicious_only; page with limit (max 100) and offset. Read-only. Requires manage_options.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'period'          => [ 'type' => 'string', 'enum' => [ 'day', 'week', 'month' ] ],
						'status'          => [ 'type' => 'string', 'enum' => [ 'added', 'modified', 'deleted' ] ],
						'suspicious_only' => [ 'type' => 'boolean' ],
						'limit'           => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
						'offset'          => [ 'type' => 'integer', 'minimum' => 0 ],
					],
				],
			],
			'gp_block_ip' => [
				'name'        => 'gp_block_ip',
				'description' => 'Block one IP address in GuardPress. Without duration_hours the block is permanent (GuardPress\'s block list, the same as its "Block IP" screen); duration_hours (1-8760) sets a block that expires and needs a GuardPress version with timed blocks (else guardpress_update_required). Refused: addresses on GuardPress\'s allowlist (ip_allowlisted), the address the request came from and the server\'s own address (own_address), and addresses GuardPress protects as an administrator\'s (admin_address_protected, with reason; only the site owner can block those, in GuardPress). An address already blocked permanently is reported with was_already_blocked true and nothing changes (no undo). Also refused: private and reserved addresses (internal_address). A timed block is not added over a lockout that already runs at least as long. Returns blocked, was_already_blocked, block {ip (in its standard spelling), reason (kept with a timed block; for a permanent block it goes to the audit log only), blocked_at, expires_at (null = permanent), source}, and an undo token when a block was added; the undo leaves a timed block alone if it was changed afterwards, unless force is passed. dry_run true previews. Recorded in GuardPress\'s audit log. Requires manage_options.',
				'inputSchema' => [
					'type'       => 'object',
					'required'   => [ 'ip' ],
					'properties' => [
						'ip'             => [ 'type' => 'string', 'description' => 'One IPv4 or IPv6 address.' ],
						'reason'         => [ 'type' => 'string', 'description' => 'Up to 255 characters. Default "Blocked through Royal MCP".' ],
						'duration_hours' => [ 'type' => 'integer', 'description' => '1-8760. Leave out for a permanent block.' ],
						'dry_run'        => [ 'type' => 'boolean', 'description' => 'Default false; any value other than false previews.' ],
					],
				],
			],
		];
	}
}
