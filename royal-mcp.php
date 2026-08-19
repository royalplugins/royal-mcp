<?php
/**
 * Plugin Name: Royal MCP – Secure AI Connector for Claude, ChatGPT & Gemini
 * Plugin URI: https://royalplugins.com/support/royal-mcp/
 * Description: Integrate Model Context Protocol (MCP) servers with WordPress to enable LLM interactions with your site
 * Version: 1.4.42
 * Author: Royal Plugins
 * Author URI: https://www.royalplugins.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path: /languages
 * Text Domain: royal-mcp
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// If Royal MCP Pro is already active on this site, its bundled Free
// codebase (vendored) has already declared this class + function pair.
// Bail early so WP's plugin loader doesn't fatal on redeclare. Still
// register a refusal on activation so users trying to activate this
// plugin while Pro is running get a clean explanation instead of
// silently ending up in active_plugins with a no-op copy.
if ( class_exists( 'Royal_MCP_Plugin', false ) ) {
    register_activation_hook( __FILE__, function () {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( is_plugin_active( 'royal-mcp-pro/royal-mcp-pro.php' ) ) {
            wp_die(
                esc_html__( 'Royal MCP Pro is already active. It includes every Royal MCP feature — you don\'t need the free plugin alongside it. Deactivate Royal MCP Pro first if you want to use the free plugin instead.', 'royal-mcp' ),
                esc_html__( 'Royal MCP already active as part of Royal MCP Pro', 'royal-mcp' ),
                array( 'back_link' => true )
            );
        }
    } );
    return;
}

// Define plugin constants
define('ROYAL_MCP_VERSION', '1.4.42');
define('ROYAL_MCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ROYAL_MCP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ROYAL_MCP_PLUGIN_FILE', __FILE__);
define('ROYAL_MCP_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'Royal_MCP\\';
    $base_dir = ROYAL_MCP_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Wrap class + function in a class_exists gate. PHP hoists top-level
// declarations at parse time so an unconditional `class` or `function`
// would fatal-on-parse when Royal MCP Pro's vendored copy already
// declared the same name. Inside a conditional block PHP defers the
// declaration to runtime — combined with the early-return guard above,
// this keeps a Pro+Free load fully collision-safe.
if ( ! class_exists( 'Royal_MCP_Plugin', false ) ) :

/**
 * Main plugin class
 */
class Royal_MCP_Plugin {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('plugins_loaded', [$this, 'maybe_upgrade_db'], 5);
        add_action('plugins_loaded', [$this, 'init']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('rest_api_init', [$this, 'register_mcp_endpoint']);

        // Cache-Control: no-store on EVERY response under our REST namespace.
        // 1.4.13 added this to OAuth endpoints. 1.4.15 audit found the MCP
        // endpoint missing it (Server::json_response) and the REST_Controller
        // routes (/posts, /pages, /site, etc.) also missing it — both got
        // poisoned by URL-keyed edge caches when CF cached an early response
        // and served it back to differently-authenticated requests. The
        // global filter below covers the whole namespace defensively;
        // per-response edits in MCP/Server.php are kept as belt-and-suspenders.
        add_filter('rest_post_dispatch', [$this, 'force_no_store_on_namespace'], 10, 3);

        // JSON-RPC envelope integrity guard. Some host-layer transformers
        // (edge JSON minifiers, WAF response optimizers, plugin conflicts)
        // coerce our "jsonrpc":"2.0" string via a float cast which drops the
        // trailing zero, producing "jsonrpc":"2". Strict MCP clients
        // (Claude Desktop, mcp-remote) reject the response with
        // ZodError: expected "2.0". Priority 999 re-forces the correct
        // value AFTER any other filter, scoped to our namespace so we don't
        // touch sibling REST plugins' responses.
        add_filter('rest_pre_echo_response', [$this, 'force_jsonrpc_version'], 999, 3);

        // OAuth 2.0 endpoints (served at domain root, not under /wp-json/).
        add_action('init', [$this, 'register_oauth_rewrites']);
        add_filter('query_vars', [$this, 'register_oauth_query_vars']);
        add_action('parse_request', [$this, 'handle_oauth_request']);

        // Strip POST-only OAuth rewrite rules (/register, /token) on GET/HEAD
        // so browser visits fall through to any page at those slugs.
        // POST-only per RFC 7591 §3.1 (DCR) + RFC 6749 §3.2 (token).
        add_filter('option_rewrite_rules', [__CLASS__, 'strip_oauth_get_only_rules']);

        // Scheduled token cleanup.
        add_action('royal_mcp_token_cleanup', [\Royal_MCP\OAuth\Token_Store::class, 'cleanup_expired']);
        add_action('royal_mcp_token_cleanup', [\Royal_MCP\MCP\Undo_Store::class, 'cleanup_expired']);

        // sessions cleanup rides on the same daily cron action.
        add_action('royal_mcp_token_cleanup', [\Royal_MCP\MCP\Session_Store::class, 'cleanup_expired']);

        // Add plugin action links (Settings, Docs)
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);

