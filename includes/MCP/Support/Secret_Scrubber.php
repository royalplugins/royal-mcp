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
 * about passwords or tokens is left alone; "as_stored" for an option the site
 * owner chose to have returned as stored, where only credential names, provider
 * key formats, private key blocks and credentials in URLs are hidden.
 *
 * Opened values are rewritten only when something inside was replaced;
 * otherwise the original string comes back byte for byte.
 */
final class Secret_Scrubber {

	/** The word bearer, any separators, and the value after it; an optional word token may sit between. */
	const BARE_BEARER = '/(?<![a-z0-9])(bearer)(?:[\s:=+,_\-]|%(?:25)?(?:20|09|0a|0d|2b)|%c2%a0|\xC2\xA0|\xE2\x80[\x80-\x8A\xAF]|\xE3\x80\x80|\\\\[nrt]|\\\\u00(?:20|09|0a|0d|a0))+(?:token(?:[\s:=+,_\-]|%(?:25)?(?:20|09|0a|0d|2b)|\xC2\xA0)+)?["\'<]?([a-z0-9._~+\/=:\-]+)/i';

	/** Words long enough to pass for a value that commonly follow the word bearer in a sentence. */
	const BEARER_PROSE = [ 'authentication', 'authorization', 'authorisation', 'authorized', 'authorised', 'credential', 'credentials', 'instrument', 'instruments', 'certificate', 'certificates', 'document', 'documents', 'security', 'securities', 'required', 'requires', 'challenge', 'challenges', 'receives', 'presents' ];

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
	 * @param array  $opts     mode: 'strict' (default), 'content' or 'as_stored'; exempt_keys: names never
	 *                         redacted by name (their values are still checked by shape);
	 *                         content_keys: names whose string values are prose, checked in content mode;
	 *                         skip_keys: names whose values are returned untouched (the caller's own fields);
	 *                         name_list_items: true when $value maps names to lists of stored values (all of
	 *                         a post's meta), so each value is judged under its own name, as a read of that
	 *                         one name judges it.
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
	 * Placeholders Royal MCP shows in place of hidden data: the redaction marker in any case
	 * and spacing, and the placeholders of the form, link, location and nesting filters.
	 */
	const PLACEHOLDERS = '/\[\s*redacted(?:#k\d+)?\s*\]|\[\s*(?:hidden|email hidden|phone hidden|nested too deep|link removed|not stored)\s*\]|\*\*\*@\*\*\*/i';

	/**
	 * Whether a write input carries the marker or another placeholder anywhere (also HTML-entity,
	 * URL, \u or \x encoded, up to three rounds), so writing it could store the placeholder in place of the real value.
	 */
	public static function contains_marker( $value, $depth = 0 ) {
		// Nesting this deep is what a read replaces with the marker: refuse it.
		if ( $depth > self::MAX_DEPTH ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return self::text_has_marker( $value );
		}
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				if ( ( is_string( $k ) && self::text_has_marker( $k ) ) || self::contains_marker( $v, $depth + 1 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether a value holds text that is not valid UTF-8 anywhere (keys included). Replies to AI clients
	 * carry such text converted or cut, so what the client reads is not what is stored.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Nesting walked so far.
	 * @return bool
	 */
	public static function holds_invalid_utf8( $value, $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			return true;
		}
		if ( is_string( $value ) ) {
			// A check that fails to run counts as invalid text.
			return '' !== $value && 1 !== preg_match( '//u', $value );
		}
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				if ( ( is_string( $k ) && self::holds_invalid_utf8( $k, $depth + 1 ) ) || self::holds_invalid_utf8( $v, $depth + 1 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** contains_marker() for one string: as sent, then after up to three rounds of decoding. */
	private static function text_has_marker( $s ) {
		// A check that fails to run counts as a match.
		if ( 0 !== preg_match( self::PLACEHOLDERS, $s ) ) {
			return true;
		}
		if ( 0 === preg_match( '/[&%+\\\\\xC2]/', $s ) ) {
			return false;
		}
		// \uXXXX and \xNN escapes, bracket entities written without the closing ";", no-break spaces.
		$unescape = static function ( $t ) {
			$out = preg_replace_callback(
				'/\\\\u([0-9a-fA-F]{4})|\\\\x([0-9a-fA-F]{2})|&#0*(91|93);?|&#x0*(5b|5d);?/i',
				static function ( $x ) {
					if ( isset( $x[4] ) && '' !== $x[4] ) {
						return '5b' === strtolower( $x[4] ) ? '[' : ']';
					}
					if ( isset( $x[3] ) && '' !== $x[3] ) {
						return '91' === $x[3] ? '[' : ']';
					}
					if ( isset( $x[2] ) && '' !== $x[2] ) {
						return chr( hexdec( $x[2] ) );
					}
					$c = json_decode( '"\\u' . $x[1] . '"' );
					return is_string( $c ) ? $c : $x[0];
				},
				$t
			);
			return str_replace( "\xC2\xA0", ' ', is_string( $out ) ? $out : $t );
		};
		$t = $s;
		for ( $round = 0; $round < 3; $round++ ) {
			foreach ( [ 'entities', 'url', 'unicode' ] as $step ) {
				if ( 'entities' === $step ) {
					$t = html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				} elseif ( 'url' === $step ) {
					$t = urldecode( $t );
				} else {
					$t = $unescape( $t );
				}
				if ( ! is_string( $t ) || 0 !== preg_match( self::PLACEHOLDERS, $t ) ) {
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
		return (string) self::normalize_or_null( $key );
	}

	/** normalize_name(), or null when a pattern fails to run (the name is then treated as a credential name). */
	private static function normalize_or_null( $key ) {
		$key = preg_replace( '/^\x00.*\x00/s', '', (string) $key );
		$key = null === $key ? null : preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key );
		$key = null === $key ? null : preg_replace( '/\s+/', '_', trim( $key ) );
		// ASCII-only lower case, so names with accented letters read the same under every locale.
		return null === $key ? null : strtr( $key, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
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
		$key_lc = self::normalize_or_null( $key );
		if ( null === $key_lc ) {
			return true;
		}
		$flat = str_replace( [ '_', '-', '.', ' ' ], '', $key_lc );

		// Names of a storage slot, not of a credential: meta_key => "_thumbnail_id".
		if ( self::is_slot_key_name( $key_lc ) ) {
			return false;
		}
		// Keys that are public by design: CAPTCHA site keys, publishable payment keys.
		if ( self::is_public_key_name( $key_lc ) ) {
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
			// "password" in other languages.
			'passwort', 'contrasena', 'contraseña', 'mot_de_passe', 'motdepasse', 'senha', 'wachtwoord', 'parola', 'haslo', 'hasło',
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
		static $last = [ 'serial', 'webhook', 'webhooks', 'cookie', 'jwt', 'hmac', 'otp', 'totp', 'seed', 'dsn', 'pem', 'sid', 'oauth', 'mfa', 'passkey', 'creds', 'auth', 'authtoken', 'pin', 'passcode' ];
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
		if ( preg_match( '/(key|pass|pwd|secret|token|auth|salt|api)_?hash/i', $key_lc ) ) {
			return true;
		}
		return false;
	}

	/** A name for a storage slot ("meta_key", "cache_key"), whose value is a key name, not a credential. */
	private static function is_slot_key_name( $key_lc ) {
		return (bool) preg_match( '/^(?:meta|option|field|cache|setting|settings|transient|object|primary|foreign|sort|group|row|item|lookup|map|menu|translation|string|text|label|query|param|column|array|sidebar|widget|block|term|i18n|attribute|variation|idempotency|cart_item|order_item)_key$/', $key_lc );
	}

	/** A name for a key that is published in the page by design: a CAPTCHA site key or a publishable payment key. */
	private static function is_public_key_name( $key_lc ) {
		$flat = str_replace( [ '_', '-', '.', ' ' ], '', $key_lc );
		return (bool) preg_match( '/(?:sitekey|publishablekey)$/', $flat ) && ! preg_match( '/secret|pass|pwd|token|priv|auth|cred/', $flat );
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

	/** Words that make a hex value on its line a credential rather than a file digest. */
	const CREDENTIAL_WORDS = '/(?<![a-z])(?:bearer|authori[sz]ation|auth|keys?|secrets?|tokens?|signing|signatures?|credentials?|creds|licen[cs]es?|passwords?|passwd|pass|pwd|passphrases?|passcodes?|hmac|apis?|salts?|cookies?|sessions?|private|webhooks?|clients?|env|id_rsa|id_ed25519|stripe|paypal|braintree|twilio|sendgrid|mailgun|mailchimp|aws|openai|anthropic)(?![a-z])/i';

	/** Words that make numbers after PASS on the same line or the line before a code or a login. */
	const LOGIN_WORDS = '/(?<![a-z])(?:user(?:name)?s?|usr|login|host(?:name)?s?|s?ftp|ssh|wi-?fi|otp|pins?|card|codes?|backup|door|e-?mail|password|passwd|pwd|passphrase|passcode|ssid|admin|root|db|database|mysql|server|account|acct|smtp|imap|vpn|router|gate|garage|alarm|locker|keypad|voicemail|atm|bank|cpanel|network|wireless|guest)(?![a-z])/i';

	/** The word pass used for a test outcome ("PASS: 101", "pass=12"), left out when a line is checked for credential words. */
	const OUTCOME_PASS = '/(?<![a-z])pass(?:ed)?(?=["\']?[ \t]*[:=]?[ \t]*["\']?#?\d)/i';

	/** Words that say a line, a column or a block reports test results ("Smoke run passed: 12", "all PASS: 101"). */
	const TEST_WORDS = '(?:tests?|checks?|runs?|suites?|smoke|qa|specs?|cases?|all|total|results?|builds?|ci|e2e|unit|integration|lint|assertions?|posts?)';

	/** Test words for the line above an outcome or the sentence before it (no "all" / "total": too common in prose). */
	const TEST_CONTEXT_WORDS = '(?:tests?|checks?|runs?|suites?|smoke|qa|specs?|cases?|results?|builds?|ci|e2e|unit|integration|lint|assertions?|posts?|checked|rechecked|verified|tested)';

	/** Test words accepted right before the word pass ("status PASS: 9", "health check PASS: 3"). */
	const TEST_WORDS_BEFORE = '(?:tests?|checks?|runs?|suites?|smoke|qa|specs?|cases?|all|total|results?|builds?|ci|e2e|unit|integration|lint|assertions?|posts?|status|health|verify|verified|checked|tested)';

	/**
	 * A screen or form named by a login word ("the login form", "admin page") that is not itself the
	 * label of the numbers: it names what was tested, so it is not a login word for outcome checks.
	 */
	const LOGIN_UI_PHRASE = '/(?<![a-z])(?:log-?in|sign-?in|user|admin|account)[ \t]+(?:forms?|pages?|screens?)(?![a-z])(?![ \t]*["\'`]?[ \t]*pass)/i';

	/** A login-type label with a value earlier on the line ("name: bob", "UID=test", "Site: example.test"). */
	const LOGIN_LABEL_PAIR = '/(?<![a-z0-9_])(?:user(?:name)?|uname|usr|name|login|uid|host(?:name)?|site|server|account|acct)[ \t]*[:=][ \t]*["\']?[^\s"\',;]/i';

	/** @var string Nearest named block above the block being walked; decides whether a "pass" key holds a test result. */
	private static $named_parent = '';

	/** Digest length in hex for each algorithm name. */
	const DIGEST_LENGTHS = [ 'md5' => 32, 'sha1' => 40, 'sha224' => 56, 'sha256' => 64, 'sha384' => 96, 'sha512' => 128 ];

	/**
	 * The line around a position, read within LINE_SPAN bytes on each side so long single-line
	 * text stays fast: [ text before the position on its line, the line, the line before ].
	 *
	 * @param string $text Text.
	 * @param int    $at   Byte position.
	 * @return string[]
	 */
	private static function line_at( $text, $at ) {
		$from   = max( 0, $at - self::LINE_SPAN );
		$window = (string) substr( $text, $from, $at - $from );
		$nl     = strrpos( $window, "\n" );
		$before = false === $nl ? $window : (string) substr( $window, $nl + 1 );
		$ahead  = (string) substr( $text, $at, self::LINE_SPAN );
		$end    = strpos( $ahead, "\n" );
		$line   = $before . ( false === $end ? $ahead : substr( $ahead, 0, $end ) );
		$prev   = '';
		$prev2  = '';
		if ( false !== $nl ) {
			$up   = (string) substr( $window, 0, $nl );
			$pnl  = strrpos( $up, "\n" );
			$prev = false === $pnl ? $up : (string) substr( $up, $pnl + 1 );
			if ( false !== $pnl ) {
				$up2   = (string) substr( $up, 0, $pnl );
				$pnl2  = strrpos( $up2, "\n" );
				$prev2 = false === $pnl2 ? $up2 : (string) substr( $up2, $pnl2 + 1 );
			}
		}
		$prev = rtrim( $prev, "\r" );
		// A blank line between a label and the line below it does not break the context.
		$near = '' === trim( $prev ) ? rtrim( $prev2, "\r" ) : $prev;
		return [ $before, rtrim( $line, "\r" ), $prev, $near ];
	}

	/** How far line_at() reads on each side of a position. */
	const LINE_SPAN = 512;

	/**
	 * Set data URIs and SSH public keys aside: encoded payloads and public keys, not
	 * credentials, which the token checks would otherwise take for one.
	 *
	 * @param string   $text Text about to be checked.
	 * @param string[] $held Pieces set aside so far; added to.
	 * @return string
	 */
	private static function hold_payloads( $text, array &$held ) {
		if ( false === stripos( $text, ';base64,' ) && false === stripos( $text, 'ssh-' ) && false === stripos( $text, 'ecdsa-' ) ) {
			return $text;
		}
		$out = preg_replace_callback(
			'#(data:[\w.+\-]+/[\w.+\-]+(?:;[\w\-]+=[\w.\-]+)*;base64,)([A-Za-z0-9+/]+={0,2})|((?:ssh-(?:rsa|dss|ed25519)|ecdsa-sha2-nistp\d{3})[ \t]+)(AAAA[A-Za-z0-9+/]+={0,3})#i',
			static function ( $x ) use ( &$held ) {
				$lead    = '' !== $x[1] ? $x[1] : $x[3];
				$payload = '' !== $x[1] ? $x[2] : $x[4];
				// Encoded text (text/*, JSON, XML, form data) is decoded and checked like any base64 text first.
				if ( '' !== $x[1] && preg_match( '#^data:(?:text/|application/(?:json|xml|x-www-form-urlencoded))#i', $x[1] ) ) {
					$opened = self::open_base64( $payload, [ 'mode' => 'strict', 'exempt_keys' => [], 'content_keys' => [], 'skip_keys' => [] ], 0 );
					$bin    = base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading a value to check it
					if ( true === $opened || ( is_string( $bin ) && self::scrub_text( $bin, 'strict' ) !== $bin ) ) {
						return $lead . self::MARKER;
					}
				}
				// Only the payload is set aside; the media type and its parameters are checked as text.
				$held[] = $payload;
				return $lead . "\x02" . ( count( $held ) - 1 ) . "\x03";
			},
			$text
		);
		return is_string( $out ) ? $out : $text;
	}

	/**
	 * Set test results and labelled file digests aside so the name / value checks leave them alone.
	 *
	 * Digests, kept when they are a digest of a standard length that says it is one:
	 *  - a hash word right before it ("sha256 <hex>", "checksum: <hex>", "Hash: `<hex>`",
	 *    "sha256 of build.zip: <hex>", "Checksum (SHA-512): <hex>"; an algorithm name must match the length);
	 *  - checksum tool output ("<hex>  build.zip") and "build.zip: <hex>";
	 *  - a size right after it ("<hex>, 18211 bytes") when the line names a file or the size is 1024 bytes or more;
	 *  - a commit hash after commit, sha or revision.
	 * A credential word on the line (key, token, secret, pass, signing, licence, api, ...) or on a
	 * label line just above it ("API key:") makes the hex a credential: it is hidden instead. Hex
	 * followed by a size with nothing on the line naming a file, and under 1024 bytes, is hidden too.
	 *
	 * Test results: PASS / Pass / FAIL / Fail, ":" or "=", then plain whole numbers (also "#101",
	 * comma or space separated). Kept when the line reports results (a test word before it, or both
	 * a PASS and a FAIL on the line), or, for two or more numbers, when the outcome starts the line.
	 * A login, code or card word on the line or the line before keeps nothing. Numbers after PASS
	 * that are not kept are hidden as a whole, never in part.
	 *
	 * @param string   $text Text about to be checked.
	 * @param string[] $held Pieces set aside so far; added to.
	 * @return string Text with each kept piece replaced by a placeholder.
	 */
	private static function hold_test_results( $text, array &$held ) {
		$keep = static function ( $piece ) use ( &$held ) {
			$held[] = $piece;
			return "\x02" . ( count( $held ) - 1 ) . "\x03";
		};
		$out = $text;
		if ( preg_match( '/[0-9a-fA-F]{32}/', $out ) ) {
			$out = self::hold_digests( $out, $keep );
		}
		if ( preg_match( '/pass|fail/i', $out ) ) {
			$out = self::hold_outcomes( $out, $keep );
		}
		return $out;
	}

	/** Whether the text before a position on its line holds a credential word. */
	private static function credential_before( $text, $at ) {
		list( , $line, , $prev ) = self::line_at( $text, $at );
		// A test outcome ("PASS: 101 102") is not the word pass of a login.
		$line = self::without_outcome_pass( $line );
		$prev = self::without_outcome_pass( $prev );
		// Anywhere on the line, or a label line just above it ("API key:"). A failed check counts as a match.
		return 0 !== preg_match( self::CREDENTIAL_WORDS, $line )
			|| ( 0 !== preg_match( '/:[ \t]*$/', $prev ) && 0 !== preg_match( self::CREDENTIAL_WORDS, $prev ) );
	}

	/** A line with test-outcome uses of the word pass taken out; unchanged when the pattern fails to run. */
	private static function without_outcome_pass( $line ) {
		$out = preg_replace( self::OUTCOME_PASS, '', $line );
		return is_string( $out ) ? $out : $line;
	}

	/** preg_replace_callback with offsets, the callback also given the subject; the subject back unchanged on failure. */
	private static function replace_at( $pattern, callable $decide, $subject ) {
		$next = preg_replace_callback(
			$pattern,
			static function ( $x ) use ( $decide, $subject ) {
				return $decide( $x, $subject );
			},
			$subject,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);
		return is_string( $next ) ? $next : $subject;
	}

	/** hold_test_results() for hex digests. */
	private static function hold_digests( $text, callable $keep ) {
		$m = self::MARKER;

		// A hash word, then the digest.
		$text = self::replace_at(
			'/(?<![A-Za-z0-9_\-])((sha-?(?:1|224|256|384|512)|md5|hash|checksum|digest|etag)(?:sum)?(?:[ \t]*\([ \t]*(sha-?(?:1|224|256|384|512)|md5)[ \t]*\))?(?:[ \t]+of[ \t]+[\w\-\/]+\.[A-Za-z0-9]{1,8})?[ \t]*(?:[:=]|->|\xE2\x86\x92|-(?=[ \t]))?[ \t]*)(["\'`]?)([0-9a-fA-F]{32,128})\4(?![0-9A-Za-z_\-])/i',
			static function ( $x, $subject ) use ( $keep, $m ) {
				$hex  = $x[5][0];
				$algo = strtolower( str_replace( '-', '', ( isset( $x[3][1] ) && -1 !== $x[3][1] && '' !== $x[3][0] ) ? $x[3][0] : $x[2][0] ) );
				$fits = isset( self::DIGEST_LENGTHS[ $algo ] ) ? self::DIGEST_LENGTHS[ $algo ] === strlen( $hex ) : self::is_digest_hex( $hex );
				if ( ! $fits ) {
					return $x[0][0];
				}
				if ( self::credential_before( $subject, $x[0][1] ) ) {
					return $x[1][0] . $x[4][0] . $m . $x[4][0];
				}
				return $x[1][0] . $x[4][0] . $keep( $hex ) . $x[4][0];
			},
			$text
		);
		// Checksum tool output at the start of a line: "<hex>  build.zip".
		$text = self::replace_at(
			'/(?<![^\n])([ \t]*)([0-9a-fA-F]{32,128})(  | \*)(?=[^\s]*(?:\.[A-Za-z0-9]{1,8}|\/)[^\s]*[ \t]*(?:\r?\n|$))/',
			static function ( $x, $subject ) use ( $keep ) {
				if ( ! self::is_digest_hex( $x[2][0] ) || self::credential_before( $subject, $x[2][1] ) ) {
					return $x[0][0];
				}
				return $x[1][0] . $keep( $x[2][0] ) . $x[3][0];
			},
			$text
		);
		// A file name, then the digest: "build.zip: <hex>", "dist/app.js <hex>" (a file manifest).
		$text = self::replace_at(
			'/(?<![^\s"\'(\[])([\w\-\/]+\.[A-Za-z][A-Za-z0-9]{0,7}(?:[ \t]*:[ \t]*|[ \t]+))(["\'`]?)([0-9a-fA-F]{32,128})\2(?![0-9A-Za-z_\-])/',
			static function ( $x, $subject ) use ( $keep, $m ) {
				if ( ! self::is_digest_hex( $x[3][0] ) ) {
					return $x[0][0];
				}
				return $x[1][0] . $x[2][0] . ( self::credential_before( $subject, $x[0][1] ) ? $m : $keep( $x[3][0] ) ) . $x[2][0];
			},
			$text
		);
		// The digest, then a size.
		$text = self::replace_at(
			'/(?<![0-9A-Za-z_\-])([0-9a-fA-F]{32,128})(["\'`)]?[ \t]*[,;(]?[ \t]*(\d(?:[\d,]{0,18}\d)?)[ \t]*(bytes|B|KB|MB|GB|KiB|MiB|GiB)\b)/i',
			static function ( $x, $subject ) use ( $keep, $m ) {
				$hex = $x[1][0];
				if ( self::credential_before( $subject, $x[0][1] ) ) {
					return $m . $x[2][0];
				}
				list( $before ) = self::line_at( $subject, $x[0][1] );
				$size    = (int) str_replace( ',', '', $x[3][0] );
				$is_file = 1 === preg_match( '/(?<![a-z])(?:files?|zip|tar|gz|artifacts?|downloads?|size|builds?|archives?|packages?|release|upload(?:ed)?)(?![a-z])|[\w\-]\.[A-Za-z][A-Za-z0-9]{0,7}(?![\w])/i', $before )
					|| ( in_array( strtolower( $x[4][0] ), [ 'bytes', 'b' ], true ) && $size >= 1024 );
				if ( $is_file && self::is_digest_hex( $hex ) ) {
					return $keep( $hex ) . $x[2][0];
				}
				// Hex described only by the size a key would have is a key.
				return $m . $x[2][0];
			},
			$text
		);
		// A commit hash, also after a deploy or git verb ("deployed <sha1> to prod").
		return self::replace_at(
			'/(?<![A-Za-z0-9_\-])((?:commit|sha|revision|rev|deployed|deploying|deploy|released|built|tagged|merged|reverted|pushed|rebased|cherry-picked|checked[ \t]+out|checkout|head)[ \t]*[:=#]?[ \t]*)([0-9a-f]{40}|[0-9a-f]{64})(?![0-9A-Za-z_\-])/i',
			static function ( $x, $subject ) use ( $keep, $m ) {
				return $x[1][0] . ( self::credential_before( $subject, $x[0][1] ) ? $m : $keep( $x[2][0] ) );
			},
			$text
		);
	}

	/** hold_test_results() for PASS / FAIL lines. */
	private static function hold_outcomes( $text, callable $keep ) {
		$m  = self::MARKER;
		// The outcome word (not inside a name or a URL query), ":" or "=", then whole numbers: "PASS: 101 102",
		// "passed: 12 of 14", "Pass: 12/14", "pass=3", "\"pass\": 12".
		$rx = '/(?<![\w.@\/\-?&#;])(pass(?:ed)?|fail(?:ed)?)(["\']?[ \t]*[:=][ \t]*)(["\']?)((?>#?\d{1,12}(?:\/\d{1,12})?(?:(?:[ \t]*,[ \t]*|[ \t]+)(?:and[ \t]+)?#?\d{1,12})*))\3(?![\w@\-]|\.\w|[ \t]*\/)/i';
		return self::replace_at(
			$rx,
			static function ( $x, $subject ) use ( $keep, $m ) {
				$word = $x[1][0];
				list( , $line, , $near ) = self::line_at( $subject, $x[0][1] );
				$line = self::without_login_ui_phrases( $line );
				$near = self::without_login_ui_phrases( $near );
				// Numbers after an outcome word are a test result unless the line, or the line above it
				// (a blank line skipped), is about a login, a code or a card. A failed check counts as one.
				// After the word pass they are a result only in a test context (see is_outcome_context()).
				if ( 0 === preg_match( self::LOGIN_WORDS, $line ) && 0 === preg_match( self::LOGIN_WORDS, $near )
					&& ( 0 !== stripos( $word, 'pass' ) || self::is_outcome_context( $subject, $x ) ) ) {
					return $keep( $x[0][0] );
				}
				// Numbers after the word pass that are not a result are hidden whole.
				if ( 0 === stripos( $word, 'pass' ) ) {
					return $word . $x[2][0] . $x[3][0] . $m . $x[3][0];
				}
				return $x[0][0];
			},
			$text
		);
	}

	/** A line with screen / form phrases named by a login word taken out; unchanged when the pattern fails to run. */
	private static function without_login_ui_phrases( $line ) {
		$out = preg_replace( self::LOGIN_UI_PHRASE, ' ', $line );
		return is_string( $out ) ? $out : $line;
	}

	/**
	 * Whether numbers after the word pass are a test result by what surrounds them: an "N of M" or
	 * "N/M" count; nothing before the word on its line (and the line above, a blank line skipped,
	 * absent, a test line or another outcome line); test vocabulary right before the word ("all PASS:",
	 * "Smoke run passed:", "Checked posts. PASS:", "deploy verified PASS:", "status PASS:"); or a
	 * results list ("fail: 0, pass: 12", "pass=12 fail=2"). Anything else before the word ("Phone pass:",
	 * "Mom's pass:") makes the numbers a code, and so does a login-type label with a value earlier on
	 * the line ("name: bob PASS: 1234").
	 *
	 * A single number is the shape of a PIN or a numeric password, so it needs more than a list does:
	 * the upper-case word PASS (or PASSED) with ":", and either test vocabulary on the line or a test
	 * line just above. "PASS: 4821" alone, "pass: 4821", "Pass: 4821" and "PASS=4821" are hidden;
	 * "all PASS: 4821", "QA PASS: 4821" and "PASS: 101 102 103" are kept.
	 *
	 * @param string $subject Text.
	 * @param array  $x       Match with offsets from hold_outcomes().
	 */
	private static function is_outcome_context( $subject, array $x ) {
		$numbers = $x[4][0];
		$end     = $x[0][1] + strlen( $x[0][0] );
		if ( false !== strpos( $numbers, '/' ) || 1 === preg_match( '/^[ \t]+of[ \t]+\d/', (string) substr( $subject, $end, 32 ) ) ) {
			return true;
		}
		list( $before, $line, , $near ) = self::line_at( $subject, $x[0][1] );
		// A login-type label with a value earlier on the line makes the numbers that login's password.
		if ( 0 !== preg_match( self::LOGIN_LABEL_PAIR, $before ) ) {
			return false;
		}
		// Bullets, list numbers and a leading date or time (a date in brackets, or a date and time) are not text before the word.
		$lead = preg_replace( '/^[ \t]*(?:(?:[\-*+>#|]|\xE2\x80\xA2|\d{1,3}[.)]|[(\[]?\d{4}-\d{2}-\d{2}(?:[T \t]\d{1,2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+\-]\d{2}:?\d{2})?)?[)\]]?|[(\[]?\d{1,2}:\d{2}(?::\d{2})?[)\]]?)[ \t]*)*/', '', $before );
		if ( ! is_string( $lead ) ) {
			return false;
		}
		// A results list: another count right before ("fail: 0, pass: 12", "{"fail":0,"pass":12}").
		if ( 1 === preg_match( '/(?<![a-z])(?:fail(?:ed|ures?|s)?|skip(?:ped|s)?|errors?|total|tests?|assertions?)["\']?[ \t]*[:=][ \t]*["\']?#?\d[\d\/]*["\']?[ \t]*[,;|]?[ \t]*["\']?$/i', $lead ) ) {
			return true;
		}
		// The first key of an object in a line ('run done {"pass": 12, "fail": 0}'): the key before the
		// brace, when there is one, must be test vocabulary; with none, a fail / skipped / errors / total
		// count must follow on the line.
		if ( 1 === preg_match( '/\{[ \t]*["\']?$/', $lead ) ) {
			$open = (string) substr( $lead, 0, (int) strrpos( $lead, '{' ) );
			if ( 1 === preg_match( '/["\']?([A-Za-z0-9_\-]+)["\']?[ \t]*[:=][ \t]*$/', $open, $pk ) ) {
				$pw = str_replace( [ '_', '-' ], ' ', strtolower( $pk[1] ) );
				return 0 === preg_match( self::LOGIN_WORDS, $pw ) && 1 === preg_match( '/(?<![a-z0-9])' . self::TEST_WORDS . '(?![a-z0-9])/', $pw );
			}
			$rest = (string) substr( $line, strlen( $before ) + strlen( $x[0][0] ) );
			return 1 === preg_match( '/^[^{}]*["\']?(?:fail(?:ed|ures?|s)?|skip(?:ped|s)?|errors?|total)["\']?[ \t]*[:=][ \t]*["\']?\d/i', $rest );
		}
		// One number needs the upper-case word PASS / PASSED and ":" ("PASS=1234" is how a password is
		// written down), unless another outcome count follows on the line ("pass=12 fail=2").
		$rest   = (string) substr( $line, strlen( $before ) + strlen( $x[0][0] ) );
		$single = 0 === preg_match( '/\d[ \t,]+(?:and[ \t]+)?#?\d/', $numbers )
			&& 1 !== preg_match( '/^[^\n]*?(?<![a-z])(?:fail(?:ed|ures?|s)?|skip(?:ped|s)?|errors?|total)["\']?[ \t]*[:=][ \t]*["\']?\d/i', $rest );
		if ( $single && ( 1 !== preg_match( '/^PASS(?:ED)?$/', $x[1][0] ) || false === strpos( $x[2][0], ':' ) ) ) {
			return false;
		}
		$stem = rtrim( $lead, " \t\"'`:;,.([{|\-*" );
		if ( '' === $stem ) {
			$near = trim( $near );
			// A single number with nothing before it and no test line above reads like a PIN note.
			return ( '' === $near && ! $single )
				|| 1 === preg_match( '/(?<![a-z])' . self::TEST_CONTEXT_WORDS . '(?![a-z])/i', $near )
				|| 1 === preg_match( '/(?<![a-z])(?:pass(?:ed)?|fail(?:ed)?)["\']?[ \t]*[:=][ \t]*["\']?#?\d/i', $near );
		}
		if ( 1 === preg_match( '/(?<![a-z])deploy[ \t]+verified$/i', $stem ) ) {
			return true;
		}
		// A new sentence after one about the test ("Rechecked posts 101, 102 after deploy. PASS: 101").
		if ( 1 === preg_match( '/[.!?][ \t]*$/', $lead ) ) {
			return 1 === preg_match( '/(?<![a-z])' . self::TEST_CONTEXT_WORDS . '(?![a-z])/i', $stem );
		}
		// A test word right before, standing as its own word ("pre-test", "example.test", "bob@status" do not count).
		return 1 === preg_match( '/(?<![A-Za-z0-9_\-.@\/])' . self::TEST_WORDS_BEFORE . '$/i', $stem );
	}

	/** Put back what hold_payloads() and hold_test_results() set aside. */
	private static function release_test_results( $text, array $held ) {
		if ( [] === $held ) {
			return $text;
		}
		$out = preg_replace_callback(
			'/\x02(\d+)\x03/',
			static function ( $x ) use ( $held ) {
				return isset( $held[ (int) $x[1] ] ) ? $held[ (int) $x[1] ] : '';
			},
			$text
		);
		return is_string( $out ) ? $out : self::MARKER;
	}

	/** Names under which a number is a count, time or id rather than a secret. */
	private static function is_numeric_field_name( $key ) {
		return (bool) preg_match( '/(^|_)(count|counts|total|ttl|expires?|expiry|exp|at|time|timestamp|date|id|ids|num|number_of|length|size|limit|max|min|version|port|status|level|interval|seconds|minutes|hours|days|age|attempts|tries|lifetime|timeout|retries|length|tokens)$/', self::normalize_name( $key ) );
	}

	/** Keys that name an identifier (hashes, commits, slugs, hooks), whose values are not judged by shape. */
	private static function is_identifier_name( $key ) {
		if ( '' === $key ) {
			return false;
		}
		$name = self::normalize_name( $key );
		if ( self::is_digest_name( $key ) ) {
			// A digest, commit or version named after a credential ("api_fingerprint", "token_version") is not one.
			return ! preg_match( '/key|token|secret|pass|pwd|auth|api|sess|cookie|licen|priv|client|salt|hmac|bearer|cred|sig/', preg_replace( '/(^|_)(hash|sha|sha1|sha224|sha256|sha384|sha512|md5|checksum|digest|etag|fingerprint|commit|revision|version)$/', '', $name ) );
		}
		return (bool) preg_match( '/(^|_)(id|slug|hook|uuid|guid|handle|template|class|action|filter|post_name|file_name|filename)$/', $name )
			|| self::is_slot_key_name( $name ) || self::is_public_key_name( $name );
	}

	/**
	 * Keys that name a digest, a commit or a version. A digest / commit key keeps only a
	 * standard-length hex digest, a version key only a version number; anything else stored
	 * there is judged by its shape like any other value.
	 */
	private static function is_digest_name( $key ) {
		return (bool) preg_match( '/(^|_)(hash|sha|sha1|sha224|sha256|sha384|sha512|md5|checksum|digest|etag|fingerprint|commit|revision|version)$/', self::normalize_name( $key ) );
	}

	/** Whether a value fits a digest / commit / version key (see is_digest_name()). */
	private static function fits_digest_name( $key, $value ) {
		$value = trim( preg_replace( '#^W/#', '', (string) $value ), " \t\"'" );
		if ( preg_match( '/(^|_)version$/', self::normalize_name( $key ) ) ) {
			return self::is_digest_hex( $value ) || ( (bool) preg_match( '/^[vV]?\d{1,6}(?:[.\-+_][0-9A-Za-z]{1,20}){0,6}$/', $value ) && ! self::is_secret_value( $value ) );
		}
		return self::is_digest_hex( $value );
	}

	/** Hex of a standard digest length (md5, sha1, sha224, sha256, sha384, sha512). */
	private static function is_digest_hex( $hex ) {
		return (bool) preg_match( '/^[0-9a-fA-F]+$/', $hex ) && in_array( strlen( $hex ), [ 32, 40, 56, 64, 96, 128 ], true );
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
			. '|sk-[A-Za-z0-9]{32,}'
			. '|hf_[A-Za-z0-9]{30,}'
			. '|pplx-[A-Za-z0-9]{40,}'
			. '|gsk_[A-Za-z0-9]{40,}'
			. '|xai-[A-Za-z0-9]{40,}'
			. '|dop_v1_[a-f0-9]{64}'
			. '|SK[0-9a-f]{32}'
			. '|[0-9a-f]{32}-us\d{1,2}'
			. '|(?:ck|cs)_[0-9a-f]{40}'
			. '|wc_order_[A-Za-z0-9]{12,13}'
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

	/** A UUID / GUID (8-4-4-4-12 hex, optionally in braces). */
	private static function is_uuid( $value ) {
		return 1 === preg_match( '/^\{?[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\}?$/', $value );
	}

	/**
	 * A name made of words with a digest of a standard length in it: a cache key
	 * ("post_list_<md5>"), a transient ("_transient_feed_<md5>") or a hashed file
	 * name ("critical-<sha1>"). Words that name a credential do not count.
	 */
	private static function is_named_digest( $value ) {
		if ( ! preg_match( '/^_*((?:[A-Za-z]+[_\-.])+)([0-9a-f]{32}|[0-9a-f]{40}|[0-9a-f]{56}|[0-9a-f]{64}|[0-9a-f]{96}|[0-9a-f]{128})(?:[_\-.]([A-Za-z][A-Za-z0-9]{0,11}))?$/', $value, $m ) ) {
			return false;
		}
		$words = preg_split( '/[_\-.]+/', strtolower( $m[1] ), -1, PREG_SPLIT_NO_EMPTY );
		$long  = false;
		foreach ( (array) $words as $w ) {
			// Words that name a credential, a service that issues keys, or an account.
			if ( in_array( $w, [ 'sk', 'pk', 'rk', 'ck', 'cs', 'pw', 'db', 'live', 'test', 'prod', 'dev' ], true )
				|| preg_match( '/key|token|secret|pass|pwd|auth|api|sess|sig|hmac|hook|cred|licen|salt|nonce|cookie|bearer|priv|client|access|whsec|shp|deploy|master|bot|stripe|paypal|braintree|square|github|gitlab|bitbucket|openai|anthropic|sendgrid|twilio|mailchimp|mailgun|mandrill|postmark|sparkpost|slack|discord|telegram|smtp|imap|ftp|ssh|database|mysql|admin|root|user|login|account|aws|azure|google|gcp|cloudflare|shopify|heroku|vercel|netlify|dropbox|npm|docker/', $w ) ) {
				return false;
			}
			if ( strlen( $w ) >= 4 ) {
				$long = true;
			}
		}
		// One word alone counts only before a file extension ("critical-<sha1>.css").
		return $long && ( count( (array) $words ) >= 2 || ( isset( $m[3] ) && '' !== $m[3] ) );
	}

	/**
	 * A WordPress application password: six groups of four letters and digits that look generated,
	 * with digits, capitals and lower-case letters mixed in ("SKU1 SKU2 …" and "ABCD 1234 …" are codes).
	 */
	private static function is_app_password( $value ) {
		return 1 === preg_match( '/^[A-Za-z0-9]{4}(?: [A-Za-z0-9]{4}){5}$/', $value ) && preg_match( '/\d/', $value ) && preg_match( '/[A-Z]/', $value ) && preg_match( '/[a-z]/', $value );
	}

	/** The shape of an AWS secret access key: 40 characters of letters, digits, / and +, with / or + in it. */
	private static function is_aws_secret_shape( $value ) {
		return 1 === preg_match( '/^[A-Za-z0-9\/+]{40}$/', $value ) && preg_match( '/[\/+]/', $value )
			&& preg_match( '/[A-Z]/', $value ) && preg_match( '/[a-z]/', $value ) && preg_match( '/\d/', $value );
	}

	/** Whether a string is a stored password hash: phpass, bcrypt (also WordPress's prefixed form), crypt or Argon2. */
	private static function is_password_hash( $value ) {
		return 1 === preg_match( '/^(?:\$[PHS]\$[.\/0-9A-Za-z]{30,}|(?:\$wp)?\$2[abxy]\$\d{2}\$[.\/0-9A-Za-z]{53}|\$argon2(?:i|d|id)\$\S+|\$[156]\$\S{8,})\z/', trim( (string) $value ) );
	}

	/**
	 * Shapes that are a credential wherever they are stored: provider key formats, private key blocks,
	 * password hashes, application passwords and the shape of an AWS secret access key.
	 */
	private static function is_strong_secret_value( $value ) {
		$value = trim( (string) $value );
		return ( strlen( $value ) >= 8 && preg_match( '/^' . self::provider_pattern() . '$/', $value ) )
			|| preg_match( '/^-----BEGIN [A-Z ]*PRIVATE KEY-----/', $value ) || self::is_password_hash( $value )
			|| self::is_app_password( $value ) || self::is_aws_secret_shape( $value );
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
		// Publishable payment keys and identifiers that only look random.
		if ( preg_match( '/^pk_(?:live|test)_/', $value ) || self::is_uuid( $value ) || self::is_named_digest( $value ) ) {
			return false;
		}
		if ( preg_match( '/^' . self::provider_pattern() . '$/', $value ) ) {
			return true;
		}
		if ( self::is_app_password( $value ) || self::is_aws_secret_shape( $value ) ) {
			return true;
		}
		if ( preg_match( '/^-----BEGIN [A-Z ]*PRIVATE KEY-----/', $value ) ) {
			return true;
		}
		if ( self::is_password_hash( $value ) ) {
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

	/** A name for a WordPress nonce field ("nonce", "_wpnonce", "form_nonce") that names no other credential. */
	private static function is_nonce_name( $name ) {
		if ( ! preg_match( '/^(.*?)_?(?:wp_?)?nonce$/', ltrim( (string) $name, '_' ), $m ) ) {
			return false;
		}
		return '' === $m[1] || ! preg_match( '/key|token|secret|pass|pwd|auth|api|sess|cookie|licen|priv|client|salt|hmac|bearer|cred|sig/', $m[1] );
	}

	private static function is_falsy_sentinel( $v ) {
		return '' === $v || null === $v || [] === $v || '0' === $v || false === $v;
	}

	/** Whole numbers: an int, a list of ints / digit strings, or digits separated by spaces or commas. */
	private static function is_number_list( $v ) {
		if ( is_int( $v ) ) {
			return true;
		}
		if ( is_string( $v ) ) {
			return (bool) preg_match( '/^\d{1,19}(?:(?:[ 	]*,[ 	]*|[ 	]+)\d{1,19})*$/', trim( $v ) );
		}
		if ( ! is_array( $v ) || [] === $v ) {
			return false;
		}
		foreach ( $v as $item ) {
			if ( ! is_int( $item ) && ! ( is_string( $item ) && preg_match( '/^\d{1,19}$/', $item ) ) ) {
				return false;
			}
		}
		return true;
	}

	private static function name_is_secret( $key, array $opts ) {
		if ( '' === $key || in_array( $key, $opts['exempt_keys'], true ) ) {
			return false;
		}
		return self::is_secret_name( $key );
	}

	/**
	 * Replacements made so far in this request. A caller compares the count before and after a read
	 * to learn whether anything in it was hidden.
	 *
	 * @return int
	 */
	public static function change_count() {
		return self::$changes;
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
		// A list of record numbers under a plural ids name whose stem names no credential: pass_ids => [ 101, 102 ].
		if ( preg_match( '/^(.*?)_?ids$/', $name, $ids_stem ) && self::is_number_list( $value )
			&& ( in_array( $ids_stem[1], [ 'pass', 'passed', 'fail', 'failed' ], true ) || ! self::is_secret_name( $ids_stem[1] ) ) ) {
			return $value;
		}
		// A WordPress nonce: ten hex characters, valid for a day at most.
		if ( self::is_nonce_name( $name ) && is_string( $value ) && preg_match( '/^[0-9a-f]{10}$/', $value ) ) {
			return $value;
		}
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
		// A card number or a US social security number under a name that says so (also stored as a number).
		if ( '' !== $key_hint && ( is_int( $value ) || is_string( $value ) ) && self::is_personal_number_under_name( $key_hint, (string) $value ) ) {
			return self::hidden();
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
			// A public site key or publishable key stays readable; a secret key stored there by mistake does not.
			if ( '' !== $key_hint && self::is_public_key_name( self::normalize_name( $key_hint ) ) ) {
				return preg_match( '/(?<![A-Za-z0-9_\-])' . self::provider_pattern() . '(?![A-Za-z0-9_\-])|-----BEGIN [A-Z ]*PRIVATE KEY-----/', $value ) ? self::hidden() : $value;
			}
			if ( self::is_identifier_name( $key_hint ) ) {
				if ( self::is_password_hash( $value ) ) {
					return self::hidden();
				}
				// A digest key keeps a digest of a standard length (also a quoted or weak ETag);
				// anything else stored there is judged by its shape like any other value.
				if ( self::is_digest_name( $key_hint ) && ! self::fits_digest_name( $key_hint, $value ) ) {
					return self::scrub_string( $value, $opts, $depth );
				}
				// Hashes, commits, slugs and hook names: opened and scanned, not judged by their own shape.
				return self::scrub_string( $value, $opts, $depth, false );
			}
			if ( '' !== $key_hint && 'strict' === $opts['mode'] && in_array( $key_hint, $opts['content_keys'], true ) ) {
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

	/** Words near a number that make it a payment card number. */
	const CARD_WORDS = '/(?<![a-z])(?:cards?|visa|master[ \-]?card|amex|american[ \-]express|discover|credit|debit|cc|ccn|cvv|cvc|cvv2|exp|expiry|expires|expiration|pan)(?![a-z])/i';

	/** Words near a number that make it a US social security or tax id number. */
	const SSN_WORDS = '/(?<![a-z])(?:ssns?|social[ \-]security|tax[ \-]?id|taxpayer|itin|tin)(?![a-z])/i';

	/** Whether a value is a card number under a card name, or an SSN under an SSN / tax id name. */
	private static function is_personal_number_under_name( $key, $value ) {
		$name = str_replace( '_', ' ', self::normalize_name( $key ) );
		$v    = trim( $value );
		if ( 0 !== preg_match( self::CARD_WORDS, $name ) && preg_match( '/^\d[\d \-]{11,22}\d$/', $v ) && self::is_card_number( $v ) ) {
			return true;
		}
		return 0 !== preg_match( self::SSN_WORDS, $name ) && 1 === preg_match( '/^(?!000|666|9\d\d)\d{3}-?(?!00)\d{2}-?(?!0000)\d{4}$/', $v );
	}

	private static function walk_array( array $value, $key_hint, array $opts, $depth ) {
		// Attachment EXIF: location and device fields are shown as [hidden] (a write holding it is refused).
		// Empty defaults stay as they are; orientation says nothing about the photographer and stays readable.
		if ( 'image_meta' === $key_hint ) {
			foreach ( [ 'latitude', 'longitude', 'camera', 'aperture', 'iso', 'shutter_speed', 'focal_length', 'created_timestamp' ] as $exif ) {
				foreach ( array_keys( $value ) as $k ) {
					if ( strtolower( (string) $k ) === $exif && ! self::is_falsy_sentinel( $value[ $k ] ) && 0 !== $value[ $k ] && 0.0 !== $value[ $k ] ) {
						$value[ $k ] = '[hidden]';
						self::$changes++;
					}
				}
			}
		}

		// The nearest named block decides whether "pass" here is a test result; containers and list items pass it on.
		$parent = self::$named_parent;
		if ( '' !== $key_hint && 'hash' !== $key_hint && ! preg_match( '/^(?:values?|data|meta_value|option_value|body|payload|json|items?|rows?|entries|list)$/', self::normalize_name( $key_hint ) ) ) {
			self::$named_parent = $key_hint;
		}
		try {
			return self::walk_block( $value, $key_hint, $opts, $depth );
		} finally {
			self::$named_parent = $parent;
		}
	}

	/** walk_array() for one block, with the nearest named block above it set. */
	private static function walk_block( array $value, $key_hint, array $opts, $depth ) {
		// Test counts and record numbers under "pass" when the block is test results, not a login.
		$outcome_keys = self::outcome_pass_keys( $value, self::$named_parent );
		// Items of a list of digests ("hashes", "checksums", "sums") are judged as digests.
		$item_hint = '' !== $key_hint && preg_match( '/(^|_)(hashes|checksums|sums|shas|digests|etags|fingerprints|md5s)$/', self::normalize_name( $key_hint ) ) ? 'hash' : '';
		// A name-to-values map (name_list_items): the values in each top-level list take the list's name.
		if ( '' === $item_hint && 1 === $depth && '' !== $key_hint && ! empty( $opts['name_list_items'] )
			&& [] !== $value && array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
			$item_hint = $key_hint;
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

		// Name/value rows: [ { "name": "api_key", "value": "..." } ], database rows
		// { "option_name": "...", "option_value": "..." }, and field
		// definitions whose default is the stored value: { "name": "api_key", "default_value": "..." }.
		$row_secret = false;
		foreach ( [ 'name', 'key', 'field', 'param', 'header', 'option', 'option_name', 'meta_key', 'id' ] as $nk ) {
			if ( isset( $value[ $nk ] ) && is_string( $value[ $nk ] ) && self::is_secret_name( $value[ $nk ] )
				&& ( 'id' !== $nk || ! self::is_weak_name( $value[ $nk ] ) ) ) {
				$row_secret = $value[ $nk ];
				break;
			}
		}
		// A label naming a code, PIN, password or key: { "key": "Door code", "value": "4821" },
		// { "label": "API Key", "value": "..." }. Only the value field beside it is hidden.
		$label_secret = false;
		if ( false === $row_secret ) {
			foreach ( [ 'label', 'name', 'key', 'title', 'field', 'param' ] as $nk ) {
				if ( isset( $value[ $nk ] ) && is_string( $value[ $nk ] ) && self::label_names_credential( $value[ $nk ] ) ) {
					$label_secret = true;
					break;
				}
			}
		}
		// A password-type field holds a password whatever it is called.
		if ( false === $row_secret && ( ( isset( $value['type'] ) && 'password' === $value['type'] ) || ( isset( $value['field_type'] ) && 'password' === $value['field_type'] ) ) ) {
			$row_secret = 'password';
		}
		// A row naming a digest: { "key": "content_hash", "value": "<sha256>" } (a single meta
		// read). The value beside it is judged as it is under that name: a digest of a standard
		// length is kept, anything else is checked as usual.
		$row_digest = false;
		if ( false === $row_secret && ! $label_secret ) {
			foreach ( [ 'key', 'name', 'meta_key', 'option_name', 'field' ] as $nk ) {
				if ( isset( $value[ $nk ] ) && is_string( $value[ $nk ] ) ) {
					$row_digest = self::is_digest_name( $value[ $nk ] ) && self::is_identifier_name( $value[ $nk ] );
					break;
				}
			}
		}

		// A one-letter password beside a login name: { "u": "admin", "p": "..." }.
		$short_pw = array_key_exists( 'p', $value ) && [] !== array_intersect( [ 'u', 'user', 'usr', 'username', 'login' ], array_keys( $value ) );

		$out = [];
		foreach ( $value as $k => $v ) {
			if ( $short_pw && 'p' === $k ) {
				$out[ $k ] = self::value_under_secret_name( $v, 'password' );
				continue;
			}
			if ( false !== $row_secret && is_string( $k ) && in_array( $k, [ 'value', 'val', 'data', 'content', 'secret', 'default', 'default_value', 'option_value', 'meta_value' ], true ) ) {
				$out[ $k ] = self::value_under_secret_name( $v, $row_secret );
				continue;
			}
			if ( $row_digest && is_string( $k ) && in_array( $k, [ 'value', 'meta_value', 'option_value' ], true ) && is_string( $v ) && self::is_digest_hex( $v ) ) {
				$out[ $k ] = $v;
				continue;
			}
			if ( $label_secret && is_string( $k ) && in_array( $k, [ 'value', 'val' ], true ) ) {
				$out[ $k ] = self::value_under_secret_name( $v, 'password' );
				continue;
			}
			if ( [] !== $pair_prefixes && is_string( $k ) && self::is_credential_partner( $k, $pair_prefixes )
				&& ! in_array( $k, $opts['exempt_keys'], true ) && ! self::is_falsy_sentinel( $v ) ) {
				$out[ $k ] = self::hidden();
				continue;
			}
			if ( isset( $outcome_keys[ $k ] ) ) {
				$out[ $k ] = $v;
				continue;
			}
			// A file manifest: { "dist/app.js": "<sha256>" }.
			if ( is_string( $k ) && is_string( $v ) && self::is_digest_hex( $v ) && self::is_file_name_key( $k ) ) {
				$out[ $k ] = $v;
				continue;
			}
			$out[ $k ] = self::walk( $v, is_int( $k ) ? $item_hint : (string) $k, $opts, $depth + 1 );
		}
		return $out;
	}

	/** A key that is a file name or path with an extension ("dist/app.js"), naming no credential. */
	private static function is_file_name_key( $k ) {
		return strlen( $k ) <= 255 && 1 === preg_match( '/^[\w\-.\/@]*[\w\-]\.[A-Za-z][A-Za-z0-9]{0,7}$/', $k )
			&& 0 === preg_match( self::CREDENTIAL_WORDS, $k ) && 0 === preg_match( '/(?<![a-z])(?:id_rsa|id_dsa|id_ecdsa|id_ed25519|pem|ppk|p12|pfx|jks|keystore|htpasswd|netrc|pgpass)(?![a-z])/i', $k );
	}

	/**
	 * Keys of a block whose "pass" / "passed" value is a test result, not a password: whole numbers
	 * ("pass": [101, 102], "pass": "101 102", or one count beside fail / skipped / total), or an
	 * outcome line ("PASS: 101"). Only in a test context: the nearest named block above is test
	 * vocabulary ("qa", "smoke_results", "tests"), or there is no named block above and a key beside it
	 * is fail / failed / skipped / errors / total. A named block that is anything else ("safe", "phone",
	 * "wifi") or another key of the block naming a login or a credential makes the value a password.
	 *
	 * @param array  $value  Block.
	 * @param string $parent Nearest named block above ('' for none).
	 * @return array<int|string,bool>
	 */
	private static function outcome_pass_keys( array $value, $parent = '' ) {
		$found = [];
		$test  = false;
		foreach ( $value as $k => $v ) {
			if ( ! is_string( $k ) ) {
				continue;
			}
			$name = self::normalize_name( $k );
			if ( 'pass' === $name || 'passed' === $name ) {
				$found[ $k ] = $v;
				continue;
			}
			if ( preg_match( '/^(?:fail|failed|failures?|fails|skip|skipped|skips|errors?|total)$/', $name ) ) {
				$test = true;
				continue;
			}
			// A login or credential beside it: the value is a password.
			if ( 0 !== preg_match( self::LOGIN_WORDS, str_replace( '_', ' ', $name ) ) || self::is_secret_name( $k ) ) {
				return [];
			}
		}
		if ( [] === $found ) {
			return [];
		}
		$words = str_replace( [ '_', '-', '.' ], ' ', self::normalize_name( $parent ) );
		if ( '' !== $parent ) {
			if ( 0 !== preg_match( self::LOGIN_WORDS, $words ) || 1 !== preg_match( '/(?<![a-z0-9])' . self::TEST_WORDS . '(?![a-z0-9])/', $words ) ) {
				return [];
			}
		} elseif ( ! $test ) {
			return [];
		}
		$out = [];
		foreach ( $found as $k => $v ) {
			if ( self::is_number_list( $v )
				|| ( is_string( $v ) && preg_match( '/^(?:pass(?:ed)?|fail(?:ed)?)[ \t]*[:=][ \t]*#?\d{1,12}(?:(?:[ \t]*,[ \t]*|[ \t]+)#?\d{1,12})*$/i', trim( $v ) ) ) ) {
				$out[ $k ] = true;
			}
		}
		return $out;
	}

	/**
	 * Whether a row label names a code, PIN, password or key ("Door code", "Gate code", "API Key",
	 * "WiFi password"), so the value beside it is a credential. Short labels only; codes that are not
	 * secrets (zip, country, currency, tracking, embed, CSS / HTML / JS, discount, error) do not count.
	 *
	 * @param string $label Label text.
	 */
	private static function label_names_credential( $label ) {
		if ( strlen( $label ) > 60 ) {
			return false;
		}
		$words = trim( (string) preg_replace( '/[\s_\-.:]+/', ' ', self::normalize_name( $label ) ) );
		if ( '' === $words || substr_count( $words, ' ' ) > 5 ) {
			return false;
		}
		if ( self::is_secret_name( str_replace( ' ', '_', $words ) ) ) {
			return true;
		}
		if ( 1 === preg_match( '/(?<![a-z0-9])(?:pins?|passcodes?|passwords?|passwd|pass|pwd|secrets?|tokens?)(?![a-z0-9])(?! (?:types?|sizes?|style|count|colou?r|board|badge|level|holder|name|length|policy|label|field|hint|strength|reset|expiry|rate)(?![a-z0-9]))/', $words ) ) {
			return true;
		}
		if ( 1 === preg_match( '/(?<![a-z0-9])(?:api|access|secret|private|licen[cs]e|activation|serial|product|auth|signing|encryption|app|client|master|ssh|gpg|pgp|wi ?fi|registration|account|door|gate|alarm|safe)[ ]?keys?(?![a-z0-9])/', $words ) ) {
			return true;
		}
		return 1 === preg_match( '/(?<![a-z0-9])codes?(?![a-z0-9])/', $words )
			&& 0 === preg_match( '/(?<![a-z0-9])(?:zip|postal|post|country|area|region|state|currency|language|lang|locale|colou?r|hex|sku|item|tracking|status|error|response|http|short|source|embed|html|css|js|javascript|php|custom|snippet|script|pixel|analytics|header|footer|head|body|ads?|adsense|gtm|discount|coupon|promo|voucher|gift|tax|class|dial|calling|phone|iso|hs|naics|sic|font|style|unique|qr|bar|reference|ref|order|invoice|product|shipping|carrier|reason|exit)(?![a-z0-9])/', $words );
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
		if ( $shape && ( 'as_stored' === $opts['mode'] ? self::is_strong_secret_value( $s ) : self::is_secret_value( $s ) ) ) {
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
					return false === $json ? self::hidden() : self::restore_bigints( $json, $s );
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
			// A provider key or private key block, encoded.
			if ( preg_match( '/(?<![A-Za-z0-9_\-])' . self::provider_pattern() . '(?![A-Za-z0-9_\-])|-----BEGIN [A-Z ]*PRIVATE KEY-----/', $bin ) || self::is_app_password( trim( $bin ) ) ) {
				return true;
			}
			// An option returned as stored gets no free-text checks.
			if ( 'as_stored' === $opts['mode'] ) {
				return false;
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

	/**
	 * Integers too large for PHP come back from json_decode as strings. Put back as numbers only
	 * the values that were bare numbers in the original: the scalar tokens of the original and of
	 * the rewritten JSON are matched one to one, in order (decode and encode keep keys and order),
	 * and a token is restored only when it was a bare integer there and is the same digits here.
	 *
	 * @param string $json     Rewritten JSON.
	 * @param string $original JSON as it was read.
	 * @return string
	 */
	private static function restore_bigints( $json, $original ) {
		$rx = '/"(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|true|false|null/';
		if ( ! preg_match( '/(?<![\w.+-])-?\d{19,}(?![\w.])/', $original ) ) {
			return $json;
		}
		if ( ! preg_match_all( $rx, $original, $in ) || ! preg_match_all( $rx, $json, $out, PREG_OFFSET_CAPTURE ) ) {
			return $json;
		}
		if ( count( $in[0] ) !== count( $out[0] ) ) {
			return $json;
		}
		$result = '';
		$pos    = 0;
		foreach ( $out[0] as $i => $tok ) {
			list( $text, $at ) = $tok;
			$was = (string) $in[0][ $i ];
			$result .= substr( $json, $pos, $at - $pos );
			$pos     = $at + strlen( $text );
			if ( preg_match( '/^-?\d{19,}$/', $was ) && '"' . $was . '"' === $text ) {
				$result .= $was;
			} else {
				$result .= $text;
			}
		}
		return $result . substr( $json, $pos );
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
	private static function rx( $pattern, $replacement, $subject, $offsets = false ) {
		$run = static function ( $p ) use ( $replacement, $subject, $offsets ) {
			if ( ! is_callable( $replacement ) ) {
				return preg_replace( $p, $replacement, $subject );
			}
			if ( ! $offsets ) {
				return preg_replace_callback( $p, $replacement, $subject );
			}
			// The callback gets match offsets and the whole text, to look around the match.
			return preg_replace_callback(
				$p,
				static function ( $x ) use ( $replacement, $subject ) {
					return $replacement( $x, $subject );
				},
				$subject,
				-1,
				$count,
				PREG_OFFSET_CAPTURE
			);
		};
		$out = $run( $pattern );
		if ( null === $out && PREG_JIT_STACKLIMIT_ERROR === preg_last_error() ) {
			// The JIT setting applies when a pattern is compiled, and the failed pattern is
			// already cached: an empty group makes it a new pattern, compiled without the JIT.
			$fresh = $pattern[0] . '(?:)' . substr( $pattern, 1 );
			$jit   = ini_get( 'pcre.jit' );
			ini_set( 'pcre.jit', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restored straight after
			$out = $run( $fresh );
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
		// A WordPress nonce stays readable.
		if ( self::is_nonce_name( self::normalize_name( $name ) ) && preg_match( '/^[0-9a-f]{10}$/', $value ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether what follows the word bearer is a credential rather than a word of a sentence.
	 * Anything with a digit or an inner capital is one, and so is a long unbroken word. Words
	 * joined by hyphens or dots are a phrase unless a piece has too few vowels to be a word.
	 *
	 * @param string $value Text after the word.
	 * @return bool
	 */
	private static function is_bearer_value( $value ) {
		$value = rtrim( $value, '.-_~+/=:' );
		if ( strlen( $value ) < 8 || in_array( strtolower( $value ), self::BEARER_PROSE, true ) ) {
			return false;
		}
		if ( 1 === preg_match( '/\d|[a-z][A-Z]/', $value ) ) {
			return true;
		}
		$pieces = preg_split( '/[._~\-]+/', $value );
		if ( count( $pieces ) < 2 ) {
			return true;
		}
		foreach ( $pieces as $piece ) {
			if ( strlen( $piece ) >= 2 && preg_match_all( '/[aeiouy]/i', $piece ) / strlen( $piece ) < 0.2 ) {
				return true;
			}
		}
		return false;
	}

	/** Whether digits (spaces and dashes allowed) are a payment card number: 13 to 19 digits that pass the Luhn check. */
	private static function is_card_number( $value ) {
		$digits = preg_replace( '/[ \-]/', '', (string) $value );
		$len    = strlen( $digits );
		if ( $len < 13 || $len > 19 || ! ctype_digit( $digits ) ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$d = (int) $digits[ $len - 1 - $i ];
			if ( 1 === $i % 2 ) {
				$d *= 2;
				if ( $d > 9 ) {
					$d -= 9;
				}
			}
			$sum += $d;
		}
		return 0 === $sum % 10;
	}

	/** Whether a value looks generated: letters and digits mixed, not words joined by - _ or . */
	private static function looks_generated( $value ) {
		$value = rtrim( (string) $value, '.,;:!?)' );
		if ( 0 === strpos( $value, '[REDACTED' ) ) {
			return false;
		}
		return self::is_secret_value( $value )
			|| ( strlen( $value ) >= 8 && preg_match( '/\d/', $value ) && preg_match( '/[A-Za-z]/', $value ) && ! self::looks_like_words( $value ) && ! preg_match( '#^(?:https?:|/|\d+(?:\.\d+)+$)#i', $value ) );
	}

	/**
	 * Whether the value after "password is", "API key was" and the like is a credential rather
	 * than a word of the sentence ("is required", "is %s"). Strict mode hides any value with a
	 * digit, a symbol or a capital inside; content mode only one that looks generated.
	 *
	 * @param string $value  Value after the label.
	 * @param bool   $strict Strict mode.
	 * @param string $label  The label and joining words before the value.
	 */
	private static function prose_value_is_secret( $value, $strict, $label = '' ) {
		$value = rtrim( (string) $value, '.,;:!?)' );
		if ( '' === $value || 0 === strpos( $value, '[REDACTED' ) || preg_match( '/^(?:%[\d$]*[a-z]|\{[^}]*\}|<[^>]*>|\[[^\]]*\]|\$\w+|\.{2,}|x{3,}|\*+)$/i', $value ) ) {
			return false;
		}
		if ( preg_match( '/^pin\b/i', ltrim( $label ) ) ) {
			return (bool) preg_match( '/^\d{4,8}$/', $value );
		}
		if ( ! $strict ) {
			return self::looks_generated( $value );
		}
		if ( strlen( $value ) < 6 ) {
			return false;
		}
		return (bool) preg_match( '/\d|[^A-Za-z]|[a-z][A-Z]/', $value );
	}

	/** Lower-case words that start a sentence, not a value: "Key: the main entrance is on the left." */
	private static function is_prose_word_value( $value, $subject, $end ) {
		static $words = [ 'the', 'a', 'an', 'is', 'are', 'was', 'see', 'to', 'of', 'in', 'on', 'for', 'and', 'or', 'this', 'that', 'your', 'our', 'my', 'it', 'we', 'you', 'not', 'no', 'use', 'ask', 'check', 'call', 'contact', 'stored', 'kept', 'saved', 'set', 'same', 'none', 'please', 'when', 'if', 'with', 'from', 'at', 'by', 'its', 'their' ];
		return in_array( $value, $words, true ) && 1 === preg_match( '/^[ \t]+[A-Za-z]/', (string) substr( $subject, $end, 8 ) );
	}

	/** Whether a position sits in the query or fragment of a URL (already checked by scrub_url()). */
	private static function inside_url( $subject, $at ) {
		$from  = max( 0, $at - 4096 );
		$chunk = ' ' . substr( $subject, $from, $at - $from );
		// The same characters end a URL here as in the URL step (a backslash included).
		if ( ! preg_match( '/[\s"\'<>\\\\]([^\s"\'<>\\\\]*)$/', $chunk, $mm ) ) {
			return false;
		}
		return 1 === preg_match( '#[a-z][a-z0-9+.\-]{1,15}:(?:/|\\\\/)#i', $mm[1] );
	}

	/**
	 * Whether a cell of a credential column is hidden. A column headed only "key" holds names unless
	 * its values look generated; a "pass" column of counts, record numbers or outcome words is a test
	 * result unless the table also has a login or credential column.
	 *
	 * @param string $name  Column header.
	 * @param string $cell  Cell text.
	 * @param bool   $login Whether the header names a login or another credential.
	 * @param bool   $test  Whether another header cell is test vocabulary or an outcome (suite, fail, total).
	 */
	private static function table_cell_is_secret( $name, $cell, $login, $test = false ) {
		$col = self::normalize_name( $name );
		if ( 'key' === $col || 'keys' === $col ) {
			return self::looks_generated( $cell ) && self::pair_value_is_secret( $name, $cell );
		}
		if ( ! $login && $test && in_array( $col, [ 'pass', 'passed' ], true )
			&& preg_match( '/^(?:#?\d{1,12}(?:\/\d{1,12})?(?:(?:[ \t]*,[ \t]*|[ \t]+)#?\d{1,12})*|yes|no|y|n|true|false|ok|pass|passed|fail|failed|skip|skipped|x|-|n\/a|\xE2\x9C\x93|\xE2\x9C\x94|\xE2\x9C\x97|\xE2\x9C\x98)$/i', $cell ) ) {
			return false;
		}
		return self::pair_value_is_secret( $name, $cell );
	}

	/**
	 * Hide the cells of credential columns in CSV, tab-separated and markdown tables: a header
	 * line whose cells are all names, one of them a credential name, then rows with the same
	 * number of cells.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function scrub_tables( $text ) {
		if ( false === strpos( $text, "\n" ) || ! preg_match( '/[,|\t]/', $text ) ) {
			return $text;
		}
		$lines  = preg_split( '/(\r?\n)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$header = null;
		$cols   = [];
		$sep    = '';
		$width  = 0;
		$login  = false;
		$test   = false;
		$split  = static function ( $line, $sep ) {
			if ( '|' === $sep ) {
				$t = trim( $line );
				if ( '' === $t || '|' !== $t[0] ) {
					return null;
				}
				return explode( '|', trim( $t, '|' ) );
			}
			return false === strpos( $line, $sep ) ? null : explode( $sep, $line );
		};
		for ( $i = 0, $n = count( $lines ); $i < $n; $i += 2 ) {
			$line = $lines[ $i ];
			if ( null !== $header ) {
				$cells = $split( $line, $sep );
				if ( null === $cells || count( $cells ) !== $width ) {
					$header = null;
				} elseif ( '|' === $sep && preg_match( '/^\s*\|?(?:\s*:?-{2,}:?\s*\|)+\s*:?-*:?\s*$/', $line ) ) {
					continue;
				} else {
					$changed = false;
					foreach ( $cols as $c => $name ) {
						$cell = trim( $cells[ $c ] );
						if ( self::table_cell_is_secret( $name, $cell, $login, $test ) ) {
							$cells[ $c ] = str_replace( $cell, self::MARKER, $cells[ $c ] );
							$changed     = true;
						}
					}
					if ( $changed ) {
						$lead        = strlen( $line ) - strlen( ltrim( $line ) );
						$lines[ $i ] = '|' === $sep ? substr( $line, 0, $lead ) . '|' . implode( '|', $cells ) . '|' : implode( $sep, $cells );
					}
					continue;
				}
			}
			foreach ( [ '|', "\t", ',' ] as $try ) {
				$cells = $split( $line, $try );
				if ( null === $cells || count( $cells ) < 2 ) {
					continue;
				}
				$found     = [];
				$row_login = false;
				$row_test  = false;
				foreach ( $cells as $c => $cell ) {
					$cell = trim( $cell );
					if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9 _\-]{0,40}$/', $cell ) ) {
						$found = null;
						break;
					}
					$cell_name = self::normalize_name( $cell );
					if ( self::is_secret_name( $cell ) ) {
						$found[ $c ] = $cell;
						if ( ! in_array( $cell_name, [ 'pass', 'passed' ], true ) ) {
							$row_login = true;
						}
					} elseif ( 0 !== preg_match( self::LOGIN_WORDS, str_replace( '_', ' ', $cell_name ) ) ) {
						$row_login = true;
					} elseif ( 1 === preg_match( '/(?<![a-z0-9])(?:' . substr( self::TEST_WORDS, 3, -1 ) . '|fail(?:ed|ures?|s)?|skip(?:ped|s)?|errors?)(?![a-z0-9])/', str_replace( '_', ' ', $cell_name ) ) ) {
						$row_test = true;
					}
				}
				if ( $found ) {
					$header = $line;
					$cols   = $found;
					$sep    = $try;
					$width  = count( $cells );
					$login  = $row_login;
					$test   = $row_test;
					break;
				}
			}
		}
		return implode( '', $lines );
	}

	/**
	 * Text steps for card numbers and US social security numbers. Each is hidden only with a word on
	 * its line, or on a label line just above it, that says what it is (card, visa, credit, cvv, exp /
	 * ssn, social security, tax id): order, tracking, invoice and phone numbers of the same shape stay.
	 *
	 * @return array<int,array> Steps for scrub_text() (pattern, callback, with offsets).
	 */
	private static function personal_number_steps() {
		$m       = self::MARKER;
		$context = static function ( $words, $subject, $at ) {
			list( , $line, , $near ) = self::line_at( $subject, $at );
			// A failed check counts as a match.
			return 0 !== preg_match( $words, $line ) || ( 0 !== preg_match( '/:[ \t]*$/', $near ) && 0 !== preg_match( $words, $near ) );
		};
		return [
			[
				'/(?<![\d\-.])(?:[2-6]\d{12,18}|[2-6]\d{3}(?:[ \-]\d{4}){3}(?:[ \-]\d{1,3})?|3\d{3}[ \-]\d{6}[ \-]\d{5})(?![\d\-]|\.\d)/',
				static function ( $x, $subject ) use ( $m, $context ) {
					return self::is_card_number( $x[0][0] ) && $context( self::CARD_WORDS, $subject, $x[0][1] ) ? $m : $x[0][0];
				},
				true,
			],
			[
				'/(?<![\d\-])(?!000|666|9\d\d)\d{3}-(?!00)\d{2}-(?!0000)\d{4}(?![\d\-])/',
				static function ( $x, $subject ) use ( $m, $context ) {
					return $context( self::SSN_WORDS, $subject, $x[0][1] ) ? $m : $x[0][0];
				},
				true,
			],
		];
	}

	/** The text step for an AWS secret access key: 40 characters of letters, digits, / and +, mixed case and digits. */
	private static function aws_secret_step() {
		$m = self::MARKER;
		return [
			'/(?<![A-Za-z0-9\/+=])[A-Za-z0-9\/+]{40}(?![A-Za-z0-9\/+=])/',
			static function ( $x ) use ( $m ) {
				return preg_match( '/[A-Z]/', $x[0] ) && preg_match( '/[a-z]/', $x[0] ) && preg_match( '/\d/', $x[0] ) ? $m : $x[0];
			},
		];
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
		// An option the site owner has returned as stored: only private key blocks, provider key
		// formats and credentials in URLs; none of the free-text checks.
		if ( 'as_stored' === $mode ) {
			$m   = self::MARKER;
			$out = self::rx( '/-----BEGIN ([A-Z ]*)PRIVATE KEY-----.*?-----END \1PRIVATE KEY-----/s', $m, $s );
			// Encoded text in a data URI that holds a provider key, a private key block or an application password.
			$out = null === $out || false === stripos( $out, ';base64,' ) ? $out : self::rx(
				'#(data:[\w.+\-]+/[\w.+\-]+(?:;[\w\-]+=[\w.\-]+)*;base64,)([A-Za-z0-9+/]+={0,2})#i',
				static function ( $x ) use ( $m ) {
					$bin = base64_decode( $x[2], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading a value to check it
					if ( is_string( $bin ) && ( 0 !== preg_match( '/(?<![A-Za-z0-9_\-])' . self::provider_pattern() . '(?![A-Za-z0-9_\-])|-----BEGIN [A-Z ]*PRIVATE KEY-----/', $bin ) || self::is_app_password( trim( $bin ) ) ) ) {
						return $x[1] . $m;
					}
					return $x[0];
				},
				$out
			);
			// Credential headers, Basic and bearer credentials, card numbers and SSNs with their context word,
			// application passwords, and AWS secret keys in text that mentions AWS.
			$as_stored_steps = [
				[ '/\b((?:proxy-)?authorization[ \t]*:[ \t]*(?:(?:Bearer|Basic|Token|Digest|Bot|ApiKey)[ \t]+)?)[A-Za-z0-9._~+\/=\-]{8,}/i', '$1' . $m ],
				[ '/\b((?:x-api-key|x-auth-token|api-key|x-access-token)[ \t]*:[ \t]*"?)[^\s"]{8,}/i', '$1' . $m ],
				[ '/\b(Basic[ \t]+)[A-Za-z0-9+\/]{12,}={0,2}(?![A-Za-z0-9+\/=])/', '$1' . $m ],
				[
					self::BARE_BEARER,
					static function ( $x ) use ( $m ) {
						return self::is_bearer_value( $x[2] ) ? $x[1] . ' ' . $m : $x[0];
					},
				],
				[
					'/(?<![A-Za-z0-9])[A-Za-z0-9]{4}(?: [A-Za-z0-9]{4}){5}(?![A-Za-z0-9])/',
					static function ( $x ) use ( $m ) {
						return self::is_app_password( $x[0] ) ? $m : $x[0];
					},
				],
			];
			$as_stored_steps = array_merge( $as_stored_steps, self::personal_number_steps() );
			if ( 0 !== preg_match( '/AKIA|ASIA|(?<![A-Za-z])(?i:aws)(?![A-Za-z])/', $s ) ) {
				$as_stored_steps[] = self::aws_secret_step();
			}
			foreach ( $as_stored_steps as $step ) {
				$out = null === $out ? null : self::rx( $step[0], $step[1], $out, ! empty( $step[2] ) );
			}
			$out = null === $out ? null : self::rx( '/(?<![A-Za-z0-9_\-])' . self::provider_pattern() . '(?![A-Za-z0-9_\-])/', $m, $out );
			$out = null === $out ? null : self::rx(
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
				$out
			);
			return null === $out ? self::MARKER : $out;
		}
		// Nothing that could hold a credential: no separator, URL, dump, markup, bearer word or key prefix.
		// A check that fails to run counts as a hit, so the text is still checked.
		if ( strlen( $s ) < 8 || 0 === preg_match( '/[:=@\/\["\'<%&,|\t\n]|(?i:bearer|token[ \t]|(?:key|password|passcode|passphrase|secret|token|pin)[ \t]+(?:is|was)\b|[ \t](?:-|\xE2\x80\x93|\xE2\x80\x94)[ \t])|-----BEGIN|(?:sk|rk)_|whsec_|SG\.|xox|gh[pousr]_|github_pat_|glpat-|AKIA|AIza|ya29\.|key-|EAA|sq0|shp|npm_|eyJ|Basic |sk-|hf_|pplx-|gsk_|xai-|dop_v1_|SK[0-9a-f]{8}|-us\d|wc_order_|\d{3}-\d{2}-\d{4}|\d{4}[ \-]?\d{4}|[A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4}|[A-Za-z0-9_\-]{40}|[A-Za-z0-9\/+]{40}/', $s ) ) {
			return $s;
		}
		// A whole string of base64 is data, not text with name=value pairs in it.
		if ( 0 !== stripos( $s, 'bearer' ) && preg_match( '/^(?:[A-Za-z0-9+\/]{16,}={0,2}|[A-Za-z0-9+\/]{8,}={1,2})$/', $s ) ) {
			return $s;
		}
		$strict = 'content' !== $mode;
		$m      = self::MARKER;
		$pre    = [];

		// Private key blocks.
		$pre[] = [ '/-----BEGIN ([A-Z ]*)PRIVATE KEY-----.*?-----END \1PRIVATE KEY-----/s', $m ];

		// Credential headers: the credential part only.
		$pre[] = [ '/\b((?:proxy-)?authorization[ \t]*:[ \t]*(?:(?:Bearer|Basic|Token|Digest|Bot|ApiKey)[ \t]+)?)[A-Za-z0-9._~+\/=\-]{8,}/i', '$1' . $m ];
		$pre[] = [ '/\b((?:x-api-key|x-auth-token|api-key|x-access-token)[ \t]*:[ \t]*"?)[^\s"]{8,}/i', '$1' . $m ];
		$pre[] = [ '/\b(Basic[ \t]+)[A-Za-z0-9+\/]{12,}={0,2}(?![A-Za-z0-9+\/=])/', '$1' . $m ];
		if ( $strict ) {
			// A bare bearer credential in free text, in any case and after any separator.
			$pre[] = [
				self::BARE_BEARER,
				static function ( $x ) use ( $m ) {
					return self::is_bearer_value( $x[2] ) ? $x[1] . ' ' . $m : $x[0];
				},
			];
			// The word token before a value with letters and digits; prose rarely has one.
			$pre[] = [ '/(?<![A-Za-z0-9_\-])(token)[ \t]+(?=[A-Za-z0-9._~+\/=\-]*\d)(?=[A-Za-z0-9._~+\/=\-]*[A-Za-z])[A-Za-z0-9._~+\/=\-]{16,}/i', '$1 ' . $m ];
			$pre[] = [
				'/\b((?:set-)?cookie[ \t]*:[ \t]*)([^\r\n]+)/i',
				static function ( $x ) use ( $m ) {
					return $x[1] . preg_replace( '/([A-Za-z0-9_\-.]+[ \t]*=[ \t]*)(?![A-Za-z]{1,12}(?:[;\s]|$))[A-Za-z0-9._\-+\/=%]{8,}/', '$1' . $m, $x[2] );
				},
			];
		}

		// Provider key formats, card numbers, US social security numbers, application passwords.
		$pre[] = [ '/(?<![A-Za-z0-9_\-])' . self::provider_pattern() . '(?![A-Za-z0-9_\-])/', $m ];
		foreach ( self::personal_number_steps() as $step ) {
			$pre[] = $step;
		}
		if ( $strict ) {
			// The password in SQL: IDENTIFIED BY 'x', IDENTIFIED WITH plugin BY 'x'.
			$pre[] = [ '/(\bIDENTIFIED[ \t]+(?:WITH[ \t]+\S+[ \t]+)?BY[ \t]+(?:PASSWORD[ \t]*\(?[ \t]*)?)(["\'])((?:(?!\2)[^\\\\\r\n]|\\\\.)*+)\2/i', '$1$2' . $m . '$2' ];
		}
		$pre[] = [
			'/(?<![A-Za-z0-9])[A-Za-z0-9]{4}(?: [A-Za-z0-9]{4}){5}(?![A-Za-z0-9])/',
			static function ( $x ) use ( $m ) {
				return self::is_app_password( $x[0] ) ? $m : $x[0];
			},
		];
		// An AWS secret access key (40 characters, / and + allowed) in text that mentions AWS or holds an access key ID.
		if ( 0 !== preg_match( '/AKIA|ASIA|(?<![A-Za-z])(?i:aws)(?![A-Za-z])/', $s ) ) {
			$pre[] = self::aws_secret_step();
		}

		// Credentials named in a sentence: "the API key is X", "password "X"", "token - X",
		// and a value alone on the line after a label line ("API key:" then the key).
		$labels = '(?:api[ _\-]?key|access[ _\-]?key|secret(?:[ _\-]?key)?|password|passcode|passphrase|passwd|token|license[ _\-]?key|licence[ _\-]?key|pin)';
		$pre[]  = [
			'/(?<![A-Za-z0-9_])(' . $labels . '[ \t]+(?:is|was)[ \t]*:?[ \t]*)(["\'`]?)([^\s"\'`<>]{4,}?)\2([.,;:!?)]*)(?=\s|$)/i',
			static function ( $x ) use ( $m, $strict ) {
				return self::prose_value_is_secret( $x[3], $strict, $x[1] ) ? $x[1] . $x[2] . $m . $x[2] . $x[4] : $x[0];
			},
		];
		$pre[] = [
			'/(?<![A-Za-z0-9_])(' . $labels . '[ \t]+)(["\'`])([^"\'`\s]{6,})\2/i',
			static function ( $x ) use ( $m, $strict ) {
				return self::prose_value_is_secret( $x[3], $strict, $x[1] ) ? $x[1] . $x[2] . $m . $x[2] : $x[0];
			},
		];
		$pre[] = [
			'/(?<![A-Za-z0-9_])(' . $labels . '[ \t]+(?:-|\xE2\x80\x93|\xE2\x80\x94)[ \t]+)([^\s"\']{8,})/i',
			static function ( $x ) use ( $m ) {
				return self::looks_generated( $x[2] ) ? $x[1] . $m : $x[0];
			},
		];
		$pre[] = [
			'/(^|\n)([ \t]*([A-Za-z][A-Za-z0-9 _\-]{1,40}?)[ \t]*:[ \t]*\r?\n[ \t]*)([^\s]{8,})(?=[ \t]*(?:\r?\n|$))/',
			static function ( $x ) use ( $m ) {
				$label = preg_replace( '/^(?:your|the|my|our)[ \t]+/i', '', trim( $x[3] ) );
				return self::is_secret_name( $label ) && self::looks_generated( $x[4] ) ? $x[1] . $x[2] . $m : $x[0];
			},
		];
		if ( $strict ) {
			// Tables (CSV, tab separated, markdown) whose header names a credential column.
			$pre[] = [ '/\A[\s\S]*\z/', static function ( $x ) { return self::scrub_tables( $x[0] ); } ];
		}

		$steps = [];

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
		// The same URL with one slash, the form a path clean-up leaves.
		$steps[] = [
			'#\b[a-z][a-z0-9+.\-]{1,15}:/(?!/)[^\s"\'<>\\\\]+#i',
			static function ( $x ) {
				$url  = $x[0];
				$tail = '';
				if ( preg_match( '/[).,;:!?]+$/', $url, $tm ) ) {
					$tail = $tm[0];
					$url  = substr( $url, 0, -strlen( $tail ) );
				}
				$at    = strpos( $url, ':/' );
				$full  = substr( $url, 0, $at ) . '://' . substr( $url, $at + 2 );
				$clean = self::scrub_url( $full );
				return ( $clean === $full ? $url : substr( $clean, 0, $at ) . ':/' . substr( $clean, $at + 3 ) ) . $tail;
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
			// A pair inside a URL's query was already checked by scrub_url(); a lower-case word
			// that starts a sentence is prose ("Key: the main entrance is on the left").
			$steps[] = [
				'/(?<![A-Za-z0-9_\-.])([A-Za-z][A-Za-z0-9_\-.]{1,63})([ \t]*[:=][ \t]*)(?!:)(?!\[REDACTED)(?![A-Za-z][\w\-.]{0,63}[ \t]*[:=])(?!\/\/)(?!=)([^\s"\'&;,<>)\]\\\\]{1,4096})/',
				static function ( $x, $subject ) use ( $m ) {
					if ( ! self::pair_value_is_secret( $x[1][0], $x[3][0] ) ) {
						return $x[0][0];
					}
					$at = $x[1][1];
					if ( $at > 0 && false !== strpos( '?&#', $subject[ $at - 1 ] ) && self::inside_url( $subject, $at ) ) {
						return $x[0][0];
					}
					if ( self::is_prose_word_value( $x[3][0], $subject, $x[0][1] + strlen( $x[0][0] ) ) ) {
						return $x[0][0];
					}
					return $x[1][0] . $x[2][0] . $m;
				},
				true,
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
				'/(?<![A-Za-z0-9_\-.])([A-Za-z][A-Za-z0-9_\-.]{1,63})([ \t]*[:=][ \t]*)(?!:)(?!\[REDACTED)(?![A-Za-z][\w\-.]{0,63}[ \t]*[:=])(?!\/\/)(?!=)([^\s"\'&;,<>)\]\\\\]{8,4096})/',
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
				'/(?<![A-Za-z0-9_\-\/.+])[A-Za-z0-9_\-]{40,}(?![A-Za-z0-9_\-\/+]|={1,2}(?:[^=]|$))(?=(\.[A-Za-z][A-Za-z0-9]{0,11}(?![A-Za-z0-9]))?)/',
				static function ( $x ) use ( $m ) {
					// A file extension right after the run is judged with it ("critical-<sha1>.css").
					$hashed_file = isset( $x[1] ) && '' !== $x[1] && self::is_named_digest( $x[0] . $x[1] );
					return self::is_secret_value( $x[0] ) && ! $hashed_file ? $m : $x[0];
				},
			];
		}

		// Pieces set aside are put back at the end. Text that already holds the placeholder
		// characters is checked without setting anything aside.
		$held  = [];
		$holds = false === strpos( $s, "\x02" );
		if ( $holds ) {
			$s = self::hold_payloads( $s, $held );
		}
		foreach ( $pre as $step ) {
			$s = self::rx( $step[0], $step[1], $s, ! empty( $step[2] ) );
			// A pattern that still fails returns null; withhold the text rather than return it unchecked.
			if ( null === $s ) {
				return self::MARKER;
			}
		}
		if ( $strict && $holds ) {
			$s = self::hold_test_results( $s, $held );
		}
		foreach ( $steps as $step ) {
			$s = self::rx( $step[0], $step[1], $s, ! empty( $step[2] ) );
			if ( null === $s ) {
				return self::MARKER;
			}
		}
		return self::release_test_results( $s, $held );
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
