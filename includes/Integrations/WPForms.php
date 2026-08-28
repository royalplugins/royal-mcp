<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPForms MCP Integration — read-only surface (forms + submissions).
 *
 * Detection: function_exists('wpforms') — defined by both Lite and Pro.
 * Identifier: form_id (integer, wpforms CPT post ID).
 *
 * Submission tools require WPForms Pro (Lite does not persist entries).
 * On Lite, handlers return {state: 'unavailable', reason: 'requires_pro',
 * tier: 'wpforms_pro'} so agents distinguish it from a matched-nothing query.
 */
class WPForms {

	/**
	 * WPForms is present when its canonical global accessor function is
	 * defined. The `wpforms()` function loads during the plugin's earliest
	 * bootstrap and is defined identically by Lite and Pro, so it is the
	 * safest availability probe across tiers and load-order edge cases.
	 */
	public static function is_available() {
		return function_exists( 'wpforms' );
	}

	public static function get_tools() {
		// Always register so tools appear in MCP tools/list regardless of
		// WPForms activation state. Callers see the schemas immediately on
		// connect; execute_tool cleanly refuses with "WPForms is not active"
		// when the underlying plugin isn't loaded. Prevents the ghost-tools UX
		// where activating a plugin post-MCP-connection requires the client to
		// reconnect before the tools become discoverable.
		return [
			[
				'name'        => 'wpforms_list_forms',
				'description' => 'List WPForms forms with metadata. Returns form_id, title, field count, active status, date created. Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'  => [ 'type' => 'integer', 'description' => 'Max forms to return. Default 50, max 100.' ],
						'offset' => [ 'type' => 'integer', 'description' => 'Pagination offset. Default 0.' ],
					],
				],
			],
			[
				'name'        => 'wpforms_get_form',
				'description' => 'Read a single WPForms form\'s full schema: fields, settings, notifications, confirmations. Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'form_id' => [ 'type' => 'integer', 'description' => 'WPForms form ID from wpforms_list_forms.' ],
					],
					'required'   => [ 'form_id' ],
				],
			],
			[
				'name'        => 'wpforms_list_submissions',
				'description' => 'List submissions/entries for a form. Requires WPForms Pro (Lite does not store entries); Lite returns {state: \'unavailable\', reason: \'requires_pro\', tier: \'wpforms_pro\', message: ...}. Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'form_id' => [ 'type' => 'integer', 'description' => 'Form ID to list submissions for.' ],
						'limit'   => [ 'type' => 'integer', 'description' => 'Max submissions. Default 50, max 100.' ],
						'offset'  => [ 'type' => 'integer', 'description' => 'Pagination offset. Default 0.' ],
					],
					'required'   => [ 'form_id' ],
				],
			],
			[
				'name'        => 'wpforms_get_submission',
				'description' => 'Read a single submission with all field values + metadata (submitter IP, timestamp, entry status). Requires WPForms Pro; Lite returns {state: \'unavailable\', reason: \'requires_pro\', tier: \'wpforms_pro\', message: ...}. Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'submission_id' => [ 'type' => 'integer', 'description' => 'Submission/entry ID.' ],
					],
					'required'   => [ 'submission_id' ],
				],
			],
		];
	}

	/**
	 * Coarse permission cap check fires BEFORE the availability check so a
	 * Subscriber-tier OAuth Bearer receives an identical permission error
	 * whether WPForms is installed or not — prevents error-message probing
	 * for integration presence. Form definitions can contain integration
	 * secrets and notification email addresses, and submission entries
	 * often contain user-submitted PII, so even read-only listings require
	 * admin-tier capability.
	 */
	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use WPForms tools.' );
		}

		if ( ! self::is_available() ) {
			throw new \Exception( 'WPForms is not active' );
		}

		switch ( $name ) {
			case 'wpforms_list_forms':
				return self::handle_list_forms( $args );

			case 'wpforms_get_form':
				return self::handle_get_form( $args );

			case 'wpforms_list_submissions':
				return self::handle_list_submissions( $args );

			case 'wpforms_get_submission':
				return self::handle_get_submission( $args );

			default:
				$suffix = strpos( $name, 'wpforms_' ) === 0
					? substr( $name, strlen( 'wpforms_' ) )
					: $name;
				throw new \Exception( 'Unknown WPForms tool: ' . esc_html( (string) $suffix ) );
		}
	}

	// ==================== Handlers ====================

	/**
	 * List WPForms forms with pagination.
	 *
	 * Reads all forms via the form handler's get() no-arg call (returns an
	 * array of WP_Post objects keyed by post ID), sorts reverse-chronological
	 * on post_date so callers see newest first regardless of the underlying
	 * WPForms default ordering, applies offset/limit slicing, and normalizes
	 * each entry to the flat shape documented in the tool schema.
	 *
	 * Empty-state safe — a fresh install with 0 forms returns a valid shape
	 * with an empty forms array so downstream skills can branch on count
	 * without a try/catch.
	 */
	private static function handle_list_forms( $args ) {
		$limit = isset( $args['limit'] ) ? intval( $args['limit'] ) : 50;
		if ( $limit < 1 ) {
			$limit = 50;
		}
		if ( $limit > 100 ) {
			$limit = 100;
		}
		$offset = isset( $args['offset'] ) ? intval( $args['offset'] ) : 0;
		if ( $offset < 0 ) {
			$offset = 0;
		}

		$handler = wpforms()->obj( 'form' );
		if ( ! $handler ) {
			throw new \Exception( 'WPForms form handler is not available.' );
		}

		$forms = $handler->get();
		if ( ! is_array( $forms ) ) {
			$forms = [];
		}

		// The form handler's default ordering is not guaranteed
		// reverse-chronological across WPForms versions, so sort explicitly
		// on post_date DESC to give callers a stable newest-first contract.
		// Tiebreak on ID DESC — same-second-created forms (typical for
		// programmatic seeding, migration imports, CSV bulk-creates) would
		// otherwise fall back to PHP 8+ stable-sort input order, which comes
		// from WPForms's own handler and has no documented ordering
		// guarantee. Without the tiebreak, offset-based pagination can drop
		// or duplicate rows across successive requests on any high-throughput
		// site.
		usort( $forms, function ( $a, $b ) {
			$a_date = isset( $a->post_date ) ? (string) $a->post_date : '';
			$b_date = isset( $b->post_date ) ? (string) $b->post_date : '';
			$cmp    = strcmp( $b_date, $a_date );
			if ( $cmp !== 0 ) {
				return $cmp;
			}
			$a_id = isset( $a->ID ) ? (int) $a->ID : 0;
			$b_id = isset( $b->ID ) ? (int) $b->ID : 0;
			return $b_id <=> $a_id;
		} );

		$entries = [];
		foreach ( $forms as $post ) {
			if ( ! is_object( $post ) ) {
				continue;
			}
			$entries[] = self::format_form_entry( $post );
		}

		$total  = count( $entries );
		$sliced = array_slice( $entries, $offset, $limit );

		return [
			'forms'  => array_values( $sliced ),
			'count'  => count( $sliced ),
			'total'  => $total,
			'offset' => $offset,
			'limit'  => $limit,
		];
	}

	/**
	 * Read a single form's full schema — top-level metadata plus decoded
	 * settings, fields, notifications, and confirmations blocks from the
	 * JSON payload stored in post_content.
	 *
	 * wpforms_decode can return null on malformed JSON; the (array) cast
	 * collapses null → [] so a corrupted form definition returns a valid
	 * empty-shape rather than fataling. Settings/fields/notifications/
	 * confirmations each default to [] when absent from the decoded schema
	 * so callers can always foreach without an isset guard.
	 */
	private static function handle_get_form( $args ) {
		$form_id = self::resolve_form_id( $args );
		$post    = self::ensure_form_exists( $form_id );

		$raw     = isset( $post->post_content ) ? (string) $post->post_content : '';
		$decoded = function_exists( 'wpforms_decode' ) ? wpforms_decode( $raw ) : null;
		$decoded = (array) ( $decoded ?? [] );

		$settings      = isset( $decoded['settings'] ) && is_array( $decoded['settings'] ) ? $decoded['settings'] : [];
		$fields        = isset( $decoded['fields'] ) && is_array( $decoded['fields'] ) ? $decoded['fields'] : [];
		$notifications = isset( $settings['notifications'] ) && is_array( $settings['notifications'] ) ? $settings['notifications'] : [];
		$confirmations = isset( $settings['confirmations'] ) && is_array( $settings['confirmations'] ) ? $settings['confirmations'] : [];

		$entry = self::format_form_entry( $post );

		return [
			'form_id'         => $entry['form_id'],
			'title'           => $entry['title'],
			'is_active'       => $entry['is_active'],
			'created_at'      => $entry['created_at'],
			'created_at_iso'  => $entry['created_at_iso'],
			'modified_at'     => $entry['modified_at'],
			'modified_at_iso' => $entry['modified_at_iso'],
			'settings'        => $settings,
			'fields'          => array_map( [ self::class, 'format_field' ], array_values( $fields ) ),
			'notifications'   => $notifications,
			'confirmations'   => $confirmations,
		];
	}

	/**
	 * Normalize a single WP_Post (post_type=wpforms) to the flat entry shape
	 * documented in the wpforms_list_forms schema.
	 *
	 * Field count is derived from the decoded schema's fields array — a
	 * missing/malformed schema collapses to 0 rather than fataling. Timestamps
	 * are int seconds (post_date_gmt / post_modified_gmt via strtotime) to
	 * match the numeric-cast invariant. is_active tracks post_status='publish'
	 * (WPForms stores drafts + trashed forms with different statuses).
	 */
	private static function format_form_entry( $post ) {
		$form_id = isset( $post->ID ) ? (int) $post->ID : 0;
		$title   = isset( $post->post_title ) ? (string) $post->post_title : '';
		$status  = isset( $post->post_status ) ? (string) $post->post_status : '';

		$raw     = isset( $post->post_content ) ? (string) $post->post_content : '';
		$decoded = function_exists( 'wpforms_decode' ) ? wpforms_decode( $raw ) : null;
		$decoded = (array) ( $decoded ?? [] );

		$field_count = 0;
		if ( isset( $decoded['fields'] ) && is_array( $decoded['fields'] ) ) {
			$field_count = count( $decoded['fields'] );
		}

		$created_at  = 0;
		$modified_at = 0;
		if ( isset( $post->post_date_gmt ) && $post->post_date_gmt ) {
			$ts = strtotime( (string) $post->post_date_gmt . ' UTC' );
			$created_at = $ts ? (int) $ts : 0;
		}
		if ( isset( $post->post_modified_gmt ) && $post->post_modified_gmt ) {
			$ts = strtotime( (string) $post->post_modified_gmt . ' UTC' );
			$modified_at = $ts ? (int) $ts : 0;
		}

		// Timestamps carry both int seconds (backwards compat for any caller
		// integrating against the initial WPForms integration ship) and an
		// ISO 8601 UTC string. Every other Royal MCP time field returns ISO
		// 8601 (gmdate('c', $ts)) so consumers can locale-format without a
		// second lookup; the int-only shape forced callers to know it was UTC
		// and format themselves, cascading timezone-offset bugs if they
		// assumed local time.
		return [
			'form_id'         => $form_id,
			'title'           => $title,
			'field_count'     => (int) $field_count,
			'is_active'       => ( $status === 'publish' ),
			'created_at'      => $created_at,
			'created_at_iso'  => $created_at ? gmdate( 'c', $created_at ) : '',
			'modified_at'     => $modified_at,
			'modified_at_iso' => $modified_at ? gmdate( 'c', $modified_at ) : '',
		];
	}

	/**
	 * Normalize a single WPForms field definition to a stable AI-consumable
	 * shape.
	 *
	 * WPForms's on-disk field schema carries inconsistencies that break naive
	 * AI reasoning: `required` is stored as string "1" for some fields and
	 * omitted entirely for others (absent-vs-false ambiguity), and choice
	 * arrays for select/radio/checkbox fields come through as string-keyed
	 * objects with only `label` populated, losing the `value` field that
	 * maps to stored submission data.
	 *
	 * This normalizer produces:
	 * - `required`: strict boolean (missing/false/"0"/0 → false, truthy → true).
	 * - `choices` on choice-bearing types: indexed array of
	 *   `[{label, value, key}]` where `value` falls back to `label` when
	 *   absent (matches WPForms's own rendering behavior) and `key` retains
	 *   the original schema key for callers that need to reference it back
	 *   into the raw schema.
	 *
	 * Non-array input passes through unmodified so downstream code stays
	 * defensive against malformed schema payloads.
	 */
	private static function format_field( $field ) {
		if ( ! is_array( $field ) ) {
			return $field;
		}

		$field['required'] = ! empty( $field['required'] );

		$type = isset( $field['type'] ) ? (string) $field['type'] : '';

		// Choice-bearing field types across WPForms + the payment addon.
		// payment-* variants share the same choices shape as their non-payment
		// siblings — WPForms's own field-type registration mirrors the
		// standard-choice contract.
		$choice_types = [ 'select', 'radio', 'checkbox', 'payment-multiple', 'payment-select', 'payment-checkbox' ];

		if ( in_array( $type, $choice_types, true )
			&& isset( $field['choices'] )
			&& is_array( $field['choices'] )
		) {
			$normalized = [];
			foreach ( $field['choices'] as $key => $choice ) {
				if ( ! is_array( $choice ) ) {
					continue;
				}
				$label = isset( $choice['label'] ) ? (string) $choice['label'] : '';
				$value = ( isset( $choice['value'] ) && $choice['value'] !== '' )
					? (string) $choice['value']
					: $label;
				$normalized[] = [
					'label' => $label,
					'value' => $value,
					'key'   => is_numeric( $key ) ? (int) $key : (string) $key,
				];
			}
			$field['choices'] = $normalized;
		}

		return $field;
	}

	/**
	 * List submissions/entries for a form.
	 *
	 * Arg validation MUST fire before the tier probe so callers see a consistent error contract.
	 * Pro entry-handler branch is scaffolded; real enumeration pending.
	 */
	private static function handle_list_submissions( $args ) {
		$form_id = self::resolve_form_id( $args );

		$limit = isset( $args['limit'] ) ? intval( $args['limit'] ) : 50;
		if ( $limit < 1 ) {
			$limit = 50;
		}
		if ( $limit > 100 ) {
			$limit = 100;
		}
		$offset = isset( $args['offset'] ) ? intval( $args['offset'] ) : 0;
		if ( $offset < 0 ) {
			$offset = 0;
		}

		// Tier-gate: MUST return `unavailable` (not `no_match`) so agents don't misread as query outcome.
		if ( ! wpforms()->is_pro() ) {
			return [
				'state'   => 'unavailable',
				'reason'  => 'requires_pro',
				'tier'    => 'wpforms_pro',
				'message' => 'This feature requires WPForms Pro; the Lite plugin does not store submissions accessible via API.',
			];
		}

		// TODO: Pro submission path is scaffolded — retest against an
		// activated Pro install and pin the returned row shape (field
		// values, submitter IP, timestamps, entry status).
		try {
			$entry_handler = wpforms()->obj( 'entry' );
			if ( ! $entry_handler ) {
				return [
					'state'   => 'error',
					'reason'  => 'handler_unavailable',
					'tier'    => 'wpforms_pro',
					'message' => 'WPForms entry handler not available in this Pro build.',
				];
			}

			return [
				'form_id'     => $form_id,
				'submissions' => [],
				'count'       => 0,
				'total'       => 0,
				'offset'      => $offset,
				'limit'       => $limit,
				'note'        => 'Pro branch scaffolded — real field extraction TODO once verified against Pro install',
			];
		} catch ( \Throwable $e ) {
			error_log( 'Royal MCP WPForms list_submissions Pro fallback: ' . $e->getMessage() );
			return [
				'state'   => 'error',
				'reason'  => 'enumeration_failed',
				'tier'    => 'wpforms_pro',
				'message' => 'Failed to enumerate Pro submissions.',
			];
		}
	}

	/**
	 * Read a single submission with all field values + metadata.
	 *
	 * Arg validation MUST fire before the tier probe — see handle_list_submissions.
	 * Pro entry-handler branch is scaffolded; real field extraction pending.
	 */
	private static function handle_get_submission( $args ) {
		$submission_id = self::resolve_submission_id( $args );

		if ( ! wpforms()->is_pro() ) {
			return [
				'state'   => 'unavailable',
				'reason'  => 'requires_pro',
				'tier'    => 'wpforms_pro',
				'message' => 'This feature requires WPForms Pro; the Lite plugin does not store submissions accessible via API.',
			];
		}

		// TODO: Pro single-submission path is scaffolded — retest against an
		// activated Pro install and pin the returned row shape.
		try {
			$entry_handler = wpforms()->obj( 'entry' );
			if ( ! $entry_handler ) {
				return [
					'state'   => 'error',
					'reason'  => 'handler_unavailable',
					'tier'    => 'wpforms_pro',
					'message' => 'WPForms entry handler not available in this Pro build.',
				];
			}

			return [
				'submission_id' => $submission_id,
				'submissions'   => null,
				'note'          => 'Pro branch scaffolded — real field extraction TODO once verified against Pro install',
			];
		} catch ( \Throwable $e ) {
			error_log( 'Royal MCP WPForms get_submission Pro fallback: ' . $e->getMessage() );
			return [
				'state'   => 'error',
				'reason'  => 'fetch_failed',
				'tier'    => 'wpforms_pro',
				'message' => 'Failed to fetch Pro submission.',
			];
		}
	}

	// ==================== Helpers ====================

	/**
	 * Resolve the `form_id` arg to a positive integer. Missing/zero raises
	 * so handlers can trust the returned int. Mirrors the YoastSEO
	 * resolve_post_id helper shape adapted for the WPForms identifier
	 * vocabulary — WPForms stores form definitions as wpforms custom-post
	 * type entries keyed by numeric post ID.
	 */
	private static function resolve_form_id( $args ) {
		$raw = $args['form_id'] ?? 0;
		$form_id = absint( intval( $raw ) );
		if ( $form_id <= 0 ) {
			throw new \Exception( 'form_id is required.' );
		}
		return $form_id;
	}

	/**
	 * Resolve the `submission_id` arg to a positive integer. Missing/zero
	 * raises so handlers can trust the returned int. Mirrors resolve_form_id
	 * for the submission identifier vocabulary — WPForms Pro stores
	 * submissions as rows in the wp_wpforms_entries table keyed by numeric
	 * entry_id.
	 */
	private static function resolve_submission_id( $args ) {
		$raw = $args['submission_id'] ?? 0;
		$submission_id = absint( intval( $raw ) );
		if ( $submission_id <= 0 ) {
			throw new \Exception( 'submission_id is required.' );
		}
		return $submission_id;
	}

	/**
	 * Confirm a form exists for the given form_id and return the WP_Post.
	 * Handlers reuse the returned post so a second lookup is not needed.
	 * Throws when the form_id does not resolve to a known form.
	 *
	 * Uses the WPForms form handler's get($id) signature which returns a
	 * WP_Post on success or false when the id is unknown — verified via
	 * reflection against WPForms_Form_Handler::get. Calling get() with no
	 * arguments returns ALL forms, so the empty-guard on $form_id in
	 * resolve_form_id is load-bearing.
	 */
	private static function ensure_form_exists( $form_id ) {
		$handler = wpforms()->obj( 'form' );
		if ( ! $handler ) {
			throw new \Exception( 'WPForms form handler is not available.' );
		}
		$form = $handler->get( $form_id );
		if ( ! $form ) {
			throw new \Exception( 'Form not found: ' . esc_html( (string) $form_id ) );
		}
		return $form;
	}
}