        // Elementor MCP module coexistence: admin notice + dismiss handler.
        // Safe to register unconditionally; the render callback checks native
        // detection before drawing anything.
        \Royal_MCP\Integrations\Elementor_Coexistence::register_hooks();

        // Preview_Link redirect handler — validates rmcp_preview token param
        // and forwards to the native preview URL. Registered unconditionally
        // so incoming token URLs are honored regardless of whether the
        // wp_create_preview_link tool was the most recent MCP call.
        \Royal_MCP\MCP\Support\Preview_Link::register();


        // Royal Plugins Chrome Pack: custom top header + lightweight footer +
        // Royal Tools submenu + Founders Bundle callout. Screen-ID-gated to
        // Royal MCP admin pages only — never touches other WP admin.
        require_once ROYAL_MCP_PLUGIN_DIR . 'includes/chrome/class-royal-mcp-chrome.php';
        \Royal_MCP\Chrome\Royal_MCP_Chrome::get_instance();

        // WordPress Abilities API registration (WP 6.9+). function_exists() guard makes this a
        // silent no-op on older WP. The option flag lets an admin flip the feature off in one
        // call for rollback without a plugin update.
        //
        // WP core exposes two separate hooks: wp_abilities_api_categories_init runs first
        // (categories registry init), wp_abilities_api_init runs after (ability registry init,
        // by which point categories must already exist). Registering an ability against a
        // non-registered category throws.
        if ( function_exists( 'wp_register_ability_category' ) && (bool) get_option( 'royal_mcp_abilities_registration_enabled', true ) ) {
            add_action( 'wp_abilities_api_categories_init', array( \Royal_MCP\Abilities\Categories::class, 'register' ) );
            add_action( 'wp_abilities_api_init', array( \Royal_MCP\Abilities\Registrar::class, 'register' ) );

            // MCP Adapter server registration (Option C — own named server, explicit ability
            // list, our abilities do NOT auto-enroll on the adapter's default server). Guarded
            // on adapter presence; silent no-op when MCP Adapter isn't installed.
            if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
                add_action( 'mcp_adapter_init', array( \Royal_MCP\Abilities\MCP_Adapter_Server::class, 'register' ) );
            }
        }
    }

    /**
     * Force no-store cache headers on every response under royal-mcp/* namespace.
     *
     * Hooked late on rest_post_dispatch so it overrides any cache headers a
     * route callback may have set. Prevents edge/host caches from URL-keying
     * responses and serving them back to subsequent requests with different
     * auth state.
     *
     * @param \WP_REST_Response $response The dispatch result.
     * @param \WP_REST_Server   $server   The REST server instance.
     * @param \WP_REST_Request  $request  The original request.
     * @return \WP_REST_Response
     */
    public function force_no_store_on_namespace( $response, $server, $request ) {
        if ( ! $response instanceof \WP_REST_Response ) {
            return $response;
        }
        $route = $request->get_route();
        if ( is_string( $route ) && 0 === strpos( $route, '/royal-mcp/' ) ) {
            $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
            $response->header( 'Pragma', 'no-cache' );
        }
        return $response;
    }

    /**
     * Re-force jsonrpc="2.0" on responses from our namespace.
     *
     * Fires on rest_pre_echo_response (after rest_post_dispatch, before
     * wp_json_encode) at priority 999 so any upstream transformer that
     * coerces our jsonrpc string via float cast gets overwritten before
     * serialization. Scoped to /royal-mcp/ prefix so sibling plugins'
     * REST responses are untouched.
     *
     * @param array|mixed        $result  The response data about to be JSON-encoded.
     * @param \WP_REST_Server    $server  The REST server instance.
     * @param \WP_REST_Request   $request The original request.
     * @return array|mixed
     */
    public function force_jsonrpc_version( $result, $server, $request ) {
        $route = $request->get_route();
        if ( ! is_string( $route ) || 0 !== strpos( $route, '/royal-mcp/' ) ) {
            return $result;
        }
        if ( is_array( $result ) && isset( $result['jsonrpc'] ) ) {
            $result['jsonrpc'] = '2.0';
        }
        return $result;
    }

    /**
     * Add action links to plugins page
     */
    public function add_action_links($links) {
        $plugin_links = [
            '<a href="' . admin_url('admin.php?page=royal-mcp') . '">' . __('Settings', 'royal-mcp') . '</a>',
            '<a href="https://royalplugins.com/support/royal-mcp/" target="_blank">' . __('Docs', 'royal-mcp') . '</a>',
        ];
        return array_merge($plugin_links, $links);
    }

    public function activate() {
        // Refuse activation if the Pro plugin is active. Pro bundles the free
        // codebase; running both simultaneously double-registers every hook
        // and REST route. wp_die during activation rolls the activation back
        // and shows the message to the user in wp-admin.
        //
        // The `! defined( 'ROYAL_MCP_LOADED_BY_PRO' )` gate skips this check
        // when Free is running vendored inside Pro (Pro's bootstrap defines
        // that constant before requiring the vendored royal-mcp.php). In that
        // context Free's activate() is the mechanism that creates OAuth +
        // session tables Pro needs — refusing it would leave Pro half-installed.
        if ( ! defined( 'ROYAL_MCP_LOADED_BY_PRO' ) ) {
            if ( ! function_exists( 'is_plugin_active' ) ) {
                include_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            if ( is_plugin_active( 'royal-mcp-pro/royal-mcp-pro.php' ) ) {
                wp_die(
                    esc_html__( 'Royal MCP Pro is already active. It includes every Royal MCP feature — you don\'t need the free plugin alongside it. Deactivate Royal MCP Pro first if you want to use the free plugin instead.', 'royal-mcp' ),
                    esc_html__( 'Royal MCP already active as part of Royal MCP Pro', 'royal-mcp' ),
                    array( 'back_link' => true )
                );
            }
        }

        // Create necessary database tables and options
        $this->create_tables();

        // Create OAuth tables.
        if ( class_exists( '\Royal_MCP\OAuth\Token_Store' ) ) {
            \Royal_MCP\OAuth\Token_Store::create_tables();
        } else {
            // Force-load if autoloader hasn't fired yet (WP 7.0+ activation flow)
            $token_store_file = ROYAL_MCP_PLUGIN_DIR . 'includes/OAuth/Token_Store.php';
            if ( file_exists( $token_store_file ) ) {
                require_once $token_store_file;
                \Royal_MCP\OAuth\Token_Store::create_tables();
            }
        }

        // Create sessions table. Same force-load pattern as Token_Store
        // because register_activation_hook fires before the autoloader on some
        // WP versions, so class_exists() returns false on a fresh activation.
        if ( class_exists( '\Royal_MCP\MCP\Session_Store' ) ) {
            \Royal_MCP\MCP\Session_Store::create_tables();
        } else {
            $session_store_file = ROYAL_MCP_PLUGIN_DIR . 'includes/MCP/Session_Store.php';
            if ( file_exists( $session_store_file ) ) {
                require_once $session_store_file;
                \Royal_MCP\MCP\Session_Store::create_tables();
            }
        }

        // Set default options.
        // API key uses lowercase hex (32 chars) instead of mixed-case alphanumeric
        // so customers can transcribe it without uppercase/lowercase ambiguity in
        // monospace admin fonts (e.g., O vs 0, I vs l vs 1).
        add_option('royal_mcp_settings', [
            'enabled' => false,
            'platforms' => [],
            'mcp_servers' => [],
            'api_key' => bin2hex(random_bytes(16)),
        ]);

        // Register OAuth rewrite rules before flushing.
        $this->register_oauth_rewrites();

        // Flush rewrite rules
        flush_rewrite_rules();

        // Schedule daily token cleanup.
        if ( ! wp_next_scheduled( 'royal_mcp_token_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'royal_mcp_token_cleanup' );
        }

        // Mark schema as current so the runtime migration check is a no-op for fresh installs.
        update_option('royal_mcp_db_version', ROYAL_MCP_VERSION);
    }

    /**
     * Runtime schema check. register_activation_hook only fires on activation, so plugins
     * that ship new tables via an update never run create_tables() on existing installs.
     * This heals any install where the DB version doesn't match the plugin version.
     *
     * INVARIANT: db_version must only advance when EVERY required migration actually ran.
     * If class_exists() returns false (autoloader transiently failed during auto-update,
     * opcache stale, file-deploy race) AND the force-load fallback can't find the file,
     * we leave db_version alone so the next request retries.
     *
     * INVARIANT: db_version matching the plugin version is necessary but NOT sufficient —
     * we also verify required tables physically exist before short-circuiting. Stuck states
     * like "uninstall dropped tables but left db_version intact, then reinstall ran" cannot
     * latch the healer into a permanent no-op.
     */
    public function maybe_upgrade_db() {
        if (get_option('royal_mcp_db_version') === ROYAL_MCP_VERSION
            && $this->required_tables_exist()) {
            return;
        }

        $token_store_ok = false;
        if (class_exists('\Royal_MCP\OAuth\Token_Store')) {
            \Royal_MCP\OAuth\Token_Store::create_tables();
            $token_store_ok = true;
        } else {
            $f = ROYAL_MCP_PLUGIN_DIR . 'includes/OAuth/Token_Store.php';
            if (file_exists($f)) {
                require_once $f;
                \Royal_MCP\OAuth\Token_Store::create_tables();
                $token_store_ok = true;
            }
        }

        $session_store_ok = false;
        if (class_exists('\Royal_MCP\MCP\Session_Store')) {
            \Royal_MCP\MCP\Session_Store::create_tables();
            $session_store_ok = true;
        } else {
            $f = ROYAL_MCP_PLUGIN_DIR . 'includes/MCP/Session_Store.php';
            if (file_exists($f)) {
                require_once $f;
                \Royal_MCP\MCP\Session_Store::create_tables();
                $session_store_ok = true;
            }
        }

        if ($token_store_ok && $session_store_ok) {
            update_option('royal_mcp_db_version', ROYAL_MCP_VERSION);
        }
        // If either failed: db_version stays at the old value, next request retries.
    }

    /**
     * Verify the two core tables required for OAuth client registration and MCP session
     * persistence physically exist. Used by maybe_upgrade_db() as a backstop against the
     * db_version option lying.
     *
     * Two SHOW TABLES LIKE queries per pageload — negligible cost, and the safe-by-default
     * payoff is that no external or accidental state mismatch can latch the healer.
     */
    private function required_tables_exist() {
        global $wpdb;
        $required = [
            $wpdb->prefix . 'royal_mcp_oauth_clients',
            $wpdb->prefix . 'royal_mcp_sessions',
        ];
        foreach ($required as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe, no caching layer involved.
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
                return false;
            }
        }
        return true;
    }

    public function deactivate() {
        // Clear scheduled events.
        wp_clear_scheduled_hook( 'royal_mcp_token_cleanup' );

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_name = $wpdb->prefix . 'royal_mcp_logs';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            mcp_server varchar(255) NOT NULL,
            action varchar(100) NOT NULL,
            request_data longtext,
            response_data longtext,
            status varchar(50) NOT NULL,
            PRIMARY KEY  (id),
            KEY timestamp (timestamp),
            KEY mcp_server (mcp_server)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /* ------------------------------------------------------------------
     *  OAuth 2.0 rewrite rules & request handling
     * ----------------------------------------------------------------*/

    /**
     * OAuth endpoint URL slugs (no leading slash, no regex suffix). Filterable
     * for customers whose site has an existing page at one of the default slugs
     * (common on membership sites — /register conflicts with MemberPress, Paid
     * Memberships Pro, Restrict Content Pro, Ultimate Member defaults).
     *
     * Return value shape: [ action => slug ]. Action matches the query var
     * royal_mcp_oauth value. Slug is a URL path segment; customers can nest,
     * e.g. return [ 'register' => 'royal-mcp-oauth/register' ] to relocate.
     * metadata() reads from this same source so OAuth discovery advertises
     * whatever the site actually serves.
     */
    public static function get_oauth_rewrite_paths() {
        return apply_filters( 'royal_mcp_oauth_rewrite_paths', [
            'authorize' => 'authorize',
            'token'     => 'token',
            'register'  => 'register',
        ] );
    }

    /**
     * Register rewrite rules for OAuth endpoints at domain root.
     */
    public function register_oauth_rewrites() {
        add_rewrite_rule( '\.well-known/oauth-protected-resource(/.*)?$', 'index.php?royal_mcp_oauth=protected_resource', 'top' );
        add_rewrite_rule( '\.well-known/oauth-authorization-server/?$', 'index.php?royal_mcp_oauth=metadata', 'top' );
        foreach ( self::get_oauth_rewrite_paths() as $action => $slug ) {
            $slug = ltrim( trim( (string) $slug ), '/' );
            if ( $slug === '' ) continue;
            add_rewrite_rule( $slug . '/?$', 'index.php?royal_mcp_oauth=' . $action, 'top' );
        }
    }

    /**
     * Strip OAuth rewrite rules on GET/HEAD for endpoints that are POST-only
     * per spec (/register per RFC 7591 §3.1, /token per RFC 6749 §3.2). This
     * lets the WP page router take over for browser visits to those paths on
     * sites where the customer has a real page at the same slug — most commonly
     * a membership-plugin /register page. /authorize is spec-required to accept
     * GET (RFC 6749 §3.1) so it is NOT stripped.
     *
     * IMPORTANT: hooks option_rewrite_rules NOT rewrite_rules_array. The latter
     * feeds update_option() during a flush; filtering there would persist the
     * removal and permanently break POST /register.
     *
     * @param mixed $rules
     * @return mixed
     */
    public static function strip_oauth_get_only_rules( $rules ) {
        if ( ! is_array( $rules ) ) {
            return $rules;
        }
        $method = isset( $_SERVER['REQUEST_METHOD'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
            : 'GET';
        if ( $method !== 'GET' && $method !== 'HEAD' ) {
            return $rules;
        }
        $paths = self::get_oauth_rewrite_paths();
        foreach ( [ 'register', 'token' ] as $action ) {
            $slug = isset( $paths[ $action ] ) ? ltrim( trim( (string) $paths[ $action ] ), '/' ) : '';
            if ( $slug === '' ) continue;
            $rule_key = $slug . '/?$';
            if ( isset( $rules[ $rule_key ] )
                && false !== strpos( (string) $rules[ $rule_key ], 'royal_mcp_oauth=' . $action ) ) {
                unset( $rules[ $rule_key ] );
            }
        }
        return $rules;
    }

    /**
     * Register the query variable used by OAuth rewrite rules.
     */
    public function register_oauth_query_vars( $vars ) {
        $vars[] = 'royal_mcp_oauth';
        return $vars;
    }

    /**
     * Intercept requests that match OAuth rewrite rules and dispatch to OAuth\Server.
     */
    public function handle_oauth_request( $wp ) {
        if ( empty( $wp->query_vars['royal_mcp_oauth'] ) ) {
            return;
        }

        // Only handle OAuth if plugin is enabled (allow metadata always for discovery).
        $action = sanitize_text_field( $wp->query_vars['royal_mcp_oauth'] );
        if ( 'metadata' !== $action ) {
            $settings = get_option( 'royal_mcp_settings', [] );
            if ( empty( $settings['enabled'] ) ) {
                status_header( 503 );
                header( 'Content-Type: application/json' );
                echo wp_json_encode( [ 'error' => 'server_error', 'error_description' => 'Royal MCP is currently disabled.' ] );
                exit;
            }
        }

        // Short-circuit self-check probes from Well_Known_Notice::check_register_301().
        // Reaching this point means the rewrite resolved (so there's no host-side 301 to
        // detect); return 204 No Content without invoking OAuth\Server so we don't pollute
        // the Activity Log with a synthetic "register failed" entry every 12 hours.
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        if ( 'Royal MCP Self-Check' === $ua && in_array( $action, [ 'register', 'authorize', 'token' ], true ) ) {
            status_header( 204 );
            header( 'Cache-Control: no-store, no-cache, must-revalidate, private' );
            exit;
        }

        $oauth_server = new Royal_MCP\OAuth\Server();
        $oauth_server->dispatch( $action );
        // dispatch() calls exit, but just in case:
        exit;
    }

    public function init() {
        // Text domain is automatically loaded by WordPress 4.6+ for plugins hosted on WordPress.org
        // No need to call load_plugin_textdomain() manually

        // Endpoint tool-profile filter — trims tools/list by ?tools=<profile>.
        Royal_MCP\MCP\Tool_Profiles::register();

        // Initialize components
        if (is_admin()) {
            new Royal_MCP\Admin\Settings_Page();
            new Royal_MCP\Admin\Well_Known_Notice();
        }
    }

    public function register_rest_routes() {
        $api = new Royal_MCP\API\REST_Controller();
        $api->register_routes();
    }

    public function register_mcp_endpoint() {
        $server = new Royal_MCP\MCP\Server();

        // Streamable HTTP endpoint.
        // Single endpoint for all MCP communication - no SSE connection needed
        // MCP protocol requires public REST endpoints — auth enforced inside
        // Server::validate_auth() on every request (API key or Bearer token).
        // @security-ignore WP-AUTH-001 — verified: auth on all code paths in Server.php
        register_rest_route('royal-mcp/v1', '/mcp', [
            'methods' => ['GET', 'POST', 'DELETE', 'OPTIONS'],
            'callback' => [$server, 'handle_mcp'],
            'permission_callback' => '__return_true', // @security-ignore — auth in validate_auth()
        ]);

        // Also register at namespace root path — Claude Desktop may post to /wp-json/royal-mcp/v1
        // when it strips the last path segment from the configured MCP URL.
        // @security-ignore WP-AUTH-001 — same handler as above
        register_rest_route('royal-mcp', '/v1', [
            'methods' => ['GET', 'POST', 'DELETE', 'OPTIONS'],
            'callback' => [$server, 'handle_mcp'],
            'permission_callback' => '__return_true', // @security-ignore — auth in validate_auth()
        ]);

        // LEGACY: SSE endpoint (deprecated, returns redirect info)
        // @security-ignore WP-AUTH-001 — deprecated, returns error message only
        register_rest_route('royal-mcp/v1', '/sse', [
            'methods' => 'GET',
            'callback' => [$server, 'handle_sse'],
            'permission_callback' => '__return_true', // @security-ignore — deprecated endpoint
        ]);

        // LEGACY: Messages endpoint (forwards to new handler with full auth)
        // @security-ignore WP-AUTH-001 — forwards to handle_mcp() which has validate_auth()
        register_rest_route('royal-mcp/v1', '/messages', [
            'methods' => 'POST',
            'callback' => [$server, 'handle_message'],
            'permission_callback' => '__return_true', // @security-ignore — auth in validate_auth()
        ]);
    }
}

// Initialize the plugin
function royal_mcp_init() {
    return Royal_MCP_Plugin::get_instance();
}

// Start the plugin
royal_mcp_init();

endif; // ! class_exists( 'Royal_MCP_Plugin', false )
