<?php
/**
 * The Connection Doctor's own requests to the sign-in endpoints. Each carries a one-time
 * value in a request header; the endpoints answer it without counting it against any rate
 * limit, without writing it to the Activity Log and without creating anything.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Doctor_Probe {

	const HEADER = 'X-Royal-MCP-Doctor';
	const KEY    = 'royal_mcp_doctor_probe';

	/** Values matched in this request: a value is used up by its first match, later checks reuse the answer. */
	private static $used = [];

	/** Whether this request is a Doctor probe. */
	public static function is_probe_request() {
		return self::is_probe_value( isset( $_SERVER['HTTP_X_ROYAL_MCP_DOCTOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_ROYAL_MCP_DOCTOR'] ) ) : '' );
	}

	/** Whether a header value is a live one-time probe value (and use it up). */
	public static function is_probe_value( $sent ) {
		$sent = (string) $sent;
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $sent ) ) {
			return false;
		}
		if ( isset( self::$used[ $sent ] ) ) {
			return true;
		}
		$key  = self::KEY . '_' . md5( $sent );
		$want = (string) get_transient( $key );
		if ( '' === $want || ! hash_equals( $want, $sent ) ) {
			return false;
		}
		delete_transient( $key );
		self::$used[ $sent ] = true;
		return true;
	}

	/** A new one-time value, live for a minute. */
	public static function issue() {
		$value = wp_generate_password( 32, false, false );
		set_transient( self::KEY . '_' . md5( $value ), $value, MINUTE_IN_SECONDS ); // audit:transient-ttl-only -- one-time probe value
		return $value;
	}

	/** Remove a value that was not used. */
	public static function forget( $value ) {
		delete_transient( self::KEY . '_' . md5( (string) $value ) );
	}
}
