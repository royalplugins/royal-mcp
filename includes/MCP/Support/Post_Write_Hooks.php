<?php
namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridge Royal MCP meta-only writes to the WordPress save_post signal.
 *
 * WordPress's update_post_meta() fires updated_post_meta / updated_{meta_type}_meta,
 * NOT save_post. Downstream consumers that watch save_post to invalidate caches
 * or rebuild derived data (page-cache plugins, Yoast Indexable Builder's OG
 * image column, sitemap generators, SEO snapshot systems) miss meta-only writes
 * originated by Royal MCP tools. Result: post edits made by an AI agent update
 * the DB successfully but leave served HTML stale and Yoast's OG image presenter
 * rendering from a stale indexable row.
 *
 * Every Royal MCP tool that ends with an update_post_meta() call on a post
 * should call Post_Write_Hooks::trigger($post_id) as its last write step to
 * bridge the gap. Idempotent — safe to call multiple times per request.
 *
 * Filterable via `royal_mcp_fire_save_post_after_write` — return false to
 * suppress for hosts where save_post subscribers produce unwanted side effects
 * (heavy notification chains, external-service pings, etc.). Default true.
 */
class Post_Write_Hooks {

	/**
	 * Fire clean_post_cache + save_post + post_updated for a post that was
	 * mutated via a meta-only path. Safe on invalid / missing post IDs.
	 *
	 * @param int $post_id Post ID that received the write.
	 * @return void
	 */
	public static function trigger( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		/**
		 * Allow hosts to suppress the auto-fired save_post if their plugin
		 * stack does heavy work in save_post subscribers that shouldn't run
		 * on a meta-only edit.
		 */
		if ( ! apply_filters( 'royal_mcp_fire_save_post_after_write', true, $post_id, $post ) ) {
			return;
		}

		clean_post_cache( $post_id );
		do_action( 'save_post', $post_id, $post, true );
		do_action( 'post_updated', $post_id, $post, $post );

		self::bump_modified_timestamp( $post_id );
		self::rebuild_yoast_indexable( $post_id );
	}

	/**
	 * Bump post_modified + post_modified_gmt to now for meta-only writes.
	 *
	 * Correct WordPress behavior is that update_post_meta / set_post_thumbnail
	 * do NOT touch post_modified — they mutate the meta table, not wp_posts.
	 * But downstream consumers keyed on post_modified — sitemap generators,
	 * RSS feed regenerators, search-index rebuilders, staleness detectors —
	 * never fire for MCP meta operations, leaving derived artifacts stale
	 * indefinitely.
	 *
	 * Written via direct $wpdb->update on wp_posts to avoid firing save_post
	 * recursively (which trigger() already fired above). Filterable via
	 * `royal_mcp_bump_modified_on_meta_write` — return false for hosts that
	 * reject modification-timestamp mutation for meta-only writes.
	 *
	 * Silent when the post row is gone (deleted between trigger call and
	 * this write); nothing to do, no error.
	 */
	private static function bump_modified_timestamp( $post_id ) {
		if ( ! apply_filters( 'royal_mcp_bump_modified_on_meta_write', true, $post_id ) ) {
			return;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! $wpdb ) {
			return;
		}
		$now_local = current_time( 'mysql', 0 );
		$now_gmt   = current_time( 'mysql', 1 );
		$wpdb->update(
			$wpdb->posts,
			[
				'post_modified'     => $now_local,
				'post_modified_gmt' => $now_gmt,
			],
			[ 'ID' => (int) $post_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		clean_post_cache( $post_id );
	}

	/**
	 * Force a synchronous Yoast indexable rebuild for the given post.
	 *
	 * Yoast's Indexable_Post_Meta_Watcher subscribes to updated_post_meta and
	 * queues the post_id for rebuild — the actual rebuild runs async (WP-Cron,
	 * shutdown, or on next request). Between the AI tool's write and the AI
	 * agent's read-back, the queued rebuild may not have executed, so a
	 * follow-up yoast_get_meta / seo_audit_meta_tags call sees stale
	 * open_graph_image, twitter_image, and other derived fields.
	 *
	 * Directly invoking Indexable_Builder->build() and save() forces the
	 * rebuild synchronously so the AI agent's next tool call reads the
	 * up-to-date resolved values.
	 *
	 * Filterable via `royal_mcp_yoast_sync_rebuild` — return false to keep
	 * the async queue behavior (rare — only useful for hosts where the
	 * synchronous rebuild is measurably slow at their scale).
	 *
	 * No-op when Yoast SEO is not loaded, when the container / builder /
	 * repository classes don't exist (guards against Yoast version drift),
	 * or when the indexable row doesn't yet exist for this post.
	 */
	private static function rebuild_yoast_indexable( $post_id ) {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return;
		}
		if ( ! apply_filters( 'royal_mcp_yoast_sync_rebuild', true, $post_id ) ) {
			return;
		}
		// Yoast's Symfony DI container registers services WITHOUT a leading
		// backslash; passing '\Yoast\...' throws ServiceNotFoundException.
		// class_exists accepts either form, container->get() does not.
		$repository_class = 'Yoast\\WP\\SEO\\Repositories\\Indexable_Repository';
		$builder_class    = 'Yoast\\WP\\SEO\\Builders\\Indexable_Builder';
		if ( ! class_exists( $repository_class ) || ! class_exists( $builder_class ) ) {
			return;
		}
		try {
			$container  = YoastSEO()->classes;
			$repository = $container->get( $repository_class );
			$builder    = $container->get( $builder_class );
			$indexable  = $repository->find_by_id_and_type( $post_id, 'post', false );
			if ( ! $indexable ) {
				return;
			}
			$rebuilt = $builder->build( $indexable, [
				'object_id'   => $post_id,
				'object_type' => 'post',
			] );
			if ( $rebuilt ) {
				$rebuilt->save();
			}
		} catch ( \Throwable $e ) {
			// Yoast internals threw — non-fatal. AI caller's write already
			// succeeded; the indexable will rebuild via Yoast's async queue
			// on the next request instead of this one. Nothing to report.
			return;
		}
	}
}
