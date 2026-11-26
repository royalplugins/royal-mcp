<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Undo_Store;
use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Post_Access;
use Royal_MCP\MCP\Support\Protected_Meta;
use Royal_MCP\MCP\Support\Tool_Refusal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JetEngine (Crocoblock): its post types, meta field definitions, custom content
 * types and relations, plus field value changes on a post or a custom content
 * type item, each checked against the field's own definition and undoable.
 *
 * Reads and writes go through JetEngine's own objects (jet_engine()->cpt,
 * ->meta_boxes, ->relations and the Custom Content Types module), each guarded
 * so a missing part reports "not available" instead of failing. Nothing here
 * creates or changes post types, fields, content types or relations.
 */
class JetEngine {

	/** Fields changed in one call. */
	const MAX_FIELDS_PER_CALL = 20;

	/** Largest text value accepted, in bytes. */
	const MAX_TEXT_BYTES = 65536;

	/** Most entries in a multi-value field (checkbox, multi-select, gallery, posts). */
	const MAX_LIST_VALUES = 100;

	/** Largest page size for lists. */
	const MAX_PER_PAGE = 100;

	/** Highest page number accepted. */
	const MAX_PAGE = 100000;

	/** Field types whose value Royal MCP can write. */
	const WRITABLE_TYPES = [ 'text', 'textarea', 'wysiwyg', 'number', 'date', 'time', 'datetime-local', 'select', 'radio', 'checkbox', 'switcher', 'colorpicker', 'iconpicker', 'media', 'gallery', 'posts' ];

	/** Field types a custom content type item can take through these tools (single, plain values). */
	const CCT_WRITABLE_TYPES = [ 'text', 'textarea', 'wysiwyg', 'number', 'date', 'time', 'datetime-local', 'select', 'radio', 'switcher', 'colorpicker', 'iconpicker', 'media', 'posts' ];

	/** Field entries that only lay out the edit screen and hold no value. */
	const LAYOUT_TYPES = [ 'tab', 'accordion', 'endpoint' ];

	/** Arguments each tool accepts. */
	const ALLOWED_ARGS = [
		'jetengine_list_post_types'     => [ 'page', 'per_page' ],
		'jetengine_list_meta_boxes'     => [ 'object', 'page', 'per_page' ],
		'jetengine_get_post_fields'     => [ 'post_id' ],
		'jetengine_update_post_fields'  => [ 'post_id', 'fields' ],
		'jetengine_list_content_types'  => [ 'page', 'per_page' ],
		'jetengine_list_cct_items'      => [ 'type', 'page', 'per_page' ],
		'jetengine_get_cct_item'        => [ 'type', 'item_id' ],
		'jetengine_update_cct_item'     => [ 'type', 'item_id', 'fields' ],
		'jetengine_list_relations'      => [ 'page', 'per_page' ],
		'jetengine_get_related_items'   => [ 'relation_id', 'object_id', 'direction', 'page', 'per_page' ],
	];

	public static function is_available() {
		return function_exists( 'jet_engine' ) && is_object( jet_engine() );
	}

	/** The Custom Content Types module's manager, or null when the module is off. */
	private static function cct_manager() {
		$module = '\Jet_Engine\Modules\Custom_Content_Types\Module';
		if ( ! class_exists( $module ) || ! method_exists( $module, 'instance' ) ) {
			return null;
		}
		$instance = $module::instance();
		return ( is_object( $instance ) && isset( $instance->manager ) && is_object( $instance->manager ) ) ? $instance->manager : null;
	}

	public static function get_tools() {
		$paging = [
			'page'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE, 'description' => 'Page number, from 1. Default 1.' ],
			'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'description' => 'Rows per page, 1 to ' . self::MAX_PER_PAGE . '. Default 20.' ],
		];
		$fields_arg = [
			'type'                 => 'object',
			'minProperties'        => 1,
			'maxProperties'        => self::MAX_FIELDS_PER_CALL,
			'description'          => 'Field name => new value, up to ' . self::MAX_FIELDS_PER_CALL . ' fields. Values by field type: text, textarea, wysiwyg, iconpicker: text; number: a number; date "YYYY-MM-DD"; time "HH:MM"; datetime-local "YYYY-MM-DDTHH:MM"; select or radio: an option value (a list for a multiple select); checkbox: a list of option values; switcher: true or false; colorpicker: "#rrggbb" or rgba(); media: an attachment ID; gallery: a list of attachment IDs; posts: a post ID (a list for a multiple field). null clears the field.',
			'additionalProperties' => true,
		];
		$untrusted = ' Values of fields that site forms fill in come from visitors.';
		return [
			[
				'name'        => 'jetengine_list_post_types',
				'description' => 'List the custom post types JetEngine registers: slug, name, whether it is public, whether WordPress has it registered now, and how many JetEngine meta fields it has. Returns results, total and has_more. Read-only. Administrators only.',
				'inputSchema' => [ 'type' => 'object', 'properties' => $paging, 'additionalProperties' => false ],
			],
			[
				'name'        => 'jetengine_list_meta_boxes',
				'description' => 'List JetEngine meta field definitions, grouped by what they belong to (a post type, a taxonomy or users): field name, label, type, options (value and label) for choice fields, whether it takes several values, number limits, the media value format, and whether jetengine_update_post_fields can write it. Pass object (for example a post type slug) to see one. Returns results (one row per field), total and has_more. Read-only. Administrators only.',
				'inputSchema' => [ 'type' => 'object', 'properties' => array_merge( [ 'object' => [ 'type' => 'string', 'maxLength' => 100, 'description' => 'Only fields of this post type, taxonomy or "user".' ] ], $paging ), 'additionalProperties' => false ],
			],
			[
				'name'        => 'jetengine_get_post_fields',
				'description' => 'Read the JetEngine meta fields of one post: each field\'s definition (name, label, type, options) and its current value. Values of protected or credential-like keys are withheld. Needs permission to read the post. Read-only.',
				'inputSchema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'ID of the post.' ] ], 'required' => [ 'post_id' ], 'additionalProperties' => false ],
			],
			[
				'name'        => 'jetengine_update_post_fields',
				'description' => 'Change JetEngine meta field values on one post. Each value is checked against the field\'s definition (type, options, number limits, dates, attachments and posts you can read) and stored the way JetEngine stores it; text that looks like serialized PHP is refused. Repeater, map and other complex fields are refused: change them in JetEngine. All fields are checked before anything is written. Needs permission to edit the post. Refused in read-only mode. Returns an undo token that restores every field changed.',
				'inputSchema' => [ 'type' => 'object', 'properties' => [ 'post_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'ID of the post.' ], 'fields' => $fields_arg ], 'required' => [ 'post_id', 'fields' ], 'additionalProperties' => false ],
			],
			[
				'name'        => 'jetengine_list_content_types',
				'description' => 'List JetEngine custom content types (CCT): slug, name, item count and field definitions (name, label, type, options). Says so when the Custom Content Types module is off. Returns results, total and has_more. Read-only. Administrators only.',
				'inputSchema' => [ 'type' => 'object', 'properties' => $paging, 'additionalProperties' => false ],
			],
			[
				'name'        => 'jetengine_list_cct_items',
				'description' => 'List the items of one JetEngine custom content type, newest first: item ID, status, created and modified times, linked post, and each field value. Returns results, total and has_more.' . $untrusted . ' Read-only. Administrators only.',
				'inputSchema' => [ 'type' => 'object', 'properties' => array_merge( [ 'type' => [ 'type' => 'string', 'maxLength' => 64, 'description' => 'Content type slug from jetengine_list_content_types.' ] ], $paging ), 'required' => [ 'type' ], 'additionalProperties' => false ],
			],
			[
				'name'        => 'jetengine_get_cct_item',
				'description' => 'Read one item of a JetEngine custom content type: item ID, status, times, linked post and every field value with its definition.' . $untrusted . ' Read-only. Administrators only.',
				'inputSchema' => [ 'type' => 'object', 'properties' => [ 'type' => [ 'type' => 'string', 'maxLength' => 64, 'description' => 'Content type slug.' ], 'item_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Item ID (_ID).' ] ], 'required' => [ 'type', 'item_id' ], 'additionalProperties' => false ],
			],
			[
				'name'        => 'jetengine_update_cct_item',
				'description' => 'Change field values of one JetEngine custom content type item. Each value is checked against the field definition the same way as jetengine_update_post_fields; fields that hold several values (checkbox, gallery, multiple select or posts, repeater) are refused here: change them in JetEngine. Refused in read-only mode. Administrators only. Returns an undo token that restores every field changed.',
				'inputSchema' => [ 'type' => 'object', 'properties' => [ 'type' => [ 'type' => 'string', 'maxLength' => 64, 'description' => 'Content type slug.' ], 'item_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Item ID (_ID).' ], 'fields' => $fields_arg ], 'required' => [ 'type', 'item_id', 'fields' ], 'additionalProperties' => false ],
			],
			[
				'name'        => 'jetengine_list_relations',
				'description' => 'List JetEngine relations: ID, name, parent and child object (for example posts::product or cct::books), and type (one_to_one, one_to_many or many_to_many). Returns results, total and has_more. Read-only. Administrators only.',
				'inputSchema' => [ 'type' => 'object', 'properties' => $paging, 'additionalProperties' => false ],
			],
			[
				'name'        => 'jetengine_get_related_items',
				'description' => 'List the items related to one object through a JetEngine relation: direction "children" lists the children of a parent object, "parents" the parents of a child object. Each row has the related ID and, for posts you can read, the title and post type; posts you cannot read are counted but not listed. Returns results, total and has_more. Read-only. Administrators only.',
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => array_merge( [
						'relation_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Relation ID from jetengine_list_relations.' ],
						'object_id'   => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'ID of the parent (direction children) or child (direction parents) object.' ],
						'direction'   => [ 'type' => 'string', 'enum' => [ 'children', 'parents' ], 'description' => 'children (default) or parents.' ],
					], $paging ),
					'required'             => [ 'relation_id', 'object_id' ],
					'additionalProperties' => false,
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		$post_tools = [ 'jetengine_get_post_fields', 'jetengine_update_post_fields' ];
		$base_cap   = in_array( $name, $post_tools, true ) ? 'edit_posts' : 'manage_options';
		if ( ! current_user_can( $base_cap ) ) {
			throw new \Exception( 'You do not have permission to use JetEngine tools.' );
		}
		if ( is_multisite() && ! is_super_admin() && ! is_user_member_of_blog( get_current_user_id(), get_current_blog_id() ) ) {
			throw new \Exception( 'You do not have permission to use JetEngine tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'JetEngine is not active' );
		}
		if ( null === $args ) {
			$args = [];
		}
		if ( is_object( $args ) ) {
			$args = get_object_vars( $args );
		}
		if ( ! is_array( $args ) ) {
			throw new \Exception( 'Arguments must be an object.' );
		}
		if ( isset( self::ALLOWED_ARGS[ $name ] ) ) {
			$unknown = array_diff( array_map( 'strval', array_keys( $args ) ), self::ALLOWED_ARGS[ $name ] );
			if ( $unknown ) {
				throw new Tool_Refusal( 'unknown_argument', sprintf( 'Unknown argument: %s.', esc_html( implode( ', ', array_slice( $unknown, 0, 5 ) ) ) ) );
			}
		}
		switch ( $name ) {
			case 'jetengine_list_post_types':
				return self::list_post_types( $args );
			case 'jetengine_list_meta_boxes':
				return self::list_meta_boxes( $args );
			case 'jetengine_get_post_fields':
				return self::get_post_fields( $args );
			case 'jetengine_update_post_fields':
				return self::update_post_fields( $args );
			case 'jetengine_list_content_types':
				return self::list_content_types( $args );
			case 'jetengine_list_cct_items':
				return self::list_cct_items( $args );
			case 'jetengine_get_cct_item':
				return self::get_cct_item( $args );
			case 'jetengine_update_cct_item':
				return self::update_cct_item( $args );
			case 'jetengine_list_relations':
				return self::list_relations( $args );
			case 'jetengine_get_related_items':
				return self::get_related_items( $args );
		}
		throw new \Exception( 'Unknown JetEngine tool: ' . esc_html( $name ) );
	}

