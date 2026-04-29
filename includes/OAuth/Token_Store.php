<?php
namespace Royal_MCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * OAuth Token Store.
 *
 * Handles CRUD for access/refresh tokens, authorization codes,
 * and dynamically registered OAuth clients.
 */
class Token_Store {

    /** Token lifetimes in seconds. */
    const ACCESS_TOKEN_TTL  = 3600;       // 1 hour
    const REFRESH_TOKEN_TTL = 2592000;    // 30 days
    const AUTH_CODE_TTL     = 600;        // 10 minutes

    /* ------------------------------------------------------------------
     *  Table helpers
     * ----------------------------------------------------------------*/

    /**
     * Get the tokens table name.
     */
    public static function tokens_table() {
        global $wpdb;
        return $wpdb->prefix . 'royal_mcp_oauth_tokens';
    }

    /**
     * Get the clients table name.
     */
    public static function clients_table() {
        global $wpdb;
        return $wpdb->prefix . 'royal_mcp_oauth_clients';
    }

    /**
     * Create both OAuth tables. Called from plugin activation.
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $tokens_table  = self::tokens_table();
        $clients_table = self::clients_table();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // dbDelta needs each CREATE TABLE as a separate call.
        dbDelta( "CREATE TABLE IF NOT EXISTS $tokens_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            token_hash varchar(64) NOT NULL,
            token_type varchar(20) NOT NULL,
            client_id varchar(255) NOT NULL,
            user_id bigint(20) NOT NULL,
            scope varchar(255) DEFAULT '',
            expires_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            revoked tinyint(1) DEFAULT 0 NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY client_id (client_id),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) $charset_collate;" );

        dbDelta( "CREATE TABLE IF NOT EXISTS $clients_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            client_id varchar(255) NOT NULL,
            client_secret_hash varchar(64) DEFAULT NULL,
            client_name varchar(255) NOT NULL,
            redirect_uris text NOT NULL,
            grant_types varchar(255) DEFAULT 'authorization_code' NOT NULL,
            token_endpoint_auth_method varchar(50) DEFAULT 'none' NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY client_id (client_id)
        ) $charset_collate;" );
    }

    /**
     * Drop OAuth tables. Called from uninstall.
     */
    public static function drop_tables() {
        global $wpdb;
        $tokens_table  = esc_sql( self::tokens_table() );
        $clients_table = esc_sql( self::clients_table() );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS `{$tokens_table}`" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS `{$clients_table}`" );
    }

    /* ------------------------------------------------------------------
     *  Authorization codes  (stored as transients — short-lived)
     * ----------------------------------------------------------------*/

    /**
     * Store an authorization code.
     *
     * @param string $code       The raw authorization code.
     * @param array  $data       Payload: user_id, client_id, redirect_uri, code_challenge, code_challenge_method, scope.
     */
    public static function store_auth_code( $code, array $data ) {
        $data['created_at'] = time();
        set_transient( 'royal_mcp_authcode_' . hash( 'sha256', $code ), $data, self::AUTH_CODE_TTL );
    }

    /**
     * Consume an authorization code (single-use).
     *
     * @param string $code The raw code presented by the client.
     * @return array|false The stored data, or false if invalid/expired.
     */
    public static function consume_auth_code( $code ) {
        $key  = 'royal_mcp_authcode_' . hash( 'sha256', $code );
        $data = get_transient( $key );
        // Immediately delete — codes are single-use.
        delete_transient( $key );
        return $data;
    }

    /* ------------------------------------------------------------------
     *  Access / Refresh tokens
     * ----------------------------------------------------------------*/

    /**
     * Generate and store a token pair (access + refresh).
     *
     * @param string $client_id WordPress OAuth client ID.
     * @param int    $user_id   WordPress user ID.
     * @param string $scope     Space-separated scopes.
     * @return array [ 'access_token' => …, 'refresh_token' => …, 'expires_in' => … ]
     */
    public static function create_token_pair( $client_id, $user_id, $scope = '' ) {
        $access_token  = bin2hex( random_bytes( 32 ) );
        $refresh_token = bin2hex( random_bytes( 32 ) );

        self::store_token( $access_token, 'access', $client_id, $user_id, $scope, self::ACCESS_TOKEN_TTL );
        self::store_token( $refresh_token, 'refresh', $client_id, $user_id, $scope, self::REFRESH_TOKEN_TTL );

        return [
            'access_token'  => $access_token,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TOKEN_TTL,
            'refresh_token' => $refresh_token,
            'scope'         => $scope,
        ];
    }

    /**
     * Store a single token (hashed) in the database.
     */
    private static function store_token( $raw_token, $type, $client_id, $user_id, $scope, $ttl ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert(
            self::tokens_table(),
            [
                'token_hash' => hash( 'sha256', $raw_token ),
                'token_type' => $type,
                'client_id'  => $client_id,
                'user_id'    => $user_id,
                'scope'      => $scope,
                'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s' ]
        );
    }

    /**
     * Validate a Bearer token.
     *
     * @param string $raw_token The raw access token from the Authorization header.
     * @return array|false Token row (with user_id, client_id, scope) or false.
     */
    public static function validate_token( $raw_token ) {
        global $wpdb;
        $table = self::tokens_table();
        $hash  = hash( 'sha256', $raw_token );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE token_hash = %s AND token_type = 'access' AND revoked = 0 AND expires_at > %s LIMIT 1",
                $hash,
                gmdate( 'Y-m-d H:i:s' )
            ),
            ARRAY_A
        );

        return $row ? $row : false;
    }

    /**
     * Validate and consume a refresh token (token rotation).
     *
     * @param string $raw_refresh_token The raw refresh token.
     * @return array|false Token row or false.
     */
    public static function consume_refresh_token( $raw_refresh_token ) {
        global $wpdb;
        $table = self::tokens_table();
        $hash  = hash( 'sha256', $raw_refresh_token );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE token_hash = %s AND token_type = 'refresh' AND revoked = 0 AND expires_at > %s LIMIT 1",
                $hash,
                gmdate( 'Y-m-d H:i:s' )
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return false;
        }

        // Revoke the old refresh token (rotation).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->update(
            $table,
            [ 'revoked' => 1 ],
            [ 'id' => $row['id'] ],
            [ '%d' ],
            [ '%d' ]
        );

        return $row;
    }

    /**
     * Revoke all tokens for a client+user combination.
     */
    public static function revoke_tokens_for_user( $client_id, $user_id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            self::tokens_table(),
            [ 'revoked' => 1 ],
            [ 'client_id' => $client_id, 'user_id' => $user_id ],
            [ '%d' ],
            [ '%s', '%d' ]
        );
    }

    /**
     * Delete expired and revoked tokens. Called by scheduled cleanup.
     */
    public static function cleanup_expired() {
        global $wpdb;
        $table = esc_sql( self::tokens_table() );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE revoked = 1 OR expires_at < %s",
                gmdate( 'Y-m-d H:i:s' )
            )
        );
    }

    /* ------------------------------------------------------------------
     *  Dynamic client registration
     * ----------------------------------------------------------------*/

    /**
     * Register a new OAuth client.
     *
     * @param array $data Client registration data.
     * @return array|\WP_Error Stored client record on success, WP_Error if the DB write failed.
     */
    public static function register_client( array $data ) {
        global $wpdb;

        $client_id = 'rmcp_' . bin2hex( random_bytes( 16 ) );

        $client_secret      = null;
        $client_secret_hash = null;
        $auth_method        = isset( $data['token_endpoint_auth_method'] ) ? sanitize_text_field( $data['token_endpoint_auth_method'] ) : 'none';

        if ( 'client_secret_post' === $auth_method ) {
            $client_secret      = bin2hex( random_bytes( 32 ) );
            $client_secret_hash = hash( 'sha256', $client_secret );
        }

        $redirect_uris = isset( $data['redirect_uris'] ) && is_array( $data['redirect_uris'] )
            ? array_map( 'sanitize_url', $data['redirect_uris'] )
            : [];

        $client_name = isset( $data['client_name'] ) ? sanitize_text_field( $data['client_name'] ) : 'MCP Client';
        $grant_types = isset( $data['grant_types'] ) && is_array( $data['grant_types'] )
            ? sanitize_text_field( implode( ' ', $data['grant_types'] ) )
            : 'authorization_code';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $inserted = $wpdb->insert(
            self::clients_table(),
            [
                'client_id'                  => $client_id,
                'client_secret_hash'         => $client_secret_hash,
                'client_name'                => $client_name,
                'redirect_uris'              => wp_json_encode( $redirect_uris ),
                'grant_types'                => $grant_types,
                'token_endpoint_auth_method' => $auth_method,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            return new \WP_Error(
                'royal_mcp_register_failed',
                'Failed to persist client registration. The OAuth tables may be missing — deactivate and reactivate Royal MCP to recreate them.',
                [ 'db_error' => $wpdb->last_error ]
            );
        }

        $result = [
            'client_id'                  => $client_id,
            'client_name'                => $client_name,
            'redirect_uris'              => $redirect_uris,
            'grant_types'                => explode( ' ', $grant_types ),
            'token_endpoint_auth_method' => $auth_method,
            'response_types'             => [ 'code' ],
            'client_id_issued_at'        => time(),
        ];

        if ( $client_secret ) {
            $result['client_secret'] = $client_secret;
        }

        return $result;
    }

    /**
     * Look up a registered client by client_id.
     *
     * Checks the database first (dynamic clients), then falls back
     * to the static client configured in plugin settings.
     *
     * @param string $client_id The client ID.
     * @return array|false Client row or false.
     */
    public static function get_client( $client_id ) {
        global $wpdb;
        $table = self::clients_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE client_id = %s LIMIT 1", $client_id ),
            ARRAY_A
        );

        if ( $row ) {
            $row['redirect_uris'] = json_decode( $row['redirect_uris'], true ) ?: [];
            return $row;
        }

        // Check static client from settings.
        $settings = get_option( 'royal_mcp_settings', [] );
        if ( ! empty( $settings['oauth_client_id'] ) && hash_equals( $settings['oauth_client_id'], $client_id ) ) {
            return [
                'client_id'                  => $settings['oauth_client_id'],
                'client_secret_hash'         => ! empty( $settings['oauth_client_secret'] ) ? hash( 'sha256', $settings['oauth_client_secret'] ) : null,
                'client_name'                => get_bloginfo( 'name' ) . ' (static)',
                'redirect_uris'              => [], // Static clients accept any localhost/HTTPS redirect.
                'grant_types'                => 'authorization_code',
                'token_endpoint_auth_method' => ! empty( $settings['oauth_client_secret'] ) ? 'client_secret_post' : 'none',
                'is_static'                  => true,
            ];
        }

        return false;
    }

    /**
     * Validate a redirect URI against a client's registered URIs.
     *
     * @param string $redirect_uri The URI to validate.
     * @param array  $client       The client record from get_client().
     * @return bool True if allowed.
     */
    public static function validate_redirect_uri( $redirect_uri, $client ) {
        // Must be localhost (any port) or HTTPS.
        $parsed = wp_parse_url( $redirect_uri );
        if ( ! $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
            return false;
        }

        $is_localhost = in_array( $parsed['host'], [ 'localhost', '127.0.0.1', '::1' ], true );
        if ( ! $is_localhost && 'https' !== $parsed['scheme'] ) {
            return false;
        }

        // Static clients (from settings) accept any valid localhost/HTTPS URI.
        if ( ! empty( $client['is_static'] ) ) {
            return true;
        }

        // Dynamic clients: exact match required.
        $registered = $client['redirect_uris'] ?? [];
        if ( empty( $registered ) ) {
            return true; // No URIs registered = accept any valid one (matches Claude Desktop behavior).
        }

        return in_array( $redirect_uri, $registered, true );
    }
}
