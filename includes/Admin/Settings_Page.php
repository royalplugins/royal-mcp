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
        add_action('admin_init', [$this, 'maybe_dismiss_founders_banner']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_filter('admin_footer_text', [$this, 'admin_footer_text']);

        // AJAX handlers
        add_action('wp_ajax_royal_mcp_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_royal_mcp_get_platform_fields', [$this, 'ajax_get_platform_fields']);
        add_action('wp_ajax_royal_mcp_reset_oauth_state', [$this, 'ajax_reset_oauth_state']);
    }

    /**
     * Output the Royal Plugins Founders Bundle banner if the current user has
     * not dismissed it. Called from the Royal MCP admin templates only.
     */
    public static function render_founders_banner() {
        $user_id = get_current_user_id();
        if (!$user_id || get_user_meta($user_id, 'royal_mcp_founders_dismissed', true)) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            add_query_arg('royal_mcp_dismiss_founders', '1'),
            'royal_mcp_dismiss_founders'
        );

        $plugins = [
            ['icon' => 'shield-alt',  'name' => __('GuardPress Pro', 'royal-mcp'),       'url' => 'https://royalplugins.com/guardpress/'],
            ['icon' => 'superhero',   'name' => __('ForgeCache', 'royal-mcp'),           'url' => 'https://royalplugins.com/forgecache/'],
            ['icon' => 'database',    'name' => __('SiteVault Pro', 'royal-mcp'),        'url' => 'https://royalplugins.com/sitevault/'],
            ['icon' => 'chart-line',  'name' => __('SEObolt Pro', 'royal-mcp'),          'url' => 'https://royalplugins.com/seobolt/'],
            ['icon' => 'feedback',    'name' => __('FormForge Pro', 'royal-mcp'),        'url' => 'https://royalplugins.com/formforge/'],
            ['icon' => 'groups',      'name' => __('Royal Affiliate Pro', 'royal-mcp'),  'url' => 'https://royalplugins.com/royal-affiliates/'],
        ];
        ?>
        <div class="royal-mcp-founders-banner">
            <a href="<?php echo esc_url($dismiss_url); ?>" class="royal-mcp-founders-dismiss" aria-label="<?php esc_attr_e('Dismiss this notice', 'royal-mcp'); ?>">
                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
            </a>
            <div class="royal-mcp-founders-left">
                <span class="royal-mcp-founders-eyebrow">
                    <span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
                    <?php esc_html_e('Founders Pricing', 'royal-mcp'); ?>
                </span>
                <h2 class="royal-mcp-founders-title"><?php esc_html_e('Royal Plugins Founders Bundle', 'royal-mcp'); ?></h2>
                <p class="royal-mcp-founders-subtitle"><?php esc_html_e('All 6 premium plugins. Lifetime updates. One bundle, one purchase. Built by the team behind Royal MCP.', 'royal-mcp'); ?></p>
                <a href="https://royalplugins.com/founders/" target="_blank" rel="noopener noreferrer" class="royal-mcp-founders-cta-primary">
                    <?php esc_html_e('View bundle', 'royal-mcp'); ?>
                    <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
                </a>
            </div>
            <div class="royal-mcp-founders-grid">
                <?php foreach ($plugins as $plugin) : ?>
                    <a href="<?php echo esc_url($plugin['url']); ?>" target="_blank" rel="noopener noreferrer" class="royal-mcp-founders-grid-item">
                        <span class="dashicons dashicons-<?php echo esc_attr($plugin['icon']); ?>" aria-hidden="true"></span>
                        <span class="royal-mcp-founders-grid-item-name"><?php echo esc_html($plugin['name']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Persist a per-user dismissal of the Founders banner when the dismiss
     * link is followed. Validates capability and nonce, then redirects.
     */
    public function maybe_dismiss_founders_banner() {
        if (!isset($_GET['royal_mcp_dismiss_founders'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!isset($_GET['_wpnonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'royal_mcp_dismiss_founders')) {
            return;
        }

        update_user_meta(get_current_user_id(), 'royal_mcp_founders_dismissed', 1);

        wp_safe_redirect(remove_query_arg(['royal_mcp_dismiss_founders', '_wpnonce']));
        exit;
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
        $sanitized['allow_option_writes'] = isset($input['allow_option_writes']) ? (bool) $input['allow_option_writes'] : false;
        $sanitized['allow_theme_writes'] = isset($input['allow_theme_writes']) ? (bool) $input['allow_theme_writes'] : false;

        // Sanitize API key.
        // Order matters: the readonly `api_key` field in the settings form posts the
        // current value on every submit, so we must check `regenerate_api_key` FIRST.
        // Pre-1.4.15 the order was reversed and the regenerate button was silently
        // overridden — clicking Regenerate did nothing, customer kept the same key.
        if (isset($input['regenerate_api_key'])) {
            $sanitized['api_key'] = bin2hex(random_bytes(16));
        } elseif (isset($input['api_key']) && !empty($input['api_key'])) {
            $sanitized['api_key'] = sanitize_text_field($input['api_key']);
        } else {
            $sanitized['api_key'] = $settings['api_key'] ?? bin2hex(random_bytes(16));
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
                'confirmRegenerate' => esc_html__('Are you sure? This will invalidate the current API key.', 'royal-mcp'),
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
            'api_key' => bin2hex(random_bytes(16)),
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
     * AJAX handler — wipe all OAuth state (clients, tokens, in-flight auth codes).
     *
     * Used by the "Reset OAuth State" button on the settings page. Replaces the
     * wp-cli SQL recipe customers previously had to paste from support emails
     * when a Claude connector got stuck mid-handshake. All connected MCP clients
     * will need to re-authorize after this runs — that's the point.
     */
    public function ajax_reset_oauth_state() {
        check_ajax_referer('royal_mcp_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
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

        wp_send_json_success([
            'message' => sprintf(
                /* translators: 1: clients deleted, 2: tokens deleted, 3: auth codes deleted */
                esc_html__('OAuth state reset. Deleted %1$d clients, %2$d tokens, %3$d auth codes. Connected MCP clients will need to re-authorize.', 'royal-mcp'),
                (int) $counts['clients'],
                (int) $counts['tokens'],
                (int) $counts['auth_codes']
            ),
            'counts'  => $counts,
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
