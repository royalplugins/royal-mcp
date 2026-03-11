<?php
/**
 * Plugin Name: Royal MCP
 * Plugin URI: https://royalplugins.com/support/royal-mcp/
 * Description: Integrate Model Context Protocol (MCP) servers with WordPress to enable LLM interactions with your site
 * Version: 1.2.2
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
define('ROYAL_MCP_VERSION', '1.2.2');
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

        add_action('plugins_loaded', [$this, 'init']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('rest_api_init', [$this, 'register_mcp_endpoint']);

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

        // Set default options
        add_option('royal_mcp_settings', [
            'enabled' => false,
            'platforms' => [],
            'mcp_servers' => [],
            'api_key' => wp_generate_password(32, false),
        ]);

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    public function deactivate() {
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
        // Note: permission_callback is __return_true because MCP protocol requires public endpoints.
        // Authentication is handled inside handlers via session management and Origin validation.
        // See Server::validate_origin() for security controls.
        register_rest_route('royal-mcp/v1', '/mcp', [
            'methods' => ['GET', 'POST', 'DELETE', 'OPTIONS'],
            'callback' => [$server, 'handle_mcp'],
            'permission_callback' => '__return_true', // Intentionally public - auth handled in callback
        ]);

        // LEGACY: SSE endpoint (deprecated, returns redirect info)
        // Note: Public endpoint per MCP protocol requirements
        register_rest_route('royal-mcp/v1', '/sse', [
            'methods' => 'GET',
            'callback' => [$server, 'handle_sse'],
            'permission_callback' => '__return_true', // Intentionally public - deprecated endpoint
        ]);

        // LEGACY: Messages endpoint (forwards to new handler)
        // Note: Public endpoint per MCP protocol requirements - auth in callback
        register_rest_route('royal-mcp/v1', '/messages', [
            'methods' => 'POST',
            'callback' => [$server, 'handle_message'],
            'permission_callback' => '__return_true', // Intentionally public - auth handled in callback
        ]);
    }
}

// Initialize the plugin
function royal_mcp_init() {
    return Royal_MCP_Plugin::get_instance();
}

// Start the plugin
royal_mcp_init();
