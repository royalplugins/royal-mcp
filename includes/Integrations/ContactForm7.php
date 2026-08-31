<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contact Form 7 MCP Integration
 *
 * Contact Form 7 does not store submissions natively. Submission history
 * requires Flamingo (canonical companion by the same author) or an
 * equivalent CF7-DB plugin. The submissions tool auto-detects and returns
 * an actionable {state: unavailable, reason: requires_addon} envelope
 * when neither is present.
 */
class ContactForm7 {

	public static function is_available() {
		return defined( 'WPCF7_VERSION' ) && class_exists( '\\WPCF7_ContactForm' );
	}

	private static function has_flamingo() {
		return defined( 'FLAMINGO_VERSION' ) && class_exists( '\\Flamingo_Inbound_Message' );
	}

	public static function get_tools() {
		return [
			[
				'name'        => 'cf7_list_forms',
				'description' => 'List Contact Form 7 forms with title, slug, locale, hash, and creation/modification timestamps. Supports pagination via limit + offset.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'   => [ 'type' => 'integer', 'description' => 'Max forms to return (default 50, max 200).' ],
						'offset'  => [ 'type' => 'integer', 'description' => 'Row offset for pagination (default 0).' ],
						'orderby' => [ 'type' => 'string', 'description' => 'Sort column: ID, title, date. Default ID.', 'enum' => [ 'ID', 'title', 'date' ] ],
						'order'   => [ 'type' => 'string', 'description' => 'Sort direction. Default ASC.', 'enum' => [ 'ASC', 'DESC' ] ],
					],
				],
			],
			[
				'name'        => 'cf7_get_form',
				'description' => 'Get a single Contact Form 7 form by ID. Returns the parsed field list (name, type, required flag, default values, options), messages, and the raw form template. Mail settings are omitted to avoid exposing recipient addresses.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'id' => [ 'type' => 'integer', 'description' => 'CF7 form post ID.' ],
					],
					'required'   => [ 'id' ],
				],
			],
			[
				'name'        => 'cf7_list_submissions',
				'description' => 'List Contact Form 7 submissions. Requires the Flamingo add-on (or a compatible storage plugin) — CF7 does not store submissions on its own. Returns id, subject, from, timestamp, fields, and spam flag. When no storage add-on is active the response is {state: unavailable, reason: requires_addon}.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'form_id' => [ 'type' => 'integer', 'description' => 'Optional CF7 form post ID to filter submissions by.' ],
						'limit'   => [ 'type' => 'integer', 'description' => 'Max submissions to return (default 50, max 200).' ],
						'offset'  => [ 'type' => 'integer', 'description' => 'Row offset for pagination (default 0).' ],
					],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use Contact Form 7 tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Contact Form 7 is not active' );
		}

		switch ( $name ) {
			case 'cf7_list_forms':
				return self::handle_list_forms( $args );
			case 'cf7_get_form':
				return self::handle_get_form( $args );
			case 'cf7_list_submissions':
				return self::handle_list_submissions( $args );
			default:
				throw new \Exception( 'Unknown Contact Form 7 tool: ' . esc_html( $name ) );
		}
	}

	private static function handle_list_forms( $args ) {
		$limit   = min( max( intval( $args['limit']  ?? 50 ), 1 ), 200 );
		$offset  = max( intval( $args['offset'] ?? 0 ), 0 );
		$orderby = in_array( $args['orderby'] ?? 'ID', [ 'ID', 'title', 'date' ], true ) ? ( $args['orderby'] ?? 'ID' ) : 'ID';
		$order   = strtoupper( $args['order'] ?? 'ASC' ) === 'DESC' ? 'DESC' : 'ASC';

		$forms = \WPCF7_ContactForm::find( [
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'orderby'        => $orderby,
			'order'          => $order,
		] );

		$out = [];
		foreach ( $forms as $form ) {
			$out[] = self::format_form_summary( $form );
		}

		return [
			'limit'   => $limit,
			'offset'  => $offset,
			'count'   => count( $out ),
			'total'   => (int) \WPCF7_ContactForm::count(),
			'entries' => $out,
		];
	}

	private static function handle_get_form( $args ) {
		$id = intval( $args['id'] ?? 0 );
		if ( $id <= 0 ) {
			throw new \Exception( 'id is required' );
		}
		$form = \WPCF7_ContactForm::get_instance( $id );
		if ( ! $form ) {
			throw new \Exception( 'Contact Form 7 form not found: ' . $id );
		}
		return self::format_form_detail( $form );
	}

	private static function handle_list_submissions( $args ) {
		if ( ! self::has_flamingo() ) {
			return [
				'state'   => 'unavailable',
				'reason'  => 'requires_addon',
				'message' => 'Contact Form 7 does not store submissions on its own. Install the Flamingo add-on (free, from the same author) to enable submission history.',
				'total'   => 0,
				'entries' => [],
			];
		}

		$limit  = min( max( intval( $args['limit']  ?? 50 ), 1 ), 200 );
		$offset = max( intval( $args['offset'] ?? 0 ), 0 );

		$query = [
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'post_status'    => 'any',
		];

		if ( ! empty( $args['form_id'] ) ) {
			$form_id = intval( $args['form_id'] );
			$form    = \WPCF7_ContactForm::get_instance( $form_id );
			if ( $form ) {
				// Flamingo stores channel as taxonomy term matching the form slug.
				$query['channel'] = $form->name();
			}
		}

		$messages = \Flamingo_Inbound_Message::find( $query );
		$total    = (int) \Flamingo_Inbound_Message::count( $query );

		$out = [];
		foreach ( $messages as $msg ) {
			$out[] = self::format_submission( $msg );
		}

		return [
			'limit'   => $limit,
			'offset'  => $offset,
			'count'   => count( $out ),
			'total'   => $total,
			'entries' => $out,
		];
	}

	private static function format_form_summary( $form ) {
		$post_id = $form->id();
		$post    = get_post( $post_id );
		$created = $post ? $post->post_date_gmt : null;
		$modified = $post ? $post->post_modified_gmt : null;

		return [
			'id'                => (int) $post_id,
			'title'             => $form->title(),
			'slug'              => $form->name(),
			'hash'              => $form->hash(),
			'locale'            => $form->locale(),
			'date_created'      => $created,
			'date_created_iso'  => self::to_iso( $created ),
			'date_modified'     => $modified,
			'date_modified_iso' => self::to_iso( $modified ),
		];
	}

	private static function format_form_detail( $form ) {
		$summary = self::format_form_summary( $form );

		$tags = [];
		foreach ( $form->scan_form_tags() as $tag ) {
			// Free-tier text/select/checkbox/radio + submit are the meaningful
			// user-input tags. Non-input tags (submit, response-output) are
			// still surfaced with input=false so callers can distinguish.
			$input_types = [ 'text', 'email', 'url', 'tel', 'number', 'date', 'textarea', 'select', 'checkbox', 'radio', 'acceptance', 'quiz', 'file', 'range' ];
			$tags[] = [
				'name'       => $tag->name,
				'raw_name'   => $tag->raw_name,
				'type'       => $tag->type,
				'basetype'   => $tag->basetype,
				'required'   => method_exists( $tag, 'is_required' ) ? (bool) $tag->is_required() : false,
				'is_input'   => in_array( $tag->basetype, $input_types, true ),
				'labels'     => is_array( $tag->labels ) ? array_values( $tag->labels ) : [],
				'values'     => is_array( $tag->values ) ? array_values( $tag->values ) : [],
				'options'    => is_array( $tag->options ) ? array_values( $tag->options ) : [],
			];
		}

		$messages = $form->prop( 'messages' );
		if ( ! is_array( $messages ) ) {
			$messages = [];
		}

		return $summary + [
			'form_template' => (string) $form->prop( 'form' ),
			'field_count'   => count( array_filter( $tags, function ( $t ) { return $t['is_input']; } ) ),
			'fields'        => $tags,
			'messages'      => $messages,
		];
	}

	private static function format_submission( $msg ) {
		$post_id  = $msg->id();
		$post     = $post_id ? get_post( $post_id ) : null;
		$posted   = $post ? $post->post_date_gmt : null;

		return [
			'id'               => (int) $post_id,
			'channel'          => $msg->channel ?? '',
			'subject'          => $msg->subject ?? '',
			'from'             => $msg->from ?? '',
			'from_name'        => $msg->from_name ?? '',
			'from_email'       => $msg->from_email ?? '',
			'fields'           => is_array( $msg->fields ?? null ) ? $msg->fields : [],
			'spam'             => ! empty( $msg->spam ),
			'submitted_at'     => $posted,
			'submitted_at_iso' => self::to_iso( $posted ),
		];
	}

	private static function to_iso( $mysql_datetime ) {
		if ( empty( $mysql_datetime ) || '0000-00-00 00:00:00' === $mysql_datetime ) {
			return null;
		}
		$ts = strtotime( $mysql_datetime . ' UTC' );
		return $ts ? gmdate( 'c', $ts ) : null;
	}
}

/**
 * Manifest declaration.
 * All CF7 tools are read-only in the Free tier — no write capability.
 */
add_filter( 'royal_mcp_manifests', function ( $manifests ) {
	if ( ! defined( 'WPCF7_VERSION' ) ) {
		return $manifests;
	}
	$manifests[] = [
		'royal_mcp_manifest_version' => '1.0',
		'plugin_slug'                => 'contact-form-7',
		'plugin_display_name'        => 'Contact Form 7',
		'plugin_version'             => WPCF7_VERSION,
		'vendor_name'                => 'Rock Lobster, LLC.',
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
