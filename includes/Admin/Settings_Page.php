<?php
namespace Royal_MCP\Admin;

use Royal_MCP\Platform\Registry;

if (!defined('ABSPATH')) {
    exit;
}

class Settings_Page {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_filter('admin_footer_text', [$this, 'admin_footer_text']);

        // AJAX handlers
        add_action('wp_ajax_royal_mcp_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_royal_mcp_get_platform_fields', [$this, 'ajax_get_platform_fields']);
    }

    public function add_menu_page() {
        add_menu_page(
            __('Royal MCP Settings', 'royal-mcp'),
            __('Royal MCP', 'royal-mcp'),
            'manage_options',
            'royal-mcp',
            [$this, 'render_settings_page'],
            'dashicons-networking',
            80
        );

        add_submenu_page(
            'royal-mcp',
            __('Settings', 'royal-mcp'),
            __('Settings', 'royal-mcp'),
            'manage_options',
            'royal-mcp',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'royal-mcp',
            __('Activity Log', 'royal-mcp'),
            __('Activity Log', 'royal-mcp'),
            'manage_options',
            'royal-mcp-logs',
            [$this, 'render_logs_page']
        );

    }

    public function register_settings() {
        register_setting('royal_mcp_settings_group', 'royal_mcp_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings($input) {
        $sanitized = [];
        $settings = get_option('royal_mcp_settings', []);

        $sanitized['enabled'] = isset($input['enabled']) ? (bool) $input['enabled'] : false;

        // Sanitize API key
        if (isset($input['api_key']) && !empty($input['api_key'])) {
            $sanitized['api_key'] = sanitize_text_field($input['api_key']);
        } elseif (isset($input['regenerate_api_key'])) {
            $sanitized['api_key'] = wp_generate_password(32, false);
        } else {
            $sanitized['api_key'] = $settings['api_key'] ?? wp_generate_password(32, false);
        }

        // Sanitize OAuth settings
        if (isset($input['oauth_client_id']) && !empty($input['oauth_client_id'])) {
            $sanitized['oauth_client_id'] = sanitize_text_field($input['oauth_client_id']);
        } else {
            $sanitized['oauth_client_id'] = $settings['oauth_client_id'] ?? '';
        }

        if (isset($input['oauth_client_secret']) && !empty($input['oauth_client_secret'])) {
            $sanitized['oauth_client_secret'] = sanitize_text_field($input['oauth_client_secret']);
        } else {
            $sanitized['oauth_client_secret'] = $settings['oauth_client_secret'] ?? '';
        }

        // Sanitize AI Platforms (new structure)
        $sanitized['platforms'] = [];
        if (isset($input['platforms']) && is_array($input['platforms'])) {
            foreach ($input['platforms'] as $index => $platform_config) {
                if (empty($platform_config['platform'])) {
                    continue;
                }

                $platform_id = sanitize_text_field($platform_config['platform']);
                $platform = Registry::get_platform($platform_id);

                if (!$platform) {
                    continue;
                }

                $sanitized_platform = [
                    'platform' => $platform_id,
                    'enabled' => isset($platform_config['enabled']) ? (bool) $platform_config['enabled'] : true,
                ];

                // Sanitize each field based on platform configuration
                foreach ($platform['fields'] as $field_id => $field_config) {
                    if (isset($platform_config[$field_id])) {
                        switch ($field_config['type']) {
                            case 'url':
                                $sanitized_platform[$field_id] = esc_url_raw($platform_config[$field_id]);
                                break;
                            case 'password':
                            case 'text':
                            case 'select':
                            default:
                                $sanitized_platform[$field_id] = sanitize_text_field($platform_config[$field_id]);
                                break;
                        }
                    } elseif (isset($field_config['default'])) {
                        $sanitized_platform[$field_id] = $field_config['default'];
                    }
                }

                $sanitized['platforms'][] = $sanitized_platform;
            }
        }

        // Legacy: Also keep mcp_servers for backward compatibility
        $sanitized['mcp_servers'] = [];
        if (isset($input['mcp_servers']) && is_array($input['mcp_servers'])) {
            foreach ($input['mcp_servers'] as $server) {
                if (!empty($server['name']) && !empty($server['url'])) {
                    $sanitized['mcp_servers'][] = [
                        'name' => sanitize_text_field($server['name']),
                        'url' => esc_url_raw($server['url']),
                        'api_key' => sanitize_text_field($server['api_key'] ?? ''),
                        'enabled' => isset($server['enabled']) ? (bool) $server['enabled'] : true,
                    ];
                }
            }
        }

        return $sanitized;
    }

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'royal-mcp') === false) {
            return;
        }

        wp_enqueue_style(
            'royal-mcp-admin',
            ROYAL_MCP_PLUGIN_URL . 'assets/css/admin.css',
            [],
            ROYAL_MCP_VERSION
        );

        wp_enqueue_script(
            'royal-mcp-admin',
            ROYAL_MCP_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            ROYAL_MCP_VERSION,
            true
        );

        // Get platform data for JavaScript
        $platforms = Registry::get_platforms();
        $platform_groups = Registry::get_platform_groups();

        wp_localize_script('royal-mcp-admin', 'royalMcp', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('royal_mcp_nonce'),
            'restUrl' => rest_url('royal-mcp/v1/'),
            'platforms' => $platforms,
            'platformGroups' => $platform_groups,
            'strings' => [
                'selectPlatform' => esc_html__('Select a platform...', 'royal-mcp'),
                'testConnection' => esc_html__('Test Connection', 'royal-mcp'),
                'testing' => esc_html__('Testing...', 'royal-mcp'),
                'connectionSuccess' => esc_html__('Connection successful!', 'royal-mcp'),
                'connectionFailed' => esc_html__('Connection failed', 'royal-mcp'),
                'removePlatform' => esc_html__('Remove', 'royal-mcp'),
                'getApiKey' => esc_html__('Get API Key', 'royal-mcp'),
                'documentation' => esc_html__('Documentation', 'royal-mcp'),
                'confirmRemove' => esc_html__('Are you sure you want to remove this platform?', 'royal-mcp'),
            ],
        ]);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option('royal_mcp_settings', [
            'enabled' => false,
            'platforms' => [],
            'mcp_servers' => [],
            'api_key' => wp_generate_password(32, false),
        ]);

        $platforms = Registry::get_platforms();
        $platform_groups = Registry::get_platform_groups();

        include ROYAL_MCP_PLUGIN_DIR . 'templates/admin/settings.php';
    }

    public function render_logs_page() {
        if (!current_user_can('manage_options')) {
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
     * AJAX handler for testing platform connections
     */
    public function ajax_test_connection() {
        check_ajax_referer('royal_mcp_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Unauthorized', 'royal-mcp')]);
        }

        $platform_id = isset($_POST['platform']) ? sanitize_text_field(wp_unslash($_POST['platform'])) : '';
        $config = [];

        // Get config from POST data
        if (isset($_POST['config']) && is_array($_POST['config'])) {
            $posted_config = map_deep(wp_unslash($_POST['config']), 'sanitize_text_field');
            foreach ($posted_config as $key => $value) {
                $config[sanitize_text_field($key)] = sanitize_text_field($value);
            }
        }

        if (empty($platform_id)) {
            wp_send_json_error(['message' => esc_html__('No platform selected', 'royal-mcp')]);
        }

        $result = Registry::test_connection($platform_id, $config);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler to get platform field HTML
     */
    public function ajax_get_platform_fields() {
        check_ajax_referer('royal_mcp_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Unauthorized', 'royal-mcp')]);
        }

        $platform_id = isset($_POST['platform']) ? sanitize_text_field(wp_unslash($_POST['platform'])) : '';
        $index = isset($_POST['index']) ? absint($_POST['index']) : 0;

        $platform = Registry::get_platform($platform_id);

        if (!$platform) {
            wp_send_json_error(['message' => esc_html__('Invalid platform', 'royal-mcp')]);
        }

        ob_start();
        $this->render_platform_fields($platform, $index);
        $html = ob_get_clean();

        wp_send_json_success([
            'html' => $html,
            'platform' => $platform,
        ]);
    }

    /**
     * Render platform-specific fields
     */
    public function render_platform_fields($platform, $index, $values = []) {
        foreach ($platform['fields'] as $field_id => $field) {
            $field_name = "royal_mcp_settings[platforms][{$index}][{$field_id}]";
            $field_value = $values[$field_id] ?? ($field['default'] ?? '');
            $required = !empty($field['required']) ? 'required' : '';
            ?>
            <tr class="platform-field platform-field-<?php echo esc_attr($field_id); ?>">
                <th scope="row">
                    <label for="platform-<?php echo esc_attr($index); ?>-<?php echo esc_attr($field_id); ?>">
                        <?php echo esc_html($field['label']); ?>
                        <?php if (!empty($field['required'])) : ?>
                            <span class="required">*</span>
                        <?php endif; ?>
                    </label>
                </th>
                <td>
                    <?php
                    switch ($field['type']) {
                        case 'select':
                            ?>
                            <select
                                name="<?php echo esc_attr($field_name); ?>"
                                id="platform-<?php echo esc_attr($index); ?>-<?php echo esc_attr($field_id); ?>"
                                class="regular-text"
                                <?php echo esc_attr($required); ?>
                                data-field="<?php echo esc_attr($field_id); ?>"
                            >
                                <?php foreach ($field['options'] as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($field_value, $value); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php
                            break;

                        case 'password':
                            ?>
                            <input
                                type="password"
                                name="<?php echo esc_attr($field_name); ?>"
                                id="platform-<?php echo esc_attr($index); ?>-<?php echo esc_attr($field_id); ?>"
                                value="<?php echo esc_attr($field_value); ?>"
                                class="regular-text"
                                placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>"
                                <?php echo esc_attr($required); ?>
                                data-field="<?php echo esc_attr($field_id); ?>"
                                autocomplete="new-password"
                            >
                            <button type="button" class="button toggle-password" title="<?php esc_attr_e('Show/Hide', 'royal-mcp'); ?>">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                            <?php
                            break;

                        case 'url':
                            ?>
                            <input
                                type="url"
                                name="<?php echo esc_attr($field_name); ?>"
                                id="platform-<?php echo esc_attr($index); ?>-<?php echo esc_attr($field_id); ?>"
                                value="<?php echo esc_attr($field_value); ?>"
                                class="regular-text"
                                placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>"
                                <?php echo esc_attr($required); ?>
                                data-field="<?php echo esc_attr($field_id); ?>"
                            >
                            <?php
                            break;

                        case 'text':
                        default:
                            ?>
                            <input
                                type="text"
                                name="<?php echo esc_attr($field_name); ?>"
                                id="platform-<?php echo esc_attr($index); ?>-<?php echo esc_attr($field_id); ?>"
                                value="<?php echo esc_attr($field_value); ?>"
                                class="regular-text"
                                placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>"
                                <?php echo esc_attr($required); ?>
                                data-field="<?php echo esc_attr($field_id); ?>"
                            >
                            <?php
                            break;
                    }

                    if (!empty($field['help'])) :
                        ?>
                        <p class="description"><?php echo esc_html($field['help']); ?></p>
                        <?php
                    endif;
                    ?>
                </td>
            </tr>
            <?php
        }
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
