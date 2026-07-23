<?php
namespace Royal_MCP\MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MCP Session Store.
 *
 * DB-backed storage for MCP session state.
 *
 * When an object cache drop-in (object-cache.php) is active, set_transient()
 * writes to the cache layer instead of wp_options. Some cache backends evict
 * keys between requests, so a session that writes successfully reads back as
 * `false` milliseconds later — every MCP request after `initialize` returns
 * 404 "Session not found". Direct DB storage with sha256-hashed lookup gives
 * reliable persistence regardless of which cache backend (if any) is active.
 */
class Session_Store {

    /** Default session lifetime in seconds (24h sliding window). */
    const SESSION_TTL = DAY_IN_SECONDS;

    /**
     * Get the sessions table name.
     */
    public static function sessions_table() {
        global $wpdb;
        return $wpdb->prefix . 'royal_mcp_sessions';
    }

    /**
     * Create the sessions table. Called from activation AND from the runtime
     * migration check in royal-mcp.php. Idempotent.
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table           = self::sessions_table();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_hash varchar(64) NOT NULL,
            auth_fingerprint varchar(64) NOT NULL DEFAULT '',
            last_event_id bigint(20) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            last_seen_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY session_hash (session_hash),
            KEY expires_at (expires_at)
        ) $charset_collate;" );
    }

    /**
     * Drop the sessions table. Called from uninstall.
     */
    public static function drop_tables() {
        global $wpdb;
        $table = esc_sql( self::sessions_table() );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
    }

    /**
     * Persist a new session.
     *
     * Hashes the raw session_id before storage. The caller keeps the plaintext
     * to return in the Mcp-Session-Id response header; we only need the hash
     * for lookups. Same defense-in-depth pattern Token_Store uses for auth
     * codes and access tokens — if the table is ever leaked, attackers can't
     * replay the session IDs.
     *
     * @param string $session_id       Raw session ID (caller-generated).
     * @param string $auth_fingerprint sha256 of the credentials that opened the session.
     * @return int|false Rows affected (1) on insert, false on failure.
     */
    public static function create_session( $session_id, $auth_fingerprint = '' ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct insert.
        return $wpdb->insert(
            self::sessions_table(),
            [
                'session_hash'     => hash( 'sha256', $session_id ),
                'auth_fingerprint' => (string) $auth_fingerprint,
                'last_event_id'    => 0,
                'expires_at'       => gmdate( 'Y-m-d H:i:s', time() + self::SESSION_TTL ),
            ],
            [ '%s', '%s', '%d', '%s' ]
        );
    }

    /**
     * Check that a session is valid and refresh its TTL.
     *
     * Two-step SELECT-then-UPDATE. We can't collapse this to a single UPDATE
     * because datetime columns are second-resolution: if two MCP requests
     * for the same session arrive in the same wall-clock second, both
     * UPDATEs would set last_seen_at and expires_at to identical values, and
     * MySQL would report 0 affected rows even though the row exists — which
     * is indistinguishable from "session not found." The SELECT confirms
     * existence first, then the UPDATE slides the TTL forward.
     *
     * @param string $session_id Raw session ID.
     * @return bool True if the session was valid (and was refreshed).
     */
    public static function touch_session( $session_id ) {
        global $wpdb;
        $table = self::sessions_table();
        $hash  = hash( 'sha256', $session_id );
        $now   = gmdate( 'Y-m-d H:i:s' );
        $new_expiry = gmdate( 'Y-m-d H:i:s', time() + self::SESSION_TTL );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper.
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM `{$table}` WHERE session_hash = %s AND expires_at > %s LIMIT 1",
                $hash,
                $now
            )
        );
        if ( ! $exists ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET last_seen_at = %s, expires_at = %s WHERE session_hash = %s",
                $now,
                $new_expiry,
                $hash
            )
        );

        return true;
    }

    /**
     * Read the auth_fingerprint stored alongside a session.
     *
     * Used by the credential-binding check in Server.php — every MCP request
     * after initialize must come from the same credentials that opened the
     * session, so a leaked Mcp-Session-Id alone can't be replayed across
     * auth contexts.
     *
     * @param string $session_id Raw session ID.
     * @return string|null The fingerprint, or null if the session doesn't exist or is expired.
     */
    public static function get_fingerprint( $session_id ) {
        global $wpdb;
        $table = self::sessions_table();
        $hash  = hash( 'sha256', $session_id );
        $now   = gmdate( 'Y-m-d H:i:s' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT auth_fingerprint FROM `{$table}` WHERE session_hash = %s AND expires_at > %s LIMIT 1",
                $hash,
                $now
            ),
            ARRAY_A
        );

        return $row ? (string) $row['auth_fingerprint'] : null;
    }

    /**
     * Delete a single session (client-initiated termination via DELETE).
     *
     * @param string $session_id Raw session ID.
     * @return int|false Rows affected.
     */
    public static function delete_session( $session_id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct delete.
        return $wpdb->delete(
            self::sessions_table(),
            [ 'session_hash' => hash( 'sha256', $session_id ) ],
            [ '%s' ]
        );
    }

    /**
     * Delete all expired sessions. Hooked to the existing royal_mcp_token_cleanup
     * daily cron action (Token_Store::cleanup_expired runs on the same hook).
     */
    public static function cleanup_expired() {
        global $wpdb;
        $table = esc_sql( self::sessions_table() );
        $now   = gmdate( 'Y-m-d H:i:s' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE expires_at < %s",
                $now
            )
        );
    }
}
