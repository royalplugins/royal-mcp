<?php
/**
 * Royal MCP Uninstall
 *
 * Fired when the plugin is deleted.
 * Cleans up all plugin data from the database.
 *
 * @package Royal_MCP
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Pro ships its own uninstall handler and shares these tables/options.
if ( file_exists( WP_PLUGIN_DIR . '/royal-mcp-pro/royal-mcp-pro.php' ) ) {
    return;
}

// Delete plugin options
delete_option('royal_mcp_settings');
delete_option('royal_mcp_abilities_registration_enabled');

// MUST clear db_version so a reinstall re-runs maybe_upgrade_db().
delete_option('royal_mcp_db_version');

// Rewrite-version marker + last-failed-upgrade timestamp — misc singleton
// state options written by royal-mcp.php that would otherwise linger.
delete_option('royal_mcp_rewrite_version');
delete_option('royal_mcp_db_upgrade_last_failed_at');

// Delete the logs table
global $wpdb;
// Table name constructed safely from prefix + hardcoded string, then escaped
$royal_mcp_table_name = esc_sql($wpdb->prefix . 'royal_mcp_logs');
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Cleanup on uninstall, table name escaped via esc_sql()
$wpdb->query("DROP TABLE IF EXISTS `{$royal_mcp_table_name}`");

// Drop OAuth tables.
$royal_mcp_tokens_table = esc_sql($wpdb->prefix . 'royal_mcp_oauth_tokens');
$royal_mcp_clients_table = esc_sql($wpdb->prefix . 'royal_mcp_oauth_clients');
$royal_mcp_auth_codes_table = esc_sql($wpdb->prefix . 'royal_mcp_oauth_auth_codes');
$royal_mcp_sessions_table = esc_sql($wpdb->prefix . 'royal_mcp_sessions');
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query("DROP TABLE IF EXISTS `{$royal_mcp_tokens_table}`");
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query("DROP TABLE IF EXISTS `{$royal_mcp_clients_table}`");
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query("DROP TABLE IF EXISTS `{$royal_mcp_auth_codes_table}`");
// sessions table (DB-backed MCP session storage). phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query("DROP TABLE IF EXISTS `{$royal_mcp_sessions_table}`");

// Clear any transients
delete_transient('royal_mcp_cache');

// Clean up OAuth auth code transients (pattern: royal_mcp_authcode_*).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_authcode_%' OR option_name LIKE '_transient_timeout_royal_mcp_authcode_%'");

// Clean up any leftover transient-based MCP sessions from older installs that upgraded mid-flow.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_session_%' OR option_name LIKE '_transient_timeout_royal_mcp_session_%'");

// Clean up cached CIMD metadata documents keyed by sha256 of the client_id URL.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_cimd_meta_%' OR option_name LIKE '_transient_timeout_royal_mcp_cimd_meta_%'");

// Clean up undo-snapshot options (populated by Undo_Store for reversible tools like wp_reorder_menu_items).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'royal_mcp_undo_%'");

// Weekly protocol-version counter rows — one option row per ISO week, so a
// blanket LIKE sweep catches every historical bucket without listing them.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'royal_mcp_protocol_counter_%'");

// Per-IP rate-limit transients (both the MCP endpoint and /register).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_rate_%' OR option_name LIKE '_transient_timeout_royal_mcp_rate_%'");
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_reg_rate_%' OR option_name LIKE '_transient_timeout_royal_mcp_reg_rate_%'");

// wp_verify_rendered_page per-(site, URL) fetch bucket.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_vrp_%' OR option_name LIKE '_transient_timeout_royal_mcp_vrp_%'");

// Preview_Link short-lived preview tokens (rmcp_preview_ prefix).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rmcp_preview_%' OR option_name LIKE '_transient_timeout_rmcp_preview_%'");

// Auth-header probe cache (persists whether the origin passes Authorization
// through when the request hit /mcp behind mod_rewrite).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_auth_header_probe_%' OR option_name LIKE '_transient_timeout_royal_mcp_auth_header_probe_%'");

// One-shot api_key reveal transients (per-user, 15 min TTL — safe to nuke).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_reveal_api_key_%' OR option_name LIKE '_transient_timeout_royal_mcp_reveal_api_key_%'");

// Discovery-document transients — Server_Card + Agent_Skills_Index.
delete_transient('royal_mcp_server_card_json');
delete_transient('royal_mcp_agent_skills_index_json');

// Miscellaneous singleton admin-notice + endpoint-probe status transients
// set by Well_Known_Notice, Authorization_Header_Notice, and related
// diagnostics — literal keys, listed rather than swept so we don't
// accidentally match unrelated future keys.
delete_transient('royal_mcp_webmcp_bridge_status');
delete_transient('royal_mcp_auth_header_status');
delete_transient('royal_mcp_well_known_status');
delete_transient('royal_mcp_missing_endpoints_list');
delete_transient('royal_mcp_register_301_status');

// Clear scheduled events.
wp_clear_scheduled_hook('royal_mcp_token_cleanup');

// Clean up any user meta if applicable
delete_metadata('user', 0, 'royal_mcp_dismissed_notices', '', true);
delete_metadata('user', 0, 'royal_mcp_founders_dismissed', '', true);
// version-stamped dismissal meta for founders + review banners.
delete_metadata('user', 0, 'royal_mcp_founders_dismissed_version', '', true);
delete_metadata('user', 0, 'royal_mcp_review_dismissed_version', '', true);
// Legacy chrome-callout dismissal meta.
delete_metadata('user', 0, 'royal_plugins_dismissed_founders_callout', '', true);
