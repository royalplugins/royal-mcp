<?php
namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds credentials in values on their way to an AI client and replaces them
 * with [REDACTED]. A value is checked by the name it is stored under and by its
 * own shape, and strings are opened before they are checked: JSON, serialized
 * PHP (any depth), base64 holding either of those or key=value text, URLs
 * (userinfo, query values, webhook paths) and free text (key=value pairs,
 * dumps, headers, markup, provider key formats, private key blocks).
 *
 * Modes: "strict" for settings, meta and logs; "content" for post content and
 * other prose, where only high-confidence patterns apply so ordinary writing
 * about passwords or tokens is left alone.
 *
 * Opened values are rewritten only when something inside was replaced;
 * otherwise the original string comes back byte for byte.
 */
final class Secret_Scrubber {

	const MARKER = '[REDACTED]';

	/** Strings longer than this are scanned as text but not decoded. */
	const MAX_DECODE_BYTES = 8388608;

	/** Nesting deeper than this is withheld rather than walked. */
	const MAX_DEPTH = 64;

	/** Values visited per call; a reply larger than this cannot be checked (see scrub()). */
	const MAX_NODES = 2000000;

	/** @var int The node limit in force (MAX_NODES; lowered only by tests). */
	private static $max_nodes = self::MAX_NODES;

	/** @var int Nodes visited in the current top-level call. */
	private static $nodes = 0;

	/** @var int Re-entry depth of scrub(), so the node budget resets per top-level call. */
	private static $calls = 0;

	/** @var int Replacements made so far; compared before and after opening a value. */
	private static $changes = 0;

	/**
	 * Return $value with credentials replaced by [REDACTED].
	 *
	 * @param mixed  $value    Value to check.
	 * @param string $key_hint Name the value is stored under (option name, meta key, array key).
	 * @param array  $opts     mode: 'strict' (default) or 'content'; exempt_keys: names never
	 *                         redacted by name (their values are still checked by shape);
	 *                         content_keys: names whose string values are prose, checked in content mode;
	 *                         skip_keys: names whose values are returned untouched (the caller's own fields).
	 * @return mixed
	 * @throws \OverflowException When the value is too large to check (more than MAX_NODES values).
	 */
	public static function scrub( $value, $key_hint = '', array $opts = [] ) {
		if ( 0 === self::$calls ) {
			self::$nodes = 0;
		}
		self::$calls++;
		try {
			$opts += [ 'mode' => 'strict', 'exempt_keys' => [], 'content_keys' => [], 'skip_keys' => [] ];
			return self::walk( $value, (string) $key_hint, $opts, 0 );
		} finally {
			self::$calls--;
		}
	}

