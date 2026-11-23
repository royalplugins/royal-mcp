<?php
namespace Royal_MCP\Admin;


if (!defined('ABSPATH')) {
    exit;
}

class Settings_Page {

    /** True only while save_programmatically() is saving. */
    private static $programmatic_save = false;

    /** admin-post action (and nonce action) that rotates the API key. */
    const REGENERATE_ACTION = 'royal_mcp_regenerate_api_key';
    const DISABLE_KEY_ACTION = 'royal_mcp_disable_api_key';
    const CLEAR_LOG_ACTION   = 'royal_mcp_clear_activity_log';
    const EXPORT_LOG_ACTION  = 'royal_mcp_export_activity_log';
    /** admin-post action (and nonce action) that puts a cleared batch of Activity Log entries back. */
    const RESTORE_LOG_ACTION = 'royal_mcp_restore_activity_log';
    /** Seconds cleared Activity Log entries can be restored. */
    const LOG_CLEAR_UNDO_SECONDS = DAY_IN_SECONDS;
    /** Table (after the prefix) holding cleared Activity Log entries until they can no longer be restored. */
    const LOG_CLEARED_TABLE = 'royal_mcp_logs_cleared';
    /** Rows moved per query when clearing or restoring. */
    const LOG_MOVE_BATCH = 5000;
    /** Schema version of the cleared-entries table, and the option recording the one installed. */
    const LOG_CLEARED_SCHEMA = '1';
    const LOG_CLEARED_SCHEMA_OPTION = 'royal_mcp_logs_cleared_schema';
    /** admin-post action (and nonce action) that saves how long Activity Log entries are kept. */
    const LOG_RETENTION_ACTION = 'royal_mcp_set_log_retention';
    /** Most entries one export holds, newest first. */
    const LOG_EXPORT_MAX     = 50000;
    /** Longest exported cell (bytes), under a spreadsheet's own cell limit. */
    const LOG_EXPORT_CELL_MAX = 30000;
    /** Column names of the Activity Log export. */
    const LOG_EXPORT_COLUMNS = [ 'id', 'timestamp', 'source', 'action', 'status', 'tool', 'user_id', 'auth', 'client_id', 'access_level', 'route', 'arg_keys', 'error', 'change_id' ];

    /** Days an Activity Log entry is kept unless the owner picks another period. */
    const LOG_RETENTION_DAYS = 90;
    /** Option holding the owner's choice of retention period (days; 0 keeps entries until cleared). */
    const LOG_RETENTION_OPTION = 'royal_mcp_log_retention_days';
    /** Periods the Activity Log screen offers. */
    const LOG_RETENTION_CHOICES = [ 30, 90, 180, 365, 0 ];

