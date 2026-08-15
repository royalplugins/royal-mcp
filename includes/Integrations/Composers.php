<?php
/**
 * Royal MCP — Cross-plugin composers.
 *
 * Free-tier workflow tools that chain multiple primitives into a single
 * agent-facing call. Best-effort composition semantics: post creation is
 * the anchor step (fails whole tool if it fails); downstream steps
 * (featured image, taxonomy, SEO meta) surface as warnings on failure
 * rather than rolling back the created post.
 *
 * Ships in Free 1.4.41:
 *   wp_publish_and_promote   Compose upload_media → create_post →
 *                            set_featured → add_terms → SEO meta.
 *
 * Method names + arg names + status-defaulted-to-publish warning mirror
 * the sibling Pro implementation (Royal_MCP_Pro\Integrations\Composers)
 * so a Pro-tier caller upgrading to the Pro variant sees the same
 * response shape with added compose_mode/rollback/dependencies_probed
 * fields on top.
 */

namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Composers {

	public static function get_tools() {
		return [
			[
				'name'        => 'wp_publish_and_promote',
				'description' => 'Publish a WordPress post in one call: optional featured image sideload, post creation, optional category (created if missing), optional tags (created if missing), optional SEO meta write (Yoast / Rank Math / SEObolt auto-detected). Best-effort composition — the post is the anchor step; if any downstream step (featured image, taxonomy, SEO) fails, the post is still created and the failure surfaces as a warning. Status defaults to "publish" (writes a LIVE post); pass status="draft" to stage. Supports scheduling via publish_date (future date auto-converts status to "future"). Note: payloads with backslash escape sequences (JSON unicode escapes, embedded JSON-LD, Divi loop field bindings) may not survive the MCP → REST → write pipeline as literal backslashes — decode client-side before sending or verify rendered output.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'title'              => [ 'type' => 'string',  'description' => 'Post title. Required.' ],
						'content'            => [ 'type' => 'string',  'description' => 'Post content (HTML). Required.' ],
						'status'             => [ 'type' => 'string',  'enum' => [ 'draft', 'publish', 'pending', 'private', 'future' ], 'description' => 'Default "publish" (LIVE). Pass "draft" to stage.' ],
						'publish_date'       => [ 'type' => 'string',  'description' => 'Optional. Format YYYY-MM-DD HH:MM:SS. If in the future and status=publish, post_status auto-becomes "future".' ],
						'featured_image_url' => [ 'type' => 'string',  'description' => 'Optional. Publicly-fetchable image URL to sideload as the featured image.' ],
						'category'           => [ 'type' => 'string',  'description' => 'Optional. Category name. Created if it does not exist.' ],
						'tags'               => [ 'type' => 'array',   'description' => 'Optional. Array of tag names. Created if they do not exist.' ],
						'seo_title'          => [ 'type' => 'string',  'description' => 'Optional. Written to the auto-detected SEO plugin (Yoast/Rank Math/SEObolt).' ],
						'seo_description'    => [ 'type' => 'string',  'description' => 'Optional. Written to the auto-detected SEO plugin.' ],
						'seo_focus_keyword'  => [ 'type' => 'string',  'description' => 'Optional. Written to the auto-detected SEO plugin.' ],
					],
					'required'   => [ 'title', 'content' ],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		switch ( $name ) {
			case 'wp_publish_and_promote':
				return self::handle_publish_and_promote( $args );

			default:
				return Envelope::error(
					'unknown_tool',
					sprintf( 'Unknown composer tool: %s', $name ),
					[ 'name' => $name ]
				);
		}
	}

	/**
	 * Handler for wp_publish_and_promote.
	 *
	 * Step order (matches Pro's handle_publish_and_promote_pro):
	 *   1. upload_featured_image (if featured_image_url)
	 *   2. create_post (anchor step — fails whole tool on failure)
	 *   3. set_featured_image (if attachment_id from step 1)
	 *   4. assign_category (creates term if missing)
	 *   5. assign_tags (creates terms if missing)
	 *   6. write_seo_meta (auto-detected SEO plugin)
	 *
	 * Best-effort mode: any step after create_post that fails surfaces as
	 * a warning in the response but does not undo the post. Pro's version
	 * adds compose_mode=strict (with full rollback) + pre_backup +
	 * post_purge_cache on top; Free stays at best-effort per scope.
	 */
	public static function handle_publish_and_promote( array $args ) {
		if ( ! current_user_can( 'publish_posts' ) ) {
			return Envelope::error(
				'insufficient_caps',
				'publish_posts capability required.'
			);
		}

		$title   = isset( $args['title'] )   ? sanitize_text_field( (string) $args['title'] )   : '';
		$content = isset( $args['content'] ) ? (string) $args['content']                        : '';
		if ( '' === $title ) {
			return Envelope::error( 'invalid_args', 'title is required.' );
		}
		if ( '' === $content ) {
			return Envelope::error( 'invalid_args', 'content is required.' );
		}

		$status_explicit = isset( $args['status'] );
		$status          = $status_explicit ? sanitize_key( (string) $args['status'] ) : 'publish';
		if ( ! in_array( $status, [ 'draft', 'publish', 'pending', 'private', 'future' ], true ) ) {
			$status = 'publish';
		}
		$publish_date = isset( $args['publish_date'] )       ? sanitize_text_field( (string) $args['publish_date'] )       : '';
		$featured_url = isset( $args['featured_image_url'] ) ? esc_url_raw( (string) $args['featured_image_url'] )         : '';
		$category     = isset( $args['category'] )           ? sanitize_text_field( (string) $args['category'] )           : '';
		$tags         = ( isset( $args['tags'] ) && is_array( $args['tags'] ) )
			? array_map( 'sanitize_text_field', $args['tags'] )
			: [];
		$seo_title    = isset( $args['seo_title'] )          ? sanitize_text_field( (string) $args['seo_title'] )          : '';
		$seo_desc     = isset( $args['seo_description'] )    ? sanitize_text_field( (string) $args['seo_description'] )    : '';
		$seo_focus_kw = isset( $args['seo_focus_keyword'] )  ? sanitize_text_field( (string) $args['seo_focus_keyword'] )  : '';

		$steps    = [];
		$warnings = [];

		// Step 1: sideload featured image (best-effort)
		$attachment_id = 0;
		if ( '' !== $featured_url ) {
			$sideload = self::sideload_media( $featured_url );
			if ( is_wp_error( $sideload ) ) {
				$msg = $sideload->get_error_message();
				$steps[]    = [ 'name' => 'upload_featured_image', 'status' => 'error', 'message' => $msg ];
				$warnings[] = [ 'step' => 'upload_featured_image', 'message' => $msg ];
			} else {
				$attachment_id = (int) $sideload;
				$steps[] = [ 'name' => 'upload_featured_image', 'status' => 'ok', 'data' => [ 'attachment_id' => $attachment_id ] ];
			}
		}

		// Step 2: create post (ANCHOR — whole tool fails if this fails)
		$insert = [
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_type'    => 'post',
			'post_author'  => get_current_user_id() ?: 0,
		];
		if ( '' !== $publish_date ) {
			// Validate parseability before feeding to wp_insert_post — otherwise
			// garbage input can create posts with invalid date fields that
			// break sorting and archive queries downstream.
			$parsed = strtotime( $publish_date );
			if ( false === $parsed ) {
				$warnings[] = [ 'step' => 'publish_date', 'message' => sprintf( 'publish_date "%s" could not be parsed; using current time.', $publish_date ) ];
			} else {
				$insert['post_date']     = $publish_date;
				$insert['post_date_gmt'] = get_gmt_from_date( $publish_date );
				if ( 'publish' === $status && $parsed > time() ) {
					$insert['post_status'] = 'future';
				}
			}
		}
		$post_id = wp_insert_post( $insert, true );
		if ( is_wp_error( $post_id ) ) {
			$steps[] = [ 'name' => 'create_post', 'status' => 'error', 'message' => $post_id->get_error_message() ];
			return Envelope::error(
				'post_create_failed',
				$post_id->get_error_message(),
				[ 'steps' => $steps ]
			);
		}
		$post_id = (int) $post_id;
		$steps[] = [ 'name' => 'create_post', 'status' => 'ok', 'data' => [ 'post_id' => $post_id ] ];

		// Step 3: set featured image (only if step 1 succeeded)
		if ( $attachment_id > 0 ) {
			set_post_thumbnail( $post_id, $attachment_id );
			$steps[] = [ 'name' => 'set_featured_image', 'status' => 'ok' ];
		}

		// Step 4: assign category (create-if-missing)
		$category_id = null;
		if ( '' !== $category ) {
			$term = term_exists( $category, 'category' );
			if ( ! $term ) {
				$term = wp_insert_term( $category, 'category' );
			}
			if ( is_wp_error( $term ) ) {
				$msg = $term->get_error_message();
				$steps[]    = [ 'name' => 'assign_category', 'status' => 'error', 'message' => $msg ];
				$warnings[] = [ 'step' => 'assign_category', 'message' => $msg ];
			} else {
				$category_id = (int) $term['term_id'];
				wp_set_object_terms( $post_id, $category_id, 'category', false );
				$steps[] = [ 'name' => 'assign_category', 'status' => 'ok', 'data' => [ 'term_id' => $category_id ] ];
			}
		}

		// Step 5: assign tags (create-if-missing via wp_set_post_tags)
		$tag_ids = [];
		if ( ! empty( $tags ) ) {
			$tag_result = wp_set_post_tags( $post_id, $tags, false );
			if ( is_wp_error( $tag_result ) ) {
				$msg = $tag_result->get_error_message();
				$steps[]    = [ 'name' => 'assign_tags', 'status' => 'error', 'message' => $msg ];
				$warnings[] = [ 'step' => 'assign_tags', 'message' => $msg ];
			} elseif ( ! is_array( $tag_result ) ) {
				// wp_set_post_tags can return false on invalid post_id;
				// shouldn't happen since we just created it, but guarding
				// against silent [0] tag_ids from array-casting false.
				$steps[]    = [ 'name' => 'assign_tags', 'status' => 'error', 'message' => 'wp_set_post_tags returned false; tags not assigned.' ];
				$warnings[] = [ 'step' => 'assign_tags', 'message' => 'wp_set_post_tags returned false; tags not assigned.' ];
			} else {
				$tag_ids = array_map( 'intval', $tag_result );
				$steps[] = [ 'name' => 'assign_tags', 'status' => 'ok', 'data' => [ 'count' => count( $tag_ids ) ] ];
			}
		}

		// Step 6: write SEO meta (auto-detect plugin)
		$seo_plugin = 'none';
		if ( '' !== $seo_title || '' !== $seo_desc || '' !== $seo_focus_kw ) {
			$seo = self::write_seo_meta( $post_id, $seo_title, $seo_desc, $seo_focus_kw );
			$seo_plugin = (string) $seo['plugin'];
			if ( ! $seo['ok'] ) {
				$steps[]    = [ 'name' => 'write_seo_meta', 'status' => 'skipped', 'message' => $seo['message'] ];
				$warnings[] = [ 'step' => 'write_seo_meta', 'message' => $seo['message'] ];
			} else {
				$steps[] = [ 'name' => 'write_seo_meta', 'status' => 'ok', 'data' => [ 'plugin' => $seo_plugin, 'fields_written' => $seo['fields_written'] ] ];
			}
		}

		// Status-default warning — matches Pro's exact phrasing (envelope
		// mirror ensures this note reaches clients that drop structuredContent).
		if ( ! $status_explicit && 'publish' === $status ) {
			$warnings[] = [
				'step'    => 'status_default',
				'message' => 'status defaulted to "publish" — this composer wrote a LIVE post. If you meant to stage first, pass status="draft" explicitly.',
			];
		}

		$post_obj = get_post( $post_id );

		$structured = [
			'post_id'           => $post_id,
			'url'               => (string) get_permalink( $post_id ),
			'featured_media_id' => $attachment_id ?: null,
			'seo_plugin'        => $seo_plugin,
			'category_id'       => $category_id,
			'tag_ids'           => $tag_ids,
			'published_at'      => $post_obj ? (string) $post_obj->post_date_gmt : null,
			'post_status'       => $post_obj ? (string) $post_obj->post_status : $status,
			'steps'             => $steps,
		];
		if ( ! empty( $warnings ) ) {
			$structured['warnings'] = $warnings;
		}

		$summary = sprintf(
			'Post "%s" created (id=%d, status=%s%s%s).',
			$title,
			$post_id,
			$structured['post_status'],
			$attachment_id ? sprintf( ', featured=%d', $attachment_id ) : '',
			! empty( $warnings ) ? sprintf( ', %d warning(s)', count( $warnings ) ) : ''
		);

		return Envelope::success( $summary, $structured );
	}

	// ----------------------------------------------------------------------
	// Internal helpers
	// ----------------------------------------------------------------------

	/**
	 * Sideload a remote image URL into the WordPress media library and
	 * return the attachment ID. Uses WP core's media_sideload_image with
	 * 'id' return type. Requires the wp-admin/includes/* files to be
	 * loaded (they typically aren't in a REST context).
	 *
	 * @return int|\WP_Error attachment_id on success, WP_Error on failure.
	 */
	private static function sideload_media( $url ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$result = media_sideload_image( $url, 0, null, 'id' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return (int) $result;
	}

	/**
	 * Auto-detect the active SEO plugin. Mirrors the detection order in
	 * Server::detect_seo_plugin so both surfaces agree on which plugin
	 * "owns" the SEO meta writes.
	 *
	 * @return string 'yoast' | 'rankmath' | 'aioseo' | 'seobolt' | 'none'
	 */
	private static function detect_seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			return 'aioseo';
		}
		if ( defined( 'SEOBOLT_VERSION' ) ) {
			return 'seobolt';
		}
		return 'none';
	}

	/**
	 * Dispatch SEO meta write to the detected plugin's postmeta keys.
	 * Only writes non-empty fields.
	 *
	 * AIOSEO uses a custom table in newer versions (not postmeta), so
	 * this composer skips it with a message; callers who need AIOSEO
	 * should call wp_update_seo_meta directly after the post is created.
	 *
	 * @return array{ok: bool, plugin: string, message?: string, fields_written?: array<int, string>}
	 */
	private static function write_seo_meta( $post_id, $title, $desc, $focus_kw ) {
		$plugin = self::detect_seo_plugin();
		if ( 'none' === $plugin ) {
			return [ 'ok' => false, 'plugin' => 'none', 'message' => 'No SEO plugin detected.' ];
		}

		$fields = [];
		$map    = [];
		switch ( $plugin ) {
			case 'yoast':
				$map = [
					'title'         => '_yoast_wpseo_title',
					'description'   => '_yoast_wpseo_metadesc',
					'focus_keyword' => '_yoast_wpseo_focuskw',
				];
				break;
			case 'rankmath':
				$map = [
					'title'         => 'rank_math_title',
					'description'   => 'rank_math_description',
					'focus_keyword' => 'rank_math_focus_keyword',
				];
				break;
			case 'seobolt':
				$map = [
					'title'         => '_seobolt_title',
					'description'   => '_seobolt_description',
					'focus_keyword' => '_seobolt_focus_keyword',
				];
				break;
			case 'aioseo':
				return [
					'ok'      => false,
					'plugin'  => 'aioseo',
					'message' => 'AIOSEO uses a custom table for meta storage. Use wp_update_seo_meta directly after the post is created.',
				];
		}

		if ( '' !== $title ) {
			update_post_meta( $post_id, $map['title'], $title );
			$fields[] = 'title';
		}
		if ( '' !== $desc ) {
			update_post_meta( $post_id, $map['description'], $desc );
			$fields[] = 'description';
		}
		if ( '' !== $focus_kw ) {
			update_post_meta( $post_id, $map['focus_keyword'], $focus_kw );
			$fields[] = 'focus_keyword';
		}

		return [ 'ok' => true, 'plugin' => $plugin, 'fields_written' => $fields ];
	}
}