	/**
	 * Whether a write input carries the marker anywhere (also URL-encoded), so
	 * writing it could store the marker in place of the real value it stands for.
	 */
	public static function contains_marker( $value, $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			return false;
		}
		if ( is_string( $value ) ) {
			return false !== strpos( $value, self::MARKER ) || false !== stripos( $value, '%5BREDACTED%5D' );
		}
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				if ( self::MARKER === $k || self::contains_marker( $v, $depth + 1 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	// ------------------------------------------------------------------
	// Names
	// ------------------------------------------------------------------

	/** Lower-case name, object-property marker removed, camelCase split: "\0*\0smtpUser Name" -> "smtp_user_name". */
	public static function normalize_name( $key ) {
		$key = preg_replace( '/^\x00.*\x00/s', '', (string) $key );
		$key = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key );
		return strtolower( preg_replace( '/\s+/', '_', trim( $key ) ) );
	}

	/**
	 * Whether a key name says its value is a credential. Deliberately broad:
	 * a false positive hides a value the caller can still fetch elsewhere, a
	 * false negative sends a secret to the AI client.
	 */
	public static function is_secret_name( $key ) {
		if ( ! is_string( $key ) || '' === $key || strlen( $key ) > 256 ) {
			return false;
		}
		$key_lc = self::normalize_name( $key );
		$flat   = str_replace( [ '_', '-', '.', ' ' ], '', $key_lc );

		// Names of a storage slot, not of a credential: meta_key => "_thumbnail_id".
		if ( preg_match( '/^(?:meta|option|field|cache|setting|settings|transient|object|primary|foreign|sort|group|row|item|lookup|map|menu|translation|string|text|label|query|param|column|array|sidebar|widget|block|term|i18n|attribute|variation|idempotency|cart_item|order_item)_key$/', $key_lc ) ) {
			return false;
		}

		// Names that describe a credential field rather than hold one:
		// password_label, show_lost_password, token_name, max_tokens, nonce_life.
		$parts = preg_split( '/[_\-.\s]+/', $key_lc );
		$parts = is_array( $parts ) ? array_values( array_filter( $parts, static function ( $v ) { return '' !== $v; } ) ) : [];
		if ( count( $parts ) > 1 ) {
			static $describe_first = [ 'show', 'hide', 'enable', 'disable', 'has', 'is', 'use', 'allow', 'require', 'force', 'min', 'max', 'num', 'lost', 'reset', 'forgot', 'remember' ];
			static $describe_last  = [
				'name', 'label', 'title', 'placeholder', 'type', 'count', 'length', 'status', 'enabled', 'required', 'field', 'text',
				'description', 'desc', 'hint', 'help', 'message', 'msg', 'style', 'color', 'size', 'position', 'format', 'rule', 'rules',
				'policy', 'expiry', 'expires', 'lifetime', 'ttl', 'life', 'strength', 'changed', 'page', 'rate', 'uri', 'link', 'icon',
				'class', 'mode', 'tab', 'version', 'prefix', 'suffix', 'template', 'display', 'visible', 'visibility', 'sent', 'at',
				'date', 'time', 'error', 'errors', 'notice', 'tokens',
			];
			if ( in_array( $parts[0], $describe_first, true ) || in_array( end( $parts ), $describe_last, true ) ) {
				return false;
			}
		}
		if ( 'tokens' === $key_lc ) {
			return false;
		}

		$needles = [
			'password', 'passwd', 'secret', 'salt', 'token', 'nonce',
			'apikey', 'api_key', 'accesskey', 'access_key',
			'private_key', 'public_key',
			'client_secret', 'client_id', 'auth_key', 'auth_token',
			'bearer', 'license_key', 'licence_key', 'consumer_secret', 'consumer_key',
			'webhook_secret', 'session_key', 'credential', 'passcode', 'authorization',
			'webhook_url', 'hook_url', 'license_code', 'licence_code', 'license_data', 'licence_data',
			'activation_code', 'activation_key', 'registration_code', 'registration_key', 'purchase_key',
			'serial_key', 'serial_number', 'session_id', 'auth_cookie', 'service_account',
			'recovery_code', 'backup_code', 'app_password', 'application_password',
			'access_code', 'pin_code', 'security_code', 'verification_code', 'unlock_code',
			'conn_str', 'sas_url', 'signed_url', 'presigned_url',
			'card_number', 'cardnumber', 'cc_number', 'ccnum', 'card_cvv', 'card_cvc', 'card_code',
		];
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $key_lc, $needle ) || false !== strpos( $flat, str_replace( '_', '', $needle ) ) ) {
				return true;
			}
		}

		// One-word credential names (privatekey, transactionkey, smtppass) and
		// gateway login names that are half of a key pair. "keyword" and
		// "monkey" carry no such prefix.
		if ( preg_match( '/(private|secret|security|encryption|transaction|signing|merchant|master|shared|account|secure|consumer|webhook|storage|access|auth|app|license|licence|serial|activation)key(?!word)/', $flat ) ) {
			return true;
		}
		if ( preg_match( '/(smtp|mail|db|ftp|sftp|ssh|proxy|user|admin)(pass|pwd)/', $flat ) ) {
			return true;
		}
		foreach ( [
			'signature', 'secretword', 'passphrase', 'apiuser', 'apilogin', 'loginname',
			'purchasecode', 'authcode', 'recoverycodes', 'backupcodes', 'otpseed', 'totpseed',
			'connectionstring', 'databaseurl', 'mnemonic', 'seedphrase', 'privkey', 'keyfile',
		] as $needle ) {
			if ( false !== strpos( $flat, $needle ) ) {
				return true;
			}
		}

		$segments = preg_split( '/[_\-.\s]+/', $key_lc );
		$segments = is_array( $segments ) ? array_values( array_filter( $segments, static function ( $v ) { return '' !== $v; } ) ) : [];

		if ( self::is_licence_value_name( $segments ) ) {
			return true;
		}

		// Whole names, and last name segments, that hold a credential on their own.
		static $whole = [
			'access', 'refresh', 'auth', 'oauth', 'api', 'pat', 'sid', 'pin', 'otp', 'totp', 'hotp', 'mfa', 'hmac', 'jwt',
			'cookie', 'session', 'seed', 'serial', 'webhook', 'webhooks', 'dsn', 'pem', 'cert', 'passkey', 'wallet', 'creds', 'cvv', 'cvc', 'cvv2',
		];
		if ( in_array( $key_lc, $whole, true ) || in_array( $flat, $whole, true ) ) {
			return true;
		}
		static $last = [ 'serial', 'webhook', 'webhooks', 'cookie', 'jwt', 'hmac', 'otp', 'totp', 'seed', 'dsn', 'pem', 'sid', 'oauth', 'mfa', 'passkey', 'creds' ];
		if ( count( $segments ) > 1 && in_array( end( $segments ), $last, true ) ) {
			return true;
		}

		// Generic suffixes at a boundary: cdn-cloudflare_key, mailserver_pass,
		// third-party.conf.secret. Boundaries keep donkey and keyword readable.
		if ( preg_match( '/(^|[_\-.])(pass|pwd|pw|secret|token|dsn)([_\-.]|$)/i', $key_lc ) ) {
			return true;
		}
		// "key" as the whole name or the last part (cloudflare_key); key_points and key_name are not keys.
		if ( preg_match( '/(^|[_\-.])key$/i', $key_lc ) ) {
			return true;
		}

		// Hashes only when the root is a credential: api_key_hash yes, content_hash no.
		if ( preg_match( '/(key|pass|pwd|secret|token|auth|salt)_?hash/i', $key_lc ) ) {
			return true;
		}
		return false;
	}

	/**
	 * A licence segment that names the licence itself (license, edd_license,
	 * license_key, nss_plugin_license_sfwd_lms), not a field stored beside it
	 * (license_status, license_server_version, licensed_to, license_activated_at).
	 *
	 * @param string[] $segments Name split on _ - . and spaces.
	 */
	private static function is_licence_value_name( array $segments ) {
		static $value_words = [ 'key', 'keys', 'code', 'codes', 'data', 'number', 'string', 'hash', 'token', 'secret', 'id' ];
		static $field_words = [
			'status', 'state', 'type', 'tier', 'plan', 'level', 'expires', 'expiry', 'expiration', 'expired', 'valid', 'validity',
			'active', 'activated', 'activation', 'deactivated', 'url', 'page', 'name', 'count', 'limit', 'seats', 'sites',
			'date', 'version', 'server', 'message', 'error', 'notice', 'notices', 'owner', 'email', 'customer', 'product',
			'renewal', 'renews', 'check', 'checked', 'last', 'cache', 'info', 'details', 'next', 'seen', 'at', 'label', 'text',
			'link', 'title', 'term', 'terms', 'agreement', 'accepted', 'manager', 'screen', 'menu', 'tab', 'nag', 'banner', 'is', 'has',
			'deactivate', 'activate', 'fail', 'on', 'auto', 'enable', 'enabled', 'disable', 'disabled', 'notify', 'notification', 'required', 'mode',
		];
		foreach ( $segments as $i => $seg ) {
			if ( ! preg_match( '/^licen[cs]es?$/', $seg ) ) {
				continue;
			}
			$before = array_slice( $segments, 0, $i );
			if ( in_array( 'is', $before, true ) || in_array( 'has', $before, true ) ) {
				return false;
			}
			$after = array_slice( $segments, $i + 1 );
			if ( [] === $after || in_array( $after[0], $value_words, true ) ) {
				return true;
			}
			foreach ( $after as $word ) {
				if ( in_array( $word, $field_words, true ) ) {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	/** Short names that are credentials only when their value is not a plain word ("access" => "public" stays). */
	private static function is_weak_name( $key ) {
		static $weak = [ 'access', 'refresh', 'auth', 'oauth', 'api', 'pat', 'sid', 'pin', 'session', 'seed', 'serial', 'cert', 'pem', 'cookie', 'webhook', 'dsn', 'mfa', 'otp', 'totp', 'hotp', 'jwt', 'hmac' ];
		return in_array( self::normalize_name( trim( (string) $key, " \t\"'$" ) ), $weak, true );
	}

	/** A setting word, not a credential: "public", "read-only", "Full access". Credential schemes never count. */
	private static function is_plain_word( $value ) {
		return is_string( $value ) && strlen( $value ) <= 40
			&& ! preg_match( '/^(?:bearer|token|basic|digest|bot|apikey)\b/i', $value )
			&& (bool) preg_match( '/^(?:[A-Za-z][a-z]*(?:[ _\-][A-Za-z][a-z]*){0,3}|v\d{1,3}(?:\.\d{1,3}){0,2})$/', $value );
	}

	/** Names of the licence itself or a short credential name, whose block of other fields is checked field by field. */
	private static function opens_blocks( $key ) {
		return self::is_weak_name( $key ) || (bool) preg_match( '/licen[cs]e/', self::normalize_name( $key ) );
	}

	/** Names under which a number is a count, time or id rather than a secret. */
	private static function is_numeric_field_name( $key ) {
		return (bool) preg_match( '/(^|_)(count|counts|total|ttl|expires?|expiry|exp|at|time|timestamp|date|id|ids|num|number_of|length|size|limit|max|min|version|port|status|level|interval|seconds|minutes|hours|days|age|attempts|tries|lifetime|timeout|retries|length|tokens)$/', self::normalize_name( $key ) );
	}

	/** Keys that name an identifier (hashes, slugs, hooks), whose values are not judged by shape. */
	private static function is_identifier_name( $key ) {
		return '' !== $key && (bool) preg_match( '/(^|_)(hash|sha1|sha256|md5|checksum|etag|slug|hook|uuid|guid|handle|template|class|action|filter|post_name|file_name|filename)$/', self::normalize_name( $key ) );
	}

	// ------------------------------------------------------------------
	// Values
	// ------------------------------------------------------------------

	/** Provider key formats, matched as a whole value or inside text. */
	private static function provider_pattern() {
		return '(?:'
			. '(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{8,}'
			. '|sk-(?:ant|proj|svcacct|admin)-[A-Za-z0-9_\-]{20,}'
			. '|whsec_[A-Za-z0-9]{8,}'
			. '|SG\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}'
			. '|xox[abposr]-[A-Za-z0-9\-]{8,}'
			. '|gh[pousr]_[A-Za-z0-9]{10,}'
			. '|github_pat_[A-Za-z0-9_]{20,}'
			. '|glpat-[A-Za-z0-9_\-]{16,}'
			. '|AKIA[0-9A-Z]{16}'
			. '|AIza[0-9A-Za-z_\-]{30,}'
			. '|ya29\.[0-9A-Za-z_\-]{20,}'
			. '|key-[0-9a-f]{32}'
			. '|\d{6,12}:AA[0-9A-Za-z_\-]{30,}'
			. '|EAA[A-Za-z0-9]{40,}'
			. '|sq0(?:atp|csp)-[0-9A-Za-z_\-]{20,}'
			. '|shp(?:at|ss|ca|pa)_[a-fA-F0-9]{32}'
			. '|npm_[A-Za-z0-9]{30,}'
			. '|eyJ[A-Za-z0-9_\-]{8,}\.eyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}'
			. ')';
	}

	/**
	 * Words joined by - _ or . (hook names, slugs, file names), not a generated
	 * token: each part is a lower-case or Capitalised word or a short number.
	 * Random tokens mix cases inside a part, so they do not pass.
	 */
	private static function looks_like_words( $value ) {
		$value = (string) $value;
		if ( strlen( $value ) > 96 ) {
			return false;
		}
		$parts = preg_split( '/[\-_.]+/', $value );
		$parts = is_array( $parts ) ? array_values( array_filter( $parts, static function ( $v ) { return '' !== $v; } ) ) : [];
		if ( count( $parts ) < 3 ) {
			return false;
		}
		$words = 0;
		foreach ( $parts as $p ) {
			if ( preg_match( '/^(?:[a-z]{2,24}|[A-Z][a-z]{1,23}|[A-Z]{2,5})$/', $p ) || preg_match( '/^(?:v?\d{1,4}|[a-z]{1,3}\d{1,4})$/', $p ) ) {
				$words++;
			}
		}
		return $words === count( $parts ) || ( count( $parts ) >= 5 && $words / count( $parts ) >= 0.8 );
	}

	/** Whether a string, taken whole, looks like a credential by its shape alone. */
	public static function is_secret_value( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$value = trim( $value );
		$len   = strlen( $value );
		if ( $len < 8 || $len > 16384 ) {
			return false;
		}
		if ( preg_match( '/^' . self::provider_pattern() . '$/', $value ) ) {
			return true;
		}
		if ( preg_match( '/^-----BEGIN [A-Z ]*PRIVATE KEY-----/', $value ) ) {
			return true;
		}
		if ( $len < 32 || $len > 4096 ) {
			return false;
		}
		// Generated tokens of this length almost always mix in digits; names and
		// slugs (long_hook_names, very-long-slug-words-2026) do not look random.
		if ( ! preg_match( '/[0-9]/', $value ) || self::looks_like_words( $value ) ) {
			return false;
		}
		// 32+ characters of token alphabet, or a JWT-like three-part value.
		if ( preg_match( '/^[A-Za-z0-9_\-]{32,}$/', $value ) ) {
			return true;
		}
		return (bool) preg_match( '/^[A-Za-z0-9_\-]{4,}\.[A-Za-z0-9_\-]{4,}\.[A-Za-z0-9_\-]{4,}$/', $value );
	}

	// ------------------------------------------------------------------
	// Walk
	// ------------------------------------------------------------------

	private static function is_falsy_sentinel( $v ) {
		return '' === $v || null === $v || [] === $v || '0' === $v || false === $v;
	}

	private static function name_is_secret( $key, array $opts ) {
		if ( '' === $key || in_array( $key, $opts['exempt_keys'], true ) ) {
			return false;
		}
		return self::is_secret_name( $key );
	}

	/** The marker, counted as a replacement. */
	private static function hidden() {
		self::$changes++;
		return self::MARKER;
	}

	/** What a value under a credential name becomes. */
	private static function value_under_secret_name( $value, $key ) {
		// Empty values mean "not set" and stay visible, as do flags, counts and
		// times under such names, and plain setting words under short names.
		if ( self::is_falsy_sentinel( $value ) || is_bool( $value ) ) {
			return $value;
		}
		if ( ( is_int( $value ) || is_float( $value ) ) && self::is_numeric_field_name( $key ) ) {
			return $value;
		}
		if ( self::is_weak_name( $key ) && self::is_plain_word( $value ) ) {
			return $value;
		}
		$name = self::normalize_name( $key );
		// "key" in rows and field definitions names a slot: a meta key, an ACF field key.
		if ( 'key' === $name && ( is_int( $value ) || ( is_string( $value ) && ! self::is_secret_value( $value )
			&& preg_match( '/^(?:_[A-Za-z0-9_\-]+|(?:field|group|layout|option|meta)_[a-z0-9]+|[a-z][a-z0-9]*(?:_[a-z0-9]+)+)$/', $value ) ) ) ) {
			return $value;
		}
		// A licence as text ("GPLv2 or later", "CC BY 4.0"), not a licence key.
		if ( is_string( $value ) && preg_match( '/(^|_)licen[cs]es?$/', $name )
			&& ( preg_match( '/\s/', trim( $value ) ) || preg_match( '/^(?:GPL|LGPL|AGPL|MIT|BSD|Apache|CC|MPL|ISC|EPL|Unlicense|Proprietary|Public domain)/i', $value ) ) ) {
			return $value;
		}
		return self::hidden();
	}

	private static function walk( $value, $key_hint, array $opts, $depth ) {
		if ( ++self::$nodes > self::$max_nodes ) {
			// Too large to check: refuse the whole value rather than return part of it unchecked or damaged.
			throw new \OverflowException( 'This reply is too large to check for credentials. Ask for fewer items (a smaller limit or per_page, or a narrower range).' );
		}
		if ( $depth > self::MAX_DEPTH ) {
			return self::hidden();
		}
		if ( '' !== $key_hint && in_array( $key_hint, $opts['skip_keys'], true ) ) {
			return $value;
		}
		if ( self::name_is_secret( $key_hint, $opts ) ) {
			$opens = self::opens_blocks( $key_hint );
			if ( $opens && ( is_array( $value ) || is_object( $value ) ) ) {
				// "license" => { total, remaining, expires }: each field is checked on its own.
				return self::walk( $value, '', $opts, $depth );
			}
			if ( $opens && is_string( $value ) && strlen( $value ) >= 40 && preg_match( '/^[^\s]+(?:\s+[^\s]+){5,}$/', $value ) && ! self::is_secret_value( $value ) ) {
				// Licence terms and other sentences, not a key.
				return self::scrub_string( $value, $opts, $depth );
			}
			return self::value_under_secret_name( $value, $key_hint );
		}
		if ( $value instanceof \stdClass ) {
			$out = new \stdClass();
			foreach ( self::walk_array( (array) $value, $key_hint, $opts, $depth ) as $k => $v ) {
				$out->{$k} = $v;
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( is_string( $value ) ) {
			if ( self::is_identifier_name( $key_hint ) ) {
				// Hashes, slugs and hook names: opened and scanned, not judged by their own shape.
				return self::scrub_string( $value, $opts, $depth, false );
			}
			if ( '' !== $key_hint && 'content' !== $opts['mode'] && in_array( $key_hint, $opts['content_keys'], true ) ) {
				// Prose fields (post content, titles) get the content-mode checks only.
				return self::scrub_string( $value, [ 'mode' => 'content' ] + $opts, $depth );
			}
			return self::scrub_string( $value, $opts, $depth );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		return self::walk_array( $value, $key_hint, $opts, $depth );
	}

	private static function walk_array( array $value, $key_hint, array $opts, $depth ) {
		// Attachment EXIF: location and device fields are dropped outright.
		if ( 'image_meta' === $key_hint ) {
			foreach ( [ 'latitude', 'longitude', 'camera', 'aperture', 'iso', 'shutter_speed', 'focal_length', 'orientation', 'created_timestamp' ] as $exif ) {
				foreach ( array_keys( $value ) as $k ) {
					if ( strtolower( (string) $k ) === $exif ) {
						unset( $value[ $k ] );
						self::$changes++;
					}
				}
			}
		}

		$pair_prefixes = self::pair_secret_prefixes( array_keys( $value ), true );
		// A login name beside a block that holds the secret: { username, auth: { password } }.
		if ( ! isset( $pair_prefixes[''] ) ) {
			foreach ( $value as $ck => $child ) {
				if ( is_array( $child ) && is_string( $ck ) && preg_match( '/^(auth|authentication|credentials?|creds|login|secrets?|oauth|basic_auth|http_auth)$/', self::normalize_name( $ck ) )
					&& isset( self::pair_secret_prefixes( array_keys( $child ), true )[''] ) ) {
					$pair_prefixes[''] = true;
					break;
				}
			}
		}

		// Name/value rows: [ { "name": "api_key", "value": "..." } ].
		$row_secret = false;
		foreach ( [ 'name', 'key', 'field', 'param', 'header', 'option' ] as $nk ) {
			if ( isset( $value[ $nk ] ) && is_string( $value[ $nk ] ) && self::is_secret_name( $value[ $nk ] ) ) {
				$row_secret = $value[ $nk ];
				break;
			}
		}

		$out = [];
		foreach ( $value as $k => $v ) {
			if ( false !== $row_secret && is_string( $k ) && in_array( $k, [ 'value', 'val', 'data', 'content', 'secret', 'default' ], true ) ) {
				$out[ $k ] = self::value_under_secret_name( $v, $row_secret );
				continue;
			}
			if ( [] !== $pair_prefixes && is_string( $k ) && self::is_credential_partner( $k, $pair_prefixes )
				&& ! in_array( $k, $opts['exempt_keys'], true ) && ! self::is_falsy_sentinel( $v ) ) {
				$out[ $k ] = self::hidden();
				continue;
			}
			$out[ $k ] = self::walk( $v, is_int( $k ) ? '' : (string) $k, $opts, $depth + 1 );
		}
		return $out;
	}

	/** Open a string (JSON, serialized, base64) or scan it as text. */
	private static function scrub_string( $s, array $opts, $depth, $shape = true ) {
		if ( '' === $s ) {
			return $s;
		}
		// Base64 of readable text is judged by what it says, before its shape.
		if ( $shape && strlen( $s ) >= 16 && strlen( $s ) <= self::MAX_DECODE_BYTES && preg_match( '/^[A-Za-z0-9+\/]+={0,2}$/', $s ) ) {
			$opened = self::open_base64( $s, $opts, $depth );
			if ( false === $opened ) {
				return $s;
			}
			if ( true === $opened ) {
				return self::hidden();
			}
		}
		if ( $shape && self::is_secret_value( $s ) ) {
			return self::hidden();
		}
		if ( strlen( $s ) <= self::MAX_DECODE_BYTES ) {
			$t     = ltrim( $s );
			$first = '' === $t ? '' : $t[0];

			if ( '{' === $first || '[' === $first ) {
				$decoded = json_decode( $s, false, 512, JSON_BIGINT_AS_STRING );
				if ( ( is_array( $decoded ) || is_object( $decoded ) ) && JSON_ERROR_NONE === json_last_error() ) {
					$before = self::$changes;
					$walked = self::walk( $decoded, '', $opts, $depth + 1 );
					if ( self::$changes === $before ) {
						return $s;
					}
					$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
					if ( false !== strpos( $s, "\n" ) ) {
						$flags |= JSON_PRETTY_PRINT;
					}
					$json = wp_json_encode( $walked, $flags );
					return false === $json ? self::hidden() : $json;
				}
			}

			if ( self::looks_serialized( $s ) ) {
				$u = @unserialize( $s, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes disabled; read only
				if ( false !== $u || 'b:0;' === $s ) {
					if ( is_object( $u ) && ! $u instanceof \stdClass ) {
						$u = (array) $u;
					}
					$before = self::$changes;
					$walked = self::walk( $u, '', $opts, $depth + 1 );
					if ( self::$changes === $before ) {
						return $s;
					}
					return serialize( $walked ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- returned as text, never stored
				}
			}

			$b64 = preg_replace( '/\s+/', '', $s );
			if ( strlen( $b64 ) >= 16 && preg_match( '/^[A-Za-z0-9+\/]+={0,2}$/', $b64 ) ) {
				$opened = self::open_base64( $b64, $opts, $depth );
				if ( null !== $opened ) {
					return $opened ? self::hidden() : $s;
				}
				// Binary data in base64 (images, archives): not text to scan.
				if ( strlen( $b64 ) === strlen( $s ) ) {
					return $s;
				}
			}
		}
		$before = self::$changes;
		$out    = self::scrub_text( $s, $opts['mode'] );
		if ( $out !== $s && self::$changes === $before ) {
			self::$changes++;
		}
		return $out;
	}

	/**
	 * Decode base64 and check what it holds. True when it holds a credential,
	 * false when it holds text or data with none, null when it is not base64
	 * of text, JSON or serialized data.
	 */
	private static function open_base64( $b64, array $opts, $depth ) {
		$pad = strlen( $b64 ) % 4;
		if ( 1 === $pad ) {
			return null;
		}
		$bin = base64_decode( $pad ? $b64 . str_repeat( '=', 4 - $pad ) : $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading stored values to check them
		if ( false === $bin || '' === $bin ) {
			return null;
		}
		$b = ltrim( $bin );
		$is_json = '' !== $b && ( '{' === $b[0] || '[' === $b[0] ) && null !== json_decode( $bin ) && JSON_ERROR_NONE === json_last_error();
		$is_ser  = self::looks_serialized( $bin ) && ( 'b:0;' === $bin || false !== @unserialize( $bin, [ 'allowed_classes' => false ] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes disabled; read only
		if ( $is_json || $is_ser ) {
			$before = self::$changes;
			self::scrub_string( $bin, $opts, $depth + 1 );
			return self::$changes !== $before;
		}
		// Readable text: "user=admin&pass=...", "admin:secret".
		if ( strlen( $bin ) <= 4096 && preg_match( '/^[\x20-\x7E\t\r\n]+$/', $bin ) ) {
			if ( preg_match( '#^[a-z][a-z0-9+.\-]{1,15}://#i', $bin ) ) {
				return self::scrub_url( $bin ) !== $bin;
			}
			// user:password (the Basic auth shape): a user-name-like part, then a secret-looking part.
			if ( preg_match( '/^[A-Za-z0-9._%+\-@]{1,64}:([^\s:\/]{4,})$/', $bin, $up ) && ! preg_match( '/^\d/', $bin )
				&& ( preg_match( '/\d/', $up[1] ) || strlen( $up[1] ) >= 8 ) && ! preg_match( '/^\d+x\d+|^[a-z]+-[a-z]+$/i', $up[1] ) ) {
				return true;
			}
			if ( preg_match( '/[=:&]/', $bin ) ) {
				return self::scrub_text( $bin, 'strict' ) !== $bin;
			}
			return false;
		}
		return null;
	}

	private static function looks_serialized( $s ) {
		if ( function_exists( 'is_serialized' ) ) {
			return is_serialized( $s );
		}
		return (bool) preg_match( '/^(?:N;|b:[01];|i:-?\d+;|d:[^;]+;|s:\d+:".*";|a:\d+:\{.*\}|O:\d+:"[^"]+":\d+:\{.*\}|E:\d+:".*";)$/s', $s );
	}

	// ------------------------------------------------------------------
	// Text
	// ------------------------------------------------------------------

	/**
	 * preg_replace / preg_replace_callback that retries once without the JIT
	 * when the JIT stack runs out, and returns null only when the pattern
	 * still fails.
	 */
	private static function rx( $pattern, $replacement, $subject ) {
		$out = is_callable( $replacement ) ? preg_replace_callback( $pattern, $replacement, $subject ) : preg_replace( $pattern, $replacement, $subject );
		if ( null === $out && PREG_JIT_STACKLIMIT_ERROR === preg_last_error() ) {
			// The JIT setting applies when a pattern is compiled, and the failed pattern is
			// already cached: an empty group makes it a new pattern, compiled without the JIT.
			$fresh = $pattern[0] . '(?:)' . substr( $pattern, 1 );
			$jit   = ini_get( 'pcre.jit' );
			ini_set( 'pcre.jit', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restored straight after
			$out = is_callable( $replacement ) ? preg_replace_callback( $fresh, $replacement, $subject ) : preg_replace( $fresh, $replacement, $subject );
			ini_set( 'pcre.jit', (string) $jit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring
		}
		return $out;
	}

	/** Whether a pair's value should be hidden: a secret name and a value that is not empty, already hidden or a plain word under a short name. */
	private static function pair_value_is_secret( $name, $value ) {
		$value = (string) $value;
		$name  = trim( (string) $name, " \t\"'$" );
		if ( '' === trim( $value ) || 0 === strpos( ltrim( $value ), '[REDACTED' ) || 0 === strpos( $value, '$matches[' ) ) {
			return false;
		}
		if ( ! self::is_secret_name( $name ) ) {
			return false;
		}
		if ( self::is_weak_name( $name ) && self::is_plain_word( $value ) ) {
			return false;
		}
		if ( preg_match( '/^-?\d+(?:\.\d+)?$/', $value ) && self::is_numeric_field_name( $name ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Replace credentials inside free text (logs, dumps, URLs, markup, prose).
	 *
	 * @param string   $s    Text.
	 * @param string   $mode 'strict' or 'content'.
	 * @param string[] $keep Values that stay as they are wherever they appear (the caller's own tokens).
	 */
	public static function scrub_text( $s, $mode = 'strict', array $keep = [] ) {
		if ( ! is_string( $s ) || '' === $s ) {
			return $s;
		}
		$held = [];
		foreach ( array_values( array_filter( $keep, static function ( $v ) { return is_string( $v ) && strlen( $v ) >= 8; } ) ) as $i => $v ) {
			if ( false !== strpos( $s, $v ) ) {
				$held[ '[REDACTED#k' . $i . ']' ] = $v;
				$s = str_replace( $v, '[REDACTED#k' . $i . ']', $s );
			}
		}
		if ( [] !== $held ) {
			$out = self::scrub_text( $s, $mode );
			return strtr( (string) $out, $held );
		}
		// Nothing that could hold a credential: no separator, URL, dump, markup or key prefix.
		if ( strlen( $s ) < 8 || ! preg_match( '/[:=@\/\["\'<%&]|-----BEGIN|(?:sk|rk)_|whsec_|SG\.|xox|gh[pousr]_|github_pat_|glpat-|AKIA|AIza|ya29\.|key-|EAA|sq0|shp|npm_|eyJ|Basic |[A-Za-z0-9_\-]{40}/', $s ) ) {
			return $s;
		}
		// A whole string of base64 is data, not text with name=value pairs in it.
		if ( preg_match( '/^(?:[A-Za-z0-9+\/]{16,}={0,2}|[A-Za-z0-9+\/]{8,}={1,2})$/', $s ) ) {
			return $s;
		}
		$strict = 'content' !== $mode;
		$m      = self::MARKER;
		$steps  = [];

		// Private key blocks.
		$steps[] = [ '/-----BEGIN ([A-Z ]*)PRIVATE KEY-----.*?-----END \1PRIVATE KEY-----/s', $m ];

		// Credential headers: the credential part only.
		$steps[] = [ '/\b((?:proxy-)?authorization[ \t]*:[ \t]*(?:(?:Bearer|Basic|Token|Digest|Bot|ApiKey)[ \t]+)?)[A-Za-z0-9._~+\/=\-]{8,}/i', '$1' . $m ];
		$steps[] = [ '/\b((?:x-api-key|x-auth-token|api-key|x-access-token)[ \t]*:[ \t]*"?)[^\s"]{8,}/i', '$1' . $m ];
		$steps[] = [ '/\b(Basic[ \t]+)[A-Za-z0-9+\/]{12,}={0,2}(?![A-Za-z0-9+\/=])/', '$1' . $m ];
		if ( $strict ) {
			$steps[] = [
				'/\b((?:set-)?cookie[ \t]*:[ \t]*)([^\r\n]+)/i',
				static function ( $x ) use ( $m ) {
					return $x[1] . preg_replace( '/([A-Za-z0-9_\-.]+[ \t]*=[ \t]*)(?![A-Za-z]{1,12}(?:[;\s]|$))[A-Za-z0-9._\-+\/=%]{8,}/', '$1' . $m, $x[2] );
				},
			];
		}

		// URLs: userinfo, credential query values, webhook paths. Also JSON-escaped (https:\/\/...).
		$steps[] = [
			'#\b[a-z][a-z0-9+.\-]{1,15}://[^\s"\'<>\\\\]+#i',
			static function ( $x ) {
				$url  = $x[0];
				$tail = '';
				if ( preg_match( '/[).,;:!?]+$/', $url, $tm ) ) {
					$tail = $tm[0];
					$url  = substr( $url, 0, -strlen( $tail ) );
				}
				return self::scrub_url( $url ) . $tail;
			},
		];
		$steps[] = [
			'#\b[a-z][a-z0-9+.\-]{1,15}:\\\\/\\\\/(?:[^\s"\'<>\\\\]|\\\\/)+#i',
			static function ( $x ) {
				$plain = str_replace( '\/', '/', $x[0] );
				$clean = self::scrub_url( $plain );
				return $clean === $plain ? $x[0] : str_replace( '/', '\/', $clean );
			},
		];

		// var_dump: ["password"]=> string(6) "value", ["key":protected]=> int(4821)
		$steps[] = [
			'/(\["([^"\r\n]{1,128})"(?::[^\]\r\n]{1,128})?\]=>\s*(?:string\(\d+\)\s*"|int\(|float\())([^"\r\n)]*)([")])/',
			static function ( $x ) use ( $m ) {
				return self::pair_value_is_secret( $x[2], $x[3] ) ? $x[1] . $m . $x[4] : $x[0];
			},
		];

		// Quoted pairs: "name": "value", 'name' => 'value' (JSON, PHP arrays, JS objects).
		foreach ( [ '"', "'" ] as $q ) {
			$steps[] = [
				'/(?<!\[)(["\'])([^"\'\r\n]{1,128})\1([ \t]*(?:=>|:)[ \t]*)' . $q . '([^' . $q . '\\\\\r\n]*+(?:\\\\.[^' . $q . '\\\\\r\n]*+)*+)' . $q . '/',
				static function ( $x ) use ( $m, $q ) {
					return self::pair_value_is_secret( $x[2], stripslashes( $x[4] ) ) ? $x[1] . $x[2] . $x[1] . $x[3] . $q . $m . $q : $x[0];
				},
			];
		}
		// Quoted name, number value: "otp": 482913
		$steps[] = [
			'/(["\'])([^"\'\r\n]{1,128})\1([ \t]*(?:=>|:)[ \t]*)(-?\d+(?:\.\d+)?)(?![\d.])/',
			static function ( $x ) use ( $m ) {
				return self::pair_value_is_secret( $x[2], $x[4] ) ? $x[1] . $x[2] . $x[1] . $x[3] . '"' . $m . '"' : $x[0];
			},
		];
		// HTML-entity and URL-encoded JSON: &quot;api_key&quot;:&quot;...&quot;, %22api_key%22%3A%22...%22
		$steps[] = [
			'/((?:&quot;|&#0?34;)([^&"\r\n<>]{1,128})(?:&quot;|&#0?34;)[ \t]*(?::|&#0?58;)[ \t]*(?:&quot;|&#0?34;))(.{0,4096}?)((?:&quot;|&#0?34;))/',
			static function ( $x ) use ( $m ) {
				return self::pair_value_is_secret( $x[2], html_entity_decode( $x[3], ENT_QUOTES ) ) ? $x[1] . $m . $x[4] : $x[0];
			},
		];
		$steps[] = [
			'/(%22([^%"\r\n]{1,128})%22(?:%3A|:)%22)(.{0,4096}?)(%22)/i',
			static function ( $x ) use ( $m ) {
				return self::pair_value_is_secret( rawurldecode( $x[2] ), rawurldecode( $x[3] ) ) ? $x[1] . rawurlencode( $m ) . $x[4] : $x[0];
			},
		];

		// define( 'NAME', 'value' ), with escaped quotes inside the value.
		$steps[] = [
			'/(define\s*\(\s*(["\'])([A-Za-z0-9_]{1,128})\2\s*,\s*)(["\'])((?:(?!\4)[^\\\\\r\n]|\\\\.)*+)\4/',
			static function ( $x ) use ( $m ) {
				return self::pair_value_is_secret( $x[3], $x[5] ) ? $x[1] . $x[4] . $m . $x[4] : $x[0];
			},
		];

		// Form fields: <input name="api_key" value="..."> in either attribute order.
		$steps[] = [
			'/<input\b[^>]*>/i',
			static function ( $x ) use ( $m ) {
				$tag = $x[0];
				if ( ! preg_match( '/\bname\s*=\s*(["\'])([^"\']{1,128})\1/i', $tag, $nm ) || ! preg_match( '/\bvalue\s*=\s*(["\'])([^"\']*)\1/i', $tag, $vm ) ) {
					return $tag;
				}
				if ( ! self::pair_value_is_secret( $nm[2], $vm[2] ) ) {
					return $tag;
				}
				return str_replace( $vm[0], 'value=' . $vm[1] . $m . $vm[1], $tag );
			},
		];

		if ( $strict ) {
			// Unquoted name, quoted value: password = "x", API_KEY='x', apiKey: 'x', $pass = "x".
			$steps[] = [
				'/(?<![\w\-.$\'"])(\$?[A-Za-z_][\w\-.]{0,63})([ \t]*(?:=>|=|:)[ \t]*)(["\'])((?:(?!\3)[^\\\\\r\n]|\\\\.)*+)\3/',
				static function ( $x ) use ( $m ) {
					return self::pair_value_is_secret( $x[1], $x[4] ) ? $x[1] . $x[2] . $x[3] . $m . $x[3] : $x[0];
				},
			];
			// $cfg["password"] = "x";
			$steps[] = [
				'/(\$\w+\[\s*(["\'])([^"\'\r\n]{1,64})\2\s*\]\s*=\s*)(["\'])((?:(?!\4)[^\\\\\r\n]|\\\\.)*+)\4/',
				static function ( $x ) use ( $m ) {
					return self::pair_value_is_secret( $x[3], $x[5] ) ? $x[1] . $x[4] . $m . $x[4] : $x[0];
				},
			];
			// XML elements: <password>x</password>
			$steps[] = [
				'/<([A-Za-z_][\w\-.:]{0,63})>([^<\r\n]{1,4096})<\/\1>/',
				static function ( $x ) use ( $m ) {
					return self::pair_value_is_secret( $x[1], $x[2] ) ? '<' . $x[1] . '>' . $m . '</' . $x[1] . '>' : $x[0];
				},
			];
			// print_r: [name] => value, [name:protected] => value
			$steps[] = [
				'/(\[([^\]\r\n:]{1,128})(?::[^\]\r\n]{1,128})?\][ \t]*=>[ \t]*)(?!Array\b|[A-Za-z_\\\\]+ Object\b)([^\r\n]*?)(?=[ \t]*\[[^\]\r\n]{1,128}\][ \t]*=>|[ \t]*\)|\r|\n|$)/',
				static function ( $x ) use ( $m ) {
					return self::pair_value_is_secret( trim( $x[2], "'\" " ), trim( $x[3] ) ) ? $x[1] . $m : $x[0];
				},
			];
			// Unquoted name=value / name: value on one line. The value may not itself
			// start another name: value pair ("Error: password: x" checks password).
			$steps[] = [
				'/(?<![A-Za-z0-9_\-.])([A-Za-z][A-Za-z0-9_\-.]{1,63})([ \t]*[:=][ \t]*)(?!\[REDACTED)(?![A-Za-z][\w\-.]{0,63}[ \t]*[:=])(?!\/\/)(?!=)([^\s"\'&;,<>)\]\\\\]{1,4096})/',
				static function ( $x ) use ( $m ) {
					return self::pair_value_is_secret( $x[1], $x[3] ) ? $x[1] . $x[2] . $m : $x[0];
				},
			];
			// curl -u user:password / --user user:password
			$steps[] = [ '/(\s(?:-u|--user)\s+(["\']?)[^\s:"\']+:)[^\s"\']+/', '$1' . $m ];
			// new PDO( dsn, user, 'password' )
			$steps[] = [ '/(new\s+PDO\s*\([^,]+,\s*(["\'])[^"\']*\2\s*,\s*)(["\'])[^"\']*\3/i', '$1$3' . $m . '$3' ];
		}

		if ( ! $strict ) {
			// In prose a name=value pair is hidden only when the value looks generated:
			// "use api_key=YOUR_KEY" in a how-to stays, "api_key=Qz9x41..." does not.
			$steps[] = [
				'/(?<![A-Za-z0-9_\-.])([A-Za-z][A-Za-z0-9_\-.]{1,63})([ \t]*[:=][ \t]*)(?!\[REDACTED)(?![A-Za-z][\w\-.]{0,63}[ \t]*[:=])(?!\/\/)(?!=)([^\s"\'&;,<>)\]\\\\]{8,4096})/',
				static function ( $x ) use ( $m ) {
					$v         = $x[3];
					$generated = self::is_secret_value( $v ) || ( preg_match( '/\d/', $v ) && preg_match( '/[A-Za-z]/', $v ) && ! self::looks_like_words( $v ) );
					return $generated && self::pair_value_is_secret( $x[1], $v ) ? $x[1] . $x[2] . $m : $x[0];
				},
			];
		}

		// Provider key formats anywhere.
		$steps[] = [ '/(?<![A-Za-z0-9_\-])' . self::provider_pattern() . '(?![A-Za-z0-9_\-])/', $m ];

		if ( $strict ) {
			// Long token-alphabet runs left over (raw tokens dumped mid-line).
			$steps[] = [
				'/(?<![A-Za-z0-9_\-\/.+])[A-Za-z0-9_\-]{40,}(?![A-Za-z0-9_\-\/+]|={1,2}(?:[^=]|$))/',
				static function ( $x ) use ( $m ) {
					return self::is_secret_value( $x[0] ) ? $m : $x[0];
				},
			];
		}

		foreach ( $steps as $step ) {
			$s = self::rx( $step[0], $step[1], $s );
			// A pattern that still fails returns null; withhold the text rather than return it unchecked.
			if ( null === $s ) {
				return self::MARKER;
			}
		}
		return $s;
	}

	/** Webhook endpoints whose path is the credential. */
	private static function webhook_path_hosts() {
		return '#^(?:hooks\.slack\.com/(?:services|workflows|triggers)/|(?:canary\.|ptb\.)?discord(?:app)?\.com/api/webhooks/|hooks\.zapier\.com/hooks/catch/|hook\.[a-z0-9]+\.make\.com/|hook\.integromat\.com/|script\.google\.com/macros/s/|[a-z0-9\-]+\.webhook\.office\.com/|outlook\.office\.com/webhook/|api\.telegram\.org/bot|maker\.ifttt\.com/trigger/[^/]+/with/key/|calendar\.google\.com/calendar/ical/[^/]+/private-)#i';
	}

	private static function scrub_url( $url ) {
		$m     = self::MARKER;
		$parts = explode( '://', $url, 2 );
		if ( 2 !== count( $parts ) ) {
			return $url;
		}
		list( $scheme, $rest ) = $parts;

		// Userinfo: everything before the last @ of the authority.
		$main = substr( $rest, 0, strcspn( $rest, '?#' ) );
		$at   = strrpos( $main, '@' );
		if ( false !== $at ) {
			$userinfo = substr( $rest, 0, $at );
			$after    = substr( $rest, $at );
			// User info sits before the host: no "/" in it, or a "user:" whose password
			// holds a "/" (not a host:port followed by a path, not a path with ":" in it).
			$colon       = strpos( $userinfo, ':' );
			$slash       = strpos( $userinfo, '/' );
			$is_userinfo = false === $slash
				|| ( false !== $colon && $colon < $slash && ! preg_match( '/^\d+$/', substr( $userinfo, $colon + 1, $slash - $colon - 1 ) ) );
			if ( $is_userinfo ) {
				$colon = strpos( $userinfo, ':' );
				if ( false !== $colon ) {
					$rest = substr( $userinfo, 0, $colon + 1 ) . $m . $after;
				} elseif ( strlen( $userinfo ) >= 20 || self::is_secret_value( $userinfo ) ) {
					// A token used as the user name (https://<token>@host/repo.git).
					$rest = $m . $after;
				}
			}
		}

		$host_and_path = preg_replace( '#^[^@/]*@#', '', $rest );
		if ( preg_match( self::webhook_path_hosts(), $host_and_path, $wm ) ) {
			return $scheme . '://' . substr( $rest, 0, strlen( $rest ) - strlen( $host_and_path ) ) . $wm[0] . $m;
		}
		// Generic webhook paths: the segment after /webhook/, /hooks/ and the like, when token-like.
		$rest = preg_replace_callback(
			'#(/(?:webhooks?|hooks?|trigger|callback)/)([A-Za-z0-9_\-]{16,})#i',
			static function ( $x ) use ( $m ) {
				return preg_match( '/\d/', $x[2] ) ? $x[1] . $m : $x[0];
			},
			$rest
		);

		// Query and fragment values under credential names.
		$rest = preg_replace_callback(
			'/([?&#;])([^=&#\s]{1,128})=([^&#\s]*)/',
			static function ( $x ) use ( $m ) {
				$name  = rawurldecode( $x[2] );
				$value = rawurldecode( $x[3] );
				if ( '' === $value || 0 === strpos( $value, '[REDACTED' ) || 0 === strpos( $value, '$matches[' ) ) {
					return $x[0];
				}
				$lname = strtolower( $name );
				if ( in_array( $lname, [ 'sig', 'signature', 'x-amz-signature', 'x-amz-credential', 'x-amz-security-token', 'x-goog-signature', 'x-goog-credential', 'pwd', 'pass', 'passwd' ], true ) ) {
					return $x[1] . $x[2] . '=' . $m;
				}
				// Generic short names hide a value only when it looks generated.
				if ( in_array( $lname, [ 'key', 'k', 'code', 'id' ], true ) ) {
					return ( strlen( $value ) >= 16 && preg_match( '/\d/', $value ) && preg_match( '/[A-Za-z]/', $value ) && ! self::looks_like_words( $value ) ) ? $x[1] . $x[2] . '=' . $m : $x[0];
				}
				return self::pair_value_is_secret( $name, $value ) ? $x[1] . $x[2] . '=' . $m : $x[0];
			},
			$rest
		);
		return $scheme . '://' . $rest;
	}

	// ------------------------------------------------------------------
	// Credential pairs: the login half stored beside a secret
	// ------------------------------------------------------------------

	/**
	 * Prefixes of the names in $keys that hold the secret half of a login pair,
	 * as a set: "wsc_client_secret" gives "", "wsc_" and "wsc_client_". Licence
	 * keys, public keys, nonces, salts and hashes are not pair secrets.
	 *
	 * @param bool $with_empty Include "" so an unprefixed username pairs with any secret.
	 * @return array<string,bool>
	 */
	public static function pair_secret_prefixes( array $keys, $with_empty ) {
		$set = [];
		foreach ( $keys as $k ) {
			if ( ! is_string( $k ) || ! self::is_secret_name( $k ) ) {
				continue;
			}
			$name = self::normalize_name( $k );
			$flat = str_replace( [ '_', '-', '.' ], '', $name );
			if ( preg_match( '/licen[cs]e|nonce|salt|hash|public|(?<!api)signature|serial|activation(?:code|key)|registration(?:code|key)|webhook|session|cookie|jwt|seed/', $flat ) ) {
				continue;
			}
			if ( $with_empty ) {
				$set[''] = true;
			}
			$len = strlen( $name );
			for ( $i = 0; $i < $len; $i++ ) {
				if ( '_' === $name[ $i ] || '-' === $name[ $i ] || '.' === $name[ $i ] ) {
					$set[ substr( $name, 0, $i + 1 ) ] = true;
				}
			}
		}
		return $set;
	}

	/**
	 * The login half of a pair (merchant ID, login ID, username, user,
	 * account, partner, vendor, account email) stored next to a pair secret
	 * with the same prefix. The same names elsewhere stay readable.
	 *
	 * @param array<string,bool> $pair_prefixes From pair_secret_prefixes().
	 */
	public static function is_credential_partner( $key, array $pair_prefixes ) {
		$prefix = self::credential_partner_prefix( $key );
		return null !== $prefix && isset( $pair_prefixes[ $prefix ] );
	}

	/** Prefix of a login-half name ("smtp_" for "smtp_user_name"), or null when the name is not one. */
	public static function credential_partner_prefix( $key ) {
		if ( ! preg_match( '/^(|.*[_\-.])(merchant[_\-.]?id|merchant|login[_\-.]?id|login|user[_\-.]?name|user|email|account[_\-.]?(?:id|sid|number)|account|partner[_\-.]?id|partner|vendor|seller[_\-.]?id)$/', self::normalize_name( $key ), $m ) ) {
			return null;
		}
		return $m[1];
	}
}
