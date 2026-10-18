<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Code_Redactor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPCode (Lite and Pro) MCP integration, read only: list snippets, read one
 * snippet with its code, and read a snippet's status and last error.
 *
 * Nothing here creates, edits, activates, deactivates or runs a snippet.
 * Returned code, notes and error messages have credential-shaped values
 * replaced with [REDACTED]. WPCode's own capabilities apply: wpcode_edit_snippets
 * to use the tools, and the per-type edit capability to see a snippet's code
 * or error messages (which can quote the code).
 */
class WPCode {

	const MAX_CODE_BYTES = 262144;

	const MAX_ERROR_CHARS = 2000;

	const CODE_TYPES = [ 'php', 'universal', 'html', 'js', 'css', 'scss', 'text', 'blocks' ];

	public static function is_available() {
		if ( ! class_exists( '\WPCode_Snippet' ) || ! post_type_exists( 'wpcode' ) ) {
			return false;
		}
		foreach ( [ 'get_code', 'get_last_error', 'get_title', 'get_code_type', 'get_location', 'is_active', 'get_priority', 'get_tags', 'get_note', 'get_id' ] as $method ) {
			if ( ! method_exists( '\WPCode_Snippet', $method ) ) {
				return false;
			}
		}
		return true;
	}

	public static function get_tools() {
		$id = [ 'type' => 'integer', 'description' => 'Snippet ID (from wpcode_list_snippets).' ];
		return [
			[
				'name'        => 'wpcode_list_snippets',
				'description' => 'List WPCode snippets: ID, title, code type, where it is inserted (location), active, tags, priority, whether it has a recorded error, whether conditional logic or a schedule applies, and last change. No code; use wpcode_get_snippet. Royal MCP never creates, edits, activates or runs WPCode snippets: do that in WPCode. Paged: {snippets, page, per_page, total, total_pages}. Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'status'    => [ 'type' => 'string', 'enum' => [ 'all', 'active', 'inactive' ], 'description' => 'Default all.' ],
						'code_type' => [ 'type' => 'string', 'enum' => self::CODE_TYPES ],
						'tag'       => [ 'type' => 'string', 'description' => 'Tag slug.' ],
						'search'    => [ 'type' => 'string', 'description' => 'Only snippets whose title contains this text (not case-sensitive).' ],
						'page'      => [ 'type' => 'integer', 'description' => 'Page number, 1-indexed (default 1).' ],
						'per_page'  => [ 'type' => 'integer', 'description' => 'Snippets per page (default 50, max 100).' ],
					],
				],
			],
			[
				'name'        => 'wpcode_get_snippet',
				'description' => 'Get one WPCode snippet: its settings (location, priority, tags, note, shortcode, schedule dates, conditional logic) and its code, with values that look like passwords, API keys, tokens or connection secrets replaced by [REDACTED]. Code over 256 KB, code that cannot be checked, or code of a type your account may not edit in WPCode is withheld (code_withheld: true). Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [ 'id' => $id ],
					'required'   => [ 'id' ],
				],
			],
			[
				'name'        => 'wpcode_get_snippet_status',
				'description' => 'Whether a WPCode snippet is active, and the last error WPCode recorded for it: when, the line in the snippet, whether WPCode deactivated it because of the error, and the message (shown only if your account may see the snippet\'s code). Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [ 'id' => $id ],
					'required'   => [ 'id' ],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		// The capability check comes first, so callers who could never use these
		// tools are not told whether WPCode is installed.
		if ( ! self::is_available() ) {
			// WPCode's capabilities do not exist while it is inactive; administrators get the plain answer.
			throw new \Exception( current_user_can( 'manage_options' ) ? 'WPCode is not active' : 'You do not have permission to use WPCode tools.' );
		}
		if ( ! current_user_can( 'wpcode_edit_snippets' ) ) {
			throw new \Exception( 'You do not have permission to use WPCode tools.' );
		}
		$args = is_array( $args ) ? $args : [];

		switch ( $name ) {
			case 'wpcode_list_snippets':
				return self::list_snippets( $args );
			case 'wpcode_get_snippet':
				return self::get_snippet( $args );
			case 'wpcode_get_snippet_status':
				return self::get_status( $args );
			default:
				throw new \Exception( 'Unknown WPCode tool: ' . esc_html( (string) $name ) );
		}
	}

	private static function list_snippets( array $args ) {
		$status = self::string_arg( $args, 'status', 'all' );
		if ( ! in_array( $status, [ 'all', 'active', 'inactive' ], true ) ) {
			throw new \Exception( 'status must be all, active or inactive.' );
		}
		$type = self::string_arg( $args, 'code_type', '' );
		if ( '' !== $type && ! in_array( $type, self::CODE_TYPES, true ) ) {
			throw new \Exception( 'code_type must be one of: ' . implode( ', ', self::CODE_TYPES ) . '.' );
		}
		$tag      = self::string_arg( $args, 'tag', '' );
		$search   = self::string_arg( $args, 'search', '' );
		$per_page = (int) max( 1, min( is_scalar( $args['per_page'] ?? null ) ? (float) $args['per_page'] : 50, 100 ) );
		$page     = (int) max( 1, min( is_scalar( $args['page'] ?? null ) ? (float) $args['page'] : 1, 1000000 ) );

		$query = [
			'post_type'      => 'wpcode',
			'post_status'    => 'all' === $status ? [ 'publish', 'draft' ] : ( 'active' === $status ? 'publish' : 'draft' ),
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		];
		$tax = [];
		if ( '' !== $type ) {
			$tax[] = [ 'taxonomy' => 'wpcode_type', 'field' => 'slug', 'terms' => $type ];
		}
		if ( '' !== $tag ) {
			$tax[] = [ 'taxonomy' => 'wpcode_tags', 'field' => 'slug', 'terms' => sanitize_title( $tag ) ];
		}
		if ( $tax ) {
			$query['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		// The title match runs here, not through WordPress search, which would
		// also match inside the code.
		$posts = [];
		foreach ( (array) ( new \WP_Query( $query ) )->posts as $post ) {
			if ( '' === $search || false !== mb_stripos( (string) $post->post_title, $search ) ) {
				$posts[] = $post;
			}
		}
		$total = count( $posts );
		$rows  = [];
		foreach ( array_slice( $posts, ( $page - 1 ) * $per_page, $per_page ) as $post ) {
			$rows[] = self::summary( new \WPCode_Snippet( $post ) );
		}
		return [
			'snippets'    => $rows,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => (int) max( 1, ceil( $total / $per_page ) ),
		];
	}

	private static function get_snippet( array $args ) {
		$snippet = self::find( $args );
		$type    = self::text( $snippet->get_code_type() );
		$out     = self::summary( $snippet );
		$note    = Code_Redactor::redact( self::text( $snippet->get_note() ), 'text' );
		$out['note']      = null === $note ? '' : $note;
		$out['shortcode'] = method_exists( $snippet, 'get_custom_shortcode' ) ? self::text( $snippet->get_custom_shortcode() ) : '';
		$out['schedule']  = self::schedule( $snippet );

		if ( ! current_user_can( self::code_cap( $type ) ) ) {
			$out['code']                 = '';
			$out['code_withheld']        = true;
			$out['code_withheld_reason'] = 'Your account may not edit ' . $type . ' snippets in WPCode, so their code is not shown.';
			return $out;
		}
		$code  = self::text( $snippet->get_code() );
		$clean = strlen( $code ) > self::MAX_CODE_BYTES ? null : Code_Redactor::redact( $code, self::redactor_type( $type ) );
		$out['code']          = null === $clean ? '' : $clean;
		$out['code_withheld'] = null === $clean;
		if ( null === $clean ) {
			$out['code_withheld_reason'] = strlen( $code ) > self::MAX_CODE_BYTES
				? 'The code is larger than 256 KB; read it in WPCode.'
				: 'The code could not be checked for credentials, so it is not returned; read it in WPCode.';
		}
		return $out;
	}

	private static function get_status( array $args ) {
		$snippet = self::find( $args );
		$error   = self::last_error( $snippet );
		$last    = null;
		if ( $error ) {
			$type = self::text( $error['wpc_type'] ?? 'error' );
			$line = $error['error_line'] ?? ( $error['line'] ?? null );
			$last = [
				'type'        => '' === $type ? 'error' : $type,
				'time'        => isset( $error['time'] ) && is_numeric( $error['time'] ) ? gmdate( 'c', (int) $error['time'] ) : null,
				'line'        => is_numeric( $line ) ? (int) $line : null,
				'deactivated' => 'deactivated' === $type,
			];
			if ( current_user_can( self::code_cap( self::text( $snippet->get_code_type() ) ) ) ) {
				$last['message'] = self::error_message( self::text( $error['message'] ) );
			} else {
				$last['message']          = '';
				$last['message_withheld'] = true;
			}
		}
		return [
			'id'                => (int) $snippet->get_id(),
			'title'             => self::text( $snippet->get_title() ),
			'active'            => (bool) $snippet->is_active(),
			'location'          => self::text( $snippet->get_location() ),
			'conditional_logic' => method_exists( $snippet, 'conditional_rules_enabled' ) && (bool) $snippet->conditional_rules_enabled(),
			'scheduled'         => method_exists( $snippet, 'is_scheduled' ) && (bool) $snippet->is_scheduled(),
			'last_error'        => $last,
		];
	}

	// ============ Helpers ============

	/** One answer for a missing snippet and an invalid ID, so IDs cannot be probed. */
	private static function find( array $args ) {
		$id = $args['id'] ?? null;
		$id = is_int( $id ) ? $id : ( is_string( $id ) && ctype_digit( $id ) ? (int) $id : 0 );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || 'wpcode' !== $post->post_type || ! in_array( $post->post_status, [ 'publish', 'draft' ], true ) ) {
			throw new \Exception( 'No WPCode snippet with that ID.' );
		}
		return new \WPCode_Snippet( $post );
	}

	private static function summary( $snippet ) {
		$post = method_exists( $snippet, 'get_post_data' ) ? $snippet->get_post_data() : null;
		return [
			'id'                => (int) $snippet->get_id(),
			'title'             => self::text( $snippet->get_title() ),
			'code_type'         => self::text( $snippet->get_code_type() ),
			'location'          => self::text( $snippet->get_location() ),
			'active'            => (bool) $snippet->is_active(),
			'priority'          => is_numeric( $snippet->get_priority() ) ? (int) $snippet->get_priority() : 10,
			'tags'              => array_values( array_filter( array_map( [ __CLASS__, 'text' ], (array) $snippet->get_tags() ), 'strlen' ) ),
			'has_error'         => null !== self::last_error( $snippet ),
			'conditional_logic' => method_exists( $snippet, 'conditional_rules_enabled' ) && (bool) $snippet->conditional_rules_enabled(),
			'scheduled'         => method_exists( $snippet, 'is_scheduled' ) && (bool) $snippet->is_scheduled(),
			'last_update'       => $post instanceof \WP_Post ? self::modified( $post ) : '',
			'code_bytes'        => strlen( self::text( $snippet->get_code() ) ),
		];
	}

	/** The recorded error when it has a message, else null. */
	private static function last_error( $snippet ) {
		$error = $snippet->get_last_error();
		if ( ! is_array( $error ) || '' === trim( self::text( $error['message'] ?? '' ) ) ) {
			return null;
		}
		return $error;
	}

	/**
	 * An error message without server paths or the stack trace, with credential
	 * values redacted and the length capped.
	 */
	private static function error_message( $message ) {
		$message = preg_split( '/\R\s*Stack trace:/i', $message )[0];
		$message = preg_replace( '~(?:[A-Za-z]:)?[\\\\/](?:[^\s:()\'"\\\\/]+[\\\\/])+([^\s:()\'"\\\\/]+)~', '$1', $message );
		$message = mb_substr( (string) $message, 0, self::MAX_ERROR_CHARS );
		$clean   = Code_Redactor::redact( $message, 'text' );
		return null === $clean ? '[REDACTED]' : $clean;
	}

	/** Start and end dates of the snippet's schedule only. */
	private static function schedule( $snippet ) {
		if ( ! method_exists( $snippet, 'get_schedule' ) ) {
			return [];
		}
		$schedule = $snippet->get_schedule();
		$schedule = is_array( $schedule ) ? $schedule : [];
		return [
			'start' => self::text( $schedule['start'] ?? '' ),
			'end'   => self::text( $schedule['end'] ?? '' ),
		];
	}

	/** Last change in UTC; drafts never saved again carry no GMT date. */
	private static function modified( \WP_Post $post ) {
		if ( '0000-00-00 00:00:00' !== $post->post_modified_gmt && '' !== (string) $post->post_modified_gmt ) {
			return (string) get_post_modified_time( 'c', true, $post );
		}
		$local = (string) get_post_modified_time( 'c', false, $post );
		return false === strpos( $local, '0000' ) ? $local : '';
	}

	/** A scalar as text; anything else (arrays, objects) as an empty string. */
	private static function text( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** A string argument, its default when absent, refused when given as anything but text. */
	private static function string_arg( array $args, $key, $default ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			return $default;
		}
		if ( ! is_string( $args[ $key ] ) ) {
			throw new \Exception( $key . ' must be text.' );
		}
		return '' === $args[ $key ] ? $default : $args[ $key ];
	}

	/** WPCode's capability for editing (and so reading the code of) a snippet type. */
	private static function code_cap( $type ) {
		if ( in_array( $type, [ 'php', 'universal' ], true ) ) {
			return 'wpcode_edit_php_snippets';
		}
		if ( in_array( $type, [ 'text', 'blocks' ], true ) ) {
			return 'wpcode_edit_snippets';
		}
		return 'wpcode_edit_html_snippets';
	}

	private static function redactor_type( $type ) {
		return in_array( $type, [ 'php', 'universal' ], true ) ? $type : 'text';
	}
}