    /**
     * Record an API key event (created or rotated) in the activity log, with where it came from.
     * Writes nothing when the log table does not exist yet.
     *
     * @param string $action  settings:api_key_created | settings:api_key_rotated.
     * @param string $source  activation | settings | wp-cli.
     * @param int    $user_id User the key is bound to.
     */
    public static function log_api_key_event( $action, $source, $user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'royal_mcp_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence check of our own table
        if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- insert into our own log table
        $wpdb->insert(
            $table,
            [
                'mcp_server'    => 'MCP Server',
                'action'        => (string) $action,
                'request_data'  => wp_json_encode( [ 'source' => (string) $source, 'bound_to' => (int) $user_id ] ),
                'response_data' => wp_json_encode( [ 'status' => 'success' ] ),
                'status'        => 'success',
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /** A fingerprint of the stored settings, carried by the form so a stale tab can be refused. */
    public static function settings_fingerprint( $settings = null ) {
        $settings = null === $settings ? get_option( 'royal_mcp_settings', [] ) : $settings;
        $settings = is_array( $settings ) ? $settings : [];
        // Taken over the form the settings have after the one-time static-secret migration, so
        // that migration (run by any OAuth request) does not make an open tab look stale.
        if ( isset( $settings['oauth_client_secret'] ) && '' !== (string) $settings['oauth_client_secret'] ) {
            $settings['oauth_client_secret_hash'] = hash( 'sha256', (string) $settings['oauth_client_secret'] );
            unset( $settings['oauth_client_secret'] );
        }
        return hash( 'sha256', (string) wp_json_encode( $settings ) );
    }

    /**
     * Save settings from our own code, keeping the stored values as they are.
     * The settings form keeps going through sanitize_settings().
     */
    public static function save_programmatically( array $settings ) {
        self::$programmatic_save = true;
        try {
            return update_option( 'royal_mcp_settings', $settings );
        } finally {
            self::$programmatic_save = false;
        }
    }

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_filter('admin_footer_text', [$this, 'admin_footer_text']);

        // AJAX handlers
        add_action('wp_ajax_royal_mcp_reset_oauth_state', [$this, 'ajax_reset_oauth_state']);
        add_action('wp_ajax_royal_mcp_clear_oauth_field', [$this, 'ajax_clear_oauth_field']);
        add_action('admin_post_' . self::REGENERATE_ACTION, [$this, 'handle_regenerate_api_key']);
        add_action('admin_post_' . self::DISABLE_KEY_ACTION, [$this, 'handle_disable_api_key']);
        add_action('admin_post_' . self::CLEAR_LOG_ACTION, [$this, 'handle_clear_activity_log']);
        add_action('admin_post_' . self::EXPORT_LOG_ACTION, [$this, 'handle_export_activity_log']);
        add_action('admin_post_' . self::RESTORE_LOG_ACTION, [$this, 'handle_restore_activity_log']);
        add_action('admin_post_' . self::LOG_RETENTION_ACTION, [$this, 'handle_set_log_retention']);
    }

    /**
     * Capability the Royal MCP settings, logs and sign-in reset need. Filter
     * royal_mcp_settings_capability (Royal MCP Pro asks for a network admin on multisite).
     */
    public static function admin_capability() {
        $cap = apply_filters( 'royal_mcp_settings_capability', 'manage_options' );
        return is_string( $cap ) && '' !== $cap ? $cap : 'manage_options';
    }

    public function add_menu_page() {
        add_menu_page(
            __('Royal MCP', 'royal-mcp'),
            __('Royal MCP', 'royal-mcp'),
            self::admin_capability(),
            'royal-mcp',
            [ Dashboard_Page::class, 'render' ],
            'dashicons-networking',
            80
        );

        add_submenu_page(
            'royal-mcp',
            __('Royal MCP', 'royal-mcp'),
            __('Dashboard', 'royal-mcp'),
            self::admin_capability(),
            'royal-mcp',
            [ Dashboard_Page::class, 'render' ]
        );

        add_submenu_page(
            'royal-mcp',
            __('Connections', 'royal-mcp'),
            __('Connections', 'royal-mcp'),
            self::admin_capability(),
            Menu_Layout::CONNECTIONS,
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'royal-mcp',
            __('Safety', 'royal-mcp'),
            __('Safety', 'royal-mcp'),
            self::admin_capability(),
            Menu_Layout::SAFETY,
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'royal-mcp',
            __('Activity Log', 'royal-mcp'),
            __('Activity Log', 'royal-mcp'),
            self::admin_capability(),
            'royal-mcp-logs',
            [$this, 'render_logs_page']
        );

    }

    public function register_settings() {
        // options.php saves of this form need the same capability as the page.
        add_filter( 'option_page_capability_royal_mcp_settings_group', [ __CLASS__, 'admin_capability' ] );
        register_setting('royal_mcp_settings_group', 'royal_mcp_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    /**
     * Log a WebMCP toggle change to the Activity Log. Called from
     * sanitize_settings() when the master toggle flips in either direction.
     * Auditability is load-bearing here — toggling this option materially
     * changes the site's auth posture (adds/removes the cookie-auth path
     * for browser agents).
     */
    private function log_webmcp_toggle_change( $prior, $next ) {
        $this->log_toggle_change( 'settings:webmcp_toggle', $prior, $next );
    }

    /**
     * Record a safety-setting change made outside the settings screen (WP-CLI),
     * with the values before and after.
     *
     * @param string $action Log action, e.g. settings:drafts_only_mode.
     * @param mixed  $prior  Value before.
     * @param mixed  $next   Value after.
     * @param string $via    Where the change came from.
     */
    public static function log_setting_change( $action, $prior, $next, $via = 'cli' ) {
        global $wpdb;
        $scalar = static function ( $v ) {
            return is_scalar( $v ) || null === $v ? $v : wp_json_encode( $v );
        };
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct insert to the logs table.
        $wpdb->insert(
            $wpdb->prefix . 'royal_mcp_logs',
            [
                'mcp_server'    => 'MCP Server',
                'action'        => (string) $action,
                'request_data'  => wp_json_encode( [ 'user_id' => get_current_user_id(), 'via' => (string) $via ] ),
                'response_data' => wp_json_encode( [ 'prior' => $scalar( $prior ), 'next' => $scalar( $next ) ] ),
                'status'        => 'success',
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Activity Log row for an on/off setting that changes what AI clients may do.
     */
    private function log_toggle_change( $action, $prior, $next ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct insert to the logs table.
        $wpdb->insert(
            $wpdb->prefix . 'royal_mcp_logs',
            [
                'mcp_server'    => 'MCP Server',
                'action'        => (string) $action,
                'request_data'  => wp_json_encode( [ 'user_id' => get_current_user_id() ] ),
                'response_data' => wp_json_encode( [
                    'prior' => (bool) $prior,
                    'next'  => (bool) $next,
                ] ),
                'status'        => 'success',
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Detect WebMCP bridge presence on the current site by issuing a HEAD
     * request to /.webmcp/bridge.js. Returns one of:
     *   'detected'     — HEAD returns 200 (Cloudflare WebMCP is toggled on for this zone)
     *   'not_detected' — HEAD returns 404 (Cloudflare not fronting this site OR WebMCP not enabled)
     *   'unknown'      — HEAD returns any other status / connection error / etc.
     *
     * Cached in a short-lived transient so the settings page render doesn't
     * hit the loopback on every load. Manual re-probe via the settings form
     * (a nonce-gated admin-post handler) invalidates the cache.
     *
     * Kept in the settings-page class instead of Well_Known_Notice because
     * this isn't a "notify the admin" surface — it's a live status pill on
     * a form field, distinct concern.
     */
    public static function detect_webmcp_bridge() {
        $cached = get_transient( 'royal_mcp_webmcp_bridge_status' );
        if ( false !== $cached ) {
            return $cached;
        }

        $url = home_url( '/.webmcp/bridge.js' );
        $response = wp_remote_head( $url, [
            'timeout'     => 3,
            'redirection' => 0,
            'sslverify'   => true,
            'user-agent'  => 'Royal MCP WebMCP Bridge Probe',
        ] );

        $status = 'unknown';
        if ( ! is_wp_error( $response ) ) {
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( 200 === $code ) {
                $status = 'detected';
            } elseif ( 404 === $code ) {
                $status = 'not_detected';
            }
        }

        set_transient( 'royal_mcp_webmcp_bridge_status', $status, 30 * MINUTE_IN_SECONDS );
        return $status;
    }

    public function sanitize_settings($input) {
        $sanitized = [];
        $settings = get_option('royal_mcp_settings', []);

        // Our own code (Reset OAuth state, Clear field) saves the stored
        // settings array through save_programmatically(); this form sanitizer
        // would read that as an empty form — wiping the admin option
        // allowlist, turning Abilities off and keeping the credentials it
        // meant to clear. Every other save is treated as a form post.
        if ( self::$programmatic_save ) {
            return is_array( $input ) ? $input : $settings;
        }

        // When the option did not exist yet, WordPress runs this sanitizer a second time on its own
        // first-pass result; that result is already clean.
        if ( null !== self::$form_result && $input === self::$form_result ) {
            return $input;
        }
        // Only the settings form changes Enabled: it posts a marker and a hidden "0" before the
        // checkbox, so "unticked" and "not part of this save" are told apart.
        $from_form = is_array( $input ) && isset( $input['_form'] ) && 'settings' === $input['_form'];
        // 4: a form post must carry the fingerprint of the settings it was opened with.
        if ( $from_form && ( ! isset( $input['_settings_hash'] ) || ! is_string( $input['_settings_hash'] )
            || ! hash_equals( self::settings_fingerprint( $settings ), $input['_settings_hash'] ) ) ) {
            add_settings_error( 'royal_mcp_settings', 'royal_mcp_settings_stale', __( 'Settings were changed elsewhere since this page was opened (another tab, another admin, WP-CLI or Royally). Nothing was saved: reload the page and save again.', 'royal-mcp' ), 'error' );
            return $settings;
        }
        if ( $from_form ) {
            $posted = $input['enabled'] ?? '0';
            $sanitized['enabled'] = is_scalar( $posted ) && in_array( strtolower( trim( (string) $posted ) ), [ '1', 'on', 'true', 'yes' ], true );
        } else {
            $sanitized['enabled'] = ! empty( $settings['enabled'] );
        }
        $sanitized['allow_option_writes'] = isset($input['allow_option_writes']) ? (bool) $input['allow_option_writes'] : false;
        $sanitized['allow_theme_writes'] = isset($input['allow_theme_writes']) ? (bool) $input['allow_theme_writes'] : false;
        $sanitized['require_client_approval'] = isset($input['require_client_approval']) ? (bool) $input['require_client_approval'] : false;
        $prior_groups = isset( $settings[ \Royal_MCP\MCP\Support\Tool_Groups::SETTING ] ) && is_array( $settings[ \Royal_MCP\MCP\Support\Tool_Groups::SETTING ] ) ? $settings[ \Royal_MCP\MCP\Support\Tool_Groups::SETTING ] : null;
        $sanitized[ \Royal_MCP\MCP\Support\Tool_Groups::SETTING ] = \Royal_MCP\MCP\Support\Tool_Groups::sanitize( is_array( $input ) ? ( $input[ \Royal_MCP\MCP\Support\Tool_Groups::SETTING ] ?? null ) : null, $prior_groups, $from_form );
        // Royal MCP Pro keeps its own group switches in the same option; a save here leaves them as stored.
        if ( isset( $settings['pro_tool_groups'] ) && is_array( $settings['pro_tool_groups'] ) ) {
            $sanitized['pro_tool_groups'] = $settings['pro_tool_groups'];
        }
        foreach ( $sanitized[ \Royal_MCP\MCP\Support\Tool_Groups::SETTING ] as $royal_mcp_group => $royal_mcp_on ) {
            $royal_mcp_was = ! empty( $prior_groups[ $royal_mcp_group ] );
            if ( null !== $prior_groups && $royal_mcp_was !== $royal_mcp_on ) {
                $this->log_toggle_change( 'settings:tool_group:' . $royal_mcp_group, $royal_mcp_was, $royal_mcp_on );
            }
        }

        // Read-only mode — every tool that can change the site is refused,
        // for every client and every user. A change is logged for the audit
        // trail, as the WebMCP toggle is.
        $prior_read_only = ! empty( $settings['read_only_mode'] );
        $sanitized['read_only_mode'] = isset( $input['read_only_mode'] ) ? (bool) $input['read_only_mode'] : false;
        if ( $prior_read_only !== $sanitized['read_only_mode'] ) {
            $this->log_toggle_change( 'settings:read_only_mode', $prior_read_only, $sanitized['read_only_mode'] );
        }

        // Drafts-only mode: AI clients may create and edit drafts, nothing else.
        $prior_drafts_only = ! empty( $settings['drafts_only_mode'] );
        $sanitized['drafts_only_mode'] = isset( $input['drafts_only_mode'] ) ? (bool) $input['drafts_only_mode'] : false;
        if ( $prior_drafts_only !== $sanitized['drafts_only_mode'] ) {
            $this->log_toggle_change( 'settings:drafts_only_mode', $prior_drafts_only, $sanitized['drafts_only_mode'] );
        }

        // Access levels for the API key and for calls through other MCP
        // servers and browser agents (per-client levels live on Connected Clients).
        foreach ( [ 'api_key_access', 'other_routes_access' ] as $access_key ) {
            $prior_access = \Royal_MCP\MCP\Support\Access_Levels::normalize( $settings[ $access_key ] ?? '' );
            $prior_access = '' !== $prior_access ? $prior_access : 'full';
            $next_access  = \Royal_MCP\MCP\Support\Access_Levels::normalize( isset( $input[ $access_key ] ) ? sanitize_text_field( wp_unslash( (string) $input[ $access_key ] ) ) : '' );
            $sanitized[ $access_key ] = '' !== $next_access ? $next_access : 'full';
            if ( $prior_access !== $sanitized[ $access_key ] ) {
                self::log_setting_change( 'settings:' . $access_key, $prior_access, $sanitized[ $access_key ], 'settings' );
            }
        }

        // Who may use tools outside the Royal MCP endpoint, and who may connect AI clients.
        foreach ( [ \Royal_MCP\MCP\Support\Route_Roles::OUTSIDE_KEY, \Royal_MCP\MCP\Support\Route_Roles::CONNECT_KEY ] as $role_key ) {
            $prior_role = \Royal_MCP\MCP\Support\Route_Roles::normalize( $role_key, $settings[ $role_key ] ?? '' );
            $sanitized[ $role_key ] = \Royal_MCP\MCP\Support\Route_Roles::normalize( $role_key, isset( $input[ $role_key ] ) ? wp_unslash( (string) $input[ $role_key ] ) : '' );
            if ( $prior_role !== $sanitized[ $role_key ] ) {
                self::log_setting_change( 'settings:' . $role_key, $prior_role, $sanitized[ $role_key ], 'settings' );
            }
        }

        // Delete all data when the plugin is deleted — off by default, so a
        // delete-and-reinstall keeps settings, logs and undo history.
        $prior_delete_data = ! empty( $settings['delete_data_on_uninstall'] );
        $sanitized['delete_data_on_uninstall'] = isset( $input['delete_data_on_uninstall'] ) ? (bool) $input['delete_data_on_uninstall'] : false;
        if ( $prior_delete_data !== $sanitized['delete_data_on_uninstall'] ) {
            $this->log_toggle_change( 'settings:delete_data_on_uninstall', $prior_delete_data, $sanitized['delete_data_on_uninstall'] );
        }

        // WebMCP browser-agent bridge — opt-in cookie-auth path for the Cloudflare
        // WebMCP bridge. Off by default so the cookie-auth surface is never a
        // hidden auth path. Toggle change gets an Activity Log row (below) for
        // auditability since it materially changes the site's auth posture.
        $prior_webmcp = ! empty( $settings['webmcp_enabled'] );
        $sanitized['webmcp_enabled'] = isset( $input['webmcp_enabled'] ) ? (bool) $input['webmcp_enabled'] : false;
        if ( $prior_webmcp !== $sanitized['webmcp_enabled'] ) {
            $this->log_webmcp_toggle_change( $prior_webmcp, $sanitized['webmcp_enabled'] );
            // Toggle flips the auth.methods advertised in the server card
            // and agent-skills index — purge caches so customers don't see
            // stale "session-cookie" listed after turning WebMCP off, or
            // miss it right after turning WebMCP on.
            delete_transient( \Royal_MCP\Discovery\Server_Card::CACHE_KEY );
            delete_transient( \Royal_MCP\Discovery\Agent_Skills_Index::CACHE_KEY );
        }

        // Tools switched off for browser agents. The form posts a marker and the
        // ticked tools; everything not ticked is stored as off, so a tool added in
        // a later release starts on. Without the marker the stored list is kept.
        $prior_webmcp_off = array_keys( \Royal_MCP\MCP\Server::webmcp_disabled_tools() );
        if ( isset( $input['webmcp_tools_form'] ) ) {
            $webmcp_on = isset( $input['webmcp_allowed_tools'] ) && is_array( $input['webmcp_allowed_tools'] )
                ? array_map( 'sanitize_key', array_filter( wp_unslash( $input['webmcp_allowed_tools'] ), 'is_string' ) )
                : [];
            $next_webmcp_off = array_values( array_diff( \Royal_MCP\MCP\Server::webmcp_switchable_tools(), $webmcp_on ) );
        } else {
            $next_webmcp_off = $prior_webmcp_off;
        }
        $sanitized['webmcp_disabled_tools'] = $next_webmcp_off;
        $webmcp_off_sorted = $next_webmcp_off;
        sort( $webmcp_off_sorted );
        $prior_off_sorted = $prior_webmcp_off;
        sort( $prior_off_sorted );
        if ( $prior_off_sorted !== $webmcp_off_sorted ) {
            self::log_setting_change( 'settings:webmcp_disabled_tools', implode( ',', $prior_off_sorted ), implode( ',', $webmcp_off_sorted ), 'settings' );
        }

        // Abilities API registration — standalone option key, NOT nested
        // inside royal_mcp_settings, so the bootstrap gate at royal-mcp.php
        // can read it without depending on the settings array being
        // well-formed. Filter and CLI consumers may also set this option
        // directly.
        // Store as string '1' / '0' — booleans serialize false to empty string,
        // which is indistinguishable from "option missing" and defeats the
        // default-on fallback at read sites.
        // Only the settings form changes it: a programmatic save (WP-CLI, another tool) that does not
        // carry the field leaves registration as it is.
        if ( $from_form ) {
            update_option(
                'royal_mcp_abilities_registration_enabled',
                isset( $input['abilities_registration_enabled'] ) ? '1' : '0'
            );
        }

        // Recording PHP fatal errors for wp_get_php_errors: standalone option, on by default,
        // changed only by the settings form itself.
        if ( $from_form ) {
            update_option( \Royal_MCP\Integrations\Php_Errors::SETTING, isset( $input['record_php_errors'] ) ? '1' : '0', false );
        }

        // Access token TTL — whitelist against the 4 UI choices; anything else falls back to the default.
        $posted_ttl = isset($input['access_token_ttl_seconds']) ? (int) $input['access_token_ttl_seconds'] : 0;
        $sanitized['access_token_ttl_seconds'] = in_array($posted_ttl, \Royal_MCP\OAuth\Token_Store::ACCESS_TOKEN_TTL_CHOICES, true)
            ? $posted_ttl
            : \Royal_MCP\OAuth\Token_Store::ACCESS_TOKEN_TTL;

        // Third-party option allowlist — parse the textarea (one option name per line).
        // Every key runs through sanitize_key() so a hostile / malformed input can't smuggle
        // an option name shape the wp_update_option gate wouldn't recognize. The denylist
        // in MCP\Server.php runs AFTER the allowlist check, so entries here CANNOT escape
        // the sensitive-option denylist regardless of what admins add.
        $keys = [];
        if (isset($input['writable_options_admin']) && is_string($input['writable_options_admin'])) {
            $lines = preg_split('/\r?\n/', (string) $input['writable_options_admin']);
            foreach ($lines as $line) {
                $clean = sanitize_key(trim($line));
                if ($clean !== '') { $keys[] = $clean; }
            }
        }
        $sanitized['writable_options_admin'] = array_values(array_unique($keys));

        // Options returned as stored by wp_get_option: kept in their own option, changed only
        // by this form and only by an administrator. A name counts only while it is also on the
        // read allowlist and is not protected; that is checked on every read.
        if ( $from_form && isset( $input['unmasked_options'] ) && is_string( $input['unmasked_options'] ) && current_user_can( 'manage_options' ) ) {
            $unmasked = [];
            foreach ( preg_split( '/\r?\n/', (string) $input['unmasked_options'] ) as $line ) {
                $clean = sanitize_key( trim( $line ) );
                if ( '' !== $clean ) {
                    $unmasked[] = $clean;
                }
            }
            $unmasked       = array_values( array_unique( $unmasked ) );
            $unmasked_prior = get_option( \Royal_MCP\MCP\Server::UNMASKED_OPTIONS_OPTION, [] );
            $unmasked_prior = is_array( $unmasked_prior ) ? array_values( $unmasked_prior ) : [];
            if ( $unmasked !== $unmasked_prior ) {
                update_option( \Royal_MCP\MCP\Server::UNMASKED_OPTIONS_OPTION, $unmasked, false );
                self::log_setting_change( 'settings:unmasked_options', $unmasked_prior, $unmasked, 'settings' );
            }
        }

        // Protected meta keys: exact names only, stored as typed. Blocked entries stay
        // on the list so the screen can name them, and never open at write time.
        $meta_list_key = \Royal_MCP\MCP\Support\Protected_Meta::SETTING;
        if (isset($input[$meta_list_key]) && is_string($input[$meta_list_key])) {
            $sanitized[$meta_list_key] = \Royal_MCP\MCP\Support\Protected_Meta::parse_list($input[$meta_list_key]);
        } else {
            $sanitized[$meta_list_key] = is_array($settings[$meta_list_key] ?? null) ? $settings[$meta_list_key] : [];
        }

        // Sanitize API key.
        // Order matters: the readonly `api_key` field in the settings form posts
        // whatever value is currently rendered on every submit, so the
        // `regenerate_api_key` signal has to be checked FIRST. With the order
        // reversed, the field POST silently overrides the regenerate signal and
        // clicking Regenerate becomes a no-op.
        //
        // Storage model: only the SHA-256 hash of the key sits at rest. The raw
        // plaintext is handed to the admin ONCE via a short-lived reveal
        // transient after regeneration — after that the field renders masked
        // and the admin must regenerate again if they lose the copy.
        // A settings save never changes the key: it is rotated only by the
        // Regenerate action (handle_regenerate_api_key) and by WP-CLI. A posted
        // regenerate_api_key field is ignored. If a legacy install still has a
        // plaintext key, keep it in place so existing MCP clients keep working;
        // maybe_upgrade_db handles the one-time migration to the hashed form.
        $sanitized['api_key_hash'] = $settings['api_key_hash'] ?? '';
        $sanitized['api_key']      = $settings['api_key']      ?? '';

        // Bind the api_key to a specific user_id so the auth path attributes
        // tool calls to a real human rather than "first administrator on the
        // site". Rebound on every regeneration; back-filled for existing
        // installs where a key exists but the bind is missing.
        if ( ( ! empty( $sanitized['api_key_hash'] ) || ! empty( $sanitized['api_key'] ) )
                   && empty( $settings['api_key_user_id'] ) ) {
            $sanitized['api_key_user_id'] = (int) get_current_user_id();
        } else {
            $sanitized['api_key_user_id'] = (int) ( $settings['api_key_user_id'] ?? 0 );
        }

        // Sanitize OAuth settings
        if (isset($input['oauth_client_id']) && !empty($input['oauth_client_id'])) {
            $sanitized['oauth_client_id'] = sanitize_text_field($input['oauth_client_id']);
        } else {
            $sanitized['oauth_client_id'] = $settings['oauth_client_id'] ?? '';
        }

        // The static client secret is kept as a hash only; the plain value is never stored or shown again.
        $secret_hash = (string) ( $settings['oauth_client_secret_hash'] ?? '' );
        if ( '' === $secret_hash && ! empty( $settings['oauth_client_secret'] ) ) {
            $secret_hash = hash( 'sha256', (string) $settings['oauth_client_secret'] );
        }
        if ( isset( $input['oauth_client_secret'] ) && is_string( $input['oauth_client_secret'] ) && '' !== trim( $input['oauth_client_secret'] ) ) {
            $secret_hash = hash( 'sha256', sanitize_text_field( $input['oauth_client_secret'] ) );
        }
        if ( '' !== $secret_hash ) {
            $sanitized['oauth_client_secret_hash'] = $secret_hash;
        }

        // Redirect URIs the static client may send people back to (https or loopback, one per line).
        if ( isset( $input['oauth_static_redirect_uris'] ) && is_string( $input['oauth_static_redirect_uris'] ) ) {
            $uris = [];
            foreach ( preg_split( '/[\r\n]+/', substr( $input['oauth_static_redirect_uris'], 0, 4000 ) ) as $uri ) {
                $uri = trim( (string) $uri );
                if ( '' !== $uri && \Royal_MCP\OAuth\Token_Store::is_acceptable_redirect_uri( $uri ) ) {
                    $uris[] = \Royal_MCP\OAuth\Token_Store::sanitize_redirect_uri( $uri );
                }
            }
            $sanitized['oauth_static_redirect_uris'] = implode( "\n", array_slice( array_values( array_unique( $uris ) ), 0, 10 ) );
        } else {
            $sanitized['oauth_static_redirect_uris'] = (string) ( $settings['oauth_static_redirect_uris'] ?? '' );
        }

        if ( $from_form ) {
            self::$form_result = $sanitized;
        }
        return $sanitized;
    }

    /** The last settings-form result, recognised when WordPress sanitizes it a second time. */
    private static $form_result = null;

    /**
     * Rotate the API key from the settings screen's Regenerate button: its own
     * form and nonce, so no settings save can change the key. The new key is
     * shown once and the rotation is logged.
     */
    public function handle_regenerate_api_key() {
        if ( ! current_user_can( self::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from admin_capability(); per-site option royal_mcp_settings only
            wp_die( esc_html__( 'You do not have permission.', 'royal-mcp' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( self::REGENERATE_ACTION );

        $settings  = get_option( 'royal_mcp_settings', [] );
        $settings  = is_array( $settings ) ? $settings : [];
        $previous  = (int) ( $settings['api_key_user_id'] ?? 0 );
        $user_id   = (int) get_current_user_id();
        $plaintext = bin2hex( random_bytes( 16 ) );
        $settings['api_key_hash']    = hash( 'sha256', $plaintext );
        $settings['api_key']         = '';
        $settings['api_key_user_id'] = $user_id;
        self::save_programmatically( $settings );
        set_transient( // audit:transient-ttl-only -- one-time key reveal, deleted when the settings page shows it
            'royal_mcp_reveal_api_key_' . $user_id,
            $plaintext,
            15 * MINUTE_IN_SECONDS
        );

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct insert to the logs table.
        $wpdb->insert(
            $wpdb->prefix . 'royal_mcp_logs',
            [
                'mcp_server'    => 'MCP Server',
                'action'        => 'settings:api_key_rotated',
                'request_data'  => wp_json_encode( [ 'source' => 'settings', 'bound_to' => $user_id, 'previously_bound_to' => $previous ] ),
                'response_data' => wp_json_encode( [ 'status' => 'success' ] ),
                'status'        => 'success',
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        wp_safe_redirect( add_query_arg( [ 'page' => Menu_Layout::CONNECTIONS, 'settings-updated' => 'true' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Turn the API key off: no key is stored, so every API-key request is refused
     * until Regenerate makes a new one. OAuth sign-ins are not affected.
     */
    public function handle_disable_api_key() {
        if ( ! current_user_can( self::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from admin_capability(); per-site option royal_mcp_settings only
            wp_die( esc_html__( 'You do not have permission.', 'royal-mcp' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( self::DISABLE_KEY_ACTION );

        $settings = get_option( 'royal_mcp_settings', [] );
        $settings = is_array( $settings ) ? $settings : [];
        $previous = (int) ( $settings['api_key_user_id'] ?? 0 );
        $settings['api_key_hash']    = '';
        $settings['api_key']         = '';
        $settings['api_key_user_id'] = 0;
        self::save_programmatically( $settings );
        delete_transient( 'royal_mcp_reveal_api_key_' . (int) get_current_user_id() );

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct insert to the logs table.
        $wpdb->insert(
            $wpdb->prefix . 'royal_mcp_logs',
            [
                'mcp_server'    => 'MCP Server',
                'action'        => 'settings:api_key_disabled',
                'request_data'  => wp_json_encode( [ 'source' => 'settings', 'by' => (int) get_current_user_id(), 'previously_bound_to' => $previous ] ),
                'response_data' => wp_json_encode( [ 'status' => 'success' ] ),
                'status'        => 'success',
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        wp_safe_redirect( add_query_arg( [ 'page' => Menu_Layout::CONNECTIONS, 'settings-updated' => 'true' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'royal-mcp') === false) {
            return;
        }

        // Use filemtime() appended to the version string so intra-version patches
        // bust CDN / immutable browser caches. Plain ROYAL_MCP_VERSION alone is
        // not enough on Cloudflare-fronted sites where plugin JS/CSS gets cached
        // with `Cache-Control: immutable, max-age=2592000` — the same ?ver= URL
        // serves the stale asset for 30 days regardless of hard-refresh.
        $css_path = ROYAL_MCP_PLUGIN_DIR . 'assets/css/admin.css';
        $js_path  = ROYAL_MCP_PLUGIN_DIR . 'assets/js/admin.js';
        $css_ver  = ROYAL_MCP_VERSION . '.' . (file_exists($css_path) ? filemtime($css_path) : '0');
        $js_ver   = ROYAL_MCP_VERSION . '.' . (file_exists($js_path)  ? filemtime($js_path)  : '0');

        wp_enqueue_style(
            'royal-mcp-admin',
            ROYAL_MCP_PLUGIN_URL . 'assets/css/admin.css',
            [],
            $css_ver
        );

        wp_enqueue_script(
            'royal-mcp-admin',
            ROYAL_MCP_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            $js_ver,
            true
        );

        wp_localize_script('royal-mcp-admin', 'royalMcp', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('royal_mcp_nonce'),
            'restUrl' => rest_url('royal-mcp/v1/'),
            'strings' => [
                'confirmRegenerate' => esc_html__('Are you sure? This will invalidate the current API key.', 'royal-mcp'),
                'keyCopied'         => esc_html__('API key copied to clipboard!', 'royal-mcp'),
                'copied'            => esc_html__('Copied to clipboard!', 'royal-mcp'),
                'networkError'      => esc_html__('Network error while clearing field.', 'royal-mcp'),
                'resetting'         => esc_html__('Resetting... (can take several minutes)', 'royal-mcp'),
                'resetFailed'       => esc_html__('Reset request failed (network or server error)', 'royal-mcp'),
            ],
        ]);
    }

    public function render_settings_page() {
        if (!current_user_can( self::admin_capability() )) { // audit:multisite-manage-options-safe -- capability from admin_capability(); reads per-site royal_mcp_settings only (per-site option, no network-scope state)
            return;
        }
        // Sets the tool-group switches on first view, before the form takes its fingerprint of the settings.
        \Royal_MCP\MCP\Support\Tool_Groups::states();

        $settings = get_option('royal_mcp_settings', [
            'enabled'         => false,
            'api_key'         => '',
            'api_key_hash'    => '',
            'api_key_user_id' => 0,
        ]);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads which screen is open, changes nothing
        $royal_mcp_view = isset( $_GET['page'] ) && Menu_Layout::SAFETY === sanitize_key( wp_unslash( $_GET['page'] ) ) ? 'safety' : 'connections';

        include ROYAL_MCP_PLUGIN_DIR . 'templates/admin/settings.php';
    }

    /**
     * Days an Activity Log entry is kept; 0 keeps entries until they are cleared by hand.
     *
     * @return int
     */
    public static function log_retention_days() {
        /**
         * Filter how many days Activity Log entries are kept. Return 0 to keep them all.
         * Receives the period chosen on the Activity Log screen (90 days until one is chosen).
         *
         * @param int $days Days.
         */
        return max( 0, (int) apply_filters( 'royal_mcp_log_retention_days', self::log_retention_choice() ) );
    }

    /** The period chosen on the Activity Log screen, before any filter. */
    public static function log_retention_choice() {
        $stored = get_option( self::LOG_RETENTION_OPTION, null );
        if ( null !== $stored && is_numeric( $stored ) && in_array( (int) $stored, self::LOG_RETENTION_CHOICES, true ) ) {
            return (int) $stored;
        }
        return self::LOG_RETENTION_DAYS;
    }

    /**
     * Whether the current user may not change the retention period: on a network, keeping
     * entries until they are cleared is a network administrator's choice, and only a network
     * administrator can shorten it again.
     */
    public static function log_retention_locked() {
        return is_multisite() && 0 === self::log_retention_choice() && ! current_user_can( 'manage_network_options' );
    }

    /** The Activity Log screen's retention form: save the chosen period and record who changed it. */
    public function handle_set_log_retention() {
        if ( ! current_user_can( self::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from admin_capability(); per-site retention of the per-site log table
            wp_die( esc_html__( 'You do not have permission.', 'royal-mcp' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( self::LOG_RETENTION_ACTION );
        // Exactly one of the listed numbers, as the form sends it; nothing is cleaned up into a choice.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against a fixed list of digit strings below
        $raw  = isset( $_POST['royal_mcp_log_retention'] ) && is_string( $_POST['royal_mcp_log_retention'] ) ? wp_unslash( $_POST['royal_mcp_log_retention'] ) : '';
        $days = in_array( $raw, array_map( 'strval', self::LOG_RETENTION_CHOICES ), true ) ? (int) $raw : -1;
        $done = 'invalid';
        // On a network, keeping a site's log for ever is a network administrator's call.
        if ( 0 === $days && is_multisite() && ! current_user_can( 'manage_network_options' ) ) {
            $done = 'network';
            $days = -1;
        } elseif ( $days >= 0 && $days !== self::log_retention_choice() && self::log_retention_locked() ) {
            $done = 'network_kept';
            $days = -1;
        }
        if ( in_array( $days, self::LOG_RETENTION_CHOICES, true ) ) {
            $before           = self::log_retention_choice();
            $before_effective = self::log_retention_days();
            update_option( self::LOG_RETENTION_OPTION, $days, false );
            if ( $days !== self::log_retention_choice() ) {
                $done = 'failed';
            } else {
                $done = 'saved';
                if ( $before !== $days && self::log_table_exists() ) {
                    self::log_setting_change(
                        'settings:activity_log_retention_changed',
                        [ 'days' => $before, 'in_force' => $before_effective ],
                        [ 'days' => $days, 'in_force' => self::log_retention_days() ],
                        'settings'
                    );
                }
            }
        }
        wp_safe_redirect( add_query_arg( [ 'page' => 'royal-mcp-logs', 'royal_mcp_retention' => $done ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /** Whether this site has the Activity Log table. */
    private static function log_table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'royal_mcp_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence check of our own table
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }

    /**
     * Most Activity Log entries kept. Sign-in request entries go first; other
     * entries only when entries are not kept until they are cleared.
     */
    const LOG_MAX_ROWS = 100000;

    /**
     * Most Activity Log entries kept. Return 0 from the filter for no ceiling.
     *
     * @return int
     */
    public static function log_max_rows() {
        /**
         * Filter how many Activity Log entries are kept at most (oldest removed first). 0 = no ceiling.
         *
         * @param int $rows Entries.
         */
        return max( 0, (int) apply_filters( 'royal_mcp_log_max_rows', self::LOG_MAX_ROWS ) );
    }

    /**
     * Daily: delete Activity Log entries older than the retention period, then,
     * beyond the row ceiling, the oldest sign-in request entries and (unless
     * entries are kept until cleared) the oldest of the rest, in batches so a
     * large table never holds one long delete. Also sweeps idle rate counters.
     *
     * @return int Rows deleted.
     */
    public static function prune_activity_log() {
        global $wpdb;
        \Royal_MCP\OAuth\Rate_Limit::sweep();
        self::purge_cleared_logs();
        if ( ! self::log_table_exists() ) {
            return 0;
        }
        $days    = self::log_retention_days();
        $table   = esc_sql( $wpdb->prefix . 'royal_mcp_logs' );
        $deleted = 0;
        for ( $batch = 0; $days > 0 && $batch < 20; $batch++ ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, name escaped above
            $n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE timestamp < DATE_SUB( NOW(), INTERVAL %d DAY ) LIMIT 5000", $days ) );
            $deleted += $n;
            if ( $n < 5000 ) {
                break;
            }
        }
        $max = self::log_max_rows();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, name escaped above
        $excess = $max > 0 ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) - $max : 0;
        // Over the ceiling, sign-in request entries (written by the OAuth endpoints,
        // not by an administrator) go first, oldest first.
        $admin_oauth = [ 'oauth:revoke_connection', 'oauth:set_client_access', 'oauth:revoke_all_sessions', 'oauth:reset' ];
        for ( $batch = 0; $excess > 0 && $batch < 40; $batch++ ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, name escaped above
            $n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE mcp_server = 'OAuth Server' AND action NOT IN ( %s, %s, %s, %s ) ORDER BY id LIMIT %d", $admin_oauth[0], $admin_oauth[1], $admin_oauth[2], $admin_oauth[3], min( 5000, $excess ) ) );
            $deleted += $n;
            $excess  -= $n;
            if ( $n < 1 ) {
                break;
            }
        }
        // Tool calls and settings changes are only removed for the ceiling when
        // entries are not kept until they are cleared.
        if ( $excess > 0 && $days > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, name escaped above
            $cut = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` ORDER BY id DESC LIMIT 1 OFFSET %d", $max ) );
            for ( $batch = 0; $cut > 0 && $batch < 40; $batch++ ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, name escaped above
                $n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE id <= %d ORDER BY id LIMIT 5000", $cut ) );
                $deleted += $n;
                if ( $n < 5000 ) {
                    break;
                }
            }
        }
        return $deleted;
    }

    /** A value as a CSV cell: one line, and never read as a formula by a spreadsheet. */
    private static function csv_cell( $value ) {
        $value = is_scalar( $value ) ? (string) $value : '';
        // One line of printable text: control and line-separator characters become a space.
        $value = (string) preg_replace( '/[\x00-\x1F\x7F]+|\xE2\x80[\xA8\xA9]/', ' ', $value );
        $value = trim( $value );
        if ( strlen( $value ) > self::LOG_EXPORT_CELL_MAX ) {
            $value = substr( $value, 0, self::LOG_EXPORT_CELL_MAX ) . '...';
        }
        // Formula characters are looked for behind spaces and invisible characters too, in their full-width forms as well.
        $lead = (string) preg_replace( '/^(?:\s|\xC2\xA0|\xE2\x80[\x8B-\x8F]|\xE3\x80\x80|\xEF\xBB\xBF)+/', '', $value );
        return preg_match( '/^(?:[=+\-@]|\xEF\xBC\x9D|\xEF\xBC\x8B|\xEF\xBC\x8D|\xEF\xBC\xA0)/', $lead ) ? "'" . $value : $value;
    }

    /**
     * Walk Activity Log entries newest first, in batches by id, and hand each one to $emit
     * as a list of values in LOG_EXPORT_COLUMNS order.
     *
     * @param callable $emit     Receives one entry's values.
     * @param int      $max_rows Most entries.
     * @param int      $upto     When above 0, only entries with an id up to this one.
     * @return int Entries handed over.
     */
    private static function each_export_row( callable $emit, $max_rows, $upto = 0 ) {
        global $wpdb;
        $max_rows = max( 0, (int) $max_rows );
        if ( ! self::log_table_exists() ) {
            return 0;
        }
        $table   = esc_sql( $wpdb->prefix . 'royal_mcp_logs' );
        $written = 0;
        $before  = (int) $upto > 0 ? (int) $upto + 1 : PHP_INT_MAX;
        while ( $written < $max_rows ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, name escaped above; read in batches by id
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, timestamp, mcp_server, action, request_data, response_data, status FROM `{$table}` WHERE id < %d ORDER BY id DESC LIMIT %d", $before, min( 1000, $max_rows - $written ) ), ARRAY_A );
            if ( ! $rows ) {
                break;
            }
            foreach ( $rows as $row ) {
                $req    = json_decode( (string) $row['request_data'], true );
                $res    = json_decode( (string) $row['response_data'], true );
                $req    = is_array( $req ) ? $req : [];
                $res    = is_array( $res ) ? $res : [];
                $action = (string) $row['action'];
                $tool   = isset( $req['tool'] ) && is_scalar( $req['tool'] ) ? (string) $req['tool'] : ( 0 === strpos( $action, 'tools/call:' ) ? substr( $action, 11 ) : '' );
                $keys   = isset( $req['arg_keys'] ) && is_array( $req['arg_keys'] ) ? implode( ' ', array_filter( $req['arg_keys'], 'is_scalar' ) ) : '';
                $emit( [
                    (int) $row['id'],
                    $row['timestamp'],
                    $row['mcp_server'],
                    $action,
                    $row['status'],
                    $tool,
                    $req['user_id'] ?? ( $req['by'] ?? '' ),
                    $req['auth'] ?? '',
                    $req['client_id'] ?? '',
                    $req['access_level'] ?? '',
                    $req['route'] ?? '',
                    $keys,
                    $res['error'] ?? '',
                    $res['change_id'] ?? '',
                ] );
                $written++;
                $before = (int) $row['id'];
            }
        }
        return $written;
    }

    /**
     * Write the Activity Log as CSV to an open stream, newest first. An entry holds the tool
     * name, the names of its arguments and any error text, never argument values.
     *
     * @param resource $stream   Open, writable stream.
     * @param int      $max_rows Most entries to write.
     * @param int      $upto     When above 0, only entries with an id up to this one.
     * @param bool     $trailer  End with a line giving the number of entries written.
     * @return int Entries written.
     */
    public static function write_activity_log_csv( $stream, $max_rows = self::LOG_EXPORT_MAX, $upto = 0, $trailer = false ) {
        // A byte-order mark so a spreadsheet reads the file as UTF-8. No escape character: a quote inside a
        // cell is always doubled, which is the only form spreadsheets read, so a cell cannot end early.
        fwrite( $stream, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- the response body or a memory stream, not a file
        fputcsv( $stream, self::LOG_EXPORT_COLUMNS, ',', '"', '' );
        $n = self::each_export_row( static function ( $values ) use ( $stream ) {
            fputcsv( $stream, array_map( [ __CLASS__, 'csv_cell' ], $values ), ',', '"', '' );
        }, $max_rows, $upto );
        if ( $trailer ) {
            // Last line: how many entries the file holds, so a cut-off download can be recognised.
            fputcsv( $stream, array_pad( [ '#END', 'rows=' . (int) $n, 'complete' ], count( self::LOG_EXPORT_COLUMNS ), '' ), ',', '"', '' );
        }
        return $n;
    }

    /**
     * Write the Activity Log as JSON to an open stream, newest first, one entry at a time:
     * {"entries":[{...}],"rows":N,"complete":true}. Entries have the LOG_EXPORT_COLUMNS keys
     * and the same content as the CSV export; rows and complete come last, so a cut-off
     * download is not valid JSON and lacks them.
     *
     * @param resource $stream   Open, writable stream.
     * @param int      $max_rows Most entries to write.
     * @param int      $upto     When above 0, only entries with an id up to this one.
     * @return int Entries written.
     */
    public static function write_activity_log_json( $stream, $max_rows = self::LOG_EXPORT_MAX, $upto = 0 ) {
        $first   = true;
        $written = 0;
        fwrite( $stream, '{"entries":[' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- the response body or a memory stream, not a file
        self::each_export_row( static function ( $values ) use ( $stream, &$first, &$written ) {
            foreach ( $values as $i => $v ) {
                if ( $i > 0 ) {
                    $v            = is_scalar( $v ) ? (string) $v : '';
                    $values[ $i ] = strlen( $v ) > self::LOG_EXPORT_CELL_MAX ? substr( $v, 0, self::LOG_EXPORT_CELL_MAX ) . '...' : $v;
                }
            }
            $json = wp_json_encode( array_combine( self::LOG_EXPORT_COLUMNS, $values ), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
            if ( false === $json ) {
                return;
            }
            fwrite( $stream, ( $first ? "\n" : ",\n" ) . $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- the response body or a memory stream, not a file
            $first = false;
            $written++;
        }, $max_rows, $upto );
        fwrite( $stream, "\n],\"rows\":" . (int) $written . ",\"complete\":true}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- the response body or a memory stream, not a file
        return $written;
    }

    /** Download file name: plugin, site host and today's date. */
    public static function activity_log_export_filename( $format ) {
        $host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $host = sanitize_file_name( '' !== $host ? $host : 'site' );
        return 'royal-mcp-activity-log-' . $host . '-' . gmdate( 'Y-m-d' ) . ( 'json' === $format ? '.json' : '.csv' );
    }

    /** Highest Activity Log entry id now: what a Clear started from the screen removes up to. */
    public static function activity_log_max_id() {
        global $wpdb;
        if ( ! self::log_table_exists() ) {
            return 0;
        }
        $table = esc_sql( $wpdb->prefix . 'royal_mcp_logs' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, name escaped above
        return (int) $wpdb->get_var( "SELECT MAX(id) FROM `{$table}`" );
    }

    /** The Export button on the Activity Log page: record who exported, then send the CSV. */
    public function handle_export_activity_log() {
        if ( ! current_user_can( self::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from admin_capability(); reads the per-site wp_royal_mcp_logs table only
            wp_die( esc_html__( 'You do not have permission.', 'royal-mcp' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( self::EXPORT_LOG_ACTION );

        $format = isset( $_POST['format'] ) && 'json' === sanitize_key( wp_unslash( $_POST['format'] ) ) ? 'json' : 'csv';
        // Before a Clear: every entry the Clear will remove (ids up to the one the screen showed), with no row cap.
        $upto = isset( $_POST['upto'] ) ? absint( wp_unslash( $_POST['upto'] ) ) : 0;
        $upto = $upto > 0 ? min( $upto, self::activity_log_max_id() ) : 0;
        // A long export is not cut short by the time limit or a closed tab while it can be helped.
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- streaming download of a large table
        }
        ignore_user_abort( true );

        global $wpdb;
        if ( self::log_table_exists() ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- insert into our own log table
            $wpdb->insert(
                $wpdb->prefix . 'royal_mcp_logs',
                [
                    'mcp_server'    => 'MCP Server',
                    'action'        => 'settings:activity_log_exported',
                    'request_data'  => wp_json_encode( [ 'source' => 'settings', 'by' => (int) get_current_user_id(), 'format' => $format, 'before_clear' => $upto > 0 ] ),
                    'response_data' => wp_json_encode( [ 'status' => 'success' ] ),
                    'status'        => 'success',
                ],
                [ '%s', '%s', '%s', '%s', '%s' ]
            );
        }
        nocache_headers();
        header( 'json' === $format ? 'Content-Type: application/json; charset=utf-8' : 'Content-Type: text/csv; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: attachment; filename="' . self::activity_log_export_filename( $format ) . '"' );
        $out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the response body, not a file
        if ( $out ) {
            $max = $upto > 0 ? PHP_INT_MAX : self::LOG_EXPORT_MAX;
            if ( 'json' === $format ) {
                self::write_activity_log_json( $out, $max, $upto );
            } else {
                self::write_activity_log_csv( $out, $max, $upto, true );
            }
            fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the response body, not a file
        }
        exit;
    }

    /** Full name of the table holding cleared entries. */
    public static function cleared_log_table() {
        global $wpdb;
        return $wpdb->prefix . self::LOG_CLEARED_TABLE;
    }

    /** Whether the cleared-entries table exists. */
    private static function cleared_table_exists() {
        global $wpdb;
        $table = self::cleared_log_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence check of our own table
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }

    /** Create or update the cleared-entries table to the current schema. Returns whether it exists afterwards. */
    private static function ensure_cleared_table() {
        global $wpdb;
        if ( self::LOG_CLEARED_SCHEMA === get_option( self::LOG_CLEARED_SCHEMA_OPTION ) && self::cleared_table_exists() ) {
            return true;
        }
        $table   = self::cleared_log_table();
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE {$table} (
            id bigint(20) NOT NULL,
            timestamp datetime NOT NULL,
            mcp_server varchar(255) NOT NULL,
            action varchar(100) NOT NULL,
            request_data longtext,
            response_data longtext,
            status varchar(50) NOT NULL,
            batch_id varchar(32) NOT NULL,
            cleared_by bigint(20) NOT NULL DEFAULT 0,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (batch_id,id),
            KEY expires_at (expires_at)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        if ( ! self::cleared_table_exists() ) {
            return false;
        }
        update_option( self::LOG_CLEARED_SCHEMA_OPTION, self::LOG_CLEARED_SCHEMA, false );
        return true;
    }

    /**
     * Move Activity Log entries with an id up to $upto into the cleared-entries table, where
     * they can be restored for LOG_CLEAR_UNDO_SECONDS. A slice is deleted from the log only
     * after its copy succeeded; when the delete fails, the slice's copy is taken back.
     *
     * @param int $upto    Highest entry id to clear; 0 = every entry there is now.
     * @param int $user_id Who cleared.
     * @return array{removed:int,batch_id:string,expires_at:string,error:string}
     */
    public static function clear_activity_log( $upto = 0, $user_id = 0 ) {
        global $wpdb;
        $out = [ 'removed' => 0, 'batch_id' => '', 'expires_at' => '', 'error' => '' ];
        if ( ! self::log_table_exists() ) {
            return $out;
        }
        $max_id = self::activity_log_max_id();
        $upto   = (int) $upto > 0 ? min( (int) $upto, $max_id ) : $max_id;
        if ( $upto < 1 ) {
            return $out;
        }
        if ( ! self::ensure_cleared_table() ) {
            $out['error'] = 'no_undo_store';
            return $out;
        }
        $log     = esc_sql( $wpdb->prefix . 'royal_mcp_logs' );
        $store   = esc_sql( self::cleared_log_table() );
        $batch   = strtolower( wp_generate_password( 20, false, false ) );
        $expires = gmdate( 'Y-m-d H:i:s', time() + self::LOG_CLEAR_UNDO_SECONDS );
        $after   = 0;
        while ( $after < $upto ) {
            // The id that closes this slice of at most LOG_MOVE_BATCH entries.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, name escaped above
            $end = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$log}` WHERE id > %d AND id <= %d ORDER BY id LIMIT 1 OFFSET %d", $after, $upto, self::LOG_MOVE_BATCH - 1 ) );
            $end = $end > 0 ? $end : $upto;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own tables, names escaped above
            $copied = $wpdb->query( $wpdb->prepare( "INSERT INTO `{$store}` ( id, timestamp, mcp_server, action, request_data, response_data, status, batch_id, cleared_by, expires_at ) SELECT id, timestamp, mcp_server, action, request_data, response_data, status, %s, %d, %s FROM `{$log}` WHERE id > %d AND id <= %d", $batch, (int) $user_id, $expires, $after, $end ) );
            if ( false === $copied ) {
                $out['error'] = 'copy_failed';
                break;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, name escaped above
            $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$log}` WHERE id > %d AND id <= %d", $after, $end ) );
            if ( false === $deleted || (int) $deleted !== (int) $copied ) {
                // Take back the copies of entries that are still in the log; entries the delete did
                // remove stay restorable.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own tables, names escaped above
                $wpdb->query( $wpdb->prepare( "DELETE s FROM `{$store}` s INNER JOIN `{$log}` l ON l.id = s.id WHERE s.batch_id = %s AND s.id > %d AND s.id <= %d", $batch, $after, $end ) );
                $out['removed'] += false === $deleted ? 0 : (int) $deleted;
                $out['error']    = 'delete_failed';
                break;
            }
            $out['removed'] += (int) $deleted;
            $after           = $end;
        }
        if ( $out['removed'] > 0 ) {
            $out['batch_id']   = $batch;
            $out['expires_at'] = $expires;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- insert into our own log table
        $wpdb->insert(
            $wpdb->prefix . 'royal_mcp_logs',
            [
                'mcp_server'    => 'MCP Server',
                'action'        => 'settings:activity_log_cleared',
                'request_data'  => wp_json_encode( [ 'source' => 'settings', 'by' => (int) $user_id ] ),
                'response_data' => wp_json_encode( [ 'status' => '' === $out['error'] ? 'success' : 'error', 'entries_removed' => $out['removed'], 'restorable_until' => $out['expires_at'] ] ),
                'status'        => '' === $out['error'] ? 'success' : 'error',
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );
        return $out;
    }

    /**
     * Cleared batches that can still be restored, newest first.
     *
     * @return array<int,array{batch_id:string,entries:int,expires_at:string}>
     */
    public static function restorable_log_batches() {
        global $wpdb;
        if ( ! self::cleared_table_exists() ) {
            return [];
        }
        $store = esc_sql( self::cleared_log_table() );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, name escaped above
        $rows = $wpdb->get_results( "SELECT batch_id, COUNT(*) AS entries, MAX(expires_at) AS expires_at FROM `{$store}` WHERE expires_at > UTC_TIMESTAMP() GROUP BY batch_id ORDER BY MAX(expires_at) DESC LIMIT 10", ARRAY_A );
        $out  = [];
        foreach ( (array) $rows as $row ) {
            $out[] = [ 'batch_id' => (string) $row['batch_id'], 'entries' => (int) $row['entries'], 'expires_at' => (string) $row['expires_at'] ];
        }
        return $out;
    }

    /**
     * Put a cleared batch back into the Activity Log, newest entries first, up to the row
     * ceiling. An entry already back in the log (same id, time and action) counts as restored.
     * An entry whose id another entry now holds is not written over: both are kept, it stays
     * restorable, and it is counted under collisions. Entries that do not fit under the ceiling
     * stay restorable until the batch expires.
     *
     * @param string $batch_id Batch to restore.
     * @param int    $user_id  Who restored.
     * @return array{restored:int,left:int,collisions:int,error:string}
     */
    public static function restore_activity_log( $batch_id, $user_id = 0 ) {
        global $wpdb;
        $out      = [ 'restored' => 0, 'left' => 0, 'collisions' => 0, 'error' => '' ];
        $batch_id = strtolower( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $batch_id ) );
        if ( '' === $batch_id || ! self::cleared_table_exists() || ! self::log_table_exists() ) {
            $out['error'] = 'not_found';
            return $out;
        }
        $log   = esc_sql( $wpdb->prefix . 'royal_mcp_logs' );
        $store = esc_sql( self::cleared_log_table() );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, name escaped above
        $waiting = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$store}` WHERE batch_id = %s AND expires_at > UTC_TIMESTAMP()", $batch_id ) );
        if ( $waiting < 1 ) {
            $out['error'] = 'not_found';
            return $out;
        }
        $max = self::log_max_rows();
        // One place is kept for the entry that records the restore.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, name escaped above
        $room   = $max > 0 ? max( 0, $max - (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$log}`" ) - 1 ) : PHP_INT_MAX;
        $cursor = PHP_INT_MAX;
        while ( true ) {
            // The next slice, newest first, below the ids already handled.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, name escaped above
            $ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$store}` WHERE batch_id = %s AND id < %d ORDER BY id DESC LIMIT %d", $batch_id, $cursor, self::LOG_MOVE_BATCH ) ) );
            if ( ! $ids ) {
                break;
            }
            $low = (int) end( $ids );
            // Entries already back in the log count as restored without taking room.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own tables, names escaped above
            $same = $wpdb->query( $wpdb->prepare( "DELETE s FROM `{$store}` s INNER JOIN `{$log}` l ON l.id = s.id AND l.timestamp = s.timestamp AND l.action = s.action WHERE s.batch_id = %s AND s.id >= %d AND s.id < %d", $batch_id, $low, $cursor ) );
            $out['restored'] += (int) $same;
            // Entries whose id is held by a different entry stay where they are.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own tables, names escaped above
            $out['collisions'] += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$store}` s INNER JOIN `{$log}` l ON l.id = s.id WHERE s.batch_id = %s AND s.id >= %d AND s.id < %d", $batch_id, $low, $cursor ) );
            if ( $room < 1 ) {
                break;
            }
            // The newest free ids of the slice that fit, written back.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own tables, names escaped above
            $free = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT s.id FROM `{$store}` s LEFT JOIN `{$log}` l ON l.id = s.id WHERE s.batch_id = %s AND s.id >= %d AND s.id < %d AND l.id IS NULL ORDER BY s.id DESC LIMIT %d", $batch_id, $low, $cursor, (int) min( self::LOG_MOVE_BATCH, $room ) ) ) );
            if ( $free ) {
                $free_low = (int) end( $free );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own tables, names escaped above
                $copied = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `{$log}` ( id, timestamp, mcp_server, action, request_data, response_data, status ) SELECT s.id, s.timestamp, s.mcp_server, s.action, s.request_data, s.response_data, s.status FROM `{$store}` s LEFT JOIN `{$log}` l ON l.id = s.id WHERE s.batch_id = %s AND s.id >= %d AND s.id < %d AND l.id IS NULL", $batch_id, $free_low, $cursor ) );
                if ( false === $copied ) {
                    $out['error'] = 'copy_failed';
                    break;
                }
                // Only entries now in the log with the same id, time and action leave the undo store.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own tables, names escaped above
                $moved = (int) $wpdb->query( $wpdb->prepare( "DELETE s FROM `{$store}` s INNER JOIN `{$log}` l ON l.id = s.id AND l.timestamp = s.timestamp AND l.action = s.action WHERE s.batch_id = %s AND s.id >= %d AND s.id < %d", $batch_id, $free_low, $cursor ) );
                $out['restored'] += $moved;
                $room            -= $moved;
            }
            $cursor = $low;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, name escaped above
        $out['left'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$store}` WHERE batch_id = %s", $batch_id ) );
        if ( 0 === $out['restored'] && '' === $out['error'] ) {
            $out['error'] = $out['collisions'] > 0 && $out['collisions'] >= $out['left'] ? 'collision' : 'full';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- insert into our own log table
        $wpdb->insert(
            $wpdb->prefix . 'royal_mcp_logs',
            [
                'mcp_server'    => 'MCP Server',
                'action'        => 'settings:activity_log_restored',
                'request_data'  => wp_json_encode( [ 'source' => 'settings', 'by' => (int) $user_id ] ),
                'response_data' => wp_json_encode( [ 'status' => '' === $out['error'] ? 'success' : 'error', 'entries_restored' => $out['restored'], 'entries_left' => $out['left'], 'collisions' => $out['collisions'] ] ),
                'status'        => '' === $out['error'] ? 'success' : 'error',
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );
        return $out;
    }

    /**
     * Delete cleared entries whose restore window has passed. Runs with the daily clean-up.
     *
     * @return int Rows deleted.
     */
    public static function purge_cleared_logs() {
        global $wpdb;
        if ( ! self::cleared_table_exists() ) {
            return 0;
        }
        $store   = esc_sql( self::cleared_log_table() );
        $deleted = 0;
        for ( $batch = 0; $batch < 200; $batch++ ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, name escaped above
            $n        = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$store}` WHERE expires_at <= UTC_TIMESTAMP() LIMIT %d", self::LOG_MOVE_BATCH ) );
            $deleted += $n;
            if ( $n < self::LOG_MOVE_BATCH ) {
                break;
            }
        }
        return $deleted;
    }

    /** Whether this request was sent as a form post: Clear and Restore change data only on POST. */
    private static function is_post_request() {
        return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
    }

    /** The Clear button on the Activity Log page: move the entries shown up to now to the undo store, then record who did it. */
    public function handle_clear_activity_log() {
        if ( ! current_user_can( self::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from admin_capability(); clears the per-site wp_royal_mcp_logs table only
            wp_die( esc_html__( 'You do not have permission.', 'royal-mcp' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( self::CLEAR_LOG_ACTION );

        $args = [ 'page' => 'royal-mcp-logs', 'royal_mcp_log_cleared' => '0' ];
        if ( ! self::is_post_request() ) {
            $args['royal_mcp_log_cleared'] = 'stale';
        } elseif ( ! empty( $_POST['royal_mcp_clear_confirm'] ) ) {
            // The cut-off id the screen showed is required, so a Clear never reaches past what was shown.
            $upto = isset( $_POST['upto'] ) ? absint( wp_unslash( $_POST['upto'] ) ) : 0;
            if ( $upto < 1 ) {
                $args['royal_mcp_log_cleared'] = 'stale';
            } else {
                $result                        = self::clear_activity_log( $upto, (int) get_current_user_id() );
                $args['royal_mcp_log_cleared'] = '' === $result['error'] ? '1' : 'error';
                $args['royal_mcp_log_count']   = (int) $result['removed'];
            }
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /** The Restore button next to a cleared batch: put its entries back. */
    public function handle_restore_activity_log() {
        if ( ! current_user_can( self::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from admin_capability(); restores into the per-site wp_royal_mcp_logs table only
            wp_die( esc_html__( 'You do not have permission.', 'royal-mcp' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( self::RESTORE_LOG_ACTION );

        if ( ! self::is_post_request() ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'royal-mcp-logs' ], admin_url( 'admin.php' ) ) );
            exit;
        }
        $batch  = isset( $_POST['batch_id'] ) ? sanitize_key( wp_unslash( $_POST['batch_id'] ) ) : '';
        $result = self::restore_activity_log( $batch, (int) get_current_user_id() );
        wp_safe_redirect( add_query_arg( [
            'page'                    => 'royal-mcp-logs',
            'royal_mcp_log_restored'  => '' === $result['error'] ? 'done' : sanitize_key( $result['error'] ),
            'royal_mcp_log_count'     => (int) $result['restored'],
            'royal_mcp_log_left'      => (int) $result['left'],
            'royal_mcp_log_collision' => (int) $result['collisions'],
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function render_logs_page() {
        if (!current_user_can( self::admin_capability() )) { // audit:multisite-manage-options-safe -- capability from admin_capability(); reads per-site wp_royal_mcp_logs (per-site-prefixed table, no network-scope data)
            return;
        }

        global $wpdb;
        // Table name constructed safely from prefix + hardcoded string, then escaped
        $table_name = esc_sql($wpdb->prefix . 'royal_mcp_logs');

        // Verify nonce for page navigation
        $nonce_valid = isset($_GET['_wpnonce']) ? wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'royal_mcp_logs_page') : true;
        $page = ($nonce_valid && isset($_GET['paged'])) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin logs table, table name escaped via esc_sql()
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // Custom plugin logs table - table name escaped via esc_sql()
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table_name}` ORDER BY timestamp DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );
        // phpcs:enable

        include ROYAL_MCP_PLUGIN_DIR . 'templates/admin/logs.php';
    }

    /**
     * AJAX handler — wipe all OAuth state (clients, tokens, in-flight auth codes).
     *
     * Used by the "Reset OAuth State" button on the settings page. Wipes all
     * clients, tokens, and in-flight auth codes; every connected MCP client
     * must re-authorize after this runs.
     */
    public function ajax_reset_oauth_state() {
        check_ajax_referer('royal_mcp_nonce', 'nonce');

        if (!current_user_can( self::admin_capability() )) { // audit:multisite-manage-options-safe -- capability from admin_capability(); clears per-site OAuth tables only (wp_royal_mcp_oauth_* are per-site-prefixed; no network-scope state)
            wp_send_json_error(['message' => esc_html__('Unauthorized', 'royal-mcp')]);
        }

        $counts = \Royal_MCP\OAuth\Token_Store::reset_all_oauth_state();

        // Audit trail in the Activity Log so we can see who reset and when.
        global $wpdb;
        $current_user = wp_get_current_user();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert(
            $wpdb->prefix . 'royal_mcp_logs',
            [
                'mcp_server'    => 'OAuth Server',
                'action'        => 'oauth:reset',
                'request_data'  => wp_json_encode([
                    'user_id'    => (int) $current_user->ID,
                    'user_login' => $current_user->user_login,
                ]),
                'response_data' => wp_json_encode($counts),
                'status'        => 'success',
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        $base_message = sprintf(
            /* translators: 1: clients deleted, 2: tokens deleted, 3: auth codes deleted */
            esc_html__('OAuth state reset. Deleted %1$d clients, %2$d tokens, %3$d auth codes. Connected MCP clients will need to re-authorize.', 'royal-mcp'),
            (int) $counts['clients'],
            (int) $counts['tokens'],
            (int) $counts['auth_codes']
        );

        if ( ! empty( $counts['static_creds_cleared'] ) ) {
            $base_message .= ' ' . esc_html__('Manually-configured OAuth client credentials were also cleared; the connector will fall back to Dynamic Client Registration.', 'royal-mcp');
        }

        wp_send_json_success([
            'message' => $base_message,
            'counts'  => $counts,
        ]);
    }

    /**
     * AJAX handler — clear a single OAuth manual-credential field (oauth_client_id
     * or oauth_client_secret) from the royal_mcp_settings option.
     *
     * The settings sanitize callback treats an empty form submission as
     * "preserve previous value" (defense against accidental blanking), which
     * would otherwise leave admins with no UI path to switch from manual-
     * credential mode back to Dynamic Client Registration once they had
     * generated a static client. This AJAX handler is that UI path.
     */
    public function ajax_clear_oauth_field() {
        check_ajax_referer('royal_mcp_nonce', 'nonce');

        if (!current_user_can( self::admin_capability() )) { // audit:multisite-manage-options-safe -- capability from admin_capability(); clears one field on per-site royal_mcp_settings option (no network-scope state)
            wp_send_json_error(['message' => esc_html__('Unauthorized', 'royal-mcp')]);
        }

        $field = isset($_POST['field']) ? sanitize_text_field(wp_unslash($_POST['field'])) : '';
        $allowed_fields = ['oauth_client_id', 'oauth_client_secret'];
        if (!in_array($field, $allowed_fields, true)) {
            wp_send_json_error(['message' => esc_html__('Invalid field name.', 'royal-mcp')]);
        }

        $settings = get_option('royal_mcp_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }
        if ('oauth_client_secret' === $field) {
            unset($settings['oauth_client_secret'], $settings['oauth_client_secret_hash']);
        } else {
            $settings[$field] = '';
        }
        self::save_programmatically($settings);

        wp_send_json_success([
            'field'   => $field,
            'message' => esc_html__('Field cleared. Save your settings to confirm.', 'royal-mcp'),
        ]);
    }

    public function admin_footer_text($text) {
        $current_screen = get_current_screen();

        // Add footer to all admin pages
        $footer_text = sprintf(
            /* translators: %s: Royal Plugins link */
            __('Built By %s', 'royal-mcp'),
            '<a href="https://www.royalplugins.com" target="_blank" rel="noopener noreferrer">Royal Plugins</a>'
        );

        // If we're on our plugin pages, add it before the existing text
        if ($current_screen && strpos($current_screen->id, 'royal-mcp') !== false) {
            return $footer_text . ' | ' . $text;
        }

        // For all other admin pages, return original text unchanged
        return $text;
    }

}
