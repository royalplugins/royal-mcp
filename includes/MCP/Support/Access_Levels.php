<?php
/**
 * Access level per connection: Full, Read-only or Drafts only, for each
 * OAuth client, for the API key, and for calls that arrive through other
 * routes (other MCP servers via the Abilities API, browser agents). Site-wide
 * read-only and drafts-only modes still apply on top.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Access_Levels {

	const LEVELS = [ 'full', 'read-only', 'drafts-only' ];

	/** Per-client levels, keyed by OAuth client_id. Full is not stored. */
	const CLIENTS_OPTION = 'royal_mcp_client_access';

	/**
	 * A level name in its stored form, or '' when it isn't one.
	 *
	 * @param mixed $level Level as typed or posted.
	 * @return string
	 */
	public static function normalize( $level ) {
		$level = str_replace( [ '_', ' ' ], '-', strtolower( trim( (string) $level ) ) );
		if ( 'readonly' === $level ) {
			$level = 'read-only';
		} elseif ( 'drafts' === $level || 'drafts-only-mode' === $level ) {
			$level = 'drafts-only';
		}
		return in_array( $level, self::LEVELS, true ) ? $level : '';
	}

	/** Display label for a level. */
	public static function label( $level ) {
		$labels = [
			'full'        => __( 'Full', 'royal-mcp' ),
			'read-only'   => __( 'Read-only', 'royal-mcp' ),
			'drafts-only' => __( 'Drafts only', 'royal-mcp' ),
		];
		return $labels[ $level ] ?? $labels['full'];
	}

	/**
	 * Stored per-client levels (only clients that are not Full).
	 *
	 * @return array<string, string>
	 */
	public static function clients() {
		$stored = get_option( self::CLIENTS_OPTION, [] );
		$out    = [];
		if ( is_array( $stored ) ) {
			foreach ( $stored as $client_id => $level ) {
				$level = self::normalize( $level );
				if ( '' !== $level && 'full' !== $level ) {
					$out[ (string) $client_id ] = $level;
				}
			}
		}
		return $out;
	}

	/** Level for one OAuth client. */
	public static function for_client( $client_id ) {
		$clients = self::clients();
		return $clients[ (string) $client_id ] ?? 'full';
	}

	/**
	 * Set one client's level. Levels of clients that are no longer
	 * registered are dropped on the way.
	 *
	 * @param string $client_id Registered OAuth client.
	 * @param string $level     Level.
	 * @return string The stored level.
	 * @throws \InvalidArgumentException On an unknown level or client.
	 */
	public static function set_client( $client_id, $level ) {
		$client_id = (string) $client_id;
		$level     = self::normalize( $level );
		if ( '' === $level ) {
			throw new \InvalidArgumentException( 'Level must be full, read-only or drafts-only.' );
		}
		if ( '' === $client_id || ! \Royal_MCP\OAuth\Token_Store::get_client( $client_id ) ) {
			throw new \InvalidArgumentException( 'No registered OAuth client has that client_id.' );
		}
		$clients = self::clients();
		if ( 'full' === $level ) {
			unset( $clients[ $client_id ] );
		} else {
			$clients[ $client_id ] = $level;
		}
		global $wpdb;
		foreach ( array_keys( $clients ) as $id ) {
			$wpdb->last_error = '';
			$found            = \Royal_MCP\OAuth\Token_Store::get_client( (string) $id );
			// Drop a level only when the lookup worked and found no client.
			if ( ! $found && '' === $wpdb->last_error ) {
				unset( $clients[ $id ] );
			}
		}
		update_option( self::CLIENTS_OPTION, $clients, false );
		return $level;
	}

	/** Level for connections that use the WordPress API key. */
	public static function for_api_key() {
		$settings = get_option( 'royal_mcp_settings', [] );
		$level    = is_array( $settings ) ? self::normalize( $settings['api_key_access'] ?? '' ) : '';
		return '' !== $level ? $level : 'full';
	}

	/** Level for calls through other MCP servers (Abilities API) and browser agents. */
	public static function for_other_routes() {
		$settings = get_option( 'royal_mcp_settings', [] );
		$level    = is_array( $settings ) ? self::normalize( $settings['other_routes_access'] ?? '' ) : '';
		return '' !== $level ? $level : 'full';
	}
}
