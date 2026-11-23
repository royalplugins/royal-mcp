<?php
/**
 * Keeps an outbound response from being decompressed twice.
 *
 * With the curl transport, curl already decodes a compressed body (and the response size
 * limit counts the decoded bytes). The Content-Encoding header is still in the response,
 * so the HTTP library would decode the body a second time with no size limit; a body that
 * is itself compressed data would then expand without bound. While the guard runs, the
 * header is dropped from curl responses before the library parses them, so the body stays
 * as curl decoded it. Other transports are left alone.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Http_Decode_Guard {

	/** Nesting depth of run() calls. */
	private static $depth = 0;

	/** Whether the last guarded curl response carried a Content-Encoding header. */
	private static $saw_encoding = false;

	/**
	 * Run a callable that makes outbound requests with the guard in place.
	 *
	 * @param callable $fn Callable.
	 * @return mixed What the callable returns.
	 */
	public static function run( callable $fn ) {
		self::start();
		try {
			return $fn();
		} finally {
			self::stop();
		}
	}

	/** Put the guard in place; pair every call with stop() in a finally block. */
	public static function start() {
		if ( 0 === self::$depth++ ) {
			add_action( 'requests-curl.after_request', [ __CLASS__, 'strip' ], 1, 1 );
		}
		self::$saw_encoding = false;
	}

	/** Take the guard away once the outermost start() is matched. */
	public static function stop() {
		if ( self::$depth > 0 && 0 === --self::$depth ) {
			remove_action( 'requests-curl.after_request', [ __CLASS__, 'strip' ], 1 );
		}
	}

	/** Whether the last response fetched under the guard had been sent compressed. */
	public static function saw_encoding() {
		return self::$saw_encoding;
	}

	/**
	 * Drop Content-Encoding from the header block of a raw curl response (headers, blank line, body).
	 *
	 * @param string $raw Raw response, changed in place.
	 */
	public static function strip( &$raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return;
		}
		$end  = strpos( $raw, "\r\n\r\n" );
		$head = false === $end ? $raw : substr( $raw, 0, $end );
		$rest = false === $end ? '' : substr( $raw, $end );
		$clean = preg_replace( '/\r?\nContent-Encoding:[^\r\n]*/i', '', $head, -1, $count );
		if ( $count > 0 && is_string( $clean ) ) {
			self::$saw_encoding = true;
			$raw = $clean . $rest;
		}
	}
}
