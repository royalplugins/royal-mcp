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

	/**
	 * Writes that never change what visitors see: uploads (needed to build a
	 * draft), preview links, undo, and the dispatcher (the tool it calls is
	 * checked on its own).
	 */
	const ALWAYS_ALLOWED = [
		'execute_tool',
		'mcp_undo_last_operation',
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

		if ( array_key_exists( $name, self::CREATE_TOOLS ) ) {
			$status_arg = self::CREATE_TOOLS[ $name ];
			$status     = '' !== $status_arg && isset( $args[ $status_arg ] ) ? strtolower( (string) $args[ $status_arg ] ) : '';
			if ( in_array( $status, self::PUBLISHING_STATUSES, true ) ) {
				return sprintf( '%1$s would set the status to %2$s. Save it as a draft or pending instead.', $name, $status );
			}
			return null;
		}

		if ( array_key_exists( $name, self::EDIT_TOOLS ) ) {
			foreach ( self::EDIT_STATUS_ARGS as $status_arg ) {
				$status = isset( $args[ $status_arg ] ) ? strtolower( (string) $args[ $status_arg ] ) : '';
				if ( in_array( $status, self::PUBLISHING_STATUSES, true ) ) {
					return sprintf( '%1$s would set the status to %2$s. Drafts stay drafts; a person publishes them.', $name, $status );
				}
			}
			$post_id = 0;
			foreach ( self::EDIT_TOOLS[ $name ] as $id_arg ) {
				if ( isset( $args[ $id_arg ] ) && is_numeric( $args[ $id_arg ] ) && (int) $args[ $id_arg ] > 0 ) {
					$post_id = (int) $args[ $id_arg ];
					break;
				}
			}
			if ( $post_id <= 0 ) {
				return sprintf( '%s needs the ID of a draft or pending post.', $name );
			}
			if ( 'wp_restore_revision' === $name ) {
				$revision = get_post( $post_id );
				$post_id  = $revision && 'revision' === $revision->post_type ? (int) $revision->post_parent : 0;
				if ( $post_id <= 0 ) {
					return 'wp_restore_revision needs a revision of a draft or pending post.';
				}
			}
			$status = (string) get_post_status( $post_id );
			if ( '' === $status ) {
				return null; // Let the tool report the missing post itself.
			}
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
			&& ! in_array( $name, self::ALWAYS_ALLOWED, true )
			&& ! array_key_exists( $name, self::CREATE_TOOLS )
			&& ! array_key_exists( $name, self::EDIT_TOOLS );
	}
}
