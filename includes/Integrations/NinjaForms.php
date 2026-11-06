<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Form_Entry_Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ninja Forms: forms, their fields, and submissions, read-only.
 *
 * Administrators only. Personal fields in submissions are masked unless the
 * site opts in with the royal_mcp_form_entry_fields filter. Action settings
 * (email recipients, add-on keys) are never returned, and nothing exports,
 * changes or deletes submissions.
 */
class NinjaForms {

	/** Field types that take no input. */
	const NON_INPUT_TYPES = [ 'submit', 'html', 'hr', 'note', 'spam', 'recaptcha', 'recaptcha_v3', 'hcaptcha', 'turnstile', 'button', 'confirm', 'unknown' ];

	/** Form settings returned by ninjaforms_get_form. */
	const FORM_SETTINGS = [ 'show_title', 'clear_complete', 'hide_complete', 'logged_in', 'default_label_pos', 'currency', 'sub_limit_number' ];

	const PLUGIN = 'ninja-forms';

	public static function is_available() {
		return function_exists( 'Ninja_Forms' ) && class_exists( '\NF_Database_Models_Submission' );
	}

	private static function forms_cap() {
		$cap = apply_filters( 'ninja_forms_admin_all_forms_capabilities', 'manage_options' );
		return is_string( $cap ) && '' !== $cap ? $cap : 'manage_options';
	}

	private static function entries_cap() {
		$cap = apply_filters( 'ninja_forms_admin_submissions_capabilities', 'manage_options' );
		return is_string( $cap ) && '' !== $cap ? $cap : 'manage_options';
	}

