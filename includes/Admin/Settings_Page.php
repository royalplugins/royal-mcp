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
        add_action('admin_init', [$this, 'maybe_dismiss_review_banner']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_filter('admin_footer_text', [$this, 'admin_footer_text']);

        // AJAX handlers
        add_action('wp_ajax_royal_mcp_reset_oauth_state', [$this, 'ajax_reset_oauth_state']);
        add_action('wp_ajax_royal_mcp_clear_oauth_field', [$this, 'ajax_clear_oauth_field']);
        add_action('admin_post_' . self::REGENERATE_ACTION, [$this, 'handle_regenerate_api_key']);
        add_action('admin_post_' . self::DISABLE_KEY_ACTION, [$this, 'handle_disable_api_key']);
    }

    /** Render the wp.org review-request banner (Free tier only, version-stamped dismissal). */
    public static function render_review_banner() {
        if ( class_exists( '\\Royal_MCP_Pro\\Tool_Registry', false ) ) {
            return;
        }
        $user_id = get_current_user_id();
        if (!$user_id || !current_user_can('manage_options')) { // audit:multisite-manage-options-safe -- read-only banner render gated by per-user meta (no state mutation)
            return;
        }
        $dismissed_at = get_user_meta($user_id, 'royal_mcp_review_dismissed_version', true);
        if ($dismissed_at && version_compare($dismissed_at, ROYAL_MCP_VERSION, '>=')) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            add_query_arg('royal_mcp_dismiss_review', '1'),
            'royal_mcp_dismiss_review'
        );
        ?>
        <div class="royal-mcp-review-banner">
            <a href="<?php echo esc_url($dismiss_url); ?>" class="royal-mcp-review-dismiss" aria-label="<?php esc_attr_e('Dismiss this notice', 'royal-mcp'); ?>">
                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
            </a>
            <div class="royal-mcp-review-body">
                <span class="royal-mcp-review-stars" aria-hidden="true">
                    <span class="dashicons dashicons-star-filled"></span>
                    <span class="dashicons dashicons-star-filled"></span>
                    <span class="dashicons dashicons-star-filled"></span>
                    <span class="dashicons dashicons-star-filled"></span>
                    <span class="dashicons dashicons-star-filled"></span>
                </span>
                <div class="royal-mcp-review-text">
                    <strong><?php esc_html_e('Enjoying Royal MCP?', 'royal-mcp'); ?></strong>
                    <span><?php esc_html_e('Leaving a quick review on WordPress.org helps other admins find it. Takes about a minute.', 'royal-mcp'); ?></span>
                </div>
            </div>
            <a href="https://wordpress.org/support/plugin/royal-mcp/reviews/#new-post" target="_blank" rel="noopener noreferrer" class="royal-mcp-review-cta">
                <?php esc_html_e('Leave a Review', 'royal-mcp'); ?>
                <span class="dashicons dashicons-external" aria-hidden="true"></span>
            </a>
        </div>
        <?php
    }

    /**
     * Persist a per-user dismissal of the review banner. Version-stamped —
     * the banner reappears on each plugin version update.
     */
    public function maybe_dismiss_review_banner() {
        if (!isset($_GET['royal_mcp_dismiss_review'])) {
            return;
        }
        if (!current_user_can('manage_options')) { // audit:multisite-manage-options-safe -- writes per-user meta only (no per-site or network-scope state)
            return;
        }
        if (!isset($_GET['_wpnonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'royal_mcp_dismiss_review')) {
            return;
        }

        update_user_meta(get_current_user_id(), 'royal_mcp_review_dismissed_version', ROYAL_MCP_VERSION);

        wp_safe_redirect(remove_query_arg(['royal_mcp_dismiss_review', '_wpnonce']));
        exit;
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
            __('Royal MCP Settings', 'royal-mcp'),
            __('Royal MCP', 'royal-mcp'),
            self::admin_capability(),
            'royal-mcp',
            [$this, 'render_settings_page'],
            'dashicons-networking',
            80
        );

        add_submenu_page(
            'royal-mcp',
            __('Settings', 'royal-mcp'),
            __('Settings', 'royal-mcp'),
            self::admin_capability(),
            'royal-mcp',
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

        wp_safe_redirect( add_query_arg( [ 'page' => 'royal-mcp', 'settings-updated' => 'true' ], admin_url( 'admin.php' ) ) );
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

        wp_safe_redirect( add_query_arg( [ 'page' => 'royal-mcp', 'settings-updated' => 'true' ], admin_url( 'admin.php' ) ) );
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
            ],
        ]);
    }

    public function render_settings_page() {
        if (!current_user_can( self::admin_capability() )) { // audit:multisite-manage-options-safe -- capability from admin_capability(); reads per-site royal_mcp_settings only (per-site option, no network-scope state)
            return;
        }

        $settings = get_option('royal_mcp_settings', [
            'enabled'         => false,
            'api_key'         => '',
            'api_key_hash'    => '',
            'api_key_user_id' => 0,
        ]);

        include ROYAL_MCP_PLUGIN_DIR . 'templates/admin/settings.php';
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
