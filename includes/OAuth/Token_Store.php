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
    const REJECTED_CIMD_OPTION = 'royal_mcp_rejected_cimd_clients';


    /** Token lifetimes in seconds. */
    const ACCESS_TOKEN_TTL  = 86400;      // 24 hours — default fallback; site-configurable via get_access_token_ttl()
    const REFRESH_TOKEN_TTL = 2592000;    // 30 days
    const AUTH_CODE_TTL     = 600;        // 10 minutes

    /**
     * How long a refresh token stays usable AFTER it has been rotated.
     * Grace window covers concurrent refresh presentations from a single
     * client so a rotation race doesn't force a re-consent. OAuth 2.1 §6.1
     * permits a short reuse-interval for this purpose.
     */
    const REFRESH_GRACE_SECONDS = 60;

    /** How many times a used refresh token may be presented again inside its grace window. */
    const REFRESH_GRACE_RETRIES = 3;

    /** Whitelist of access token TTL values selectable in Settings → OAuth. */
    const ACCESS_TOKEN_TTL_CHOICES = [ 3600, 28800, 86400, 604800 ];

    /**
     * Return the effective access-token TTL in seconds.
     *
     * Reads royal_mcp_settings['access_token_ttl_seconds'] (whitelist-guarded),
     * falls back to ACCESS_TOKEN_TTL when unset or invalid, then exposes a
     * royal_mcp_access_token_ttl filter for per-role / per-client policies.
     * The filter has final say — matches WP convention where filters override
     * options.
     *
     * @param int    $user_id   User the token is issued to (0 when not known).
     * @param string $client_id OAuth client the token is issued to ('' when not known).
     * @return int TTL in seconds.
     */
    public static function get_access_token_ttl( $user_id = 0, $client_id = '' ) {
        $settings   = get_option( 'royal_mcp_settings', [] );
        // Guard against a corrupted option value (string, null, object). Any non-array
        // shape falls back to the constant default rather than blowing up on array access.
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }
        $configured = isset( $settings['access_token_ttl_seconds'] ) ? (int) $settings['access_token_ttl_seconds'] : 0;
        $ttl        = in_array( $configured, self::ACCESS_TOKEN_TTL_CHOICES, true ) ? $configured : self::ACCESS_TOKEN_TTL;

        /**
         * Filter the access-token TTL in seconds.
         *
         * Overrides the site-owner UI selection. Return any positive integer.
         *
         * @param int    $ttl       Effective TTL after option lookup + whitelist check.
         * @param int    $user_id   User the token is issued to (0 when not known).
         * @param string $client_id OAuth client the token is issued to ('' when not known).
         */
        $filtered = (int) apply_filters( 'royal_mcp_access_token_ttl', $ttl, (int) $user_id, (string) $client_id );

        return $filtered > 0 ? $filtered : self::ACCESS_TOKEN_TTL;
    }

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
     * Get the authorization codes table name.
     */
    public static function auth_codes_table() {
        global $wpdb;
        return $wpdb->prefix . 'royal_mcp_oauth_auth_codes';
    }

    /**
     * Create all OAuth tables. Called from plugin activation AND from the
     * runtime migration check in royal-mcp.php (which fires on plugins_loaded
     * when royal_mcp_db_version doesn't match ROYAL_MCP_VERSION). Idempotent.
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $tokens_table     = self::tokens_table();
        $clients_table    = self::clients_table();
        $auth_codes_table = self::auth_codes_table();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // dbDelta needs each CREATE TABLE as a separate call.
        dbDelta( "CREATE TABLE $tokens_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            token_hash varchar(64) NOT NULL,
            token_type varchar(20) NOT NULL,
            client_id varchar(255) NOT NULL,
            user_id bigint(20) NOT NULL,
            scope varchar(255) DEFAULT '',
            expires_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            revoked tinyint(1) DEFAULT 0 NOT NULL,
            rotated_at datetime DEFAULT NULL,
            resource varchar(255) DEFAULT '' NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY client_id (client_id),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) $charset_collate;" );

        // client_id index is prefixed to 191 chars because a full varchar(255)
        // index under utf8mb4 is 1020 bytes, which exceeds the max key length
        // on some MySQL configurations (notably MyISAM at 1000 bytes). 191 is
        // the standard WordPress utf8mb4-safe prefix (191 * 4 = 764 bytes).
        // Rejected clients never carry client_id values longer than 191 chars
        // anyway (RFC 7591 doesn't cap them but generated values are short).
        dbDelta( "CREATE TABLE $clients_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            client_id varchar(255) NOT NULL,
            client_secret_hash varchar(64) DEFAULT NULL,
            client_name varchar(255) NOT NULL,
            redirect_uris text NOT NULL,
            grant_types varchar(255) DEFAULT 'authorization_code' NOT NULL,
            token_endpoint_auth_method varchar(50) DEFAULT 'none' NOT NULL,
            status varchar(20) DEFAULT 'active' NOT NULL,
            ip_address varchar(45) DEFAULT '' NOT NULL,
            user_agent varchar(255) DEFAULT '' NOT NULL,
            last_authorized_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            issuer_kind varchar(16) DEFAULT '' NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY client_id (client_id(191)),
            KEY status (status)
        ) $charset_collate;" );

        // Authorization codes in a dedicated table (not transients). Object-
        // cache drop-ins on some host stacks silently evict transient keys
        // between /authorize and /token, breaking the OAuth handshake. Direct
        // DB storage with sha256-hashed lookup gives reliable consume semantics
        // regardless of which cache backend is active.
        dbDelta( "CREATE TABLE $auth_codes_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            code_hash varchar(64) NOT NULL,
            user_id bigint(20) NOT NULL,
            client_id varchar(255) NOT NULL,
            redirect_uri text NOT NULL,
            code_challenge varchar(255) NOT NULL,
            code_challenge_method varchar(10) NOT NULL DEFAULT 'S256',
            scope varchar(255) DEFAULT '',
            resource varchar(255) DEFAULT '' NOT NULL,
            used tinyint(1) DEFAULT 0 NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code_hash (code_hash),
            KEY expires_at (expires_at)
        ) $charset_collate;" );

        static $migrating = false;
        if ( ! $migrating ) {
            $migrating = true;
            // Re-checked: the tables were just created or altered.
            self::migrate_namespace_clients( true );
            $migrating = false;
        }
    }

    /**
     * Drop OAuth tables. Called from uninstall.
     */
    public static function drop_tables() {
        global $wpdb;
        $tokens_table     = esc_sql( self::tokens_table() );
        $clients_table    = esc_sql( self::clients_table() );
        $auth_codes_table = esc_sql( self::auth_codes_table() );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS `{$tokens_table}`" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS `{$clients_table}`" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS `{$auth_codes_table}`" );
    }

    /* ------------------------------------------------------------------
     *  Authorization codes  (DB-backed — see create_tables comment)
     * ----------------------------------------------------------------*/

    /**
     * Store an authorization code.
     *
     * Stores only the sha256 hash of the code, not the code itself — same
     * defense-in-depth pattern we use for access/refresh tokens. If the table
     * is ever leaked, attackers can't replay the codes (and they're expired
     * within 10 minutes anyway).
     *
     * @param string $code The raw authorization code (caller keeps the plaintext).
     * @param array  $data Payload: user_id, client_id, redirect_uri, code_challenge, code_challenge_method, scope.
     */
    public static function store_auth_code( $code, array $data ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct insert.
        $wpdb->insert(
            self::auth_codes_table(),
            [
                'code_hash'             => hash( 'sha256', $code ),
                'user_id'               => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
                'client_id'             => isset( $data['client_id'] ) ? (string) $data['client_id'] : '',
                'redirect_uri'          => isset( $data['redirect_uri'] ) ? (string) $data['redirect_uri'] : '',
                'code_challenge'        => isset( $data['code_challenge'] ) ? (string) $data['code_challenge'] : '',
                'code_challenge_method' => isset( $data['code_challenge_method'] ) ? (string) $data['code_challenge_method'] : 'S256',
                'scope'                 => isset( $data['scope'] ) ? (string) $data['scope'] : '',
                'expires_at'            => gmdate( 'Y-m-d H:i:s', time() + self::AUTH_CODE_TTL ),
            ] + ( self::has_resource_columns() ? [ 'resource' => isset( $data['resource'] ) ? substr( (string) $data['resource'], 0, 255 ) : '' ] : [] ),
            self::has_resource_columns() ? [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] : [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Consume an authorization code (single-use, atomic).
     *
     * Two-step pattern: an atomic UPDATE marks the row used IFF it exists,
     * is unused, and is unexpired — returning affected_rows = 1 if we won the
     * race (single-row MySQL lock semantics make this safe for concurrent
     * /token POSTs). If we won, a SELECT reads the payload. If two requests
     * arrive simultaneously with the same code, exactly one will get the
     * payload; the other gets false.
     *
     * @param string $code The raw code presented by the client.
     * @return array|false The stored payload, or false if invalid/expired/already-used.
     */
    public static function consume_auth_code( $code ) {
        global $wpdb;
        $table = self::auth_codes_table();
        $hash  = hash( 'sha256', $code );
        $now   = gmdate( 'Y-m-d H:i:s' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
        $claimed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET used = 1 WHERE code_hash = %s AND used = 0 AND expires_at > %s",
                $hash,
                $now
            )
        );

        if ( ! $claimed ) {
            return false; // Code doesn't exist, already consumed, or expired.
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT user_id, client_id, redirect_uri, code_challenge, code_challenge_method, scope" . ( self::has_resource_columns() ? ', resource' : '' ) . " FROM `{$table}` WHERE code_hash = %s LIMIT 1",
                $hash
            ),
            ARRAY_A
        );

        return $row ? $row : false;
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
    public static function create_token_pair( $client_id, $user_id, $scope = '', $resource = '' ) {
        $access_token  = bin2hex( random_bytes( 32 ) );
        $refresh_token = bin2hex( random_bytes( 32 ) );
        $access_ttl    = self::get_access_token_ttl( (int) $user_id, (string) $client_id );

        self::store_token( $access_token, 'access', $client_id, $user_id, $scope, $access_ttl, $resource );
        self::store_token( $refresh_token, 'refresh', $client_id, $user_id, $scope, self::REFRESH_TOKEN_TTL, $resource );

        // Mark the client as "has completed an authorization at least once" so
        // gc_stale_clients() never removes a client whose tokens merely expired
        // (an idle connector) — only clients that never finished a handshake.
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            self::clients_table(),
            [ 'last_authorized_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'client_id' => $client_id ],
            [ '%s' ],
            [ '%s' ]
        );

        return [
            'access_token'  => $access_token,
            'token_type'    => 'Bearer',
            'expires_in'    => $access_ttl,
            'refresh_token' => $refresh_token,
            'refresh_token_expires_in' => self::REFRESH_TOKEN_TTL,
            'scope'         => $scope,
        ];
    }

    /**
     * Store a single token (hashed) in the database.
     */
    private static function store_token( $raw_token, $type, $client_id, $user_id, $scope, $ttl, $resource = '' ) {
        global $wpdb;
        $row = [
            'token_hash' => hash( 'sha256', $raw_token ),
            'token_type' => $type,
            'client_id'  => $client_id,
            'user_id'    => $user_id,
            'scope'      => $scope,
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
        ];
        $formats = [ '%s', '%s', '%s', '%d', '%s', '%s' ];
        if ( self::has_resource_columns() ) {
            $row['resource'] = substr( (string) $resource, 0, 255 );
            $formats[]       = '%s';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert( self::tokens_table(), $row, $formats );
    }

    /**
     * Whether the tokens and codes tables have the resource column (RFC 8707). A table
     * made by an older version gets it on the next table update; until then tokens are
     * simply not bound, so sign-in never breaks over it.
     */
    public static function has_resource_columns() {
        static $has = null;
        if ( null !== $has ) {
            return $has;
        }
        global $wpdb;
        $probe = static function () use ( $wpdb ) {
            $ok = true;
            foreach ( [ self::tokens_table(), self::auth_codes_table() ] as $table ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
                $ok = $ok && (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'resource' ) );
            }
            return $ok;
        };
        $has = $probe();
        if ( ! $has && ! get_transient( 'royal_mcp_resource_column_failed' ) ) {
            self::create_tables();
            $has = $probe();
            if ( ! $has ) {
                set_transient( 'royal_mcp_resource_column_failed', 1, HOUR_IN_SECONDS );
            }
        }
        return $has;
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
     * A used token is kept as revoked = 2 with its rotation time: inside the grace window it is a
     * client retry, after it a copy, which ends the whole grant.
     *
     * @param string    $raw_refresh_token The raw refresh token.
     * @param bool|null $reused            Set to true when the token was presented again after its grace window.
     * @return array|false Token row or false.
     */
    public static function consume_refresh_token( $raw_refresh_token, &$reused = null ) {
        global $wpdb;
        $table  = self::tokens_table();
        $hash   = hash( 'sha256', $raw_refresh_token );
        $reused = false;

        if ( self::has_rotation_column() ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE token_hash = %s AND token_type = 'refresh' AND revoked IN ( 0, 2 ) AND expires_at > %s LIMIT 1",
                    $hash,
                    gmdate( 'Y-m-d H:i:s' )
                ),
                ARRAY_A
            );
            if ( ! $row ) {
                return false;
            }
            if ( empty( $row['rotated_at'] ) ) {
                $now = gmdate( 'Y-m-d H:i:s' );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
                $claimed = (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET rotated_at = %s, revoked = 2 WHERE id = %d AND rotated_at IS NULL AND revoked = 0", $now, (int) $row['id'] ) );
                if ( 1 === $claimed ) {
                    $row['rotated_at'] = $now;
                    return $row;
                }
                // Another request rotated it a moment ago: read its rotation time.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d AND revoked IN ( 0, 2 ) LIMIT 1", (int) $row['id'] ), ARRAY_A );
                if ( ! $row || empty( $row['rotated_at'] ) ) {
                    return false;
                }
            }
            // A rotated token presented again inside the grace window is a client retry; it gets its own pair,
            // a few times at most (each retry would otherwise mint another working pair).
            if ( time() - (int) strtotime( $row['rotated_at'] . ' UTC' ) <= self::REFRESH_GRACE_SECONDS ) {
                if ( ! self::claim_grace_retry( $hash ) ) {
                    return false;
                }
                return $row;
            }
            // Presented again after the grace window: the token has been copied. End the whole grant.
            $reused = true;
            self::revoke_connection( $row['client_id'], (int) $row['user_id'] );
            return false;
        }

        // Tokens table without rotated_at (the upgrade has not added it): rotation by expiry only.

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

        // Rotate: the presented refresh token stops being valid REFRESH_GRACE_SECONDS
        // from now instead of immediately. A concurrent second presentation inside
        // that window still succeeds (and mints its own fresh pair) rather than
        // failing with invalid_grant and forcing a full re-consent. Never extend a
        // token that is already closer to expiry than the grace window.
        $grace_until = gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_GRACE_SECONDS );
        if ( (string) $row['expires_at'] > $grace_until ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->update(
                $table,
                [ 'expires_at' => $grace_until ],
                [ 'id' => $row['id'] ],
                [ '%s' ],
                [ '%d' ]
            );
        }

        return $row;
    }

    /**
     * Whether the tokens table has rotated_at, which records when a refresh token was used.
     * Adds it once when it is missing; a failed attempt is not retried for an hour.
     */
    private static function has_rotation_column() {
        static $has = [];
        global $wpdb;
        $table = self::tokens_table();
        if ( isset( $has[ $table ] ) ) {
            return $has[ $table ];
        }
        $probe = static function () use ( $wpdb, $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
            return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'rotated_at' ) );
        };
        $found = $probe();
        if ( ! $found && ! get_transient( 'royal_mcp_rotation_column_failed' ) ) {
            self::create_tables();
            $found = $probe();
            if ( ! $found ) {
                set_transient( 'royal_mcp_rotation_column_failed', 1, HOUR_IN_SECONDS );
            }
        }
        $has[ $table ] = $found;
        return $found;
    }

    /**
     * The client and user a live refresh token belongs to, read without using it.
     *
     * @return array|null [client_id, user_id] or null.
     */
    public static function peek_refresh_token( $raw_refresh_token ) {
        global $wpdb;
        $table = self::tokens_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT client_id, user_id FROM `{$table}` WHERE token_hash = %s AND token_type = 'refresh' AND revoked IN ( 0, 2 ) AND expires_at > %s LIMIT 1",
                hash( 'sha256', (string) $raw_refresh_token ),
                gmdate( 'Y-m-d H:i:s' )
            ),
            ARRAY_A
        );
        return $row ? $row : null;
    }

    /**
     * The client and user of an unused, unexpired authorization code, without using it.
     *
     * @param string $code Raw code.
     * @return array{client_id:string,user_id:string}|null
     */
    public static function peek_auth_code( $code ) {
        global $wpdb;
        $table = self::auth_codes_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT client_id, user_id FROM `{$table}` WHERE code_hash = %s AND used = 0 AND expires_at > %s LIMIT 1",
                hash( 'sha256', (string) $code ),
                gmdate( 'Y-m-d H:i:s' )
            ),
            ARRAY_A
        );
        return $row ? $row : null;
    }

    /** The client a stored token (of either kind) was issued to, or null. */
    public static function token_client( $raw_token ) {
        global $wpdb;
        $table = self::tokens_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
        $client_id = $wpdb->get_var( $wpdb->prepare( "SELECT client_id FROM `{$table}` WHERE token_hash = %s LIMIT 1", hash( 'sha256', (string) $raw_token ) ) );
        return null === $client_id ? null : (string) $client_id;
    }

    /** Whether a stored token has been revoked since it was read. */
    public static function token_is_revoked( $token_id ) {
        global $wpdb;
        $table = self::tokens_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
        $revoked = $wpdb->get_var( $wpdb->prepare( "SELECT revoked FROM `{$table}` WHERE id = %d", (int) $token_id ) );
        return null === $revoked || 1 === (int) $revoked;
    }

    /**
     * Soft-delete every unrevoked access + refresh token in one operation.
     *
     * Powers the "Revoke all" button under Connections → Connected Clients.
     * Uses soft-delete (revoked = 1) rather than hard truncate so future
     * audit-log surfaces can still inspect what was revoked. Does not touch
     * registered clients or in-flight authorization codes.
     *
     * @return int Number of token rows revoked.
     */
    public static function revoke_all_tokens() {
        global $wpdb;
        $table = self::tokens_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
        $count = (int) $wpdb->query(
            "UPDATE `{$table}` SET revoked = 1 WHERE revoked <> 1"
        );
        return $count;
    }

    /**
     * Revoke every OAuth token a user holds, on every client.
     *
     * @return int Token rows revoked.
     */
    public static function revoke_all_for_user( $user_id ) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return 0;
        }
        $table = self::tokens_table();
        // Active tokens and refresh tokens still inside their reuse grace window (revoked = 2).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a safe helper
        return (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET revoked = 1 WHERE user_id = %d AND revoked IN (0, 2)", $user_id ) );
    }

    /** deleted_user: the account is gone, and so are its connections. */
    public static function revoke_on_user_removed( $user_id ) {
        self::revoke_all_for_user( (int) $user_id );
    }

    /** remove_user_from_blog: the account left one site of a network; its connections to that site end. */
    public static function revoke_on_removed_from_blog( $user_id, $blog_id = 0 ) {
        $blog_id  = (int) $blog_id;
        $switched = is_multisite() && $blog_id > 0 && get_current_blog_id() !== $blog_id;
        if ( $switched ) {
            switch_to_blog( $blog_id );
        }
        try {
            if ( self::tokens_table_exists() ) {
                self::revoke_all_for_user( (int) $user_id );
            }
        } finally {
            if ( $switched ) {
                restore_current_blog();
            }
        }
    }

    /** Whether the current site has the tokens table (a network site where the plugin never ran has none). */
    private static function tokens_table_exists() {
        global $wpdb;
        $table = self::tokens_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }

    /** after_password_reset: the user chose a new password through the reset link. */
    public static function revoke_on_password_reset( $user ) {
        if ( is_object( $user ) && isset( $user->ID ) ) {
            self::revoke_all_for_user( (int) $user->ID );
        }
    }

    /** wp_set_password: the password was changed (profile screen, an admin, or code). */
    public static function revoke_on_password_set( $password, $user_id = 0 ) {
        self::revoke_all_for_user( (int) $user_id );
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
     * Revoke the grant a token belongs to: every unrevoked access and refresh
     * token issued to the same client for the same user. Tokens carry no
     * grant identifier, so client + user is the narrowest unit that is sure
     * to take the token's partner with it.
     *
     * Changes nothing and returns 0 when the token is unknown or already
     * revoked, when $client_id names a different client than the one the
     * token was issued to, or when that client authenticates with a secret
     * and the caller has not done so.
     *
     * @param string $raw_token            Access or refresh token as presented.
     * @param string $client_id            Client making the request, '' when not stated.
     * @param bool   $client_authenticated Whether $client_id proved itself with its secret.
     * @return int Token rows revoked.
     */
    public static function revoke_by_token( $raw_token, $client_id = '', $client_authenticated = false ) {
        global $wpdb;
        $table = self::tokens_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT client_id, user_id, revoked FROM `{$table}` WHERE token_hash = %s LIMIT 1",
                hash( 'sha256', (string) $raw_token )
            ),
            ARRAY_A
        );
        if ( ! $row || 1 === (int) $row['revoked'] ) {
            return 0;
        }

        $client_id = (string) $client_id;
        if ( '' !== $client_id && ! hash_equals( (string) $row['client_id'], $client_id ) ) {
            return 0;
        }
        if ( ! $client_authenticated ) {
            $owner = self::get_client( $row['client_id'] );
            if ( $owner && 'client_secret_post' === ( $owner['token_endpoint_auth_method'] ?? 'none' ) ) {
                return 0;
            }
        }

        return self::revoke_connection( $row['client_id'], (int) $row['user_id'] );
    }

    /**
     * Count one more retry of a used refresh token inside its grace window, if the cap allows it.
     * The counter is a database row changed by single conditional statements, so two requests at
     * the same moment cannot both take the last retry. Rows are removed by the daily cleanup.
     *
     * @param string $hash sha256 of the presented refresh token.
     * @return bool True when this retry may get a new pair.
     */
    private static function claim_grace_retry( $hash ) {
        global $wpdb;
        $cap = (int) apply_filters( 'royal_mcp_refresh_grace_retries', self::REFRESH_GRACE_RETRIES );
        if ( $cap <= 0 ) {
            return false;
        }
        $name = self::GRACE_RETRY_PREFIX . substr( (string) $hash, 0, 40 );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic counter row
        $inserted = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, '1', 'no' )", $name ) );
        if ( 1 === $inserted ) {
            return true;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic counter row
        $claimed = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CAST( option_value AS UNSIGNED ) + 1 WHERE option_name = %s AND CAST( option_value AS UNSIGNED ) < %d", $name, $cap ) );
        return 1 === $claimed;
    }

    /** Option-name prefix of the grace-window retry counters. */
    const GRACE_RETRY_PREFIX = 'royal_mcp_rt_retry_';

    /**
     * Revoke every token issued to one client, for every user.
     *
     * @param string $client_id Client identifier.
     * @return int Token rows revoked.
     */
    public static function revoke_client_tokens( $client_id ) {
        global $wpdb;
        $client_id = (string) $client_id;
        if ( '' === $client_id ) {
            return 0;
        }
        $table = self::tokens_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a safe helper
        return (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET revoked = 1 WHERE client_id = %s AND revoked <> 1", $client_id ) );
    }

    /**
     * When the static client id in Settings is changed or cleared, the old id's tokens stop working.
     * Hooked on update_option_royal_mcp_settings.
     *
     * @param mixed $old_value Settings before the save.
     * @param mixed $new_value Settings after the save.
     */
    public static function on_settings_updated( $old_value, $new_value ) {
        $old_id = is_array( $old_value ) ? (string) ( $old_value['oauth_client_id'] ?? '' ) : '';
        $new_id = is_array( $new_value ) ? (string) ( $new_value['oauth_client_id'] ?? '' ) : '';
        if ( '' === $old_id || $old_id === $new_id ) {
            return;
        }
        // A registered client with the same id is a different application; leave its tokens alone.
        global $wpdb;
        $clients = self::clients_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a safe helper
        if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM `{$clients}` WHERE client_id = %s LIMIT 1", $old_id ) ) ) {
            return;
        }
        self::revoke_client_tokens( $old_id );
    }

    /**
     * Revoke every live token one client holds for one user. The client's
     * registration is kept, so it can be authorized again from the client.
     *
     * @param string $client_id Client the tokens were issued to.
     * @param int    $user_id   User the tokens act as.
     * @return int Token rows revoked.
     */
    public static function revoke_connection( $client_id, $user_id ) {
        global $wpdb;
        $table = self::tokens_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET revoked = 1 WHERE client_id = %s AND user_id = %d AND revoked <> 1",
                (string) $client_id,
                (int) $user_id
            )
        );
    }

    /**
     * Whether a client still holds a usable token (access, or refresh that can
     * still be exchanged) for a user. False once the connection is revoked.
     *
     * @param string $client_id Client identifier.
     * @param int    $user_id   User the tokens act as.
     * @return bool
     */
    public static function connection_is_live( $client_id, $user_id ) {
        global $wpdb;
        if ( '' === (string) $client_id || (int) $user_id <= 0 || ! self::tokens_table_exists() ) {
            return false;
        }
        $table = self::tokens_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a safe helper
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM `{$table}` WHERE client_id = %s AND user_id = %d AND revoked IN ( 0, 2 ) AND expires_at > %s LIMIT 1", (string) $client_id, (int) $user_id, gmdate( 'Y-m-d H:i:s' ) ) );
    }

    /**
     * Connections that can use the site right now: one row per client and
     * user holding at least one token that is neither revoked nor expired,
     * most recently issued first.
     *
     * Ages are seconds counted by the database rather than timestamps: the
     * created_at columns are stamped by the database in its own time zone.
     *
     * @param int $limit Most rows to return.
     * @return array[] Each: client_id, client_name ('' when unknown), user_id,
     *                 live_tokens, last_issued_age, registered_age (null when
     *                 the client has no stored registration).
     */
    public static function list_connections( $limit = 200 ) {
        global $wpdb;
        $tokens  = self::tokens_table();
        $clients = self::clients_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT client_id, user_id, COUNT(*) AS live_tokens, MIN( TIMESTAMPDIFF( SECOND, created_at, NOW() ) ) AS last_issued_age
                 FROM `{$tokens}`
                 WHERE revoked = 0 AND expires_at > %s
                 GROUP BY client_id, user_id
                 ORDER BY last_issued_age ASC
                 LIMIT %d",
                gmdate( 'Y-m-d H:i:s' ),
                max( 1, (int) $limit )
            ),
            ARRAY_A
        );
        if ( ! $rows ) {
            return [];
        }

        $ids          = array_values( array_unique( array_column( $rows, 'client_id' ) ) );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method; one %s placeholder per id.
        $registered = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT client_id, client_name, TIMESTAMPDIFF( SECOND, created_at, NOW() ) AS registered_age FROM `{$clients}` WHERE client_id IN ( {$placeholders} )",
                $ids
            ),
            ARRAY_A
        );
        $by_id = [];
        foreach ( (array) $registered as $client ) {
            $by_id[ (string) $client['client_id'] ] = $client;
        }

        $out = [];
        foreach ( $rows as $row ) {
            $client_id = (string) $row['client_id'];
            $name      = '';
            $age       = null;
            if ( isset( $by_id[ $client_id ] ) ) {
                $name = (string) $by_id[ $client_id ]['client_name'];
                $age  = max( 0, (int) $by_id[ $client_id ]['registered_age'] );
            } else {
                // The client defined in the plugin's settings has no stored registration.
                $static = self::get_client( $client_id );
                if ( $static ) {
                    $name = (string) $static['client_name'];
                }
            }
            $out[] = [
                'client_id'       => $client_id,
                'client_name'     => $name,
                'user_id'         => (int) $row['user_id'],
                'live_tokens'     => (int) $row['live_tokens'],
                'last_issued_age' => max( 0, (int) $row['last_issued_age'] ),
                'registered_age'  => $age,
            ];
        }
        return $out;
    }

    /**
     * Wipe ALL OAuth state — registered clients, issued tokens, and pending
     * auth codes — in one operation. Used by the admin "Reset OAuth State"
     * button to recover from stuck handshakes without resorting to wp-cli SQL.
     *
     * All connected MCP clients will need to re-authorize after this runs.
     * Does NOT touch settings (API key, allow-lists), Activity Log entries,
     * or any other plugin state.
     *
     * @return array Counts of deleted rows: [ 'clients' => N, 'tokens' => N, 'auth_codes' => N ].
     */
    public static function reset_all_oauth_state() {
        global $wpdb;
        $tokens_table     = esc_sql( self::tokens_table() );
        $clients_table    = esc_sql( self::clients_table() );
        $auth_codes_table = esc_sql( self::auth_codes_table() );

        // Wipe all rows in each OAuth table. Table names come from esc_sql() above; no user input. phpcs:ignore comments are per-line because the security scanner grep is line-scoped, not block-scoped.
        $tokens     = (int) $wpdb->query( "DELETE FROM `{$tokens_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $clients    = (int) $wpdb->query( "DELETE FROM `{$clients_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $auth_codes = (int) $wpdb->query( "DELETE FROM `{$auth_codes_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Belt-and-suspenders: clear any legacy in-flight authcode transients from earlier storage backends.
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_royal_mcp_authcode_%' OR option_name LIKE '_transient_timeout_royal_mcp_authcode_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        // Also clear any manually-configured static OAuth client_id / client_secret so the
        // connector falls back to Dynamic Client Registration on the next handshake.
        // Without this clear, an admin in manual-creds mode has no UI path back to DCR.
        $static_creds_cleared = 0;
        $settings             = get_option( 'royal_mcp_settings', [] );
        if ( is_array( $settings ) && ( ! empty( $settings['oauth_client_id'] ) || ! empty( $settings['oauth_client_secret'] ) || ! empty( $settings['oauth_client_secret_hash'] ) ) ) {
            $settings['oauth_client_id'] = '';
            unset( $settings['oauth_client_secret'], $settings['oauth_client_secret_hash'], $settings['oauth_static_redirect_uris'] );
            if ( class_exists( '\\Royal_MCP\\Admin\\Settings_Page' ) ) {
                \Royal_MCP\Admin\Settings_Page::save_programmatically( $settings );
            } else {
                update_option( 'royal_mcp_settings', $settings );
            }
            $static_creds_cleared = 1;
        }

        return [
            'clients'              => $clients,
            'tokens'               => $tokens,
            'auth_codes'           => $auth_codes,
            'static_creds_cleared' => $static_creds_cleared,
        ];
    }

    /**
     * Delete OAuth clients that have never had a successful authorization AND
     * are older than the TTL window. Failed handshake attempts (network hiccups
     * on token exchange, wizard retries, abandoned setups) leave orphan client
     * rows that never issue a token. Left alone, these accumulate over time,
     * so a periodic sweep keeps the clients and auth_codes tables lean.
     *
     * TTL default 14 days, filterable via royal_mcp_oauth_gc_ttl_days. Applies
     * to both dynamic-registration (rmcp_ prefix) and CIMD (https:// prefix)
     * client rows uniformly — the "never authorized" signal is authoritative
     * for both origin types.
     *
     * Cascade: auth codes for the deleted clients are also removed. Tokens
     * cascade is a no-op by construction (a client with zero tokens has none
     * to delete), but the DELETE is defensive against manual DB mutations.
     *
     * @return int Number of stale clients deleted.
     */
    public static function gc_stale_clients() {
        global $wpdb;

        $ttl_days = (int) apply_filters( 'royal_mcp_oauth_gc_ttl_days', 14 );
        if ( $ttl_days < 1 ) {
            $ttl_days = 1;
        }

        $clients_table    = self::clients_table();
        $tokens_table     = self::tokens_table();
        $auth_codes_table = self::auth_codes_table();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $stale_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT c.client_id
                 FROM `{$clients_table}` c
                 LEFT JOIN `{$tokens_table}` t ON t.client_id = c.client_id
                 WHERE c.created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
                   AND c.last_authorized_at IS NULL
                   AND t.id IS NULL
                 GROUP BY c.client_id",
                $ttl_days
            )
        );

        if ( empty( $stale_ids ) ) {
            return 0;
        }

        $placeholders = implode( ',', array_fill( 0, count( $stale_ids ), '%s' ) );

        // Drop orphan auth codes first so the client row deletion doesn't leave
        // dangling FK-like references for anyone reading the auth_codes table.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$auth_codes_table}` WHERE client_id IN ({$placeholders})",
                $stale_ids
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$clients_table}` WHERE client_id IN ({$placeholders})",
                $stale_ids
            )
        );

        return (int) $deleted;
    }

    /**
     * Delete expired and revoked tokens, plus expired and consumed auth codes.
     * Called by scheduled cleanup.
     */
    public static function cleanup_expired() {
        global $wpdb;
        $tokens_table     = esc_sql( self::tokens_table() );
        $auth_codes_table = esc_sql( self::auth_codes_table() );
        $now              = gmdate( 'Y-m-d H:i:s' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$tokens_table}` WHERE revoked = 1 OR expires_at < %s",
                $now
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$auth_codes_table}` WHERE used = 1 OR expires_at < %s",
                $now
            )
        );

        // Grace-window retry counters: their tokens' windows ended long before the daily run.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::GRACE_RETRY_PREFIX ) . '%' ) );
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
            ? array_map( [ self::class, 'sanitize_redirect_uri' ], $data['redirect_uris'] )
            : [];

        $client_name = isset( $data['client_name'] ) ? sanitize_text_field( $data['client_name'] ) : 'MCP Client';
        $grant_types = isset( $data['grant_types'] ) && is_array( $data['grant_types'] )
            ? sanitize_text_field( implode( ' ', $data['grant_types'] ) )
            : 'authorization_code';

        // Pending-approval gate. Site owners flip this on to require a manual
        // approve/reject decision before every newly-registered OAuth client
        // can complete an /authorize handshake. Default remains active for
        // backwards compatibility — installs that don't touch the toggle keep
        // the standard open-DCR behavior with no change to any existing flow.
        $require_approval = ! empty( $data['_require_approval'] );
        $status           = $require_approval ? 'pending_approval' : 'active';

        // Requester metadata for the Pending Clients admin surface. Captured
        // from the OAuth Server dispatch (which pulls REMOTE_ADDR + UA) and
        // passed through as sanitized strings.
        $ip_address = isset( $data['_ip_address'] ) ? substr( (string) $data['_ip_address'], 0, 45 ) : '';
        $user_agent = isset( $data['_user_agent'] ) ? substr( sanitize_text_field( (string) $data['_user_agent'] ), 0, 255 ) : '';

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
                'status'                     => $status,
                'ip_address'                 => $ip_address,
                'user_agent'                 => $user_agent,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
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
            'status'                     => $status,
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
        $settings = self::static_client_settings();
        if ( ! empty( $settings['oauth_client_id'] ) && hash_equals( (string) $settings['oauth_client_id'], (string) $client_id ) ) {
            $secret_hash = ! empty( $settings['oauth_client_secret_hash'] ) ? (string) $settings['oauth_client_secret_hash'] : null;
            return [
                'client_id'                  => $settings['oauth_client_id'],
                'client_secret_hash'         => $secret_hash,
                'client_name'                => get_bloginfo( 'name' ) . ' (static)',
                // Only the redirect URIs the site owner registered for this client.
                'redirect_uris'              => self::static_redirect_uris( $settings ),
                'grant_types'                => 'authorization_code',
                'status'                     => 'active', // Static clients are always active — never gated by approval.
                'token_endpoint_auth_method' => null !== $secret_hash ? 'client_secret_post' : 'none',
                'is_static'                  => true,
            ];
        }

        return false;
    }

    /* ------------------------------------------------------------------
     *  Client ID Metadata Document (CIMD) support
     * ----------------------------------------------------------------*/

    /**
     * Clients registered at the namespace endpoints, by client_id: the list kept before
     * the clients table had an issuer_kind column. Read until it has been moved into
     * the column (migrate_namespace_clients()), then deleted.
     */
    const NAMESPACE_CLIENTS_OPTION = 'royal_mcp_oauth_namespace_clients';

    /** Record that a client registered through the namespace endpoints, on its own row. */
    public static function mark_namespace_client( $client_id ) {
        global $wpdb;
        if ( self::has_issuer_kind_column() ) {
            $table = self::clients_table();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Clients table, no cache layer.
            $done = $wpdb->update( $table, [ 'issuer_kind' => 'namespace' ], [ 'client_id' => (string) $client_id ], [ '%s' ], [ '%s' ] );
            if ( false !== $done ) {
                return;
            }
        }
        // Without the column (its creation failed on this host): the bounded list.
        $map = get_option( self::NAMESPACE_CLIENTS_OPTION, [] );
        $map = is_array( $map ) ? $map : [];
        $map[ (string) $client_id ] = time();
        arsort( $map );
        update_option( self::NAMESPACE_CLIENTS_OPTION, array_slice( $map, 0, 2000, true ), false );
    }

    /** Whether a client registered through the namespace endpoints. */
    public static function is_namespace_client( $client_id ) {
        global $wpdb;
        $client_id = (string) $client_id;
        if ( '' === $client_id ) {
            return false;
        }
        if ( self::has_issuer_kind_column() ) {
            $table = self::clients_table();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
            if ( 'namespace' === $wpdb->get_var( $wpdb->prepare( "SELECT issuer_kind FROM `{$table}` WHERE client_id = %s LIMIT 1", $client_id ) ) ) {
                return true;
            }
        }
        $map = get_option( self::NAMESPACE_CLIENTS_OPTION, [] );
        return is_array( $map ) && isset( $map[ $client_id ] );
    }

    /**
     * Move the bounded namespace-client list into the issuer_kind column, then delete
     * the list. The list is kept when any row fails to update, so nothing is lost.
     */
    public static function migrate_namespace_clients( $fresh = false ) {
        global $wpdb;
        $map = get_option( self::NAMESPACE_CLIENTS_OPTION, null );
        if ( null === $map ) {
            return true;
        }
        if ( ! is_array( $map ) || [] === $map ) {
            delete_option( self::NAMESPACE_CLIENTS_OPTION );
            return true;
        }
        if ( ! self::has_issuer_kind_column( $fresh ) ) {
            return false;
        }
        $table = self::clients_table();
        foreach ( array_chunk( array_map( 'strval', array_keys( $map ) ), 200 ) as $ids ) {
            $marks = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method; one placeholder per id.
            if ( false === $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET issuer_kind = 'namespace' WHERE client_id IN ( {$marks} )", $ids ) ) ) {
                return false;
            }
        }
        delete_option( self::NAMESPACE_CLIENTS_OPTION );
        return true;
    }

    /** Whether the clients table has the issuer_kind column (created when missing, retried hourly). */
    private static function has_issuer_kind_column( $fresh = false ) {
        static $has = [];
        global $wpdb;
        $table = self::clients_table();
        if ( $fresh ) {
            unset( $has[ $table ] );
        }
        if ( isset( $has[ $table ] ) ) {
            return $has[ $table ];
        }
        $probe = static function () use ( $wpdb, $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from safe helper method.
            return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'issuer_kind' ) );
        };
        $found = $probe();
        if ( ! $found && ! get_transient( 'royal_mcp_issuer_kind_column_failed' ) ) {
            $has[ $table ] = false;
            self::create_tables();
            $found = $probe();
            if ( ! $found ) {
                set_transient( 'royal_mcp_issuer_kind_column_failed', 1, HOUR_IN_SECONDS );
            }
        }
        $has[ $table ] = $found;
        return $found;
    }

    /**
     * Detect a CIMD client_id by shape. CIMD client_ids are absolute https URLs
     * that point to a JSON metadata document; standard dynamic-registration
     * client_ids use the `rmcp_` prefix. Detection by prefix avoids a false
     * positive on any future non-URL identifier scheme.
     *
     * @param mixed $client_id Value from the OAuth request; may be non-string.
     * @return bool
     */
    public static function is_cimd_client( $client_id ) {
        return is_string( $client_id )
            && strlen( $client_id ) > 8
            && 0 === strncasecmp( $client_id, 'https://', 8 );
    }

    /**
     * Resolve a URL-shaped client_id against its metadata document.
     *
     * Called by Server.php entry points BEFORE get_client() so the client row
     * is persisted before downstream lookup runs. Non-URL client_ids
     * short-circuit to null immediately, making this a no-op on the standard
     * dynamic-registration path.
     *
     * @param string $client_id The client identifier from the OAuth request.
     * @return array|null Client row (same shape as get_client) or null if
     *                    not a CIMD client, feature disabled, or fetch/validate failed.
     */
    public static function resolve_cimd_client( $client_id ) {
        self::$cimd_failure = '';
        if ( ! self::is_cimd_client( $client_id ) ) {
            return null;
        }

        if ( ! apply_filters( 'royal_mcp_cimd_enabled', true ) ) {
            self::$cimd_failure = 'disabled';
            return null;
        }

        if ( self::is_rejected_cimd_client( $client_id ) ) {
            self::$cimd_failure = 'rejected';
            return null;
        }

        $metadata = self::fetch_cimd_metadata( $client_id );
        if ( null === $metadata ) {
            if ( '' === self::$cimd_failure ) {
                self::$cimd_failure = 'fetch_failed';
                // Only a document a user already approved counts, once its address passed the checks:
                // a request can name any address, and what that address answers is up to its owner.
                if ( self::$cimd_transport_error && self::is_cimd_known_good( $client_id ) ) {
                    self::record_cimd_fetch_failure( $client_id );
                }
            }
            return null;
        }

        if ( ! self::validate_cimd_metadata( $metadata, $client_id ) ) {
            self::$cimd_failure = 'invalid_document';
            return null;
        }

        $result = self::register_cimd_client( $client_id, $metadata );
        if ( is_wp_error( $result ) ) {
            self::$cimd_failure = 'not_saved';
            return null;
        }

        return $result;
    }

    /** Why the last resolve_cimd_client() call in this request found no client ('' when it did, or was not a URL client). */
    private static $cimd_failure = '';

    /**
     * The reason the last metadata-document lookup failed: disabled, rejected, rate_limited,
     * fetch_failed, invalid_document, not_saved, or '' when nothing failed.
     */
    public static function cimd_failure_reason() {
        return self::$cimd_failure;
    }

    /**
     * Fetch and transient-cache a CIMD metadata document.
     *
     * Only https addresses whose host resolves (A and AAAA) to public addresses
     * are fetched, and the connection is pinned to the checked address; redirects
     * (at most 3) get the same checks. Response size is capped at 64KB and the
     * timeout at 5s. Without the curl transport nothing is fetched.
     *
     * @param string $url The metadata document URL.
     * @return array|null Parsed metadata document on success, null on any failure.
     */
    private static function fetch_cimd_metadata( $url ) {
        $ttl       = (int) apply_filters( 'royal_mcp_cimd_cache_ttl', HOUR_IN_SECONDS );
        $cache_key = 'royal_mcp_cimd_meta_' . hash( 'sha256', $url );

        $cached = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }
        self::$cimd_transport_error = false;

        if ( 0 !== strncasecmp( $url, 'https://', 8 ) ) {
            return null;
        }

        if ( false === wp_http_validate_url( $url ) ) {
            return null;
        }

        // This runs before sign-in: cap how many documents one address can make the site fetch.
        // Same address resolution as every other limiter, so a trusted proxy does not merge all callers into one count.
        $ip    = \Royal_MCP\MCP\Server::resolve_client_ip();
        $limit = (int) apply_filters( 'royal_mcp_cimd_fetches_per_ip', 20 );
        $rkey  = 'royal_mcp_cimd_rate_' . md5( $ip );
        $count = (int) get_transient( $rkey );
        if ( $limit > 0 && $count >= $limit ) {
            self::$cimd_failure = 'rate_limited';
            return null;
        }
        set_transient( $rkey, $count + 1, 10 * MINUTE_IN_SECONDS ); // audit:transient-ttl-only -- per-address fetch counter

        // The connection is pinned to the address that was checked, which needs the curl transport.
        if ( ! function_exists( 'curl_init' ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
            return null;
        }
        // Through a proxy the proxy picks the address, so the pin cannot hold.
        if ( self::cimd_goes_through_proxy( (string) $url ) ) {
            return null;
        }

        // Redirects are followed here, so every hop gets the same checks and its own pin.
        $current  = (string) $url;
        $response = null;
        for ( $hop = 0; $hop <= 3; $hop++ ) {
            $pin = self::cimd_check_url( $current );
            if ( null === $pin || self::cimd_goes_through_proxy( $current ) ) {
                self::$cimd_transport_error = false;
                return null;
            }
            // From here on any failure (no answer, an error status, a challenge page) counts as the document not being reachable.
            self::$cimd_transport_error = true;
            $response = self::cimd_pinned_get( $current, $pin[0], $pin[1] );
            if ( null === $response ) {
                return null;
            }
            $status   = (int) wp_remote_retrieve_response_code( $response );
            $location = (string) wp_remote_retrieve_header( $response, 'location' );
            if ( $status >= 300 && $status < 400 && '' !== $location && $hop < 3 ) {
                $current = \WP_Http::make_absolute_url( $location, $current );
                continue;
            }
            break;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( strlen( $body ) > 64 * 1024 ) {
            return null;
        }

        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            return null;
        }

        set_transient( $cache_key, $data, $ttl ); // audit:transient-ttl-only -- client metadata document, fetched again after the cache time
        if ( self::is_cimd_known_good( $url ) ) {
            self::clear_cimd_fetch_failure( $url );
        }

        return $data;
    }

    /** Set when the last document fetch failed after its address passed every check and the connection was pinned. */
    private static $cimd_transport_error = false;

    /** Option holding failed metadata-document fetches: host => time, nothing else. */
    const CIMD_FAILURE_OPTION = 'royal_mcp_cimd_fetch_failure';

    /** Option holding the documents a signed-in user approved: sha256 of the address => time. */
    const CIMD_KNOWN_GOOD_OPTION = 'royal_mcp_cimd_known_good';

    /** Host part of a document address, reduced to host-name characters. */
    private static function cimd_host( $url ) {
        $host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
        return substr( (string) preg_replace( '/[^a-z0-9.\-]/', '', $host ), 0, 100 );
    }

    /**
     * Remember a document whose client was signed in with (tokens were issued), keeping the newest 50.
     *
     * @param string $url Document address (the client_id).
     */
    public static function mark_cimd_known_good( $url ) {
        $list = get_option( self::CIMD_KNOWN_GOOD_OPTION, [] );
        $list = is_array( $list ) ? $list : [];
        $key  = hash( 'sha256', (string) $url );
        unset( $list[ $key ] );
        $list[ $key ] = time();
        update_option( self::CIMD_KNOWN_GOOD_OPTION, array_slice( $list, -50, null, true ), false );
    }

    /** Whether a signed-in user has used this document before. */
    public static function is_cimd_known_good( $url ) {
        $list = get_option( self::CIMD_KNOWN_GOOD_OPTION, [] );
        return is_array( $list ) && isset( $list[ hash( 'sha256', (string) $url ) ] );
    }

    /**
     * Remember that an approved document could not be reached, so the discovery documents stop
     * advertising metadata-document support for a while and clients register instead.
     *
     * @param string $url Address of the document that could not be fetched.
     */
    public static function record_cimd_fetch_failure( $url ) {
        $list = get_option( self::CIMD_FAILURE_OPTION, [] );
        $list = is_array( $list ) && ! isset( $list['at'] ) ? $list : [];
        $host = self::cimd_host( $url );
        unset( $list[ $host ] );
        $list[ $host ] = time();
        update_option( self::CIMD_FAILURE_OPTION, array_slice( $list, -10, null, true ), false );
    }

    /**
     * Forget the failed fetch of one host (a document there was fetched since).
     *
     * @param string $url Address of the document that was fetched.
     */
    public static function clear_cimd_fetch_failure( $url ) {
        $list = get_option( self::CIMD_FAILURE_OPTION, false );
        if ( ! is_array( $list ) ) {
            return;
        }
        unset( $list[ self::cimd_host( $url ) ] );
        if ( empty( $list ) || isset( $list['at'] ) ) {
            delete_option( self::CIMD_FAILURE_OPTION );
        } else {
            update_option( self::CIMD_FAILURE_OPTION, $list, false );
        }
    }

    /**
     * The most recent failed fetch, or null.
     *
     * @return array{at: int, host: string, until: int}|null
     */
    public static function cimd_fetch_failure() {
        $list = get_option( self::CIMD_FAILURE_OPTION, null );
        if ( ! is_array( $list ) || empty( $list ) || isset( $list['at'] ) ) {
            return null;
        }
        arsort( $list );
        $at = (int) reset( $list );
        return [ 'at' => $at, 'host' => (string) key( $list ), 'until' => $at + self::cimd_fallback_window() ];
    }

    /** Seconds metadata-document support stays un-advertised after a failed fetch. */
    public static function cimd_fallback_window() {
        return max( 0, (int) apply_filters( 'royal_mcp_cimd_fallback_window', HOUR_IN_SECONDS ) );
    }

    /** Whether a recent failed fetch keeps metadata-document support out of the discovery documents. */
    public static function cimd_fallback_active() {
        $f = self::cimd_fetch_failure();
        return null !== $f && time() < $f['until'];
    }

    /**
     * Check one metadata-document address before it is requested: https, and every IPv4 and
     * IPv6 address its host resolves to is public.
     *
     * @param string $url Address about to be requested.
     * @return array|null [ host, first checked address ] or null when it may not be fetched.
     */
    private static function cimd_check_url( $url ) {
        $parts = wp_parse_url( (string) $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return null;
        }
        if ( false === wp_http_validate_url( (string) $url ) ) {
            return null;
        }
        $host = trim( strtolower( (string) $parts['host'] ), '[]' );
        $ips  = self::cimd_resolve( $host );
        if ( empty( $ips ) ) {
            return null;
        }
        foreach ( $ips as $ip ) {
            if ( ! self::is_public_ip( $ip ) ) {
                return null;
            }
        }
        return [ $host, (string) $ips[0] ];
    }

    /**
     * The IPv4 and IPv6 addresses a host resolves to (the address itself for an IP literal).
     * Filterable through royal_mcp_cimd_resolved_ips; whatever is returned is still checked.
     *
     * @param string $host Host name or IP literal.
     * @return string[]
     */
    public static function cimd_resolve( $host ) {
        $host = trim( strtolower( (string) $host ), '[]' );
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            $ips = [ $host ];
        } else {
            $ips = (array) @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is handled as no address
            if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
                foreach ( (array) @dns_get_record( $host, DNS_AAAA ) as $aaaa ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is handled as no address
                    if ( is_array( $aaaa ) && ! empty( $aaaa['ipv6'] ) ) {
                        $ips[] = (string) $aaaa['ipv6'];
                    }
                }
            }
        }
        $ips = apply_filters( 'royal_mcp_cimd_resolved_ips', $ips, $host );
        return array_values( array_filter( array_map( 'strval', is_array( $ips ) ? $ips : [] ) ) );
    }

    /** Whether WordPress would send a request for this address through a configured proxy. */
    private static function cimd_goes_through_proxy( $url ) {
        if ( ! defined( 'WP_PROXY_HOST' ) || '' === (string) WP_PROXY_HOST ) {
            return false;
        }
        if ( ! class_exists( '\WP_HTTP_Proxy' ) ) {
            return true;
        }
        $proxy = new \WP_HTTP_Proxy();
        return $proxy->is_enabled() && $proxy->send_through_proxy( $url );
    }

    /**
     * GET one metadata-document address over curl, connected to the checked address.
     * Null when the request failed, did not go through the pinned connection, or curl reports
     * that it connected to another address.
     */
    private static function cimd_pinned_get( $url, $host, $ip ) {
        $pinned  = false;
        $primary = null;
        $after   = static function ( $headers = null, $info = null ) use ( &$primary ) {
            if ( is_array( $info ) && isset( $info['primary_ip'] ) && is_string( $info['primary_ip'] ) && '' !== $info['primary_ip'] ) {
                $primary = $info['primary_ip'];
            }
        };
        $pin    = static function ( $handle, $r, $request_url ) use ( $host, $ip, &$pinned ) {
            if ( trim( strtolower( (string) wp_parse_url( $request_url, PHP_URL_HOST ) ), '[]' ) !== $host ) {
                return;
            }
            $port = (int) ( wp_parse_url( $request_url, PHP_URL_PORT ) ?: 443 );
            $addr = false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip;
            curl_setopt( $handle, CURLOPT_RESOLVE, [ $host . ':' . $port . ':' . $addr ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- pins the connection WordPress opens to the checked address
            $pinned = true;
        };
        add_action( 'http_api_curl', $pin, 10, 3 );
        add_action( 'requests-curl.after_request', $after, 10, 2 );
        add_filter( 'use_streams_transport', '__return_false', PHP_INT_MAX );
        $encoded = false;
        try {
            // The document is asked for uncompressed; a reply sent compressed anyway is refused,
            // and the guard keeps it from being decompressed a second time without a size limit.
            $response = \Royal_MCP\MCP\Support\Http_Decode_Guard::run(
                static function () use ( $url, &$encoded ) {
                    $r = wp_safe_remote_get(
                        $url,
                        [
                            'timeout'             => 5,
                            'redirection'         => 0,
                            'limit_response_size' => 64 * 1024 + 1,
                            'user-agent'  => 'RoyalMCP/' . ROYAL_MCP_VERSION . ' (+https://royalplugins.com/support/royal-mcp/)',
                            'headers'     => [ 'Accept' => 'application/json', 'Accept-Encoding' => 'identity' ],
                        ]
                    );
                    $encoded = \Royal_MCP\MCP\Support\Http_Decode_Guard::saw_encoding() || ( ! is_wp_error( $r ) && '' !== trim( (string) wp_remote_retrieve_header( $r, 'content-encoding' ), " \t" ) && 'identity' !== strtolower( trim( (string) wp_remote_retrieve_header( $r, 'content-encoding' ) ) ) );
                    return $r;
                }
            );
        } finally {
            remove_action( 'http_api_curl', $pin, 10 );
            remove_action( 'requests-curl.after_request', $after, 10 );
            remove_filter( 'use_streams_transport', '__return_false', PHP_INT_MAX );
        }
        if ( is_wp_error( $response ) || ! $pinned || $encoded ) {
            self::$cimd_transport_error = $pinned;
            return null;
        }
        // Where curl reports the address it connected to, it must be the checked one.
        if ( null !== $primary && @inet_pton( trim( $primary, '[]' ) ) !== @inet_pton( trim( (string) $ip, '[]' ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unparsable address compares as false
            return null;
        }
        return $response;
    }

    /**
     * Whether an IPv4 or IPv6 address is public: not private, loopback, link-local, unique-local,
     * shared (carrier-grade NAT), benchmarking, multicast or reserved. An IPv6 address that carries
     * an IPv4 one (mapped, translated, compatible, NAT64, 6to4) is judged by the IPv4 address
     * inside it; Teredo and local-use NAT64 addresses are refused because the address they reach
     * cannot be checked.
     *
     * @param string $ip Address.
     * @return bool
     */
    public static function is_public_ip( $ip ) {
        $ip = trim( (string) $ip, '[]' );
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            $n = ip2long( $ip );
            if ( false === $n || 0x64400000 === ( $n & 0xFFC00000 ) || 0xC6120000 === ( $n & 0xFFFE0000 ) || 0xC0000000 === ( $n & 0xFFFFFF00 ) || 0xE0000000 === ( $n & 0xF0000000 ) ) {
                return false; // 100.64.0.0/10, 198.18.0.0/15, 192.0.0.0/24, 224.0.0.0/4
            }
            return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
        }
        $packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid address is handled below
        if ( false === $packed || 16 !== strlen( $packed ) ) {
            return false;
        }
        if ( str_repeat( "\0", 16 ) === $packed || str_repeat( "\0", 15 ) . "\1" === $packed ) {
            return false; // :: and ::1
        }
        $head = substr( $packed, 0, 12 );
        if ( str_repeat( "\0", 10 ) . "\xff\xff" === $head || str_repeat( "\0", 8 ) . "\xff\xff\0\0" === $head || str_repeat( "\0", 12 ) === $head || "\x00\x64\xff\x9b" . str_repeat( "\0", 8 ) === $head ) {
            $v4 = inet_ntop( substr( $packed, 12 ) );
            return false !== $v4 && self::is_public_ip( $v4 );
        }
        if ( "\x20\x02" === substr( $packed, 0, 2 ) ) {
            $v4 = inet_ntop( substr( $packed, 2, 4 ) );
            return false !== $v4 && self::is_public_ip( $v4 );
        }
        if ( "\x20\x01\x00\x00" === substr( $packed, 0, 4 ) || "\x00\x64\xff\x9b\x00\x01" === substr( $packed, 0, 6 ) ) {
            return false;
        }
        $first = ord( $packed[0] );
        // fc00::/7 unique-local, fe80::/10 link-local, fec0::/10 site-local, ff00::/8 multicast.
        if ( 0xfc === ( $first & 0xfe ) || 0xff === $first || ( 0xfe === $first && 0x80 === ( ord( $packed[1] ) & 0xc0 ) ) || ( 0xfe === $first && 0xc0 === ( ord( $packed[1] ) & 0xc0 ) ) ) {
            return false;
        }
        return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
    }

    /**
     * Validate a CIMD metadata document against RFC 7591 required fields.
     *
     * Required: `redirect_uris` (non-empty array of strings).
     * Optional but typed: `client_name` (string when present). A `client_id`
     * in the document must be the address it was fetched from.
     *
     * @param array  $metadata  Parsed metadata document.
     * @param string $client_id The address the document was fetched from.
     * @return bool
     */
    private static function validate_cimd_metadata( array $metadata, $client_id = '' ) {
        if ( empty( $metadata['redirect_uris'] ) || ! is_array( $metadata['redirect_uris'] ) ) {
            return false;
        }

        if ( isset( $metadata['client_id'] ) && ( ! is_string( $metadata['client_id'] ) || (string) $client_id !== $metadata['client_id'] ) ) {
            return false;
        }

        foreach ( $metadata['redirect_uris'] as $uri ) {
            if ( ! is_string( $uri ) || '' === trim( $uri ) ) {
                return false;
            }
        }

        if ( isset( $metadata['client_name'] ) && ! is_string( $metadata['client_name'] ) ) {
            return false;
        }

        return true;
    }

    /**
     * Persist (upsert) a CIMD-registered client into the clients table.
     *
     * Unlike register_client(), the client_id is provided by the caller (the
     * metadata document URL) rather than randomly generated. Idempotent: a
     * refreshed metadata document updates the existing row in place and keeps
     * its status; a new client waits for approval when the site requires it.
     *
     * @param string $client_id The URL-shaped client_id (metadata document URL).
     * @param array  $metadata  The validated metadata document.
     * @return array|\WP_Error Client record on success.
     */
    public static function register_cimd_client( $client_id, array $metadata ) {
        global $wpdb;

        if ( strlen( $client_id ) > 191 ) {
            return new \WP_Error(
                'royal_mcp_cimd_client_id_too_long',
                'CIMD client_id URL exceeds the 191-character index limit for the clients table.'
            );
        }

        $redirect_uris = array_map( [ self::class, 'sanitize_redirect_uri' ], $metadata['redirect_uris'] );
        $client_name   = isset( $metadata['client_name'] )
            ? sanitize_text_field( $metadata['client_name'] )
            : 'CIMD Client';
        $grant_types = isset( $metadata['grant_types'] ) && is_array( $metadata['grant_types'] )
            ? sanitize_text_field( implode( ' ', $metadata['grant_types'] ) )
            : 'authorization_code';

        $table = self::clients_table();

        $fields = [
            'client_name'                => $client_name,
            'redirect_uris'              => wp_json_encode( $redirect_uris ),
            'grant_types'                => $grant_types,
            'token_endpoint_auth_method' => 'none',
        ];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE client_id = %s LIMIT 1", $client_id ) );
        if ( null !== $existing ) {
            // A refreshed document updates the row; its status (pending, approved) stays.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $replaced = $wpdb->update( $table, $fields, [ 'client_id' => $client_id ], [ '%s', '%s', '%s', '%s' ], [ '%s' ] );
        } else {
            $settings = get_option( 'royal_mcp_settings', [] );
            $status   = ( is_array( $settings ) && ! empty( $settings['require_client_approval'] ) ) ? 'pending_approval' : 'active';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $replaced = $wpdb->insert(
                $table,
                array_merge( [ 'client_id' => $client_id, 'client_secret_hash' => null ], $fields, [ 'status' => $status ] ),
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );
            // Another request registered the same client a moment earlier: that row serves.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
            if ( false === $replaced && null !== $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE client_id = %s LIMIT 1", $client_id ) ) ) {
                $replaced = 0;
            }
        }

        if ( false === $replaced ) {
            return new \WP_Error(
                'royal_mcp_cimd_register_failed',
                'Failed to persist CIMD client registration.',
                [ 'db_error' => $wpdb->last_error ]
            );
        }

        return [
            'client_id'                  => $client_id,
            'client_name'                => $client_name,
            'redirect_uris'              => $redirect_uris,
            'grant_types'                => explode( ' ', $grant_types ),
            'token_endpoint_auth_method' => 'none',
            'response_types'             => [ 'code' ],
            'client_id_issued_at'        => time(),
            'is_cimd'                    => true,
        ];
    }

    /* ------------------------------------------------------------------
     *  Pending-approval helpers (opt-in DCR gate)
     * ----------------------------------------------------------------*/

    /**
     * True if the client row's status is 'pending_approval'. Wraps the raw
     * column read so callers don't hardcode the sentinel value.
     */
    public static function is_pending_approval( array $client ) {
        return 'pending_approval' === ( $client['status'] ?? 'active' );
    }

    /**
     * Flip a client from pending_approval to active. Idempotent — already-
     * active clients are a no-op.
     *
     * @param string $client_id
     * @return bool True on state change, false on no-op / missing row.
     */
    public static function approve_client( $client_id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (bool) $wpdb->update(
            self::clients_table(),
            [ 'status' => 'active' ],
            [ 'client_id' => $client_id, 'status' => 'pending_approval' ],
            [ '%s' ],
            [ '%s', '%s' ]
        );
    }

    /**
     * Reject a pending client by deleting the row and any auth codes it
     * accumulated. Tokens shouldn't exist for a pending client but delete
     * those defensively too.
     *
     * @param string $client_id
     * @return bool True if a row was deleted, false if not found.
     */
    public static function reject_client( $client_id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( self::auth_codes_table(), [ 'client_id' => $client_id ], [ '%s' ] );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( self::tokens_table(), [ 'client_id' => $client_id ], [ '%s' ] );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = (bool) $wpdb->delete(
            self::clients_table(),
            [ 'client_id' => $client_id, 'status' => 'pending_approval' ],
            [ '%s', '%s' ]
        );
        // A metadata-document client would register itself again on its next sign-in; remember the rejection.
        if ( $deleted && self::is_cimd_client( $client_id ) ) {
            $rejected = get_option( self::REJECTED_CIMD_OPTION, [] );
            $rejected = is_array( $rejected ) ? $rejected : [];
            $rejected[ hash( 'sha256', (string) $client_id ) ] = time();
            arsort( $rejected );
            update_option( self::REJECTED_CIMD_OPTION, array_slice( $rejected, 0, 500, true ), false );
        }
        return $deleted;
    }

    /** True when an administrator rejected this metadata-document client. */
    public static function is_rejected_cimd_client( $client_id ) {
        $rejected = get_option( self::REJECTED_CIMD_OPTION, [] );
        return is_array( $rejected ) && isset( $rejected[ hash( 'sha256', (string) $client_id ) ] );
    }

    /**
     * List every client row with status = pending_approval. Includes IP + UA
     * + created_at for the admin surface. Ordered oldest-first so the admin
     * works through the queue in FIFO.
     *
     * @return array
     */
    public static function get_pending_clients() {
        global $wpdb;
        $table = self::clients_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT client_id, client_name, redirect_uris, ip_address, user_agent, created_at
             FROM `{$table}`
             WHERE status = 'pending_approval'
             ORDER BY created_at ASC",
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) {
            return [];
        }
        foreach ( $rows as &$row ) {
            $row['redirect_uris'] = json_decode( $row['redirect_uris'], true ) ?: [];
        }
        return $rows;
    }

    /**
     * Fast count of pending rows for the admin bar badge.
     */
    public static function count_pending_clients() {
        global $wpdb;
        $table = self::clients_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE status = 'pending_approval'"
        );
    }

    /**
     * Auto-expire pending client rows older than the TTL window. Ships alongside
     * the daily royal_mcp_token_cleanup cron so the pending queue can't grow
     * unbounded if an admin stops triaging.
     *
     * TTL default 48 hours, filterable via royal_mcp_pending_client_ttl_hours.
     *
     * @return int Number of pending rows deleted.
     */
    public static function gc_expired_pending_clients() {
        global $wpdb;
        $ttl_hours = (int) apply_filters( 'royal_mcp_pending_client_ttl_hours', 48 );
        if ( $ttl_hours < 1 ) {
            $ttl_hours = 1;
        }
        $table = self::clients_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$table}`
                 WHERE status = 'pending_approval'
                   AND created_at < DATE_SUB(NOW(), INTERVAL %d HOUR)",
                $ttl_hours
            )
        );
    }

    /**
     * Royal MCP settings with the static client secret kept as a hash only. A secret
     * saved in plain text by an earlier version is replaced by its hash on first read.
     *
     * @return array
     */
    public static function static_client_settings() {
        $settings = get_option( 'royal_mcp_settings', [] );
        if ( ! is_array( $settings ) ) {
            return [];
        }
        if ( isset( $settings['oauth_client_secret'] ) && '' !== (string) $settings['oauth_client_secret'] ) {
            $settings['oauth_client_secret_hash'] = hash( 'sha256', (string) $settings['oauth_client_secret'] );
            unset( $settings['oauth_client_secret'] );
            if ( class_exists( '\\Royal_MCP\\Admin\\Settings_Page' ) ) {
                \Royal_MCP\Admin\Settings_Page::save_programmatically( $settings );
            } else {
                update_option( 'royal_mcp_settings', $settings );
            }
        }
        return $settings;
    }

    /**
     * Where a static client with no registered redirect may still return (besides loopback
     * addresses for desktop clients): the sign-in callbacks of the common AI clients.
     *
     * @return string[]
     */
    public static function static_default_redirect_uris() {
        /**
         * Filter the redirect URIs a static OAuth client may use when none are registered.
         *
         * @param string[] $uris Redirect URIs.
         */
        return (array) apply_filters( 'royal_mcp_static_client_default_redirects', [
            'https://claude.ai/api/mcp/auth_callback',
            'https://claude.com/api/mcp/auth_callback',
            'https://chatgpt.com/connector_platform_oauth_redirect',
        ] );
    }

    /**
     * The redirect URIs registered for the static client (one per line in Settings).
     *
     * @param array $settings Royal MCP settings.
     * @return string[]
     */
    public static function static_redirect_uris( array $settings ) {
        $raw  = $settings['oauth_static_redirect_uris'] ?? [];
        $list = is_array( $raw ) ? $raw : preg_split( '/[\r\n]+/', (string) $raw );
        $out  = [];
        foreach ( (array) $list as $uri ) {
            $uri = trim( (string) $uri );
            if ( '' !== $uri && self::is_acceptable_redirect_uri( $uri ) ) {
                $out[] = self::sanitize_redirect_uri( $uri );
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Validate a redirect URI against a client's registered URIs.
     *
     * @param string $redirect_uri The URI to validate.
     * @param array  $client       The client record from get_client().
     * @return bool True if allowed.
     */
    public static function validate_redirect_uri( $redirect_uri, $client ) {
        if ( ! self::is_acceptable_redirect_uri( $redirect_uri ) ) {
            return false;
        }

        // Every client, the static one included, matches against its registered list. An empty
        // registered list denies — nothing to match against, and treating
        // that state as accept-anything defeats the point of the check.
        $registered = $client['redirect_uris'] ?? [];
        if ( empty( $registered ) && ! empty( $client['is_static'] ) ) {
            // No registered redirect: desktop clients on a loopback address, or the known AI client callbacks.
            $host   = (string) wp_parse_url( $redirect_uri, PHP_URL_HOST );
            $scheme = strtolower( (string) wp_parse_url( $redirect_uri, PHP_URL_SCHEME ) );
            if ( '' !== $host && in_array( $scheme, [ 'http', 'https' ], true ) && self::is_loopback_host( $host ) ) {
                return true;
            }
            $registered = self::static_default_redirect_uris();
        }
        if ( empty( $registered ) ) {
            return false;
        }

        foreach ( $registered as $candidate ) {
            if ( self::redirect_uris_match( (string) $candidate, (string) $redirect_uri ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Loopback hosts per RFC 8252 §7.3 (plus `localhost`, common in native
     * OAuth clients even though §8.3 discourages the hostname form).
     */
    public static function is_loopback_host( $host ) {
        return in_array( strtolower( (string) $host ), [ 'localhost', '127.0.0.1', '[::1]', '::1' ], true );
    }

    /**
     * Which redirect URIs this server is willing to deliver codes to:
     *   - https://… with a host (hosted clients)
     *   - http://  ONLY to a loopback host (RFC 8252 §7.3 native clients)
     *   - a private-use app scheme (RFC 8252 §7.1) that isn't on the
     *     dangerous-scheme blocklist
     * Plain http:// to a real host is refused.
     */
    public static function is_acceptable_redirect_uri( $uri ) {
        $parsed = wp_parse_url( (string) $uri );
        if ( ! $parsed || empty( $parsed['scheme'] ) || ! empty( $parsed['user'] ) || ! empty( $parsed['pass'] ) ) {
            return false;
        }
        $scheme = strtolower( $parsed['scheme'] );
        $host   = isset( $parsed['host'] ) ? $parsed['host'] : '';

        if ( 'https' === $scheme ) {
            return '' !== $host;
        }
        if ( 'http' === $scheme ) {
            return self::is_loopback_host( $host );
        }
        return ! in_array( $scheme, [ 'javascript', 'data', 'file', 'ftp', 'mailto', 'tel' ], true );
    }

    /**
     * Compare a registered redirect URI with the one presented on /authorize.
     *
     * Exact string match (OAuth 2.1 §4.1.3) — except for loopback URIs, where
     * RFC 8252 §7.3 REQUIRES the port to be ignored because native clients
     * bind an ephemeral port per session and register a port-less entry.
     * Scheme, host, path and query must still match.
     */
    public static function redirect_uris_match( $registered, $requested ) {
        if ( $registered === $requested ) {
            return true;
        }
        $r = wp_parse_url( $registered );
        $q = wp_parse_url( $requested );
        if ( ! $r || ! $q || empty( $r['host'] ) || empty( $q['host'] ) ) {
            return false;
        }
        if ( ! self::is_loopback_host( $r['host'] ) || ! self::is_loopback_host( $q['host'] ) ) {
            return false;
        }
        $norm = static function ( array $p ) {
            $path = isset( $p['path'] ) ? rtrim( $p['path'], '/' ) : '';
            return strtolower( $p['scheme'] ?? '' ) . '://' . strtolower( $p['host'] ) . $path
                . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
        };
        return $norm( $r ) === $norm( $q );
    }

    /**
     * Sanitize a redirect URI for storage. `sanitize_url()` blanks any URL
     * whose scheme is not in wp_allowed_protocols(), which drops RFC 8252
     * §7.1 private-use app schemes. Allow the URI's own scheme when it is
     * otherwise acceptable.
     */
    public static function sanitize_redirect_uri( $uri ) {
        $uri = trim( (string) $uri );
        if ( '' === $uri || ! self::is_acceptable_redirect_uri( $uri ) ) {
            return '';
        }
        $scheme    = strtolower( (string) wp_parse_url( $uri, PHP_URL_SCHEME ) );
        $protocols = wp_allowed_protocols();
        if ( '' !== $scheme && ! in_array( $scheme, $protocols, true ) ) {
            $protocols[] = $scheme;
        }
        return esc_url_raw( $uri, $protocols );
    }
}