	public static function get_tools() {
		$masking = ' Personal fields (email, phone, names, address, hidden, password, file, signature, payment, repeater, fields Ninja Forms marks as personal data, and fields whose key, label or placeholder names personal data) come back with value null and masked: true; email addresses and phone numbers in other text are hidden and user_id is null; the site can opt in to more with the royal_mcp_form_entry_fields filter. Administrators only.';
		return [
			[
				'name'        => 'ninjaforms_list_forms',
				'description' => 'List Ninja Forms forms: form_id, title, field count, submission count and creation date. Supports limit + offset.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'  => [ 'type' => 'integer', 'description' => 'Max forms to return (default 50, max 200).' ],
						'offset' => [ 'type' => 'integer', 'description' => 'Row offset for pagination (default 0).' ],
					],
				],
			],
			[
				'name'        => 'ninjaforms_get_form',
				'description' => 'Get one Ninja Forms form: title, display settings, fields (id, name, label, type, required, personal-data flag, choices) and actions (type, label, active). Action settings such as email recipients and add-on keys are not returned.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'form_id' => [ 'type' => 'integer', 'description' => 'Ninja Forms form ID.' ],
					],
					'required'   => [ 'form_id' ],
				],
			],
			[
				'name'        => 'ninjaforms_count_entries',
				'description' => 'Count a Ninja Forms form\'s submissions: total, in the trash, and when the last one came in. No field values.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'form_id' => [ 'type' => 'integer', 'description' => 'Ninja Forms form ID.' ],
					],
					'required'   => [ 'form_id' ],
				],
			],
			[
				'name'        => 'ninjaforms_list_entries',
				'description' => 'List a Ninja Forms form\'s submissions, newest first: entry_id, sequence number, date, status, user_id and fields (name, label, type, value).' . $masking,
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'form_id' => [ 'type' => 'integer', 'description' => 'Ninja Forms form ID.' ],
						'limit'   => [ 'type' => 'integer', 'description' => 'Max submissions to return (default 20, max 100).' ],
						'offset'  => [ 'type' => 'integer', 'description' => 'Row offset for pagination (default 0).' ],
						'status'  => [ 'type' => 'string', 'enum' => [ 'publish', 'trash' ], 'description' => 'publish (default) or trash.' ],
					],
					'required'   => [ 'form_id' ],
				],
			],
			[
				'name'        => 'ninjaforms_get_entry',
				'description' => 'Get one Ninja Forms submission by entry ID: form, sequence number, date, status, user_id and fields.' . $masking,
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'entry_id' => [ 'type' => 'integer', 'description' => 'Submission ID.' ],
					],
					'required'   => [ 'entry_id' ],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		$args = is_array( $args ) ? $args : [];
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use Ninja Forms tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Ninja Forms is not active' );
		}
		$entries = in_array( $name, [ 'ninjaforms_count_entries', 'ninjaforms_list_entries', 'ninjaforms_get_entry' ], true );
		if ( ! current_user_can( $entries ? self::entries_cap() : self::forms_cap() ) ) {
			throw new \Exception( 'You do not have permission to use Ninja Forms tools.' );
		}

		switch ( $name ) {
			case 'ninjaforms_list_forms':
				return self::list_forms( $args );
			case 'ninjaforms_get_form':
				return self::get_form( $args );
			case 'ninjaforms_count_entries':
				return self::count_entries( $args );
			case 'ninjaforms_list_entries':
				return self::list_entries( $args );
			case 'ninjaforms_get_entry':
				return self::get_entry( $args );
			default:
				throw new \Exception( 'Unknown Ninja Forms tool: ' . esc_html( $name ) );
		}
	}

	private static function list_forms( array $args ) {
		global $wpdb;
		$limit  = min( max( self::num( $args, 'limit', 50 ), 1 ), 200 );
		$offset = max( self::num( $args, 'offset', 0 ), 0 );
		$forms  = $wpdb->prefix . 'nf3_forms';
		$fields = $wpdb->prefix . 'nf3_fields';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, created_at FROM {$forms} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$forms}" );
		$ids   = array_map( 'intval', wp_list_pluck( (array) $rows, 'id' ) );
		$field_counts = [];
		$sub_counts   = [];
		if ( $ids ) {
			$in = implode( ',', $ids );
			foreach ( (array) $wpdb->get_results( "SELECT parent_id, COUNT(*) AS n FROM {$fields} WHERE parent_id IN ({$in}) GROUP BY parent_id", ARRAY_A ) as $r ) {
				$field_counts[ (int) $r['parent_id'] ] = (int) $r['n'];
			}
			foreach ( (array) $wpdb->get_results( "SELECT pm.meta_value AS form_id, COUNT(*) AS n FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_form_id' AND pm.meta_value IN ({$in}) AND p.post_type = 'nf_sub' AND p.post_status = 'publish' GROUP BY pm.meta_value", ARRAY_A ) as $r ) {
				$sub_counts[ (int) $r['form_id'] ] = (int) $r['n'];
			}
		}
		// phpcs:enable
		$out = [];
		foreach ( (array) $rows as $r ) {
			$id    = (int) $r['id'];
			$out[] = [
				'form_id'          => $id,
				'title'            => (string) $r['title'],
				'field_count'      => $field_counts[ $id ] ?? 0,
				'submission_count' => $sub_counts[ $id ] ?? 0,
				'created_at_iso'   => Form_Entry_Privacy::iso_local( $r['created_at'] ?? '' ),
			];
		}
		return [ 'limit' => $limit, 'offset' => $offset, 'count' => count( $out ), 'total' => $total, 'forms' => $out ];
	}

	private static function get_form( array $args ) {
		$id   = self::form_id( $args );
		$form = Ninja_Forms()->form( $id )->get();
		$settings = [];
		foreach ( self::FORM_SETTINGS as $key ) {
			$v = $form->get_setting( $key );
			if ( false !== $v && null !== $v && ! is_array( $v ) && ! is_object( $v ) ) {
				$settings[ $key ] = $v;
			}
		}
		$fields = [];
		foreach ( self::fields( $id ) as $f ) {
			unset( $f['_also'] );
			$fields[] = $f;
		}
		$actions = [];
		foreach ( (array) Ninja_Forms()->form( $id )->get_actions() as $action ) {
			if ( ! is_object( $action ) ) {
				continue;
			}
			$actions[] = [
				'id'     => (int) $action->get_id(),
				'type'   => (string) $action->get_setting( 'type' ),
				'label'  => (string) $action->get_setting( 'label' ),
				'active' => self::truthy( $action->get_setting( 'active' ) ),
			];
		}
		return [
			'form_id'  => $id,
			'title'    => (string) $form->get_setting( 'title' ),
			'settings' => $settings,
			'fields'   => $fields,
			'actions'  => $actions,
		];
	}

	private static function count_entries( array $args ) {
		$id    = self::form_id( $args );
		$count = static function ( $status ) use ( $id ) {
			$q = new \WP_Query( [
				'post_type'      => 'nf_sub',
				'post_status'    => $status,
				'meta_key'       => '_form_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => false,
				'suppress_filters' => true,
			] );
			return [ (int) $q->found_posts, $q->posts ? (int) $q->posts[0] : 0 ];
		};
		list( $total, $last_id ) = $count( 'publish' );
		list( $trashed )         = $count( 'trash' );
		$last = $last_id ? get_post( $last_id ) : null;
		return [
			'form_id'               => $id,
			'total'                 => $total,
			'trashed'               => $trashed,
			'last_submitted_at_iso' => $last ? Form_Entry_Privacy::iso( $last->post_date_gmt ) : null,
		];
	}

	private static function list_entries( array $args ) {
		$id     = self::form_id( $args );
		$limit  = min( max( self::num( $args, 'limit', 20 ), 1 ), 100 );
		$offset = max( self::num( $args, 'offset', 0 ), 0 );
		$status = $args['status'] ?? 'publish';
		if ( ! is_string( $status ) || ! in_array( $status, [ 'publish', 'trash' ], true ) ) {
			throw new \Exception( 'status must be publish or trash.' );
		}
		$q = new \WP_Query( [
			'post_type'        => 'nf_sub',
			'post_status'      => $status,
			'meta_key'         => '_form_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'       => $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'posts_per_page'   => $limit,
			'offset'           => $offset,
			'orderby'          => [ 'date' => 'DESC', 'ID' => 'DESC' ],
			'suppress_filters' => true,
		] );
		$fields = self::fields( $id );
		$reveal = Form_Entry_Privacy::reveal_list( self::PLUGIN, $id, 'list' );
		$out    = [];
		foreach ( $q->posts as $post ) {
			$out[] = self::format_entry( $post, $id, $fields, $reveal );
		}
		return [ 'form_id' => $id, 'status' => $status, 'limit' => $limit, 'offset' => $offset, 'count' => count( $out ), 'total' => (int) $q->found_posts, 'entries' => $out ];
	}

	private static function get_entry( array $args ) {
		$entry_id = Form_Entry_Privacy::positive_int( $args['entry_id'] ?? null );
		$post     = $entry_id > 0 ? get_post( $entry_id ) : null;
		$form_id  = $post && 'nf_sub' === $post->post_type ? (int) get_post_meta( $post->ID, '_form_id', true ) : 0;
		if ( ! $post || 'nf_sub' !== $post->post_type || ! in_array( $post->post_status, [ 'publish', 'trash' ], true ) || ! self::form_exists( $form_id ) ) {
			throw new \Exception( 'Ninja Forms entry not found.' );
		}
		$reveal = Form_Entry_Privacy::reveal_list( self::PLUGIN, $form_id, 'single' );
		return [ 'form_id' => $form_id ] + self::format_entry( $post, $form_id, self::fields( $form_id ), $reveal );
	}

	private static function format_entry( \WP_Post $post, $form_id, array $fields, array $reveal ) {
		$rows = [];
		foreach ( $fields as $f ) {
			if ( ! $f['is_input'] ) {
				continue;
			}
			$raw = get_post_meta( $post->ID, '_field_' . $f['id'], true );
			if ( class_exists( '\WPN_Helper' ) && method_exists( '\WPN_Helper', 'decode_submission_value' ) ) {
				$raw = \WPN_Helper::decode_submission_value( $raw );
			}
			$rows[] = Form_Entry_Privacy::field( $f['name'], $f['label'], $f['type'], $raw, $reveal, $f['personally_identifiable'], $f['_also'] );
		}
		return [
			'entry_id'         => (int) $post->ID,
			'seq_num'          => (int) get_post_meta( $post->ID, '_seq_num', true ),
			'status'           => (string) $post->post_status,
			'user_id'          => Form_Entry_Privacy::user_id( $post->post_author, $reveal ),
			'submitted_at_iso' => Form_Entry_Privacy::iso( $post->post_date_gmt ),
			'fields'           => $rows,
		];
	}

	/** A form's fields as returned, in form order. */
	private static function fields( $form_id ) {
		$out = [];
		foreach ( (array) Ninja_Forms()->form( $form_id )->get_fields() as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}
			$type    = (string) $field->get_setting( 'type' );
			$choices = [];
			$options = $field->get_setting( 'options' );
			if ( is_array( $options ) ) {
				foreach ( $options as $o ) {
					if ( is_array( $o ) ) {
						$choices[] = [ 'label' => (string) ( $o['label'] ?? '' ), 'value' => (string) ( $o['value'] ?? '' ) ];
					}
				}
			}
			$out[] = [
				'id'                      => (int) $field->get_id(),
				'name'                    => (string) $field->get_setting( 'key' ),
				'label'                   => (string) $field->get_setting( 'label' ),
				'type'                    => $type,
				'required'                => self::truthy( $field->get_setting( 'required' ) ),
				'personally_identifiable' => self::truthy( $field->get_setting( 'personally_identifiable' ) ),
				'is_input'                => ! in_array( $type, self::NON_INPUT_TYPES, true ),
				'choices'                 => Form_Entry_Privacy::form_text( $choices ),
				'_also'                   => [ (string) $field->get_setting( 'admin_label' ), (string) $field->get_setting( 'placeholder' ) ],
				'order'                   => (int) $field->get_setting( 'order' ),
			];
		}
		usort( $out, static function ( $a, $b ) {
			return [ $a['order'], $a['id'] ] <=> [ $b['order'], $b['id'] ];
		} );
		return $out;
	}

	/** A Ninja Forms checkbox setting as a boolean. */
	private static function truthy( $v ) {
		return true === $v || 1 === $v || ( is_string( $v ) && in_array( strtolower( $v ), [ '1', 'on', 'yes', 'true' ], true ) );
	}

	private static function form_id( array $args ) {
		$id = Form_Entry_Privacy::positive_int( $args['form_id'] ?? null );
		if ( ! self::form_exists( $id ) ) {
			throw new \Exception( 'Ninja Forms form not found.' );
		}
		return $id;
	}

	private static function form_exists( $id ) {
		global $wpdb;
		if ( $id <= 0 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}nf3_forms WHERE id = %d", $id ) );
	}

	private static function num( array $args, $key, $default ) {
		if ( ! isset( $args[ $key ] ) ) {
			return $default;
		}
		$v = $args[ $key ];
		if ( ! is_int( $v ) && ! ( is_string( $v ) && preg_match( '/^-?\d{1,9}$/', $v ) ) ) {
			throw new \Exception( esc_html( $key ) . ' must be a whole number.' );
		}
		return (int) $v;
	}
}

/**
 * Manifest declaration. Read-only.
 */
add_filter( 'royal_mcp_manifests', function ( $manifests ) {
	if ( ! function_exists( 'Ninja_Forms' ) || ! class_exists( '\Ninja_Forms' ) ) {
		return $manifests;
	}
	$manifests[] = [
		'royal_mcp_manifest_version' => '1.0',
		'plugin_slug'                => 'ninja-forms',
		'plugin_display_name'        => 'Ninja Forms',
		'plugin_version'             => defined( 'Ninja_Forms::VERSION' ) ? \Ninja_Forms::VERSION : '',
		'vendor_name'                => 'Saturday Drive',
		'mcp_endpoint'               => rest_url( 'royal-mcp/v1/mcp' ),
		'auth_methods'               => [ 'oauth2.1' ],
		'capabilities'               => [ 'read' ],
		'manifest_updated_at'        => gmdate( 'c' ),
		'trust_signals'              => [
			'supports_dry_run'                => false,
			'supports_undo'                   => false,
			'supports_snapshots'              => false,
			'requires_review_for_destructive' => false,
		],
	];
	return $manifests;
} );
