<?php
/**
 * Drafts-only rules: which tool calls may run when AI clients are limited to
 * drafts. New content may be created as a draft or pending; existing content
 * may be changed only while it is a draft or pending; nothing else on the
 * site may change.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Drafts_Policy {

	/** Post statuses that count as not published. */
	const DRAFT_STATUSES = [ 'draft', 'pending', 'auto-draft' ];

	/** Statuses a call may not ask for. */
	const PUBLISHING_STATUSES = [ 'publish', 'future', 'private' ];

	/** The only statuses a call may ask for while drafts-only applies. */
	const SAFE_STATUSES = [ 'draft', 'pending' ];

	/** Post-id arguments the handlers read, checked on every edit tool. */
	const ID_KEYS = [ 'id', 'post_id' ];

	/**
	 * Undo is allowed only for these content changes, and only while their
	 * post is still a draft. Value: the snapshot target key naming the post.
	 */
	const UNDO_CONTENT_OPS = [
		'wp_update_post'                   => 'post_id',
		'wp_update_post_meta'              => 'post_id',
		'wp_add_post_meta'                 => 'post_id',
		'wp_update_seo_meta'               => 'post_id',
		'elementor_replace_text'           => 'post_id',
		'elementor_replace_image'          => 'post_id',
		'elementor_add_widget'             => 'post_id',
		'elementor_apply_template_to_page' => 'post_id',
		'elementor_rebuild_post_content'   => 'post_id',
		'divi_replace_image'               => 'post_id',
		'divi_import_template'             => 'target_post_id',
	];

	/**
	 * Writes that never change what visitors see: uploads (needed to build a
	 * draft), preview links, and the dispatcher (the tool it calls is checked
	 * on its own). Undo is checked against its snapshot instead.
	 */
	const ALWAYS_ALLOWED = [
		'execute_tool',
		'wp_upload_media',
		'wp_upload_media_from_url',
		'wp_create_preview_link',
	];

	/** Tools that create new content, and the argument that sets its status. */
	const CREATE_TOOLS = [
		'wp_create_post'            => 'status',
		'wp_create_page'            => 'status',
		'wp_publish_and_promote'    => 'status',
		'elementor_clone_page'      => 'new_status',
		'divi_clone_page'           => 'new_status',
		// Library templates and new patterns stay inert until used on a page.
		'elementor_import_template' => '',
		'wp_create_synced_pattern'  => '',
	];

	/** Tools that change one existing post, and the arguments that name it. */
	const EDIT_TOOLS = [
		'wp_update_post'                   => [ 'id', 'post_id' ],
		'wp_update_page'                   => [ 'id', 'post_id' ],
		'wp_replace_in_post'               => [ 'id', 'post_id' ],
		'wp_replace_in_page'               => [ 'id', 'post_id' ],
		'wp_delete_post'                   => [ 'id', 'post_id' ],
		'wp_delete_page'                   => [ 'id', 'post_id' ],
		'wp_set_featured_image'            => [ 'post_id' ],
		'wp_add_post_terms'                => [ 'post_id', 'id' ],
		'wp_update_post_meta'              => [ 'post_id', 'id' ],
		'wp_add_post_meta'                 => [ 'post_id', 'id' ],
		'wp_delete_post_meta'              => [ 'post_id', 'id' ],
		'wp_update_seo_meta'               => [ 'post_id', 'id' ],
		'yoast_update_meta'                => [ 'post_id', 'id' ],
		'elementor_replace_text'           => [ 'post_id' ],
		'elementor_replace_image'          => [ 'post_id' ],
		'elementor_add_widget'             => [ 'post_id' ],
		'elementor_rebuild_post_content'   => [ 'post_id' ],
		'elementor_apply_template_to_page' => [ 'target_post_id' ],
		'divi_replace_text'                => [ 'post_id' ],
		'divi_replace_image'               => [ 'post_id' ],
		'divi_import_template'             => [ 'target_post_id' ],
		'acf_update_field'                 => [ 'post_id' ],
		'wp_restore_revision'              => [ 'revision_id' ],
		'wp_insert_blocks'                 => [ 'id', 'post_id' ],
		'wp_replace_block'                 => [ 'id', 'post_id' ],
		'wp_move_block'                    => [ 'id', 'post_id' ],
		'wp_delete_block'                  => [ 'id', 'post_id' ],
		'wp_replace_text_in_blocks'        => [ 'id', 'post_id' ],
		'wp_update_block_attributes'       => [ 'id', 'post_id' ],
		// A synced pattern is published content shared by every page that uses it.
		'wp_update_synced_pattern'         => [ 'id' ],
	];

	/** Status-changing arguments on the edit tools. */
	const EDIT_STATUS_ARGS = [ 'status' ];

	/**
	 * Why a call is refused under drafts-only rules, or null when it may run.
	 *
	 * @param string $name      Tool name.
	 * @param array  $args      Tool arguments.
	 * @param bool   $read_only Whether the tool only reads.
	 * @return string|null
	 */
	public static function refusal( $name, array $args, $read_only ) {
		$name = (string) $name;
		if ( $read_only || in_array( $name, self::ALWAYS_ALLOWED, true ) ) {
			return null;
		}
		if ( 'mcp_undo_last_operation' === $name ) {
			return self::undo_refusal( $args );
		}

		if ( array_key_exists( $name, self::CREATE_TOOLS ) ) {
			$status_arg = self::CREATE_TOOLS[ $name ];
			if ( '' !== $status_arg && array_key_exists( $status_arg, $args ) ) {
				return self::status_refusal( $name, $args[ $status_arg ] );
			}
			return null;
		}

		if ( array_key_exists( $name, self::EDIT_TOOLS ) ) {
			foreach ( self::EDIT_STATUS_ARGS as $status_arg ) {
				if ( array_key_exists( $status_arg, $args ) ) {
					$why = self::status_refusal( $name, $args[ $status_arg ] );
					if ( null !== $why ) {
						return $why;
					}
				}
			}
			// Every post-id argument the call carries, read the way the handlers
			// read them (intval), so the guard and the handler see the same post.
			$ids = [];
			foreach ( array_unique( array_merge( self::EDIT_TOOLS[ $name ], self::ID_KEYS ) ) as $id_arg ) {
				if ( array_key_exists( $id_arg, $args ) && intval( $args[ $id_arg ] ) > 0 ) {
					$ids[ $id_arg ] = intval( $args[ $id_arg ] );
				}
			}
			if ( ! $ids ) {
				return sprintf( '%s needs the ID of a draft or pending post.', $name );
			}
			if ( count( array_unique( $ids ) ) > 1 ) {
				return sprintf( '%s was given more than one post ID (%s). Pass a single post ID.', $name, implode( ', ', array_map( static function ( $k, $v ) { return $k . ' ' . $v; }, array_keys( $ids ), $ids ) ) );
			}
			$post_id = (int) reset( $ids );
			if ( 'wp_restore_revision' === $name ) {
				$revision = get_post( $post_id );
				$post_id  = $revision && 'revision' === $revision->post_type ? (int) $revision->post_parent : 0;
				if ( $post_id <= 0 ) {
					return 'wp_restore_revision needs a revision of a draft or pending post.';
				}
			}
			if ( ! get_post( $post_id ) ) {
				return null; // Let the tool report the missing post itself.
			}
			// Name the status only to someone who may edit the post.
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return sprintf( '%s needs a draft or pending post you can edit.', $name );
			}
			$status = (string) get_post_status( $post_id );
			if ( ! in_array( $status, self::DRAFT_STATUSES, true ) ) {
				return sprintf(
					'post %1$d is %2$s, and only drafts and pending posts can be changed. Clone it as a draft first (elementor_clone_page or divi_clone_page for builder pages) and edit the copy.',
					$post_id,
					'publish' === $status ? 'published' : $status
				);
			}
			return null;
		}

		return sprintf( 'only drafts and pending posts can be changed, and %s changes something else on the site.', $name );
	}

	/**
	 * Why a requested status is refused, or null. The value must read as draft
	 * or pending however a handler sanitizes it.
	 *
	 * @param string $name Tool name.
	 * @param mixed  $raw  Status argument as sent.
	 * @return string|null
	 */
	private static function status_refusal( $name, $raw ) {
		$text = is_scalar( $raw ) ? (string) $raw : '';
		if ( is_scalar( $raw ) && '' === trim( $text ) ) {
			return null;
		}
		$forms = [ strtolower( trim( $text ) ), strtolower( sanitize_text_field( $text ) ), sanitize_key( $text ) ];
		foreach ( $forms as $form ) {
			if ( ! in_array( $form, self::SAFE_STATUSES, true ) ) {
				return sprintf( '%1$s may only set the status to draft or pending (asked for "%2$s"). Drafts stay drafts; a person publishes them.', $name, sanitize_text_field( $text ) );
			}
		}
		return null;
	}

	/**
	 * Undo runs only when it reverts a content change on a post that is still a
	 * draft, and would not put a published status back.
	 *
	 * @param array $args Undo arguments.
	 * @return string|null
	 */
	private static function undo_refusal( array $args ) {
		$token    = isset( $args['token'] ) && is_scalar( $args['token'] ) ? sanitize_text_field( (string) $args['token'] ) : '';
		$snapshot = '' !== $token ? \Royal_MCP\MCP\Undo_Store::read( $token ) : null;
		if ( ! is_array( $snapshot ) ) {
			return null; // The undo tool gives its usual answer for unknown tokens.
		}
		$op = (string) ( $snapshot['op'] ?? '' );
		if ( ! isset( self::UNDO_CONTENT_OPS[ $op ] ) ) {
			return sprintf( 'undoing %s changes more than a draft\'s content, so it is not allowed while drafts-only applies.', $op );
		}
		$target  = (array) ( $snapshot['target'] ?? [] );
		$post_id = (int) ( $target[ self::UNDO_CONTENT_OPS[ $op ] ] ?? ( $target['post_id'] ?? 0 ) );
		$status  = $post_id > 0 ? (string) get_post_status( $post_id ) : '';
		if ( ! in_array( $status, self::DRAFT_STATUSES, true ) ) {
			return 'this undo would change a post that is not a draft or pending post, so it is not allowed while drafts-only applies.';
		}
		$prior_status = (string) ( $snapshot['pre_op_state']['prior_values']['post_status'] ?? '' );
		if ( '' !== $prior_status && ! in_array( $prior_status, self::DRAFT_STATUSES, true ) ) {
			return sprintf( 'this undo would set the post back to %s, so it is not allowed while drafts-only applies.', $prior_status );
		}
		return null;
	}

	/**
	 * Whether a tool can never run under drafts-only rules (it is neither a
	 * read, nor allowed, nor a create or edit tool). Used to mark tool lists.
	 *
	 * @param string $name      Tool name.
	 * @param bool   $read_only Whether the tool only reads.
	 * @return bool
	 */
	public static function always_refused( $name, $read_only ) {
		$name = (string) $name;
		return ! $read_only
			&& 'mcp_undo_last_operation' !== $name
			&& ! in_array( $name, self::ALWAYS_ALLOWED, true )
			&& ! array_key_exists( $name, self::CREATE_TOOLS )
			&& ! array_key_exists( $name, self::EDIT_TOOLS );
	}
}
