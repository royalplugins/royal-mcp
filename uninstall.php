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

// Delete plugin options
delete_option('royal_mcp_settings');

// Clear the db_version option so a future reinstall can't be silently
// short-circuited by maybe_upgrade_db() seeing a matching version with the
// tables already dropped. Fresh installs deserve a clean slate.
delete_option('royal_mcp_db_version');

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

// Clear scheduled events.
wp_clear_scheduled_hook('royal_mcp_token_cleanup');

// Clean up any user meta if applicable
delete_metadata('user', 0, 'royal_mcp_dismissed_notices', '', true);
delete_metadata('user', 0, 'royal_mcp_founders_dismissed', '', true);
// version-stamped dismissal meta for founders + review banners.
delete_metadata('user', 0, 'royal_mcp_founders_dismissed_version', '', true);
delete_metadata('user', 0, 'royal_mcp_review_dismissed_version', '', true);
