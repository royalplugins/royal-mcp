<?php
/**
 * Which users may use Royal MCP tools outside this plugin's own MCP endpoint
 * (the WordPress Abilities API and the MCP servers built on it, browser
 * agents), and which users may connect an AI client over OAuth. Both default
 * to administrators; the site owner can widen them.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Route_Roles {

	const OUTSIDE_KEY = 'outside_endpoint_role';
	const CONNECT_KEY = 'connect_role';

	const OUTSIDE_CHOICES = [ 'administrators', 'editors', 'tool_capability' ];
	const CONNECT_CHOICES = [ 'administrators', 'editors', 'any' ];

	/** Stored value of a setting, or its default when unset or unknown. */
	public static function setting( $key ) {
		$settings = get_option( 'royal_mcp_settings', [] );
		$value    = is_array( $settings ) ? (string) ( $settings[ $key ] ?? '' ) : '';
		$choices  = self::OUTSIDE_KEY === $key ? self::OUTSIDE_CHOICES : self::CONNECT_CHOICES;
		return in_array( $value, $choices, true ) ? $value : 'administrators';
	}

	/** A posted value in stored form, or the default. */
	public static function normalize( $key, $value ) {
		$value   = sanitize_key( (string) $value );
		$choices = self::OUTSIDE_KEY === $key ? self::OUTSIDE_CHOICES : self::CONNECT_CHOICES;
		return in_array( $value, $choices, true ) ? $value : 'administrators';
	}

	/** Display label for a choice. */
	public static function label( $value ) {
		$labels = [
			'administrators'  => __( 'Administrators only', 'royal-mcp' ),
			'editors'         => __( 'Editors and above', 'royal-mcp' ),
			'tool_capability' => __( 'Any user with the tool\'s own capability', 'royal-mcp' ),
			'any'             => __( 'Any user', 'royal-mcp' ),
		];
		return $labels[ $value ] ?? $value;
	}

	/** Whether the user meets a role setting. */
	private static function meets( $value, $user_id ) {
		if ( 'administrators' === $value ) {
			return user_can( $user_id, 'manage_options' );
		}
		if ( 'editors' === $value ) {
			return user_can( $user_id, 'edit_others_posts' );
		}
		return $user_id > 0;
	}

	/** Whether the current user may use tools outside the Royal MCP endpoint. */
	public static function outside_allowed( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		return self::meets( self::setting( self::OUTSIDE_KEY ), $user_id );
	}

	/** Whether the user may connect AI clients over OAuth. */
	public static function connect_allowed( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		return self::meets( self::setting( self::CONNECT_KEY ), $user_id );
	}
}