/**
 * Manifest declaration for host discovery (any consumer of the
 * royal_mcp_manifests filter).
 *
 * Registered unconditionally; the wpforms() function guard runs inside the
 * callback so plugin load order does not affect the filter registration.
 * plugin_version falls back to 'unknown' when WPFORMS_VERSION is undefined
 * (older builds or bespoke forks) — the wpforms() function is the canonical
 * availability probe.
 *
 * capabilities is ['read'] only because all four tools in this integration
 * are read-only. supports_undo is false for the same reason — nothing to
 * reverse when the tool never mutates state.
 */
add_filter( 'royal_mcp_manifests', function ( $manifests ) {
	if ( ! function_exists( 'wpforms' ) ) {
		return $manifests;
	}
	$manifests[] = [
		'royal_mcp_manifest_version' => '1.0',
		'plugin_slug'                => 'wpforms',
		'plugin_display_name'        => 'WPForms',
		'plugin_version'             => defined( 'WPFORMS_VERSION' ) ? WPFORMS_VERSION : 'unknown',
		'vendor_name'                => 'WPForms LLC',
		'mcp_endpoint'               => rest_url( 'royal-mcp/v1/mcp' ),
		'auth_methods'               => [ 'oauth2.1' ],
		'capabilities'               => [ 'read' ],
		'manifest_updated_at'        => gmdate( 'c' ),
		'trust_signals'              => [
			'supports_dry_run'                 => false,
			'supports_undo'                    => false,
			'supports_snapshots'               => false,
			'requires_review_for_destructive'  => true,
		],
	];
	return $manifests;
} );