	// ============ Shared helpers ============

	/** A whole-number argument within a range; anything else is refused, not adjusted. */
	private static function int_arg( array $args, $key, $default, $min, $max ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			if ( null === $default ) {
				throw new Tool_Refusal( 'invalid_args', sprintf( '%s is required.', esc_html( $key ) ) );
			}
			return $default;
		}
		$v = $args[ $key ];
		if ( ! is_int( $v ) ) {
			throw new Tool_Refusal( 'invalid_args', sprintf( '%s must be a whole number from %d to %d.', esc_html( $key ), (int) $min, (int) $max ) );
		}
		if ( $v < $min || $v > $max ) {
			throw new Tool_Refusal( 'invalid_args', sprintf( '%s must be from %d to %d.', esc_html( $key ), (int) $min, (int) $max ) );
		}
		return $v;
	}

	/** One page of rows, in the list shape every list tool returns. */
	private static function page_of( array $rows, array $args, array $extra = [] ) {
		$page     = self::int_arg( $args, 'page', 1, 1, self::MAX_PAGE );
		$per_page = self::int_arg( $args, 'per_page', 20, 1, self::MAX_PER_PAGE );
		$total    = count( $rows );
		$slice    = array_values( array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ) );
		$more     = $total > $page * $per_page;
		$out      = [ 'results' => $slice, 'total' => $total, 'has_more' => $more, 'page' => $page, 'per_page' => $per_page ];
		if ( $more ) {
			$out['next_page'] = $page + 1;
		}
		return array_merge( $out, $extra );
	}

	/** Plain text from a label JetEngine stored. */
	private static function label( $value ) {
		return is_scalar( $value ) ? wp_strip_all_tags( (string) $value ) : '';
	}

	/** Field definition rows as JetEngine keeps them, without layout entries. */
	private static function value_fields( $fields ) {
		$out = [];
		foreach ( is_array( $fields ) ? $fields : [] as $field ) {
			if ( is_object( $field ) ) {
				$field = get_object_vars( $field );
			}
			if ( ! is_array( $field ) || empty( $field['name'] ) || ! is_string( $field['name'] ) ) {
				continue;
			}
			$kind = (string) ( $field['object_type'] ?? 'field' );
			if ( in_array( $kind, self::LAYOUT_TYPES, true ) || in_array( (string) ( $field['type'] ?? '' ), self::LAYOUT_TYPES, true ) ) {
				continue;
			}
			$out[ $field['name'] ] = $field;
		}
		return $out;
	}

	/** Option key => label of a choice field, from options stored with the field. */
	private static function field_options( array $field ) {
		$out = [];
		foreach ( is_array( $field['options'] ?? null ) ? $field['options'] : [] as $k => $opt ) {
			if ( is_array( $opt ) && array_key_exists( 'key', $opt ) && is_scalar( $opt['key'] ) ) {
				$out[ (string) $opt['key'] ] = self::label( $opt['value'] ?? $opt['key'] );
			} elseif ( is_scalar( $opt ) ) {
				$out[ (string) $k ] = self::label( $opt );
			}
		}
		return $out;
	}

	/** Whether a choice field takes its options from somewhere other than the list saved with it. */
	private static function options_elsewhere( array $field ) {
		$source = isset( $field['options_source'] ) && is_scalar( $field['options_source'] ) ? (string) $field['options_source'] : '';
		return ! in_array( $source, [ '', 'manual' ], true ) || self::flag( $field['options_from_glossary'] ?? false );
	}

	private static function is_multiple( array $field ) {
		$m = $field['is_multiple'] ?? false;
		return true === $m || 'true' === $m || 1 === $m || '1' === $m;
	}

	private static function flag( $value ) {
		return true === $value || 'true' === $value || 1 === $value || '1' === $value;
	}

	/** A definition as the tools describe it. */
	private static function describe_field( array $field, $writable_types = self::WRITABLE_TYPES ) {
		$type = (string) ( $field['type'] ?? 'text' );
		$row  = [
			'name'     => (string) $field['name'],
			'label'    => self::label( $field['title'] ?? $field['name'] ),
			'type'     => $type,
			'writable' => in_array( $type, $writable_types, true ) && ! Protected_Meta::is_protected_key( (string) $field['name'] ) && ! Protected_Meta::is_blocked( (string) $field['name'] ),
		];
		$options = self::options_elsewhere( $field ) ? [] : self::field_options( $field );
		if ( $options ) {
			$row['options'] = [];
			foreach ( $options as $k => $l ) {
				$row['options'][] = [ 'value' => (string) $k, 'label' => $l ];
			}
		} elseif ( in_array( $type, [ 'select', 'radio', 'checkbox' ], true ) ) {
			$row['options_note'] = 'The options come from another source (a glossary or a query), so this field cannot be written here.';
			$row['writable']     = false;
		}
		if ( in_array( $type, [ 'select', 'posts' ], true ) ) {
			$row['multiple'] = self::is_multiple( $field );
			// Content type items take single values only through these tools.
			if ( $row['multiple'] && self::CCT_WRITABLE_TYPES === $writable_types ) {
				$row['writable'] = false;
			}
		}
		if ( 'number' === $type ) {
			foreach ( [ 'min_value' => 'min', 'max_value' => 'max', 'step_value' => 'step' ] as $k => $out ) {
				if ( isset( $field[ $k ] ) && is_numeric( $field[ $k ] ) ) {
					$row[ $out ] = 0 + $field[ $k ];
				}
			}
		}
		if ( in_array( $type, [ 'media', 'gallery' ], true ) ) {
			$row['value_format'] = in_array( $field['value_format'] ?? 'id', [ 'id', 'url', 'both' ], true ) ? (string) ( $field['value_format'] ?? 'id' ) : 'id';
		}
		if ( in_array( $type, [ 'date', 'datetime-local' ], true ) && self::flag( $field['is_timestamp'] ?? false ) ) {
			$row['stored_as_timestamp'] = true;
		}
		if ( 'checkbox' === $type && self::flag( $field['is_array'] ?? false ) ) {
			$row['stored_as_list'] = true;
		}
		if ( self::flag( $field['is_required'] ?? false ) ) {
			$row['required'] = true;
		}
		return $row;
	}

	// ============ Post types and meta boxes ============

	/** Rows JetEngine stored for one of its data objects (cpt, meta_boxes), unserialized. */
	private static function stored_items( $component ) {
		$je = jet_engine();
		if ( ! isset( $je->$component ) || ! is_object( $je->$component ) ) {
			return null;
		}
		$obj   = $je->$component;
		$items = null;
		if ( isset( $obj->data ) && is_object( $obj->data ) && method_exists( $obj->data, 'get_items' ) ) {
			$items = $obj->data->get_items();
		} elseif ( method_exists( $obj, 'get_items' ) ) {
			$items = $obj->get_items();
		}
		$out = [];
		foreach ( is_array( $items ) ? $items : [] as $item ) {
			if ( is_object( $item ) ) {
				$item = get_object_vars( $item );
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			foreach ( [ 'args', 'labels', 'meta_fields' ] as $k ) {
				if ( isset( $item[ $k ] ) && is_string( $item[ $k ] ) ) {
					$item[ $k ] = self::unserialize_plain( $item[ $k ] );
				}
			}
			$out[] = $item;
		}
		return $out;
	}

	/** A stored serialized or JSON value as arrays only: objects are never built. */
	private static function unserialize_plain( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( is_serialized( $value ) ) {
			$v = @unserialize( trim( $value ), [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes refused
			return false === $v && 'b:0;' !== trim( $value ) ? [] : $v;
		}
		$j = json_decode( $value, true );
		return is_array( $j ) ? $j : $value;
	}

	private static function list_post_types( array $args ) {
		$items = self::stored_items( 'cpt' );
		if ( null === $items ) {
			return self::page_of( [], $args, [ 'note' => 'JetEngine\'s post type component is not available.' ] );
		}
		$rows = [];
		foreach ( $items as $item ) {
			$a    = is_array( $item['args'] ?? null ) ? $item['args'] : [];
			$slug = sanitize_key( (string) ( $item['slug'] ?? ( $a['slug'] ?? '' ) ) );
			if ( '' === $slug ) {
				continue;
			}
			$labels = is_array( $item['labels'] ?? null ) ? $item['labels'] : [];
			$fields = self::value_fields( $item['meta_fields'] ?? [] );
			$status = (string) ( $item['status'] ?? 'publish' );
			$rows[] = [
				'slug'        => $slug,
				'name'        => self::label( $labels['name'] ?? ( $a['name'] ?? $slug ) ),
				'public'      => array_key_exists( 'public', $a ) ? self::flag( $a['public'] ) : true,
				'registered'  => post_type_exists( $slug ),
				'enabled'     => 'publish' === $status || '' === $status,
				'field_count' => count( $fields ),
			];
		}
		return self::page_of( $rows, $args );
	}

	/** Object => value fields, from JetEngine's registered meta boxes. */
	private static function registered_fields() {
		$je = jet_engine();
		if ( ! isset( $je->meta_boxes ) || ! is_object( $je->meta_boxes ) ) {
			return null;
		}
		$mb = $je->meta_boxes;
		if ( method_exists( $mb, 'get_registered_fields' ) ) {
			$all = $mb->get_registered_fields();
			$out = [];
			foreach ( is_array( $all ) ? $all : [] as $object => $fields ) {
				$out[ (string) $object ] = self::value_fields( $fields );
			}
			return $out;
		}
		return [];
	}

	/** Value fields JetEngine defines for one object (a post type slug). */
	private static function fields_for_object( $object ) {
		$je = jet_engine();
		if ( isset( $je->meta_boxes ) && is_object( $je->meta_boxes ) && method_exists( $je->meta_boxes, 'get_meta_fields_for_object' ) ) {
			return self::value_fields( $je->meta_boxes->get_meta_fields_for_object( (string) $object ) );
		}
		$all = self::registered_fields();
		return is_array( $all ) && isset( $all[ $object ] ) ? $all[ $object ] : [];
	}

	/**
	 * Value fields that apply to one post: the fields JetEngine keeps on its own post type,
	 * plus those of each post meta box whose conditions (post types, posts, excluded posts,
	 * page templates) include this post. A meta box with a condition these tools cannot
	 * check is left out. Without stored meta boxes, JetEngine's own field list for the type.
	 */
	private static function fields_for_post( \WP_Post $post ) {
		$boxes = self::stored_items( 'meta_boxes' );
		$cpts  = self::stored_items( 'cpt' );
		if ( ! $boxes ) {
			return self::fields_for_object( $post->post_type );
		}
		$out = [];
		foreach ( is_array( $cpts ) ? $cpts : [] as $cpt ) {
			$slug = (string) ( $cpt['slug'] ?? ( is_array( $cpt['args'] ?? null ) ? ( $cpt['args']['slug'] ?? '' ) : '' ) );
			if ( $slug === $post->post_type ) {
				$out += self::value_fields( $cpt['meta_fields'] ?? [] );
			}
		}
		foreach ( $boxes as $box ) {
			$a = is_array( $box['args'] ?? null ) ? $box['args'] : [];
			if ( self::box_applies( $a, $post ) ) {
				$out += self::value_fields( $box['meta_fields'] ?? [] );
			}
		}
		return $out;
	}

	/** Condition keys these tools check; any other non-empty condition keeps the box out. */
	const BOX_CONDITIONS = [ 'allowed_post_type', 'allowed_posts', 'excluded_posts', 'include_templates', 'exclude_templates' ];

	private static function box_applies( array $a, \WP_Post $post ) {
		$kind = (string) ( $a['object_type'] ?? 'post' );
		if ( '' !== $kind && 'post' !== $kind ) {
			return false;
		}
		$conds = $a;
		if ( isset( $a['active_conditions'] ) && is_array( $a['active_conditions'] ) ) {
			foreach ( $a['active_conditions'] as $c ) {
				$name = is_array( $c ) ? (string) ( $c['type'] ?? ( $c['name'] ?? '' ) ) : (string) $c;
				if ( '' !== $name && ! in_array( $name, self::BOX_CONDITIONS, true ) ) {
					return false;
				}
				if ( is_array( $c ) ) {
					$conds = array_merge( $conds, $c );
				}
			}
		}
		$list = static function ( $key ) use ( $conds ) {
			$v = $conds[ $key ] ?? [];
			return array_values( array_filter( array_map( 'strval', is_array( $v ) ? $v : ( '' === (string) $v ? [] : explode( ',', (string) $v ) ) ), 'strlen' ) );
		};
		$types = $list( 'allowed_post_type' );
		if ( ! $types || ! in_array( $post->post_type, $types, true ) ) {
			return false;
		}
		$ids = array_map( 'intval', $list( 'allowed_posts' ) );
		if ( $ids && ! in_array( (int) $post->ID, $ids, true ) ) {
			return false;
		}
		if ( in_array( (int) $post->ID, array_map( 'intval', $list( 'excluded_posts' ) ), true ) ) {
			return false;
		}
		$template = (string) get_page_template_slug( $post );
		$inc      = $list( 'include_templates' );
		if ( $inc && ! in_array( $template, $inc, true ) ) {
			return false;
		}
		return ! in_array( $template, $list( 'exclude_templates' ), true );
	}

	private static function list_meta_boxes( array $args ) {
		$object = null;
		if ( array_key_exists( 'object', $args ) && null !== $args['object'] ) {
			if ( ! is_string( $args['object'] ) || '' === trim( $args['object'] ) || strlen( $args['object'] ) > 100 ) {
				throw new Tool_Refusal( 'invalid_args', 'object must be a post type slug, taxonomy slug or "user".' );
			}
			$object = sanitize_key( $args['object'] );
		}
		$all = self::registered_fields();
		if ( null === $all ) {
			return self::page_of( [], $args, [ 'note' => 'JetEngine\'s meta box component is not available.' ] );
		}
		$rows = [];
		foreach ( $all as $obj => $fields ) {
			if ( null !== $object && $obj !== $object ) {
				continue;
			}
			foreach ( $fields as $field ) {
				$rows[] = array_merge( [ 'object' => (string) $obj ], self::describe_field( $field ) );
			}
		}
		return self::page_of( $rows, $args );
	}

	// ============ Post field values ============

	/** The post a post-field tool names, readable (or editable) by the caller. */
	private static function post_arg( array $args, $edit ) {
		$id = Post_Access::id_value( $args['post_id'] ?? null );
		if ( null === $id || $id < 1 ) {
			throw new Tool_Refusal( 'invalid_args', 'post_id must be a positive whole number.' );
		}
		$post = get_post( $id );
		$ok   = $post instanceof \WP_Post && ( $edit ? ( Post_Access::can_read( $post ) && current_user_can( 'edit_post', $id ) ) : Post_Access::can_read_content( $post ) );
		if ( ! $ok ) {
			throw new Tool_Refusal( 'not_found', sprintf( 'Post %d was not found, or you do not have permission to use it.', (int) $id ) );
		}
		return $post;
	}

	/** Whether a key's value is shown and written: never protected or credential-like keys. */
	private static function key_open( $key ) {
		return ! Protected_Meta::is_protected_key( (string) $key ) && ! Protected_Meta::is_blocked( (string) $key );
	}

	private static function get_post_fields( array $args ) {
		$post   = self::post_arg( $args, false );
		$fields = self::fields_for_post( $post );
		$defs   = [];
		$values = [];
		$hidden = [];
		foreach ( $fields as $name => $field ) {
			$defs[] = self::describe_field( $field );
			if ( ! self::key_open( $name ) ) {
				$hidden[] = (string) $name;
				continue;
			}
			$values[ (string) $name ] = metadata_exists( 'post', $post->ID, $name ) ? get_post_meta( $post->ID, $name, true ) : null;
		}
		$out = [
			'post_id'   => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'fields'    => $defs,
			'values'    => $values,
		];
		if ( $hidden ) {
			$out['withheld'] = $hidden;
		}
		if ( ! $fields ) {
			$out['note'] = 'JetEngine defines no meta fields for this post type.';
		}
		return $out;
	}

	/** Field name => value from the call, checked for shape before any definition is read. */
	private static function fields_arg( array $args ) {
		$fields = $args['fields'] ?? null;
		if ( is_object( $fields ) ) {
			$fields = get_object_vars( $fields );
		}
		if ( ! is_array( $fields ) || [] === $fields || array_keys( $fields ) === range( 0, count( $fields ) - 1 ) ) {
			throw new Tool_Refusal( 'invalid_args', 'fields must be an object of field name => value.' );
		}
		if ( count( $fields ) > self::MAX_FIELDS_PER_CALL ) {
			throw new Tool_Refusal( 'invalid_args', sprintf( 'Up to %d fields per call.', self::MAX_FIELDS_PER_CALL ) );
		}
		return $fields;
	}

	/**
	 * A value converted to what JetEngine stores for this field, or a refusal naming why.
	 * Returns [ 'clear' => true ] for null.
	 */
	private static function stored_value( array $field, $value, array $writable_types, array &$warnings ) {
		$name = (string) $field['name'];
		$type = (string) ( $field['type'] ?? 'text' );
		$bad  = static function ( $why ) use ( $name ) {
			return new Tool_Refusal( 'invalid_value', sprintf( 'Field %s: %s Nothing was changed.', esc_html( $name ), $why ), [ 'field' => $name ] );
		};
		if ( ! in_array( $type, $writable_types, true ) ) {
			throw new Tool_Refusal( 'unsupported_field_type', sprintf( 'Field %s is a %s field, which these tools do not write. Change it in JetEngine. Nothing was changed.', esc_html( $name ), esc_html( $type ) ), [ 'field' => $name ] );
		}
		if ( null === $value ) {
			return [ 'clear' => true ];
		}
		switch ( $type ) {
			case 'text':
			case 'textarea':
			case 'wysiwyg':
			case 'iconpicker':
				if ( ! is_string( $value ) ) {
					throw $bad( 'the value must be text.' );
				}
				if ( strlen( $value ) > self::MAX_TEXT_BYTES ) {
					throw $bad( sprintf( 'the text is longer than %d bytes.', self::MAX_TEXT_BYTES ) );
				}
				if ( is_serialized( $value ) ) {
					throw $bad( 'the text looks like serialized PHP data, which is never written.' );
				}
				if ( 'iconpicker' === $type ) {
					if ( ! preg_match( '/^[A-Za-z0-9 _:-]{1,200}$/', $value ) ) {
						throw $bad( 'an icon is letters, digits, spaces, dashes, colons and underscores only (for example "fa fa-book").' );
					}
					$clean = $value;
				} else {
					$clean = current_user_can( 'unfiltered_html' ) ? $value : wp_kses_post( $value );
				}
				if ( $clean !== $value ) {
					$warnings[] = sprintf( 'Field %s: markup your account may not save was removed.', $name );
				}
				return [ 'value' => $clean ];

			case 'number':
				if ( ! is_int( $value ) && ! is_float( $value ) ) {
					throw $bad( 'the value must be a number.' );
				}
				if ( is_float( $value ) && ( is_nan( $value ) || is_infinite( $value ) ) ) {
					throw $bad( 'the value must be a finite number.' );
				}
				if ( isset( $field['min_value'] ) && is_numeric( $field['min_value'] ) && $value < 0 + $field['min_value'] ) {
					throw $bad( sprintf( 'the value is below the minimum %s.', esc_html( (string) $field['min_value'] ) ) );
				}
				if ( isset( $field['max_value'] ) && is_numeric( $field['max_value'] ) && $value > 0 + $field['max_value'] ) {
					throw $bad( sprintf( 'the value is above the maximum %s.', esc_html( (string) $field['max_value'] ) ) );
				}
				if ( isset( $field['step_value'] ) && is_numeric( $field['step_value'] ) && 0 + $field['step_value'] > 0 ) {
					$base  = isset( $field['min_value'] ) && is_numeric( $field['min_value'] ) ? 0 + $field['min_value'] : 0;
					$steps = ( $value - $base ) / ( 0 + $field['step_value'] );
					if ( abs( $steps - round( $steps ) ) > 1e-9 ) {
						throw $bad( sprintf( 'the value must be a multiple of the step %s.', esc_html( (string) $field['step_value'] ) ) );
					}
				}
				return [ 'value' => (string) $value ];

			case 'date':
			case 'time':
			case 'datetime-local':
				$formats = [ 'date' => [ 'Y-m-d' ], 'time' => [ 'H:i', 'H:i:s' ], 'datetime-local' => [ 'Y-m-d\TH:i', 'Y-m-d\TH:i:s' ] ];
				if ( ! is_string( $value ) ) {
					throw $bad( 'the value must be text in the field\'s date or time format.' );
				}
				$parsed = null;
				foreach ( $formats[ $type ] as $f ) {
					$d = \DateTime::createFromFormat( '!' . $f, $value, new \DateTimeZone( 'UTC' ) );
					if ( $d && $d->format( $f ) === $value ) {
						$parsed = $d;
						break;
					}
				}
				if ( null === $parsed ) {
					throw $bad( sprintf( 'expected %s.', 'date' === $type ? 'YYYY-MM-DD' : ( 'time' === $type ? 'HH:MM' : 'YYYY-MM-DDTHH:MM' ) ) );
				}
				if ( 'time' !== $type && self::flag( $field['is_timestamp'] ?? false ) ) {
					return [ 'value' => (string) $parsed->getTimestamp() ];
				}
				return [ 'value' => $value ];

			case 'select':
			case 'radio':
			case 'checkbox':
				$options = self::options_elsewhere( $field ) ? [] : self::field_options( $field );
				if ( ! $options ) {
					throw $bad( 'its options come from another source (a glossary or a query); change it in JetEngine.' );
				}
				$many = 'checkbox' === $type || ( 'select' === $type && self::is_multiple( $field ) );
				$list = $many ? $value : [ $value ];
				if ( ! is_array( $list ) || ( $many && array_keys( $list ) !== range( 0, count( $list ) - 1 ) && [] !== $list ) ) {
					throw $bad( 'the value must be a list of option values.' );
				}
				if ( count( $list ) > self::MAX_LIST_VALUES ) {
					throw $bad( sprintf( 'up to %d options.', self::MAX_LIST_VALUES ) );
				}
				$keys = [];
				foreach ( $list as $k ) {
					if ( ! is_string( $k ) && ! is_int( $k ) ) {
						throw $bad( 'option values must be text.' );
					}
					if ( ! array_key_exists( (string) $k, $options ) ) {
						throw $bad( sprintf( '"%s" is not one of its options (%s).', esc_html( (string) $k ), esc_html( implode( ', ', array_slice( array_map( 'strval', array_keys( $options ) ), 0, 20 ) ) ) ) );
					}
					$keys[ (string) $k ] = true;
				}
				$keys = array_keys( $keys );
				if ( ! $many ) {
					return [ 'value' => (string) $keys[0] ];
				}
				if ( 'checkbox' === $type && ! self::flag( $field['is_array'] ?? false ) ) {
					$stored = [];
					foreach ( array_keys( $options ) as $k ) {
						$stored[ (string) $k ] = in_array( (string) $k, array_map( 'strval', $keys ), true ) ? 'true' : 'false';
					}
					return [ 'value' => $stored ];
				}
				return [ 'value' => array_map( 'strval', $keys ) ];

			case 'switcher':
				if ( ! is_bool( $value ) ) {
					throw $bad( 'the value must be true or false.' );
				}
				return [ 'value' => $value ? 'true' : 'false' ];

			case 'colorpicker':
				if ( ! is_string( $value ) || ( ! preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) && ! preg_match( '/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\)$/', $value ) ) ) {
					throw $bad( 'expected a colour such as "#1e73be" or "rgba(30,115,190,0.5)".' );
				}
				return [ 'value' => $value ];

			case 'media':
			case 'gallery':
				$format = in_array( $field['value_format'] ?? 'id', [ 'id', 'url', 'both' ], true ) ? (string) ( $field['value_format'] ?? 'id' ) : 'id';
				$ids    = 'media' === $type ? [ $value ] : $value;
				if ( ! is_array( $ids ) || ( 'gallery' === $type && [] !== $ids && array_keys( $ids ) !== range( 0, count( $ids ) - 1 ) ) ) {
					throw $bad( 'the value must be a list of attachment IDs.' );
				}
				if ( count( $ids ) > self::MAX_LIST_VALUES ) {
					throw $bad( sprintf( 'up to %d images.', self::MAX_LIST_VALUES ) );
				}
				$items = [];
				foreach ( $ids as $raw ) {
					$aid = is_int( $raw ) ? $raw : null;
					$att = null !== $aid && $aid > 0 ? get_post( $aid ) : null;
					if ( ! $att instanceof \WP_Post || 'attachment' !== $att->post_type || ! Post_Access::can_read( $att ) ) {
						throw $bad( sprintf( 'attachment %s was not found, or you cannot use it.', esc_html( is_scalar( $raw ) ? (string) $raw : '?' ) ) );
					}
					$url     = (string) wp_get_attachment_url( $aid );
					$items[] = [ 'id' => $aid, 'url' => $url ];
				}
				if ( 'media' === $type ) {
					$one = $items[0];
					return [ 'value' => 'url' === $format ? $one['url'] : ( 'both' === $format ? [ 'id' => (string) $one['id'], 'url' => $one['url'] ] : (string) $one['id'] ) ];
				}
				if ( 'both' === $format ) {
					return [ 'value' => array_map( static function ( $i ) { return [ 'id' => (string) $i['id'], 'url' => $i['url'] ]; }, $items ) ];
				}
				return [ 'value' => implode( ',', array_map( static function ( $i ) use ( $format ) { return 'url' === $format ? $i['url'] : (string) $i['id']; }, $items ) ) ];

			case 'posts':
				$many = self::is_multiple( $field );
				$ids  = $many ? $value : [ $value ];
				if ( ! is_array( $ids ) || ( $many && [] !== $ids && array_keys( $ids ) !== range( 0, count( $ids ) - 1 ) ) ) {
					throw $bad( $many ? 'the value must be a list of post IDs.' : 'the value must be one post ID.' );
				}
				if ( count( $ids ) > self::MAX_LIST_VALUES ) {
					throw $bad( sprintf( 'up to %d posts.', self::MAX_LIST_VALUES ) );
				}
				$types = array_filter( array_map( 'strval', (array) ( $field['search_post_type'] ?? [] ) ) );
				$out   = [];
				foreach ( $ids as $raw ) {
					$pid = is_int( $raw ) ? $raw : null;
					$p   = null !== $pid && $pid > 0 ? get_post( $pid ) : null;
					if ( ! $p instanceof \WP_Post || ! Post_Access::can_read( $p ) ) {
						throw $bad( sprintf( 'post %s was not found, or you cannot read it.', esc_html( is_scalar( $raw ) ? (string) $raw : '?' ) ) );
					}
					if ( $types && ! in_array( $p->post_type, $types, true ) ) {
						throw $bad( sprintf( 'post %d is a %s; the field takes %s.', (int) $pid, esc_html( $p->post_type ), esc_html( implode( ', ', $types ) ) ) );
					}
					$out[] = (string) $pid;
				}
				return [ 'value' => $many ? array_values( array_unique( $out ) ) : $out[0] ];
		}
		throw new Tool_Refusal( 'unsupported_field_type', sprintf( 'Field %s cannot be written here. Nothing was changed.', esc_html( $name ) ) );
	}

	/** The rows of one meta key as stored, for comparing before and after. */
	private static function meta_rows( $post_id, $key ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- exact stored form for the undo check
		return (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id", (int) $post_id, (string) $key ) );
	}

	private static function meta_print( $post_id, $key ) {
		return md5( (string) wp_json_encode( self::meta_rows( $post_id, $key ) ) );
	}

	private static function update_post_fields( array $args ) {
		if ( \Royal_MCP\MCP\Server::read_only_mode() ) {
			throw new Tool_Refusal( 'read_only_mode', 'This site is in read-only mode: tools that change the site are switched off.' );
		}
		$post   = self::post_arg( $args, true );
		$input  = self::fields_arg( $args );
		$fields = self::fields_for_post( $post );
		if ( ! $fields ) {
			throw new Tool_Refusal( 'no_fields', sprintf( 'JetEngine defines no meta fields for the post type %s. Nothing was changed.', esc_html( $post->post_type ) ) );
		}
		$plan     = [];
		$warnings = [];
		foreach ( $input as $name => $value ) {
			$name = (string) $name;
			if ( ! isset( $fields[ $name ] ) ) {
				throw new Tool_Refusal( 'unknown_field', sprintf( 'Field %s is not a JetEngine field of the post type %s. Use jetengine_get_post_fields to list them. Nothing was changed.', esc_html( $name ), esc_html( $post->post_type ) ), [ 'field' => $name ] );
			}
			if ( ! self::key_open( $name ) || ! current_user_can( 'edit_post_meta', $post->ID, $name ) ) {
				throw new Tool_Refusal( 'protected_field', sprintf( 'Field %s is a protected key, which these tools do not write. Nothing was changed.', esc_html( $name ) ), [ 'field' => $name ] );
			}
			if ( count( self::meta_rows( $post->ID, $name ) ) > 1 ) {
				throw new Tool_Refusal( 'multiple_values', sprintf( 'Field %s holds more than one stored value on this post, so it is left alone. Nothing was changed.', esc_html( $name ) ), [ 'field' => $name ] );
			}
			$plan[ $name ] = self::stored_value( $fields[ $name ], $value, self::WRITABLE_TYPES, $warnings );
		}

		$prior   = [];
		$changed = [];
		foreach ( $plan as $name => $step ) {
			$exists         = metadata_exists( 'post', $post->ID, $name );
			$prior[ $name ] = [ 'exists' => $exists, 'value' => $exists ? get_post_meta( $post->ID, $name, true ) : null ];
		}
		foreach ( $plan as $name => $step ) {
			$before = self::meta_print( $post->ID, $name );
			if ( ! empty( $step['clear'] ) ) {
				if ( $prior[ $name ]['exists'] ) {
					delete_post_meta( $post->ID, $name );
				}
			} else {
				update_post_meta( $post->ID, $name, wp_slash( $step['value'] ) );
			}
			if ( self::meta_print( $post->ID, $name ) !== $before ) {
				$changed[] = $name;
			}
		}
		$fails = [];
		foreach ( $plan as $name => $step ) {
			$now = metadata_exists( 'post', $post->ID, $name ) ? get_post_meta( $post->ID, $name, true ) : null;
			$want = empty( $step['clear'] ) ? $step['value'] : null;
			if ( wp_json_encode( $now ) !== wp_json_encode( $want ) && ! ( is_scalar( $now ) && is_scalar( $want ) && (string) $now === (string) $want ) ) {
				$fails[] = $name;
			}
		}
		$values = [];
		foreach ( array_keys( $plan ) as $name ) {
			$values[ $name ] = metadata_exists( 'post', $post->ID, $name ) ? get_post_meta( $post->ID, $name, true ) : null;
		}
		$struct = [ 'post_id' => (int) $post->ID, 'changed' => $changed, 'values' => (object) $values ];
		if ( $warnings ) {
			$struct['warnings'] = $warnings;
		}
		if ( $fails ) {
			$struct['not_saved'] = $fails;
		}
		if ( ! $changed ) {
			return Envelope::success( sprintf( 'Post %d already held these values; nothing changed.', (int) $post->ID ), $struct );
		}
		\Royal_MCP\MCP\Support\Post_Write_Hooks::trigger( (int) $post->ID );
		$prints = [];
		foreach ( $changed as $name ) {
			$prints[ $name ] = self::meta_print( $post->ID, $name );
		}
		$keep = array_intersect_key( $prior, array_flip( $changed ) );
		$undo = Undo_Store::store( [
			'op'           => 'jetengine_update_post_fields',
			'summary'      => sprintf( 'Restore %d JetEngine field(s) on post %d', count( $changed ), (int) $post->ID ),
			'target'       => [ 'post_id' => (int) $post->ID ],
			'pre_op_state' => [ 'prior' => $keep, 'written' => $prints, 'applied' => array_intersect_key( $values, array_flip( $changed ) ) ],
		] );
		$msg = sprintf( 'Updated %d JetEngine field(s) on post %d: %s.', count( $changed ), (int) $post->ID, implode( ', ', $changed ) );
		if ( $fails ) {
			$msg .= ' JetEngine or another plugin changed these on save: ' . implode( ', ', $fails ) . '.';
		}
		return Envelope::success( $msg, $struct, $undo );
	}

	// ============ Custom content types ============

	/** slug => content type object. */
	private static function content_types() {
		$mgr = self::cct_manager();
		if ( null === $mgr || ! method_exists( $mgr, 'get_content_types' ) ) {
			return null;
		}
		$types = $mgr->get_content_types();
		return is_array( $types ) ? $types : [];
	}

	/** A content type's setting, or a default. */
	private static function cct_setting( $type, $key, $default = '' ) {
		if ( is_object( $type ) && method_exists( $type, 'get_arg' ) ) {
			$v = $type->get_arg( $key );
			return null === $v || false === $v ? $default : $v;
		}
		return $default;
	}

	/** The value fields of a content type. */
	private static function cct_fields( $type ) {
		$raw = null;
		if ( method_exists( $type, 'get_formatted_fields' ) ) {
			$raw = $type->get_formatted_fields();
		} elseif ( isset( $type->fields ) && is_array( $type->fields ) ) {
			$raw = $type->fields;
		} else {
			$raw = self::cct_setting( $type, 'fields', [] );
		}
		return self::value_fields( $raw );
	}

	/** The content type a call names, after the caller's permission for it. */
	private static function cct_type_arg( array $args ) {
		$types = self::content_types();
		if ( null === $types ) {
			throw new Tool_Refusal( 'module_off', 'JetEngine\'s Custom Content Types module is off.' );
		}
		$slug = $args['type'] ?? null;
		if ( ! is_string( $slug ) || '' === $slug || strlen( $slug ) > 64 || sanitize_key( $slug ) !== $slug ) {
			throw new Tool_Refusal( 'invalid_args', 'type must be a content type slug from jetengine_list_content_types.' );
		}
		if ( ! isset( $types[ $slug ] ) || ! is_object( $types[ $slug ] ) || ! isset( $types[ $slug ]->db ) || ! is_object( $types[ $slug ]->db ) ) {
			throw new Tool_Refusal( 'not_found', sprintf( 'No JetEngine content type %s.', esc_html( $slug ) ) );
		}
		$cap = self::cct_setting( $types[ $slug ], 'capability', 'manage_options' );
		if ( ! is_string( $cap ) || '' === $cap ) {
			$cap = 'manage_options';
		}
		if ( ! current_user_can( $cap ) ) {
			throw new Tool_Refusal( 'forbidden', 'You do not have permission to use this content type.' );
		}
		return [ $slug, $types[ $slug ] ];
	}

	/** Item columns Royal MCP returns besides the fields. */
	const CCT_SYSTEM_COLUMNS = [ '_ID' => 'id', 'cct_status' => 'status', 'cct_author_id' => 'author_id', 'cct_created' => 'created', 'cct_modified' => 'modified', 'cct_single_post_id' => 'linked_post_id' ];

	private static function cct_row( $item, array $fields ) {
		if ( is_object( $item ) ) {
			$item = get_object_vars( $item );
		}
		$item = is_array( $item ) ? $item : [];
		$row  = [];
		foreach ( self::CCT_SYSTEM_COLUMNS as $col => $key ) {
			if ( array_key_exists( $col, $item ) ) {
				$row[ $key ] = in_array( $col, [ '_ID', 'cct_author_id', 'cct_single_post_id' ], true ) ? (int) $item[ $col ] : $item[ $col ];
			}
		}
		$values = [];
		foreach ( $fields as $name => $field ) {
			if ( ! self::key_open( $name ) ) {
				continue;
			}
			$values[ $name ] = array_key_exists( $name, $item ) ? self::unserialize_plain( $item[ $name ] ) : null;
		}
		$row['values'] = $values;
		return $row;
	}

	private static function list_content_types( array $args ) {
		$types = self::content_types();
		if ( null === $types ) {
			return self::page_of( [], $args, [ 'note' => 'JetEngine\'s Custom Content Types module is off.' ] );
		}
		$rows = [];
		foreach ( $types as $slug => $type ) {
			if ( ! is_object( $type ) ) {
				continue;
			}
			$cap = self::cct_setting( $type, 'capability', 'manage_options' );
			if ( is_string( $cap ) && '' !== $cap && ! current_user_can( $cap ) ) {
				continue;
			}
			$count = null;
			if ( isset( $type->db ) && is_object( $type->db ) && method_exists( $type->db, 'count' ) ) {
				$count = (int) $type->db->count();
			}
			$rows[] = [
				'slug'       => sanitize_key( (string) $slug ),
				'name'       => self::label( self::cct_setting( $type, 'name', $slug ) ),
				'item_count' => $count,
				'fields'     => array_values( array_map( static function ( $f ) { return self::describe_field( $f, self::CCT_WRITABLE_TYPES ); }, self::cct_fields( $type ) ) ),
			];
		}
		return self::page_of( $rows, $args );
	}

	private static function list_cct_items( array $args ) {
		list( $slug, $type ) = self::cct_type_arg( $args );
		$page     = self::int_arg( $args, 'page', 1, 1, self::MAX_PAGE );
		$per_page = self::int_arg( $args, 'per_page', 20, 1, self::MAX_PER_PAGE );
		$db       = $type->db;
		if ( ! method_exists( $db, 'query' ) ) {
			throw new Tool_Refusal( 'not_available', 'This version of JetEngine does not offer item reads.' );
		}
		$total  = method_exists( $db, 'count' ) ? (int) $db->count() : null;
		$items  = $db->query( [], $per_page + 1, ( $page - 1 ) * $per_page, [ [ 'orderby' => '_ID', 'order' => 'DESC' ] ] );
		$items  = is_array( $items ) ? array_values( $items ) : [];
		$more   = count( $items ) > $per_page;
		$fields = self::cct_fields( $type );
		$rows   = [];
		foreach ( array_slice( $items, 0, $per_page ) as $item ) {
			$rows[] = self::cct_row( $item, $fields );
		}
		$out = [ 'results' => $rows, 'total' => null === $total ? count( $rows ) : $total, 'has_more' => $more, 'page' => $page, 'per_page' => $per_page, 'type' => $slug ];
		if ( $more ) {
			$out['next_page'] = $page + 1;
		}
		return $out;
	}

	/** One stored item, or null. */
	private static function cct_item( $type, $id ) {
		$db = $type->db;
		if ( method_exists( $db, 'get_item' ) ) {
			$item = $db->get_item( (int) $id );
		} elseif ( method_exists( $db, 'query' ) ) {
			$found = $db->query( [ '_ID' => (int) $id ], 1 );
			$item  = is_array( $found ) ? reset( $found ) : null;
		} else {
			return null;
		}
		if ( is_object( $item ) ) {
			$item = get_object_vars( $item );
		}
		return is_array( $item ) && (int) ( $item['_ID'] ?? 0 ) === (int) $id ? $item : null;
	}

	private static function get_cct_item( array $args ) {
		list( $slug, $type ) = self::cct_type_arg( $args );
		$id   = self::int_arg( $args, 'item_id', null, 1, PHP_INT_MAX );
		$item = self::cct_item( $type, $id );
		if ( null === $item ) {
			throw new Tool_Refusal( 'not_found', sprintf( 'Item %d of content type %s was not found.', (int) $id, esc_html( $slug ) ) );
		}
		$fields = self::cct_fields( $type );
		return array_merge( [ 'type' => $slug ], self::cct_row( $item, $fields ), [ 'fields' => array_values( array_map( static function ( $f ) { return self::describe_field( $f, self::CCT_WRITABLE_TYPES ); }, $fields ) ) ] );
	}

	private static function cct_print( $item, array $names ) {
		$sub = [];
		foreach ( $names as $n ) {
			$sub[ $n ] = is_array( $item ) && array_key_exists( $n, $item ) ? (string) ( is_scalar( $item[ $n ] ) || null === $item[ $n ] ? $item[ $n ] : wp_json_encode( $item[ $n ] ) ) : null;
		}
		return md5( (string) wp_json_encode( $sub ) );
	}

	private static function update_cct_item( array $args ) {
		if ( \Royal_MCP\MCP\Server::read_only_mode() ) {
			throw new Tool_Refusal( 'read_only_mode', 'This site is in read-only mode: tools that change the site are switched off.' );
		}
		list( $slug, $type ) = self::cct_type_arg( $args );
		$id    = self::int_arg( $args, 'item_id', null, 1, PHP_INT_MAX );
		$input = self::fields_arg( $args );
		$item  = self::cct_item( $type, $id );
		if ( null === $item ) {
			throw new Tool_Refusal( 'not_found', sprintf( 'Item %d of content type %s was not found.', (int) $id, esc_html( $slug ) ) );
		}
		if ( ! method_exists( $type->db, 'update' ) ) {
			throw new Tool_Refusal( 'not_available', 'This version of JetEngine does not offer item updates.' );
		}
		$fields   = self::cct_fields( $type );
		$data     = [];
		$warnings = [];
		foreach ( $input as $name => $value ) {
			$name = (string) $name;
			if ( ! isset( $fields[ $name ] ) || isset( self::CCT_SYSTEM_COLUMNS[ $name ] ) ) {
				throw new Tool_Refusal( 'unknown_field', sprintf( 'Field %s is not a field of content type %s. Nothing was changed.', esc_html( $name ), esc_html( $slug ) ), [ 'field' => $name ] );
			}
			if ( ! self::key_open( $name ) ) {
				throw new Tool_Refusal( 'protected_field', sprintf( 'Field %s is a protected key, which these tools do not write. Nothing was changed.', esc_html( $name ) ), [ 'field' => $name ] );
			}
			$field = $fields[ $name ];
			if ( ( 'select' === ( $field['type'] ?? '' ) || 'posts' === ( $field['type'] ?? '' ) ) && self::is_multiple( $field ) ) {
				throw new Tool_Refusal( 'unsupported_field_type', sprintf( 'Field %s takes several values, which these tools do not write on content type items. Change it in JetEngine. Nothing was changed.', esc_html( $name ) ), [ 'field' => $name ] );
			}
			$step = self::stored_value( $field, $value, self::CCT_WRITABLE_TYPES, $warnings );
			if ( isset( $step['value'] ) && ! is_scalar( $step['value'] ) ) {
				throw new Tool_Refusal( 'unsupported_field_type', sprintf( 'Field %s stores several parts, which these tools do not write on content type items. Change it in JetEngine. Nothing was changed.', esc_html( $name ) ), [ 'field' => $name ] );
			}
			$data[ $name ] = ! empty( $step['clear'] ) ? '' : (string) $step['value'];
		}
		$names = array_keys( $data );
		$prior = [];
		foreach ( $names as $n ) {
			$prior[ $n ] = array_key_exists( $n, $item ) ? $item[ $n ] : null;
		}
		$same = true;
		foreach ( $data as $n => $v ) {
			if ( (string) $prior[ $n ] !== $v ) {
				$same = false;
			}
		}
		if ( $same ) {
			return Envelope::success( sprintf( 'Item %d of %s already held these values; nothing changed.', (int) $id, $slug ), [ 'type' => $slug, 'item_id' => (int) $id, 'changed' => [] ] );
		}
		if ( array_key_exists( 'cct_modified', $item ) ) {
			$data['cct_modified'] = current_time( 'mysql' );
		}
		$type->db->update( $data, [ '_ID' => (int) $id ] );
		$after   = self::cct_item( $type, $id );
		$changed = [];
		$fails   = [];
		foreach ( $names as $n ) {
			$now = is_array( $after ) && array_key_exists( $n, $after ) ? (string) $after[ $n ] : null;
			if ( $now !== (string) $prior[ $n ] ) {
				$changed[] = $n;
			}
			if ( $now !== $data[ $n ] ) {
				$fails[] = $n;
			}
		}
		$values = [];
		foreach ( $names as $n ) {
			$values[ $n ] = is_array( $after ) && array_key_exists( $n, $after ) ? $after[ $n ] : null;
		}
		$struct = [ 'type' => $slug, 'item_id' => (int) $id, 'changed' => $changed, 'values' => (object) $values ];
		if ( $warnings ) {
			$struct['warnings'] = $warnings;
		}
		if ( $fails ) {
			$struct['not_saved'] = $fails;
		}
		if ( ! $changed ) {
			throw new Tool_Refusal( 'not_saved', sprintf( 'JetEngine did not save the change to item %d. Nothing was changed.', (int) $id ), [ 'fields' => $fails ] );
		}
		$undo = Undo_Store::store( [
			'op'           => 'jetengine_update_cct_item',
			'summary'      => sprintf( 'Restore %d field(s) of %s item %d', count( $changed ), $slug, (int) $id ),
			'target'       => [ 'cct_type' => $slug, 'item_id' => (int) $id ],
			'pre_op_state' => [ 'prior' => array_intersect_key( $prior, array_flip( $changed ) ), 'written' => self::cct_print( $after, $changed ), 'fields' => $changed, 'applied' => array_intersect_key( $values, array_flip( $changed ) ) ],
		] );
		return Envelope::success( sprintf( 'Updated %d field(s) of %s item %d: %s.', count( $changed ), $slug, (int) $id, implode( ', ', $changed ) ), $struct, $undo );
	}

	// ============ Relations ============

	/** id => relation object. */
	private static function relations() {
		$je = jet_engine();
		if ( ! isset( $je->relations ) || ! is_object( $je->relations ) || ! method_exists( $je->relations, 'get_active_relations' ) ) {
			return null;
		}
		$all = $je->relations->get_active_relations();
		return is_array( $all ) ? $all : [];
	}

	private static function relation_arg( $rel, $key ) {
		if ( is_object( $rel ) && method_exists( $rel, 'get_args' ) ) {
			$v = $rel->get_args( $key );
			return is_scalar( $v ) ? (string) $v : '';
		}
		return '';
	}

	private static function list_relations( array $args ) {
		$all = self::relations();
		if ( null === $all ) {
			return self::page_of( [], $args, [ 'note' => 'JetEngine\'s relations component is not available.' ] );
		}
		$rows = [];
		foreach ( $all as $id => $rel ) {
			if ( ! is_object( $rel ) ) {
				continue;
			}
			$rid  = method_exists( $rel, 'get_id' ) ? (int) $rel->get_id() : (int) $id;
			$name = method_exists( $rel, 'get_relation_name' ) ? (string) $rel->get_relation_name() : self::relation_arg( $rel, 'name' );
			$rows[] = [
				'id'            => $rid,
				'name'          => self::label( $name ),
				'parent_object' => self::label( self::relation_arg( $rel, 'parent_object' ) ),
				'child_object'  => self::label( self::relation_arg( $rel, 'child_object' ) ),
				'type'          => sanitize_key( self::relation_arg( $rel, 'type' ) ),
			];
		}
		return self::page_of( $rows, $args );
	}

	private static function get_related_items( array $args ) {
		$all = self::relations();
		if ( null === $all ) {
			throw new Tool_Refusal( 'not_available', 'JetEngine\'s relations component is not available.' );
		}
		$rid       = self::int_arg( $args, 'relation_id', null, 1, PHP_INT_MAX );
		$object_id = self::int_arg( $args, 'object_id', null, 1, PHP_INT_MAX );
		$direction = $args['direction'] ?? 'children';
		if ( ! in_array( $direction, [ 'children', 'parents' ], true ) ) {
			throw new Tool_Refusal( 'invalid_args', 'direction must be "children" or "parents".' );
		}
		$rel = null;
		foreach ( $all as $id => $candidate ) {
			$cid = is_object( $candidate ) && method_exists( $candidate, 'get_id' ) ? (int) $candidate->get_id() : (int) $id;
			if ( $cid === $rid ) {
				$rel = $candidate;
				break;
			}
		}
		if ( ! is_object( $rel ) ) {
			throw new Tool_Refusal( 'not_found', sprintf( 'No JetEngine relation %d.', (int) $rid ) );
		}
		$method = 'children' === $direction ? 'get_children' : 'get_parents';
		if ( ! method_exists( $rel, $method ) ) {
			throw new Tool_Refusal( 'not_available', 'This version of JetEngine does not offer related item reads.' );
		}
		$from = self::relation_arg( $rel, 'children' === $direction ? 'parent_object' : 'child_object' );
		$to   = self::relation_arg( $rel, 'children' === $direction ? 'child_object' : 'parent_object' );
		if ( 0 === strpos( $from, 'posts::' ) ) {
			$src = get_post( $object_id );
			if ( ! $src instanceof \WP_Post || ! Post_Access::can_read( $src ) ) {
				throw new Tool_Refusal( 'not_found', sprintf( 'Object %d was not found, or you do not have permission to use it.', (int) $object_id ) );
			}
		}
		$ids = $rel->$method( $object_id, 'ids' );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : [] ) ) ) );
		$rows     = [];
		$withheld = 0;
		$is_posts = 0 === strpos( $to, 'posts::' );
		foreach ( $ids as $iid ) {
			if ( $is_posts ) {
				$p = get_post( $iid );
				if ( ! $p instanceof \WP_Post || ! Post_Access::can_read( $p ) ) {
					$withheld++;
					continue;
				}
				$rows[] = [ 'id' => $iid, 'title' => wp_strip_all_tags( get_the_title( $p ) ), 'post_type' => (string) $p->post_type ];
			} else {
				$rows[] = [ 'id' => $iid ];
			}
		}
		$extra = [ 'relation_id' => $rid, 'object_id' => $object_id, 'direction' => $direction, 'related_object' => self::label( $to ) ];
		if ( $withheld ) {
			$extra['withheld'] = $withheld;
			$extra['withheld_note'] = 'Related posts you cannot read are counted here but not listed.';
		}
		return self::page_of( $rows, $args, $extra );
	}

	// ============ Undo ============

	public static function undo( array $snapshot, $force = false ) {
		if ( ! self::is_available() ) {
			throw new \Exception( 'JetEngine is no longer active, so this change cannot be undone.' );
		}
		if ( \Royal_MCP\MCP\Server::read_only_mode() ) {
			throw new Tool_Refusal( 'read_only_mode', 'This site is in read-only mode: tools that change the site are switched off.' );
		}
		$op  = (string) ( $snapshot['op'] ?? '' );
		$pre = (array) ( $snapshot['pre_op_state'] ?? [] );
		if ( 'jetengine_update_post_fields' === $op ) {
			return self::undo_post_fields( $snapshot, $pre, $force );
		}
		if ( 'jetengine_update_cct_item' === $op ) {
			return self::undo_cct_item( $snapshot, $pre, $force );
		}
		throw new \Exception( 'Unsupported JetEngine undo operation.' );
	}

	private static function undo_post_fields( array $snapshot, array $pre, $force ) {
		$pid  = (int) ( $snapshot['target']['post_id'] ?? 0 );
		$post = $pid > 0 ? get_post( $pid ) : null;
		if ( ! $post instanceof \WP_Post ) {
			throw new Tool_Refusal( 'already_removed', sprintf( 'Post %d no longer exists.', $pid ) );
		}
		if ( ! current_user_can( 'edit_post', $pid ) ) {
			throw new Tool_Refusal( 'forbidden', 'You do not have permission to edit this post.' );
		}
		$prior   = is_array( $pre['prior'] ?? null ) ? $pre['prior'] : [];
		$written = is_array( $pre['written'] ?? null ) ? $pre['written'] : [];
		$names   = array_values( array_filter( array_map( 'strval', array_keys( $prior ) ), [ __CLASS__, 'key_open' ] ) );
		if ( ! $force ) {
			foreach ( $names as $n ) {
				if ( self::meta_print( $pid, $n ) !== (string) ( $written[ $n ] ?? '' ) ) {
					throw new Tool_Refusal( 'edited_since_write', sprintf( 'Cannot undo: field %s on post %d changed again after this change. The undo token stays valid: undo again with force: true to restore anyway.', esc_html( $n ), $pid ) );
				}
			}
		}
		foreach ( $names as $n ) {
			$p = is_array( $prior[ $n ] ) ? $prior[ $n ] : [];
			if ( ! empty( $p['exists'] ) ) {
				update_post_meta( $pid, $n, wp_slash( $p['value'] ?? '' ) );
			} else {
				delete_post_meta( $pid, $n );
			}
		}
		\Royal_MCP\MCP\Support\Post_Write_Hooks::trigger( $pid );
		return Envelope::success( sprintf( 'Restored %d JetEngine field(s) on post %d.', count( $names ), $pid ), [ 'undone' => true, 'op' => 'jetengine_update_post_fields', 'target' => [ 'post_id' => $pid ], 'restored' => true, 'fields' => $names ] );
	}

	private static function undo_cct_item( array $snapshot, array $pre, $force ) {
		$slug = (string) ( $snapshot['target']['cct_type'] ?? '' );
		$id   = (int) ( $snapshot['target']['item_id'] ?? 0 );
		list( , $type ) = self::cct_type_arg( [ 'type' => $slug ] );
		$item = self::cct_item( $type, $id );
		if ( null === $item ) {
			throw new Tool_Refusal( 'already_removed', sprintf( 'Item %d of %s no longer exists.', $id, esc_html( $slug ) ) );
		}
		$fields = self::cct_fields( $type );
		$prior  = is_array( $pre['prior'] ?? null ) ? $pre['prior'] : [];
		$names  = array_values( array_filter( array_map( 'strval', (array) ( $pre['fields'] ?? [] ) ), static function ( $n ) use ( $fields, $prior ) {
			return isset( $fields[ $n ] ) && array_key_exists( $n, $prior ) && self::key_open( $n );
		} ) );
		if ( ! $force && self::cct_print( $item, $names ) !== (string) ( $pre['written'] ?? '' ) ) {
			throw new Tool_Refusal( 'edited_since_write', sprintf( 'Cannot undo: item %d of %s changed again after this change. The undo token stays valid: undo again with force: true to restore anyway.', $id, esc_html( $slug ) ) );
		}
		$data = [];
		foreach ( $names as $n ) {
			$data[ $n ] = null === $prior[ $n ] ? '' : ( is_scalar( $prior[ $n ] ) ? (string) $prior[ $n ] : '' );
		}
		if ( $data ) {
			$type->db->update( $data, [ '_ID' => $id ] );
		}
		return Envelope::success( sprintf( 'Restored %d field(s) of %s item %d.', count( $data ), $slug, $id ), [ 'undone' => true, 'op' => 'jetengine_update_cct_item', 'target' => [ 'cct_type' => $slug, 'item_id' => $id ], 'restored' => true, 'fields' => $names ] );
	}

	/** Rows for the change view: each field before, as written, and now. */
	public static function change_rows( $op, array $t, array $pre ) {
		$rows    = [];
		$applied = is_array( $pre['applied'] ?? null ) ? $pre['applied'] : [];
		if ( 'jetengine_update_post_fields' === $op ) {
			$pid = (int) ( $t['post_id'] ?? 0 );
			foreach ( (array) ( $pre['prior'] ?? [] ) as $n => $p ) {
				$n   = (string) $n;
				$has = $pid > 0 && metadata_exists( 'post', $pid, $n );
				$rows[] = [ 'field' => $n, 'before' => is_array( $p ) ? ( $p['value'] ?? null ) : null, 'after' => $applied[ $n ] ?? null, 'current' => $has ? get_post_meta( $pid, $n, true ) : null, 'key_hint' => $n ];
			}
			return $rows;
		}
		foreach ( (array) ( $pre['prior'] ?? [] ) as $n => $v ) {
			$rows[] = [ 'field' => (string) $n, 'before' => $v, 'after' => $applied[ $n ] ?? null ];
		}
		return $rows;
	}
}
