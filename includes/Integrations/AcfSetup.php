<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Tool_Refusal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advanced Custom Fields, setup tools: create, copy, change, delete, import
 * and export field groups; add, change, delete and reorder fields; read a
 * group's full definition and the field types on the site; and register a
 * post type through ACF's own post type screen.
 *
 * Everything is stored the way ACF stores it (its own posts and options).
 * Nothing here writes a file. Administrators only.
 */
class AcfSetup {

	/** Reply fields that hold ACF's own group and field keys ("group_...", "field_..."): identifiers, not secrets. */
	const ID_FIELDS = [ 'key', 'group_key', 'group_keys', 'field_key', 'field_keys', 'source_group_key', 'clone_group_key', 'new_key', 'new_group_key', 'missing_keys', 'parent', 'parent_key', 'field_keys_ordered', 'previous_order', 'new_order', 'post_type_key' ];

	/** Tool => [ handler, undo handler or null for a read ]. */
	const TOOLS = [
		'acf_create_field_group'         => [ 'handle_create_field_group', 'undo_create_field_group' ],
		'acf_clone_field_group'          => [ 'handle_clone_field_group', 'undo_clone_field_group' ],
		'acf_update_field_group'         => [ 'handle_update_field_group', 'undo_update_field_group' ],
		'acf_delete_field_group'         => [ 'handle_delete_field_group', 'undo_delete_field_group' ],
		'acf_import_field_group_json'    => [ 'handle_import_field_group_json', 'undo_import_field_group_json' ],
		'acf_export_field_group_json'    => [ 'handle_export_field_group_json', null ],
		'acf_add_field'                  => [ 'handle_add_field', 'undo_add_field' ],
		'acf_update_field_definition'    => [ 'handle_update_field_definition', 'undo_update_field_definition' ],
		'acf_delete_field'               => [ 'handle_delete_field', 'undo_delete_field' ],
		'acf_reorder_fields'             => [ 'handle_reorder_fields', 'undo_reorder_fields' ],
		'acf_get_field_group_config'     => [ 'handle_get_field_group_config', null ],
		'acf_list_supported_field_types' => [ 'handle_list_supported_field_types', null ],
		'acf_register_post_type'         => [ 'handle_register_post_type', 'undo_register_post_type' ],
	];

	const GROUP_POSITIONS = [ 'acf_after_title', 'normal', 'side' ];
	const GROUP_STYLES = [ 'default', 'seamless' ];
	const LABEL_PLACEMENTS = [ 'top', 'left' ];
	const INSTRUCTION_PLACEMENTS = [ 'label', 'field' ];
	const IMPORT_MODES = [ 'create', 'update_if_exists', 'fail_if_exists' ];
	const LOCATION_OPERATORS = [ '==', '!=' ];

	public static function handles( $name ) {
		return is_string( $name ) && isset( self::TOOLS[ $name ] );
	}

	public static function is_available() {
		return self::plugin_active();
	}

	public static function get_tools() {
		return array_values( array_intersect_key( self::get_tool_schemas(), self::TOOLS ) );
	}

	private static function has_admin_caps() {
		return current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
	}

	public static function execute_tool( $name, $args ) {
		if ( ! self::handles( $name ) ) {
			throw new \Exception( 'Unknown ACF tool: ' . esc_html( is_string( $name ) ? $name : '' ) );
		}
		// Field groups shape every edit screen of the site: administrators only.
		if ( ! self::has_admin_caps() ) {
			throw new \Exception( 'You do not have permission to use the ACF setup tools.' );
		}
		return call_user_func( [ __CLASS__, self::TOOLS[ $name ][0] ], is_array( $args ) ? $args : [] );
	}

	/**
	 * Stores what an undo needs and returns the token details the replies carry.
	 *
	 * @param array $spec tool_name and undo_payload (which holds its own summary).
	 * @return array{token:string, expires_at:string}|null
	 */
	private static function make_token( array $spec ) {
		$payload = isset( $spec['undo_payload'] ) && is_array( $spec['undo_payload'] ) ? $spec['undo_payload'] : [];
		$stored  = \Royal_MCP\MCP\Undo_Store::store( [
			'op'           => (string) ( $spec['tool_name'] ?? '' ),
			'summary'      => isset( $payload['summary'] ) && is_string( $payload['summary'] ) ? $payload['summary'] : (string) ( $spec['tool_name'] ?? '' ),
			'target'       => [],
			// The field groups the write touched, as they stand right after it, to tell a later change apart.
			'pre_op_state' => [ 'payload' => $payload, 'groups' => self::fingerprints( self::group_keys_in( $payload ) ) ],
		] );
		return is_array( $stored ) && ! empty( $stored['token'] ) ? [ 'token' => (string) $stored['token'], 'expires_at' => (string) ( $stored['expires_at'] ?? '' ) ] : null;
	}

	/** The key of the field group a post belongs to: the group itself, or the group above a field or sub-field. '' when there is none. */
	private static function group_key_of_post( $post_id ) {
		for ( $hops = 0; $hops < 8 && $post_id > 0; $hops++ ) {
			$post = get_post( (int) $post_id );
			if ( ! $post ) {
				return '';
			}
			if ( 'acf-field-group' === $post->post_type ) {
				return (string) $post->post_name;
			}
			if ( 'acf-field' !== $post->post_type ) {
				return '';
			}
			$post_id = (int) $post->post_parent;
		}
		return '';
	}

	/**
	 * Every field group a stored undo touches: group keys ("group_...") found anywhere in it, the
	 * groups of the fields it names ("field_..."), and the groups its group or parent ids point at.
	 */
	private static function group_keys_in( $value, $depth = 0, $name = '' ) {
		$keys = [];
		if ( is_string( $value ) ) {
			if ( 1 === preg_match( '/^group_[A-Za-z0-9_]{1,60}$/', $value ) ) {
				return [ $value ];
			}
			if ( 1 === preg_match( '/^field_[A-Za-z0-9_]{1,60}$/', $value ) && function_exists( 'acf_get_field' ) ) {
				$field = acf_get_field( $value );
				$key   = is_array( $field ) && ! empty( $field['ID'] ) ? self::group_key_of_post( (int) $field['ID'] ) : '';
				return '' !== $key ? [ $key ] : [];
			}
			return [];
		}
		if ( is_int( $value ) && $value > 0 && in_array( $name, [ 'group_id', 'parent', 'parent_id', 'post_id', 'ID' ], true ) ) {
			$key = self::group_key_of_post( $value );
			return '' !== $key ? [ $key ] : [];
		}
		if ( ! is_array( $value ) || $depth > 4 ) {
			return [];
		}
		foreach ( $value as $k => $v ) {
			// A group key can be the name an entry is stored under; the stored data's own field names are not group keys.
			$named = is_string( $k ) && ! in_array( $k, [ 'group_key', 'group_keys', 'group_id', 'group_snapshot', 'group_count' ], true ) ? self::group_keys_in( $k, $depth + 1 ) : [];
			$keys  = array_merge( $keys, $named, self::group_keys_in( $v, $depth + 1, is_string( $k ) ? $k : '' ) );
		}
		return array_slice( array_values( array_unique( $keys ) ), 0, 50 );
	}

	/** What each field group looks like now: its settings and all its fields, as one hash ("absent" when it is gone or in the trash). */
	private static function fingerprints( array $group_keys ) {
		$out = [];
		foreach ( $group_keys as $key ) {
			$group        = function_exists( 'acf_get_field_group' ) ? acf_get_field_group( $key ) : null;
			$fields       = is_array( $group ) ? self::stored_fields( $group ) : [];
			$out[ $key ] = is_array( $group ) && ! empty( $group['ID'] ) ? md5( (string) wp_json_encode( [ $group, $fields ] ) ) : 'absent';
		}
		return $out;
	}

	/** Undo of any of the tools that return a token. */
	public static function undo( array $snapshot, $force = false ) {
		$op = (string) ( $snapshot['op'] ?? '' );
		if ( ! self::handles( $op ) || null === self::TOOLS[ $op ][1] ) {
			throw new \Exception( 'Unknown ACF undo.' );
		}
		if ( ! self::has_admin_caps() ) {
			throw new \Exception( 'You do not have permission to use the ACF setup tools.' );
		}
		if ( ! self::plugin_active() ) {
			throw new \Exception( 'Advanced Custom Fields is no longer active, so this cannot be undone from here.' );
		}
		$payload = (array) ( $snapshot['pre_op_state']['payload'] ?? [] );
		$was     = (array) ( $snapshot['pre_op_state']['groups'] ?? [] );
		$changed = array_keys( array_diff_assoc( $was, self::fingerprints( array_keys( $was ) ) ) );
		if ( $changed && ! $force ) {
			throw new Tool_Refusal( 'edited_since_write', esc_html( sprintf( 'The field group %s was changed after this write, so the undo left it as it is. Pass force: true to undo anyway; changes made since can be lost.', implode( ', ', $changed ) ) ), [ 'changed_since' => $changed ] );
		}
		$result  = (array) call_user_func( [ __CLASS__, self::TOOLS[ $op ][1] ], $payload, [ 'force' => (bool) $force ] );
		// ACF remembers the field groups it looked up, also the ones it did not find; forget those so what was just restored is seen.
		foreach ( [ 'field-groups', 'fields' ] as $store ) {
			if ( function_exists( 'acf_get_store' ) && is_object( acf_get_store( $store ) ) && method_exists( acf_get_store( $store ), 'reset' ) ) {
				acf_get_store( $store )->reset();
			}
		}
		$type = function_exists( 'acf_get_internal_post_type_instance' ) ? acf_get_internal_post_type_instance( 'acf-field-group' ) : null;
		if ( is_object( $type ) && isset( $type->cache_key ) && function_exists( 'acf_cache_key' ) ) {
			foreach ( array_unique( array_merge( array_keys( $was ), self::group_keys_in( $payload ) ) ) as $group_key ) {
				wp_cache_delete( $type->cache_key . $group_key, 'acf' );
				wp_cache_delete( acf_cache_key( $type->cache_key . $group_key ), 'acf' );
			}
			wp_cache_delete( acf_cache_key( (string) ( $type->cache_key_plural ?? '' ) ), 'acf' );
		}
		$summary = (string) ( $result['restored_state_summary'] ?? 'Done.' );
		unset( $result['restored_state_summary'], $result['remaining_payload'] );
		// A handler that left things alone (changed since, still holding values, or in use) says so; the refusal keeps the token usable.
		$reasons = array_column( (array) ( $result['skipped'] ?? [] ), 'reason' );
		if ( isset( $result['restored'] ) && false === $result['restored'] && ! $force && array_intersect( $reasons, [ 'edited_since_write', 'changed_since_write', 'has_values', 'in_use' ] ) ) {
			throw new Tool_Refusal( 'edited_since_write', esc_html( $summary ), [ 'skipped' => $result['skipped'] ] );
		}
		$undone = empty( $result['noop'] ) && ( ! isset( $result['restored'] ) || false !== $result['restored'] );
		return Envelope::success( $summary, [ 'undone' => $undone, 'op' => $op ] + $result );
	}

	public static function plugin_active() {
		$default = function_exists( 'get_field' )
			&& function_exists( 'acf_get_field_groups' )
			&& defined( 'ACF_VERSION' )
			&& version_compare( ACF_VERSION, '6.0', '>=' );
		return (bool) apply_filters( 'royal_mcp_acf_setup_active', $default );
	}

	public static function plugin_pro_active() {
		return self::plugin_active()
			&& function_exists( 'acf_is_pro' )
			&& acf_is_pro();
	}

	/** ACF 6.1 or later, free or Pro: both have the post types screen. */
	public static function plugin_61_plus() {
		return self::plugin_active()
			&& version_compare( ACF_VERSION, '6.1', '>=' )
			&& function_exists( 'acf_update_internal_post_type' );
	}

	public static function plugin_pro_61_plus() {
		return self::plugin_pro_active()
			&& version_compare( ACF_VERSION, '6.1', '>=' );
	}

	private static function handle_create_field_group( array $args ) {
		if ( ! self::has_admin_caps() ) {
			return self::error( 'insufficient_caps', 'manage_options capability required.' );
		}
		if ( ! self::plugin_active() ) {
			return self::error( 'acf_inactive', 'Advanced Custom Fields 6.0+ is not installed or active on this site.' );
		}

		$title = isset( $args['title'] ) && is_string( $args['title'] ) ? trim( $args['title'] ) : '';
		if ( $title === '' ) {
			return self::error( 'invalid_title', 'title is required and must be a non-empty string.' );
		}

		$fields_in = isset( $args['fields'] ) && is_array( $args['fields'] ) ? array_values( $args['fields'] ) : [];
		if ( empty( $fields_in ) ) {
			return self::error( 'invalid_fields', 'fields must be a non-empty array of field definitions.' );
		}

		$location_in = isset( $args['location'] ) && is_array( $args['location'] ) ? array_values( $args['location'] ) : [];
		if ( empty( $location_in ) ) {
			return self::error( 'invalid_location', 'location must be a non-empty array of location rule groups.' );
		}

		$location_validation = self::validate_location_rule_groups( $location_in );
		if ( is_array( $location_validation ) && isset( $location_validation['error'] ) ) {
			return $location_validation['error'];
		}
		$location_normalized = $location_validation['location'];

		foreach ( $fields_in as $idx => $field ) {
			if ( ! is_array( $field ) ) {
				return self::error( 'invalid_field', sprintf( 'fields[%d] must be an object.', (int) $idx ) );
			}
			$type = isset( $field['type'] ) ? (string) $field['type'] : '';
			if ( $type === '' ) {
				return self::error( 'invalid_field', sprintf( 'fields[%d] missing required "type" key.', (int) $idx ) );
			}
			if ( function_exists( 'acf_get_field_type' ) && ! acf_get_field_type( $type ) ) {
				return self::error(
					'unknown_field_type',
					sprintf( 'Field type "%s" not registered.', $type ),
					[ 'field_index' => (int) $idx, 'type' => $type ]
				);
			}
		}

		$group_key = isset( $args['key'] ) && is_string( $args['key'] ) && $args['key'] !== ''
			? $args['key']
			: self::generate_group_key();
		if ( strpos( $group_key, 'group_' ) !== 0 ) {
			$group_key = 'group_' . $group_key;
		}
		if ( function_exists( 'acf_get_field_group' ) && acf_get_field_group( $group_key ) ) {
			return self::error(
				'group_key_exists',
				sprintf( 'A field group with key "%s" already exists.', $group_key ),
				[ 'group_key' => $group_key ]
			);
		}

		$prepared_fields = [];
		foreach ( $fields_in as $idx => $field ) {
			$prepared_fields[] = self::prepare_field_for_write( $field, $idx );
		}

		$group_payload = [
			'key'         => $group_key,
			'title'       => $title,
			'fields'      => $prepared_fields,
			'location'    => $location_normalized,
			'menu_order'  => isset( $args['menu_order'] ) ? (int) $args['menu_order'] : 0,
			'position'    => isset( $args['position'] ) && in_array( $args['position'], self::GROUP_POSITIONS, true ) ? (string) $args['position'] : 'normal',
			'style'       => isset( $args['style'] ) && in_array( $args['style'], self::GROUP_STYLES, true ) ? (string) $args['style'] : 'default',
			'label_placement'       => isset( $args['label_placement'] ) && in_array( $args['label_placement'], self::LABEL_PLACEMENTS, true ) ? (string) $args['label_placement'] : 'top',
			'instruction_placement' => isset( $args['instruction_placement'] ) && in_array( $args['instruction_placement'], self::INSTRUCTION_PLACEMENTS, true ) ? (string) $args['instruction_placement'] : 'label',
			'hide_on_screen'        => isset( $args['hide_on_screen'] ) && is_array( $args['hide_on_screen'] ) ? array_values( array_map( 'strval', $args['hide_on_screen'] ) ) : [],
			'active'                => isset( $args['active'] ) ? (bool) $args['active'] : true,
			'description'           => isset( $args['description'] ) ? (string) $args['description'] : '',
		];

		$saved = acf_update_field_group( $group_payload );
		if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
			return self::error( 'acf_write_failed', 'acf_update_field_group returned no post id — ACF rejected the payload.' );
		}

		$saved_group_id  = (int) $saved['ID'];
		$saved_group_key = isset( $saved['key'] ) ? (string) $saved['key'] : $group_key;
		$saved_field_keys = [];
		foreach ( $prepared_fields as $field ) {
			$field['parent'] = $saved_group_id;
			$written = self::save_field( $field );
			if ( is_array( $written ) && ! empty( $written['key'] ) ) {
				$saved_field_keys[] = (string) $written['key'];
			}
			if ( is_array( $written ) && ! empty( $written['ID'] ) ) {
				self::persist_nested_fields( $field, (int) $written['ID'] );
			}
		}

		try {
			$undo_meta = self::make_token( [
				'audit_id'     => 0,
				'tool_name'    => 'acf_create_field_group',
				'undo_payload' => [
					'group_key' => $saved_group_key,
					'summary'   => sprintf( 'Created field group "%s" (%s).', $title, $saved_group_key ),
				],
				'ttl_hours'    => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			] );
		} catch ( \Throwable $e ) {

			$undo_meta = null;
		}
		$undo_token = ( is_array( $undo_meta ) && isset( $undo_meta['token'] ) ) ? (string) $undo_meta['token'] : null;

