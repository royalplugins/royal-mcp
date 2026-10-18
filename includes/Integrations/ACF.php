<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advanced Custom Fields MCP Integration
 *
 * Registers MCP tools for ACF (free + Pro). Only loaded when ACF is active.
 *
 * Why a dedicated integration when wp_get_post_meta / wp_update_post_meta already
 * "work" with ACF: WP's meta API returns the raw stored value, which for ACF means
 * serialized arrays (repeater rows), raw post IDs (relationship), raw attachment
 * IDs (image), etc. ACF's get_field()/update_field() respect each field's Return
 * Format setting and hydrate values per field type — what a human editor sees in
 * the ACF UI. LLMs interacting with ACF want the formatted view.
 *
 * Tools:
 *  - acf_get_field         — read one field, ACF-formatted
 *  - acf_get_fields        — read every ACF field on a post (discovery + read bundle)
 *  - acf_update_field      — write one field
 *  - acf_get_field_groups  — enumerate field groups, optionally by post type
 */
class ACF {

	/**
	 * Check if ACF is available.
	 */
	public static function is_available() {
		return function_exists( 'get_field' ) && function_exists( 'acf_get_field_groups' );
	}

	/**
	 * Get tool definitions for MCP tools/list response.
	 */
	public static function get_tools() {
		// Always register so tools appear in MCP tools/list regardless of the
		// underlying plugin activation state. execute_tool gates at call time
		// with a clean 'not active' throw. Prevents ghost-tools UX where
		// activating a plugin post-MCP-connection requires the client to
		// reconnect before the tools become discoverable.

		return [
			[
				'name'        => 'acf_get_field',
				'description' => 'Get a single ACF (Advanced Custom Fields) field value from a post, formatted per the field\'s Return Format setting (image arrays, post objects, parsed repeater rows, etc.). Use this instead of wp_get_post_meta when ACF is active — wp_get_post_meta returns the raw serialized value, this returns the hydrated value the ACF UI would show.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'field_name' => [ 'type' => 'string', 'description' => 'ACF field name or field key (e.g. "subtitle" or "field_abc123")' ],
						'post_id'    => [ 'type' => 'integer', 'description' => 'Post or page ID' ],
					],
					'required'   => [ 'field_name', 'post_id' ],
				],
			],
			[
				'name'        => 'acf_get_fields',
				'description' => 'Get every ACF field defined for a post in one call, with each field\'s name, label, type, and formatted value. The most efficient way for an AI agent to discover what ACF fields exist on a post and read them all without round-trips.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer', 'description' => 'Post or page ID' ],
					],
					'required'   => [ 'post_id' ],
				],
			],
			[
				'name'        => 'acf_update_field',
				'description' => 'Update an ACF field value on a post. Accepts the field name (or field key) and a value matching the field type — scalar for text/number/select-single, array for repeater rows / flexible content / select-multi, post ID for post_object/relationship, attachment ID for image/file. ACF stores it correctly per field type.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'field_name' => [ 'type' => 'string', 'description' => 'ACF field name or field key' ],
						'post_id'    => [ 'type' => 'integer', 'description' => 'Post or page ID' ],
						'value'      => [ 'type' => ['string', 'integer', 'number', 'boolean', 'array', 'object', 'null'], 'description' => 'New value. Type depends on the field type — see ACF documentation.' ],
					],
					'required'   => [ 'field_name', 'post_id', 'value' ],
				],
			],
			[
				'name'        => 'acf_get_field_groups',
				'description' => 'Enumerate ACF field groups on this site with the fields they contain. Optionally filter by post type to discover which field groups apply to a given content type. Use this to discover what custom fields are available before reading or writing them.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_type' => [ 'type' => 'string', 'description' => 'Optional post type slug (e.g. "post", "page", "product") — returns only field groups attached to that post type. Omit to return all field groups.' ],
					],
				],
			],
		];
	}

	/**
	 * Execute an ACF MCP tool.
	 */
	public static function execute_tool( $name, $args ) {
		// umbrella cap check fires BEFORE the active-check. Without
		// this a Subscriber-tier OAuth Bearer would receive "Advanced Custom
		// Fields is not active" and learn whether the integration is present.
		// edit_posts is the least-restrictive cap among the per-case checks
		// below; per-handler read_post / edit_post / edit_posts still enforce
		// object-level access for callers who pass the umbrella.
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new \Exception( 'You do not have permission to use ACF tools.' );
		}

		if ( ! self::is_available() ) {
			throw new \Exception( 'Advanced Custom Fields is not active' );
		}

		switch ( $name ) {
			case 'acf_get_field':
				$field_name = sanitize_text_field( $args['field_name'] ?? '' );
				$post_id    = intval( $args['post_id'] ?? 0 );
				if ( '' === $field_name || $post_id <= 0 ) {
					throw new \Exception( 'field_name and post_id are required' );
				}
				if ( ! get_post( $post_id ) ) {
					throw new \Exception( 'Post not found for ID ' . esc_html( (string) $post_id ) );
				}
				// ACF field values live in post meta; gate behind the
				// parent post's read cap (private posts' ACF data is not public).
				if ( ! \Royal_MCP\MCP\Support\Post_Access::can_read_content( $post_id ) ) {
					throw new \Exception( 'You do not have permission to read ACF fields on this post.' );
				}
				$field_object = get_field_object( $field_name, $post_id, true, true );
				if ( ! $field_object ) {
					return [
						'post_id'    => $post_id,
						'field_name' => $field_name,
						'value'      => null,
						'exists'     => false,
						'message'    => 'No ACF field with that name/key is registered for this post.',
					];
				}
				return [
					'post_id'    => $post_id,
					'field_name' => $field_object['name'] ?? $field_name,
					'field_key'  => $field_object['key'] ?? '',
					'label'      => $field_object['label'] ?? '',
					'type'       => $field_object['type'] ?? '',
					'value'      => self::redact_field( $field_object, self::flatten_value( $field_object['value'] ?? null ) ),
					'exists'     => true,
				];

			case 'acf_get_fields':
				$post_id = intval( $args['post_id'] ?? 0 );
				if ( $post_id <= 0 ) {
					throw new \Exception( 'post_id is required' );
				}
				if ( ! get_post( $post_id ) ) {
					throw new \Exception( 'Post not found for ID ' . esc_html( (string) $post_id ) );
				}
				if ( ! \Royal_MCP\MCP\Support\Post_Access::can_read_content( $post_id ) ) {
					throw new \Exception( 'You do not have permission to read ACF fields on this post.' );
				}
				$objects = get_field_objects( $post_id, true, true );
				if ( ! $objects ) {
					return [
						'post_id' => $post_id,
						'fields'  => [],
						'message' => 'No ACF fields are attached to this post.',
					];
				}
				$fields = [];
				foreach ( $objects as $field_name => $object ) {
					$fields[] = [
						'name'  => $field_name,
						'key'   => $object['key'] ?? '',
						'label' => $object['label'] ?? '',
						'type'  => $object['type'] ?? '',
						'value' => self::redact_field( $object, self::flatten_value( $object['value'] ?? null ) ),
					];
				}
				return [
					'post_id' => $post_id,
					'fields'  => $fields,
				];

			case 'acf_update_field':
				$field_name = sanitize_text_field( $args['field_name'] ?? '' );
				$post_id    = intval( $args['post_id'] ?? 0 );
				if ( '' === $field_name || $post_id <= 0 ) {
					throw new \Exception( 'field_name and post_id are required' );
				}
				if ( ! get_post( $post_id ) ) {
					throw new \Exception( 'Post not found for ID ' . esc_html( (string) $post_id ) );
				}
				// writing ACF fields modifies the post; require the
				// parent post's edit cap.
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					throw new \Exception( 'You do not have permission to edit ACF fields on this post.' );
				}
				if ( ! array_key_exists( 'value', $args ) ) {
					throw new \Exception( 'value is required (pass null to clear the field)' );
				}
				// Only fields ACF knows: an unknown name would make update_field()
				// write raw post meta under that name.
				// Resolved the way update_field() resolves it (field name, key, or sub-field path).
				$acf_field = function_exists( 'acf_maybe_get_field' ) ? acf_maybe_get_field( $field_name, $post_id, false ) : false;
				if ( ! $acf_field ) {
					throw new \Exception( 'Unknown ACF field "' . esc_html( $field_name ) . '". Use acf_get_fields to list the fields on this post.' );
				}
				$meta_key = ! empty( $acf_field['name'] ) ? (string) $acf_field['name'] : $field_name;
				$path_key = 0 === strpos( $field_name, 'field_' ) ? $meta_key : $field_name;
				if ( ( is_protected_meta( $meta_key, 'post' ) || is_protected_meta( $path_key, 'post' ) )
					&& ! current_user_can( 'edit_post_meta', $post_id, $meta_key ) ) {
					throw new \Exception( 'You do not have permission to write that field.' );
				}
				$result = update_field( $field_name, $args['value'], $post_id );
				if ( false === $result ) {
					throw new \Exception( 'Failed to update ACF field "' . esc_html( $field_name ) . '" on post ' . esc_html( (string) $post_id ) );
				}
				$updated = get_field_object( $field_name, $post_id, true, true );
				return [
					'post_id'    => $post_id,
					'field_name' => $updated['name'] ?? $field_name,
					'field_key'  => $updated['key'] ?? '',
					'type'       => $updated['type'] ?? '',
					'value'      => self::flatten_value( $updated['value'] ?? null ),
					'success'    => true,
				];

			case 'acf_get_field_groups':
				// discovery of registered field groups is editor-tier
				// metadata (which custom fields exist on which post types).
				if ( ! current_user_can( 'edit_posts' ) ) {
					throw new \Exception( 'You do not have permission to discover ACF field groups.' );
				}
				$filter = [];
				if ( ! empty( $args['post_type'] ) ) {
					$filter['post_type'] = sanitize_key( $args['post_type'] );
				}
				$groups  = acf_get_field_groups( $filter );
				$results = [];
				foreach ( $groups as $group ) {
					$group_key = $group['key'] ?? '';
					$fields    = function_exists( 'acf_get_fields' ) ? acf_get_fields( $group_key ) : [];
					$field_summary = [];
					if ( is_array( $fields ) ) {
						foreach ( $fields as $field ) {
							$field_summary[] = [
								'name'  => $field['name'] ?? '',
								'key'   => $field['key'] ?? '',
								'label' => $field['label'] ?? '',
								'type'  => $field['type'] ?? '',
							];
						}
					}
					$results[] = [
						'key'      => $group_key,
						'title'    => $group['title'] ?? '',
						'location' => $group['location'] ?? [],
						'fields'   => $field_summary,
					];
				}
				return [
					'count'        => count( $results ),
					'field_groups' => $results,
				];

			default:
				throw new \Exception( 'Unknown ACF tool: ' . esc_html( $name ) );
		}
	}

	/**
	 * Flatten ACF return values that aren't JSON-encodable as-is. ACF returns
	 * WP_Post / WP_User / WP_Term objects for post_object / user / taxonomy
	 * fields when "Return Format" is set to "Object" — we collapse those to a
	 * small array the LLM can reason about, while passing scalars and plain
	 * arrays through untouched.
	 */
	/**
	 * Field value with the credential redaction post meta reads get; a
	 * password-type field is always withheld.
	 */
	private static function redact_field( array $field, $value ) {
		$empty = ( '' === $value || null === $value || [] === $value || '0' === $value || false === $value );
		if ( 'password' === ( $field['type'] ?? '' ) ) {
			return $empty ? $value : '[REDACTED]';
		}
		static $server = null;
		if ( null === $server ) {
			$server = new \Royal_MCP\MCP\Server();
		}
		return $server->redact_for_display( $value, (string) ( $field['name'] ?? '' ) );
	}

	private static function flatten_value( $value ) {
		if ( is_null( $value ) || is_scalar( $value ) ) {
			return $value;
		}
		if ( $value instanceof \WP_Post ) {
			// A linked post the caller may not read comes back as its ID only.
			if ( ! \Royal_MCP\MCP\Support\Post_Access::can_read( $value ) ) {
				return [ 'id' => (int) $value->ID, 'readable' => false ];
			}
			return [
				'id'         => (int) $value->ID,
				'title'      => $value->post_title,
				'post_type'  => $value->post_type,
				'status'     => $value->post_status,
				'permalink'  => get_permalink( $value ),
			];
		}
		if ( $value instanceof \WP_User ) {
			$user = [
				'id'           => (int) $value->ID,
				'display_name' => $value->display_name,
			];
			// Email addresses only for users who can list users.
			if ( current_user_can( 'list_users' ) ) {
				$user['user_email'] = $value->user_email;
			}
			return $user;
		}
		if ( $value instanceof \WP_Term ) {
			return [
				'id'       => (int) $value->term_id,
				'name'     => $value->name,
				'slug'     => $value->slug,
				'taxonomy' => $value->taxonomy,
			];
		}
		if ( is_array( $value ) ) {
			// A user field in "array" return format carries the email too.
			if ( isset( $value['user_email'] ) && ! current_user_can( 'list_users' ) ) {
				unset( $value['user_email'] );
			}
			$flat = [];
			foreach ( $value as $k => $v ) {
				$flat[ $k ] = self::flatten_value( $v );
			}
			return $flat;
		}
		// Anything else (object that isn't WP_*, resource, etc.) — JSON-safe cast.
		return is_object( $value ) ? get_object_vars( $value ) : null;
	}
}
