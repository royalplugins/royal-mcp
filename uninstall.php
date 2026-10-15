<?php
/**
 * Royal MCP Uninstall
 *
 * Fired when the plugin is deleted. On every site of the install (each site of
 * a multisite network): scheduled events, transients, caches, undo snapshots
 * and expired sessions and tokens are always removed. Settings (including the
 * API key), the activity log, connected clients and upload fingerprints are
 * removed only on sites whose owner opted in: the site's "Delete all data when
 * the plugin is deleted" setting, or the ROYAL_MCP_ALLOW_UNINSTALL constant.
 * Deleting the plugin to reinstall it must never wipe that data by default.
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

if ( ! function_exists( 'royal_mcp_uninstall_site' ) ) :
/**
 * Remove Royal MCP data from the current site.
 */
function royal_mcp_uninstall_site() {
    global $wpdb;

    // Read the opt-in before anything is deleted.
    $settings    = get_option( 'royal_mcp_settings', array() );
    $delete_data = ( defined( 'ROYAL_MCP_ALLOW_UNINSTALL' ) && ROYAL_MCP_ALLOW_UNINSTALL )
        || ( is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] ) );

    // -----------------------------------------------------------------------
    // Always: scheduled events, transients, caches.
    // -----------------------------------------------------------------------

    // Rewrite-version marker + last-failed-upgrade timestamp. Removing the marker
    // makes a reinstall flush its rewrite rules again.
    delete_option('royal_mcp_rewrite_version');
    delete_option('royal_mcp_db_upgrade_last_failed_at');

    delete_transient('royal_mcp_cache');

    // OAuth auth code transients (pattern: royal_mcp_authcode_*).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_authcode_%' OR option_name LIKE '_transient_timeout_royal_mcp_authcode_%'");

    // Transient-based MCP sessions left by older versions.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_session_%' OR option_name LIKE '_transient_timeout_royal_mcp_session_%'");

    // Cached CIMD metadata documents keyed by sha256 of the client_id URL.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_cimd_meta_%' OR option_name LIKE '_transient_timeout_royal_mcp_cimd_meta_%'");

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

    // Auth-header probe cache (whether the origin passes Authorization through).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_auth_header_probe_%' OR option_name LIKE '_transient_timeout_royal_mcp_auth_header_probe_%'");

    // One-shot api_key reveal transients (per-user, 15 min TTL).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_reveal_api_key_%' OR option_name LIKE '_transient_timeout_royal_mcp_reveal_api_key_%'");

    // Discovery-document transients — Server_Card + Agent_Skills_Index.
    delete_transient('royal_mcp_server_card_json');
    delete_transient('royal_mcp_agent_skills_index_json');

    // Singleton admin-notice and endpoint-probe status transients, listed rather
    // than swept so unrelated future keys are never matched.
    delete_transient('royal_mcp_webmcp_bridge_status');
    delete_transient('royal_mcp_auth_header_status');
    delete_transient('royal_mcp_well_known_status');
    delete_transient('royal_mcp_foreign_as_endpoint');
    delete_transient('royal_mcp_missing_endpoints_list');
    delete_transient('royal_mcp_register_301_status');

    // Scheduled events.
    wp_clear_scheduled_hook('royal_mcp_token_cleanup');

    // Short-lived data the removed cleanup event would have expired: undo
    // snapshots (72 hours, copies of changed content), and the same session,
    // token and authorization-code rows the daily cleanup removes.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'royal_mcp_undo_%'");
    $now = gmdate( 'Y-m-d H:i:s' );
    foreach ( array(
        'royal_mcp_sessions'         => 'expires_at < %s',
        'royal_mcp_oauth_tokens'     => 'revoked = 1 OR expires_at < %s',
        'royal_mcp_oauth_auth_codes' => 'used = 1 OR expires_at < %s',
    ) as $short_lived => $where ) {
        $short_lived = $wpdb->prefix . $short_lived;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $short_lived === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $short_lived ) ) ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the prefix and a fixed list
            $wpdb->query( $wpdb->prepare( 'DELETE FROM `' . esc_sql( $short_lived ) . '` WHERE ' . $where, $now ) );
        }
    }

    // -----------------------------------------------------------------------
    // Only when this site's owner opted in: settings (including the API key),
    // the activity log, connected clients, sessions, usage counters.
    // -----------------------------------------------------------------------
    if ( $delete_data ) {
        delete_option('royal_mcp_settings');
        delete_option('royal_mcp_abilities_registration_enabled');
        delete_option('royal_mcp_client_access');

        // Cleared with the tables so a reinstall recreates them.
        delete_option('royal_mcp_db_version');

        foreach ( array( 'royal_mcp_logs', 'royal_mcp_oauth_tokens', 'royal_mcp_oauth_clients', 'royal_mcp_oauth_auth_codes', 'royal_mcp_sessions' ) as $royal_mcp_table ) {
            $royal_mcp_table = esc_sql( $wpdb->prefix . $royal_mcp_table );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the prefix and a fixed list
            $wpdb->query( "DROP TABLE IF EXISTS `{$royal_mcp_table}`" );
        }

        // Upload fingerprints on media attachments (source URL, hashes, options).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_royal_mcp_source_url', '_royal_mcp_source_sha256', '_royal_mcp_upload_opts', '_royal_mcp_file_sha256', '_royal_mcp_file_sha256_sig')");

        // Weekly protocol-version counter rows, one option row per ISO week.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'royal_mcp_protocol_counter_%'");
    }

    // The bulk queries above delete rows without going through delete_option, so
    // drop WordPress's in-request options caches.
    wp_cache_delete( 'alloptions', 'options' );
    wp_cache_delete( 'notoptions', 'options' );
}
endif;

if ( is_multisite() ) {
    foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $royal_mcp_site_id ) {
        switch_to_blog( (int) $royal_mcp_site_id );
        royal_mcp_uninstall_site();
        restore_current_blog();
    }
} else {
    royal_mcp_uninstall_site();
}

// Dismissed-notice user meta (one user table for the whole network).
delete_metadata('user', 0, 'royal_mcp_dismissed_notices', '', true);
delete_metadata('user', 0, 'royal_mcp_founders_dismissed', '', true);
delete_metadata('user', 0, 'royal_mcp_foreign_as_dismissed', '', true);
delete_metadata('user', 0, 'royal_mcp_founders_dismissed_version', '', true);
delete_metadata('user', 0, 'royal_mcp_review_dismissed_version', '', true);
delete_metadata('user', 0, 'royal_mcp_pro_launch_notice_dismissed', '', true);
delete_metadata('user', 0, 'royal_plugins_dismissed_founders_callout', '', true);
