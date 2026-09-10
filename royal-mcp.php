<?php
/**
 * Plugin Name: Royal MCP – Secure AI Connector for Claude, ChatGPT & any LLM via MCP
 * Plugin URI: https://royalplugins.com/support/royal-mcp/
 * Description: Integrate Model Context Protocol (MCP) servers with WordPress to enable LLM interactions with your site
 * Version: 1.5.1
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

// Pro vendors this class — bail if it's already declared, refuse activation cleanly.
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

// Define plugin constants. Guards prevent PHP "constant already defined"
// warnings when this file is loaded as Pro's vendored Free copy — Pro
// defines the same constants first, then requires this file. Without the
// guards each MCP request produces 4 warnings + 4 nginx error-log stack
// traces, which on shared PHP-FPM pools amplifies into cross-site worker
// starvation.
defined( 'ROYAL_MCP_VERSION' )          || define( 'ROYAL_MCP_VERSION', '1.5.1' );
defined( 'ROYAL_MCP_PLUGIN_DIR' )       || define( 'ROYAL_MCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
defined( 'ROYAL_MCP_PLUGIN_URL' )       || define( 'ROYAL_MCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
defined( 'ROYAL_MCP_PLUGIN_FILE' )      || define( 'ROYAL_MCP_PLUGIN_FILE', __FILE__ );
defined( 'ROYAL_MCP_PLUGIN_BASENAME' )  || define( 'ROYAL_MCP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

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

// MUST wrap in class_exists gate — PHP hoists top-level declarations at parse time.
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

        // Force Cache-Control: no-store on every response in our namespace.
        add_filter('rest_post_dispatch', [$this, 'force_no_store_on_namespace'], 10, 3);

        // RFC 8288 Link header pointing at the agent-readiness discovery
        // documents. Emitted on every /mcp response (canonical REST route +
        // /mcp root alias + namespace-root alias) so any agent runtime that
        // hits the MCP endpoint discovers the server-card + agent-skills
        // + OAuth authorization-server without extra probing.
        add_filter('rest_post_dispatch', [$this, 'add_agent_readiness_link_headers'], 10, 3);

        // Re-force jsonrpc="2.0" pre-encode; some transformers float-cast it to "2".
        add_filter('rest_pre_echo_response', [$this, 'force_jsonrpc_version'], 999, 3);

        // OAuth 2.0 endpoints (served at domain root, not under /wp-json/).
        add_action('init', [$this, 'register_oauth_rewrites']);
        add_filter('query_vars', [$this, 'register_oauth_query_vars']);
        add_action('parse_request', [$this, 'handle_oauth_request']);

        // Rewrite-flush trigger. When ROYAL_MCP_VERSION advances (e.g. 1.5.0
        // → 1.5.1 adds the /mcp root alias), upgraders who never
        // deactivate/reactivate would otherwise never see the new rule land
        // in WP's cached rewrite_rules option. Runs on admin_init so the
        // flush cost stays off the frontend request path.
        add_action('admin_init', [$this, 'maybe_flush_rewrites']);

        // WebMCP bootstrap-shim JS — enqueued on the frontend only when the
        // Browser Agents toggle is ON and the visitor is logged in. Runs at
        // wp_enqueue_scripts priority 1 so it lands in <head> before any
        // Cloudflare-injected bridge script that expects to POST to /mcp.
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_webmcp_bootstrap'], 1);

        // Strip GET/HEAD rewrites for POST-only endpoints (/register, /token) so browser visits fall through.
        add_filter('option_rewrite_rules', [__CLASS__, 'strip_oauth_get_only_rules']);

        // Scheduled token cleanup.
        add_action('royal_mcp_token_cleanup', [\Royal_MCP\OAuth\Token_Store::class, 'cleanup_expired']);
        add_action('royal_mcp_token_cleanup', [\Royal_MCP\MCP\Undo_Store::class, 'cleanup_expired']);

        // sessions cleanup rides on the same daily cron action.
        add_action('royal_mcp_token_cleanup', [\Royal_MCP\MCP\Session_Store::class, 'cleanup_expired']);

        // Add plugin action links (Settings, Docs)
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);

        // Elementor MCP module coexistence notice — render callback checks detection.
        \Royal_MCP\Integrations\Elementor_Coexistence::register_hooks();

        // Preview_Link redirect handler for rmcp_preview token URLs.
        \Royal_MCP\MCP\Support\Preview_Link::register();


        // Royal Plugins Chrome Pack: header/footer/submenu on Royal MCP admin screens only.
        require_once ROYAL_MCP_PLUGIN_DIR . 'includes/chrome/class-royal-mcp-chrome.php';
        require_once ROYAL_MCP_PLUGIN_DIR . 'includes/chrome/class-royal-mcp-whats-new.php';
        \Royal_MCP\Chrome\Royal_MCP_Chrome::get_instance();
        \Royal_MCP\Chrome\Whats_New::instance();

        // WordPress Abilities API registration (WP 6.9+). Categories hook fires before
        // abilities hook; registering an ability against a non-registered category throws.
        if ( function_exists( 'wp_register_ability_category' ) && (bool) get_option( 'royal_mcp_abilities_registration_enabled', true ) ) {
            add_action( 'wp_abilities_api_categories_init', array( \Royal_MCP\Abilities\Categories::class, 'register' ) );
            add_action( 'wp_abilities_api_init', array( \Royal_MCP\Abilities\Registrar::class, 'register' ) );

            // MCP Adapter: own named server, explicit ability list, no auto-enroll on default server.
            if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
                add_action( 'mcp_adapter_init', array( \Royal_MCP\Abilities\MCP_Adapter_Server::class, 'register' ) );
            }
        }
    }

    /**
     * Emit RFC 8288 Link header pointing at the three agent-readiness
     * discovery documents from every /mcp endpoint response. Signals to
     * scanners + agent runtimes where to find the MCP server card,
     * agent-skills index, and OAuth authorization-server metadata without
     * requiring them to guess the well-known paths.
     */
    public function add_agent_readiness_link_headers( $response, $server, $request ) {
        if ( ! $response instanceof \WP_REST_Response ) {
            return $response;
        }
        $route = $request->get_route();
        if ( ! is_string( $route ) ) {
            return $response;
        }
        // Only /mcp endpoint routes get the Link header — the well-known
        // documents themselves don't need to self-reference.
        $mcp_routes = [ '/royal-mcp/v1/mcp', '/royal-mcp/v1', '/royal-mcp/v1/messages' ];
        if ( ! in_array( $route, $mcp_routes, true ) ) {
            return $response;
        }

        $home  = rtrim( (string) home_url(), '/' );
        $links = [
            '<' . $home . '/.well-known/mcp/server-card.json>; rel="mcp-server-card"',
            '<' . $home . '/.well-known/agent-skills/index.json>; rel="agent-skills"',
            '<' . $home . '/.well-known/oauth-authorization-server>; rel="oauth-authorization-server"',
        ];
        $response->header( 'Link', implode( ', ', $links ) );

        return $response;
    }

    /** Force no-store cache headers on every response in the royal-mcp namespace. */
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

    /** Re-force jsonrpc="2.0" pre-encode on responses in the royal-mcp namespace. */
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
        // Refuse activation if Pro is active; skip refusal when Pro is bootstrapping Free.
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

        // Create OAuth tables. Force-load fallback: autoloader may not have fired yet on activation.
        if ( class_exists( '\Royal_MCP\OAuth\Token_Store' ) ) {
            \Royal_MCP\OAuth\Token_Store::create_tables();
        } else {
            $token_store_file = ROYAL_MCP_PLUGIN_DIR . 'includes/OAuth/Token_Store.php';
            if ( file_exists( $token_store_file ) ) {
                require_once $token_store_file;
                \Royal_MCP\OAuth\Token_Store::create_tables();
            }
        }

        // Create sessions table. Same force-load fallback as Token_Store.
        if ( class_exists( '\Royal_MCP\MCP\Session_Store' ) ) {
            \Royal_MCP\MCP\Session_Store::create_tables();
        } else {
            $session_store_file = ROYAL_MCP_PLUGIN_DIR . 'includes/MCP/Session_Store.php';
            if ( file_exists( $session_store_file ) ) {
                require_once $session_store_file;
                \Royal_MCP\MCP\Session_Store::create_tables();
            }
        }

        // Set default options. API key: lowercase hex avoids O/0 I/l/1 transcription ambiguity.
        add_option('royal_mcp_settings', [
            'enabled' => false,
            'platforms' => [],
            'mcp_servers' => [],
            'api_key' => bin2hex(random_bytes(16)),
        ]);

        // Register OAuth + /mcp alias rewrite rules before flushing.
        $this->register_oauth_rewrites();

        // Flush rewrite rules
        flush_rewrite_rules();

        // Mark rewrite-version current so maybe_flush_rewrites() no-ops on
        // the first admin_init after activation.
        update_option( 'royal_mcp_rewrite_version', ROYAL_MCP_VERSION );

        // Schedule daily token cleanup.
        if ( ! wp_next_scheduled( 'royal_mcp_token_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'royal_mcp_token_cleanup' );
        }

        // Mark schema as current so the runtime migration check is a no-op for fresh installs.
        update_option('royal_mcp_db_version', ROYAL_MCP_VERSION);
    }

    /**
     * Runtime schema check — heals installs where db_version lags the plugin version.
     *
     * INVARIANT: db_version only advances when every required migration ran AND
     * required_tables_exist() confirms the tables physically exist.
     *
     * Retry-loop guard: when a CREATE TABLE fails (e.g. host-side schema
     * incompatibility), remember the failure and skip re-running for
     * DAY_IN_SECONDS. Without this the same failing DDL would fire on every
     * request, flooding error logs. Guard state is a single option that
     * gets cleared as soon as a run succeeds, so admins who fix the
     * underlying issue see recovery on their next request.
     */
    public function maybe_upgrade_db() {
        if (get_option('royal_mcp_db_version') === ROYAL_MCP_VERSION
            && $this->required_tables_exist()) {
            return;
        }

        $last_failed = (int) get_option('royal_mcp_db_upgrade_last_failed_at', 0);
        if ($last_failed > 0 && (time() - $last_failed) < DAY_IN_SECONDS) {
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

        if ($token_store_ok && $session_store_ok && $this->required_tables_exist()) {
            update_option('royal_mcp_db_version', ROYAL_MCP_VERSION);
            delete_option('royal_mcp_db_upgrade_last_failed_at');
        } else {
            update_option('royal_mcp_db_upgrade_last_failed_at', time());
        }
    }

    /** Verify core OAuth + session tables physically exist; backstop for maybe_upgrade_db(). */
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
     * OAuth endpoint URL slugs, filterable via royal_mcp_oauth_rewrite_paths.
     *
     * Shape: [ action => slug ]. Action matches the royal_mcp_oauth query var.
     * metadata() reads from the same source so discovery matches what's served.
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
        add_rewrite_rule( '\.well-known/oauth-authorization-server/mcp/?$', 'index.php?royal_mcp_oauth=metadata_mcp', 'top' );
        add_rewrite_rule( '\.well-known/oauth-authorization-server/?$', 'index.php?royal_mcp_oauth=metadata', 'top' );
        foreach ( self::get_oauth_rewrite_paths() as $action => $slug ) {
            $slug = ltrim( trim( (string) $slug ), '/' );
            if ( $slug === '' ) continue;
            add_rewrite_rule( $slug . '/?$', 'index.php?royal_mcp_oauth=' . $action, 'top' );
        }

        // Root-path /mcp alias — served through WordPress's built-in REST
        // bootstrap by rewriting to rest_route. The Cloudflare WebMCP bridge
        // default target is /mcp on the origin with no customer-configurable
        // override, so the alias is required for zero-config browser-agent
        // access. Rewrite (not redirect) preserves POST method + JSON body +
        // Authorization header through the same PHP request.
        add_rewrite_rule( '^mcp/?$', 'index.php?rest_route=/royal-mcp/v1/mcp', 'top' );

        // Agent-readiness discovery documents. Root .well-known paths dispatched
        // through the corresponding REST route so response shape + Content-Type
        // stay under WP_REST_Response control. These are what Cloudflare's
        // Agent Readiness scanner, Vercel is-agentic, and Chrome Lighthouse
        // 13.3+ Agentic Browsing audit look for at stable paths.
        add_rewrite_rule( '\.well-known/mcp/server-card\.json$',       'index.php?rest_route=/royal-mcp/v1/discovery/server-card', 'top' );
        add_rewrite_rule( '\.well-known/agent-skills/index\.json$',    'index.php?rest_route=/royal-mcp/v1/discovery/agent-skills', 'top' );
    }

    /**
     * Redirect GET/HEAD requests to POST-only OAuth endpoints (/register,
     * /token) to a 405 Method Not Allowed handler instead of letting them
     * fall through to a bare WordPress 404. 405 is the spec-correct
     * response ("endpoint exists, wrong method") and gives probing MCP
     * clients an Allow header they can act on.
     *
     * MUST hook option_rewrite_rules, NOT rewrite_rules_array — the latter
     * feeds update_option() during a flush and would persist the rewrite.
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
                $rules[ $rule_key ] = 'index.php?royal_mcp_oauth=method_not_allowed&royal_mcp_endpoint=' . $action;
            }
        }
        return $rules;
    }

    /**
     * Enqueue the WebMCP bootstrap-shim JS on the frontend when:
     *   1. The Browser Agents (WebMCP) master toggle is ON
     *   2. The current visitor is logged in (nonces are only meaningful for
     *      authenticated users; anonymous visitors have no session to
     *      authorize against anyway)
     *
     * The shim monkey-patches window.fetch to inject an X-WP-Nonce header
     * onto /mcp calls, giving the Cloudflare WebMCP bridge (which never
     * sends a nonce itself) the auth material Royal MCP requires on the
     * cookie-auth path.
     */
    public function maybe_enqueue_webmcp_bootstrap() {
        $settings = get_option( 'royal_mcp_settings', [] );
        if ( empty( $settings['webmcp_enabled'] ) ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            return;
        }

        wp_enqueue_script(
            'royal-mcp-webmcp-bootstrap',
            ROYAL_MCP_PLUGIN_URL . 'assets/js/webmcp-bootstrap.js',
            [],
            ROYAL_MCP_VERSION,
            false // in head, not footer — must run before the CF bridge script
        );
        wp_localize_script(
            'royal-mcp-webmcp-bootstrap',
            'royalMcpWebMcp',
            [
                // 'wp_rest' action is load-bearing — WordPress's own REST
                // cookie-auth (rest_cookie_check_errors) validates X-WP-Nonce
                // against this specific action before dispatching to any
                // REST handler. Any other action name would fail WP's check
                // with rest_cookie_invalid_nonce before Royal MCP sees the
                // request.
                'nonce' => wp_create_nonce( 'wp_rest' ),
            ]
        );
    }

    /**
     * Re-register OAuth + /mcp alias rewrites and flush WordPress's cached
     * rewrite_rules option when the stored rewrite-version lags the plugin
     * version. Runs at admin_init so the flush happens on the next admin
     * page load an upgrader visits, without cost on frontend requests.
     */
    public function maybe_flush_rewrites() {
        if ( get_option( 'royal_mcp_rewrite_version' ) === ROYAL_MCP_VERSION ) {
            return;
        }
        $this->register_oauth_rewrites();
        flush_rewrite_rules( false );
        update_option( 'royal_mcp_rewrite_version', ROYAL_MCP_VERSION );
    }

    /**
     * Register the query variable used by OAuth rewrite rules.
     */
    public function register_oauth_query_vars( $vars ) {
        $vars[] = 'royal_mcp_oauth';
        $vars[] = 'royal_mcp_endpoint';
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

        // Short-circuit self-check probes to avoid synthetic OAuth log entries.
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
        // Endpoint tool-profile filter — trims tools/list by ?tools=<profile>.
        Royal_MCP\MCP\Tool_Profiles::register();

        // Initialize components
        if (is_admin()) {
            new Royal_MCP\Admin\Settings_Page();
            new Royal_MCP\Admin\Well_Known_Notice();
            new Royal_MCP\Admin\Help_Page();
        }
    }

    public function register_rest_routes() {
        $api = new Royal_MCP\API\REST_Controller();
        $api->register_routes();
    }

    public function register_mcp_endpoint() {
        $server = new Royal_MCP\MCP\Server();

        // Streamable HTTP endpoint. Auth enforced in Server::validate_auth() on every request.
        // @security-ignore WP-AUTH-001 — verified: auth on all code paths in Server.php
        register_rest_route('royal-mcp/v1', '/mcp', [
            'methods' => ['GET', 'POST', 'DELETE', 'OPTIONS'],
            'callback' => [$server, 'handle_mcp'],
            'permission_callback' => '__return_true', // @security-ignore — auth in validate_auth()
        ]);

        // Namespace-root alias — some clients POST to /wp-json/royal-mcp/v1.
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

        // Agent-readiness discovery documents. Public — served at root
        // .well-known paths via rewrite rules (see register_oauth_rewrites),
        // dispatched through REST for a stable Content-Type + WP_REST_Response
        // response shape. No auth required: categories + counts only, no
        // tool names or schemas leak.
        register_rest_route('royal-mcp/v1', '/discovery/server-card', [
            'methods' => 'GET',
            'callback' => [ \Royal_MCP\Discovery\Server_Card::class, 'handle_request' ],
            'permission_callback' => '__return_true', // @security-ignore WP-AUTH-001 — intentionally public discovery document
        ]);
        register_rest_route('royal-mcp/v1', '/discovery/agent-skills', [
            'methods' => 'GET',
            'callback' => [ \Royal_MCP\Discovery\Agent_Skills_Index::class, 'handle_request' ],
            'permission_callback' => '__return_true', // @security-ignore WP-AUTH-001 — intentionally public discovery document
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
