<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Undo_Store;
use Royal_MCP\MCP\Support\Envelope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Code Snippets MCP integration, read and recover only: list and read
 * snippets, see which were deactivated recently, deactivate a snippet that
 * breaks the site, and change a snippet's name, description or tags.
 *
 * Creating snippets, editing code, activating, running and importing are not
 * offered: each one runs arbitrary PHP or publishes HTML / JavaScript on the
 * site. Returned code has credential-shaped values replaced with [REDACTED].
 * Only this site's snippets are covered, not network-wide ones.
 */
class CodeSnippets {

	const MAX_CODE_BYTES = 262144;

	public static function is_available() {
		foreach ( [ 'code_snippets', 'get_snippet', 'get_snippets', 'deactivate_snippet', 'clean_snippets_cache' ] as $fn ) {
			if ( ! function_exists( '\Code_Snippets\\' . $fn ) ) {
				return false;
			}
		}
		return method_exists( '\Code_Snippets\Model\Snippet', 'get_type' );
	}

	public static function get_tools() {
		$id = [ 'type' => 'integer', 'description' => 'Snippet ID (from snippets_list).' ];
		return [
			[
				'name'        => 'snippets_list',
				'description' => 'List Code Snippets snippets: ID, name, type (php, html, css, js), where it runs (scope), active, priority, tags, last change and whether it is locked. No code; use snippets_get. Royal MCP never creates, edits, activates or runs snippets: those run code on the site, so do them in the Snippets screen. Paged: {snippets, page, per_page, total, total_pages}. Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'status'   => [ 'type' => 'string', 'enum' => [ 'all', 'active', 'inactive', 'trashed' ], 'description' => 'Default all (not trashed).' ],
						'type'     => [ 'type' => 'string', 'enum' => [ 'php', 'html', 'css', 'js' ] ],
						'search'   => [ 'type' => 'string', 'description' => 'Only snippets whose name, description or tags contain this text.' ],
						'page'     => [ 'type' => 'integer', 'description' => 'Page number, 1-indexed (default 1).' ],
						'per_page' => [ 'type' => 'integer', 'description' => 'Snippets per page (default 50, max 100).' ],
					],
				],
			],
			[
				'name'        => 'snippets_get',
				'description' => 'Get one snippet: its fields and its code, with values that look like passwords, API keys, tokens or connection secrets replaced by [REDACTED]. Code over 256 KB, or code that cannot be checked, is withheld (code_withheld: true). Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [ 'id' => $id ],
					'required'   => [ 'id' ],
				],
			],
			[
				'name'        => 'snippets_recently_active',
				'description' => 'Snippets that were deactivated recently, newest first, with when and whether they are active now. Use it to find what changed before a site broke. Read-only.',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
			[
				'name'        => 'snippets_deactivate',
				'description' => 'Deactivate one snippet, for example one that breaks the site. Locked snippets are refused. No undo token: reactivating runs the code again, so the site owner turns it back on in the Snippets screen after fixing it.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [ 'id' => $id ],
					'required'   => [ 'id' ],
				],
			],
			[
				'name'        => 'snippets_update_metadata',
				'description' => 'Change a snippet\'s name, description or tags (not its code, type or where it runs). Locked snippets are refused. Returns an undo token.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'id'          => $id,
						'name'        => [ 'type' => 'string' ],
						'description' => [ 'type' => 'string' ],
						'tags'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Replaces the tag list.' ],
					],
					'required'   => [ 'id' ],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		// The capability check comes first, so callers who could never use these
		// tools are not told whether Code Snippets is installed.
		if ( ! current_user_can( self::cap() ) ) {
			throw new \Exception( 'You do not have permission to use Code Snippets tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Code Snippets is not active' );
		}
		$args = is_array( $args ) ? $args : [];

		switch ( $name ) {
			case 'snippets_list':
				return self::list_snippets( $args );
			case 'snippets_get':
				return self::get( $args );
			case 'snippets_recently_active':
				return self::recently_active();
			case 'snippets_deactivate':
				return self::deactivate( $args );
			case 'snippets_update_metadata':
				return self::update_metadata( $args );
			default:
				throw new \Exception( 'Unknown Code Snippets tool: ' . esc_html( (string) $name ) );
		}
	}

	private static function list_snippets( array $args ) {
		$status = isset( $args['status'] ) && is_string( $args['status'] ) ? $args['status'] : 'all';
		if ( ! in_array( $status, [ 'all', 'active', 'inactive', 'trashed' ], true ) ) {
			throw new \Exception( 'status must be all, active, inactive or trashed.' );
		}
		$type = isset( $args['type'] ) && is_string( $args['type'] ) && '' !== $args['type'] ? $args['type'] : '';
		if ( '' !== $type && ! in_array( $type, [ 'php', 'html', 'css', 'js' ], true ) ) {
			throw new \Exception( 'type must be php, html, css or js.' );
		}
		$search   = isset( $args['search'] ) && is_string( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
		$per_page = (int) max( 1, min( is_scalar( $args['per_page'] ?? null ) ? (float) $args['per_page'] : 50, 100 ) );
		$page     = (int) max( 1, min( is_scalar( $args['page'] ?? null ) ? (float) $args['page'] : 1, 1000000 ) );

		$rows = [];
		foreach ( \Code_Snippets\get_snippets( [], false ) as $snippet ) {
			$trashed = ! empty( $snippet->trashed );
			if ( ( 'trashed' === $status ) !== $trashed ) {
				continue;
			}
			if ( 'active' === $status && empty( $snippet->active ) ) {
				continue;
			}
			if ( 'inactive' === $status && ! empty( $snippet->active ) ) {
				continue;
			}
			if ( '' !== $type && $snippet->get_type() !== $type ) {
				continue;
			}
			if ( '' !== $search && false === stripos( $snippet->name . ' ' . $snippet->desc . ' ' . implode( ' ', (array) $snippet->tags ), $search ) ) {
				continue;
			}
			$rows[] = self::summary( $snippet );
		}
		usort( $rows, static function ( $a, $b ) { return $a['id'] <=> $b['id']; } );
		$total = count( $rows );
		return [
			'snippets'    => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => (int) max( 1, ceil( $total / $per_page ) ),
		];
	}

	private static function get( array $args ) {
		$snippet = self::find( $args );
		$code    = (string) $snippet->code;
		$out     = self::summary( $snippet );
		$clean   = strlen( $code ) > self::MAX_CODE_BYTES ? null : \Royal_MCP\MCP\Support\Code_Redactor::redact( $code, $snippet->get_type() );
		$out['code']          = null === $clean ? '' : $clean;
		$out['code_withheld'] = null === $clean;
		if ( null === $clean ) {
			$out['code_withheld_reason'] = strlen( $code ) > self::MAX_CODE_BYTES
				? 'The code is larger than 256 KB; read it in the Snippets screen.'
				: 'The code could not be checked for credentials, so it is not returned; read it in the Snippets screen.';
		}
		return $out;
	}

	private static function recently_active() {
		$recent = get_option( 'recently_active_snippets', [] );
		$recent = is_array( $recent ) ? $recent : [];
		arsort( $recent );
		$out = [];
		foreach ( $recent as $id => $time ) {
			$snippet = \Code_Snippets\get_snippet( (int) $id, false );
			if ( ! $snippet || ! $snippet->id ) {
				continue;
			}
			$out[] = [
				'id'             => (int) $snippet->id,
				'name'           => (string) $snippet->name,
				'type'           => $snippet->get_type(),
				'deactivated_at' => gmdate( 'c', (int) $time ),
				'active'         => ! empty( $snippet->active ),
			];
		}
		return [ 'snippets' => $out ];
	}

	private static function deactivate( array $args ) {
		$snippet = self::find( $args );
		self::refuse_locked( $snippet );
		if ( empty( $snippet->active ) ) {
			return Envelope::success( sprintf( 'Snippet %d is already inactive.', $snippet->id ), [ 'id' => (int) $snippet->id, 'active' => false, 'changed' => false ] );
		}
		$after = \Code_Snippets\deactivate_snippet( (int) $snippet->id, false );
		if ( ! $after || ! empty( $after->active ) ) {
			throw new \Exception( 'The snippet could not be deactivated.' );
		}
		return Envelope::success(
			sprintf( 'Deactivated snippet %d (%s). Turn it back on in the Snippets screen after fixing it; Royal MCP does not activate snippets.', $after->id, $after->name ),
			[ 'id' => (int) $after->id, 'active' => false, 'changed' => true ]
		);
	}

	private static function update_metadata( array $args ) {
		$snippet = self::find( $args );
		self::refuse_locked( $snippet );
		$fields = self::metadata_fields( $args );
		if ( [] === $fields ) {
			throw new \Exception( 'Pass name, description or tags.' );
		}
		$before = self::metadata( $snippet );
		self::write_metadata( (int) $snippet->id, $fields );
		$after = self::metadata( \Code_Snippets\get_snippet( (int) $snippet->id, false ) );

		$undo = Undo_Store::store( [
			'op'           => 'snippets_update_metadata',
			'summary'      => sprintf( 'Restore the name, description and tags of snippet %d', $snippet->id ),
			'target'       => [ 'snippet_id' => (int) $snippet->id ],
			'pre_op_state' => [ 'prior_values' => $before, 'applied_values' => $after ],
		] );
		return Envelope::success( sprintf( 'Updated snippet %d.', $snippet->id ), [ 'id' => (int) $snippet->id, 'before' => $before, 'after' => $after ], $undo );
	}

	/** Undo of a metadata change; refuses when the snippet changed again since. */
	public static function undo( array $snapshot ) {
		if ( ! self::is_available() ) {
			throw new \Exception( 'Code Snippets is no longer active, so this change cannot be undone.' );
		}
		if ( ! current_user_can( self::cap() ) ) {
			throw new \Exception( 'You do not have permission to change snippets.' );
		}
		if ( 'snippets_update_metadata' !== ( $snapshot['op'] ?? '' ) ) {
			throw new \Exception( 'Unsupported Code Snippets undo.' );
		}
		$id      = (int) ( $snapshot['target']['snippet_id'] ?? 0 );
		$snippet = $id > 0 ? \Code_Snippets\get_snippet( $id, false ) : null;
		if ( ! $snippet || ! $snippet->id ) {
			throw new \Exception( 'Snippet ' . (int) $id . ' no longer exists, so there is nothing to undo.' );
		}
		self::refuse_locked( $snippet );
		$pre = (array) ( $snapshot['pre_op_state'] ?? [] );
		if ( self::metadata( $snippet ) !== (array) ( $pre['applied_values'] ?? [] ) ) {
			throw new \Exception( 'Cannot undo: snippet ' . (int) $id . '\'s name, description or tags changed after the tracked change.' );
		}
		$prior = (array) ( $pre['prior_values'] ?? [] );
		self::write_metadata( $id, [ 'name' => (string) ( $prior['name'] ?? '' ), 'desc' => (string) ( $prior['description'] ?? '' ), 'tags' => (array) ( $prior['tags'] ?? [] ) ] );
		return Envelope::success( sprintf( 'Restored snippet %d\'s name, description and tags.', $id ), [ 'undone' => true, 'op' => 'snippets_update_metadata', 'id' => $id ] );
	}

	// ============ Helpers ============

	private static function cap() {
		if ( function_exists( '\Code_Snippets\code_snippets' ) ) {
			return \Code_Snippets\code_snippets()->get_cap();
		}
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/** One answer for a missing snippet and an invalid ID, so IDs cannot be probed. */
	private static function find( array $args ) {
		$id = $args['id'] ?? null;
		$id = is_int( $id ) ? $id : ( is_string( $id ) && ctype_digit( $id ) ? (int) $id : 0 );
		$snippet = $id > 0 ? \Code_Snippets\get_snippet( $id, false ) : null;
		if ( ! $snippet || (int) $snippet->id !== $id ) {
			throw new \Exception( 'No snippet with that ID.' );
		}
		return $snippet;
	}

	private static function refuse_locked( $snippet ) {
		if ( ! empty( $snippet->locked ) ) {
			throw new \Exception( sprintf( 'Snippet %d is locked; unlock it in the Snippets screen first.', $snippet->id ) );
		}
	}

	private static function summary( $snippet ) {
		return [
			'id'          => (int) $snippet->id,
			'name'        => (string) $snippet->name,
			'description' => (string) $snippet->desc,
			'type'        => $snippet->get_type(),
			'scope'       => (string) $snippet->scope,
			'active'      => ! empty( $snippet->active ),
			'trashed'     => ! empty( $snippet->trashed ),
			'locked'      => ! empty( $snippet->locked ),
			'priority'    => (int) $snippet->priority,
			'tags'        => array_values( array_map( 'strval', (array) $snippet->tags ) ),
			'modified'    => (string) $snippet->modified,
			'code_bytes'  => strlen( (string) $snippet->code ),
		];
	}

	/**
	 * Write name, description and tags to the snippets table (columns name,
	 * description, tags; tags as a comma-separated list), then let Code
	 * Snippets react as it does to its own edits.
	 */
	private static function write_metadata( $id, array $fields ) {
		global $wpdb;
		$columns = [];
		if ( array_key_exists( 'name', $fields ) ) {
			$columns['name'] = (string) $fields['name'];
		}
		if ( array_key_exists( 'desc', $fields ) ) {
			$columns['description'] = (string) $fields['desc'];
		}
		if ( array_key_exists( 'tags', $fields ) ) {
			$columns['tags'] = implode( ', ', array_map( 'strval', (array) $fields['tags'] ) );
		}
		if ( ! $columns ) {
			return;
		}
		$table = \Code_Snippets\code_snippets()->db->get_table_name( false );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$written = $wpdb->update( $table, $columns, [ 'id' => (int) $id ], array_fill( 0, count( $columns ), '%s' ), [ '%d' ] );
		if ( false === $written ) {
			throw new \Exception( 'The snippet could not be updated.' );
		}
		\Code_Snippets\clean_snippets_cache( $table );
		$updated = \Code_Snippets\get_snippet( (int) $id, false );
		if ( $updated && $updated->id ) {
			do_action( 'code_snippets/update_snippet', $updated, $table );
		}
	}

	private static function metadata( $snippet ) {
		return [
			'name'        => (string) $snippet->name,
			'description' => (string) $snippet->desc,
			'tags'        => array_values( array_map( 'strval', (array) $snippet->tags ) ),
		];
	}

	private static function metadata_fields( array $args ) {
		$fields = [];
		if ( array_key_exists( 'name', $args ) ) {
			if ( ! is_string( $args['name'] ) || '' === trim( $args['name'] ) || strlen( $args['name'] ) > 255 ) {
				throw new \Exception( 'name must be text of 1 to 255 characters.' );
			}
			$fields['name'] = sanitize_text_field( $args['name'] );
		}
		if ( array_key_exists( 'description', $args ) ) {
			if ( ! is_string( $args['description'] ) || strlen( $args['description'] ) > 65535 ) {
				throw new \Exception( 'description must be text of at most 65535 bytes.' );
			}
			$fields['desc'] = wp_kses_post( $args['description'] );
		}
		if ( array_key_exists( 'tags', $args ) ) {
			if ( ! is_array( $args['tags'] ) || count( $args['tags'] ) > 50 ) {
				throw new \Exception( 'tags must be a list of at most 50 tags.' );
			}
			$tags = [];
			foreach ( $args['tags'] as $tag ) {
				if ( ! is_string( $tag ) ) {
					throw new \Exception( 'Each tag must be text.' );
				}
				if ( false !== strpos( $tag, ',' ) ) {
					throw new \Exception( 'A tag cannot contain a comma; pass each tag separately.' );
				}
				$tag = sanitize_text_field( $tag );
				if ( '' !== $tag ) {
					$tags[] = $tag;
				}
			}
			$fields['tags'] = array_values( array_unique( $tags ) );
		}
		return $fields;
	}
}
