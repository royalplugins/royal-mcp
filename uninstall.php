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

// Delete the logs table
global $wpdb;
// Table name constructed safely from prefix + hardcoded string, then escaped
$royal_mcp_table_name = esc_sql($wpdb->prefix . 'royal_mcp_logs');
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Cleanup on uninstall, table name escaped via esc_sql()
$wpdb->query("DROP TABLE IF EXISTS `{$royal_mcp_table_name}`");

// Clear any transients
delete_transient('royal_mcp_cache');

// Clean up any user meta if applicable
delete_metadata('user', 0, 'royal_mcp_dismissed_notices', '', true);