		return [
			'isError'           => false, // audit:helper-bypass-safe -- same reply shape as Envelope::success
			'content'           => [ [
				'type' => 'text',
				'text' => sprintf(
					'acf_create_field_group: created group "%s" (group %s, id %d, %d fields). undo_token: %s.',
					$title,
					$saved_group_key,
					$saved_group_id,
					count( $saved_field_keys ),
					$undo_token ?: 'NOT_ISSUED'
				),
			] ],
			'structuredContent' => [
				'group_id'        => $saved_group_id,
				'group_key'       => $saved_group_key,
				'title'           => $title,
				'field_count'     => count( $saved_field_keys ),
				'field_keys'      => $saved_field_keys,
				'undo_token'      => $undo_token ?: 'NOT_ISSUED',
				'undo_expires_at' => is_array( $undo_meta ) ? ( $undo_meta['expires_at'] ?? null ) : null,
				'undo_ttl_hours'  => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			],

			'undo'              => is_array( $undo_meta ) ? $undo_meta : null,
		];
	}

	private static function handle_clone_field_group( array $args ) {
		if ( ! self::has_admin_caps() ) {
			return self::error( 'insufficient_caps', 'manage_options capability required.' );
		}
		if ( ! self::plugin_active() ) {
			return self::error( 'acf_inactive', 'Advanced Custom Fields 6.0+ is not installed or active on this site.' );
		}

		$source_key = isset( $args['source_group_key'] ) ? (string) $args['source_group_key'] : '';
		if ( $source_key === '' ) {
			return self::error( 'invalid_source_group', 'source_group_key is required.' );
		}

		$new_title = isset( $args['new_title'] ) && is_string( $args['new_title'] ) ? trim( $args['new_title'] ) : '';
		if ( $new_title === '' ) {
			return self::error( 'invalid_new_title', 'new_title is required and must be a non-empty string.' );
		}

		$source = acf_get_field_group( $source_key );
		if ( ! is_array( $source ) || empty( $source ) ) {
			return self::error(
				'group_not_found',
				sprintf( 'Source field group "%s" not found.', $source_key ),
				[ 'source_group_key' => $source_key ]
			);
		}

		$source_fields = self::stored_fields( $source );

		$override_in = isset( $args['location_overrides'] ) && is_array( $args['location_overrides'] ) ? array_values( $args['location_overrides'] ) : null;
		$location_final = isset( $source['location'] ) && is_array( $source['location'] ) ? $source['location'] : [];
		if ( is_array( $override_in ) && ! empty( $override_in ) ) {
			$override_validation = self::validate_location_rule_groups( $override_in );
			if ( isset( $override_validation['error'] ) ) {
				return $override_validation['error'];
			}
			$location_final = $override_validation['location'];
		}

		$new_key = isset( $args['new_key'] ) && is_string( $args['new_key'] ) && $args['new_key'] !== ''
			? $args['new_key']
			: self::generate_group_key();
		if ( strpos( $new_key, 'group_' ) !== 0 ) {
			$new_key = 'group_' . $new_key;
		}
		if ( acf_get_field_group( $new_key ) ) {
			return self::error(
				'group_key_exists',
				sprintf( 'A field group with key "%s" already exists.', $new_key ),
				[ 'new_key' => $new_key ]
			);
		}

		$clone_payload = $source;
		unset( $clone_payload['ID'] );
		$clone_payload['key']      = $new_key;
		$clone_payload['title']    = $new_title;
		$clone_payload['location'] = $location_final;

		$key_map       = [];
		$cloned_fields = [];
		foreach ( $source_fields as $idx => $field ) {
			$cloned_fields[] = self::deep_regen_keys( (array) $field, $idx, $key_map );
		}
		foreach ( $cloned_fields as $i => $f ) {
			$cloned_fields[ $i ] = self::remap_conditional_logic( $f, $key_map );
		}
		$clone_payload['fields'] = $cloned_fields;

		$saved = acf_update_field_group( $clone_payload );
		if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
			return self::error( 'acf_write_failed', 'acf_update_field_group returned no post id for clone.' );
		}
		$saved_group_id  = (int) $saved['ID'];
		$saved_group_key = isset( $saved['key'] ) ? (string) $saved['key'] : $new_key;

		$new_field_keys = [];
		foreach ( $cloned_fields as $field ) {
			$field['parent'] = $saved_group_id;
			$written = self::save_field( $field );
			if ( is_array( $written ) && ! empty( $written['key'] ) ) {
				$new_field_keys[] = (string) $written['key'];
			}
			if ( is_array( $written ) && ! empty( $written['ID'] ) ) {
				self::persist_nested_fields( $field, (int) $written['ID'] );
			}
		}

		try {
			$undo_meta = self::make_token( [
				'audit_id'     => 0,
				'tool_name'    => 'acf_clone_field_group',
				'undo_payload' => [
					'clone_group_key' => $saved_group_key,
					'summary'         => sprintf( 'Cloned "%s" as "%s".', $source_key, $saved_group_key ),
				],
				'ttl_hours'    => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			] );
		} catch ( \Throwable $e ) {
			$undo_meta = null;
		}
		$undo_token = ( is_array( $undo_meta ) && isset( $undo_meta['token'] ) ) ? (string) $undo_meta['token'] : null;

		return [
			'isError'           => false, // audit:helper-bypass-safe -- same reply shape as Envelope::success
			'content'           => [ [
				'type' => 'text',
				'text' => sprintf(
					'acf_clone_field_group: cloned "%s" as "%s" (id=%d, %d fields). undo_token: %s.',
					$source_key,
					$saved_group_key,
					$saved_group_id,
					count( $new_field_keys ),
					$undo_token ?: 'NOT_ISSUED'
				),
			] ],
			'structuredContent' => [
				'new_group_id'    => $saved_group_id,
				'new_group_key'   => $saved_group_key,
				'source_group_key' => $source_key,
				'field_count'     => count( $new_field_keys ),
				'field_keys'      => $new_field_keys,
				'undo_token'      => $undo_token ?: 'NOT_ISSUED',
				'undo_expires_at' => is_array( $undo_meta ) ? ( $undo_meta['expires_at'] ?? null ) : null,
				'undo_ttl_hours'  => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			],
			'undo'              => is_array( $undo_meta ) ? $undo_meta : null,
		];
	}

	private static function handle_update_field_group( array $args ) {
		if ( ! self::has_admin_caps() ) {
			return self::error( 'insufficient_caps', 'manage_options capability required.' );
		}
		if ( ! self::plugin_active() ) {
			return self::error( 'acf_inactive', 'Advanced Custom Fields 6.0+ is not installed or active on this site.' );
		}

		$group_key = isset( $args['group_key'] ) ? (string) $args['group_key'] : '';
		if ( $group_key === '' ) {
			return self::error( 'invalid_group', 'group_key is required.' );
		}

		$existing = acf_get_field_group( $group_key );
		if ( ! is_array( $existing ) || empty( $existing ) ) {
			return self::error(
				'group_not_found',
				sprintf( 'Field group "%s" not found.', $group_key ),
				[ 'group_key' => $group_key ]
			);
		}

		$previous_meta = $existing;
		unset( $previous_meta['fields'] );

		$updated = $existing;
		unset( $updated['fields'] );
		$changed = [];

		if ( array_key_exists( 'title', $args ) && is_string( $args['title'] ) ) {
			$new_title = trim( $args['title'] );
			if ( $new_title === '' ) {
				return self::error( 'invalid_title', 'title must be a non-empty string when provided.' );
			}
			$updated['title'] = $new_title;
			$changed[] = 'title';
		}
		if ( array_key_exists( 'location', $args ) && is_array( $args['location'] ) ) {
			$location_validation = self::validate_location_rule_groups( array_values( $args['location'] ) );
			if ( isset( $location_validation['error'] ) ) {
				return $location_validation['error'];
			}
			$updated['location'] = $location_validation['location'];
			$changed[] = 'location';
		}
		foreach ( [ 'position', 'style', 'label_placement', 'instruction_placement' ] as $enum_key ) {
			if ( ! array_key_exists( $enum_key, $args ) ) {
				continue;
			}
			$allowed = self::allowed_enum_for( $enum_key );
			if ( ! in_array( $args[ $enum_key ], $allowed, true ) ) {
				return self::error(
					'invalid_' . $enum_key,
					sprintf( '%s must be one of: %s.', $enum_key, implode( ', ', $allowed ) ),
					[ 'received' => $args[ $enum_key ], 'allowed' => $allowed ]
				);
			}
			$updated[ $enum_key ] = (string) $args[ $enum_key ];
			$changed[] = $enum_key;
		}
		if ( array_key_exists( 'hide_on_screen', $args ) && is_array( $args['hide_on_screen'] ) ) {
			$updated['hide_on_screen'] = array_values( array_map( 'strval', $args['hide_on_screen'] ) );
			$changed[] = 'hide_on_screen';
		}
		if ( array_key_exists( 'active', $args ) ) {
			$updated['active'] = (bool) $args['active'];
			$changed[] = 'active';
		}
		if ( array_key_exists( 'description', $args ) && is_string( $args['description'] ) ) {
			$updated['description'] = (string) $args['description'];
			$changed[] = 'description';
		}
		if ( array_key_exists( 'menu_order', $args ) ) {
			$updated['menu_order'] = (int) $args['menu_order'];
			$changed[] = 'menu_order';
		}

		if ( empty( $changed ) ) {
			return self::error( 'nothing_to_update', 'update payload contains no supported meta keys.' );
		}

		$saved = acf_update_field_group( $updated );
		if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
			return self::error( 'acf_write_failed', 'acf_update_field_group returned no post id.' );
		}

		try {
			$undo_meta = self::make_token( [
				'audit_id'     => 0,
				'tool_name'    => 'acf_update_field_group',
				'undo_payload' => [
					'group_key'        => (string) $existing['key'],
					'previous_meta'    => $previous_meta,
					'summary'          => sprintf( 'Updated %d meta key(s) on "%s".', count( $changed ), (string) $existing['key'] ),
				],
				'ttl_hours'    => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			] );
		} catch ( \Throwable $e ) {
			$undo_meta = null;
		}
		$undo_token = ( is_array( $undo_meta ) && isset( $undo_meta['token'] ) ) ? (string) $undo_meta['token'] : null;

		return [
			'isError'           => false, // audit:helper-bypass-safe -- same reply shape as Envelope::success
			'content'           => [ [
				'type' => 'text',
				'text' => sprintf(
					'acf_update_field_group: updated "%s" — changed %s. undo_token: %s.',
					(string) $existing['key'],
					implode( ', ', $changed ),
					$undo_token ?: 'NOT_ISSUED'
				),
			] ],
			'structuredContent' => [
				'group_id'        => (int) $saved['ID'],
				'group_key'       => (string) ( $saved['key'] ?? $existing['key'] ),
				'changed_keys'    => $changed,
				'undo_token'      => $undo_token ?: 'NOT_ISSUED',
				'undo_expires_at' => is_array( $undo_meta ) ? ( $undo_meta['expires_at'] ?? null ) : null,
				'undo_ttl_hours'  => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			],
			'undo'              => is_array( $undo_meta ) ? $undo_meta : null,
		];
	}

	private static function handle_delete_field_group( array $args ) {
		if ( ! self::has_admin_caps() ) {
			return self::error( 'insufficient_caps', 'manage_options capability required.' );
		}
		if ( ! self::plugin_active() ) {
			return self::error( 'acf_inactive', 'Advanced Custom Fields 6.0+ is not installed or active on this site.' );
		}

		$group_key = isset( $args['group_key'] ) ? (string) $args['group_key'] : '';
		if ( $group_key === '' ) {
			return self::error( 'invalid_group', 'group_key is required.' );
		}

		$existing = acf_get_field_group( $group_key );
		if ( ! is_array( $existing ) || empty( $existing ) || empty( $existing['ID'] ) ) {
			return self::error(
				'group_not_found',
				sprintf( 'Field group "%s" not found.', $group_key ),
				[ 'group_key' => $group_key ]
			);
		}

		$force    = ! empty( $args['force'] );
		$post_id  = (int) $existing['ID'];
		$fields   = self::with_stored_fields( static function () use ( $existing ) {
			return self::expand_field_tree( self::stored_fields( $existing ) );
		} );

		if ( $force ) {

			$group_snapshot  = $existing;
			unset( $group_snapshot['ID'] );
			unset( $group_snapshot['fields'] );

			$fields_snapshot = [];
			foreach ( $fields as $field ) {
				$field_arr = (array) $field;
				unset( $field_arr['ID'], $field_arr['parent'] );
				$fields_snapshot[] = $field_arr;
			}

			$deleted = self::delete_stored_group( $post_id );
			if ( ! $deleted ) {
				return self::error(
					'acf_delete_failed',
					sprintf( 'Delete of ACF field group id %d failed.', $post_id ),
					[ 'group_key' => $group_key, 'post_id' => $post_id ]
				);
			}

			$undo_payload = [
				'mode'             => 'force',
				'group_key'        => (string) $existing['key'],
				'group_snapshot'   => $group_snapshot,
				'fields_snapshot'  => $fields_snapshot,
				'summary'          => sprintf( 'Permanently deleted "%s" (%d fields).', (string) $existing['key'], count( $fields_snapshot ) ),
			];
			$summary_text = sprintf(
				'acf_delete_field_group: permanently deleted "%s" (%d fields). undo_token: %%s.',
				(string) $existing['key'],
				count( $fields_snapshot )
			);
			$was_trashed = false;
		} else {

			$trashed = self::with_stored_fields( static function () use ( $post_id ) {
				return function_exists( 'acf_trash_field_group' ) ? acf_trash_field_group( $post_id ) : wp_trash_post( $post_id );
			} );
			if ( ! $trashed ) {
				return self::error(
					'acf_trash_failed',
					sprintf( 'Trash of ACF field group id %d failed.', $post_id ),
					[ 'group_key' => $group_key, 'post_id' => $post_id ]
				);
			}
			$undo_payload = [
				'mode'         => 'trash',
				'group_key'    => (string) $existing['key'],
				'post_id'      => $post_id,
				'was_trashed'  => true,
				'summary'      => sprintf( 'Trashed "%s" (recoverable).', (string) $existing['key'] ),
			];
			$summary_text = sprintf(
				'acf_delete_field_group: trashed "%s" (recoverable). undo_token: %%s.',
				(string) $existing['key']
			);
			$was_trashed = true;
		}

		try {
			$undo_meta = self::make_token( [
				'audit_id'     => 0,
				'tool_name'    => 'acf_delete_field_group',
				'undo_payload' => $undo_payload,
				'ttl_hours'    => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			] );
		} catch ( \Throwable $e ) {
			$undo_meta = null;
		}
		$undo_token = ( is_array( $undo_meta ) && isset( $undo_meta['token'] ) ) ? (string) $undo_meta['token'] : null;

		return [
			'isError'           => false, // audit:helper-bypass-safe -- same reply shape as Envelope::success
			'content'           => [ [
				'type' => 'text',
				'text' => sprintf( $summary_text, $undo_token ?: 'NOT_ISSUED' ),
			] ],
			'structuredContent' => [
				'group_key'                 => (string) $existing['key'],
				'deleted'                   => true,
				'was_trashed'               => $was_trashed,
				'field_count_before_delete' => count( $fields ),
				'undo_token'                => $undo_token ?: 'NOT_ISSUED',
				'undo_expires_at'           => is_array( $undo_meta ) ? ( $undo_meta['expires_at'] ?? null ) : null,
				'undo_ttl_hours'            => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			],
			'undo'              => is_array( $undo_meta ) ? $undo_meta : null,
		];
	}

	private static function handle_import_field_group_json( array $args ) {
		if ( ! self::has_admin_caps() ) {
			return self::error( 'insufficient_caps', 'manage_options capability required.' );
		}
		if ( ! self::plugin_active() ) {
			return self::error( 'acf_inactive', 'Advanced Custom Fields 6.0+ is not installed or active on this site.' );
		}

		if ( ! array_key_exists( 'json', $args ) ) {
			return self::error( 'invalid_json', 'json argument is required.' );
		}

		$raw = $args['json'];
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) || json_last_error() !== JSON_ERROR_NONE ) {
				return self::error(
					'invalid_json',
					sprintf( 'json string could not be parsed: %s.', json_last_error_msg() ),
					[ 'json_last_error' => json_last_error_msg() ]
				);
			}
			$raw = $decoded;
		}
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return self::error( 'invalid_json', 'json must decode to a group object or array of group objects.' );
		}

		if ( isset( $raw['key'] ) || isset( $raw['title'] ) ) {
			$groups_in = [ $raw ];
		} else {
			$groups_in = array_values( $raw );
		}

		$mode = isset( $args['mode'] ) && in_array( $args['mode'], self::IMPORT_MODES, true )
			? (string) $args['mode']
			: 'update_if_exists';
		$dry_run = ! empty( $args['dry_run'] );

		$required_keys = [ 'key', 'title', 'fields', 'location' ];
		foreach ( $groups_in as $g_idx => $group ) {
			if ( ! is_array( $group ) ) {
				return self::error(
					'invalid_json',
					sprintf( 'groups[%d] must be an object.', (int) $g_idx )
				);
			}

			$group             = self::strip_write_ids( $group );
			$groups_in[ $g_idx ] = $group;
			$missing = [];
			foreach ( $required_keys as $rk ) {
				if ( ! array_key_exists( $rk, $group ) ) {
					$missing[] = $rk;
				}
			}
			if ( ! empty( $missing ) ) {
				return self::error(
					'missing_required_key',
					sprintf( 'groups[%d] missing required key(s): %s.', (int) $g_idx, implode( ', ', $missing ) ),
					[ 'group_index' => (int) $g_idx, 'group_key' => (string) ( $group['key'] ?? '' ), 'missing' => $missing ]
				);
			}
			if ( ! is_array( $group['fields'] ) ) {
				return self::error(
					'invalid_json',
					sprintf( 'groups[%d].fields must be an array.', (int) $g_idx ),
					[ 'group_key' => (string) $group['key'] ]
				);
			}

			if ( function_exists( 'acf_get_field_type' ) ) {
				foreach ( $group['fields'] as $f_idx => $field ) {
					if ( ! is_array( $field ) ) {
						continue;
					}
					$type = isset( $field['type'] ) ? (string) $field['type'] : '';
					if ( $type !== '' && ! acf_get_field_type( $type ) ) {
						return self::error(
							'unknown_field_type',
							sprintf( 'groups[%d].fields[%d] has unregistered type "%s".', (int) $g_idx, (int) $f_idx, $type ),
							[ 'group_key' => (string) $group['key'], 'field_index' => (int) $f_idx, 'type' => $type ]
						);
					}
				}
			}

			if ( ! is_array( $group['location'] ) || empty( $group['location'] ) ) {
				return self::error(
					'invalid_location',
					sprintf( 'groups[%d].location must be a non-empty array of rule groups.', (int) $g_idx ),
					[ 'group_key' => (string) $group['key'] ]
				);
			}
			$loc_validation = self::validate_location_rule_groups( array_values( $group['location'] ) );
			if ( isset( $loc_validation['error'] ) ) {
				return $loc_validation['error'];
			}
		}

		$plan     = [];
		$snapshots = [];
		foreach ( $groups_in as $group ) {
			$group_key = (string) $group['key'];
			$existing  = function_exists( 'acf_get_field_group' ) ? acf_get_field_group( $group_key ) : null;
			$exists    = is_array( $existing ) && ! empty( $existing['ID'] );

			if ( $exists && $mode === 'create' ) {
				return self::error(
					'group_key_exists',
					sprintf( 'Group "%s" already exists and mode=create.', $group_key ),
					[ 'group_key' => $group_key ]
				);
			}
			if ( $exists && $mode === 'fail_if_exists' ) {
				$plan[] = [
					'group_key'   => $group_key,
					'group_id'    => (int) $existing['ID'],
					'action'      => 'skipped',
					'field_count' => is_array( $group['fields'] ) ? count( $group['fields'] ) : 0,
				];
				continue;
			}
			if ( $exists ) {

				$meta_snapshot = $existing;
				unset( $meta_snapshot['fields'] );
				$fields_snapshot = [];
				if ( function_exists( 'acf_get_fields' ) ) {
					foreach ( self::with_stored_fields( static function () use ( $existing ) { return self::expand_field_tree( self::stored_fields( $existing ) ); } ) as $f ) {
						$farr = (array) $f;
						unset( $farr['ID'], $farr['parent'] );
						$fields_snapshot[] = $farr;
					}
				}
				$snapshots[] = [
					'action'          => 'updated',
					'group_key'       => $group_key,
					'group_id'        => (int) $existing['ID'],
					'meta_snapshot'   => $meta_snapshot,
					'fields_snapshot' => $fields_snapshot,
				];
				$plan[] = [
					'group_key'   => $group_key,
					'group_id'    => (int) $existing['ID'],
					'action'      => 'updated',
					'field_count' => is_array( $group['fields'] ) ? count( $group['fields'] ) : 0,
				];
			} else {
				$snapshots[] = [
					'action'    => 'created',
					'group_key' => $group_key,
				];
				$plan[] = [
					'group_key'   => $group_key,
					'group_id'    => 0,
					'action'      => 'created',
					'field_count' => is_array( $group['fields'] ) ? count( $group['fields'] ) : 0,
				];
			}
		}

		if ( $dry_run ) {
			return [
				'isError'           => false, // audit:helper-bypass-safe -- same reply shape as Envelope::success
				'content'           => [ [
					'type' => 'text',
					'text' => sprintf(
						'acf_import_field_group_json: dry_run — %d group(s) planned. undo_token: null.',
						count( $plan )
					),
				] ],
				'structuredContent' => [
					'imported'        => $plan,
					'group_count'     => count( $plan ),
					'dry_run'         => true,
					'mode'            => $mode,
					'undo_token'      => null,
					'undo_expires_at' => null,
					'undo_ttl_hours'  => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
				],
				'undo'              => null,
			];
		}

		if ( ! function_exists( 'acf_import_internal_post_type' ) ) {
			return self::error( 'acf_api_missing', 'acf_import_internal_post_type is not available — ACF 6.1+ required for import bridge.' );
		}
		$imported_actions = [];
		foreach ( $groups_in as $idx => $group ) {
			$group_key = (string) $group['key'];
			$planned   = $plan[ $idx ];

			if ( $planned['action'] === 'skipped' ) {
				$imported_actions[] = $planned;
				continue;
			}

			$to_import = $group;
			if ( $planned['action'] === 'updated' && ! empty( $planned['group_id'] ) ) {
				$to_import['ID'] = (int) $planned['group_id'];
			}

			$to_import = acf_prepare_internal_post_type_for_import( $to_import, 'acf-field-group' );
			$result    = self::with_stored_fields( static function () use ( $to_import ) {
				return acf_import_internal_post_type( $to_import, 'acf-field-group' );
			} );

			if ( ! is_array( $result ) || empty( $result['ID'] ) ) {
				return self::error(
					'acf_import_failed',
					sprintf( 'acf_import_internal_post_type returned no ID for group "%s".', $group_key ),
					[ 'group_key' => $group_key ]
				);
			}

			$imported_actions[] = [
				'group_key'   => (string) ( $result['key'] ?? $group_key ),
				'group_id'    => (int) $result['ID'],
				'action'      => $planned['action'],
				'field_count' => $planned['field_count'],
			];
		}

		try {
			$undo_meta = self::make_token( [
				'audit_id'     => 0,
				'tool_name'    => 'acf_import_field_group_json',
				'undo_payload' => [
					'snapshots' => $snapshots,
					'mode'      => $mode,
					'summary'   => sprintf(
						'Imported %d group(s) via JSON bridge.',
						count( $imported_actions )
					),
				],
				'ttl_hours'    => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			] );
		} catch ( \Throwable $e ) {
			$undo_meta = null;
		}
		$undo_token = ( is_array( $undo_meta ) && isset( $undo_meta['token'] ) ) ? (string) $undo_meta['token'] : null;

		return [
			'isError'           => false, // audit:helper-bypass-safe -- same reply shape as Envelope::success
			'content'           => [ [
				'type' => 'text',
				'text' => sprintf(
					'acf_import_field_group_json: imported %d group(s). undo_token: %s.',
					count( $imported_actions ),
					$undo_token ?: 'NOT_ISSUED'
				),
			] ],
			'structuredContent' => [
				'imported'        => $imported_actions,
				'group_count'     => count( $imported_actions ),
				'dry_run'         => false,
				'mode'            => $mode,
				'undo_token'      => $undo_token ?: 'NOT_ISSUED',
				'undo_expires_at' => is_array( $undo_meta ) ? ( $undo_meta['expires_at'] ?? null ) : null,
				'undo_ttl_hours'  => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			],

			'undo'              => is_array( $undo_meta ) ? $undo_meta : null,
		];
	}

	private static function handle_export_field_group_json( array $args ) {
		if ( ! self::has_admin_caps() ) {
			return self::error( 'insufficient_caps', 'manage_options capability required.' );
		}
		if ( ! self::plugin_active() ) {
			return self::error( 'acf_inactive', 'Advanced Custom Fields 6.0+ is not installed or active on this site.' );
		}

		$keys_in = isset( $args['group_keys'] ) && is_array( $args['group_keys'] ) ? array_values( $args['group_keys'] ) : [];
		if ( empty( $keys_in ) ) {
			return self::error( 'invalid_group_keys', 'group_keys must be a non-empty array of field group keys.' );
		}
		$group_keys = [];
		foreach ( $keys_in as $k ) {
			if ( ! is_string( $k ) || $k === '' ) {
				return self::error( 'invalid_group_keys', 'group_keys must contain only non-empty strings.' );
			}
			$group_keys[] = $k;
		}

		$include_fields = array_key_exists( 'include_fields', $args ) ? (bool) $args['include_fields'] : true;

		$resolved = [];
		$missing  = [];
		foreach ( $group_keys as $key ) {
			$g = function_exists( 'acf_get_field_group' ) ? acf_get_field_group( $key ) : null;
			if ( ! is_array( $g ) || empty( $g ) ) {
				$missing[] = $key;
			} else {
				$resolved[ $key ] = $g;
			}
		}
		if ( ! empty( $missing ) ) {
			return self::error(
				'group_not_found',
				sprintf( '%d requested group key(s) not found.', count( $missing ) ),
				[ 'missing_keys' => $missing ]
			);
		}

		if ( ! function_exists( 'acf_prepare_internal_post_type_for_export' ) ) {
			return self::error( 'acf_api_missing', 'acf_prepare_internal_post_type_for_export is not available — ACF 6.1+ required for export bridge.' );
		}

		$exported = [];
		foreach ( $resolved as $key => $post ) {
			if ( $include_fields && function_exists( 'acf_get_fields' ) ) {
				$post['fields'] = self::stored_fields( $post );
			}
			$exported[] = acf_prepare_internal_post_type_for_export( $post, 'acf-field-group' );
		}

		return Envelope::success(
			sprintf(
				'acf_export_field_group_json: exported %d group(s) (%s).',
				count( $exported ),
				implode( ', ', $group_keys )
			),
			[
				'groups'      => $exported,
				'group_count' => count( $exported ),
				'format'      => 'acf_json',
			]
		);
	}

	private static function handle_add_field( array $args ) {
		if ( ! self::has_admin_caps() ) {
			return self::error( 'insufficient_caps', 'manage_options capability required.' );
		}
		if ( ! self::plugin_active() ) {
			return self::error( 'acf_inactive', 'Advanced Custom Fields 6.0+ is not installed or active on this site.' );
		}

		$group_key = isset( $args['group_key'] ) ? (string) $args['group_key'] : '';
		if ( $group_key === '' ) {
			return self::error( 'invalid_group', 'group_key is required.' );
		}
		$group = acf_get_field_group( $group_key );
		if ( ! is_array( $group ) || empty( $group['ID'] ) ) {
			return self::error(
				'group_not_found',
				sprintf( 'Field group "%s" not found.', $group_key ),
				[ 'group_key' => $group_key ]
			);
		}

		$field_in = isset( $args['field'] ) && is_array( $args['field'] ) ? $args['field'] : null;
		if ( $field_in === null ) {
			return self::error( 'invalid_field', 'field must be an object with at least a type key.' );
		}
		$type = isset( $field_in['type'] ) ? (string) $field_in['type'] : '';
		if ( $type === '' ) {
			return self::error( 'invalid_field', 'field.type is required.' );
		}
		if ( function_exists( 'acf_get_field_type' ) && ! acf_get_field_type( $type ) ) {
			return self::error(
				'unknown_field_type',
				sprintf( 'Field type "%s" not registered.', $type ),
				[ 'type' => $type ]
			);
		}

		$existing = self::stored_fields( $group );

		$position = isset( $args['position'] ) ? $args['position'] : 'append';
		$max_order = -1;
		foreach ( $existing as $f ) {
			if ( is_array( $f ) && isset( $f['menu_order'] ) ) {
				$max_order = max( $max_order, (int) $f['menu_order'] );
			}
		}
		$target_menu_order = $max_order + 1;
		$shift_from        = null;

		if ( is_string( $position ) && $position === 'append' ) {

		} elseif ( is_string( $position ) && $position === 'prepend' ) {
			$target_menu_order = 0;
			$shift_from        = 0;
		} elseif ( is_array( $position ) && isset( $position['after'] ) ) {
			$anchor_key = (string) $position['after'];
			$anchor     = null;
			foreach ( $existing as $f ) {
				if ( is_array( $f ) && ( (string) ( $f['key'] ?? '' ) === $anchor_key ) ) {
					$anchor = $f;
					break;
				}
			}
			if ( $anchor === null ) {
				return self::error(
					'anchor_field_not_found',
					sprintf( 'position.after references unknown field key "%s".', $anchor_key ),
					[ 'group_key' => $group_key, 'anchor_key' => $anchor_key ]
				);
			}
			$target_menu_order = (int) $anchor['menu_order'] + 1;
			$shift_from        = $target_menu_order;
		} elseif ( is_array( $position ) && isset( $position['before'] ) ) {
			$anchor_key = (string) $position['before'];
			$anchor     = null;
			foreach ( $existing as $f ) {
				if ( is_array( $f ) && ( (string) ( $f['key'] ?? '' ) === $anchor_key ) ) {
					$anchor = $f;
					break;
				}
			}
			if ( $anchor === null ) {
				return self::error(
					'anchor_field_not_found',
					sprintf( 'position.before references unknown field key "%s".', $anchor_key ),
					[ 'group_key' => $group_key, 'anchor_key' => $anchor_key ]
				);
			}
			$target_menu_order = (int) $anchor['menu_order'];
			$shift_from        = $target_menu_order;
		} elseif ( ! ( is_string( $position ) && $position === 'append' ) ) {
			return self::error(
				'invalid_position',
				'position must be "append", "prepend", {"after": key}, or {"before": key}.',
				[ 'position' => $position ]
			);
		}

		$prepared           = self::prepare_field_for_write( $field_in, $target_menu_order );
		$prepared['parent'] = (int) $group['ID'];

		$prepared['menu_order'] = $target_menu_order;

		if ( $shift_from !== null ) {
			foreach ( $existing as $f ) {
				if ( ! is_array( $f ) || empty( $f['ID'] ) ) {
					continue;
				}
				if ( (int) $f['menu_order'] >= $shift_from ) {
					$shifted = $f;
					$shifted['menu_order'] = (int) $f['menu_order'] + 1;
					self::save_field( $shifted );
				}
			}
		}

		$written = self::save_field( $prepared );
		if ( ! is_array( $written ) || empty( $written['key'] ) ) {
			return self::error(
				'acf_write_failed',
				'acf_update_field returned no key for the new field.',
				[ 'group_key' => $group_key ]
			);
		}
		$new_field_key = (string) $written['key'];
		$new_field_id  = (int) ( $written['ID'] ?? 0 );

		self::persist_nested_fields( $prepared, $new_field_id );

		try {
			$undo_meta = self::make_token( [
				'audit_id'     => 0,
				'tool_name'    => 'acf_add_field',
				'undo_payload' => [
					'field_key'       => $new_field_key,
					'parent_group_id' => (int) $group['ID'],
					'summary'         => sprintf( 'Added field "%s" to group "%s".', $new_field_key, $group_key ),
				],
				'ttl_hours'    => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			] );
		} catch ( \Throwable $e ) {
			$undo_meta = null;
		}
		$undo_token = ( is_array( $undo_meta ) && isset( $undo_meta['token'] ) ) ? (string) $undo_meta['token'] : null;

		return [
			'isError'           => false, // audit:helper-bypass-safe -- same reply shape as Envelope::success
			'content'           => [ [
				'type' => 'text',
				'text' => sprintf(
					'acf_add_field: added field "%s" to group "%s" at menu_order %d. undo_token: %s.',
					$new_field_key,
					$group_key,
					$target_menu_order,
					$undo_token ?: 'NOT_ISSUED'
				),
			] ],
			'structuredContent' => [
				'field_key'       => $new_field_key,
				'field_id'        => $new_field_id,
				'group_key'       => $group_key,
				'group_id'        => (int) $group['ID'],
				'menu_order'      => $target_menu_order,
				'undo_token'      => $undo_token ?: 'NOT_ISSUED',
				'undo_expires_at' => is_array( $undo_meta ) ? ( $undo_meta['expires_at'] ?? null ) : null,
				'undo_ttl_hours'  => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			],
			'undo'              => is_array( $undo_meta ) ? $undo_meta : null,
		];
	}

	private static function handle_update_field_definition( array $args ) {
		if ( ! self::has_admin_caps() ) {
			return self::error( 'insufficient_caps', 'manage_options capability required.' );
		}
		if ( ! self::plugin_active() ) {
			return self::error( 'acf_inactive', 'Advanced Custom Fields 6.0+ is not installed or active on this site.' );
		}

		$field_key = isset( $args['field_key'] ) ? (string) $args['field_key'] : '';
		if ( $field_key === '' ) {
			return self::error( 'invalid_field', 'field_key is required.' );
		}
		$updates = isset( $args['updates'] ) && is_array( $args['updates'] ) ? $args['updates'] : null;
		if ( $updates === null || empty( $updates ) ) {
			return self::error( 'invalid_updates', 'updates must be a non-empty object.' );
		}
		$current = acf_get_field( $field_key );
		if ( ! is_array( $current ) || empty( $current['ID'] ) ) {
			return self::error(
				'field_not_found',
				sprintf( 'Field "%s" not found.', $field_key ),
				[ 'field_key' => $field_key ]
			);
		}

		$current_type = (string) ( $current['type'] ?? '' );
		$type_change  = false;
		$requested_type = $current_type;
		if ( array_key_exists( 'type', $updates ) ) {
			$requested_type = (string) $updates['type'];
			if ( $requested_type !== '' && $requested_type !== $current_type ) {
				$type_change = true;
				if ( function_exists( 'acf_get_field_type' ) && ! acf_get_field_type( $requested_type ) ) {
					return self::error(
						'unknown_field_type',
						sprintf( 'Field type "%s" not registered.', $requested_type ),
						[ 'type' => $requested_type ]
					);
				}
				if ( empty( $args['allow_type_change'] ) ) {
					return self::error(
						'type_change_requires_confirmation',
						sprintf(
							'Field type change from "%s" to "%s" requires allow_type_change: true. Existing values may need manual migration.',
							$current_type,
							$requested_type
						),
						[
							'current_type'   => $current_type,
							'requested_type' => $requested_type,
						]
					);
				}
			}
		}

		$current_name = (string) ( $current['name'] ?? '' );
		$name_change  = false;
		if ( array_key_exists( 'name', $updates ) ) {
			$requested_name = (string) $updates['name'];
			if ( $requested_name !== '' && $requested_name !== $current_name ) {
				$name_change = true;
			}
		}

		$previous_field = $current;

		unset( $updates['ID'], $updates['key'], $updates['parent'], $updates['_valid'] );
		$layouts_with_fields = isset( $updates['layouts'] ) && is_array( $updates['layouts'] ) && array_filter( $updates['layouts'], static function ( $layout ) {
			return is_array( $layout ) && isset( $layout['sub_fields'] );
		} );
		if ( isset( $updates['sub_fields'] ) || $layouts_with_fields ) {
			return self::error( 'invalid_updates', 'Sub-fields cannot be changed through their parent field. Change a sub-field with its own field_key.' );
		}
		if ( empty( $updates ) ) {
			return self::error( 'invalid_updates', 'updates may not change ID, key or parent; nothing else was given.' );
		}
		$merged           = array_merge( $current, $updates );
		$merged['ID']     = (int) $current['ID'];
		$merged['key']    = (string) $current['key'];
		$merged['parent'] = $current['parent'] ?? 0;

		$written = self::save_field( $merged );
		if ( ! is_array( $written ) || empty( $written['key'] ) ) {
			return self::error(
				'acf_write_failed',
				'acf_update_field returned no key for the updated field.',
				[ 'field_key' => $field_key ]
			);
		}

		try {
			$undo_meta = self::make_token( [
				'audit_id'     => 0,
				'tool_name'    => 'acf_update_field_definition',
				'undo_payload' => [
					'field_key'          => $field_key,
					'previous_field'     => $previous_field,
					'summary'            => sprintf( 'Updated field "%s".', $field_key ),
				],
				'ttl_hours'    => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			] );
		} catch ( \Throwable $e ) {
			$undo_meta = null;
		}
		$undo_token = ( is_array( $undo_meta ) && isset( $undo_meta['token'] ) ) ? (string) $undo_meta['token'] : null;

		$sc = [
			'field_key'       => $field_key,
			'field_id'        => (int) $current['ID'],
			'updated_keys'    => array_keys( $updates ),
			'undo_token'      => $undo_token ?: 'NOT_ISSUED',
			'undo_expires_at' => is_array( $undo_meta ) ? ( $undo_meta['expires_at'] ?? null ) : null,
			'undo_ttl_hours'  => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
		];
		if ( $type_change ) {
			$sc['type_change_warning'] = 'Field type changed. Existing post_meta values may need manual migration.';
			$sc['previous_type'] = $current_type;
			$sc['new_type']      = $requested_type;
		}
		if ( $name_change ) {
			$sc['name_change_warning'] = 'Renaming field name breaks post_meta lookups on existing posts. Old values remain under the old meta_key; new values will write to the new key.';
			$sc['previous_name'] = $current_name;
			$sc['new_name']      = (string) $updates['name'];
		}

		return [
			'isError'           => false, // audit:helper-bypass-safe -- same reply shape as Envelope::success
			'content'           => [ [
				'type' => 'text',
				'text' => sprintf(
					'acf_update_field_definition: updated "%s" (%d key(s) changed). undo_token: %s.',
					$field_key,
					count( $updates ),
					$undo_token ?: 'NOT_ISSUED'
				),
			] ],
			'structuredContent' => $sc,
			'undo'              => is_array( $undo_meta ) ? $undo_meta : null,
		];
	}

	private static function handle_delete_field( array $args ) {
		if ( ! self::has_admin_caps() ) {
			return self::error( 'insufficient_caps', 'manage_options capability required.' );
		}
		if ( ! self::plugin_active() ) {
			return self::error( 'acf_inactive', 'Advanced Custom Fields 6.0+ is not installed or active on this site.' );
		}

		$field_key = isset( $args['field_key'] ) ? (string) $args['field_key'] : '';
		if ( $field_key === '' ) {
			return self::error( 'invalid_field', 'field_key is required.' );
		}
		$current = acf_get_field( $field_key );
		if ( ! is_array( $current ) || empty( $current['ID'] ) ) {
			return self::error(
				'field_not_found',
				sprintf( 'Field "%s" not found.', $field_key ),
				[ 'field_key' => $field_key ]
			);
		}

		$force        = ! empty( $args['force'] );
		$field_name   = (string) ( $current['name'] ?? '' );
		$field_id     = (int) $current['ID'];
		$parent_id    = (int) ( $current['parent'] ?? 0 );

		$field_snapshot = self::expand_field_tree( [ $current ] )[0];
		unset( $field_snapshot['ID'] );

		global $wpdb;
		$sub_field_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'acf-field' AND post_parent = %d",
			$field_id
		) );

		$meta_purged_count = 0;
		$meta_purge_capped = false;

		$owned_posts = static function ( $limit ) use ( $wpdb, $field_name, $field_key ) {
			return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT %d",
				'_' . $field_name,
				(string) $field_key,
				(int) $limit
			) ) );
		};
		if ( $force && $field_name !== '' && ! empty( $args['dry_run'] ) ) {
			$would = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				'_' . $field_name,
				(string) $field_key
			) );
			return Envelope::success( sprintf( 'acf_delete_field dry run: "%s" has saved values on %d post(s); force would remove them and the field. Nothing was changed.', $field_key, $would ), [ 'dry_run' => true, 'field_key' => $field_key, 'posts_with_values' => $would, 'sub_field_count' => $sub_field_count ] );
		}
		if ( $force && $field_name !== '' ) {
			$meta_cap = 100000;
			$batch    = 1000;
			while ( $meta_purged_count < $meta_cap ) {
				$ids = $owned_posts( $batch );
				if ( empty( $ids ) ) {
					break;
				}
				$in       = implode( ',', $ids );
				$affected = $wpdb->query( $wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND ( meta_key = %s OR ( meta_key = %s AND meta_value = %s ) )",
					$field_name,
					'_' . $field_name,
					(string) $field_key
				) );

				foreach ( $ids as $purged_post_id ) {
					wp_cache_delete( $purged_post_id, 'post_meta' );
				}
				if ( ! is_numeric( $affected ) || 0 === (int) $affected ) {
					break;
				}
				$meta_purged_count += (int) $affected;
			}
			$meta_purge_capped = $meta_purged_count >= $meta_cap;
		}

		$deleted = self::delete_stored_field( $field_id );
		if ( ! $deleted ) {
			return self::error(
				'acf_delete_failed',
				sprintf( 'acf_delete_field failed for field "%s" (id=%d).', $field_key, $field_id ),
				[ 'field_key' => $field_key, 'field_id' => $field_id ]
			);
		}

		try {
			$undo_meta = self::make_token( [
				'audit_id'     => 0,
				'tool_name'    => 'acf_delete_field',
				'undo_payload' => [
					'field_snapshot'    => $field_snapshot,
					'parent_group_id'   => $parent_id,
					'force_mode'        => $force,
					'meta_purged_count' => $meta_purged_count,
					'summary'           => sprintf(
						'Deleted field "%s" (%s).',
						$field_key,
						$force ? 'force + meta purge' : 'definition only'
					),
				],
				'ttl_hours'    => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			] );
		} catch ( \Throwable $e ) {
			$undo_meta = null;
		}
		$undo_token = ( is_array( $undo_meta ) && isset( $undo_meta['token'] ) ) ? (string) $undo_meta['token'] : null;

		$sc = [
			'field_key'         => $field_key,
			'deleted'           => true,
			'force_mode'        => $force,
			'purged_meta_count' => $meta_purged_count,
			'undo_token'        => $undo_token ?: 'NOT_ISSUED',
			'undo_expires_at'   => is_array( $undo_meta ) ? ( $undo_meta['expires_at'] ?? null ) : null,
			'undo_ttl_hours'    => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
		];
		if ( $meta_purge_capped ) {
			$sc['meta_purge_capped'] = true;
			$sc['meta_purge_note']   = 'Purge hit the 100,000-row safety cap; call the tool again with force: true to continue clearing remaining rows.';
		}
		if ( $sub_field_count > 0 ) {
			$sc['sub_fields_deleted'] = $sub_field_count;
			$sc['sub_fields_note']    = 'The field had sub-fields; ACF deleted them with it. Undo puts the field back with its sub-fields.';
		}

		return [
			'isError'           => false, // audit:helper-bypass-safe -- same reply shape as Envelope::success
			'content'           => [ [
				'type' => 'text',
				'text' => sprintf(
					'acf_delete_field: deleted "%s"%s. undo_token: %s.',
					$field_key,
					$force ? sprintf( ' (force, %d meta rows purged)', $meta_purged_count ) : '',
					$undo_token ?: 'NOT_ISSUED'
				),
			] ],
			'structuredContent' => $sc,
			'undo'              => is_array( $undo_meta ) ? $undo_meta : null,
		];
	}

	private static function handle_reorder_fields( array $args ) {
		if ( ! self::has_admin_caps() ) {
			return self::error( 'insufficient_caps', 'manage_options capability required.' );
		}
		if ( ! self::plugin_active() ) {
			return self::error( 'acf_inactive', 'Advanced Custom Fields 6.0+ is not installed or active on this site.' );
		}

		$group_key = isset( $args['group_key'] ) ? (string) $args['group_key'] : '';
		if ( $group_key === '' ) {
			return self::error( 'invalid_group', 'group_key is required.' );
		}
		$group = acf_get_field_group( $group_key );
		if ( ! is_array( $group ) || empty( $group['ID'] ) ) {
			return self::error(
				'group_not_found',
				sprintf( 'Field group "%s" not found.', $group_key ),
				[ 'group_key' => $group_key ]
			);
		}

		$requested = isset( $args['field_keys_ordered'] ) && is_array( $args['field_keys_ordered'] )
			? array_values( array_map( 'strval', $args['field_keys_ordered'] ) )
			: [];
		if ( empty( $requested ) ) {
			return self::error( 'invalid_field_keys_ordered', 'field_keys_ordered must be a non-empty array of field keys.' );
		}

		$existing = self::stored_fields( $group );
		$current_keys = [];
		$current_by_key = [];
		foreach ( $existing as $f ) {
			if ( is_array( $f ) && isset( $f['key'] ) ) {
				$k = (string) $f['key'];
				$current_keys[]     = $k;
				$current_by_key[ $k ] = $f;
			}
		}

		$missing_keys = array_values( array_diff( $current_keys, $requested ) );
		$extra_keys   = array_values( array_diff( $requested, $current_keys ) );
		if ( ! empty( $missing_keys ) || ! empty( $extra_keys ) ) {
			return self::error(
				'partial_reorder_rejected',
				'field_keys_ordered must cover ALL fields in the group and only those fields.',
				[
					'group_key'    => $group_key,
					'missing_keys' => $missing_keys,
					'extra_keys'   => $extra_keys,
					'expected_count' => count( $current_keys ),
					'provided_count' => count( $requested ),
				]
			);
		}

		$previous_order = [];
		foreach ( $current_by_key as $k => $f ) {
			$previous_order[] = [
				'field_key'  => $k,
				'menu_order' => (int) ( $f['menu_order'] ?? 0 ),
			];
		}

		$new_order = [];
		foreach ( $requested as $idx => $k ) {
			$f = $current_by_key[ $k ];
			$f['menu_order'] = (int) $idx;
			$written = self::save_field( $f );
			$new_order[] = [
				'field_key'  => $k,
				'menu_order' => (int) $idx,
			];
		}

		try {
			$undo_meta = self::make_token( [
				'audit_id'     => 0,
				'tool_name'    => 'acf_reorder_fields',
				'undo_payload' => [
					'group_key'      => $group_key,
					'previous_order' => $previous_order,
					'summary'        => sprintf( 'Reordered %d field(s) in group "%s".', count( $requested ), $group_key ),
				],
				'ttl_hours'    => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			] );
		} catch ( \Throwable $e ) {
			$undo_meta = null;
		}
		$undo_token = ( is_array( $undo_meta ) && isset( $undo_meta['token'] ) ) ? (string) $undo_meta['token'] : null;

		return [
			'isError'           => false, // audit:helper-bypass-safe -- same reply shape as Envelope::success
			'content'           => [ [
				'type' => 'text',
				'text' => sprintf(
					'acf_reorder_fields: reordered %d field(s) in group "%s". undo_token: %s.',
					count( $requested ),
					$group_key,
					$undo_token ?: 'NOT_ISSUED'
				),
			] ],
			'structuredContent' => [
				'group_key'       => $group_key,
				'field_count'     => count( $requested ),
				'previous_order'  => $previous_order,
				'new_order'       => $new_order,
				'undo_token'      => $undo_token ?: 'NOT_ISSUED',
				'undo_expires_at' => is_array( $undo_meta ) ? ( $undo_meta['expires_at'] ?? null ) : null,
				'undo_ttl_hours'  => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			],
			'undo'              => is_array( $undo_meta ) ? $undo_meta : null,
		];
	}

	private static function handle_get_field_group_config( array $args ) {
		if ( ! self::has_admin_caps() ) {
			return self::error( 'insufficient_caps', 'manage_options capability required.' );
		}
		if ( ! self::plugin_active() ) {
			return self::error( 'acf_inactive', 'Advanced Custom Fields 6.0+ is not installed or active on this site.' );
		}

		$group_key = isset( $args['group_key'] ) ? (string) $args['group_key'] : '';
		if ( $group_key === '' ) {
			return self::error( 'invalid_group', 'group_key is required.' );
		}

		$group = function_exists( 'acf_get_field_group' ) ? acf_get_field_group( $group_key ) : null;
		if ( ! is_array( $group ) || empty( $group ) ) {
			return self::error(
				'group_not_found',
				sprintf( 'Field group "%s" not found.', $group_key ),
				[ 'group_key' => $group_key ]
			);
		}

		if ( ! function_exists( 'acf_prepare_internal_post_type_for_export' ) ) {
			return self::error( 'acf_api_missing', 'acf_prepare_internal_post_type_for_export is not available — ACF 6.1+ required for introspection.' );
		}

		$include_fields     = array_key_exists( 'include_fields', $args ) ? (bool) $args['include_fields'] : true;
		$include_sub_fields = array_key_exists( 'include_sub_fields', $args ) ? (bool) $args['include_sub_fields'] : true;

		$field_count = 0;
		if ( $include_fields && function_exists( 'acf_get_fields' ) ) {
			$fields      = self::stored_fields( $group );
			$field_count = count( $fields );
			if ( $include_sub_fields ) {
				$group['fields'] = self::expand_field_tree( $fields );
			} else {
				$group['fields'] = self::strip_nested_containers( $fields );
			}
		}

		$exported = acf_prepare_internal_post_type_for_export( $group, 'acf-field-group' );

		if ( $include_fields && isset( $group['fields'] ) && ! isset( $exported['fields'] ) ) {
			$exported['fields'] = $group['fields'];
		}

		return Envelope::success(
			sprintf(
				'acf_get_field_group_config: %s (%d field%s).',
				$group_key,
				$field_count,
				$field_count === 1 ? '' : 's'
			),
			[
				'group'              => $exported,
				'group_key'          => $group_key,
				'field_count'        => $field_count,
				'include_fields'     => $include_fields,
				'include_sub_fields' => $include_sub_fields,
			]
		);
	}

	private static function handle_list_supported_field_types( array $args ) {
		if ( ! self::has_admin_caps() ) {
			return self::error( 'insufficient_caps', 'manage_options capability required.' );
		}
		if ( ! self::plugin_active() ) {
			return self::error( 'acf_inactive', 'Advanced Custom Fields 6.0+ is not installed or active on this site.' );
		}

		$transient_key = 'royal_mcp_acf_field_types_v1';
		$cached        = get_transient( $transient_key );
		if ( is_array( $cached ) && isset( $cached['field_types'] ) ) {
			$cached['cached']             = true;
			$envelope                     = self::build_field_types_envelope( $cached );
			$envelope['structuredContent']['cached'] = true;
			return $envelope;
		}

		if ( ! function_exists( 'acf_get_field_types' ) ) {
			return self::error( 'acf_api_missing', 'acf_get_field_types is not available — ACF core function missing.' );
		}

		$types_raw = acf_get_field_types();
		$listed    = [];
		$pro_only  = 0;
		foreach ( (array) $types_raw as $name => $type ) {
			if ( ! is_object( $type ) ) {
				continue;
			}

			$entry = [
				'name'        => isset( $type->name ) ? (string) $type->name : (string) $name,
				'label'       => isset( $type->label ) ? (string) $type->label : '',
				'category'    => isset( $type->category ) ? (string) $type->category : 'basic',
				'description' => isset( $type->description ) ? (string) $type->description : '',
				'pro'         => isset( $type->pro ) ? (bool) $type->pro : false,
			];
			if ( isset( $type->defaults ) && is_array( $type->defaults ) ) {
				$entry['args_schema'] = self::derive_args_schema( $type->defaults );
			} else {
				$entry['args_schema'] = 'undocumented';
			}
			if ( ! empty( $entry['pro'] ) ) {
				$pro_only++;
			}
			$listed[] = $entry;
		}

		$payload = [
			'field_types'      => $listed,
			'total_count'      => count( $listed ),
			'pro_only_count'   => $pro_only,
			'acf_pro_active'   => function_exists( 'acf_is_pro' ) && acf_is_pro(),
			'acf_version'      => defined( 'ACF_VERSION' ) ? (string) ACF_VERSION : '',
			'cached'           => false,
		];
		set_transient( $transient_key, $payload, 300 ); // audit:transient-ttl-only -- five-minute cache of the field type list

		return self::build_field_types_envelope( $payload );
	}

	private static function handle_register_post_type( array $args ) {
		if ( ! self::has_admin_caps() ) {
			return self::error( 'insufficient_caps', 'manage_options capability required.' );
		}
		if ( ! self::plugin_active() ) {
			return self::error( 'acf_inactive', 'Advanced Custom Fields 6.0+ is not installed or active on this site.' );
		}
		if ( ! self::plugin_61_plus() ) {
			return self::error(
				'requires_acf_6_1_plus',
				'Post types need ACF 6.1 or later (the free plugin or ACF Pro); nothing was registered.',
				[ 'current_version' => defined( 'ACF_VERSION' ) ? (string) ACF_VERSION : '' ]
			);
		}

		foreach ( [ 'post_type_key', 'singular_label', 'plural_label' ] as $required ) {
			if ( ! isset( $args[ $required ] ) || ! is_string( $args[ $required ] ) || trim( (string) $args[ $required ] ) === '' ) {
				return self::error(
					'missing_required_arg',
					sprintf( 'Required arg "%s" is missing or empty.', $required ),
					[ 'missing' => $required ]
				);
			}
		}

		$raw_key = strtolower( trim( (string) $args['post_type_key'] ) );

		$post_type_key = preg_replace( '/[^a-z0-9_-]/', '', $raw_key );
		if ( ! is_string( $post_type_key ) || $post_type_key === '' ) {
			return self::error(
				'invalid_post_type_key',
				'post_type_key sanitized to empty — use lowercase alphanumeric with hyphens/underscores.',
				[ 'received' => (string) $args['post_type_key'] ]
			);
		}
		if ( strlen( $post_type_key ) > 20 ) {
			return self::error(
				'invalid_post_type_key',
				'post_type_key exceeds WP core 20-char limit.',
				[ 'length' => strlen( $post_type_key ), 'max' => 20 ]
			);
		}

		$reserved = self::reserved_post_type_keys();
		if ( in_array( $post_type_key, $reserved, true ) ) {
			return self::error(
				'reserved_post_type_key',
				sprintf( 'post_type_key "%s" collides with a reserved WP core post type.', $post_type_key ),
				[ 'received' => $post_type_key, 'reserved' => $reserved ]
			);
		}

		// The same collisions ACF's own screen refuses: a post type that already exists, or one ACF already stores.
		if ( post_type_exists( $post_type_key ) ) {
			return self::error(
				'post_type_exists',
				sprintf( 'A post type "%s" is already registered on this site (by WordPress, a plugin, the theme or ACF). Nothing was registered.', $post_type_key ),
				[ 'post_type_key' => $post_type_key ]
			);
		}
		if ( function_exists( 'acf_get_internal_post_type_posts' ) ) {
			foreach ( (array) acf_get_internal_post_type_posts( 'acf-post-type' ) as $stored ) {
				if ( is_array( $stored ) && isset( $stored['post_type'] ) && $post_type_key === (string) $stored['post_type'] ) {
					return self::error(
						'post_type_exists',
						sprintf( 'ACF already has a post type "%s" (it may be inactive). Edit it in ACF instead. Nothing was registered.', $post_type_key ),
						[ 'post_type_key' => $post_type_key, 'acf_post_id' => (int) ( $stored['ID'] ?? 0 ) ]
					);
				}
			}
		}

		$supports_in = isset( $args['supports'] ) && is_array( $args['supports'] )
			? array_values( array_map( 'strval', $args['supports'] ) )
			: [ 'title', 'editor', 'thumbnail' ];
		$supports_allowed = self::allowed_post_type_supports();
		$invalid_supports = [];
		foreach ( $supports_in as $s ) {
			if ( ! in_array( $s, $supports_allowed, true ) ) {
				$invalid_supports[] = $s;
			}
		}
		if ( ! empty( $invalid_supports ) ) {
			return self::error(
				'invalid_supports_value',
				sprintf( '%d unknown "supports" value(s).', count( $invalid_supports ) ),
				[ 'invalid' => $invalid_supports, 'allowed' => $supports_allowed ]
			);
		}

		if ( ! function_exists( 'acf_update_internal_post_type' ) ) {
			return self::error(
				'acf_pro_api_missing',
				'acf_update_internal_post_type function is not available — ACF is loaded partially.'
			);
		}

		$singular = trim( (string) $args['singular_label'] );
		$plural   = trim( (string) $args['plural_label'] );
		$slug     = isset( $args['slug'] ) && is_string( $args['slug'] ) && $args['slug'] !== ''
			? sanitize_title( (string) $args['slug'] )
			: $post_type_key;
		foreach ( get_post_types( [], 'objects' ) as $existing_type ) {
			$existing_slug = is_array( $existing_type->rewrite ?? null ) ? (string) ( $existing_type->rewrite['slug'] ?? '' ) : '';
			if ( '' !== $existing_slug && trim( $existing_slug, '/' ) === $slug ) {
				return self::error(
					'slug_in_use',
					sprintf( 'The address slug "%s" is already used by the post type "%s". Pass another slug. Nothing was registered.', $slug, $existing_type->name ),
					[ 'slug' => $slug, 'used_by' => $existing_type->name ]
				);
			}
		}

		$payload = [
			'post_type'      => $post_type_key,
			'labels'         => [
				'name'          => $plural,
				'singular_name' => $singular,
			],
			'description'    => isset( $args['description'] ) ? (string) $args['description'] : '',
			'public'         => array_key_exists( 'public', $args ) ? (bool) $args['public'] : true,
			'show_in_rest'   => array_key_exists( 'show_in_rest', $args ) ? (bool) $args['show_in_rest'] : true,
			'has_archive'    => array_key_exists( 'has_archive', $args ) ? (bool) $args['has_archive'] : true,
			'hierarchical'   => array_key_exists( 'hierarchical', $args ) ? (bool) $args['hierarchical'] : false,
			'capability_type' => isset( $args['capability_type'] ) && is_string( $args['capability_type'] ) && $args['capability_type'] !== ''
				? (string) $args['capability_type']
				: 'post',
			'supports'       => $supports_in,
			'rewrite'        => [ 'slug' => $slug, 'with_front' => true ],
			'active'         => 1,
		];
		if ( array_key_exists( 'menu_position', $args ) ) {
			$payload['menu_position'] = (int) $args['menu_position'];
		}
		if ( isset( $args['menu_icon'] ) && is_string( $args['menu_icon'] ) && $args['menu_icon'] !== '' ) {
			$payload['menu_icon'] = [ 'type' => 'dashicons', 'value' => (string) $args['menu_icon'] ];
		}
		if ( isset( $args['taxonomies'] ) && is_array( $args['taxonomies'] ) ) {
			$payload['taxonomies'] = array_values( array_map( 'strval', $args['taxonomies'] ) );
		}

		$saved = acf_update_internal_post_type( $payload, 'acf-post-type' );
		if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
			return self::error(
				'acf_write_failed',
				'acf_update_internal_post_type returned no post id — ACF rejected the CPT payload.',
				[ 'post_type_key' => $post_type_key ]
			);
		}

		delete_option( 'royal_mcp_rewrite_version' );
		// ACF registers stored post types on init, so the type is live from the next request, not this one.
		$live_now = post_type_exists( $post_type_key );

		try {
			$undo_meta = self::make_token( [
				'audit_id'     => 0,
				'tool_name'    => 'acf_register_post_type',
				'undo_payload' => [
					'post_type_key' => $post_type_key,
					'acf_post_id'   => (int) $saved['ID'],
					'summary'       => sprintf( 'Registered CPT "%s" (%s).', $singular, $post_type_key ),
				],
				'ttl_hours'    => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			] );
		} catch ( \Throwable $e ) {
			$undo_meta = null;
		}
		$undo_token = ( is_array( $undo_meta ) && isset( $undo_meta['token'] ) ) ? (string) $undo_meta['token'] : null;

		return [
			'isError'           => false, // audit:helper-bypass-safe -- same reply shape as Envelope::success
			'content'           => [ [
				'type' => 'text',
				'text' => sprintf(
					'acf_register_post_type: saved post type "%s" (%s) in ACF%s. undo_token: %s.',
					$singular,
					$post_type_key,
					$live_now ? ', and it is registered now' : '; ACF registers it with WordPress from the next page load',
					$undo_token ?: 'NOT_ISSUED'
				),
			] ],
			'structuredContent' => [
				'post_type_key'          => $post_type_key,
				'singular_label'         => $singular,
				'plural_label'           => $plural,
				'slug'                   => $slug,
				'acf_post_id'            => (int) $saved['ID'],
				'registration_added_to'  => 'acf_post_type_ui',
				'saved_in_acf'           => true,
				'active'                 => ! empty( $saved['active'] ),
				'registered_now'         => $live_now,
				'admin_url'              => admin_url( 'edit.php?post_type=' . $post_type_key ),
				'undo_token'             => $undo_token ?: 'NOT_ISSUED',
				'undo_expires_at'        => is_array( $undo_meta ) ? ( $undo_meta['expires_at'] ?? null ) : null,
				'undo_ttl_hours'         => \Royal_MCP\MCP\Undo_Store::ttl_hours(),
			],
			'undo'              => is_array( $undo_meta ) ? $undo_meta : null,
		];
	}

	private static function undo_create_field_group( array $payload, array $token_row ) {
		$group_key = isset( $payload['group_key'] ) ? (string) $payload['group_key'] : '';
		if ( $group_key === '' ) {
			return [
				'restored_state_summary' => 'Undo payload missing group_key — nothing to reverse.',
				'noop'                   => true,
			];
		}
		if ( ! function_exists( 'acf_get_field_group' ) ) {
			return [
				'restored_state_summary' => 'ACF API not available — undo cannot run.',
				'noop'                   => true,
			];
		}
		$existing = acf_get_field_group( $group_key );
		if ( ! is_array( $existing ) || empty( $existing['ID'] ) ) {
			return [
				'restored_state_summary' => sprintf( 'Field group "%s" already deleted — nothing to reverse.', $group_key ),
				'noop'                   => true,
				'group_key'              => $group_key,
			];
		}
		self::refuse_if_group_has_values( (int) $existing['ID'], sprintf( 'field group "%s"', $group_key ), $token_row );
		$deleted = self::delete_stored_group( (int) $existing['ID'] );
		if ( ! $deleted ) {
			throw new \RuntimeException( sprintf( 'Delete failed for field group "%s".', $group_key ) );
		}
		return [
			'restored_state_summary' => sprintf( 'Deleted field group "%s" that this operation created.', $group_key ),
			'group_key'              => $group_key,
			'deleted'                => true,
		];
	}

	private static function undo_clone_field_group( array $payload, array $token_row ) {
		$clone_key = isset( $payload['clone_group_key'] ) ? (string) $payload['clone_group_key'] : '';
		if ( $clone_key === '' ) {
			return [
				'restored_state_summary' => 'Undo payload missing clone_group_key — nothing to reverse.',
				'noop'                   => true,
			];
		}
		if ( ! function_exists( 'acf_get_field_group' ) ) {
			return [
				'restored_state_summary' => 'ACF API not available — undo cannot run.',
				'noop'                   => true,
			];
		}
		$existing = acf_get_field_group( $clone_key );
		if ( ! is_array( $existing ) || empty( $existing['ID'] ) ) {
			return [
				'restored_state_summary' => sprintf( 'Cloned field group "%s" already deleted — nothing to reverse.', $clone_key ),
				'noop'                   => true,
				'clone_group_key'        => $clone_key,
			];
		}
		self::refuse_if_group_has_values( (int) $existing['ID'], sprintf( 'cloned field group "%s"', $clone_key ), $token_row );
		$deleted = self::delete_stored_group( (int) $existing['ID'] );
		if ( ! $deleted ) {
			throw new \RuntimeException( sprintf( 'Delete failed for cloned field group "%s".', $clone_key ) );
		}
		return [
			'restored_state_summary' => sprintf( 'Deleted cloned field group "%s"; source group untouched.', $clone_key ),
			'clone_group_key'        => $clone_key,
			'deleted'                => true,
		];
	}

	private static function undo_update_field_group( array $payload, array $token_row ) {
		$group_key = isset( $payload['group_key'] ) ? (string) $payload['group_key'] : '';
		$previous  = isset( $payload['previous_meta'] ) && is_array( $payload['previous_meta'] ) ? $payload['previous_meta'] : null;
		if ( $group_key === '' || $previous === null ) {
			return [
				'restored_state_summary' => 'Undo payload missing group_key or previous_meta — nothing to reverse.',
				'noop'                   => true,
			];
		}
		if ( ! function_exists( 'acf_get_field_group' ) ) {
			return [
				'restored_state_summary' => 'ACF API not available — undo cannot run.',
				'noop'                   => true,
			];
		}
		$existing = acf_get_field_group( $group_key );
		if ( ! is_array( $existing ) || empty( $existing['ID'] ) ) {
			return [
				'restored_state_summary' => sprintf( 'Field group "%s" no longer exists — cannot restore meta.', $group_key ),
				'noop'                   => true,
				'group_key'              => $group_key,
			];
		}

		$restore = $previous;
		$restore['ID']  = (int) $existing['ID'];
		$restore['key'] = $group_key;
		$saved = acf_update_field_group( $restore );
		if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
			throw new \RuntimeException( sprintf( 'acf_update_field_group failed while restoring "%s".', $group_key ) );
		}
		return [
			'restored_state_summary' => sprintf( 'Restored meta for field group "%s" to pre-update state.', $group_key ),
			'group_key'              => $group_key,
		];
	}

	private static function undo_delete_field_group( array $payload, array $token_row ) {
		$mode = isset( $payload['mode'] ) ? (string) $payload['mode'] : '';
		if ( $mode === 'trash' ) {
			$post_id = isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0;
			if ( $post_id <= 0 ) {
				return [
					'restored_state_summary' => 'Undo payload missing post_id for trash-mode restore — nothing to reverse.',
					'noop'                   => true,
				];
			}
			$post = get_post( $post_id );
			if ( ! $post ) {
				return [
					'restored_state_summary' => sprintf( 'Post id %d no longer exists — cannot untrash.', $post_id ),
					'noop'                   => true,
					'post_id'                => $post_id,
				];
			}
			if ( $post->post_status !== 'trash' ) {
				return [
					'restored_state_summary' => sprintf( 'Post id %d is not in trash (status=%s) — nothing to reverse.', $post_id, $post->post_status ),
					'noop'                   => true,
					'post_id'                => $post_id,
				];
			}

			$untrashed = self::with_stored_fields( static function () use ( $post_id ) {
				return function_exists( 'acf_untrash_field_group' ) ? acf_untrash_field_group( $post_id ) : wp_untrash_post( $post_id );
			} );
			if ( ! $untrashed ) {
				throw new \RuntimeException( sprintf( 'Untrash failed for post id %d.', $post_id ) );
			}
			return [
				'restored_state_summary' => sprintf( 'Restored field group post id %d from trash.', $post_id ),
				'post_id'                => $post_id,
				'group_key'              => (string) ( $payload['group_key'] ?? '' ),
			];
		}

		if ( $mode === 'force' ) {
			$group_snapshot  = isset( $payload['group_snapshot'] ) && is_array( $payload['group_snapshot'] ) ? $payload['group_snapshot'] : null;
			$fields_snapshot = isset( $payload['fields_snapshot'] ) && is_array( $payload['fields_snapshot'] ) ? $payload['fields_snapshot'] : [];
			if ( $group_snapshot === null ) {
				return [
					'restored_state_summary' => 'Undo payload missing group_snapshot for force-mode restore — nothing to reverse.',
					'noop'                   => true,
				];
			}
			$group_key = isset( $group_snapshot['key'] ) ? (string) $group_snapshot['key'] : (string) ( $payload['group_key'] ?? '' );
			if ( $group_key === '' ) {
				return [
					'restored_state_summary' => 'Snapshot has no group key — cannot restore.',
					'noop'                   => true,
				];
			}

			$collision = function_exists( 'acf_get_field_group' ) ? acf_get_field_group( $group_key ) : null;
			if ( is_array( $collision ) && ! empty( $collision['ID'] ) ) {
				return [
					'restored_state_summary' => sprintf( 'Field group "%s" already exists again — skipping restore to avoid overwrite.', $group_key ),
					'noop'                   => true,
					'group_key'              => $group_key,
				];
			}
			$group_payload = $group_snapshot;
			unset( $group_payload['ID'] );
			$group_payload['key']    = $group_key;
			$group_payload['fields'] = [];
			$saved = acf_update_field_group( $group_payload );
			if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
				throw new \RuntimeException( sprintf( 'acf_update_field_group failed while restoring "%s".', $group_key ) );
			}
			$restored_group_id = (int) $saved['ID'];
			$restored_field_keys = [];
			foreach ( $fields_snapshot as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$written = self::restore_field_tree( $field, $restored_group_id );
				if ( is_array( $written ) && ! empty( $written['key'] ) ) {
					$restored_field_keys[] = (string) $written['key'];
				}
			}
			return [
				'restored_state_summary' => sprintf(
					'Restored field group "%s" (%d fields) from snapshot after permanent delete.',
					$group_key,
					count( $restored_field_keys )
				),
				'group_key'    => $group_key,
				'group_id'     => $restored_group_id,
				'field_count'  => count( $restored_field_keys ),
				'field_keys'   => $restored_field_keys,
			];
		}

		return [
			'restored_state_summary' => 'Undo payload has unknown mode — nothing to reverse.',
			'noop'                   => true,
			'mode'                   => $mode,
		];
	}

	private static function undo_import_field_group_json( array $payload, array $token_row ) {
		$snapshots = isset( $payload['snapshots'] ) && is_array( $payload['snapshots'] ) ? $payload['snapshots'] : [];
		if ( empty( $snapshots ) ) {
			return [
				'restored_state_summary' => 'Undo payload contains no per-group snapshots — nothing to reverse.',
				'noop'                   => true,
			];
		}
		if ( ! function_exists( 'acf_get_field_group' ) ) {
			return [
				'restored_state_summary' => 'ACF API not available — undo cannot run.',
				'noop'                   => true,
			];
		}

		$deleted_count  = 0;
		$restored_count = 0;
		$noop_count     = 0;
		$per_group      = [];

		foreach ( $snapshots as $snap ) {
			if ( ! is_array( $snap ) ) {
				continue;
			}
			$action    = (string) ( $snap['action'] ?? '' );
			$group_key = (string) ( $snap['group_key'] ?? '' );

			if ( $group_key === '' ) {
				$per_group[] = [ 'action' => $action, 'result' => 'noop', 'reason' => 'missing group_key' ];
				$noop_count++;
				continue;
			}

			if ( $action === 'skipped' ) {
				$per_group[] = [ 'group_key' => $group_key, 'action' => $action, 'result' => 'noop' ];
				$noop_count++;
				continue;
			}

			if ( $action === 'created' ) {
				$existing = acf_get_field_group( $group_key );
				if ( ! is_array( $existing ) || empty( $existing['ID'] ) ) {
					$per_group[] = [ 'group_key' => $group_key, 'action' => $action, 'result' => 'noop', 'reason' => 'already deleted' ];
					$noop_count++;
					continue;
				}
				$deleted = self::delete_stored_group( (int) $existing['ID'] );
				if ( ! $deleted ) {
					throw new \RuntimeException( sprintf( 'Delete failed for created field group "%s".', $group_key ) );
				}
				$per_group[] = [ 'group_key' => $group_key, 'action' => $action, 'result' => 'deleted' ];
				$deleted_count++;
				continue;
			}

			if ( $action === 'updated' ) {
				$meta_snapshot   = isset( $snap['meta_snapshot'] ) && is_array( $snap['meta_snapshot'] ) ? $snap['meta_snapshot'] : null;
				$fields_snapshot = isset( $snap['fields_snapshot'] ) && is_array( $snap['fields_snapshot'] ) ? $snap['fields_snapshot'] : [];
				if ( $meta_snapshot === null ) {
					$per_group[] = [ 'group_key' => $group_key, 'action' => $action, 'result' => 'noop', 'reason' => 'missing meta_snapshot' ];
					$noop_count++;
					continue;
				}
				$existing = acf_get_field_group( $group_key );
				if ( ! is_array( $existing ) || empty( $existing['ID'] ) ) {

					$restore = $meta_snapshot;
					unset( $restore['ID'] );
					$restore['key']    = $group_key;
					$restore['fields'] = [];
					$saved = acf_update_field_group( $restore );
					if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
						throw new \RuntimeException( sprintf( 'acf_update_field_group failed while restoring deleted group "%s".', $group_key ) );
					}
					$group_id = (int) $saved['ID'];
				} else {

					$restore = $meta_snapshot;
					$restore['ID']  = (int) $existing['ID'];
					$restore['key'] = $group_key;

					unset( $restore['fields'] );
					$saved = acf_update_field_group( $restore );
					if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
						throw new \RuntimeException( sprintf( 'acf_update_field_group failed while restoring meta for "%s".', $group_key ) );
					}
					$group_id = (int) $saved['ID'];

					if ( function_exists( 'acf_get_fields' ) && function_exists( 'acf_delete_field' ) ) {
						// Top-level fields only: deleting a container removes its own sub-fields.
						foreach ( self::stored_fields( $existing ) as $f ) {
							if ( is_array( $f ) && ! empty( $f['ID'] ) ) {
								self::delete_stored_field( (int) $f['ID'] );
							}
						}
					}
				}

				$restored_field_keys = [];
				foreach ( $fields_snapshot as $f ) {
					if ( ! is_array( $f ) ) {
						continue;
					}
					$written = self::restore_field_tree( $f, $group_id );
					if ( is_array( $written ) && ! empty( $written['key'] ) ) {
						$restored_field_keys[] = (string) $written['key'];
					}
				}

				$per_group[] = [
					'group_key'    => $group_key,
					'action'       => $action,
					'result'       => 'restored',
					'group_id'     => $group_id,
					'field_count'  => count( $restored_field_keys ),
				];
				$restored_count++;
				continue;
			}

			$per_group[] = [ 'group_key' => $group_key, 'action' => $action, 'result' => 'noop', 'reason' => 'unknown action' ];
			$noop_count++;
		}

		return [
			'restored_state_summary' => sprintf(
				'Reversed JSON import: %d group(s) deleted, %d restored, %d noop.',
				$deleted_count,
				$restored_count,
				$noop_count
			),
			'deleted_count'  => $deleted_count,
			'restored_count' => $restored_count,
			'noop_count'     => $noop_count,
			'per_group'      => $per_group,
		];
	}

	private static function undo_add_field( array $payload, array $token_row ) {
		$field_key = isset( $payload['field_key'] ) ? (string) $payload['field_key'] : '';
		if ( $field_key === '' ) {
			return [
				'restored_state_summary' => 'Undo payload missing field_key — nothing to reverse.',
				'noop'                   => true,
			];
		}
		if ( ! function_exists( 'acf_get_field' ) || ! function_exists( 'acf_delete_field' ) ) {
			return [
				'restored_state_summary' => 'ACF API not available — undo cannot run.',
				'noop'                   => true,
			];
		}
		$field = acf_get_field( $field_key );
		if ( ! is_array( $field ) || empty( $field['ID'] ) ) {
			return [
				'restored_state_summary' => sprintf( 'Field "%s" already deleted — nothing to reverse.', $field_key ),
				'noop'                   => true,
				'field_key'              => $field_key,
			];
		}
		$deleted = self::delete_stored_field( (int) $field['ID'] );
		if ( ! $deleted ) {
			throw new \RuntimeException( sprintf( 'Delete failed for field "%s" during undo.', $field_key ) );
		}
		return [
			'restored_state_summary' => sprintf( 'Deleted field "%s" that this operation added.', $field_key ),
			'field_key'              => $field_key,
			'deleted'                => true,
		];
	}

	private static function undo_update_field_definition( array $payload, array $token_row ) {
		$field_key = isset( $payload['field_key'] ) ? (string) $payload['field_key'] : '';
		$previous  = isset( $payload['previous_field'] ) && is_array( $payload['previous_field'] ) ? $payload['previous_field'] : null;
		if ( $field_key === '' || $previous === null ) {
			return [
				'restored_state_summary' => 'Undo payload missing field_key or previous_field — nothing to reverse.',
				'noop'                   => true,
			];
		}
		if ( ! function_exists( 'acf_get_field' ) || ! function_exists( 'acf_update_field' ) ) {
			return [
				'restored_state_summary' => 'ACF API not available — undo cannot run.',
				'noop'                   => true,
			];
		}

		$existing = acf_get_field( $field_key );
		if ( ! is_array( $existing ) || empty( $existing['ID'] ) ) {
			return [
				'restored_state_summary' => sprintf( 'Field "%s" no longer exists — cannot restore.', $field_key ),
				'noop'                   => true,
				'field_key'              => $field_key,
			];
		}
		$restore        = $previous;
		$restore['ID']  = (int) $existing['ID'];
		$restore['key'] = $field_key;
		$written = self::save_field( $restore );
		if ( ! is_array( $written ) || empty( $written['key'] ) ) {
			throw new \RuntimeException( sprintf( 'acf_update_field failed while restoring "%s".', $field_key ) );
		}
		return [
			'restored_state_summary' => sprintf( 'Restored field "%s" to pre-update state.', $field_key ),
			'field_key'              => $field_key,
		];
	}

	private static function undo_delete_field( array $payload, array $token_row ) {
		$snapshot         = isset( $payload['field_snapshot'] ) && is_array( $payload['field_snapshot'] ) ? $payload['field_snapshot'] : null;
		$parent_group_id  = isset( $payload['parent_group_id'] ) ? (int) $payload['parent_group_id'] : 0;
		$force_mode       = ! empty( $payload['force_mode'] );
		$meta_purged      = isset( $payload['meta_purged_count'] ) ? (int) $payload['meta_purged_count'] : 0;

		if ( $snapshot === null || $parent_group_id <= 0 ) {
			return [
				'restored_state_summary' => 'Undo payload missing field_snapshot or parent_group_id — nothing to reverse.',
				'noop'                   => true,
			];
		}
		if ( ! function_exists( 'acf_get_field' ) || ! function_exists( 'acf_update_field' ) ) {
			return [
				'restored_state_summary' => 'ACF API not available — undo cannot run.',
				'noop'                   => true,
			];
		}
		$field_key = (string) ( $snapshot['key'] ?? '' );
		if ( $field_key === '' ) {
			return [
				'restored_state_summary' => 'Snapshot has no field key — cannot restore.',
				'noop'                   => true,
			];
		}

		$collision = acf_get_field( $field_key );
		if ( is_array( $collision ) && ! empty( $collision['ID'] ) ) {
			return [
				'restored_state_summary' => sprintf( 'Field "%s" already exists again — skipping restore to avoid overwrite.', $field_key ),
				'noop'                   => true,
				'field_key'              => $field_key,
			];
		}

		$restore        = $snapshot;
		$restore['key'] = $field_key;

		$written = self::restore_field_tree( $restore, $parent_group_id );
		if ( ! is_array( $written ) || empty( $written['key'] ) ) {
			throw new \RuntimeException( sprintf( 'acf_update_field failed while restoring "%s".', $field_key ) );
		}

		$summary = sprintf( 'Restored field "%s" from snapshot.', $field_key );
		if ( $force_mode && $meta_purged > 0 ) {
			$summary .= sprintf(
				' Definition restored; %d post_meta value(s) purged by the force-delete are NOT restored (values are gone).',
				$meta_purged
			);
		}
		return [
			'restored_state_summary' => $summary,
			'field_key'              => $field_key,
			'restored_field_id'      => (int) $written['ID'],
			'values_restored'        => ( ! $force_mode ),
			'meta_values_lost_count' => $force_mode ? $meta_purged : 0,
		];
	}

	private static function undo_reorder_fields( array $payload, array $token_row ) {
		$group_key      = isset( $payload['group_key'] ) ? (string) $payload['group_key'] : '';
		$previous_order = isset( $payload['previous_order'] ) && is_array( $payload['previous_order'] ) ? $payload['previous_order'] : [];
		if ( $group_key === '' || empty( $previous_order ) ) {
			return [
				'restored_state_summary' => 'Undo payload missing group_key or previous_order — nothing to reverse.',
				'noop'                   => true,
			];
		}
		if ( ! function_exists( 'acf_get_field' ) || ! function_exists( 'acf_update_field' ) ) {
			return [
				'restored_state_summary' => 'ACF API not available — undo cannot run.',
				'noop'                   => true,
			];
		}
		$restored = 0;
		$skipped  = 0;
		$per_field = [];
		foreach ( $previous_order as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$fk = (string) ( $entry['field_key'] ?? '' );
			$mo = (int) ( $entry['menu_order'] ?? 0 );
			if ( $fk === '' ) {
				continue;
			}
			$field = acf_get_field( $fk );
			if ( ! is_array( $field ) || empty( $field['ID'] ) ) {
				$per_field[] = [ 'field_key' => $fk, 'result' => 'noop', 'reason' => 'field no longer exists' ];
				$skipped++;
				continue;
			}
			$field['menu_order'] = $mo;
			$written = self::save_field( $field );
			if ( is_array( $written ) && isset( $written['key'] ) ) {
				$per_field[] = [ 'field_key' => $fk, 'result' => 'restored', 'menu_order' => $mo ];
				$restored++;
			} else {
				$per_field[] = [ 'field_key' => $fk, 'result' => 'noop', 'reason' => 'acf_update_field returned no key' ];
				$skipped++;
			}
		}
		return [
			'restored_state_summary' => sprintf(
				'Restored menu_order for %d field(s) in group "%s" (%d skipped).',
				$restored,
				$group_key,
				$skipped
			),
			'group_key'      => $group_key,
			'restored_count' => $restored,
			'skipped_count'  => $skipped,
			'per_field'      => $per_field,
		];
	}

	private static function undo_register_post_type( array $payload, array $token_row ) {
		$post_type_key = isset( $payload['post_type_key'] ) ? (string) $payload['post_type_key'] : '';
		$acf_post_id   = isset( $payload['acf_post_id'] ) ? (int) $payload['acf_post_id'] : 0;
		if ( $post_type_key === '' || $acf_post_id <= 0 ) {
			return [
				'restored_state_summary' => 'Undo payload missing post_type_key or acf_post_id — nothing to reverse.',
				'noop'                   => true,
			];
		}
		$existing_posts = 0;
		if ( function_exists( 'wp_count_posts' ) ) {
			$counts = wp_count_posts( $post_type_key );
			if ( is_object( $counts ) ) {
				foreach ( [ 'publish', 'draft', 'private', 'pending' ] as $status ) {
					if ( isset( $counts->{$status} ) ) {
						$existing_posts += (int) $counts->{$status};
					}
				}
			}
		}
		if ( function_exists( 'acf_delete_internal_post_type' ) ) {
			acf_delete_internal_post_type( $acf_post_id, 'acf-post-type' );
		} else {
			wp_delete_post( $acf_post_id, true );
		}
		$summary = sprintf( 'Deleted ACF CPT registration "%s" that this operation created.', $post_type_key );
		if ( $existing_posts > 0 ) {
			$summary .= sprintf( ' Warning: %d existing post(s) of this type are now orphaned.', $existing_posts );
		}
		$result = [
			'restored_state_summary' => $summary,
			'post_type_key'          => $post_type_key,
			'removed'                => true,
		];
		if ( $existing_posts > 0 ) {
			$result['warning'] = 'existing_posts_orphaned';
			$result['count']   = $existing_posts;
		}
		return $result;
	}

	private static function get_tool_schemas() {
		return [
			'acf_create_field_group' => [
				'name'        => 'acf_create_field_group',
				'description' => 'Create a new ACF field group with inline field definitions and location rules. Returns the created group id + key + field count + an undo token. Requires ACF 6.0+ active + manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'title', 'fields', 'location' ],
					'properties'           => [
						'title' => [
							'type'        => 'string',
							'description' => 'Human-readable group title shown in the ACF admin.',
						],
						'key' => [
							'type'        => 'string',
							'description' => 'Stable group key (e.g. group_landing_page). Auto-generated as group_<hash> when omitted.',
						],
						'fields' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'object' ],
							'minItems'    => 1,
							'description' => 'Ordered array of ACF field definitions. Each element mirrors the acf_add_field field-object shape (label, name, type, plus type-specific args).',
						],
						'location' => [
							'type'        => 'array',
							'items'       => [
								'type'  => 'array',
								'items' => [ 'type' => 'object' ],
							],
							'minItems'    => 1,
							'description' => 'Array of location rule groups. Outer array = OR; inner array = AND. Each rule object has {param, operator, value}.',
						],
						'position' => [
							'type'        => 'string',
							'enum'        => self::GROUP_POSITIONS,
							'description' => 'Where the group renders on the edit screen. Default "normal".',
						],
						'style' => [
							'type'        => 'string',
							'enum'        => self::GROUP_STYLES,
							'description' => 'Metabox style. Default "default".',
						],
						'label_placement' => [
							'type'        => 'string',
							'enum'        => self::LABEL_PLACEMENTS,
							'description' => 'Label position relative to input. Default "top".',
						],
						'instruction_placement' => [
							'type'        => 'string',
							'enum'        => self::INSTRUCTION_PLACEMENTS,
							'description' => 'Where field instructions render. Default "label".',
						],
						'hide_on_screen' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'WP screen elements to hide (e.g. permalink, excerpt, featured_image).',
						],
						'active' => [
							'type'        => 'boolean',
							'description' => 'Whether the group is active on save. Default true.',
						],
						'description' => [
							'type'        => 'string',
							'description' => 'Internal description shown in the ACF admin list.',
						],
						'menu_order' => [
							'type'        => 'integer',
							'description' => 'Sort order among sibling groups on the same location. Default 0.',
						],
					],
				],
			],
			'acf_clone_field_group' => [
				'name'        => 'acf_clone_field_group',
				'description' => 'Deep-clone an existing ACF field group as the starting point for a new one. Regenerates every field key and preserves sub-fields, conditional logic, validation, and wrapper classes. Returns the new group id + key + an undo token. Requires ACF 6.0+ active + manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'source_group_key', 'new_title' ],
					'properties'           => [
						'source_group_key' => [
							'type'        => 'string',
							'description' => 'Key of the source group to clone (e.g. group_standard_page).',
						],
						'new_title' => [
							'type'        => 'string',
							'description' => 'Title for the cloned group.',
						],
						'new_key' => [
							'type'        => 'string',
							'description' => 'Stable key for the cloned group. Auto-generated when omitted.',
						],
						'location_overrides' => [
							'type'        => 'array',
							'items'       => [
								'type'  => 'array',
								'items' => [ 'type' => 'object' ],
							],
							'description' => 'Optional replacement location rule groups. Omit to inherit the source group\'s rules.',
						],
					],
				],
			],
			'acf_update_field_group' => [
				'name'        => 'acf_update_field_group',
				'description' => 'Modify an existing ACF field group\'s meta — title, location, position, style, label placement, active flag, and description. Does NOT modify fields (use acf_add_field / acf_update_field_definition / acf_delete_field for those). Returns changed keys + an undo token. Requires ACF 6.0+ active + manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'group_key' ],
					'properties'           => [
						'group_key' => [
							'type'        => 'string',
							'description' => 'Key of the field group to update.',
						],
						'title' => [
							'type'        => 'string',
							'description' => 'New group title.',
						],
						'location' => [
							'type'        => 'array',
							'items'       => [
								'type'  => 'array',
								'items' => [ 'type' => 'object' ],
							],
							'description' => 'Replacement location rule groups.',
						],
						'position' => [
							'type'        => 'string',
							'enum'        => self::GROUP_POSITIONS,
							'description' => 'New render position.',
						],
						'style' => [
							'type'        => 'string',
							'enum'        => self::GROUP_STYLES,
							'description' => 'New metabox style.',
						],
						'label_placement' => [
							'type'        => 'string',
							'enum'        => self::LABEL_PLACEMENTS,
							'description' => 'New label placement.',
						],
						'instruction_placement' => [
							'type'        => 'string',
							'enum'        => self::INSTRUCTION_PLACEMENTS,
							'description' => 'New instruction placement.',
						],
						'hide_on_screen' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'Replacement list of WP screen elements to hide.',
						],
						'active' => [
							'type'        => 'boolean',
							'description' => 'Whether the group is active.',
						],
						'description' => [
							'type'        => 'string',
							'description' => 'New internal description.',
						],
						'menu_order' => [
							'type'        => 'integer',
							'description' => 'New sort order among sibling groups.',
						],
					],
				],
			],
			'acf_delete_field_group' => [
				'name'        => 'acf_delete_field_group',
				'description' => 'Trash or permanently delete an ACF field group. Trash path (default) is recoverable via the WP trash; force path captures a full pre-op snapshot so undo can restore the group and every field even after permanent delete. Returns an undo token. Requires ACF 6.0+ active + manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'group_key' ],
					'properties'           => [
						'group_key' => [
							'type'        => 'string',
							'description' => 'Key of the field group to delete.',
						],
						'force' => [
							'type'        => 'boolean',
							'description' => 'False (default) trashes the group. True permanently deletes it — undo restores from the stored snapshot.',
						],
					],
				],
			],
			'acf_import_field_group_json' => [
				'name'        => 'acf_import_field_group_json',
				'description' => 'Import ACF field groups from JSON — the exact shape produced by ACF admin\'s Tools -> Export as JSON (single group or array of groups). Replaces the manual "save file, then use ACF admin import" step. Field keys are preserved from the JSON to keep post_meta values continuous across imports. Returns per-group action + an undo token. Requires ACF 6.0+ active + manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'json' ],
					'properties'           => [
						'json' => [
							'anyOf'       => [
								[ 'type' => 'string' ],
								[ 'type' => 'object' ],
								[
									'type'  => 'array',
									'items' => [ 'type' => 'object' ],
								],
							],
							'description' => 'The ACF JSON payload. Accepts a raw JSON string, a single group object, or an array of group objects — same shapes ACF\'s admin importer accepts.',
						],
						'mode' => [
							'type'        => 'string',
							'enum'        => self::IMPORT_MODES,
							'description' => 'Behavior when the group_key already exists. Default "update_if_exists" — matches the ACF admin importer.',
						],
						'dry_run' => [
							'type'        => 'boolean',
							'description' => 'Validate the payload and return what would happen without persisting. Default false.',
						],
					],
				],
			],
			'acf_export_field_group_json' => [
				'name'        => 'acf_export_field_group_json',
				'description' => 'Export one or more ACF field groups as JSON, matching the ACF admin\'s Tools -> Export as JSON format. Round-trips cleanly through acf_import_field_group_json. Read-only. Requires ACF 6.0+ active + manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'group_keys' ],
					'properties'           => [
						'group_keys' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'minItems'    => 1,
							'description' => 'Field group keys to export.',
						],
						'include_fields' => [
							'type'        => 'boolean',
							'description' => 'Include the full field definitions in the export. Default true.',
						],
					],
				],
			],
			'acf_add_field' => [
				'name'        => 'acf_add_field',
				'description' => 'Add a single field to an existing ACF field group with positioning (append, prepend, or relative to another field key). Type-specific args (min/max for number, choices for select, sub_fields for repeater, layouts for flexible_content) are validated against the requested field type\'s schema. Returns the new field key + resolved position + an undo token. Requires ACF 6.0+ active + manage_options; repeater / flexible_content / gallery / clone types require ACF Pro.',
				'inputSchema' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'group_key', 'field' ],
					'properties'           => [
						'group_key' => [
							'type'        => 'string',
							'description' => 'Key of the target field group.',
						],
						'field' => [
							'type'        => 'object',
							'description' => 'Field definition — required: label, name, type. Optional: key, instructions, required, default_value, and any type-specific args.',
						],
						'position' => [
							'anyOf'       => [
								[
									'type' => 'string',
									'enum' => [ 'append', 'prepend' ],
								],
								[ 'type' => 'object' ],
							],
							'description' => 'Where to insert the field. Accepts "append" (default), "prepend", {"after": "field_key"}, or {"before": "field_key"}.',
						],
					],
				],
			],
			'acf_update_field_definition' => [
				'name'        => 'acf_update_field_definition',
				'description' => 'Modify an existing ACF field\'s definition — label, instructions, validation, conditional logic, choices, sub-fields, etc. Type changes require allow_type_change: true and surface a warning (existing values may need manual migration). Renaming the field name (as opposed to label) breaks post_meta lookups and surfaces a warning. Returns changed keys + an undo token. Requires ACF 6.0+ active + manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'field_key', 'updates' ],
					'properties'           => [
						'field_key' => [
							'type'        => 'string',
							'description' => 'Key of the field to update (e.g. field_hero_headline).',
						],
						'updates' => [
							'type'        => 'object',
							'description' => 'Partial field definition — only the keys to change.',
						],
						'allow_type_change' => [
							'type'        => 'boolean',
							'description' => 'Explicit opt-in required when updates.type differs from the current field type. Default false.',
						],
					],
				],
			],
			'acf_delete_field' => [
				'name'        => 'acf_delete_field',
				'description' => 'Remove a field from its group. Non-force (default) deletes only the field definition, preserving post_meta values so they reappear if the field is later re-added with the same name. Force additionally purges every matching post_meta row across the site — irreversible for values. Returns purged count + an undo token that restores the field definition (values are not restored on force). Requires ACF 6.0+ active + manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'field_key' ],
					'properties'           => [
						'field_key' => [
							'type'        => 'string',
							'description' => 'Key of the field to delete.',
						],
						'force' => [
							'type'        => 'boolean',
							'description' => 'False (default) preserves post_meta values. True purges post_meta rows using this field name across every post.',
						],
					],
				],
			],
			'acf_reorder_fields' => [
				'name'        => 'acf_reorder_fields',
				'description' => 'Reorder the fields inside an ACF field group. The provided list must contain every field key in the group — partial reorders are rejected. ACF stores order via menu_order on each field post. Returns previous + new order + an undo token. Requires ACF 6.0+ active + manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'group_key', 'field_keys_ordered' ],
					'properties'           => [
						'group_key' => [
							'type'        => 'string',
							'description' => 'Key of the target field group.',
						],
						'field_keys_ordered' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'minItems'    => 1,
							'uniqueItems' => true,
							'description' => 'Full ordered list of field keys as they should appear.',
						],
					],
				],
			],
			'acf_get_field_group_config' => [
				'name'        => 'acf_get_field_group_config',
				'description' => 'Read the full definition of an ACF field group — meta plus fields plus recursively-expanded sub-fields for repeater / flexible_content / group / clone types. Read-only, no undo. Requires ACF 6.0+ active + manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'group_key' ],
					'properties'           => [
						'group_key' => [
							'type'        => 'string',
							'description' => 'Key of the field group to introspect.',
						],
						'include_fields' => [
							'type'        => 'boolean',
							'description' => 'Include field definitions. Default true.',
						],
						'include_sub_fields' => [
							'type'        => 'boolean',
							'description' => 'Expand nested sub-fields inside repeater / flexible_content / group / clone types. Default true.',
						],
					],
				],
			],
			'acf_list_supported_field_types' => [
				'name'        => 'acf_list_supported_field_types',
				'description' => 'Enumerate every ACF field type registered on this install (core ACF + ACF Pro + third-party extensions) with per-type args schema. Also reports whether ACF Pro is active and the running ACF version. Read-only, no undo. Requires ACF 6.0+ active + manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'additionalProperties' => false,

					'properties'           => new \stdClass(),
				],
			],
			'acf_register_post_type' => [
				'name'        => 'acf_register_post_type',
				'description' => 'Register a custom post type via ACF\'s built-in Post Types screen (ACF 6.1+, free or Pro). Refused when the key is already a post type, ACF already stores one with that key, or another post type uses the slug; ACF registers the type with WordPress from the next page load (registered_now says whether it is live already). Persisted in the acf-post-type store so the CPT appears in ACF admin and remains editable there. Undo warns when existing posts of the type would be orphaned. Returns admin URL + an undo token. Requires ACF 6.1+ (free or Pro) active + manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'post_type_key', 'singular_label', 'plural_label' ],
					'properties'           => [
						'post_type_key' => [
							'type'        => 'string',
							'description' => 'CPT slug — sanitized to lowercase and hyphens (e.g. "project").',
						],
						'singular_label' => [
							'type'        => 'string',
							'description' => 'Singular label (e.g. "Project").',
						],
						'plural_label' => [
							'type'        => 'string',
							'description' => 'Plural label (e.g. "Projects").',
						],
						'slug' => [
							'type'        => 'string',
							'description' => 'URL rewrite base. Defaults to post_type_key.',
						],
						'menu_position' => [
							'type'        => 'integer',
							'description' => 'Numeric position in the WP admin menu.',
						],
						'menu_icon' => [
							'type'        => 'string',
							'description' => 'Dashicon name (e.g. "dashicons-portfolio") or full URL. Default "dashicons-admin-post".',
						],
						'supports' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'Post feature flags. Default ["title", "editor", "thumbnail"]. Valid: title, editor, thumbnail, excerpt, author, comments, trackbacks, custom-fields, revisions, page-attributes, post-formats.',
						],
						'taxonomies' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'Existing taxonomies to attach (e.g. ["category", "post_tag"]).',
						],
						'public' => [
							'type'        => 'boolean',
							'description' => 'Whether the CPT is publicly queryable + visible in the UI. Default true.',
						],
						'show_in_rest' => [
							'type'        => 'boolean',
							'description' => 'Enable REST API + Gutenberg support. Default true.',
						],
						'has_archive' => [
							'type'        => 'boolean',
							'description' => 'Enable archive views. Default true.',
						],
						'hierarchical' => [
							'type'        => 'boolean',
							'description' => 'Enable parent/child relationships (page-like CPTs). Default false.',
						],
						'capability_type' => [
							'type'        => 'string',
							'description' => 'Capability template for permission mapping. Default "post".',
						],
						'description' => [
							'type'        => 'string',
							'description' => 'Short description shown in ACF admin.',
						],
					],
				],
			],
		];
	}

	private static function error( $code, $msg, array $extra = [] ) {
		return Envelope::error( $code, $msg, $extra );
	}

	private static function validate_location_rule_groups( array $groups ) {
		if ( empty( $groups ) ) {
			return [ 'error' => self::error( 'invalid_location', 'location must contain at least one rule group.' ) ];
		}

		$known_params = [];
		if ( function_exists( 'acf_get_location_types' ) ) {
			foreach ( (array) acf_get_location_types() as $type ) {
				if ( is_object( $type ) && isset( $type->name ) ) {
					$known_params[] = (string) $type->name;
				}
			}
		}
		$normalized = [];
		foreach ( $groups as $g_idx => $group ) {
			if ( ! is_array( $group ) || empty( $group ) ) {
				return [ 'error' => self::error(
					'invalid_location',
					sprintf( 'location[%d] must be a non-empty array of rules.', (int) $g_idx )
				) ];
			}
			$rules = [];
			foreach ( $group as $r_idx => $rule ) {
				if ( ! is_array( $rule ) ) {
					return [ 'error' => self::error(
						'invalid_location_rule',
						sprintf( 'location[%d][%d] must be an object with param/operator/value.', (int) $g_idx, (int) $r_idx )
					) ];
				}
				$param    = isset( $rule['param'] ) ? (string) $rule['param'] : '';
				$operator = isset( $rule['operator'] ) ? (string) $rule['operator'] : '==';
				$value    = array_key_exists( 'value', $rule ) ? $rule['value'] : '';
				if ( $param === '' ) {
					return [ 'error' => self::error(
						'invalid_location_rule',
						sprintf( 'location[%d][%d] missing required "param" key.', (int) $g_idx, (int) $r_idx )
					) ];
				}
				if ( ! empty( $known_params ) && ! in_array( $param, $known_params, true ) ) {
					return [ 'error' => self::error(
						'invalid_location_rule',
						sprintf( 'Unknown location param "%s".', $param ),
						[ 'param' => $param, 'known_params' => $known_params ]
					) ];
				}
				if ( ! in_array( $operator, self::LOCATION_OPERATORS, true ) ) {
					return [ 'error' => self::error(
						'invalid_location_operator',
						sprintf( 'Unknown location operator "%s".', $operator ),
						[ 'operator' => $operator, 'allowed' => self::LOCATION_OPERATORS ]
					) ];
				}
				$rules[] = [
					'param'    => $param,
					'operator' => $operator,
					'value'    => is_scalar( $value ) ? (string) $value : $value,
				];
			}
			$normalized[] = $rules;
		}
		return [ 'location' => $normalized ];
	}

	private static function generate_group_key() {
		return 'group_' . bin2hex( random_bytes( 6 ) );
	}

	private static function generate_field_key() {
		return 'field_' . bin2hex( random_bytes( 6 ) );
	}

	private static function prepare_field_for_write( array $field, $order_index ) {

		$prepared = self::strip_write_ids( $field );
		$key_taken = ! empty( $prepared['key'] ) && function_exists( 'acf_get_field' ) && is_array( acf_get_field( (string) $prepared['key'] ) );
		if ( empty( $prepared['key'] ) || strpos( (string) $prepared['key'], 'field_' ) !== 0 || $key_taken ) {
			$prepared['key'] = self::generate_field_key();
		}
		if ( ! isset( $prepared['label'] ) ) {
			$prepared['label'] = '';
		}
		if ( ! isset( $prepared['name'] ) || $prepared['name'] === '' ) {
			$name = sanitize_key( (string) ( $prepared['label'] ?? '' ) );
			if ( $name === '' ) {

				$name = (string) preg_replace( '/^field_/', '', (string) $prepared['key'] );
				if ( $name === '' ) {
					$name = 'field_' . bin2hex( random_bytes( 6 ) );
				}
			}
			$prepared['name'] = $name;
		}
		if ( ! isset( $prepared['menu_order'] ) ) {
			$prepared['menu_order'] = (int) $order_index;
		}

		if ( isset( $prepared['sub_fields'] ) && is_array( $prepared['sub_fields'] ) ) {
			$prepared_subs = [];
			foreach ( array_values( $prepared['sub_fields'] ) as $s_idx => $sub ) {
				$prepared_subs[] = self::prepare_field_for_write( (array) $sub, $s_idx );
			}
			$prepared['sub_fields'] = $prepared_subs;
		}

		if ( isset( $prepared['layouts'] ) && is_array( $prepared['layouts'] ) ) {
			$prepared_layouts = [];
			foreach ( array_values( $prepared['layouts'] ) as $l_idx => $layout ) {
				$layout = (array) $layout;
				if ( empty( $layout['key'] ) ) {
					$layout['key'] = 'layout_' . bin2hex( random_bytes( 6 ) );
				}
				if ( isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
					$prepared_layout_subs = [];
					foreach ( array_values( $layout['sub_fields'] ) as $s_idx => $sub ) {
						$prepared_layout_subs[] = self::prepare_field_for_write( (array) $sub, $s_idx );
					}
					$layout['sub_fields'] = $prepared_layout_subs;
				}
				$prepared_layouts[] = $layout;
			}
			$prepared['layouts'] = $prepared_layouts;
		}
		return $prepared;
	}

	/**
	 * Writes one field. Sub-fields are posts of their own, so the copies a field array carries of
	 * them are left out of what is stored for the field itself.
	 */
	private static function save_field( array $field ) {
		$own = self::strip_nested_containers( [ $field ] );
		return acf_update_field( $own[0] );
	}

	/**
	 * Writes a field that no longer exists back under $parent_id, with every sub-field below it.
	 * Old post ids in the snapshot are dropped; keys, names and order are kept.
	 */
	private static function restore_field_tree( array $field, $parent_id ) {
		$tree           = self::strip_write_ids( $field );
		$tree['parent'] = (int) $parent_id;
		$written        = self::save_field( $tree );
		if ( is_array( $written ) && ! empty( $written['ID'] ) ) {
			self::persist_nested_fields( $tree, (int) $written['ID'] );
		}
		return $written;
	}

	private static function strip_write_ids( array $node ) {
		unset( $node['ID'], $node['parent'], $node['_valid'] );
		foreach ( [ 'sub_fields', 'fields' ] as $list_key ) {
			if ( isset( $node[ $list_key ] ) && is_array( $node[ $list_key ] ) ) {
				foreach ( $node[ $list_key ] as $i => $child ) {
					$node[ $list_key ][ $i ] = is_array( $child ) ? self::strip_write_ids( $child ) : $child;
				}
			}
		}
		if ( isset( $node['layouts'] ) && is_array( $node['layouts'] ) ) {
			foreach ( $node['layouts'] as $i => $layout ) {
				if ( is_array( $layout ) && isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
					foreach ( $layout['sub_fields'] as $j => $child ) {
						$node['layouts'][ $i ]['sub_fields'][ $j ] = is_array( $child ) ? self::strip_write_ids( $child ) : $child;
					}
				}
			}
		}
		return $node;
	}

	private static function persist_nested_fields( array $parent_field, $parent_field_id ) {
		if ( isset( $parent_field['sub_fields'] ) && is_array( $parent_field['sub_fields'] ) ) {
			foreach ( $parent_field['sub_fields'] as $sub ) {
				if ( ! is_array( $sub ) ) {
					continue;
				}
				$sub['parent'] = (int) $parent_field_id;
				$written = self::save_field( $sub );
				if ( is_array( $written ) && ! empty( $written['ID'] ) ) {
					self::persist_nested_fields( $sub, (int) $written['ID'] );
				}
			}
		}
		if ( isset( $parent_field['layouts'] ) && is_array( $parent_field['layouts'] ) ) {
			foreach ( $parent_field['layouts'] as $layout ) {
				if ( ! is_array( $layout ) || ! isset( $layout['sub_fields'] ) || ! is_array( $layout['sub_fields'] ) ) {
					continue;
				}
				foreach ( $layout['sub_fields'] as $sub ) {
					if ( ! is_array( $sub ) ) {
						continue;
					}
					$sub['parent'] = (int) $parent_field_id;
					$written = self::save_field( $sub );
					if ( is_array( $written ) && ! empty( $written['ID'] ) ) {
						self::persist_nested_fields( $sub, (int) $written['ID'] );
					}
				}
			}
		}
	}

	/**
	 * Runs $fn with ACF's clone expansion switched off and its loaded-field cache emptied before
	 * and after. Every field read inside sees only the posts stored under its own parent: a
	 * seamless clone stays one clone field instead of copies of the fields it points at (which
	 * carry the other group's post ids), and deleting a container removes only its own sub-fields.
	 */
	private static function with_stored_fields( callable $fn ) {
		static $depth = 0;
		if ( $depth > 0 ) {
			return $fn();
		}
		$store  = function_exists( 'acf_get_store' ) ? acf_get_store( 'fields' ) : false;
		$can    = function_exists( 'acf_is_filter_enabled' ) && function_exists( 'acf_disable_filter' ) && function_exists( 'acf_enable_filter' );
		$was_on = $can && acf_is_filter_enabled( 'clone' );
		if ( $was_on ) {
			acf_disable_filter( 'clone' );
		}
		if ( is_object( $store ) && method_exists( $store, 'reset' ) ) {
			$store->reset();
		}
		$depth++;
		try {
			return $fn();
		} finally {
			$depth--;
			if ( $was_on ) {
				acf_enable_filter( 'clone' );
			}
			if ( is_object( $store ) && method_exists( $store, 'reset' ) ) {
				$store->reset();
			}
		}
	}

	/** The fields stored directly under a group or field, as saved. A clone field comes back as itself, without copies of its target fields. */
	private static function stored_fields( $parent ) {
		if ( ! function_exists( 'acf_get_fields' ) ) {
			return [];
		}
		$fields = (array) self::with_stored_fields( static function () use ( $parent ) {
			return acf_get_fields( $parent );
		} );
		$out = [];
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			if ( 'clone' === (string) ( $field['type'] ?? '' ) ) {
				unset( $field['sub_fields'] );
			}
			$out[] = $field;
		}
		return $out;
	}

	/** Deletes a field group and its own fields only. */
	private static function delete_stored_group( $group_id ) {
		return self::with_stored_fields( static function () use ( $group_id ) {
			return function_exists( 'acf_delete_field_group' ) ? acf_delete_field_group( (int) $group_id ) : wp_delete_post( (int) $group_id, true );
		} );
	}

	/** Deletes a field and its own sub-fields only. */
	private static function delete_stored_field( $field_id ) {
		return self::with_stored_fields( static function () use ( $field_id ) {
			return acf_delete_field( (int) $field_id );
		} );
	}

	/** Post ids of every acf-field row below a field or group, at every depth, read from the posts table. */
	private static function descendant_field_ids( $post_id ) {
		global $wpdb;
		$ids     = [];
		$parents = [ (int) $post_id ];
		for ( $depth = 0; $parents && $depth < 10 && count( $ids ) < 5000; $depth++ ) {
			$in = implode( ',', array_map( 'intval', $parents ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only.
			$parents = array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'acf-field' AND post_parent IN ({$in})" ) );
			$ids     = array_merge( $ids, $parents );
		}
		return $ids;
	}

	/**
	 * Whether a stored field holds a saved value anywhere: post, term, user or comment meta,
	 * options, or an ACF block in post content. A clone field's values are stored under
	 * "<clone key>_<field key>" references.
	 */
	private static function field_has_values( $field_post_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT p.post_name, p.post_excerpt, p.post_content, parent.post_type AS parent_type FROM {$wpdb->posts} p LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent WHERE p.ID = %d",
			(int) $field_post_id
		), ARRAY_A );
		if ( ! is_array( $row ) || '' === (string) $row['post_name'] ) {
			return false;
		}
		$key      = (string) $row['post_name'];
		$name     = (string) $row['post_excerpt'];
		$settings = maybe_unserialize( (string) $row['post_content'] );
		$is_clone = is_array( $settings ) && 'clone' === (string) ( $settings['type'] ?? '' );
		$ref_like = $wpdb->esc_like( '_' ) . '%';
		$tables   = [ $wpdb->postmeta, $wpdb->termmeta, $wpdb->usermeta, $wpdb->commentmeta ];
		if ( $is_clone ) {
			$value_sql = 'LIKE %s';
			$value_arg = $wpdb->esc_like( $key . '_' ) . '%';
			$block_arg = '%' . $wpdb->esc_like( '"' . $key . '_' ) . '%';
		} else {
			$value_sql = '= %s';
			$value_arg = $key;
			$block_arg = '%' . $wpdb->esc_like( '"' . $key . '"' ) . '%';
		}
		$by_name = '' !== $name && ! $is_clone && 'acf-field' !== (string) $row['parent_type'];
		foreach ( $tables as $table ) {
			if ( $by_name ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table names.
				$hit = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE meta_key = %s AND meta_value = %s LIMIT 1", '_' . $name, $key ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- core table names; $value_sql is one of two fixed fragments.
				$hit = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE meta_key LIKE %s AND meta_value {$value_sql} LIMIT 1", $ref_like, $value_arg ) );
			}
			if ( $hit ) {
				return true;
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $value_sql is one of two fixed fragments.
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value {$value_sql} LIMIT 1", $ref_like, $value_arg ) ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_status IN ('publish', 'future', 'draft', 'pending', 'private') AND post_content LIKE %s AND post_content LIKE %s LIMIT 1",
			'%' . $wpdb->esc_like( '<!-- wp:acf/' ) . '%',
			$block_arg
		) );
	}

	/** Key of the first field stored under this group, at any depth, that holds a saved value; '' when none does. */
	private static function group_field_with_values( $group_id ) {
		global $wpdb;
		foreach ( self::descendant_field_ids( (int) $group_id ) as $field_id ) {
			if ( self::field_has_values( (int) $field_id ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_name FROM {$wpdb->posts} WHERE ID = %d", (int) $field_id ) );
			}
		}
		return '';
	}

	/**
	 * Refuses an undo that would delete a group whose fields hold saved values, unless the caller
	 * forces it. The undo token stays usable.
	 *
	 * @throws Tool_Refusal When a field holds values and force is off.
	 */
	private static function refuse_if_group_has_values( $group_id, $label, array $token_row ) {
		if ( ! empty( $token_row['force'] ) ) {
			return;
		}
		$valued = self::group_field_with_values( (int) $group_id );
		if ( '' !== $valued ) {
			throw new Tool_Refusal( 'fields_have_values', esc_html( sprintf( 'Content now has saved values for field "%s" of %s, so the undo left it in place. Undo again with force: true to delete it anyway (the saved values stay in the database but are no longer shown or edited through ACF).', $valued, $label ) ), [ 'field_key' => $valued ] );
		}
	}

	private static function expand_field_tree( array $fields ) {
		$expanded = [];
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				$expanded[] = $field;
				continue;
			}
			$type = isset( $field['type'] ) ? (string) $field['type'] : '';
			if ( in_array( $type, [ 'repeater', 'group', 'clone' ], true ) && function_exists( 'acf_get_fields' ) ) {
				$children            = self::stored_fields( $field );
				$field['sub_fields'] = self::expand_field_tree( $children );
			} elseif ( $type === 'flexible_content' && function_exists( 'acf_get_fields' ) && isset( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				$children_flat = self::stored_fields( $field );

				$by_layout_key = [];
				foreach ( $children_flat as $child ) {
					if ( ! is_array( $child ) ) {
						continue;
					}
					$layout_key = isset( $child['parent_layout'] ) ? (string) $child['parent_layout'] : '';
					if ( $layout_key === '' ) {
						continue;
					}
					if ( ! isset( $by_layout_key[ $layout_key ] ) ) {
						$by_layout_key[ $layout_key ] = [];
					}
					$by_layout_key[ $layout_key ][] = $child;
				}
				$rebuilt_layouts = [];
				foreach ( $field['layouts'] as $layout ) {
					if ( ! is_array( $layout ) ) {
						$rebuilt_layouts[] = $layout;
						continue;
					}
					$layout_key = isset( $layout['key'] ) ? (string) $layout['key'] : '';
					$subs       = ( $layout_key !== '' && isset( $by_layout_key[ $layout_key ] ) )
						? $by_layout_key[ $layout_key ]
						: [];
					$layout['sub_fields'] = self::expand_field_tree( $subs );
					$rebuilt_layouts[]    = $layout;
				}
				$field['layouts'] = $rebuilt_layouts;
			}
			$expanded[] = $field;
		}
		return $expanded;
	}

	private static function strip_nested_containers( array $fields ) {
		$stripped = [];
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				$stripped[] = $field;
				continue;
			}
			$type = isset( $field['type'] ) ? (string) $field['type'] : '';
			if ( in_array( $type, [ 'repeater', 'group', 'clone' ], true ) ) {
				unset( $field['sub_fields'] );
			} elseif ( $type === 'flexible_content' && isset( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				$cleaned_layouts = [];
				foreach ( $field['layouts'] as $layout ) {
					if ( is_array( $layout ) ) {
						unset( $layout['sub_fields'] );
					}
					$cleaned_layouts[] = $layout;
				}
				$field['layouts'] = $cleaned_layouts;
			}
			$stripped[] = $field;
		}
		return $stripped;
	}

	private static function derive_args_schema( array $defaults ) {
		$schema = [];
		foreach ( $defaults as $key => $value ) {
			if ( is_bool( $value ) ) {
				$schema[ (string) $key ] = 'boolean';
			} elseif ( is_int( $value ) ) {
				$schema[ (string) $key ] = 'integer';
			} elseif ( is_float( $value ) ) {
				$schema[ (string) $key ] = 'number';
			} elseif ( is_array( $value ) ) {
				$schema[ (string) $key ] = 'array';
			} elseif ( is_null( $value ) ) {
				$schema[ (string) $key ] = 'null';
			} else {
				$schema[ (string) $key ] = 'string';
			}
		}
		return $schema;
	}

	private static function build_field_types_envelope( array $payload ) {
		$count       = isset( $payload['total_count'] ) ? (int) $payload['total_count'] : 0;
		$cached_flag = ! empty( $payload['cached'] );
		return Envelope::success(
			sprintf(
				'acf_list_supported_field_types: %d type%s%s.',
				$count,
				$count === 1 ? '' : 's',
				$cached_flag ? ' (cached)' : ''
			),
			[
				'field_types'    => isset( $payload['field_types'] ) ? $payload['field_types'] : [],
				'total_count'    => $count,
				'pro_only_count' => isset( $payload['pro_only_count'] ) ? (int) $payload['pro_only_count'] : 0,
				'acf_pro_active' => ! empty( $payload['acf_pro_active'] ),
				'acf_version'    => isset( $payload['acf_version'] ) ? (string) $payload['acf_version'] : '',
				'cached'         => $cached_flag,
			]
		);
	}

	private static function deep_regen_keys( array $field, $order_index, array &$key_map = [] ) {
		$out = $field;
		unset( $out['ID'], $out['parent'] );
		$old_key = isset( $out['key'] ) ? (string) $out['key'] : '';
		$out['key']        = self::generate_field_key();
		$out['menu_order'] = (int) $order_index;
		if ( $old_key !== '' ) {
			$key_map[ $old_key ] = $out['key'];
		}

		if ( isset( $out['sub_fields'] ) && is_array( $out['sub_fields'] ) ) {
			$regen = [];
			foreach ( array_values( $out['sub_fields'] ) as $s_idx => $sub ) {
				$regen[] = self::deep_regen_keys( (array) $sub, $s_idx, $key_map );
			}
			$out['sub_fields'] = $regen;
		}

		if ( isset( $out['layouts'] ) && is_array( $out['layouts'] ) ) {
			$regen_layouts = [];
			foreach ( array_values( $out['layouts'] ) as $l_idx => $layout ) {
				$layout = (array) $layout;
				if ( isset( $layout['key'] ) ) {
					$layout['key'] = 'layout_' . bin2hex( random_bytes( 6 ) );
				}
				if ( isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
					$regen_subs = [];
					foreach ( array_values( $layout['sub_fields'] ) as $s_idx => $sub ) {
						$regen_subs[] = self::deep_regen_keys( (array) $sub, $s_idx, $key_map );
					}
					$layout['sub_fields'] = $regen_subs;
				}
				$regen_layouts[] = $layout;
			}
			$out['layouts'] = $regen_layouts;
		}
		return $out;
	}

	private static function remap_conditional_logic( array $field, array $key_map ) {
		if ( isset( $field['conditional_logic'] ) && is_array( $field['conditional_logic'] ) ) {
			$rewritten_groups = [];
			foreach ( $field['conditional_logic'] as $or_group ) {
				if ( ! is_array( $or_group ) ) {
					$rewritten_groups[] = $or_group;
					continue;
				}
				$rewritten_rules = [];
				foreach ( $or_group as $rule ) {
					if ( is_array( $rule ) && isset( $rule['field'] ) && isset( $key_map[ (string) $rule['field'] ] ) ) {
						$rule['field'] = $key_map[ (string) $rule['field'] ];
					}
					$rewritten_rules[] = $rule;
				}
				$rewritten_groups[] = $rewritten_rules;
			}
			$field['conditional_logic'] = $rewritten_groups;
		}

		if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
			$rewritten_subs = [];
			foreach ( $field['sub_fields'] as $sub ) {
				$rewritten_subs[] = self::remap_conditional_logic( (array) $sub, $key_map );
			}
			$field['sub_fields'] = $rewritten_subs;
		}

		if ( isset( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
			$rewritten_layouts = [];
			foreach ( $field['layouts'] as $layout ) {
				$layout = (array) $layout;
				if ( isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
					$rewritten_layout_subs = [];
					foreach ( $layout['sub_fields'] as $sub ) {
						$rewritten_layout_subs[] = self::remap_conditional_logic( (array) $sub, $key_map );
					}
					$layout['sub_fields'] = $rewritten_layout_subs;
				}
				$rewritten_layouts[] = $layout;
			}
			$field['layouts'] = $rewritten_layouts;
		}
		return $field;
	}

	private static function allowed_enum_for( $key ) {
		switch ( $key ) {
			case 'position':              return self::GROUP_POSITIONS;
			case 'style':                 return self::GROUP_STYLES;
			case 'label_placement':       return self::LABEL_PLACEMENTS;
			case 'instruction_placement': return self::INSTRUCTION_PLACEMENTS;
		}
		return [];
	}

	private static function reserved_post_type_keys() {
		return [
			'post',
			'page',
			'attachment',
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
		];
	}

	private static function allowed_post_type_supports() {
		return [
			'title',
			'editor',
			'thumbnail',
			'excerpt',
			'author',
			'comments',
			'trackbacks',
			'custom-fields',
			'revisions',
			'page-attributes',
			'post-formats',
		];
	}
}
