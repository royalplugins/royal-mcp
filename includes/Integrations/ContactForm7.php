<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Form_Entry_Privacy;

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
				'description' => 'Get a single Contact Form 7 form by ID. Returns the parsed field list (name, type, required flag, default values, options), messages, and the raw form template. Mail settings are omitted to avoid exposing recipient addresses. Email addresses and phone numbers in labels, choice values and the form template are hidden.',
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
				'description' => 'List Contact Form 7 submissions. Requires the Flamingo add-on (or a compatible storage plugin) — CF7 does not store submissions on its own. Returns id, subject, from, timestamp, fields, and spam flag. Personal data is masked: from, from_name, from_email and personal fields (email, phone, file, hidden, fields named like a name, address or phone, and every field of a message whose form no longer exists) come back as null and are listed in masked_fields; those values, email addresses and phone numbers are hidden in the subject and other text. The site can opt in to more with the royal_mcp_form_entry_fields filter. When no storage add-on is active the response is {state: unavailable, reason: requires_addon}.',
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
		$id = self::whole_number( $args['id'] ?? null );
		if ( null === $id || $id <= 0 ) {
			throw new \Exception( 'id is required and must be a positive integer.' );
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

		// limit 0 or absent means the default; offset absent means 0.
		$limit = self::whole_number( $args['limit'] ?? 0 );
		if ( null === $limit ) {
			throw new \Exception( 'limit must be a positive integer.' );
		}
		$limit  = 0 === $limit ? 50 : min( $limit, 200 );
		$offset = self::whole_number( $args['offset'] ?? 0 );
		if ( null === $offset ) {
			throw new \Exception( 'offset must be zero or a positive integer.' );
		}

		$query = [
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'post_status'    => 'any',
		];

		// form_id absent, 0 or empty means every form.
		$form    = null;
		$form_id = self::whole_number( $args['form_id'] ?? 0 );
		if ( null === $form_id ) {
			throw new \Exception( 'form_id must be a positive integer.' );
		}
		if ( $form_id > 0 ) {
			$form = \WPCF7_ContactForm::get_instance( $form_id );
			if ( ! $form ) {
				throw new \Exception( 'Contact Form 7 form not found: ' . (int) $form_id );
			}
			// Flamingo stores channel as taxonomy term matching the form slug.
			$query['channel'] = $form->name();
		}

		$messages = \Flamingo_Inbound_Message::find( $query );
		$total    = (int) \Flamingo_Inbound_Message::count( $query );

		$reveal = Form_Entry_Privacy::reveal_list( 'contact-form-7', $form ? (int) $form->id() : 0, 'list' );
		$out    = [];
		foreach ( $messages as $msg ) {
			$out[] = self::format_submission( $msg, $reveal );
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
				'labels'     => is_array( $tag->labels ) ? Form_Entry_Privacy::form_text( array_values( $tag->labels ) ) : [],
				'values'     => is_array( $tag->values ) ? Form_Entry_Privacy::form_text( array_values( $tag->values ) ) : [],
				'options'    => is_array( $tag->options ) ? array_values( $tag->options ) : [],
			];
		}

		$messages = $form->prop( 'messages' );
		if ( ! is_array( $messages ) ) {
			$messages = [];
		}

		return $summary + [
			'form_template' => self::hide_in_template( (string) $form->prop( 'form' ) ),
			'field_count'   => count( array_filter( $tags, function ( $t ) { return $t['is_input']; } ) ),
			'fields'        => $tags,
			'messages'      => $messages,
		];
	}

	/**
	 * One stored message. Sender details and personal fields are null and named
	 * in masked_fields unless the site lets them through.
	 */
	private static function format_submission( $msg, array $reveal ) {
		$post_id  = $msg->id();
		$post     = $post_id ? get_post( $post_id ) : null;
		$posted   = $post ? $post->post_date_gmt : null;
		$channel  = (string) ( $msg->channel ?? '' );
		$types    = self::field_types( $channel );
		$masked   = [];

		$hidden = [];
		$sender = [];
		foreach ( [ 'from', 'from_name', 'from_email' ] as $key ) {
			if ( Form_Entry_Privacy::reveals( $reveal, $key ) ) {
				$sender[ $key ] = $msg->$key ?? '';
			} else {
				$sender[ $key ] = null;
				$masked[]       = $key;
				$hidden[]       = $msg->$key ?? '';
			}
		}

		$fields = [];
		foreach ( ( is_array( $msg->fields ?? null ) ? $msg->fields : [] ) as $name => $value ) {
			// A field the form no longer defines (or a message whose form is gone) has no known type: masked.
			$type = null !== $types && isset( $types[ (string) $name ] ) ? $types[ (string) $name ] : 'unknown';
			$row  = Form_Entry_Privacy::field( (string) $name, '', $type, $value, $reveal );
			$fields[ $name ] = $row['value'];
			if ( $row['masked'] ) {
				$masked[] = (string) $name;
				$hidden[] = $value;
			}
		}

		return [
			'id'               => (int) $post_id,
			'channel'          => $channel,
			'subject'          => self::masked_subject( (string) ( $msg->subject ?? '' ), $hidden, $reveal ),
		] + $sender + [
			'fields'           => $fields,
			'masked_fields'    => $masked,
			'spam'             => ! empty( $msg->spam ),
			'submitted_at'     => $posted,
			'submitted_at_iso' => self::to_iso( $posted ),
		];
	}

	/**
	 * The mail subject with the values of masked fields and sender details
	 * replaced, then email addresses and phone numbers hidden.
	 */
	private static function masked_subject( $subject, array $hidden, array $reveal ) {
		$values = [];
		array_walk_recursive( $hidden, static function ( $v ) use ( &$values ) {
			if ( is_scalar( $v ) && strlen( trim( (string) $v ) ) >= 2 ) {
				$values[] = trim( (string) $v );
			}
		} );
		usort( $values, static function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );
		foreach ( array_unique( $values ) as $v ) {
			$masked  = preg_replace( '/' . preg_quote( $v, '/' ) . '/iu', '[hidden]', $subject );
			// Text that isn't valid UTF-8 makes the pattern fail; a plain match still hides the value.
			$subject = null !== $masked ? $masked : str_ireplace( $v, '[hidden]', $subject ); // mb-check-ignore
		}
		return Form_Entry_Privacy::free_text( $subject, $reveal );
	}

	/**
	 * Field name => CF7 field type for the form a Flamingo channel belongs to,
	 * or null when no form has that channel.
	 *
	 * @param string $channel Flamingo channel (the form's slug).
	 * @return array<string,string>|null
	 */
	private static function field_types( $channel ) {
		static $cache = [];
		if ( '' === $channel ) {
			return null;
		}
		if ( ! array_key_exists( $channel, $cache ) ) {
			$cache[ $channel ] = null;
			$forms             = \WPCF7_ContactForm::find( [ 'name' => $channel, 'posts_per_page' => 1 ] );
			if ( $forms ) {
				$cache[ $channel ] = [];
				foreach ( $forms[0]->scan_form_tags() as $tag ) {
					if ( '' !== (string) $tag->name ) {
						$cache[ $channel ][ (string) $tag->name ] = (string) $tag->basetype;
					}
				}
			}
		}
		return $cache[ $channel ];
	}

	/**
	 * The form template with email addresses and phone numbers hidden in its
	 * text and in the quoted labels and choices of its tags. Tag names and
	 * options (min:, max:, maxlength: and the like) are left as written, and
	 * everything outside a hidden span stays exactly as stored.
	 */
	private static function hide_in_template( $template ) {
		$out  = '';
		$last = 0;
		if ( preg_match_all( '/\[[^\[\]]*\]/', $template, $tags, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $tags[0] as $tag ) {
				$out .= Form_Entry_Privacy::form_text( substr( $template, $last, $tag[1] - $last ) );
				$out .= preg_replace_callback(
					'/"[^"]*"|\'[^\']*\'|[^\s"\']+/',
					static function ( $m ) {
						$part = $m[0];
						if ( '"' === $part[0] || "'" === $part[0] ) {
							return Form_Entry_Privacy::form_text( $part );
						}
						return Form_Entry_Privacy::hide_emails( $part, false );
					},
					$tag[0]
				);
				$last = $tag[1] + strlen( $tag[0] );
			}
		}
		return $out . Form_Entry_Privacy::form_text( (string) substr( $template, $last ) );
	}

	/**
	 * A whole number from a tool argument: an integer, an integral float or a
	 * string of digits; '' and null count as 0. Null for anything else
	 * (arrays, booleans, signs, fractions, text).
	 */
	private static function whole_number( $value ) {
		if ( null === $value || '' === $value ) {
			return 0;
		}
		if ( is_float( $value ) ) {
			if ( $value < 0 || $value > PHP_INT_MAX || floor( $value ) !== $value ) {
				return null;
			}
			$value = (int) $value;
		}
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		if ( is_string( $value ) && preg_match( '/^\d{1,19}$/', $value ) ) {
			$n = Form_Entry_Privacy::positive_int( $value );
			return ( $n > 0 || '' === ltrim( $value, '0' ) ) ? $n : null;
		}
		return null;
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
