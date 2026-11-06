<?php
/**
 * Personal data in form entries returned by the form tools.
 *
 * Fields that hold contact or identity data (email, phone, names, addresses,
 * hidden values, passwords, files, signatures, payment fields, repeaters and
 * grids) come back with a null value and masked: true. Submitter details (IP
 * address, browser, device, location, user account) are left out. Email
 * addresses and phone numbers inside free text are replaced too. A field the
 * site lets through by its key or type comes back as stored; letting 'email'
 * or 'phone' through also stops those being replaced inside other values.
 *
 * When unsure, a field is masked. A site opts in to more with the
 * royal_mcp_form_entry_fields filter, which runs only after the tool's
 * capability check has passed.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Form_Entry_Privacy {

	/** Field types (any form plugin's spelling) that always hold personal data. */
	const PERSONAL_TYPES = [
		'email', 'input_email',
		'phone', 'tel', 'input_mask',
		'firstname', 'lastname', 'input_name', 'name',
		'address', 'address2', 'city', 'zip', 'liststate', 'listcountry', 'select_country', 'country', 'state',
		'hidden', 'input_hidden',
		'password', 'passwordconfirm', 'input_password',
		'file', 'input_file', 'input_image', 'file_upload', 'signature',
		'repeater', 'repeater_field', 'repeater_container', 'tabular_grid',
		'creditcard', 'creditcardcvc', 'creditcardexpiration', 'creditcardfullname', 'creditcardnumber', 'creditcardzip',
		'unknown', '',
	];

	/** Submitter details the tools never return unless a site opts in by these names (or 'submitter'). */
	const SUBMITTER_KEYS = [ 'ip', 'browser', 'device', 'city', 'country', 'user_id' ];

	/**
	 * Opt-in type names that also cover each form plugin's own spelling of
	 * that field type, so one name works across Ninja Forms, Fluent Forms and
	 * Contact Form 7. They widen type matches only, never key matches.
	 */
	const TYPE_ALIASES = [
		'email'   => [ 'email', 'input_email' ],
		'phone'   => [ 'phone', 'tel' ],
		'name'    => [ 'name', 'input_name', 'firstname', 'lastname' ],
		'address' => [ 'address', 'address2' ],
		'file'    => [ 'file', 'input_file', 'input_image', 'file_upload' ],
	];

	/** Words in a field's name, label or placeholder that mark personal data when they appear as a whole word. */
	const PERSONAL_WORDS = [
		'name', 'names', 'nom', 'nombre', 'prenom', 'prénom', 'apellido', 'apellidos', 'vorname', 'nachname', 'surname',
		'contact', 'customer', 'person', 'client', 'user', 'login', 'handle', 'account', 'member',
		'mail', 'email', 'phone', 'tel', 'telefon', 'telefono', 'teléfono', 'cell', 'mobile', 'whatsapp', 'fax',
		'addr', 'address', 'adresse', 'direccion', 'dirección', 'street', 'strasse', 'straße', 'city', 'town', 'location',
		'zip', 'postal', 'postcode', 'plz', 'country',
		'dob', 'birth', 'birthday', 'age', 'gender', 'sex',
		'ssn', 'passport', 'iban', 'bic', 'swift', 'cvc', 'cvv', 'card', 'ip', 'signature', 'linkedin', 'twitter', 'instagram', 'facebook',
	];

	/** Word stems that mark personal data anywhere in a field's name or label once separators are removed. */
	const PERSONAL_STEMS = [
		'email', 'phone', 'mobile', 'whatsapp', 'address', 'adresse', 'firstname', 'lastname', 'fullname', 'surname',
		'middlename', 'maidenname', 'yourname', 'contactname', 'customername', 'username', 'birth', 'geburt', 'passport',
		'signature', 'postcode', 'postalcode', 'zipcode', 'socialsecurity', 'nationalid', 'creditcard', 'cardnumber',
		'vorname', 'nachname', 'apellido', 'nombre', 'telefon', 'linkedin', 'ipaddress',
	];

	/**
	 * Field keys, field types or submitter keys a site lets through unmasked.
	 * '*' lets everything through.
	 *
	 * @param string $plugin  Form plugin slug (ninja-forms, fluentform, contact-form-7).
	 * @param int    $form_id Form ID.
	 * @param string $context 'list' or 'single'.
	 * @return string[]
	 */
	public static function reveal_list( $plugin, $form_id, $context ) {
		/**
		 * Filters which personal fields of form entries the form tools return.
		 *
		 * Runs after the tool's capability check. Each entry is matched against
		 * a field's key and its type:
		 * - a field key lets through only the field with exactly that key;
		 * - a field type lets through every field of that type; 'email',
		 *   'phone', 'name', 'address' and 'file' also match each plugin's own
		 *   spelling of the type (for example 'input_email' or 'tel');
		 * - 'email' and 'phone' also stop email addresses and phone numbers
		 *   being hidden inside the values of other fields;
		 * - submitter keys ('ip', 'browser', 'device', 'city', 'country',
		 *   'user_id', or 'submitter' for all of them) only control submitter
		 *   details, never form fields; to let through a form field of type
		 *   city or country, name that field's key;
		 * - for Contact Form 7, 'from', 'from_name' and 'from_email';
		 * - '*' lets everything through.
		 * A field let through by its key or type is returned as stored, with
		 * nothing inside it hidden. A trailing '*' (Contact Form 7's required
		 * marker, as in 'email*') is ignored.
		 *
		 * @param string[] $reveal  Names to return unmasked. Default none.
		 * @param string   $plugin  Form plugin slug.
		 * @param int      $form_id Form ID (0 when not known).
		 * @param string   $context 'list' or 'single'.
		 */
		$list = apply_filters( 'royal_mcp_form_entry_fields', [], (string) $plugin, (int) $form_id, (string) $context );
		if ( ! is_array( $list ) ) {
			return [];
		}
		$out = [];
		foreach ( $list as $v ) {
			// A trailing * is Contact Form 7's required marker (email*, tel*).
			$v = is_scalar( $v ) ? strtolower( trim( (string) $v ) ) : '';
			$v = '*' === $v ? $v : rtrim( $v, '*' );
			if ( '' === $v ) {
				continue;
			}
			$out[] = $v;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether a form field is let through: an entry equal to its key, or to
	 * its type or a name whose aliases include that type. Submitter keys
	 * never match form fields.
	 */
	public static function reveals_field( array $reveal, $key, $type ) {
		if ( self::reveals_all( $reveal ) ) {
			return true;
		}
		$key  = strtolower( (string) $key );
		$type = strtolower( (string) $type );
		foreach ( $reveal as $entry ) {
			if ( 'submitter' === $entry || in_array( $entry, self::SUBMITTER_KEYS, true ) ) {
				continue;
			}
			if ( '' !== $key && $entry === $key ) {
				return true;
			}
			if ( '' !== $type && ( $entry === $type || in_array( $type, self::TYPE_ALIASES[ $entry ] ?? [], true ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether everything is let through. */
	public static function reveals_all( array $reveal ) {
		return in_array( '*', $reveal, true );
	}

	/** Whether a key or type is let through. */
	public static function reveals( array $reveal, ...$names ) {
		if ( self::reveals_all( $reveal ) ) {
			return true;
		}
		foreach ( $names as $n ) {
			if ( '' !== (string) $n && in_array( strtolower( (string) $n ), $reveal, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether a name, label or placeholder text names personal data. */
	public static function names_personal_data( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return false;
		}
		$text   = preg_replace( '/([\p{Ll}\d])(\p{Lu})/u', '$1 $2', $text );
		$text   = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
		$tokens = preg_split( '/[^\p{L}]+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $tokens ) {
			return false;
		}
		foreach ( $tokens as $tok ) {
			if ( in_array( $tok, self::PERSONAL_WORDS, true ) ) {
				return true;
			}
		}
		$joined = implode( '', $tokens );
		foreach ( self::PERSONAL_STEMS as $stem ) {
			if ( false !== strpos( $joined, $stem ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a field holds personal data by its type, any of its names or
	 * labels, a flag the form plugin sets, or a value made of rows.
	 *
	 * @param string   $type    Field type.
	 * @param string   $key     Field name or key.
	 * @param string   $label   Field label.
	 * @param bool     $flagged The form plugin marks the field as personal data.
	 * @param string[] $also    Other texts of the field: admin label, placeholder.
	 * @param bool     $cleared The caller checked every column of a repeater or grid and none is personal.
	 * @param mixed    $value   The value, when known.
	 */
	public static function is_personal( $type, $key, $label = '', $flagged = false, array $also = [], $cleared = false, $value = null ) {
		$type = strtolower( (string) $type );
		if ( $flagged ) {
			return true;
		}
		if ( ! $cleared ) {
			if ( in_array( $type, self::PERSONAL_TYPES, true ) ) {
				return true;
			}
			if ( false !== strpos( $type, 'payment' ) || 0 === strpos( $type, 'creditcard' ) || false !== strpos( $type, 'stripe' ) || false !== strpos( $type, 'paypal' ) || false !== strpos( $type, 'repeater' ) || false !== strpos( $type, 'grid' ) ) {
				return true;
			}
			if ( is_array( $value ) ) {
				foreach ( $value as $row ) {
					if ( is_array( $row ) ) {
						return true;
					}
				}
			}
		}
		foreach ( array_merge( [ (string) $key, (string) $label ], array_map( 'strval', $also ) ) as $text ) {
			if ( self::names_personal_data( $text ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One field of an entry as returned: personal fields masked unless let through.
	 *
	 * @return array{name: string, label: string, type: string, value: mixed, masked: bool}
	 */
	public static function field( $key, $label, $type, $value, array $reveal, $flagged = false, array $also = [], $cleared = false ) {
		// A field the site named in its opt-in list comes back as stored.
		$named  = self::reveals_field( $reveal, $key, $type );
		$masked = ! $named && self::is_personal( $type, $key, $label, $flagged, $also, $cleared, $value );
		return [
			'name'   => (string) $key,
			'label'  => (string) $label,
			'type'   => (string) $type,
			'value'  => $masked ? null : ( $named ? $value : self::free_text( $value, $reveal ) ),
			'masked' => $masked,
		];
	}

	/**
	 * A value with email addresses (also written out as "at" / "dot") and phone
	 * numbers inside free text replaced, unless let through. Dates, prices and
	 * short numbers stay.
	 */
	public static function free_text( $value, array $reveal ) {
		if ( self::reveals_all( $reveal ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::free_text( $v, $reveal );
			}
			return $out;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		$text = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( ! self::reveals( $reveal, 'email' ) ) {
			$text = self::hide_emails( $text );
		}
		if ( ! self::reveals( $reveal, 'phone' ) ) {
			$text = (string) preg_replace_callback(
				'/(?<![\p{L}\p{N}])\+?\(?\d(?:[\s.\-()]{0,3}\d){6,}\)?(?![\p{L}\p{N}])/u',
				static function ( $m ) {
					$s = trim( $m[0] );
					// Dates and decimals are not phone numbers.
					if ( preg_match( '/^\d{4}[\-.]\d{1,2}[\-.]\d{1,2}(?:\s+\d{1,2})?$|^\d{1,2}[\-.]\d{1,2}[\-.]\d{2,4}$|^\d+\.\d{1,2}$/', $s ) ) {
						return $m[0];
					}
					return '[phone hidden]';
				},
				$text
			);
		}
		return $text;
	}

	/**
	 * Email addresses replaced: anything shaped like local@host (unicode,
	 * localhost, [ip]), and with $written_out also addresses written out as
	 * name [at] domain [dot] tld or name at domain dot com.
	 */
	public static function hide_emails( $text, $written_out = true ) {
		$text = (string) preg_replace( '/[^\s<>"\'(),;:]+[@＠][^\s<>"\'(),;:]+/u', '[email hidden]', (string) $text );
		if ( $written_out ) {
			$at   = '(?:@|＠|\s*[\[\(\{<]\s*at\s*[\]\)\}>]\s*|\s+at\s+)';
			$dot  = '(?:\.(?=[\p{L}\p{N}])|\s*[\[\(\{<]\s*dot\s*[\]\)\}>]\s*|\s+dot\s+)';
			$word = '[\p{L}\p{N}_%+\-]+';
			$text = (string) preg_replace( '/' . $word . '(?:\.' . $word . ')*' . $at . $word . '(?:' . $dot . $word . ')+/iu', '[email hidden]', $text );
		}
		return $text;
	}

	/**
	 * Text a site wrote into a form (labels, choices, template text) with
	 * email addresses and phone numbers replaced. Only numbers written like a
	 * phone number count: a leading +, parentheses, three or more digit
	 * groups, or 10 to 15 digits. Postcodes such as 90210-1234, ISBN-13s,
	 * dates and shorter codes stay. Nothing else in the text changes.
	 */
	public static function form_text( $value ) {
		if ( is_array( $value ) ) {
			return array_map( [ __CLASS__, 'form_text' ], $value );
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		return self::hide_form_phones( self::hide_emails( $value ) );
	}

	/** Phone-shaped numbers in form text replaced (see form_text()). */
	public static function hide_form_phones( $text ) {
		return (string) preg_replace_callback(
			'/(?<![\p{L}\p{N}])\+?\(?\d(?:[\s.\-()]{0,3}\d){6,}\)?(?![\p{L}\p{N}])/u',
			static function ( $m ) {
				$s      = trim( $m[0] );
				$digits = strlen( (string) preg_replace( '/\D/', '', $s ) );
				if ( $digits < 7 || $digits > 15
					|| preg_match( '/^\d{4}[\-.]\d{1,2}[\-.]\d{1,2}(?:\s+\d{1,2})?$|^\d{1,2}[\-.]\d{1,2}[\-.]\d{2,4}$|^\d{5}-\d{4}$/', $s )
					|| ( 13 === $digits && preg_match( '/^97[89]/', $s ) ) ) {
					return $m[0];
				}
				$groups = preg_split( '/[\s.\-()]+/', ltrim( $s, '+' ), -1, PREG_SPLIT_NO_EMPTY );
				if ( '+' === $s[0] || false !== strpos( $s, '(' ) || count( (array) $groups ) >= 3 || $digits >= 10 ) {
					return '[phone hidden]';
				}
				return $m[0];
			},
			(string) $text
		);
	}

	/**
	 * Submitter details a site let through, from a key => value map.
	 *
	 * @return array Only the keys that were let through.
	 */
	public static function submitter( array $details, array $reveal ) {
		$out = [];
		foreach ( $details as $k => $v ) {
			if ( self::reveals( $reveal, $k, 'submitter' ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/** The submitting user's ID, or null unless a site lets it through. */
	public static function user_id( $id, array $reveal ) {
		return self::reveals( $reveal, 'user_id', 'submitter' ) ? (int) $id : null;
	}

	/**
	 * A positive integer from a tool argument, or 0. Arrays, objects, floats,
	 * booleans, signs and values above the largest integer are refused as 0.
	 */
	public static function positive_int( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}
		if ( ! is_string( $value ) || ! preg_match( '/^\d{1,40}$/', $value ) ) {
			return 0;
		}
		$digits = ltrim( $value, '0' );
		$max    = (string) PHP_INT_MAX;
		if ( '' === $digits || strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) > 0 ) ) {
			return 0;
		}
		return (int) $digits;
	}

	/** A mysql datetime (UTC) as ISO 8601, or null. */
	public static function iso( $mysql_gmt ) {
		if ( empty( $mysql_gmt ) || '0000-00-00 00:00:00' === $mysql_gmt ) {
			return null;
		}
		$ts = strtotime( $mysql_gmt . ' UTC' );
		return $ts ? gmdate( 'c', $ts ) : null;
	}

	/** A mysql datetime in site time as ISO 8601 (UTC), or null. */
	public static function iso_local( $mysql_local ) {
		if ( empty( $mysql_local ) || '0000-00-00 00:00:00' === $mysql_local ) {
			return null;
		}
		return self::iso( get_gmt_from_date( (string) $mysql_local ) );
	}
}
