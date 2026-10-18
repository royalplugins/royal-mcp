<?php
namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Credential redaction for code returned by snippet integrations. PHP is read
 * with PHP's tokenizer, so quotes inside comments and strings cannot shift
 * what counts as a string; other code types are checked line by line.
 */
final class Code_Redactor {

	/** Calls whose result is still the literal passed in. */
	const PASSTHROUGH_CALLS = [ 'trim', 'strval', 'base64_decode', 'hex2bin', 'rawurldecode', 'urldecode', 'str_rot13', 'strrev', 'html_entity_decode', 'stripslashes' ];

	/** Calls that compare a literal with a value: hash_equals( 'literal', $api_key ). */
	const COMPARE_CALLS = [ 'hash_equals', 'strcmp', 'strcasecmp', 'password_verify' ];

	/** Call sites whose argument at this position (from 0) is a database password. */
	const DB_PASSWORD_ARG = [ 'mysqli_connect' => 2, 'mysql_connect' => 2, 'mysqli_real_connect' => 3, 'real_connect' => 2, 'new:mysqli' => 2, 'new:pdo' => 2, 'new:wpdb' => 1 ];

	/**
	 * Code with credential values replaced by [REDACTED], or null when it could
	 * not be checked (the caller then withholds it). $type: php (code with no
	 * opening tag), universal (HTML with PHP blocks), or anything else as text.
	 */
	public static function redact( $code, $type ) {
		$code = (string) $code;
		if ( 'php' === $type ) {
			return self::redact_php( $code, true );
		}
		if ( 'universal' === $type ) {
			return self::redact_php( $code, false );
		}
		return self::redact_text( $code );
	}

