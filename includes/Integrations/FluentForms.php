<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Form_Entry_Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fluent Forms: forms, their fields, and entries, read-only.
 *
 * Administrators only, and Fluent Forms' own per-form access rules apply on
 * top. Personal fields are masked and submitter details (IP address,
 * browser, device, city, country) and payment details are left out unless
 * the site opts in with the royal_mcp_form_entry_fields filter. Form
 * notification and integration settings are never returned.
 */
class FluentForms {

	/** Elements that take no input. */
	const NON_INPUT = [ 'container', 'section_break', 'custom_html', 'shortcode', 'action_hook', 'form_step', 'recaptcha', 'hcaptcha', 'turnstile', 'save_progress_button', 'custom_submit_button', 'accordion' ];

	/** Entry statuses a caller may filter by. */
	const STATUSES = [ 'unread', 'read', 'trashed', 'spam', 'favorites', 'all' ];

	/** Entry columns about the submitter, left out unless a site opts in. */
	const SUBMITTER_COLUMNS = [ 'ip', 'browser', 'device', 'city', 'country' ];

	/** Entry columns about payments, left out unless a site opts in to 'payment'. */
	const PAYMENT_COLUMNS = [ 'payment_status', 'payment_method', 'payment_type', 'currency', 'payment_total', 'total_paid' ];

	const PLUGIN = 'fluentform';

	public static function is_available() {
		return defined( 'FLUENTFORM_VERSION' ) && defined( 'FLUENTFORM' );
	}

