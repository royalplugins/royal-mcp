<?php
/**
 * Per-tool capability map for the WordPress Abilities API permission_callback.
 *
 * Ability-level cap is the FIRST gate (returns fast when caller lacks it).
 * Per-tool internal caps inside Server::execute_tool remain the SECOND, fine-grained
 * gate (e.g. read_post on a specific post ID). This map's job is to reject callers
 * who could never legitimately use the tool at all — not to duplicate object-level checks.
 *
 * Order of resolution:
 *   1. Explicit override in {@see $overrides}
 *   2. Prefix/verb heuristic in {@see infer_cap()}
 *   3. Conservative fallback: manage_options
 */

namespace Royal_MCP\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tool_Capabilities {

	/**
	 * Explicit per-tool overrides for atypical caps. Tools not listed fall through
	 * to {@see infer_cap()}. Keep this list tight — only tools whose cap can't be
	 * derived from the prefix + verb rules belong here.
	 */
	private static function overrides(): array {
		return array(
			// ==================== Core: options / admin ====================
			'wp_get_option'                 => 'manage_options',
			'wp_update_option'              => 'manage_options',
			'wp_get_plugin_settings'        => 'manage_options',
			'wp_get_permalink_structure'    => 'manage_options',
			'wp_update_permalink_structure' => 'manage_options',
			'wp_get_site_status'            => 'manage_options',
			'wp_get_error_log_tail'         => 'manage_options',
			'wp_get_cron_schedule'          => 'manage_options',
			'wp_get_plugins'                => 'activate_plugins',

			// ==================== Core: users ====================
			'wp_get_users' => 'list_users',
			'wp_get_user'  => 'list_users',

			// ==================== Core: themes + appearance ====================
			'wp_get_themes'       => 'switch_themes',
			'wp_get_active_theme' => 'switch_themes',
			'wp_get_theme_mods'   => 'edit_theme_options',
			'wp_update_theme_mod' => 'edit_theme_options',
			'wp_get_custom_css'   => 'edit_theme_options',
			'wp_update_custom_css'=> 'edit_theme_options',

			// ==================== Core: menus ====================
			'wp_get_menus'          => 'edit_theme_options',
			'wp_get_menu_items'     => 'edit_theme_options',
			'wp_create_menu_item'   => 'edit_theme_options',
			'wp_update_menu_item'   => 'edit_theme_options',
			'wp_delete_menu_item'   => 'edit_theme_options',
			'wp_reorder_menu_items' => 'edit_theme_options',

			// ==================== Core: comments moderation ====================
			'wp_get_pending_comments' => 'moderate_comments',
			'wp_approve_comment'      => 'moderate_comments',
			'wp_spam_comment'         => 'moderate_comments',
			'wp_trash_comment'        => 'moderate_comments',
			'wp_delete_comment'       => 'moderate_comments',

			// ==================== Core: terms (write) ====================
			'wp_create_term'      => 'manage_categories',
			'wp_update_term'      => 'manage_categories',
			'wp_delete_term'      => 'manage_categories',
			'wp_get_term_meta'    => 'manage_categories',
			'wp_update_term_meta' => 'manage_categories',
			'wp_delete_term_meta' => 'manage_categories',
			'wp_get_terms'        => 'edit_posts', // Server.php:2042 uses edit_posts

			// ==================== Core: media ====================
			'wp_get_media'             => 'upload_files',
			'wp_get_media_item'        => 'upload_files',
			'wp_count_media'           => 'upload_files',
			'wp_upload_media_from_url' => 'upload_files',
			'wp_upload_media'          => 'upload_files',

			// ==================== Core: pages (edit_pages, not edit_posts) ====================
			'wp_create_page' => 'edit_pages',
			'wp_update_page' => 'edit_pages',
			'wp_delete_page' => 'edit_pages',

			// ==================== Core: content find/replace ====================
			// 'replace' is not a verb infer_cap() knows, so both tools would
			// otherwise fall through to the manage_options fallback.
			'wp_replace_in_post' => 'edit_posts',
			'wp_replace_in_page' => 'edit_pages',

			// ==================== Core: revisions ====================
			// wp_get_revision_content needs no entry (wp_get_* infers 'read');
			// wp_diff_revisions has no verb infer_cap() knows and would fall
			// through to manage_options. Object-level read_post is enforced
			// inside the handler, matching wp_get_post_revisions.
			'wp_diff_revisions' => 'read',

			// ==================== Core: connection health — any authenticated caller ====================
			'royal_mcp_connection_health' => 'read',

			// ==================== ForgeCache: admin ops ====================
			'fc_clear_cache'     => 'manage_options',
			'fc_get_cache_stats' => 'manage_options',
		);
	}

	/**
	 * Resolve the capability required to call a tool via the Abilities API.
	 */
	public static function for_tool( string $tool_name ): string {
		$overrides = self::overrides();
		if ( isset( $overrides[ $tool_name ] ) ) {
			return $overrides[ $tool_name ];
		}
		return self::infer_cap( $tool_name );
	}

	/**
	 * Prefix + verb heuristic for tools not in the override map.
	 * Order matters: match longest / most-specific prefix first.
	 */
	private static function infer_cap( string $tool_name ): string {
		// Read-heavy verbs on core namespace default to WP's read cap.
		if ( preg_match( '/^(wp|royal_mcp)_(get|count|list|search)/', $tool_name ) ) {
			return 'read';
		}
		// Core write verbs default to edit_posts (loosest content-write cap).
		if ( preg_match( '/^wp_(create|update|delete|add|set|restore)_/', $tool_name ) ) {
			return 'edit_posts';
		}
		// Integration-namespace defaults inherit each integration's baseline.
		if ( strpos( $tool_name, 'wc_' ) === 0 )        return 'manage_woocommerce';
		if ( strpos( $tool_name, 'elementor_' ) === 0 ) return 'edit_posts';
		if ( strpos( $tool_name, 'fc_' ) === 0 )        return 'edit_posts';
		if ( strpos( $tool_name, 'sv_' ) === 0 )        return 'manage_options';
		if ( strpos( $tool_name, 'gp_' ) === 0 )        return 'manage_options';
		if ( strpos( $tool_name, 'rl_' ) === 0 )        return 'manage_options';
		if ( strpos( $tool_name, 'raif_' ) === 0 )      return 'manage_options';
		if ( strpos( $tool_name, 'rlinks_' ) === 0 )    return 'edit_posts';
		if ( strpos( $tool_name, 'acf_' ) === 0 )       return 'edit_posts';
		if ( strpos( $tool_name, 'redirection_' ) === 0 ) return 'manage_options';

		// Unknown prefix — conservative default.
		return 'manage_options';
	}
}
