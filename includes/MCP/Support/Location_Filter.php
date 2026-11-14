<?php
namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Free text from another plugin (a label, an error message) with every word
 * that could be a host, an address, a path, a link, a file name, an account
 * or a secret replaced by [hidden].
 */
class Location_Filter {

	/** Words after which the next word names something private (an account, a host, a bucket, a folder). */
	const NAMES_NEXT = [ 'user', 'username', 'account', 'bucket', 'host', 'hostname', 'server', 'database', 'db', 'password', 'pass', 'key', 'token', 'folder', 'share', 'login' ];

	/** Words that may sit between one of those and the name. */
	const FILLER = [ 'is', 'was', 'as', 'for', 'to', 'the', 'a', 'an', 'named', 'called', 'not', 'failed', 'refused', 'rejected', 'denied', 'missing', 'unreachable', 'found', 'error', 'on', 'at', 'in', 'of', 'with', 'has', 'no' ];

	/**
	 * A word is replaced when it has a dot, colon, slash, backslash, at sign or
	 * equals sign inside it (plain numbers such as 2.5, 3/7, 10:30 or 1.2.3 and class
	 * names such as Zip::open() are left), when it mixes letters and digits and
	 * is six or more characters long, or when it follows a word such as user,
	 * bucket, host or folder.
	 *
	 * @param mixed $text Text to filter.
	 * @param int   $max  Longest result, in characters.
	 * @return string|null The filtered text, or null when nothing but hidden words is left.
	 */
	public static function clean( $text, $max = 120 ) {
		if ( ! is_scalar( $text ) || is_bool( $text ) ) {
			return null;
		}
		$max   = max( 12, (int) $max );
		$text  = mb_substr( sanitize_text_field( (string) $text ), 0, 4 * $max );
		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) ) {
			return null;
		}
		$kept      = [];
		$name_next = false;
		foreach ( $words as $word ) {
			$core  = trim( $word, ".,;:!?()[]{}\"'" );
			$lower = strtolower( $core );
			$plain = '' === $core
				|| 1 === preg_match( '#^v?\d+(?:\.\d+)?(?:[/:]\d+(?:\.\d+)?)?%?$#i', $core )
				|| 1 === preg_match( '#^v?\d+\.\d+\.\d+$#i', $core )
				|| 1 === preg_match( '#^[A-Za-z_][A-Za-z0-9_]*(?:::[A-Za-z_][A-Za-z0-9_]*)+(?:\(\))?$#', $core )
				|| 1 === preg_match( '#^[A-Za-z]{1,12}/[A-Za-z]{1,12}$#', $core );
			$hide  = ! $plain && (
				1 === preg_match( '#[.:/\\\\@=]#u', $core )
				|| ( strlen( $core ) >= 6 && 1 === preg_match( '/[A-Za-z]/', $core ) && 1 === preg_match( '/\d/', $core ) && 1 === preg_match( '/^[A-Za-z0-9+\/_\-]+$/', $core ) )
			);
			if ( $name_next && '' !== $core && ! in_array( $lower, self::FILLER, true ) ) {
				// Whatever follows "user", "bucket", "host" ... is the name itself, unless it is a plain number.
				$hide      = $hide || 1 !== preg_match( '/^\d+%?$/', $core );
				$name_next = false;
			}
			if ( in_array( $lower, self::NAMES_NEXT, true ) ) {
				$name_next = true;
			}
			$kept[] = $hide ? '[hidden]' : $word;
		}
		$text = implode( ' ', $kept );
		if ( mb_strlen( $text ) > $max ) {
			// Cut between words, so a marker is never left half-written.
			$text = (string) preg_replace( '/\s+\S*$/u', '', mb_substr( $text, 0, $max + 1 ) );
		}
		$text = trim( $text );
		return ( '' === $text || '' === trim( str_replace( '[hidden]', '', $text ) ) ) ? null : $text;
	}
}