	public static function get_tools() {
		$masking = ' Personal fields (email, phone, names, address, hidden, password, file, signature, payment, repeater, and fields whose name, label or placeholder names personal data) come back with value null and masked: true; email addresses and phone numbers in other text are hidden; IP address, browser, device, location and payment details are left out and user_id is null. The site can opt in to more with the royal_mcp_form_entry_fields filter. Administrators only.';
		return [
			[
				'name'        => 'fluentform_list_forms',
				'description' => 'List Fluent Forms forms: form_id, title, status, type, whether it takes payments, field count, entry count and creation date. Supports limit + offset.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'  => [ 'type' => 'integer', 'description' => 'Max forms to return (default 50, max 200).' ],
						'offset' => [ 'type' => 'integer', 'description' => 'Row offset for pagination (default 0).' ],
					],
				],
			],
			[
				'name'        => 'fluentform_get_form',
				'description' => 'Get one Fluent Forms form: title, status, type and its input fields (name, element, label, required, choices). Notification, confirmation and integration settings are not returned.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'form_id' => [ 'type' => 'integer', 'description' => 'Fluent Forms form ID.' ],
					],
					'required'   => [ 'form_id' ],
				],
			],
			[
				'name'        => 'fluentform_count_entries',
				'description' => 'Count a Fluent Forms form\'s entries by status (unread, read, spam, trashed) and say when the last one came in. No field values.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'form_id' => [ 'type' => 'integer', 'description' => 'Fluent Forms form ID.' ],
					],
					'required'   => [ 'form_id' ],
				],
			],
			[
				'name'        => 'fluentform_list_entries',
				'description' => 'List a Fluent Forms form\'s entries, newest first: entry_id, sequence number (seq_num), status, date, user_id, page the form was sent from (without query string) and fields (name, label, type, value).' . $masking,
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'form_id' => [ 'type' => 'integer', 'description' => 'Fluent Forms form ID.' ],
						'limit'   => [ 'type' => 'integer', 'description' => 'Max entries to return (default 20, max 100).' ],
						'offset'  => [ 'type' => 'integer', 'description' => 'Row offset for pagination (default 0).' ],
						'status'  => [ 'type' => 'string', 'enum' => self::STATUSES, 'description' => 'Default all: every entry except trashed.' ],
					],
					'required'   => [ 'form_id' ],
				],
			],
			[
				'name'        => 'fluentform_get_entry',
				'description' => 'Get one Fluent Forms entry by entry ID: form, sequence number (seq_num), status, date, user_id, page the form was sent from and fields.' . $masking,
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'entry_id' => [ 'type' => 'integer', 'description' => 'Entry ID.' ],
					],
					'required'   => [ 'entry_id' ],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		$args = is_array( $args ) ? $args : [];
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use Fluent Forms tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Fluent Forms is not active' );
		}

		switch ( $name ) {
			case 'fluentform_list_forms':
				return self::list_forms( $args );
			case 'fluentform_get_form':
				return self::get_form( $args );
			case 'fluentform_count_entries':
				return self::count_entries( $args );
			case 'fluentform_list_entries':
				return self::list_entries( $args );
			case 'fluentform_get_entry':
				return self::get_entry( $args );
			default:
				throw new \Exception( 'Unknown Fluent Forms tool: ' . esc_html( $name ) );
		}
	}

	private static function list_forms( array $args ) {
		global $wpdb;
		$limit  = min( max( self::num( $args, 'limit', 50 ), 1 ), 200 );
		$offset = max( self::num( $args, 'offset', 0 ), 0 );
		$forms  = $wpdb->prefix . 'fluentform_forms';
		$subs   = $wpdb->prefix . 'fluentform_submissions';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, status, type, has_payment, form_fields, created_at FROM {$forms} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset ) );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$forms}" );
		$counts = [];
		$ids    = array_map( 'intval', wp_list_pluck( (array) $rows, 'id' ) );
		if ( $ids ) {
			$in = implode( ',', $ids );
			foreach ( (array) $wpdb->get_results( "SELECT form_id, COUNT(*) AS n FROM {$subs} WHERE form_id IN ({$in}) AND status != 'trashed' GROUP BY form_id", ARRAY_A ) as $r ) {
				$counts[ (int) $r['form_id'] ] = (int) $r['n'];
			}
		}
		// phpcs:enable
		$out = [];
		foreach ( (array) $rows as $r ) {
			if ( ! self::may_read_form( (int) $r->id ) ) {
				continue;
			}
			$out[] = [
				'form_id'        => (int) $r->id,
				'title'          => (string) $r->title,
				'status'         => (string) $r->status,
				'type'           => (string) $r->type,
				'has_payment'    => (bool) $r->has_payment,
				'field_count'    => count( self::inputs( $r ) ),
				'entry_count'    => $counts[ (int) $r->id ] ?? 0,
				'created_at_iso' => Form_Entry_Privacy::iso_local( $r->created_at ),
			];
		}
		return [ 'limit' => $limit, 'offset' => $offset, 'count' => count( $out ), 'total' => $total, 'forms' => $out ];
	}

	private static function get_form( array $args ) {
		$form = self::form( Form_Entry_Privacy::positive_int( $args['form_id'] ?? null ), 'fluentform_forms_manager' );
		return [
			'form_id'     => (int) $form->id,
			'title'       => (string) $form->title,
			'status'      => (string) $form->status,
			'type'        => (string) $form->type,
			'has_payment' => (bool) $form->has_payment,
			'fields'      => array_values( array_map( static function ( $f ) {
				unset( $f['_also'], $f['_cleared'] );
				return $f;
			}, self::inputs( $form ) ) ),
		];
	}

	private static function count_entries( array $args ) {
		global $wpdb;
		$form = self::form( Form_Entry_Privacy::positive_int( $args['form_id'] ?? null ), 'fluentform_entries_viewer' );
		$subs = $wpdb->prefix . 'fluentform_submissions';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$subs} WHERE form_id = %d GROUP BY status", $form->id ), ARRAY_A );
		$last = $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$subs} WHERE form_id = %d AND status != 'trashed' ORDER BY id DESC LIMIT 1", $form->id ) );
		// phpcs:enable
		$by = [];
		$total = 0;
		foreach ( (array) $rows as $r ) {
			$by[ (string) $r['status'] ] = (int) $r['n'];
			if ( 'trashed' !== $r['status'] ) {
				$total += (int) $r['n'];
			}
		}
		return [
			'form_id'               => (int) $form->id,
			'total'                 => $total,
			'by_status'             => $by,
			'last_submitted_at_iso' => $last ? Form_Entry_Privacy::iso_local( $last ) : null,
		];
	}

	private static function list_entries( array $args ) {
		global $wpdb;
		$form   = self::form( Form_Entry_Privacy::positive_int( $args['form_id'] ?? null ), 'fluentform_entries_viewer' );
		$limit  = min( max( self::num( $args, 'limit', 20 ), 1 ), 100 );
		$offset = max( self::num( $args, 'offset', 0 ), 0 );
		$status = $args['status'] ?? 'all';
		if ( ! is_string( $status ) || ! in_array( $status, self::STATUSES, true ) ) {
			throw new \Exception( 'status must be one of: ' . implode( ', ', self::STATUSES ) . '.' );
		}
		$subs = $wpdb->prefix . 'fluentform_submissions';
		if ( 'all' === $status ) {
			$where = $wpdb->prepare( 'form_id = %d AND status != %s', $form->id, 'trashed' );
		} elseif ( 'favorites' === $status ) {
			$where = $wpdb->prepare( 'form_id = %d AND is_favourite = 1 AND status != %s', $form->id, 'trashed' );
		} else {
			$where = $wpdb->prepare( 'form_id = %d AND status = %s', $form->id, $status );
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$subs} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ) );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs} WHERE {$where}" );
		// phpcs:enable
		$inputs = self::inputs( $form );
		$reveal = Form_Entry_Privacy::reveal_list( self::PLUGIN, (int) $form->id, 'list' );
		$out    = [];
		foreach ( (array) $rows as $row ) {
			$out[] = self::format_entry( $row, $inputs, $reveal );
		}
		return [ 'form_id' => (int) $form->id, 'status' => $status, 'limit' => $limit, 'offset' => $offset, 'count' => count( $out ), 'total' => $total, 'entries' => $out ];
	}

	private static function get_entry( array $args ) {
		global $wpdb;
		$entry_id = Form_Entry_Privacy::positive_int( $args['entry_id'] ?? null );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $entry_id > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fluentform_submissions WHERE id = %d", $entry_id ) ) : null;
		if ( ! $row ) {
			throw new \Exception( 'Fluent Forms entry not found.' );
		}
		try {
			$form = self::form( (int) $row->form_id, 'fluentform_entries_viewer' );
		} catch ( \Exception $e ) {
			throw new \Exception( 'Fluent Forms entry not found.' );
		}
		$reveal = Form_Entry_Privacy::reveal_list( self::PLUGIN, (int) $form->id, 'single' );
		return [ 'form_id' => (int) $form->id ] + self::format_entry( $row, self::inputs( $form ), $reveal );
	}

	private static function format_entry( $row, array $inputs, array $reveal ) {
		$response = json_decode( (string) $row->response, true );
		$response = is_array( $response ) ? $response : [];
		$fields   = [];
		foreach ( $inputs as $name => $input ) {
			if ( ! array_key_exists( $name, $response ) ) {
				continue;
			}
			$fields[] = Form_Entry_Privacy::field( $name, $input['label'], $input['element'], $response[ $name ], $reveal, false, $input['_also'], $input['_cleared'] );
			unset( $response[ $name ] );
		}
		// Values the form no longer lists: their type is unknown, so they are masked.
		foreach ( $response as $name => $value ) {
			if ( '' === (string) $name || '_' === substr( (string) $name, 0, 1 ) ) {
				continue;
			}
			$fields[] = Form_Entry_Privacy::field( (string) $name, '', 'unknown', $value, $reveal );
		}
		$source = (string) ( $row->source_url ?? '' );
		if ( '' !== $source ) {
			$parts  = wp_parse_url( $source );
			$source = is_array( $parts ) && ! empty( $parts['host'] )
				? ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . ( $parts['path'] ?? '/' )
				: '';
		}
		$out = [
			'entry_id'         => (int) $row->id,
			'seq_num'          => (int) $row->serial_number,
			'status'           => (string) $row->status,
			'is_favourite'     => ! empty( $row->is_favourite ),
			'user_id'          => Form_Entry_Privacy::user_id( $row->user_id, $reveal ),
			'source_url'       => '' !== $source ? esc_url_raw( $source ) : null,
			'submitted_at_iso' => Form_Entry_Privacy::iso_local( $row->created_at ),
			'fields'           => $fields,
		];
		$submitter = [];
		foreach ( self::SUBMITTER_COLUMNS as $col ) {
			$submitter[ $col ] = isset( $row->$col ) ? (string) $row->$col : null;
		}
		$shown = Form_Entry_Privacy::submitter( $submitter, $reveal );
		if ( $shown ) {
			$out['submitter'] = $shown;
		}
		if ( Form_Entry_Privacy::reveals( $reveal, 'payment' ) ) {
			foreach ( self::PAYMENT_COLUMNS as $col ) {
				$out['payment'][ $col ] = $row->$col ?? null;
			}
		}
		return $out;
	}

	/** Input fields of a form keyed by input name, in form order. */
	private static function inputs( $form ) {
		$data   = json_decode( (string) $form->form_fields, true );
		$fields = is_array( $data ) && isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : [];
		$out    = [];
		self::walk( $fields, $out, 0 );
		return $out;
	}

	private static function walk( array $fields, array &$out, $depth ) {
		if ( $depth > 8 ) {
			return;
		}
		foreach ( $fields as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$element = (string) ( $f['element'] ?? '' );
			// Layout elements with columns hold their inputs inside; a repeater container is one input of rows.
			if ( 'repeater_container' !== $element && isset( $f['columns'] ) && is_array( $f['columns'] ) ) {
				foreach ( $f['columns'] as $col ) {
					if ( is_array( $col ) && isset( $col['fields'] ) && is_array( $col['fields'] ) ) {
						self::walk( $col['fields'], $out, $depth + 1 );
					}
				}
				continue;
			}
			$name = isset( $f['attributes']['name'] ) && is_scalar( $f['attributes']['name'] ) ? (string) $f['attributes']['name'] : '';
			if ( '' === $name || in_array( $element, self::NON_INPUT, true ) ) {
				continue;
			}
			$texts = self::texts( $f );
			$label = '' !== $texts[0] ? $texts[0] : ( '' !== $texts[1] ? $texts[1] : ( 'input_name' === $element ? 'Name' : '' ) );
			$choices = [];
			$settings = isset( $f['settings'] ) && is_array( $f['settings'] ) ? $f['settings'] : [];
			foreach ( [ 'advanced_options', 'options' ] as $opt_key ) {
				$list = $settings[ $opt_key ] ?? ( $f[ $opt_key ] ?? null );
				if ( is_array( $list ) ) {
					foreach ( $list as $k => $o ) {
						$choices[] = is_array( $o )
							? [ 'label' => wp_strip_all_tags( (string) ( $o['label'] ?? '' ) ), 'value' => (string) ( $o['value'] ?? '' ) ]
							: [ 'label' => wp_strip_all_tags( (string) $o ), 'value' => (string) $k ];
					}
					break;
				}
			}
			$cleared = false;
			if ( 'repeater_container' === $element || 'repeater_field' === $element ) {
				$cleared = self::rows_have_no_personal_column( $f, $depth );
			} elseif ( 'tabular_grid' === $element ) {
				// A grid's cells are choices the form defines, not typed-in text.
				$cleared = true;
			}
			$out[ $name ] = [
				'name'      => $name,
				'element'   => $element,
				'label'     => wp_strip_all_tags( $label ),
				'required'  => ! empty( $settings['validation_rules']['required']['value'] ),
				'choices'   => \Royal_MCP\MCP\Support\Form_Entry_Privacy::form_text( $choices ),
				'_also'     => array_slice( $texts, 1 ),
				'_cleared'  => $cleared,
			];
		}
	}

	/**
	 * A field's label, admin label and placeholder, as plain text.
	 *
	 * @return string[]
	 */
	private static function texts( array $f ) {
		$settings = isset( $f['settings'] ) && is_array( $f['settings'] ) ? $f['settings'] : [];
		$attrs    = isset( $f['attributes'] ) && is_array( $f['attributes'] ) ? $f['attributes'] : [];
		$out      = [];
		foreach ( [ $settings['label'] ?? '', $settings['admin_field_label'] ?? '', $attrs['placeholder'] ?? ( $settings['placeholder'] ?? '' ) ] as $v ) {
			$out[] = is_scalar( $v ) ? trim( wp_strip_all_tags( (string) $v ) ) : '';
		}
		return $out;
	}

	/** Whether every column of a repeater is a known, non-personal field. */
	private static function rows_have_no_personal_column( array $f, $depth ) {
		$children = [];
		if ( isset( $f['fields'] ) && is_array( $f['fields'] ) ) {
			$children = array_merge( $children, array_values( $f['fields'] ) );
		}
		if ( isset( $f['columns'] ) && is_array( $f['columns'] ) ) {
			foreach ( $f['columns'] as $col ) {
				if ( is_array( $col ) && isset( $col['fields'] ) && is_array( $col['fields'] ) ) {
					$children = array_merge( $children, array_values( $col['fields'] ) );
				}
			}
		}
		if ( ! $children || $depth > 8 ) {
			return false;
		}
		foreach ( $children as $c ) {
			if ( ! is_array( $c ) || empty( $c['element'] ) ) {
				return false;
			}
			$texts = self::texts( $c );
			$cname = isset( $c['attributes']['name'] ) && is_scalar( $c['attributes']['name'] ) ? (string) $c['attributes']['name'] : '';
			if ( \Royal_MCP\MCP\Support\Form_Entry_Privacy::is_personal( (string) $c['element'], $cname, $texts[0], false, array_slice( $texts, 1 ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** A form the caller may open, or an exception that does not say whether it exists. */
	private static function form( $id, $permission ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$form = $id > 0 ? $wpdb->get_row( $wpdb->prepare( "SELECT id, title, status, type, has_payment, form_fields FROM {$wpdb->prefix}fluentform_forms WHERE id = %d", $id ) ) : null;
		if ( ! $form || ! self::may_read_form( (int) $form->id, $permission ) ) {
			throw new \Exception( 'Fluent Forms form not found.' );
		}
		return $form;
	}

	/** Fluent Forms' own per-form access rules, when it has them. */
	private static function may_read_form( $form_id, $permission = 'fluentform_forms_manager' ) {
		if ( class_exists( '\FluentForm\App\Modules\Acl\Acl' ) && method_exists( '\FluentForm\App\Modules\Acl\Acl', 'hasPermission' ) ) {
			try {
				return (bool) \FluentForm\App\Modules\Acl\Acl::hasPermission( $permission, (int) $form_id );
			} catch ( \Throwable $e ) {
				return false;
			}
		}
		return true;
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
	if ( ! defined( 'FLUENTFORM_VERSION' ) ) {
		return $manifests;
	}
	$manifests[] = [
		'royal_mcp_manifest_version' => '1.0',
		'plugin_slug'                => 'fluentform',
		'plugin_display_name'        => 'Fluent Forms',
		'plugin_version'             => FLUENTFORM_VERSION,
		'vendor_name'                => 'WPManageNinja',
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
