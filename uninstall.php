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

// WordPress's own widget option is only updated (a menu id), never owned by this plugin.
// uninstall-audit: keep widget_nav_menu

// Royal MCP Pro shares these tables and options. While Pro is active (on a site,
// or for the whole network) its data stays; an installed but inactive Pro does not
// keep this cleanup from running.
if ( ! function_exists( 'royal_mcp_uninstall_pro_active' ) ) :
function royal_mcp_uninstall_pro_active() {
    $pro = 'royal-mcp-pro/royal-mcp-pro.php';
    if ( is_multisite() && in_array( $pro, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ), true ) ) {
        return true;
    }
    return in_array( $pro, (array) get_option( 'active_plugins', array() ), true );
}
endif;

if ( ! function_exists( 'royal_mcp_uninstall_site' ) ) :
/**
 * Remove Royal MCP data from the current site.
 */
function royal_mcp_uninstall_site() {
    global $wpdb;

    if ( royal_mcp_uninstall_pro_active() ) {
        return false;
    }

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
    delete_option('royal_mcp_connection_doctor');
    delete_option('royal_mcp_agent_readiness');
    delete_option('royal_mcp_db_upgrade_last_failed_at');
    delete_option('royal_mcp_tables_checked_at');
    delete_option('royal_mcp_wpmailsmtp_test_sends');
    delete_option('royal_mcp_wpmailsmtp_test_lock');
    delete_option('royal_mcp_fluentsmtp_test_sends');
    delete_option('royal_mcp_fluentsmtp_test_lock');
    delete_option('royal_mcp_mc4wp_refresh_at');
    delete_option('royal_mcp_mc4wp_refresh_lock');
    delete_option('royal_mcp_akismet_recheck_lock');
    delete_option('royal_mcp_rule_restore');
    delete_option('royal_mcp_cimd_fetch_failure');
    delete_option('royal_mcp_cimd_known_good');

    delete_transient('royal_mcp_cache');
    delete_transient('royal_mcp_authorize_rule_restore');
    delete_transient('royal_mcp_pkce_missing_noted');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_royal\\_mcp\\_wc\\_confirm\\_%' OR option_name LIKE '\\_transient\\_timeout\\_royal\\_mcp\\_wc\\_confirm\\_%'");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_royal\_mcp\_pkce\_missing\_noted\_%' OR option_name LIKE '\_transient\_timeout\_royal\_mcp\_pkce\_missing\_noted\_%'");

    // OAuth auth code transients (pattern: royal_mcp_authcode_*).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_authcode_%' OR option_name LIKE '_transient_timeout_royal_mcp_authcode_%'");

    // Transient-based MCP sessions left by older versions.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_session_%' OR option_name LIKE '_transient_timeout_royal_mcp_session_%'");

    // Cached CIMD metadata documents keyed by sha256 of the client_id URL.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_cimd_meta_%' OR option_name LIKE '_transient_timeout_royal_mcp_cimd_meta_%'");
    delete_transient( 'royal_mcp_jp_backups' );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_site_ips_%' OR option_name LIKE '_transient_timeout_royal_mcp_site_ips_%'");

    // Per-IP rate-limit transients (both the MCP endpoint and /register).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_rate_%' OR option_name LIKE '_transient_timeout_royal_mcp_rate_%'");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_reg_rate_%' OR option_name LIKE '_transient_timeout_royal_mcp_reg_rate_%'");
    // Request counter rows.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'royal\_mcp\_ratectr\_%'");
    // OAuth log collapse markers and metadata-document fetch counters.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_royal\_mcp\_oauth\_logged\_%' OR option_name LIKE '\_transient\_timeout\_royal\_mcp\_oauth\_logged\_%' OR option_name LIKE '\_transient\_royal\_mcp\_cimd\_rate\_%' OR option_name LIKE '\_transient\_timeout\_royal\_mcp\_cimd\_rate\_%'");

    // wp_verify_rendered_page per-(site, URL) fetch bucket.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_vrp_%' OR option_name LIKE '_transient_timeout_royal_mcp_vrp_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_page_%' OR option_name LIKE '_transient_timeout_royal_mcp_page_%'");
    // Stand-in undo lock rows (databases without named locks).
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'royal\\_mcp\\_undolock\\_%'");

    // Preview_Link short-lived preview tokens (rmcp_preview_ prefix).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rmcp_preview_%' OR option_name LIKE '_transient_timeout_rmcp_preview_%'");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_rmcp\_prvh\_%' OR option_name LIKE '\_transient\_timeout\_rmcp\_prvh\_%'");
    delete_post_meta_by_key( '_royal_mcp_preview_gen' );

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
    delete_transient('royal_mcp_legacy_key_header_seen');
    delete_transient('royal_mcp_well_known_status');
    delete_transient('royal_mcp_well_known_ns_status');
    delete_transient('royal_mcp_discovery_chain');
    delete_transient('royal_mcp_foreign_as_endpoint');
    delete_transient('royal_mcp_missing_endpoints_list');
    delete_transient('royal_mcp_register_301_status');
    delete_transient('royal_mcp_pending_notify_lock');

    // Per-address client-metadata fetch counters.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_royal\\_mcp\\_cimd\\_rate\\_%' OR option_name LIKE '\\_transient\\_timeout\\_royal\\_mcp\\_cimd\\_rate\\_%'");

    // Scheduled events.
    wp_clear_scheduled_hook('royal_mcp_token_cleanup');
    wp_clear_scheduled_hook('royal_mcp_auth_header_probe');

    // Short-lived data the removed cleanup event would have expired: undo
    // snapshots (72 hours, copies of changed content), and the same session,
    // token and authorization-code rows the daily cleanup removes.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'royal\_mcp\_undo\_%'");
    delete_transient( 'royal_mcp_undo_totals' );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall, no user input
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'royal\_mcp\_undopart\_%'");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'royal\_mcp\_rt\_retry\_%'");
    delete_option( 'royal_mcp_rejected_cimd_clients' );
    delete_option( 'royal_mcp_maintenance' );
    // The IndexNow key file written in the site root, when it still holds the key it was written for.
    $royal_mcp_key_file = (string) get_option( 'royal_mcp_indexnow_key_file', '' );
    if ( ! is_multisite() && 32 === strlen( $royal_mcp_key_file ) && ctype_xdigit( $royal_mcp_key_file ) ) {
        $royal_mcp_key_path = trailingslashit( ABSPATH ) . $royal_mcp_key_file . '.txt';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- small local file
        if ( file_exists( $royal_mcp_key_path ) && trim( (string) file_get_contents( $royal_mcp_key_path ) ) === $royal_mcp_key_file ) {
            wp_delete_file( $royal_mcp_key_path );
        }
    }
    delete_option( 'royal_mcp_indexnow_key_file' );
    delete_option( 'royal_mcp_indexnow_key' );
    delete_option( 'royal_mcp_bridged_namespaces' );
    delete_option( 'royal_mcp_bridged_abilities' );
    delete_option( 'royal_mcp_bridged_writes' );
    delete_option( 'royal_mcp_bridged_known' );
    delete_option( 'royal_mcp_skills' );
    delete_transient( 'royal_mcp_acf_field_types_v1' );
    delete_option( 'royal_mcp_mailpoet_send_guard' );
    delete_option( 'royal_mcp_mailpoet_test_sends' );
    delete_option( 'royal_mcp_mailpoet_confirmations' );
    delete_option( 'royal_mcp_mailpoet_creations' );
    delete_option( 'royal_mcp_updraft_last_trigger' );
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

    // Cleared Activity Log entries kept for restoring are temporary copies only this
    // plugin can restore, so they go whether or not the owner opted in to data removal.
    $royal_mcp_cleared_table = esc_sql( $wpdb->prefix . 'royal_mcp_logs_cleared' );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the prefix and a fixed name
    $wpdb->query( "DROP TABLE IF EXISTS `{$royal_mcp_cleared_table}`" );
    delete_option( 'royal_mcp_logs_cleared_schema' );

    // -----------------------------------------------------------------------
    // Only when this site's owner opted in: settings (including the API key),
    // the activity log, connected clients, sessions, usage counters.
    // -----------------------------------------------------------------------
    if ( $delete_data ) {
        // Royal MCP Pro, when installed, uses the same settings and tables: they stay for it.
        $royal_mcp_pro_installed = file_exists( WP_PLUGIN_DIR . '/royal-mcp-pro/royal-mcp-pro.php' );
        if ( ! $royal_mcp_pro_installed ) {
            delete_option('royal_mcp_settings');
        }
        delete_option('royal_mcp_unmasked_options');
        // Kept with the Activity Log: a reinstall must not fall back to a shorter period and prune entries the owner kept.
        delete_option('royal_mcp_log_retention_days');
        delete_option( 'royal_mcp_site_context' );
        delete_option('royal_mcp_abilities_registration_enabled');
        delete_option('royal_mcp_client_access');
        delete_option('royal_mcp_app_password_seen');
        delete_option('royal_mcp_app_password_blocked');
        delete_option('royal_mcp_php_errors');
        delete_option('royal_mcp_php_errors_since');
        delete_option('royal_mcp_record_php_errors');
        delete_option('royal_mcp_oauth_namespace_clients');
        delete_transient('royal_mcp_issuer_kind_column_failed');
        delete_transient('royal_mcp_rotation_column_failed');
        delete_transient('royal_mcp_resource_column_failed');
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_royal\\_mcp\\_doctor\\_probe%' OR option_name LIKE '\\_transient\\_timeout\\_royal\\_mcp\\_doctor\\_probe%'");

        // The tables, and with them the schema version, so a reinstall recreates them.
        foreach ( $royal_mcp_pro_installed ? array() : array( 'royal_mcp_logs', 'royal_mcp_oauth_tokens', 'royal_mcp_oauth_clients', 'royal_mcp_oauth_auth_codes', 'royal_mcp_sessions' ) as $royal_mcp_table ) {
            $royal_mcp_table = esc_sql( $wpdb->prefix . $royal_mcp_table );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from the prefix and a fixed list
            $wpdb->query( "DROP TABLE IF EXISTS `{$royal_mcp_table}`" );
        }
        if ( ! $royal_mcp_pro_installed ) {
            delete_option('royal_mcp_db_version');
        }

        // Upload fingerprints on media attachments (source URL, hashes, options).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_royal_mcp_source_url', '_royal_mcp_source_sha256', '_royal_mcp_upload_opts', '_royal_mcp_file_sha256', '_royal_mcp_file_sha256_sig', '_royal_mcp_reused_at')");

        // Weekly protocol-version counter rows, one option row per ISO week.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'royal_mcp_protocol_counter_%'");

        // Copies of page content kept by Divi layout imports, one row per import.
        // The older key name is shared with Royal MCP Pro, so it stays while Pro is installed.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_royal\\_mcp\\_divi\\_backup\\_%'");
        if ( ! file_exists( WP_PLUGIN_DIR . '/royal-mcp-pro/royal-mcp-pro.php' ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_royal\\_mcp\\_pro\\_divi\\_backup\\_%'");
        }
    }

    // The bulk queries above delete rows without going through delete_option, so
    // drop WordPress's in-request options caches.
    wp_cache_delete( 'alloptions', 'options' );
    wp_cache_delete( 'notoptions', 'options' );

    // The stored rewrite rules still hold the plugin's OAuth and /mcp rules. Dropping them
    // makes WordPress rebuild the rules on the site's next request, without this plugin's.
    delete_option( 'rewrite_rules' );
    return true;
}
endif;

