<?php
/**
 * Before/after view of one change an AI client made, built from its undo
 * snapshot: the stored earlier value, the value written (when the
 * snapshot records it) and the value now, field by field, with a line
 * diff for long text such as post content, CSS and builder data.
 *
 * Reads only what the undo snapshot already holds plus the live object, so
 * nothing is kept past the snapshot's 72-hour window.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Change_View {

	const WINDOW_HOURS    = 72;
	const MAX_VALUE_CHARS = 4000;
	const MAX_DIFF_LINES  = 400;
	const MAX_DIFF_BYTES  = 65536;
	const MAX_DIFF_INPUT  = 5000;
	const DIFF_CONTEXT    = 3;
	const MAX_ROWS        = 100;

	/** Snapshot op names that differ from the tool that made the change. */
	const OP_TOOLS = [
		'wp_delete_post_force'      => 'wp_delete_post',
		'wp_delete_post_trash'      => 'wp_delete_post',
		'wp_delete_comment_force'   => 'wp_delete_comment',
		'wp_delete_comment_trash'   => 'wp_delete_comment',
		'wc_delete_variation_force' => 'wc_delete_variation',
		'wc_delete_variation_trash' => 'wc_delete_variation',
		'wc_delete_coupon_force'    => 'wc_delete_coupon',
		'wc_delete_coupon_trash'    => 'wc_delete_coupon',
	];

	/** Snapshot fields never shown: commenter contact and device details. */
	const HIDDEN_FIELDS = [ 'comment_author_email', 'comment_author_IP', 'comment_agent' ];

	/** Ops whose undo refuses when the object changed after the tracked write. */
	const DRIFT_CHECKED = [
		'wp_update_post_meta', 'wp_add_post_meta', 'wp_update_term_meta', 'wp_update_option',
		'wp_update_theme_mod', 'wp_update_custom_css', 'wp_update_permalink_structure',
		'wp_update_media', 'wp_update_term', 'wp_update_menu_item', 'wp_update_seo_meta',
		'wc_update_product', 'wc_update_order', 'wc_update_variation', 'wc_update_coupon',
		'wc_update_order_status', 'wp_set_comment_status',
	];

	/** The tool that made a change. */
	public static function tool_name( array $change ) {
		$op = (string) ( $change['op'] ?? '' );
		if ( ! empty( $change['pre_op_state']['original_op'] ) ) {
			return (string) $change['pre_op_state']['original_op'];
		}
		return self::OP_TOOLS[ $op ] ?? $op;
	}

	/**
	 * Whether the current user may see this change: the same capability the
	 * undo of this change requires.
	 */
	public static function can_view( array $change ) {
		$op  = (string) ( $change['op'] ?? '' );
		$t   = (array) ( $change['target'] ?? [] );
		$pre = (array) ( $change['pre_op_state'] ?? [] );
		foreach ( self::cap_rules( $op, $t, $pre ) as $rule ) {
			if ( count( $rule ) > 1 && ! self::object_exists( $rule[0], (int) $rule[1] ) ) {
				// The object is gone, so there is nothing to check a per-object
				// capability against; administrators can still review the change.
				if ( current_user_can( 'manage_options' ) ) {
					return true;
				}
				continue;
			}
			if ( count( $rule ) > 1 ? current_user_can( $rule[0], $rule[1] ) : current_user_can( $rule[0] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether the object a per-object capability refers to still exists. */
	private static function object_exists( $cap, $id ) {
		if ( $id <= 0 ) {
			return false;
		}
		if ( false !== strpos( $cap, 'comment' ) ) {
			return (bool) get_comment( $id );
		}
		if ( false !== strpos( $cap, 'term' ) ) {
			return get_term( $id ) instanceof \WP_Term;
		}
		if ( false !== strpos( $cap, 'shop_order' ) && function_exists( 'wc_get_order' ) ) {
			return (bool) wc_get_order( $id );
		}
		return (bool) get_post( $id );
	}

	/**
	 * Capability alternatives per op; any one passing is enough.
	 *
	 * @return array<int, array>
	 */
	private static function cap_rules( $op, array $t, array $pre ) {
		$id = static function ( $key ) use ( $t ) {
			return (int) ( $t[ $key ] ?? 0 );
		};
		switch ( $op ) {
			case 'wp_update_post':
			case 'wp_update_post_meta':
			case 'wp_add_post_meta':
			case 'wp_update_seo_meta':
			case 'elementor_apply_template_to_page':
			case 'elementor_replace_text':
			case 'elementor_replace_image':
			case 'elementor_add_widget':
			case 'elementor_rebuild_post_content':
				return [ [ 'edit_post', $id( 'post_id' ) ] ];
			case 'divi_replace_image':
			case 'divi_import_template':
				return [ [ 'edit_post', $id( 'post_id' ) ?: $id( 'target_post_id' ) ] ];
			case 'wp_update_media':
				return [ [ 'edit_post', $id( 'media_id' ) ] ];
			case 'wp_delete_post_force':
				return [ [ 'delete_posts' ] ];
			case 'wp_delete_post_trash':
				return [ [ 'delete_post', $id( 'post_id' ) ] ];
			case 'wp_update_term':
			case 'wp_update_term_meta':
				return [ [ 'edit_term', $id( 'term_id' ) ] ];
			case 'wp_delete_term':
				return [ [ 'manage_categories' ], [ 'edit_terms', $id( 'original_term_id' ) ] ];
			case 'wp_create_comment':
			case 'wp_delete_comment_trash':
				return [ [ 'edit_comment', $id( 'comment_id' ) ] ];
			case 'wp_delete_comment_force':
			case 'wp_set_comment_status':
				return [ [ 'moderate_comments' ] ];
			case 'wp_update_option':
			case 'wp_update_permalink_structure':
				return [ [ 'manage_options' ] ];
			case 'wp_update_menu_item':
			case 'wp_delete_menu_item':
			case 'wp_reorder_menu_items':
			case 'wp_update_theme_mod':
			case 'wp_update_widget':
				return [ [ 'edit_theme_options' ] ];
			case 'wp_update_custom_css':
				return [ [ 'unfiltered_html' ] ];
			case 'wc_create_product':
				return [ [ 'delete_product', $id( 'product_id' ) ] ];
			case 'wc_update_product':
			case 'wc_batch_update_variations':
			case 'wc_set_product_attributes':
				return [ [ 'edit_product', $id( 'product_id' ) ] ];
			case 'wc_update_order_status':
			case 'wc_update_order':
			case 'wc_add_order_note':
				return [ [ 'edit_shop_order', $id( 'order_id' ) ] ];
			case 'wc_create_order':
				return [ [ 'edit_shop_orders' ] ];
			case 'wc_create_variation':
			case 'wc_delete_variation_trash':
				return [ [ 'delete_product', $id( 'variation_id' ) ] ];
			case 'wc_update_variation':
				return [ [ 'edit_product', $id( 'variation_id' ) ] ];
			case 'wc_delete_variation_force':
				return [ [ 'edit_products' ] ];
			case 'wc_create_product_attribute':
				return [ [ 'manage_product_terms' ] ];
			case 'wc_create_coupon':
				return [ [ 'delete_shop_coupon', $id( 'coupon_id' ) ], [ 'manage_woocommerce' ] ];
			case 'wc_update_coupon':
			case 'wc_delete_coupon_trash':
				return [ [ 'edit_shop_coupon', $id( 'coupon_id' ) ], [ 'manage_woocommerce' ] ];
			case 'wc_delete_coupon_force':
			case 'wc_empty_coupon_trash':
				return [ [ 'manage_woocommerce' ] ];
			case 'elementor_clone_page':
			case 'elementor_import_template':
				return [ [ 'delete_post', (int) ( $pre['created_post_id'] ?? 0 ) ] ];
			case 'divi_clone_page':
				return [ [ 'delete_post', $id( 'created_post_id' ) ] ];
		}
		return [ [ 'manage_options' ] ];
	}

	/**
	 * Which connection made the change.
	 *
	 * @param array $via Stored connection context.
	 * @return array{type: string, label: string}
	 */
	public static function connection( array $via ) {
		if ( [] === $via ) {
			return [ 'type' => 'unknown', 'label' => __( 'Not recorded', 'royal-mcp' ) ];
		}
		$auth = (string) ( $via['auth'] ?? '' );
		if ( 'abilities' === ( $via['route'] ?? '' ) ) {
			return [ 'type' => 'other-mcp-server', 'label' => __( 'Another MCP server (WordPress Abilities API)', 'royal-mcp' ) ];
		}
		if ( 'cookie-session' === $auth ) {
			return [ 'type' => 'browser-agent', 'label' => __( 'Browser agent (signed-in WordPress session)', 'royal-mcp' ) ];
		}
		if ( 'oauth-bearer' === $auth ) {
			$client_id = (string) ( $via['client_id'] ?? '' );
			$client    = '' !== $client_id ? \Royal_MCP\OAuth\Token_Store::get_client( $client_id ) : null;
			$name      = is_array( $client ) && ! empty( $client['client_name'] ) ? (string) $client['client_name'] : $client_id;
			return [ 'type' => 'oauth-client', 'label' => '' !== $name ? $name : __( 'OAuth client', 'royal-mcp' ), 'client_id' => $client_id ];
		}
		if ( 'api-key' === $auth ) {
			return [ 'type' => 'api-key', 'label' => __( 'API key', 'royal-mcp' ) ];
		}
		return [ 'type' => 'none', 'label' => __( 'No MCP connection (WP-CLI or another plugin)', 'royal-mcp' ) ];
	}

	/** Expired, undone, or still active. */
	public static function status( array $change ) {
		if ( (int) ( $change['expires_at'] ?? 0 ) <= time() ) {
			return 'expired';
		}
		return empty( $change['undone_at'] ) ? 'active' : 'undone';
	}

	/**
	 * One row for a list of changes.
	 *
	 * @param array $change     Snapshot from Undo_Store::all_changes().
	 * @param bool  $with_token Include the undo token (only for the user who made the change).
	 */
	public static function summary( array $change, $with_token ) {
		$status = self::status( $change );
		$out    = [
			'change_id'     => (string) ( $change['change_id'] ?? '' ),
			'time'          => gmdate( 'c', (int) ( $change['created_at'] ?? 0 ) ),
			'tool'          => self::tool_name( $change ),
			'target_label'  => self::target_label( (array) ( $change['target'] ?? [] ) ),
			'undo_summary'  => (string) ( $change['summary'] ?? '' ),
			'target'        => (array) ( $change['target'] ?? [] ),
			'connection'    => self::connection( (array) ( $change['via'] ?? [] ) ),
			'status'        => $status,
			'undo_possible' => 'active' === $status,
			'expires_at'    => gmdate( 'c', (int) ( $change['expires_at'] ?? 0 ) ),
		];
		if ( ! empty( $change['undone_at'] ) ) {
			$out['undone_at'] = gmdate( 'c', (int) $change['undone_at'] );
		}
		if ( $with_token && 'active' === $status && ! empty( $change['token'] ) ) {
			$out['undo_token'] = (string) $change['token'];
		}
		return $out;
	}

	/**
	 * The full before/after view of one change.
	 *
	 * @param array    $change     Snapshot from Undo_Store::find_change().
	 * @param callable $redact     fn( $value, $key_hint ) masking credential-shaped values.
	 * @param bool     $with_token Include the undo token.
	 * @param bool     $full_text  Include the full before/now text of diffed fields (admin view).
	 */
	public static function detail( array $change, callable $redact, $with_token, $full_text = false ) {
		$out = self::summary( $change, $with_token );
		if ( 'expired' === $out['status'] ) {
			$out['fields'] = [];
			$out['diffs']  = [];
			$out['note']   = __( 'This change is older than 72 hours, so its before state is no longer kept.', 'royal-mcp' );
			return $out;
		}
		$op       = (string) ( $change['op'] ?? '' );
		$resolved = self::resolve( $op, (array) ( $change['target'] ?? [] ), (array) ( $change['pre_op_state'] ?? [] ) );

		$changed_since = false;
		$fields        = [];
		$unchanged     = [];
		foreach ( $resolved['rows'] as $i => $row ) {
			if ( self::untouched( $row ) ) {
				$unchanged[] = (string) $row['field'];
				unset( $resolved['rows'][ $i ] );
			}
		}
		$resolved['rows'] = array_values( $resolved['rows'] );
		foreach ( array_slice( $resolved['rows'], 0, self::MAX_ROWS ) as $row ) {
			$hint = (string) ( $row['key_hint'] ?? $row['field'] );
			$item = [ 'field' => (string) $row['field'] ];
			foreach ( [ 'before', 'after', 'current' ] as $part ) {
				if ( array_key_exists( $part, $row ) ) {
					$item[ $part ] = self::clip( $redact( $row[ $part ], $hint ), $truncated );
					if ( $truncated ) {
						$item['truncated'] = true;
					}
				}
			}
			if ( ! empty( $row['absent'] ) ) {
				$item['absent'] = array_values( (array) $row['absent'] );
			}
			$absent = (array) ( $row['absent'] ?? [] );
			if ( in_array( $op, self::DRIFT_CHECKED, true ) && array_key_exists( 'after', $row ) && array_key_exists( 'current', $row )
				&& ! in_array( 'after', $absent, true ) ) {
				$differs = in_array( 'current', $absent, true ) || ! self::same( $row['after'], $row['current'] );
				$item['changed_since'] = $differs;
				$changed_since         = $changed_since || $differs;
			}
			$fields[] = $item;
		}
		$out['fields'] = $fields;
		if ( $unchanged ) {
			$out['unchanged_fields'] = $unchanged;
		}
		if ( count( $resolved['rows'] ) > self::MAX_ROWS ) {
			$out['fields_truncated'] = true;
		}

		$diffs = [];
		foreach ( $resolved['texts'] as $field => $pair ) {
			$before  = (string) $redact( (string) $pair[0], $field );
			$current = (string) $redact( (string) $pair[1], $field );
			if ( $before === $current ) {
				continue;
			}
			$diff = self::unified( $before, $current );
			$entry = [ 'field' => (string) $field, 'compare' => 'before vs now' ] + $diff;
			if ( $full_text ) {
				$entry['before_text']  = $before;
				$entry['current_text'] = $current;
			}
			$diffs[] = $entry;
		}
		$out['diffs'] = $diffs;

		$notes = array_filter( [ (string) $resolved['note'] ] );
		if ( $changed_since ) {
			$out['changed_since_change'] = true;
			if ( 'active' === $out['status'] ) {
				$notes[] = __( 'This has been changed again since; undo restores only when the value is still what this change wrote, so it will likely be refused.', 'royal-mcp' );
			}
		}
		if ( 'undone' === $out['status'] ) {
			$notes[] = __( 'This change was undone. "Before" is what undo restored.', 'royal-mcp' );
		}
		if ( $notes ) {
			$out['note'] = implode( ' ', $notes );
		}
		return $out;
	}

	/**
	 * Rows and long-text pairs for one snapshot.
	 *
	 * Row keys: field, before / after / current (each optional), absent (which
	 * of the three did not exist), key_hint (the name used for redaction).
	 *
	 * @return array{rows: array, texts: array<string, array{0: string, 1: string}>, note: string}
	 */
	private static function resolve( $op, array $t, array $pre ) {
		$rows  = [];
		$texts = [];
		$note  = '';
		$int   = static function ( $key ) use ( $t ) {
			return (int) ( $t[ $key ] ?? 0 );
		};

		switch ( $op ) {
			case 'wp_update_post':
				$post = get_post( $int( 'post_id' ) );
				foreach ( (array) ( $pre['prior_values'] ?? [] ) as $field => $before ) {
					$row = [ 'field' => $field, 'before' => $before ];
					if ( $post ) {
						$row['current'] = $post->$field;
						if ( in_array( $field, [ 'post_content', 'post_excerpt' ], true ) ) {
							$texts[ $field ] = [ (string) $before, (string) $post->$field ];
						}
					} else {
						$row['absent'] = [ 'current' ];
					}
					$rows[] = $row;
				}
				if ( array_key_exists( 'prior_featured_id', $pre ) ) {
					$rows[] = [ 'field' => 'featured_media', 'before' => (int) $pre['prior_featured_id'], 'current' => $post ? (int) get_post_thumbnail_id( $post ) : 0 ];
				}
				if ( ! $post ) {
					$note = __( 'The post no longer exists.', 'royal-mcp' );
				}
				break;

			case 'wp_update_media':
				$post = get_post( $int( 'media_id' ) );
				$cols = [ 'title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content' ];
				foreach ( (array) ( $pre['prior_values'] ?? [] ) as $field => $before ) {
					$current = null;
					if ( $post ) {
						$current = 'alt_text' === $field
							? (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true )
							: ( isset( $cols[ $field ] ) ? $post->{$cols[ $field ]} : null );
					}
					$rows[] = self::pair_row( $field, $before, $pre['applied_values'][ $field ] ?? null, $current, (bool) $post );
				}
				break;

			case 'wp_update_term':
				$term = get_term( $int( 'term_id' ), (string) ( $t['taxonomy'] ?? '' ) );
				$term = $term instanceof \WP_Term ? $term : null;
				foreach ( (array) ( $pre['prior_values'] ?? [] ) as $field => $before ) {
					$current = $term ? ( 'parent' === $field ? (int) $term->parent : $term->$field ) : null;
					$rows[]  = self::pair_row( $field, $before, $pre['applied_values'][ $field ] ?? null, $current, (bool) $term );
				}
				break;

			case 'wp_update_post_meta':
			case 'wp_update_term_meta':
				$key   = (string) ( $t['meta_key'] ?? '' );
				$is_pm = 'wp_update_post_meta' === $op;
				$oid   = $is_pm ? $int( 'post_id' ) : $int( 'term_id' );
				$type  = $is_pm ? 'post' : 'term';
				$has   = metadata_exists( $type, $oid, $key );
				$row   = self::pair_row( $key, $pre['prior_value'] ?? null, $pre['applied_value'] ?? null, $has ? get_metadata( $type, $oid, $key, true ) : null, $has );
				$row['key_hint'] = $key;
				$rows[] = $row;
				break;

			case 'wp_add_post_meta':
				$key     = (string) ( $t['meta_key'] ?? '' );
				$current = get_metadata_by_mid( 'post', (int) ( $t['meta_id'] ?? 0 ) );
				$row     = [ 'field' => $key, 'key_hint' => $key, 'before' => null, 'after' => $pre['added_value'] ?? null, 'absent' => [ 'before' ] ];
				if ( $current && isset( $current->meta_value ) ) {
					$row['current'] = maybe_unserialize( $current->meta_value );
				} else {
					$row['current']  = null;
					$row['absent'][] = 'current';
				}
				$rows[] = $row;
				break;

			case 'wp_update_option':
			case 'wp_update_theme_mod':
				$is_opt  = 'wp_update_option' === $op;
				$name    = (string) ( $is_opt ? ( $t['option_name'] ?? '' ) : ( $t['mod_name'] ?? '' ) );
				$missing = new \stdClass();
				if ( $is_opt ) {
					$current = get_option( $name, $missing );
				} else {
					$mods    = (array) get_theme_mods();
					$current = array_key_exists( $name, $mods ) ? $mods[ $name ] : $missing;
				}
				$row = [ 'field' => $name, 'key_hint' => $name, 'before' => $pre['prior_value'] ?? null, 'after' => $pre['applied_value'] ?? null ];
				if ( empty( $pre['existed_before'] ) ) {
					$row['before'] = null;
					$row['absent'] = [ 'before' ];
				}
				if ( $current === $missing ) {
					$row['current']  = null;
					$row['absent'][] = 'current';
				} else {
					$row['current'] = $current;
				}
				$rows[] = $row;
				break;

			case 'wp_update_custom_css':
				$current = (string) wp_get_custom_css( (string) ( $t['theme_slug'] ?? '' ) );
				$rows[]  = [ 'field' => 'custom_css', 'before' => (string) ( $pre['prior_css'] ?? '' ), 'after' => (string) ( $pre['applied_css'] ?? '' ), 'current' => $current ];
				$texts['custom_css'] = [ (string) ( $pre['prior_css'] ?? '' ), $current ];
				break;

			case 'wp_update_permalink_structure':
				$rows[] = [ 'field' => 'permalink_structure', 'before' => (string) ( $pre['prior_structure'] ?? '' ), 'after' => (string) ( $pre['applied_structure'] ?? '' ), 'current' => (string) get_option( 'permalink_structure', '' ) ];
				break;

			case 'wp_update_menu_item':
				$item_post = get_post( $int( 'menu_item_id' ) );
				$item      = $item_post ? wp_setup_nav_menu_item( $item_post ) : null;
				$props     = [ 'title' => 'title', 'url' => 'url', 'parent_id' => 'menu_item_parent', 'position' => 'menu_order', 'target' => 'target' ];
				foreach ( (array) ( $pre['prior_values'] ?? [] ) as $field => $before ) {
					$current = null;
					if ( $item && isset( $props[ $field ] ) ) {
						$current = in_array( $field, [ 'parent_id', 'position' ], true ) ? (int) $item->{$props[ $field ]} : $item->{$props[ $field ]};
					}
					$rows[] = self::pair_row( $field, $before, $pre['applied_values'][ $field ] ?? null, $current, (bool) $item );
				}
				break;

			case 'wp_update_seo_meta':
				$pid     = $int( 'post_id' );
				$adapter = (string) ( $t['adapter'] ?? '' );
				$map     = Seo_Plugin::field_maps()[ $adapter ] ?? [];
				$exists  = (bool) get_post( $pid );
				foreach ( (array) ( $pre['prior_values'] ?? [] ) as $field => $before ) {
					if ( 'slug' === $field ) {
						$current = (string) get_post_field( 'post_name', $pid );
					} elseif ( 'noindex' === $field ) {
						$current = self::seo_noindex( $adapter, $pid );
					} else {
						$current = isset( $map[ $field ] ) ? (string) get_post_meta( $pid, $map[ $field ], true ) : null;
					}
					$rows[] = self::pair_row( $field, $before, $pre['applied_values'][ $field ] ?? null, $current, $exists );
				}
				break;

			case 'wc_update_product':
			case 'wc_update_variation':
				$obj = function_exists( 'wc_get_product' ) ? wc_get_product( $int( 'wc_update_product' === $op ? 'product_id' : 'variation_id' ) ) : null;
				foreach ( (array) ( $pre['prior_values'] ?? [] ) as $field => $before ) {
					$getter = 'get_' . ( 0 === strpos( (string) $field, 'dimensions.' ) ? substr( (string) $field, 11 ) : $field );
					$current = $obj && is_callable( [ $obj, $getter ] ) ? $obj->$getter( 'edit' ) : null;
					$rows[]  = self::pair_row( $field, $before, $pre['applied_values'][ $field ] ?? null, $current, (bool) $obj );
				}
				if ( $obj && isset( $pre['prior_values']['description'] ) ) {
					$texts['description'] = [ (string) $pre['prior_values']['description'], (string) $obj->get_description( 'edit' ) ];
				}
				break;

			case 'wc_update_order':
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( $int( 'order_id' ) ) : null;
				foreach ( (array) ( $pre['prior_values'] ?? [] ) as $field => $before ) {
					$getter  = 'get_' . str_replace( '.', '_', (string) $field );
					$current = $order && is_callable( [ $order, $getter ] ) ? $order->$getter( 'edit' ) : null;
					$rows[]  = self::pair_row( $field, $before, $pre['applied_values'][ $field ] ?? null, $current, (bool) $order );
				}
				break;

			case 'wc_update_coupon':
				$coupon = class_exists( '\WC_Coupon' ) && get_post( $int( 'coupon_id' ) ) ? new \WC_Coupon( $int( 'coupon_id' ) ) : null;
				foreach ( (array) ( $pre['prior_values'] ?? [] ) as $field => $before ) {
					$getter  = 'get_' . $field;
					$current = $coupon && is_callable( [ $coupon, $getter ] ) ? $coupon->$getter( 'edit' ) : null;
					if ( $current instanceof \DateTimeInterface ) {
						$current = $current->getTimestamp();
					}
					$rows[] = self::pair_row( $field, $before, $pre['applied_values'][ $field ] ?? null, $current, (bool) $coupon );
				}
				break;

			case 'wc_update_order_status':
				$order  = function_exists( 'wc_get_order' ) ? wc_get_order( $int( 'order_id' ) ) : null;
				$rows[] = self::pair_row( 'status', $pre['prior_status'] ?? null, $pre['applied_status'] ?? null, $order ? $order->get_status() : null, (bool) $order );
				break;

			case 'wp_set_comment_status':
			case 'wp_delete_comment_trash':
				$comment = get_comment( $int( 'comment_id' ) );
				$after   = 'wp_set_comment_status' === $op ? ( $pre['applied_status'] ?? null ) : 'trash';
				$rows[]  = self::pair_row( 'status', $pre['prior_status'] ?? null, $after, $comment ? self::comment_status( $comment->comment_approved ) : null, (bool) $comment );
				break;

			case 'wp_delete_post_trash':
			case 'wc_delete_variation_trash':
			case 'wc_delete_coupon_trash':
				$key  = 'wp_delete_post_trash' === $op ? 'post_id' : ( 'wc_delete_variation_trash' === $op ? 'variation_id' : 'coupon_id' );
				$post = get_post( $int( $key ) );
				$row  = [ 'field' => 'post_status', 'after' => 'trash' ];
				if ( $post ) {
					$row['current'] = $post->post_status;
				} else {
					$row['current'] = null;
					$row['absent']  = [ 'current' ];
				}
				$rows[] = $row;
				$note   = __( 'Moved to the trash; the status before is not recorded.', 'royal-mcp' );
				break;

			case 'wp_delete_post_force':
			case 'wp_delete_term':
			case 'wp_delete_comment_force':
			case 'wp_delete_menu_item':
			case 'wc_delete_variation_force':
			case 'wc_delete_coupon_force':
				$row_data = (array) ( $pre['row'] ?? [] );
				$gone     = ! self::original_exists( $op, $t );
				foreach ( $row_data as $field => $before ) {
					if ( in_array( $field, self::HIDDEN_FIELDS, true ) ) {
						continue;
					}
					if ( 'meta' === $field || 'terms' === $field ) {
						$before = is_array( $before ) ? count( $before ) . ' ' . $field : $before;
					}
					$row = [ 'field' => (string) $field, 'before' => $before ];
					if ( $gone ) {
						$row['current'] = null;
						$row['absent']  = [ 'current' ];
					}
					$rows[] = $row;
				}
				if ( isset( $row_data['post_content'] ) && $gone ) {
					$texts['post_content'] = [ (string) $row_data['post_content'], '' ];
				}
				$note = $gone
					? __( 'Deleted permanently. Undo recreates it under a new ID.', 'royal-mcp' )
					: __( 'Deleted; an item with the original ID exists again.', 'royal-mcp' );
				break;

			case 'wc_empty_coupon_trash':
				$codes  = array_map( static function ( $r ) {
					return (string) ( $r['code'] ?? '' );
				}, (array) ( $pre['rows'] ?? [] ) );
				$rows[] = [ 'field' => 'coupons', 'before' => $codes, 'current' => null, 'absent' => [ 'current' ] ];
				$note   = __( 'Trashed coupons deleted permanently. Undo recreates them under new IDs.', 'royal-mcp' );
				break;

			case 'wp_create_comment':
			case 'wc_add_order_note':
				$cid     = $int( 'wp_create_comment' === $op ? 'comment_id' : 'note_id' );
				$comment = get_comment( $cid );
				$rows[]  = self::created_row( $comment ? sprintf( 'comment %d: %s', $cid, wp_trim_words( (string) $comment->comment_content, 20 ) ) : null );
				break;

			case 'wc_create_product':
			case 'wc_create_variation':
			case 'wc_create_coupon':
			case 'elementor_clone_page':
			case 'elementor_import_template':
			case 'divi_clone_page':
				$keys = [ 'wc_create_product' => 'product_id', 'wc_create_variation' => 'variation_id', 'wc_create_coupon' => 'coupon_id', 'divi_clone_page' => 'created_post_id' ];
				$pid  = isset( $keys[ $op ] ) ? $int( $keys[ $op ] ) : (int) ( $pre['created_post_id'] ?? 0 );
				$post = $pid > 0 ? get_post( $pid ) : null;
				$rows[] = self::created_row( $post ? sprintf( '%s %d "%s" (%s)', $post->post_type, $post->ID, $post->post_title, $post->post_status ) : null );
				break;

			case 'wc_create_order':
				$order  = function_exists( 'wc_get_order' ) ? wc_get_order( $int( 'order_id' ) ) : null;
				$rows[] = self::created_row( $order ? sprintf( 'order %d (%s)', $order->get_id(), $order->get_status() ) : null );
				break;

			case 'wc_create_product_attribute':
				$attr   = function_exists( 'wc_get_attribute' ) ? wc_get_attribute( $int( 'attribute_id' ) ) : null;
				$rows[] = self::created_row( $attr ? sprintf( 'attribute %d "%s"', $int( 'attribute_id' ), $attr->name ) : null );
				break;

			case 'wp_reorder_menu_items':
				foreach ( $pre as $item_id => $prior ) {
					if ( ! is_array( $prior ) ) {
						continue;
					}
					$item   = get_post( (int) $item_id );
					$before = [ 'menu_order' => (int) ( $prior['menu_order'] ?? 0 ), 'menu_item_parent' => (int) ( $prior['menu_item_parent'] ?? 0 ) ];
					$row    = [ 'field' => 'menu item ' . (int) $item_id, 'before' => $before ];
					if ( $item ) {
						$row['current'] = [ 'menu_order' => (int) $item->menu_order, 'menu_item_parent' => (int) get_post_meta( $item->ID, '_menu_item_menu_item_parent', true ) ];
					} else {
						$row['current'] = null;
						$row['absent']  = [ 'current' ];
					}
					$rows[] = $row;
				}
				break;

			case 'wp_update_widget':
				$rows[] = [ 'field' => 'widget ' . (string) ( $t['widget_id'] ?? '' ), 'before' => $pre['prior'] ?? null ];
				$note   = __( 'Current widget settings are not compared here; read them with wp_get_widgets.', 'royal-mcp' );
				break;

			case 'wc_set_product_attributes':
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $int( 'product_id' ) ) : null;
				$current = null;
				if ( $product ) {
					$current = [];
					foreach ( $product->get_attributes( 'edit' ) as $attr ) {
						if ( is_object( $attr ) && is_callable( [ $attr, 'get_name' ] ) ) {
							$current[] = [ 'id' => $attr->get_id(), 'name' => $attr->get_name(), 'position' => $attr->get_position(), 'visible' => $attr->get_visible(), 'variation' => $attr->get_variation(), 'options' => $attr->get_options() ];
						}
					}
				}
				$rows[] = self::pair_row( 'attributes', $pre['attributes_before'] ?? [], null, $current, (bool) $product );
				break;

			case 'wc_batch_update_variations':
				$rows[] = [ 'field' => 'created variations', 'before' => null, 'after' => array_map( 'intval', (array) ( $pre['created'] ?? [] ) ), 'absent' => [ 'before' ] ];
				foreach ( (array) ( $pre['updated'] ?? [] ) as $upd ) {
					$vid     = (int) ( $upd['variation_id'] ?? 0 );
					$obj     = function_exists( 'wc_get_product' ) ? wc_get_product( $vid ) : null;
					$current = null;
					if ( $obj ) {
						$current = [];
						foreach ( array_keys( (array) ( $upd['prior'] ?? [] ) ) as $field ) {
							$getter            = 'get_' . $field;
							$current[ $field ] = is_callable( [ $obj, $getter ] ) ? $obj->$getter( 'edit' ) : null;
						}
					}
					$rows[] = self::pair_row( 'variation ' . $vid, (array) ( $upd['prior'] ?? [] ), null, $current, (bool) $obj );
				}
				$deleted = array_map( static function ( $d ) {
					return (int) ( $d['original_id'] ?? 0 );
				}, (array) ( $pre['deleted'] ?? [] ) );
				if ( $deleted ) {
					$rows[] = [ 'field' => 'deleted variations', 'before' => $deleted, 'current' => null, 'absent' => [ 'current' ] ];
				}
				break;

			case 'elementor_apply_template_to_page':
			case 'elementor_replace_text':
			case 'elementor_replace_image':
			case 'elementor_add_widget':
				$before  = (string) ( $pre['prior_elementor_data'] ?? '' );
				$current = (string) get_post_meta( $int( 'post_id' ), '_elementor_data', true );
				$rows[]  = [ 'field' => '_elementor_data', 'before' => sprintf( '%d bytes', strlen( $before ) ), 'current' => sprintf( '%d bytes', strlen( $current ) ) ];
				$texts['_elementor_data'] = [ self::pretty_json( $before ), self::pretty_json( $current ) ];
				break;

			case 'elementor_rebuild_post_content':
				$post   = get_post( $int( 'post_id' ) );
				$before = (string) ( $pre['prior_content'] ?? '' );
				$rows[] = self::pair_row( 'post_content', $before, null, $post ? $post->post_content : null, (bool) $post );
				if ( $post ) {
					$texts['post_content'] = [ $before, (string) $post->post_content ];
				}
				break;

			case 'divi_replace_image':
			case 'divi_import_template':
				$pid    = $int( 'post_id' ) ?: $int( 'target_post_id' );
				$post   = get_post( $pid );
				$before = (string) ( $pre['post_content'] ?? '' );
				$rows[] = self::pair_row( 'post_content', $before, null, $post ? $post->post_content : null, (bool) $post );
				if ( $post ) {
					$texts['post_content'] = [ $before, (string) $post->post_content ];
				}
				foreach ( (array) ( $pre['meta'] ?? [] ) as $key => $val ) {
					$row = self::pair_row( (string) $key, $val, null, $post ? get_post_meta( $pid, (string) $key, true ) : null, (bool) $post );
					$row['key_hint'] = (string) $key;
					$rows[] = $row;
				}
				break;

			default:
				foreach ( $pre as $field => $before ) {
					$rows[] = [ 'field' => (string) $field, 'before' => $before ];
				}
				$note = __( 'No live comparison for this kind of change.', 'royal-mcp' );
		}

		foreach ( $rows as $row ) {
			$field = (string) $row['field'];
			if ( isset( $texts[ $field ] ) || ! array_key_exists( 'before', $row ) || ! array_key_exists( 'current', $row ) ) {
				continue;
			}
			if ( is_string( $row['before'] ) && is_string( $row['current'] ) && ( false !== strpos( $row['before'] . $row['current'], "\n" ) || strlen( $row['before'] ) > 200 || strlen( $row['current'] ) > 200 ) ) {
				$texts[ $field ] = [ $row['before'], $row['current'] ];
			}
		}

		return [ 'rows' => $rows, 'texts' => $texts, 'note' => $note ];
	}

	/** Nouns for target keys, in the order they name the object. */
	const TARGET_NOUNS = [
		'post_id' => 'post', 'original_post_id' => 'post', 'new_post_id' => 'post', 'created_post_id' => 'post',
		'target_post_id' => 'post', 'template_id' => 'template', 'media_id' => 'media item',
		'product_id' => 'product', 'variation_id' => 'variation', 'original_variation_id' => 'variation',
		'order_id' => 'order', 'note_id' => 'order note', 'coupon_id' => 'coupon', 'original_coupon_id' => 'coupon',
		'attribute_id' => 'attribute', 'term_id' => 'term', 'original_term_id' => 'term',
		'comment_id' => 'comment', 'original_comment_id' => 'comment', 'menu_item_id' => 'menu item',
		'original_menu_item_id' => 'menu item', 'menu_id' => 'menu', 'widget_id' => 'widget',
		'option_name' => 'option', 'mod_name' => 'theme mod', 'theme_slug' => 'custom CSS of theme',
		'meta_key' => 'meta key', 'adapter' => 'SEO plugin', 'code' => 'code',
	];

	/** Short name of what a change touched, for example: post 12 "Hello", meta key price. */
	public static function target_label( array $t ) {
		$parts = [];
		foreach ( self::TARGET_NOUNS as $key => $noun ) {
			if ( ! isset( $t[ $key ] ) || '' === (string) $t[ $key ] || is_array( $t[ $key ] ) ) {
				continue;
			}
			$part = $noun . ' ' . $t[ $key ];
			if ( 'post' === $noun || 'product' === $noun || 'template' === $noun ) {
				$post = get_post( (int) $t[ $key ] );
				if ( $post && current_user_can( 'read_post', $post->ID ) && '' !== $post->post_title ) {
					$part .= ' "' . $post->post_title . '"';
				}
			}
			$parts[] = $part;
		}
		if ( isset( $t['deleted_count'] ) ) {
			$parts[] = (int) $t['deleted_count'] . ' trashed coupons';
		}
		return implode( ', ', $parts );
	}

	/** A field the change did not touch and nothing changed since. */
	private static function untouched( array $row ) {
		if ( ! empty( $row['absent'] ) || ! array_key_exists( 'before', $row ) || ! array_key_exists( 'current', $row ) ) {
			return false;
		}
		if ( array_key_exists( 'after', $row ) && ! self::same( $row['after'], $row['before'] ) ) {
			return false;
		}
		return self::same( $row['before'], $row['current'] );
	}

	/** A before / after / now row; $exists says whether the object still exists. */
	private static function pair_row( $field, $before, $after, $current, $exists ) {
		$row = [ 'field' => (string) $field, 'before' => $before ];
		if ( null !== $after ) {
			$row['after'] = $after;
		}
		if ( $exists ) {
			$row['current'] = $current;
		} else {
			$row['current'] = null;
			$row['absent']  = [ 'current' ];
		}
		return $row;
	}

	/** Row for a change that created something. */
	private static function created_row( $current_label ) {
		$row = [ 'field' => 'created', 'before' => null, 'after' => 'created', 'absent' => [ 'before' ] ];
		if ( null === $current_label ) {
			$row['current']  = null;
			$row['absent'][] = 'current';
		} else {
			$row['current'] = $current_label;
		}
		return $row;
	}

	/** Whether the object a permanent delete removed still has its original ID. */
	private static function original_exists( $op, array $t ) {
		switch ( $op ) {
			case 'wp_delete_term':
				return get_term( (int) ( $t['original_term_id'] ?? 0 ) ) instanceof \WP_Term;
			case 'wp_delete_comment_force':
				return (bool) get_comment( (int) ( $t['original_comment_id'] ?? 0 ) );
		}
		$keys = [ 'original_post_id', 'original_menu_item_id', 'original_variation_id', 'original_coupon_id' ];
		foreach ( $keys as $key ) {
			if ( ! empty( $t[ $key ] ) ) {
				return (bool) get_post( (int) $t[ $key ] );
			}
		}
		return false;
	}

	private static function seo_noindex( $adapter, $pid ) {
		switch ( $adapter ) {
			case 'yoast':
				return '1' === get_post_meta( $pid, '_yoast_wpseo_meta-robots-noindex', true );
			case 'rankmath':
				return in_array( 'noindex', (array) get_post_meta( $pid, 'rank_math_robots', true ), true );
			case 'aioseo':
				return (bool) get_post_meta( $pid, '_aioseo_noindex', true );
			case 'seopress':
				return 'yes' === get_post_meta( $pid, '_seopress_robots_index', true );
			case 'seobolt':
				return (bool) get_post_meta( $pid, '_seobolt_robots_noindex', true );
		}
		return false;
	}

	private static function comment_status( $col ) {
		$map = [ '1' => 'approve', '0' => 'hold', 'spam' => 'spam', 'trash' => 'trash' ];
		return $map[ (string) $col ] ?? (string) $col;
	}

	/** Builder JSON one property per line, so a line diff points at the changed widget. */
	private static function pretty_json( $raw ) {
		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) ) {
			return (string) $raw;
		}
		return (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** Loose equality for values read back from the database. */
	private static function same( $a, $b ) {
		return self::normalize( $a ) === self::normalize( $b );
	}

	private static function normalize( $v ) {
		if ( is_array( $v ) || is_object( $v ) ) {
			$out = [];
			foreach ( (array) $v as $k => $item ) {
				$out[ $k ] = self::normalize( $item );
			}
			return (string) wp_json_encode( $out );
		}
		if ( is_bool( $v ) ) {
			return $v ? '1' : '';
		}
		return null === $v ? '' : (string) $v;
	}

	/** The first $bytes bytes of $text, never ending inside a UTF-8 character. */
	private static function cut( $text, $bytes ) {
		$text = substr( (string) $text, 0, $bytes );
		$len  = strlen( $text );
		$back = 0;
		while ( $back < 3 && $back < $len && 0x80 === ( ord( $text[ $len - 1 - $back ] ) & 0xC0 ) ) {
			$back++;
		}
		if ( $back < $len ) {
			$lead = ord( $text[ $len - 1 - $back ] );
			$need = $lead >= 0xF0 ? 3 : ( $lead >= 0xE0 ? 2 : ( $lead >= 0xC0 ? 1 : 0 ) );
			if ( $need > $back ) {
				$text = substr( $text, 0, $len - 1 - $back );
			}
		}
		return $text;
	}

	/** Cap a value's size for the response. */
	private static function clip( $value, &$truncated ) {
		$truncated = false;
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > self::MAX_VALUE_CHARS ) {
				$truncated = true;
				return self::cut( $value, self::MAX_VALUE_CHARS );
			}
			return $value;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			$json = (string) wp_json_encode( $value );
			if ( strlen( $json ) > self::MAX_VALUE_CHARS ) {
				$truncated = true;
				return self::cut( $json, self::MAX_VALUE_CHARS );
			}
		}
		return $value;
	}

	/**
	 * Unified line diff with context, from WordPress's own diff engine.
	 *
	 * @return array{unified: string, added: int, removed: int, truncated: bool}
	 */
	public static function unified( $before, $after ) {
		$from = explode( "\n", str_replace( "\r\n", "\n", (string) $before ) );
		$to   = explode( "\n", str_replace( "\r\n", "\n", (string) $after ) );
		if ( count( $from ) > self::MAX_DIFF_INPUT || count( $to ) > self::MAX_DIFF_INPUT ) {
			return [ 'unified' => '', 'added' => 0, 'removed' => 0, 'truncated' => true ];
		}
		if ( ! class_exists( '\Text_Diff', false ) ) {
			require_once ABSPATH . WPINC . '/wp-diff.php';
		}
		$diff  = new \Text_Diff( 'auto', [ $from, $to ] );
		$lines = [];
		$at    = 1;
		foreach ( $diff->getDiff() as $edit ) {
			if ( $edit instanceof \Text_Diff_Op_copy ) {
				foreach ( (array) $edit->orig as $line ) {
					$lines[] = [ ' ', $line, $at++ ];
				}
				continue;
			}
			foreach ( (array) ( $edit->orig ?: [] ) as $line ) {
				$lines[] = [ '-', $line, $at++ ];
			}
			foreach ( (array) ( $edit->final ?: [] ) as $line ) {
				$lines[] = [ '+', $line, $at ];
			}
		}

		$keep = [];
		foreach ( $lines as $i => $line ) {
			if ( ' ' !== $line[0] ) {
				for ( $j = max( 0, $i - self::DIFF_CONTEXT ); $j <= min( count( $lines ) - 1, $i + self::DIFF_CONTEXT ); $j++ ) {
					$keep[ $j ] = true;
				}
			}
		}
		$out       = [];
		$added     = 0;
		$removed   = 0;
		$last      = -2;
		$truncated = false;
		foreach ( $lines as $i => $line ) {
			if ( '+' === $line[0] ) {
				$added++;
			} elseif ( '-' === $line[0] ) {
				$removed++;
			}
			if ( empty( $keep[ $i ] ) ) {
				continue;
			}
			if ( count( $out ) >= self::MAX_DIFF_LINES ) {
				$truncated = true;
				continue;
			}
			if ( $i !== $last + 1 ) {
				$out[] = sprintf( '@@ before line %d @@', $line[2] );
			}
			$out[] = $line[0] . ' ' . $line[1];
			$last  = $i;
		}
		$unified = implode( "\n", $out );
		if ( strlen( $unified ) > self::MAX_DIFF_BYTES ) {
			$unified   = self::cut( $unified, self::MAX_DIFF_BYTES );
			$truncated = true;
		}
		return [ 'unified' => $unified, 'added' => $added, 'removed' => $removed, 'truncated' => $truncated ];
	}
}
