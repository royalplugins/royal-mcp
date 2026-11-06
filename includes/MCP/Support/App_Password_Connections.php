<?php
/**
 * Application Passwords that reach Royal MCP tools through the WordPress
 * Abilities API or an MCP Adapter server. Each one is listed on Connected
 * Clients (user, password name, first and last use). An admin can block a
 * password from Royal MCP (WordPress keeps it) or delete it in WordPress.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class App_Password_Connections {

	const SEEN_OPTION    = 'royal_mcp_app_password_seen';
	const BLOCKED_OPTION = 'royal_mcp_app_password_blocked';
	const MAX_ROWS       = 200;
	const WRITE_EVERY    = 60;

	/** UUID of the Application Password that signed in this request, or ''. */
	public static function current_uuid() {
		if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
			return '';
		}
		$uuid = rest_get_authenticated_app_password();
		return is_string( $uuid ) && self::valid_uuid( $uuid ) ? $uuid : '';
	}

	/**
	 * Refuse a blocked password, otherwise record the call. Runs on every call
	 * through the Abilities route; requests that did not sign in with an
	 * Application Password are left alone.
	 *
	 * @throws \Exception When the password is blocked from Royal MCP.
	 */
	public static function check_and_record() {
		$uuid    = self::current_uuid();
		$user_id = (int) get_current_user_id();
		if ( '' === $uuid || $user_id < 1 ) {
			return;
		}
		if ( self::is_blocked( $user_id, $uuid ) ) {
			throw new \Exception( 'This Application Password is blocked from Royal MCP. A site admin can allow it again under Royal MCP > Connected Clients.', 403 );
		}
		self::record( $user_id, $uuid );
	}

	public static function is_blocked( $user_id, $uuid ) {
		$blocked = get_option( self::BLOCKED_OPTION, [] );
		return is_array( $blocked ) && isset( $blocked[ self::key( $user_id, $uuid ) ] );
	}

	/** Last use is written at most once a minute per password, so busy agents do not rewrite the option on every call. */
	private static function record( $user_id, $uuid ) {
		$rows = get_option( self::SEEN_OPTION, [] );
		$rows = is_array( $rows ) ? $rows : [];
		$key  = self::key( $user_id, $uuid );
		$now  = time();
		if ( isset( $rows[ $key ] ) && is_array( $rows[ $key ] ) && $now - (int) ( $rows[ $key ]['last_seen'] ?? 0 ) < self::WRITE_EVERY ) {
			return;
		}
		$rows[ $key ] = [
			'user_id'    => (int) $user_id,
			'uuid'       => (string) $uuid,
			'first_seen' => isset( $rows[ $key ]['first_seen'] ) ? (int) $rows[ $key ]['first_seen'] : $now,
			'last_seen'  => $now,
		];
		if ( count( $rows ) > self::MAX_ROWS ) {
			uasort( $rows, static function ( $a, $b ) {
				return (int) ( $b['last_seen'] ?? 0 ) <=> (int) ( $a['last_seen'] ?? 0 );
			} );
			$rows = array_slice( $rows, 0, self::MAX_ROWS, true );
		}
		update_option( self::SEEN_OPTION, $rows, false );
	}

	/**
	 * Every password seen or blocked, newest use first, with the user and the
	 * password's name from WordPress.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_connections() {
		$seen    = get_option( self::SEEN_OPTION, [] );
		$seen    = is_array( $seen ) ? $seen : [];
		$blocked = get_option( self::BLOCKED_OPTION, [] );
		$blocked = is_array( $blocked ) ? $blocked : [];
		$keys    = array_unique( array_merge( array_keys( $seen ), array_keys( $blocked ) ) );
		$out     = [];
		foreach ( $keys as $key ) {
			$parts = explode( ':', (string) $key, 2 );
			if ( 2 !== count( $parts ) || ! self::valid_uuid( $parts[1] ) ) {
				continue;
			}
			$user_id = (int) $parts[0];
			$uuid    = $parts[1];
			$user    = get_userdata( $user_id );
			$item    = class_exists( '\WP_Application_Passwords' ) ? \WP_Application_Passwords::get_user_application_password( $user_id, $uuid ) : null;
			$out[]   = [
				'user_id'    => $user_id,
				'user_login' => $user ? (string) $user->user_login : '',
				'uuid'       => $uuid,
				'name'       => is_array( $item ) ? (string) ( $item['name'] ?? '' ) : '',
				'exists'     => is_array( $item ),
				'first_seen' => (int) ( $seen[ $key ]['first_seen'] ?? 0 ),
				'last_seen'  => (int) ( $seen[ $key ]['last_seen'] ?? 0 ),
				'blocked'    => isset( $blocked[ $key ] ),
			];
		}
		usort( $out, static function ( $a, $b ) {
			return $b['last_seen'] <=> $a['last_seen'];
		} );
		return $out;
	}

	/** Block this password from Royal MCP; WordPress keeps it. */
	public static function block( $user_id, $uuid ) {
		if ( ! self::valid_uuid( $uuid ) || (int) $user_id < 1 ) {
			return false;
		}
		$blocked = get_option( self::BLOCKED_OPTION, [] );
		$blocked = is_array( $blocked ) ? $blocked : [];
		$blocked[ self::key( $user_id, $uuid ) ] = time();
		return update_option( self::BLOCKED_OPTION, $blocked, false ) || isset( $blocked[ self::key( $user_id, $uuid ) ] );
	}

	/** Allow a blocked password again. */
	public static function unblock( $user_id, $uuid ) {
		$blocked = get_option( self::BLOCKED_OPTION, [] );
		if ( ! is_array( $blocked ) || ! isset( $blocked[ self::key( $user_id, $uuid ) ] ) ) {
			return false;
		}
		unset( $blocked[ self::key( $user_id, $uuid ) ] );
		update_option( self::BLOCKED_OPTION, $blocked, false );
		return true;
	}

	/**
	 * Delete the Application Password in WordPress and drop it from both lists.
	 *
	 * @return true|\WP_Error
	 */
	public static function delete_in_wordpress( $user_id, $uuid ) {
		if ( ! self::valid_uuid( $uuid ) || (int) $user_id < 1 || ! class_exists( '\WP_Application_Passwords' ) ) {
			return new \WP_Error( 'royal_mcp_app_password_invalid', __( 'That Application Password could not be found.', 'royal-mcp' ) );
		}
		$done = \WP_Application_Passwords::delete_application_password( (int) $user_id, $uuid );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		if ( true !== $done ) {
			return new \WP_Error( 'royal_mcp_app_password_not_deleted', __( 'WordPress did not delete that Application Password.', 'royal-mcp' ) );
		}
		self::forget( $user_id, $uuid );
		return true;
	}

	/** Drop a password from the seen and blocked lists. */
	public static function forget( $user_id, $uuid ) {
		$key = self::key( $user_id, $uuid );
		foreach ( [ self::SEEN_OPTION, self::BLOCKED_OPTION ] as $option ) {
			$rows = get_option( $option, [] );
			if ( is_array( $rows ) && isset( $rows[ $key ] ) ) {
				unset( $rows[ $key ] );
				update_option( $option, $rows, false );
			}
		}
	}

	public static function valid_uuid( $uuid ) {
		return is_string( $uuid ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid );
	}

	private static function key( $user_id, $uuid ) {
		return (int) $user_id . ':' . strtolower( (string) $uuid );
	}
}