$royal_mcp_pro_kept = false;
if ( is_multisite() ) {
    foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $royal_mcp_site_id ) {
        switch_to_blog( (int) $royal_mcp_site_id );
        if ( false === royal_mcp_uninstall_site() ) {
            $royal_mcp_pro_kept = true;
        }
        restore_current_blog();
    }
} else {
    $royal_mcp_pro_kept = false === royal_mcp_uninstall_site();
}

// User meta is shared by every site of a network; it stays while Pro is active anywhere.
if ( $royal_mcp_pro_kept ) {
    return;
}

// Dismissed-notice and seen-version user meta (one user table for the whole network).
delete_metadata('user', 0, 'royal_mcp_dismissed_notices', '', true);
delete_metadata('user', 0, 'royal_mcp_founders_dismissed', '', true);
delete_metadata('user', 0, 'royal_mcp_foreign_as_dismissed', '', true);
delete_metadata('user', 0, 'royal_mcp_discovery_chain_dismissed', '', true);
delete_metadata('user', 0, 'royal_mcp_founders_dismissed_version', '', true);
delete_metadata('user', 0, 'royal_mcp_review_dismissed_version', '', true);
delete_metadata('user', 0, 'royal_mcp_pro_launch_notice_dismissed', '', true);
delete_metadata('user', 0, 'royal_plugins_dismissed_founders_callout', '', true);
foreach ( array(
    'royal_mcp_whats_new_seen_version',
    'royal_mcp_auth_header_dismissed',
    'royal_mcp_elementor_coexistence_dismissed',
    'royal_mcp_well_known_dismissed',
    'royal_mcp_well_known_stale_dismissed',
    'royal_mcp_well_known_html_body_dismissed',
    'royal_mcp_register_301_dismissed',
    'royal_mcp_imunify360_dismissed',
    'royal_mcp_bitninja_dismissed',
    'royal_mcp_sucuri_cloudproxy_dismissed',
    'royal_mcp_plain_permalinks_dismissed',
    'royal_mcp_oauth_page_shadow_dismissed',
    'royal_mcp_well_known_missing_endpoints_dismissed',
    'royal_mcp_perfmatters_rest_dismissed',
    'royal_mcp_recheck_just_ran',
) as $royal_mcp_user_meta_key ) {
    delete_metadata( 'user', 0, $royal_mcp_user_meta_key, '', true );
}
