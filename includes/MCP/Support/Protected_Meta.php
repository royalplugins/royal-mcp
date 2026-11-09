<?php
/**
 * The administrator's list of protected post meta keys that AI tools may write.
 *
 * WordPress refuses edit_post_meta on most protected keys, even for administrators.
 * A key on this list skips that one refusal, including a refusal from a callback
 * the key was registered with: listing it is the administrator's decision.
 * edit_post on the post is still required, and every other check on the meta
 * tools still runs. Keys in the permanent blocklist never open, whatever is listed.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Protected_Meta {

	/** Key in the royal_mcp_settings option. */
	const SETTING = 'writable_meta_keys';

	const MAX_KEYS = 200;

	const MAX_VALUE_BYTES = 65536;

	/** Divi page settings that are safe to offer as a starting list. */
	const DIVI_STARTER_KEYS = [
		'_et_pb_gutter_width',
		'_et_pb_page_layout',
		'_et_pb_side_nav',
		'_et_pb_post_hide_nav',
		'_et_pb_show_title',
		'_wp_page_template',
	];

	/** WordPress keys that must never hold an ACF field reference, whether or not a row exists yet. */
	const CORE_REFERENCE_KEYS = [
		'_thumbnail_id',
		'_wp_page_template',
		'_edit_lock',
		'_edit_last',
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_old_slug',
	];

	/** Prefixes of keys that always belong to another tool or to this plugin. */
	const ALWAYS_OWNED_PREFIXES = [ '_elementor_', '_royal_mcp_' ];

	/** Post types that hold shop data under blocked keys. */
	const SHOP_POST_TYPES = [ 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_subscription' ];

	/** Protected term keys that SEO plugins read and the term meta tools document. */
	const TERM_SEO_PREFIXES = [ '_yoast_wpseo_', '_aioseo_' ];

	/** Exact keys that never open. Compared without regard to case, as the database does. */
	const BLOCKED_KEYS = [
		'_edit_lock',
		'_edit_last',
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_backup_sizes',
		'_wp_attachment_context',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_desired_post_slug',
		'_encloseme',
		'_pingme',
		'_thumbnail_id',
		'_price',
		'_regular_price',
		'_sale_price',
		'_stock',
		'_stock_status',
		'_manage_stock',
		'_backorders',
		'_sku',
		'_product_attributes',
		'_customer_user',
		'_weight',
		'_length',
		'_width',
		'_height',
		'_virtual',
		'_downloadable',
		'_downloadable_files',
		'_download_limit',
		'_download_expiry',
		'_tax_status',
		'_tax_class',
		'_purchase_note',
		'_sold_individually',
		'_default_attributes',
		'_upsell_ids',
		'_crosssell_ids',
		'_children',
		'_product_version',
		'_product_image_gallery',
		'_visibility',
		'_et_pb_old_content',
	];

	/** Key prefixes that never open. */
	const BLOCKED_PREFIXES = [
		'_wp_trash_meta_',
		'_menu_item_',
		'_sale_price_dates_',
		'_order_',
		'_billing_',
		'_shipping_',
		'_payment_',
		'_elementor_',
		'_royal_mcp_',
		'_wc_',
	];

	/**
	 * A meta key from a tool call, ready to check and write. WordPress unslashes keys
	 * on write and the database compares them loosely, so a key with a backslash, or
	 * one the database reads as starting with an underscore, can reach a different
	 * stored key than the one that was checked. Those are refused.
	 *
	 * @param mixed  $raw  Key as sent.
	 * @param string $type 'post' or 'term'.
	 * @return string
	 * @throws Tool_Refusal When the key is disguised.
	 */
	public static function clean_key( $raw, $type = 'post' ) {
		$key = SafeText::field( $raw );
		if ( self::is_disguised( $key, $type ) ) {
			throw new Tool_Refusal( 'invalid_meta_key', 'This meta key has a backslash, or the database reads it as a protected key spelled differently, so nothing was changed.' );
		}
		return $key;
	}

	/**
	 * @param string $key  Meta key after text cleaning.
	 * @param string $type 'post' or 'term'.
	 * @return bool
	 */
	public static function is_disguised( $key, $type = 'post' ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return false;
		}
		if ( false !== strpos( $key, '\\' ) ) {
			return true;
		}
		// A key starting with printable ASCII is what it looks like.
		if ( 1 === preg_match( '/^[\x21-\x7E]/', $key ) ) {
			return false;
		}
		return self::reads_as_underscore( $key, $type );
	}

	/**
	 * Asks the database whether a key sorts as starting with an underscore under the
	 * meta_key column's collation: the only reliable test, since which characters
	 * count as look-alikes or as nothing depends on the collation.
	 *
	 * @param string $key  Meta key.
	 * @param string $type 'post' or 'term'.
	 * @return bool True also when the database can't answer.
	 */
	private static function reads_as_underscore( $key, $type ) {
		global $wpdb;
		$collation = self::key_collation( $type );
		$answer    = null;
		if ( '' !== $collation ) {
			$charset  = substr( $collation, 0, (int) strpos( $collation, '_' ) );
			$suppress = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the collation name passed a strict pattern in key_collation()
			$answer = $wpdb->get_var( $wpdb->prepare( "SELECT LEFT( HEX( WEIGHT_STRING( CONVERT( %s USING {$charset} ) COLLATE {$collation} ) ), LENGTH( HEX( WEIGHT_STRING( CONVERT( '_' USING {$charset} ) COLLATE {$collation} ) ) ) ) = HEX( WEIGHT_STRING( CONVERT( '_' USING {$charset} ) COLLATE {$collation} ) )", $key ) );
			$wpdb->suppress_errors( $suppress );
		}
		if ( '' === $collation ) {
			return 1 !== preg_match( '/^[\p{L}\p{N}\p{So}\p{Sc}\p{Sk}\p{Sm}]/u', $key );
		}
		return '0' !== (string) $answer;
	}

	/**
	 * The collation of the meta_key column when it is a UTF-8 one, else an empty string.
	 *
	 * @param string $type 'post' or 'term'.
	 * @return string
	 */
	private static function key_collation( $type ) {
		global $wpdb;
		static $found = [];
		$table = 'term' === $type ? $wpdb->termmeta : $wpdb->postmeta;
		if ( ! isset( $found[ $table ] ) ) {
			$suppress = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from wpdb; schema read
			$column = $wpdb->get_row( "SHOW FULL COLUMNS FROM {$table} LIKE 'meta_key'" );
			$wpdb->suppress_errors( $suppress );
			$name            = is_object( $column ) && isset( $column->Collation ) ? (string) $column->Collation : '';
			$found[ $table ] = 1 === preg_match( '/^(?:utf8mb4|utf8mb3|utf8)_[a-z0-9_]+$/D', $name ) ? $name : '';
		}
		return $found[ $table ];
	}

	/**
	 * Whether a key is protected, counting a disguised key as protected. For tools
	 * that refuse protected keys outright.
	 *
	 * @param string $key Meta key after text cleaning.
	 * @return bool
	 */
	public static function is_protected_key( $key ) {
		$key = (string) $key;
		// These tools can write to tables with another collation, so a first character outside ASCII is refused outright.
		if ( '' !== $key && 1 !== preg_match( '/^[\x21-\x7E]/', $key ) ) {
			return true;
		}
		return self::is_disguised( $key ) || is_protected_meta( $key, 'post' );
	}

	/**
	 * Refuses a write that the database would apply to a protected key spelled differently.
	 *
	 * @param string $key       Meta key.
	 * @param int    $object_id Post or term ID.
	 * @param string $type      'post' or 'term'.
	 * @throws Tool_Refusal When a stored protected key matches under another spelling.
	 */
	public static function assert_no_spelling_clash( $key, $object_id, $type = 'post' ) {
		if ( self::has_spelling_clash( $key, $object_id, $type ) ) {
			throw new Tool_Refusal( 'invalid_meta_key', 'This meta key matches a protected key stored under a different spelling, so nothing was changed.' );
		}
	}

	/**
	 * @param string $key       Meta key.
	 * @param int    $object_id Post or term ID.
	 * @param string $type      'post' or 'term'.
	 * @return bool
	 */
	public static function has_spelling_clash( $key, $object_id, $type = 'post' ) {
		global $wpdb;
		$table  = 'term' === $type ? $wpdb->termmeta : $wpdb->postmeta;
		$column = 'term' === $type ? 'term_id' : 'post_id';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the spellings as stored; the object cache keys them exactly
		$stored = $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$table} WHERE {$column} = %d AND meta_key = %s", (int) $object_id, (string) $key ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return true;
		}
		foreach ( (array) $stored as $spelling ) {
			if ( (string) $spelling !== (string) $key && is_protected_meta( (string) $spelling, $type ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the current user may undo a write to this key: the same rule as the write.
	 *
	 * @param int    $object_id Post or term ID.
	 * @param string $key       Meta key.
	 * @param string $type      'post' or 'term'.
	 * @return bool
	 */
	public static function may_undo( $object_id, $key, $type = 'post' ) {
		if ( self::is_disguised( $key, $type ) || self::has_spelling_clash( $key, $object_id, $type ) ) {
			return false;
		}
		return ! is_protected_meta( (string) $key, $type )
			|| current_user_can( 'edit_' . $type . '_meta', $object_id, $key )
			|| ( 'term' === $type && self::is_term_seo_key( $key ) )
			|| self::is_listed( $key );
	}

	/**
	 * The gate for term meta: a protected key needs the capability, a term SEO key the
	 * tools document, or the administrator's list.
	 *
	 * @param string $key     Meta key.
	 * @param int    $term_id Term ID.
	 * @return bool True when the list opened the key, so the value guards apply.
	 * @throws Tool_Refusal When the key stays closed.
	 */
	public static function assert_term_key( $key, $term_id ) {
		if ( ! is_protected_meta( (string) $key, 'term' )
			|| current_user_can( 'edit_term_meta', $term_id, $key )
			|| self::is_term_seo_key( $key ) ) {
			return false;
		}
		self::assert_listed( $key );
		return true;
	}

	/**
	 * @param string $key Meta key.
	 * @return bool
	 */
	private static function is_term_seo_key( $key ) {
		foreach ( self::TERM_SEO_PREFIXES as $prefix ) {
			if ( 0 === strpos( (string) $key, $prefix ) ) {
				return ! self::is_credential_name( $key );
			}
		}
		return false;
	}

	/**
	 * @param string $key Meta key.
	 * @return bool
	 */
	private static function is_credential_name( $key ) {
		static $server = null;
		if ( null === $server ) {
			$server = new \Royal_MCP\MCP\Server();
		}
		return (bool) $server->is_credential_name( $key );
	}

	/**
	 * The settings textarea as a clean list: exact key names, one per line.
	 *
	 * @param string $text Raw textarea value.
	 * @return string[]
	 */
	public static function parse_list( $text ) {
		$keys = [];
		foreach ( preg_split( '/\r?\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( 1 === preg_match( '/^[A-Za-z0-9_.:\-]{1,191}$/D', $line ) ) {
				$keys[ $line ] = true;
			}
		}
		return array_slice( array_map( 'strval', array_keys( $keys ) ), 0, self::MAX_KEYS );
	}

	/**
	 * Whether a key can never be opened: internals, shop data, data with its own
	 * tool, this plugin's own state and credential-shaped names.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_blocked( $key ) {
		$key   = (string) $key;
		$lower = strtolower( $key );
		if ( in_array( $lower, self::BLOCKED_KEYS, true ) ) {
			return true;
		}
		foreach ( self::BLOCKED_PREFIXES as $prefix ) {
			if ( 0 === strpos( $lower, $prefix ) ) {
				return true;
			}
		}
		if ( null !== Seo_Plugin::owner_of_meta_key( $key ) || null !== Seo_Plugin::owner_of_meta_key( $lower ) ) {
			return true;
		}
		return self::is_credential_name( $key );
	}

	/**
	 * The entries of a list that stay closed, for the settings screen.
	 *
	 * @param string[] $keys Listed keys.
	 * @return string[]
	 */
	public static function blocked_entries( array $keys ) {
		return array_values( array_filter( $keys, [ __CLASS__, 'is_blocked' ] ) );
	}

	/**
	 * The keys the administrator listed.
	 *
	 * @return string[]
	 */
	public static function listed_keys() {
		$settings = get_option( 'royal_mcp_settings', [] );
		$keys     = is_array( $settings ) ? ( $settings[ self::SETTING ] ?? [] ) : [];
		return is_array( $keys ) ? array_values( array_filter( $keys, 'is_string' ) ) : [];
	}

	/**
	 * Whether the list opens this exact key.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_listed( $key ) {
		return in_array( (string) $key, self::listed_keys(), true ) && ! self::is_blocked( $key );
	}

	/**
	 * Refuses a protected key the list does not open. Returns normally when it does.
	 *
	 * @param string $key Meta key.
	 * @throws Tool_Refusal When the key stays closed.
	 */
	public static function assert_listed( $key ) {
		if ( self::is_blocked( $key ) ) {
			throw new Tool_Refusal( 'protected_meta_blocked', 'This protected meta key is always refused: WordPress, another plugin or Royal MCP depends on it, or a dedicated tool writes it.' );
		}
		if ( ! self::is_listed( $key ) ) {
			throw new Tool_Refusal( 'protected_meta_not_listed', 'This is a protected meta key. An administrator can allow it under Royal MCP > Settings > Writable protected meta keys.' );
		}
	}

	/**
	 * Checks a value on its way to a key opened by the list.
	 *
	 * @param string $key       Meta key.
	 * @param mixed  $value     Filtered value about to be stored.
	 * @param int    $object_id Post or term ID.
	 * @param string $type      'post' or 'term'.
	 * @throws Tool_Refusal When the value may not be stored.
	 */
	public static function assert_value( $key, $value, $object_id, $type = 'post' ) {
		if ( is_object( $value ) ) {
			throw new Tool_Refusal( 'protected_meta_value', 'A protected meta value must be text, a number, true or false, or a list.' );
		}
		if ( self::holds_serialized( $value ) ) {
			throw new Tool_Refusal( 'protected_meta_value', 'This value looks like serialized PHP, so nothing was written. Send it as JSON instead.' );
		}
		if ( strlen( (string) maybe_serialize( $value ) ) > self::MAX_VALUE_BYTES ) {
			throw new Tool_Refusal( 'protected_meta_value', 'This value is over the 64 KB limit for protected meta keys, so nothing was written.' );
		}
		if ( is_string( $value ) && self::is_field_reference( $value ) ) {
			throw new Tool_Refusal( 'protected_meta_blocked', 'This value is an ACF field reference. Use the ACF tools to change ACF fields.' );
		}
		// An update changes every row of the key, so every row counts.
		foreach ( (array) get_metadata( $type, $object_id, $key, false ) as $row ) {
			if ( self::is_field_reference( $row ) ) {
				throw new Tool_Refusal( 'protected_meta_blocked', 'This key holds an ACF field reference. Use the ACF tools to change ACF fields.' );
			}
			if ( is_array( $row ) !== is_array( $value ) ) {
				throw new Tool_Refusal( 'protected_meta_value', 'This value would change the key from a list to a single value, or the reverse, so nothing was written.' );
			}
		}
	}

	/**
	 * Refuses a delete of a key that holds an ACF field reference.
	 *
	 * @param string $key       Meta key.
	 * @param int    $object_id Post or term ID.
	 * @param string $type      'post' or 'term'.
	 * @throws Tool_Refusal When a stored row is a field reference.
	 */
	public static function assert_deletable( $key, $object_id, $type = 'post' ) {
		foreach ( (array) get_metadata( $type, $object_id, $key, false ) as $row ) {
			if ( self::is_field_reference( $row ) ) {
				throw new Tool_Refusal( 'protected_meta_blocked', 'This key holds an ACF field reference. Use the ACF tools to change ACF fields.' );
			}
		}
	}

	/**
	 * Whether a write ACF is making during a tool call must be stopped. Field names and
	 * paths decide the meta keys ACF writes, so each key is checked as it is written:
	 * a post the caller can't edit, a disguised key, a protected key another owner holds,
	 * or any protected key given something other than a field reference.
	 *
	 * @param int    $post_id Post ACF is writing to, which a bidirectional field can make another post.
	 * @param string $key     Meta key ACF is writing or deleting.
	 * @param mixed  $value   Value being written.
	 * @param bool   $delete  True for a delete.
	 * @return bool
	 */
	public static function acf_write_refused( $post_id, $key, $value = null, $delete = false ) {
		$key = (string) $key;
		if ( ! current_user_can( 'edit_post', $post_id ) || self::is_disguised( $key ) ) {
			return true;
		}
		if ( ! is_protected_meta( $key, 'post' ) || current_user_can( 'edit_post_meta', $post_id, $key ) ) {
			return false;
		}
		if ( 0 !== strpos( $key, '_' ) || self::acf_reference_conflict( $post_id, substr( $key, 1 ) ) ) {
			return true;
		}
		return ! $delete && ! self::is_field_reference( $value );
	}

	/**
	 * Refuses order meta arguments before anything on the order changes.
	 *
	 * @param mixed $meta_data The tool's meta_data argument.
	 * @throws \Exception When an entry can't be written.
	 */
	public static function assert_order_meta_args( $meta_data ) {
		if ( empty( $meta_data ) || ! is_array( $meta_data ) ) {
			return;
		}
		foreach ( $meta_data as $entry ) {
			if ( ! is_array( $entry ) || ! array_key_exists( 'key', $entry ) ) {
				continue;
			}
			if ( ! is_string( $entry['key'] ) || '' === trim( $entry['key'] ) ) {
				throw new \Exception( 'Each order meta key must be a non-empty string.' );
			}
			$key = sanitize_text_field( $entry['key'] );
			if ( '' === $key ) {
				throw new \Exception( 'Each order meta key must be a non-empty string.' );
			}
			if ( self::is_protected_key( $key ) ) {
				throw new \Exception( 'Order meta key ' . esc_html( $key ) . ' is protected and cannot be written.' );
			}
		}
	}

	/**
	 * Whether an ACF field's reference row would land on a key holding other data. ACF
	 * stores a reference under the field name with an underscore in front, so a field
	 * named thumbnail_id writes over _thumbnail_id.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $name    Field name.
	 * @return bool
	 */
	public static function acf_reference_conflict( $post_id, $name ) {
		global $wpdb;
		$reference = '_' . (string) $name;
		$lower     = strtolower( $reference );
		if ( in_array( $lower, self::CORE_REFERENCE_KEYS, true ) ) {
			return true;
		}
		foreach ( self::ALWAYS_OWNED_PREFIXES as $prefix ) {
			if ( 0 === strpos( $lower, $prefix ) ) {
				return true;
			}
		}
		// Shop keys mean something only on shop items: elsewhere a field named price is ordinary.
		if ( in_array( get_post_type( $post_id ), self::SHOP_POST_TYPES, true ) && self::is_blocked( $reference ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- every spelling the database would match; the object cache keys them exactly
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", (int) $post_id, $reference ) );
		foreach ( (array) $rows as $row ) {
			if ( '' !== (string) $row && ! self::is_field_reference( (string) $row ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param mixed $value Stored or incoming value.
	 * @return bool
	 */
	public static function is_field_reference( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^field_[a-z0-9_\-]+$/iD', $value );
	}

	/**
	 * @param mixed $value Value or nested list.
	 * @return bool
	 */
	private static function holds_serialized( $value ) {
		if ( is_string( $value ) ) {
			return is_serialized( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $item ) {
				if ( self::holds_serialized( $item ) || ( is_string( $k ) && is_serialized( $k ) ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