	/** Names whose value is a credential, beyond the rule tool replies use. */
	private static function is_secret_name( $name ) {
		static $server = null;
		if ( null === $server ) {
			$server = new \Royal_MCP\MCP\Server();
		}
		$name = trim( (string) $name, "\"'`\$ \t" );
		if ( '' === $name || strlen( $name ) > 128 ) {
			return false;
		}
		if ( in_array( strtolower( $name ), [ 'authorization', 'proxy-authorization', 'x-auth-token' ], true ) ) {
			return true;
		}
		// In code, a bare "key" (array keys, cache keys, meta keys) is not a credential
		// unless the name also says what kind of key it is.
		$plain = strtolower( preg_replace( '/[^A-Za-z0-9]+/', '_', preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $name ) ) );
		if ( preg_match( '/(?:^|_)key$/', $plain )
			&& ! preg_match( '/api|secret|private|access|licen[cs]e|auth|client|consumer|encrypt|sign|session|merchant|master|stripe|shared|webhook|account|storage|public|app_|gateway|paypal|recaptcha|turnstile|smtp|mail|aws|token|pass/', $plain ) ) {
			return false;
		}
		return $server->is_credential_name( $name );
	}

	/**
	 * PHP code, read token by token. A string is masked when it is the value of
	 * a credential (assigned to, keyed by, compared with or defaulted from a
	 * credential name, or a database password argument); other strings,
	 * comments and HTML go through the text checks.
	 */
	private static function redact_php( $code, $php_first ) {
		$added = $php_first && ! preg_match( '/^\s*<\?(?:php\b|=)?/i', $code );
		$raw   = token_get_all( ( $added ? "<?php\n" : '' ) . $code );
		$t     = [];
		foreach ( $raw as $tok ) {
			$t[] = is_array( $tok ) ? [ $tok[0], $tok[1] ] : [ null, $tok ];
		}
		$count     = count( $t );
		$name_ids  = [ T_VARIABLE, T_STRING ];
		foreach ( [ 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED' ] as $const ) {
			if ( defined( $const ) ) {
				$name_ids[] = constant( $const );
			}
		}
		$string_ids = [ T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ];
		$cmp_ids    = [ T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL ];
		$quiet_ids  = [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ];
		$next_sig   = static function ( $i ) use ( $t, $count, $quiet_ids ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( ! in_array( $t[ $j ][0], $quiet_ids, true ) ) {
					return $j;
				}
			}
			return -1;
		};

		$out       = [];
		$depth     = 0;
		$active    = null;  // Depth at which a credential value started.
		$mask_next = null;  // Depth whose next string is a header value ("Bearer " . 'x').
		$last_name = '';
		$last_kind = '';    // 'var', 'const' or 'string'.
		$stmt_cred = false; // A credential name appeared in this statement.
		$prev_sig  = '';
		$after_new = false;
		$frames    = [];    // Open brackets: [ depth, name, arg, password arg, compares a secret ].
		$define_at = null;

		for ( $i = 0; $i < $count; $i++ ) {
			[ $id, $text ] = $t[ $i ];

			if ( T_WHITESPACE === $id ) {
				$out[] = $text;
				continue;
			}
			if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				$clean = self::redact_text( $text );
				if ( null === $clean ) {
					return null;
				}
				$out[] = $clean;
				continue;
			}
			if ( T_INLINE_HTML === $id ) {
				$clean = self::redact_text( $text );
				if ( null === $clean ) {
					return null;
				}
				$out[] = $clean;
				// The name sits in the HTML and the value in the PHP block after it.
				if ( ( preg_match( '/(?<![\w$\-.])(\$?[A-Za-z_][\w\-.]*+)["\']?\s*(?:=>|:|=)\s*["\'`]?\s*$/', $text, $m ) && self::is_secret_name( $m[1] ) )
					|| ( preg_match( '/\bname\s*=\s*["\']([^"\'<>]+)["\'][^<>]*\b(?:value|content)\s*=\s*["\']?\s*$/i', $text, $m ) && self::is_secret_name( $m[1] ) ) ) {
					$active = $depth;
				}
				$prev_sig = 'html';
				continue;
			}
			if ( T_CLOSE_TAG === $id ) {
				$out[]     = $text;
				$active    = null;
				$mask_next = null;
				$stmt_cred = false;
				$prev_sig  = ';';
				continue;
			}

			if ( in_array( $id, $string_ids, true ) ) {
				$top    = $frames ? $frames[ count( $frames ) - 1 ] : null;
				$secret = null !== $active && self::reaches( $frames, $active, $depth );
				if ( null !== $mask_next && $mask_next === $depth ) {
					$secret    = true;
					$mask_next = null;
				}
				if ( $top && $top[0] === $depth && null !== $top[3] && $top[2] === $top[3] ) {
					$secret = true; // Database password argument.
				}
				if ( $top && $top[0] === $depth && $top[4] ) {
					$secret = true; // hash_equals( 'literal', $api_key ).
				}
				$j = $next_sig( $i );
				if ( $j >= 0 && T_DOUBLE_ARROW === $t[ $j ][0] ) {
					$secret = false; // An array key, not a value.
				}
				if ( $j >= 0 && in_array( $t[ $j ][0], $cmp_ids, true ) ) {
					$k = $next_sig( $j );
					if ( $k >= 0 && in_array( $t[ $k ][0], $name_ids, true ) && self::is_secret_name( $t[ $k ][1] ) ) {
						$secret = true; // 'literal' === $api_key.
					}
				}
				if ( $top && 'base64_encode' === $top[1] && $top[0] === $depth && T_CONSTANT_ENCAPSED_STRING === $id
					&& preg_match( '/^.[^:\s]+:\S+.$/s', $text ) ) {
					$secret = true; // base64_encode( 'user:password' ).
				}

				if ( $secret ) {
					$out[] = self::mask_literal( $text, $id );
				} else {
					$clean = self::redact_literal( $text, $id );
					if ( null === $clean ) {
						return null;
					}
					$out[] = $clean;
					$inner = T_CONSTANT_ENCAPSED_STRING === $id ? substr( $text, 1, -1 ) : $text;
					if ( preg_match( '/(?:\b(?:Bearer|Basic|Token)\s+|\bauthorization\s*:\s*)$/i', $inner ) || preg_match( '/\b(?:Bearer|Basic|Token)\s+%s/i', $inner ) ) {
						$mask_next = $depth; // The next string at this level is the header value.
					}
					if ( T_CONSTANT_ENCAPSED_STRING === $id ) {
						$last_name = $text;
						$last_kind = 'string';
						if ( self::is_secret_name( $text ) ) {
							$stmt_cred = true;
						}
					}
				}
				$prev_sig = 'string';
				continue;
			}

			$out[] = $text;
			$lower = strtolower( ltrim( $text, '\\' ) );

			if ( in_array( $id, $name_ids, true ) ) {
				$last_name = $text;
				$last_kind = T_VARIABLE === $id ? 'var' : 'const';
				if ( self::is_secret_name( $text ) ) {
					$stmt_cred = true;
				}
				$prev_sig  = $after_new ? 'new:' . $lower : 'name';
				$after_new = false;
				continue;
			}
			if ( T_NEW === $id ) {
				$after_new = true;
				continue;
			}
			if ( T_OBJECT_OPERATOR === $id || ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && constant( 'T_NULLSAFE_OBJECT_OPERATOR' ) === $id ) || T_DOUBLE_COLON === $id ) {
				$prev_sig = '->';
				continue;
			}

			$opens = in_array( $text, [ '(', '[', '{' ], true ) || in_array( $id, [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true );
			if ( $opens ) {
				$depth++;
				$call = '';
				if ( '(' === $text && ( 'name' === $prev_sig || 0 === strpos( $prev_sig, 'new:' ) ) ) {
					$call = 0 === strpos( $prev_sig, 'new:' ) ? $prev_sig : strtolower( ltrim( $last_name, '\\$' ) );
				}
				// [ after a name, ] or ) is an index into an array, not an array literal.
				$index = '[' === $text && ( 'name' === $prev_sig || ']' === $prev_sig || ')' === $prev_sig || 'string' === $prev_sig );
				$kind  = '(' === $text ? ( '' === $call ? '()' : $call ) : ( '[' === $text ? ( $index ? '[i]' : '[]' ) : '{}' );
				$compares = false;
				if ( in_array( $call, self::COMPARE_CALLS, true ) ) {
					$compares = self::call_has_secret_name( $t, $i, $name_ids );
				}
				$frames[] = [ $depth, $kind, 0, self::DB_PASSWORD_ARG[ $call ] ?? null, $compares ];
				if ( 'define' === $call ) {
					$define_at = $depth;
				}
			} elseif ( in_array( $text, [ ')', ']', '}' ], true ) ) {
				if ( null !== $active && $depth <= $active ) {
					$active = null;
				}
				if ( null !== $mask_next && $depth <= $mask_next ) {
					$mask_next = null;
				}
				if ( $frames && $frames[ count( $frames ) - 1 ][0] === $depth ) {
					array_pop( $frames );
				}
				if ( $define_at === $depth ) {
					$define_at = null;
				}
				$depth = max( 0, $depth - 1 );
			} elseif ( ',' === $text ) {
				if ( null !== $active && $depth <= $active ) {
					$active = null;
				}
				if ( $frames && $frames[ count( $frames ) - 1 ][0] === $depth ) {
					$frames[ count( $frames ) - 1 ][2]++;
				}
				if ( null !== $mask_next && $depth < $mask_next ) {
					$mask_next = null;
				}
				// define( 'NAME', ... ) and curl_setopt( $ch, CURLOPT_USERPWD, ... ).
				if ( ( $define_at === $depth && 'string' === $prev_sig && self::is_secret_name( $last_name ) )
					|| ( 'name' === $prev_sig && 'const' === $last_kind && preg_match( '/^[A-Z][A-Z0-9_]*$/', ltrim( $last_name, '\\' ) ) && self::is_secret_name( $last_name ) && $depth > 0 ) ) {
					$active = $depth;
				}
			} elseif ( ';' === $text ) {
				$active    = null;
				$mask_next = null;
				$stmt_cred = false;
				$define_at = null;
			} elseif ( '=' === $text || in_array( $id, array_merge( [ T_DOUBLE_ARROW, T_CONCAT_EQUAL, T_COALESCE_EQUAL ], $cmp_ids ), true ) ) {
				if ( self::is_secret_name( $last_name ) ) {
					$active = $depth;
				}
			} elseif ( ':' === $text ) {
				if ( '?' === $prev_sig && $stmt_cred ) {
					$active = $depth;
				} elseif ( 'name' === $prev_sig && 'const' === $last_kind && $depth > 0 && self::is_secret_name( $last_name ) ) {
					$active = $depth; // Named argument: api_key: 'value'.
				}
			} elseif ( T_COALESCE === $id ) {
				if ( $stmt_cred ) {
					$active = $depth;
				}
			}
			$after_new   = false;
			$prev_sig    = $text;
		}

		$result = implode( '', array_map( 'strval', $out ) );
		return $added ? (string) substr( $result, strlen( "<?php\n" ) ) : $result;
	}

	/**
	 * Whether a string at $depth still belongs to the credential value that
	 * started at $active: same level, or only inside grouping brackets, arrays
	 * or calls that hand back their input (trim, base64_decode, ...).
	 */
	private static function reaches( array $frames, $active, $depth ) {
		if ( $depth === $active ) {
			return true;
		}
		if ( $depth < $active ) {
			return false;
		}
		foreach ( $frames as $frame ) {
			if ( $frame[0] > $active && ! in_array( $frame[1], array_merge( [ '()', '[]' ], self::PASSTHROUGH_CALLS ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/** Whether the call opened at token $i has a credential-named argument. */
	private static function call_has_secret_name( array $t, $i, array $name_ids ) {
		$level = 0;
		$count = count( $t );
		for ( $j = $i; $j < $count; $j++ ) {
			$text = $t[ $j ][1];
			if ( '(' === $text ) {
				$level++;
			} elseif ( ')' === $text ) {
				$level--;
				if ( 0 === $level ) {
					return false;
				}
			} elseif ( in_array( $t[ $j ][0], $name_ids, true ) && self::is_secret_name( $text ) ) {
				return true;
			}
		}
		return false;
	}

	/** A string token with its contents replaced, quotes and heredoc markers kept. */
	private static function mask_literal( $text, $id ) {
		if ( T_CONSTANT_ENCAPSED_STRING === $id && strlen( $text ) >= 2 ) {
			return '' === trim( substr( $text, 1, -1 ) ) ? $text : $text[0] . '[REDACTED]' . substr( $text, -1 );
		}
		return '' === trim( $text ) ? $text : '[REDACTED]' . ( "\n" === substr( $text, -1 ) ? "\n" : '' );
	}

	/** A string token whose contents go through the text checks. */
	private static function redact_literal( $text, $id ) {
		if ( T_CONSTANT_ENCAPSED_STRING === $id && strlen( $text ) >= 2 ) {
			$inner = self::redact_text( substr( $text, 1, -1 ) );
			return null === $inner ? null : $text[0] . $inner . substr( $text, -1 );
		}
		return self::redact_text( $text );
	}

	/**
	 * Credential values inside free text (HTML, CSS, JS, comments, string
	 * contents). Each check stays on one line, so a stray quote elsewhere
	 * cannot pair up across the code.
	 */
	private static function redact_text( $text ) {
		$steps = [
			// Authorization header values (before name: value, which would take "Bearer").
			// The value must look like a token (a digit or mixed case), not a word in prose.
			[ '~\b(Bearer|Basic|(?<=:)\s*Token)(\s+)([A-Za-z0-9._\~+/=\-]{8,})~i', static function ( $m ) {
				$token = preg_match( '/\d/', $m[3] ) || ( preg_match( '/[a-z]/', $m[3] ) && preg_match( '/[A-Z]/', $m[3] ) );
				return $token ? $m[1] . $m[2] . '[REDACTED]' : $m[0];
			} ],
			// "Bearer " + 'value' in JavaScript.
			[ '~((["\'`])[^"\'`\r\n]*\b(?:Bearer|Basic|Token)\s*\2\s*\+\s*)(["\'`])([^"\'`\r\n]+)\3~i', static function ( $m ) {
				return $m[1] . $m[3] . '[REDACTED]' . $m[3];
			} ],
			// name = "value" / name: 'value' / "name": "value", quoted, on one line.
			[ '~(?<![\w$\-.])(\$?[A-Za-z_][\w\-.]*+)(["\']?)(\s*(?:=>|:|=)\s*)(["\'`])([^\r\n"\'`]*)\4~', static function ( $m ) {
				return ( '' !== $m[5] && self::is_secret_name( $m[1] ) ) ? $m[1] . $m[2] . $m[3] . $m[4] . '[REDACTED]' . $m[4] : $m[0];
			} ],
			// HTML: name="api_key" ... value="...", and meta name / content.
			[ '~(\bname\s*=\s*(["\'])([^"\'<>]+)\2[^<>]*?\b(?:value|content)\s*=\s*)(["\'])([^"\'<>]*)\4~i', static function ( $m ) {
				return ( '' !== $m[5] && self::is_secret_name( $m[3] ) ) ? $m[1] . $m[4] . '[REDACTED]' . $m[4] : $m[0];
			} ],
			// Header pairs in calls: setRequestHeader( "X-API-Key", "value" ).
			[ '~(\(\s*(["\'])([\w\-.]+)\2\s*,\s*)(["\'`])([^"\'`\r\n]*)\4~', static function ( $m ) {
				return ( '' !== $m[5] && self::is_secret_name( $m[3] ) ) ? $m[1] . $m[4] . '[REDACTED]' . $m[4] : $m[0];
			} ],
			// URL query parameters: ?api_key=value&...
			[ '~([?&](?:amp;)?)([A-Za-z_][\w\-.]*+)=([^&#\s"\'`<>]+)~', static function ( $m ) {
				return self::is_secret_name( $m[2] ) ? $m[1] . $m[2] . '=[REDACTED]' : $m[0];
			} ],
			// name=value in prose ("password=abc"): after a space, not a call or member.
			[ '~(?<![^\s,;])([A-Za-z_][\w\-]*+)=([^\s"\'`<>(){}\[\],;&]++)(?!\()~', static function ( $m ) {
				return ( self::is_secret_name( $m[1] ) && '[REDACTED]' !== $m[2] ) ? $m[1] . '=[REDACTED]' : $m[0];
			} ],
			// .env / .ini lines: NAME=value at the start of a line.
			[ '~^(\s*(?:export\s+)?)([A-Za-z_][\w\-.]*+)(\s*=\s*)([^\s"\'`#;]+)~m', static function ( $m ) {
				return ( self::is_secret_name( $m[2] ) && '[REDACTED]' !== $m[4] ) ? $m[1] . $m[2] . $m[3] . '[REDACTED]' : $m[0];
			} ],
			// Passwords in URLs: scheme://user:password@host.
			[ '~\b([a-z][a-z0-9+.\-]*://)([^:/@\s"\'<>]*):([^@\s"\'<>/]+)@~i', static function ( $m ) {
				return $m[1] . $m[2] . ':[REDACTED]@';
			} ],
			// curl -u user:password.
			[ '~(\s(?:-u|--user)\s+)([^:\s"\']+):([^\s"\']+)~', static function ( $m ) {
				return $m[1] . $m[2] . ':[REDACTED]';
			} ],
			// SQL passwords.
			[ '~\b(IDENTIFIED\s+(?:WITH\s+\w+\s+)?BY\s+)([\'"])([^\'"\r\n]*)\2~i', static function ( $m ) {
				return $m[1] . $m[2] . '[REDACTED]' . $m[2];
			} ],
			// Keys recognisable by their prefix.
			[ '~(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{8,}|gh[pousr]_[A-Za-z0-9]{20,}|github_pat_\w{20,}|xox[abpr]-[\w\-]{10,}|AKIA[0-9A-Z]{16}|AIza[\w\-]{30,}|SG\.[\w\-]{16,}\.[\w\-]{16,}|whsec_[A-Za-z0-9]{8,}~', static function () {
				return '[REDACTED]';
			} ],
			// Long token or base64 runs that mix letters and digits.
			[ '~(?<![A-Za-z0-9+/_\-])[A-Za-z0-9+/_\-]{32,}={0,2}(?![A-Za-z0-9+/_\-])~', static function ( $m ) {
				$v = $m[0];
				// Lower-case names joined by - or _ (hooks, functions, CSS custom properties) are not tokens.
				$token = preg_match( '/\d/', $v ) && preg_match( '/[A-Za-z]/', $v ) && ! preg_match( '~^[a-z_\-/]+$~', $v )
					&& ! preg_match( '/^-*[a-z][a-z0-9]*(?:[_\-]+[a-z0-9]+){2,}$/', $v ) && substr_count( $v, '/' ) < 3
					&& ! preg_match( '/abcdefghij|ABCDEFGHIJ|0123456789/', $v ); // Character sets, not keys.
				return $token ? '[REDACTED]' : $v;
			} ],
		];
		foreach ( $steps as [ $pattern, $fn ] ) {
			$text = preg_replace_callback( $pattern, $fn, $text );
			if ( null === $text ) {
				return null;
			}
		}
		return $text;
	}
}
