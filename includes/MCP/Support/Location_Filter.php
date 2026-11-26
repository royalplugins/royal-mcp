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
	const NAMES_NEXT = [
		'user', 'username', 'account', 'bucket', 'container', 'host', 'hostname', 'server', 'remote', 'endpoint',
		'database', 'db', 'password', 'pass', 'passphrase', 'secret', 'key', 'token', 'folder', 'share', 'login',
	];

	/** Words that may sit between one of those and the name. */
	const FILLER = [ 'is', 'was', 'as', 'for', 'to', 'the', 'a', 'an', 'named', 'called', 'not', 'failed', 'refused', 'rejected', 'denied', 'missing', 'unreachable', 'found', 'error', 'on', 'at', 'in', 'of', 'with', 'has', 'no' ];

	/** Ordinary words that may close a clause after "for" / "as" without naming an account. */
	const COMMON = [
		'user', 'users', 'name', 'names', 'account', 'accounts', 'does', 'do', 'did', 'exists', 'exist', 'already', 'unknown',
		'invalid', 'incorrect', 'wrong', 'required', 'locked', 'disabled', 'enabled', 'expired', 'permission', 'permissions',
		'access', 'and', 'or', 'but', 'could', 'cannot', 'can', 'must', 'should', 'will', 'may', 'session', 'sessions', 'profile',
		'settings', 'list', 'limit', 'quota', 'id', 'data', 'details', 'credentials', 'blocked', 'created', 'deleted', 'removed',
		'updated', 'changed', 'policy', 'storage', 'space', 'full', 'empty', 'size', 'region', 'request', 'requests', 'object',
		'objects', 'file', 'files', 'type', 'role', 'roles', 'owner', 'group', 'mode', 'level', 'status', 'lookup', 'verification',
		'authentication', 'authorization', 'configuration', 'reset', 'change', 'too', 'short', 'long', 'weak', 'mismatch',
		'this', 'that', 'these', 'those', 'it', 'its', 'your', 'our', 'their', 'here', 'there', 'now', 'again', 'later',
		'unavailable', 'inaccessible', 'timeout', 'timed', 'out', 'closed', 'lost', 'connection', 'please', 'try', 'check',
	];

	/** Words that make "for <word>" or "as <word>" at the end of a clause name an account ("Access denied for jdoe."). */
	const AUTH = [ 'failed', 'denied', 'refused', 'rejected', 'logged', 'signed', 'authenticated', 'authentication', 'unauthorized', 'forbidden', 'login', 'logon' ];

	/** Words after which a bare 8- to 10-digit number may be an IPv4 address written as one integer. */
	const ADDRESS_CONTEXT = [ 'ip', 'address', 'addr', 'host', 'hostname', 'server', 'peer', 'client', 'remote', 'from', 'connect', 'connecting', 'connected' ];

	/** Parts of a hyphenated name that still describe a job rather than name a bucket ("full-backup"). */
	const BACKUP_WORDS = [ 'full', 'daily', 'weekly', 'monthly', 'nightly', 'hourly', 'incremental', 'differential', 'auto', 'manual', 'pre', 'post', 'off', 'offsite', 'on', 'site', 'files', 'db', 'database', 'cloud', 'local' ];

	/** Archive and dump endings, also when written without a name in front (".tar.xz"). */
	const ARCHIVE = '/\.(?:zip|tar|gz|tgz|xz|txz|bz2|tbz2?|zst|zstd|lz4|lzma|7z|rar|jpa|jps|wpress|daf|sql|bak|dump|log|php)$/i';

	/**
	 * A word is replaced when it has a dot, colon, slash, backslash, at sign or
	 * equals sign inside it (plain numbers such as 2.5, 3/7, 10:30 or 1.2.3 and class
	 * names such as Zip::open() are left), when it is an IP address in any spelling,
	 * when it ends like an archive, when it mixes letters and digits and is six or
	 * more characters long, when it is a number of nine or more digits, when it is a
	 * hyphenated bucket-style name ("acme-backups"), when it follows a word such as
	 * user, bucket, host or folder, or when it is the one word after "for" / "as"
	 * that closes a failed sign-in clause.
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
		$auth      = false;
		$prev      = '';
		$last      = count( $words ) - 1;
		foreach ( $words as $i => $word ) {
			$core   = trim( $word, ".,;:!?()[]{}\"'<>" );
			$lower  = strtolower( $core );
			$bare   = trim( $word, ",;!?()[]{}\"'<>" );
			$is_ip  = false !== filter_var( $bare, FILTER_VALIDATE_IP ) || false !== filter_var( rtrim( $bare, '.' ), FILTER_VALIDATE_IP );
			$number = 1 === preg_match( '/^\d{1,8}%?$/', $core );
			$plain  = ! $is_ip && (
				'' === $core
				|| 1 === preg_match( '#^v?\d+(?:\.\d+)?(?:[/:]\d+(?:\.\d+)?)?%?$#i', $core )
				|| 1 === preg_match( '#^v?\d+\.\d+\.\d+$#i', $core )
				|| 1 === preg_match( '#^[A-Za-z_][A-Za-z0-9_]*(?:::[A-Za-z_][A-Za-z0-9_]*)+(?:\(\))?$#', $core )
				|| 1 === preg_match( '#^[A-Za-z]{1,12}/[A-Za-z]{1,12}$#', $core )
			);
			$hide   = $is_ip || 1 === preg_match( self::ARCHIVE, $bare ) || ( ! $plain && (
				1 === preg_match( '#[.:/\\\\@=]#u', $core )
				|| ( strlen( $core ) >= 6 && 1 === preg_match( '/[A-Za-z]/', $core ) && 1 === preg_match( '/\d/', $core ) && 1 === preg_match( '/^[A-Za-z0-9+\/_\-]+$/', $core ) )
			) );
			// A long run of digits is an account, order or object id.
			if ( 1 === preg_match( '/^\d{9,}$/', $core ) ) {
				$hide = true;
			}
			// An IPv4 address written as one integer, right after a word that announces an address.
			if ( 1 === preg_match( '/^\d{8,10}$/', $core ) && in_array( $prev, self::ADDRESS_CONTEXT, true ) && (float) $core >= 16777216 && (float) $core <= 4294967295 ) {
				$hide = true;
			}
			// A hyphenated name with "backup" or "bucket" in it names a storage bucket.
			if ( 1 === preg_match( '/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)+$/', $core ) ) {
				$parts  = explode( '-', $lower );
				$marker = preg_grep( '/^(?:backups?|buckets?)$/', $parts );
				if ( $marker && array_diff( $parts, $marker, self::BACKUP_WORDS ) ) {
					$hide = true;
				}
			}
			if ( $name_next && '' !== $core && ! in_array( $lower, self::FILLER, true ) ) {
				// Whatever follows "user", "bucket", "host" ... is the name itself, unless it is a plain number.
				$hide      = $hide || ! $number;
				$name_next = false;
			}
			// "... failed for jdoe." / "logged in as jdoe": the one word closing the clause after for / as.
			if ( $auth && ( 'for' === $prev || 'as' === $prev ) && '' !== $core && ! $number
				&& ! in_array( $lower, self::COMMON, true ) && ! in_array( $lower, self::FILLER, true )
				&& ( $i === $last || 1 === preg_match( '/[,.;:!?)\]]$/', $word ) ) ) {
				$hide = true;
			}
			if ( in_array( $lower, self::NAMES_NEXT, true ) ) {
				$name_next = true;
			}
			if ( in_array( $lower, self::AUTH, true ) ) {
				$auth = true;
			}
			$prev   = $lower;
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
