<?php
/**
 * Site context: what the site owner wants every connected AI client to know
 * about this site (what it is for, its tone, what to leave alone). One text
 * for the whole site, written on the Context screen, given to a client when
 * it connects and readable as a resource.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Context {

	const OPTION = 'royal_mcp_site_context';

	/** Longest context kept, in characters: the text is sent on every connection. */
	const MAX_CHARS = 4000;

	const RESOURCE_URI = 'royal-mcp://site-context';

	const MASK = '[REDACTED]';

	/** The text as the owner saved it. */
	public static function get() {
		$saved = get_option( self::OPTION, '' );
		return is_string( $saved ) && '' !== trim( $saved ) ? $saved : '';
	}

	/**
	 * Plain text as the owner typed it: valid UTF-8, line breaks and tabs kept,
	 * other control characters and characters that do not show on screen removed.
	 * It is sent as JSON and shown in a text box, so nothing in it is treated as HTML.
	 *
	 * @param mixed $text Unslashed input.
	 * @return string
	 */
	public static function clean( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}
		$text = wp_check_invalid_utf8( $text, true );
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		// Control characters except line break and tab.
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
		// Zero-width and text-direction characters: a reader of the box would not see them, the AI client would.
		$text = (string) preg_replace( '/\xE2\x80[\x8B-\x8F\xAA-\xAE]|\xE2\x81[\xA6-\xA9]|\xEF\xBB\xBF/', '', $text );
		$text = trim( $text );
		if ( preg_match( '/^.{0,' . self::MAX_CHARS . '}/us', $text, $m ) ) {
			$text = $m[0];
		}
		return trim( $text );
	}

	/**
	 * Save the owner's text.
	 *
	 * @param mixed $text Unslashed input.
	 * @return string What was saved.
	 */
	public static function save( $text ) {
		$text = self::clean( $text );
		if ( '' === $text ) {
			delete_option( self::OPTION );
			return '';
		}
		update_option( self::OPTION, $text, false );
		return $text;
	}

	/**
	 * The text with the parts that have the shape of a credential replaced. Only
	 * shapes that ordinary sentences do not have are looked for, so guidance such
	 * as "password: ask the owner" is left as written.
	 *
	 * @param string $text  Text to mask.
	 * @param int    $count Set to the number of parts replaced.
	 * @return string
	 */
	public static function mask( $text, &$count = 0 ) {
		$count = 0;
		$text  = (string) $text;
		$rules = [
			// A private key block.
			'/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|\z)/s' => self::MASK,
			// user:password@ inside a URL.
			'#(://)[^\s/:@]+:[^\s/@]+@#' => '$1' . self::MASK . '@',
			// Keys with a well-known start.
			'/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{8,}|\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{20,}|\bgithub_pat_[A-Za-z0-9_]{20,}|\bxox[baprs]-[A-Za-z0-9-]{10,}|\bAKIA[0-9A-Z]{16}\b|\bAIza[0-9A-Za-z_\-]{30,}|\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{5,}/' => self::MASK,
			// A WordPress application password: six groups of four.
			'/\b(?:[A-Za-z0-9]{4} ){5}[A-Za-z0-9]{4}\b/' => self::MASK,
			// A long unbroken run of letters and digits (32 or more), as keys and hashes are.
			'/(?<![A-Za-z0-9\/+_=\-])(?=[A-Za-z0-9\/+_=\-]*[A-Za-z])(?=[A-Za-z0-9\/+_=\-]*[0-9])[A-Za-z0-9\/+_=\-]{32,}(?![A-Za-z0-9\/+_=\-])/' => self::MASK,
			// "password is X", "password: X", "pass X": X is one word of 8 or more with a letter and a digit.
			'/\b((?:pass(?:word|wd|phrase)?|pwd)\b(?:\s+is\b|\s*[:=])?\s+)(?=\S*[A-Za-z])(?=\S*[0-9])\S{8,}/i' => '$1' . self::MASK,
		];
		foreach ( $rules as $pattern => $with ) {
			$n      = 0;
			$masked = preg_replace( $pattern, $with, $text, -1, $n );
			if ( is_string( $masked ) ) {
				$text   = $masked;
				$count += (int) $n;
			}
		}
		return $text;
	}

	/** The text as a client receives it. */
	public static function for_client() {
		return self::mask( self::get() );
	}

	/** How many parts of the saved text a client receives masked. */
	public static function masked_parts() {
		$count = 0;
		self::mask( self::get(), $count );
		return $count;
	}

	/** The instructions a client is given when it connects, or '' when the owner wrote nothing. */
	public static function instructions() {
		$text = self::for_client();
		return '' === $text ? '' : "Context from the owner of this WordPress site. Follow it when you work on the site.\n\n" . $text;
	}

	/** The resource document. */
	public static function resource() {
		$text = self::for_client();
		return [
			'set'     => '' !== $text,
			'context' => $text,
			'note'    => '' !== $text ? 'Written by the site owner for AI clients working on this site.' : 'The site owner has not written any context yet.',
		];
	}
}
