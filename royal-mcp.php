<?php
/**
 * Plugin Name: Royal MCP – Secure AI Connector for Claude, ChatGPT & Gemini
 * Plugin URI: https://royalplugins.com/support/royal-mcp/
 * Description: Integrate Model Context Protocol (MCP) servers with WordPress to enable LLM interactions with your site
 * Version: 1.4.12
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

// Define plugin constants
define('ROYAL_MCP_VERSION', '1.4.12');
define('ROYAL_MCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ROYAL_MCP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ROYAL_MCP_PLUGIN_FILE', __FILE__);

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

        // OAuth 2.0 endpoints (served at domain root, not under /wp-json/).
        add_action('init', [$this, 'register_oauth_rewrites']);
        add_filter('query_vars', [$this, 'register_oauth_query_vars']);
        add_action('parse_request', [$this, 'handle_oauth_request']);

        // Scheduled token cleanup.
        add_action('royal_mcp_token_cleanup', [\Royal_MCP\OAuth\Token_Store::class, 'cleanup_expired']);

        // Add plugin action links (Settings, Docs)
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);
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

        // Set default options
        add_option('royal_mcp_settings', [
            'enabled' => false,
            'platforms' => [],
            'mcp_servers' => [],
            'api_key' => wp_generate_password(32, false),
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
     */
    public function maybe_upgrade_db() {
        if (get_option('royal_mcp_db_version') === ROYAL_MCP_VERSION) {
            return;
        }

        if (class_exists('\Royal_MCP\OAuth\Token_Store')) {
            \Royal_MCP\OAuth\Token_Store::create_tables();
            update_option('royal_mcp_db_version', ROYAL_MCP_VERSION);
        }
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
     * Register rewrite rules for OAuth endpoints at domain root.
     */
    public function register_oauth_rewrites() {
        add_rewrite_rule( '\.well-known/oauth-protected-resource(/.*)?$', 'index.php?royal_mcp_oauth=protected_resource', 'top' );
        add_rewrite_rule( '\.well-known/oauth-authorization-server$', 'index.php?royal_mcp_oauth=metadata', 'top' );
        add_rewrite_rule( 'authorize$', 'index.php?royal_mcp_oauth=authorize', 'top' );
        add_rewrite_rule( 'token$', 'index.php?royal_mcp_oauth=token', 'top' );
        add_rewrite_rule( 'register$', 'index.php?royal_mcp_oauth=register', 'top' );
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

        $oauth_server = new Royal_MCP\OAuth\Server();
        $oauth_server->dispatch( $action );
        // dispatch() calls exit, but just in case:
        exit;
    }

    public function init() {
        // Text domain is automatically loaded by WordPress 4.6+ for plugins hosted on WordPress.org
        // No need to call load_plugin_textdomain() manually

        // Initialize components
        if (is_admin()) {
            new Royal_MCP\Admin\Settings_Page();
        }
    }

    public function register_rest_routes() {
        $api = new Royal_MCP\API\REST_Controller();
        $api->register_routes();
    }

    public function register_mcp_endpoint() {
        $server = new Royal_MCP\MCP\Server();

        // NEW: Streamable HTTP endpoint (2025-03-26 spec)
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
