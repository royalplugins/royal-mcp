<?php
if (!defined('ABSPATH')) {
    exit;
}

use Royal_MCP\Platform\Registry;

$royal_mcp_settings = isset($settings) ? $settings : get_option('royal_mcp_settings', []);
$royal_mcp_platforms = isset($platforms) ? $platforms : Registry::get_platforms();
$royal_mcp_platform_groups = isset($royal_mcp_platform_groups) ? $royal_mcp_platform_groups : Registry::get_platform_groups();
$royal_mcp_configured_platforms = $royal_mcp_settings['platforms'] ?? [];
?>

<div class="wrap royal-mcp-settings">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <div class="royal-mcp-docs-banner">
        <span class="dashicons dashicons-book-alt"></span>
        <span><?php esc_html_e('Need help getting started?', 'royal-mcp'); ?></span>
        <a href="https://royalplugins.com/support/royal-mcp/" target="_blank" class="button button-primary">
            <?php esc_html_e('View Documentation', 'royal-mcp'); ?>
            <span class="dashicons dashicons-external" style="margin-top: 3px;"></span>
        </a>
    </div>

    <?php settings_errors(); ?>

    <form method="post" action="options.php" id="royal-mcp-settings-form">
        <?php settings_fields('royal_mcp_settings_group'); ?>

        <div class="royal-mcp-settings-container">
            <!-- General Settings -->
            <div class="postbox">
                <div class="postbox-header">
                    <h2><?php esc_html_e('General Settings', 'royal-mcp'); ?></h2>
                </div>
                <div class="inside">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="enabled"><?php esc_html_e('Enable Royal MCP Integration', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <label class="switch">
                                    <input type="checkbox"
                                           name="royal_mcp_settings[enabled]"
                                           id="enabled"
                                           value="1"
                                           <?php checked(isset($royal_mcp_settings['enabled']) && $royal_mcp_settings['enabled']); ?>>
                                    <span class="slider"></span>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When enabled, AI platforms can interact with your WordPress site via the configured connections', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="api_key"><?php esc_html_e('WordPress API Key', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       name="royal_mcp_settings[api_key]"
                                       id="api_key"
                                       value="<?php echo esc_attr($royal_mcp_settings['api_key'] ?? ''); ?>"
                                       class="regular-text code"
                                       readonly>
                                <button type="button" class="button" id="copy-api-key">
                                    <?php esc_html_e('Copy', 'royal-mcp'); ?>
                                </button>
                                <button type="submit"
                                        name="royal_mcp_settings[regenerate_api_key]"
                                        value="1"
                                        class="button"
                                        onclick="return confirm('<?php esc_attr_e('Are you sure? This will invalidate the current API key.', 'royal-mcp'); ?>');">
                                    <?php esc_html_e('Regenerate', 'royal-mcp'); ?>
                                </button>
                                <p class="description">
                                    <?php esc_html_e('Use this API key to authenticate requests from AI platforms to your WordPress site', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('REST API Base URL', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       value="<?php echo esc_attr(rest_url('royal-mcp/v1/')); ?>"
                                       class="regular-text code"
                                       readonly>
                                <button type="button" class="button" id="copy-rest-url">
                                    <?php esc_html_e('Copy', 'royal-mcp'); ?>
                                </button>
                                <p class="description">
                                    <?php esc_html_e('Use this URL as the base for all API requests', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- AI Platforms -->
            <div class="postbox">
                <div class="postbox-header">
                    <h2><?php esc_html_e('AI Platforms', 'royal-mcp'); ?></h2>
                </div>
                <div class="inside">
                    <p class="description">
                        <?php esc_html_e('Configure AI platforms to connect with your WordPress site. Select a platform to see its specific configuration options.', 'royal-mcp'); ?>
                    </p>

                    <div id="platforms-list">
                        <?php
                        if (empty($royal_mcp_configured_platforms)) {
                            // Show empty state with add button
                            ?>
                            <div class="platform-empty-state">
                                <div class="empty-icon">
                                    <span class="dashicons dashicons-cloud"></span>
                                </div>
                                <h3><?php esc_html_e('No AI Platforms Configured', 'royal-mcp'); ?></h3>
                                <p><?php esc_html_e('Add your first AI platform to get started.', 'royal-mcp'); ?></p>
                            </div>
                            <?php
                        } else {
                            foreach ($royal_mcp_configured_platforms as $royal_mcp_index => $royal_mcp_platform_config) :
                                $royal_mcp_platform_id = $royal_mcp_platform_config['platform'] ?? '';
                                $royal_mcp_platform = Registry::get_platform($royal_mcp_platform_id);
                                if (!$royal_mcp_platform) continue;
                            ?>
                            <div class="platform-item" data-index="<?php echo esc_attr($royal_mcp_index); ?>" data-platform="<?php echo esc_attr($royal_mcp_platform_id); ?>">
                                <div class="platform-header">
                                    <div class="platform-info">
                                        <span class="platform-icon" style="background-color: <?php echo esc_attr($royal_mcp_platform['color']); ?>">
                                            <?php echo esc_html(substr($royal_mcp_platform['label'], 0, 1)); ?>
                                        </span>
                                        <div class="platform-details">
                                            <h3 class="platform-name"><?php echo esc_html($royal_mcp_platform['label']); ?></h3>
                                            <span class="platform-description"><?php echo esc_html($royal_mcp_platform['description']); ?></span>
                                        </div>
                                    </div>
                                    <div class="platform-actions">
                                        <label class="switch small">
                                            <input type="checkbox"
                                                   name="royal_mcp_settings[platforms][<?php echo esc_attr($royal_mcp_index); ?>][enabled]"
                                                   value="1"
                                                   <?php checked($royal_mcp_platform_config['enabled'] ?? true); ?>>
                                            <span class="slider"></span>
                                        </label>
                                        <button type="button" class="button platform-toggle">
                                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                                        </button>
                                        <button type="button" class="button remove-platform">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </div>
                                </div>
                                <div class="platform-config" style="display: none;">
                                    <input type="hidden"
                                           name="royal_mcp_settings[platforms][<?php echo esc_attr($royal_mcp_index); ?>][platform]"
                                           value="<?php echo esc_attr($royal_mcp_platform_id); ?>">

                                    <table class="form-table platform-fields">
                                        <?php
                                        foreach ($royal_mcp_platform['fields'] as $royal_mcp_field_id => $royal_mcp_field) :
                                            $royal_mcp_field_name = "royal_mcp_settings[platforms][{$royal_mcp_index}][{$royal_mcp_field_id}]";
                                            $royal_mcp_field_value = $royal_mcp_platform_config[$royal_mcp_field_id] ?? ($royal_mcp_field['default'] ?? '');
                                        ?>
                                        <tr class="platform-field platform-field-<?php echo esc_attr($royal_mcp_field_id); ?>">
                                            <th scope="row">
                                                <label for="platform-<?php echo esc_attr($royal_mcp_index); ?>-<?php echo esc_attr($royal_mcp_field_id); ?>">
                                                    <?php echo esc_html($royal_mcp_field['label']); ?>
                                                    <?php if (!empty($royal_mcp_field['required'])) : ?>
                                                        <span class="required">*</span>
                                                    <?php endif; ?>
                                                </label>
                                            </th>
                                            <td>
                                                <?php
                                                switch ($royal_mcp_field['type']) {
                                                    case 'select':
                                                        ?>
                                                        <select
                                                            name="<?php echo esc_attr($royal_mcp_field_name); ?>"
                                                            id="platform-<?php echo esc_attr($royal_mcp_index); ?>-<?php echo esc_attr($royal_mcp_field_id); ?>"
                                                            class="regular-text"
                                                            data-field="<?php echo esc_attr($royal_mcp_field_id); ?>"
                                                        >
                                                            <?php foreach ($royal_mcp_field['options'] as $royal_mcp_value => $royal_mcp_label) : ?>
                                                                <option value="<?php echo esc_attr($royal_mcp_value); ?>" <?php selected($royal_mcp_field_value, $royal_mcp_value); ?>>
                                                                    <?php echo esc_html($royal_mcp_label); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <?php
                                                        break;

                                                    case 'password':
                                                        ?>
                                                        <input
                                                            type="password"
                                                            name="<?php echo esc_attr($royal_mcp_field_name); ?>"
                                                            id="platform-<?php echo esc_attr($royal_mcp_index); ?>-<?php echo esc_attr($royal_mcp_field_id); ?>"
                                                            value="<?php echo esc_attr($royal_mcp_field_value); ?>"
                                                            class="regular-text"
                                                            placeholder="<?php echo esc_attr($royal_mcp_field['placeholder'] ?? ''); ?>"
                                                            data-field="<?php echo esc_attr($royal_mcp_field_id); ?>"
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
                                                            name="<?php echo esc_attr($royal_mcp_field_name); ?>"
                                                            id="platform-<?php echo esc_attr($royal_mcp_index); ?>-<?php echo esc_attr($royal_mcp_field_id); ?>"
                                                            value="<?php echo esc_attr($royal_mcp_field_value); ?>"
                                                            class="regular-text"
                                                            placeholder="<?php echo esc_attr($royal_mcp_field['placeholder'] ?? ''); ?>"
                                                            data-field="<?php echo esc_attr($royal_mcp_field_id); ?>"
                                                        >
                                                        <?php
                                                        break;

                                                    case 'text':
                                                    default:
                                                        ?>
                                                        <input
                                                            type="text"
                                                            name="<?php echo esc_attr($royal_mcp_field_name); ?>"
                                                            id="platform-<?php echo esc_attr($royal_mcp_index); ?>-<?php echo esc_attr($royal_mcp_field_id); ?>"
                                                            value="<?php echo esc_attr($royal_mcp_field_value); ?>"
                                                            class="regular-text"
                                                            placeholder="<?php echo esc_attr($royal_mcp_field['placeholder'] ?? ''); ?>"
                                                            data-field="<?php echo esc_attr($royal_mcp_field_id); ?>"
                                                        >
                                                        <?php
                                                        break;
                                                }

                                                if (!empty($royal_mcp_field['help'])) :
                                                ?>
                                                <p class="description"><?php echo esc_html($royal_mcp_field['help']); ?></p>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </table>

                                    <div class="platform-footer">
                                        <div class="platform-links">
                                            <?php if (!empty($royal_mcp_platform['api_key_url'])) : ?>
                                            <a href="<?php echo esc_url($royal_mcp_platform['api_key_url']); ?>" target="_blank" class="button button-link">
                                                <span class="dashicons dashicons-external"></span>
                                                <?php esc_html_e('Get API Key', 'royal-mcp'); ?>
                                            </a>
                                            <?php endif; ?>
                                            <?php if (!empty($royal_mcp_platform['docs_url'])) : ?>
                                            <a href="<?php echo esc_url($royal_mcp_platform['docs_url']); ?>" target="_blank" class="button button-link">
                                                <span class="dashicons dashicons-book"></span>
                                                <?php esc_html_e('Documentation', 'royal-mcp'); ?>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="platform-test">
                                            <button type="button" class="button test-connection">
                                                <span class="dashicons dashicons-update"></span>
                                                <?php esc_html_e('Test Connection', 'royal-mcp'); ?>
                                            </button>
                                            <span class="connection-status"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach;
                        }
                        ?>
                    </div>

                    <div class="add-platform-section">
                        <div class="add-platform-dropdown">
                            <select id="add-platform-select">
                                <option value=""><?php esc_html_e('Select a platform to add...', 'royal-mcp'); ?></option>
                                <?php foreach ($royal_mcp_platform_groups as $royal_mcp_group_id => $royal_mcp_group) : ?>
                                <optgroup label="<?php echo esc_attr($royal_mcp_group['label']); ?>">
                                    <?php foreach ($royal_mcp_group['platforms'] as $royal_mcp_pid) :
                                        $royal_mcp_p = $royal_mcp_platforms[$royal_mcp_pid] ?? null;
                                        if (!$royal_mcp_p) continue;
                                    ?>
                                    <option value="<?php echo esc_attr($royal_mcp_pid); ?>" data-color="<?php echo esc_attr($royal_mcp_p['color']); ?>">
                                        <?php echo esc_html($royal_mcp_p['label']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="button button-primary" id="add-platform-btn">
                                <span class="dashicons dashicons-plus-alt2"></span>
                                <?php esc_html_e('Add Platform', 'royal-mcp'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Claude Connector Settings - Only shown when Claude platform is configured -->
            <?php
            // Check if Claude platform is configured
            $royal_mcp_has_claude = false;
            foreach ($royal_mcp_configured_platforms as $royal_mcp_platform_config) {
                if (($royal_mcp_platform_config['platform'] ?? '') === 'claude') {
                    $royal_mcp_has_claude = true;
                    break;
                }
            }
            ?>
            <div class="postbox connector-settings-box" id="claude-connector-settings" style="<?php echo $royal_mcp_has_claude ? '' : 'display: none;'; ?>">
                <div class="postbox-header">
                    <h2>
                        <span class="dashicons dashicons-admin-links" style="margin-right: 8px;"></span>
                        <?php esc_html_e('Claude Connector Settings', 'royal-mcp'); ?>
                        <span class="beta-badge"><?php esc_html_e('For claude.ai', 'royal-mcp'); ?></span>
                    </h2>
                </div>
                <div class="inside">
                    <p class="description connector-intro">
                        <?php esc_html_e('Use these settings to add your WordPress site as a custom connector in Claude.ai. Go to Claude.ai Settings > Connectors > Add custom connector.', 'royal-mcp'); ?>
                    </p>

                    <div class="connector-fields">
                        <div class="connector-field">
                            <label><?php esc_html_e('Remote MCP Server URL', 'royal-mcp'); ?></label>
                            <?php
                            // Streamable HTTP endpoint (2025-03-26 spec)
                            $royal_mcp_url = rest_url('royal-mcp/v1/mcp');
                            // Force HTTPS for Claude connector (required)
                            $royal_mcp_url_https = preg_replace('/^http:/', 'https:', $royal_mcp_url);
                            $royal_mcp_is_localhost = strpos($royal_mcp_url, 'localhost') !== false || strpos($royal_mcp_url, '127.0.0.1') !== false;
                            ?>
                            <div class="connector-input-group">
                                <input type="text"
                                       id="mcp-server-url"
                                       value="<?php echo esc_attr($royal_mcp_url_https); ?>"
                                       class="large-text code"
                                       readonly>
                                <button type="button" class="button copy-btn" data-target="mcp-server-url">
                                    <span class="dashicons dashicons-clipboard"></span>
                                    <?php esc_html_e('Copy', 'royal-mcp'); ?>
                                </button>
                            </div>
                            <?php if ($royal_mcp_is_localhost) : ?>
                            <p class="description" style="color: #d63638;">
                                <span class="dashicons dashicons-warning" style="font-size: 16px; width: 16px; height: 16px;"></span>
                                <?php esc_html_e('Claude requires a publicly accessible HTTPS URL. Localhost URLs will not work. Deploy your site with SSL first.', 'royal-mcp'); ?>
                            </p>
                            <?php else : ?>
                            <p class="description"><?php esc_html_e('Paste this URL in the "Remote MCP server URL" field in Claude', 'royal-mcp'); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="connector-advanced">
                            <button type="button" class="button-link toggle-advanced">
                                <span class="dashicons dashicons-arrow-down-alt2"></span>
                                <?php esc_html_e('Advanced settings (OAuth)', 'royal-mcp'); ?>
                            </button>

                            <div class="advanced-fields" style="display: none;">
                                <div class="connector-field">
                                    <label for="oauth_client_id"><?php esc_html_e('OAuth Client ID', 'royal-mcp'); ?> <span class="optional">(<?php esc_html_e('optional', 'royal-mcp'); ?>)</span></label>
                                    <div class="connector-input-group">
                                        <input type="text"
                                               name="royal_mcp_settings[oauth_client_id]"
                                               id="oauth_client_id"
                                               value="<?php echo esc_attr($royal_mcp_settings['oauth_client_id'] ?? ''); ?>"
                                               class="regular-text code"
                                               placeholder="<?php esc_attr_e('Leave empty to use API key auth', 'royal-mcp'); ?>">
                                        <button type="button" class="button copy-btn" data-target="oauth_client_id">
                                            <span class="dashicons dashicons-clipboard"></span>
                                        </button>
                                        <?php if (empty($royal_mcp_settings['oauth_client_id'])) : ?>
                                        <button type="button" class="button generate-oauth" data-field="oauth_client_id">
                                            <?php esc_html_e('Generate', 'royal-mcp'); ?>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="connector-field">
                                    <label for="oauth_client_secret"><?php esc_html_e('OAuth Client Secret', 'royal-mcp'); ?> <span class="optional">(<?php esc_html_e('optional', 'royal-mcp'); ?>)</span></label>
                                    <div class="connector-input-group">
                                        <input type="password"
                                               name="royal_mcp_settings[oauth_client_secret]"
                                               id="oauth_client_secret"
                                               value="<?php echo esc_attr($royal_mcp_settings['oauth_client_secret'] ?? ''); ?>"
                                               class="regular-text code"
                                               placeholder="<?php esc_attr_e('Leave empty to use API key auth', 'royal-mcp'); ?>">
                                        <button type="button" class="button toggle-password">
                                            <span class="dashicons dashicons-visibility"></span>
                                        </button>
                                        <button type="button" class="button copy-btn" data-target="oauth_client_secret">
                                            <span class="dashicons dashicons-clipboard"></span>
                                        </button>
                                        <?php if (empty($royal_mcp_settings['oauth_client_secret'])) : ?>
                                        <button type="button" class="button generate-oauth" data-field="oauth_client_secret">
                                            <?php esc_html_e('Generate', 'royal-mcp'); ?>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <p class="description"><?php esc_html_e('OAuth is optional. If not configured, use your WordPress API Key for authentication.', 'royal-mcp'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="connector-help">
                        <h4><?php esc_html_e('Quick Setup Guide', 'royal-mcp'); ?></h4>
                        <ol>
                            <li><?php echo wp_kses( __( 'Go to <a href="https://claude.ai" target="_blank">claude.ai</a> and open Settings', 'royal-mcp' ), array( 'a' => array( 'href' => array(), 'target' => array() ) ) ); ?></li>
                            <li><?php esc_html_e('Click on "Connectors" in the sidebar', 'royal-mcp'); ?></li>
                            <li><?php esc_html_e('Click "Add custom connector"', 'royal-mcp'); ?></li>
                            <li><?php esc_html_e('Enter a name (e.g., "My WordPress Site")', 'royal-mcp'); ?></li>
                            <li><?php esc_html_e('Paste the Remote MCP Server URL from above', 'royal-mcp'); ?></li>
                            <li><?php esc_html_e('Click "Add" to save the connector', 'royal-mcp'); ?></li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- API Endpoints Reference -->
            <div class="postbox">
                <div class="postbox-header">
                    <h2><?php esc_html_e('Available API Endpoints', 'royal-mcp'); ?></h2>
                </div>
                <div class="inside">
                    <div class="api-endpoints-reference">
                        <h3><?php esc_html_e('Posts', 'royal-mcp'); ?></h3>
                        <ul>
                            <li><code>GET /posts</code> - <?php esc_html_e('List posts', 'royal-mcp'); ?></li>
                            <li><code>GET /posts/{id}</code> - <?php esc_html_e('Get a specific post', 'royal-mcp'); ?></li>
                            <li><code>POST /posts</code> - <?php esc_html_e('Create a new post', 'royal-mcp'); ?></li>
                            <li><code>PUT /posts/{id}</code> - <?php esc_html_e('Update a post', 'royal-mcp'); ?></li>
                            <li><code>DELETE /posts/{id}</code> - <?php esc_html_e('Delete a post', 'royal-mcp'); ?></li>
                        </ul>

                        <h3><?php esc_html_e('Pages', 'royal-mcp'); ?></h3>
                        <ul>
                            <li><code>GET /pages</code> - <?php esc_html_e('List pages', 'royal-mcp'); ?></li>
                            <li><code>GET /pages/{id}</code> - <?php esc_html_e('Get a specific page', 'royal-mcp'); ?></li>
                            <li><code>POST /pages</code> - <?php esc_html_e('Create a new page', 'royal-mcp'); ?></li>
                            <li><code>PUT /pages/{id}</code> - <?php esc_html_e('Update a page', 'royal-mcp'); ?></li>
                            <li><code>DELETE /pages/{id}</code> - <?php esc_html_e('Delete a page', 'royal-mcp'); ?></li>
                        </ul>

                        <h3><?php esc_html_e('Media', 'royal-mcp'); ?></h3>
                        <ul>
                            <li><code>GET /media</code> - <?php esc_html_e('List media files', 'royal-mcp'); ?></li>
                            <li><code>GET /media/{id}</code> - <?php esc_html_e('Get a specific media file', 'royal-mcp'); ?></li>
                            <li><code>POST /media</code> - <?php esc_html_e('Upload media', 'royal-mcp'); ?></li>
                            <li><code>DELETE /media/{id}</code> - <?php esc_html_e('Delete media', 'royal-mcp'); ?></li>
                        </ul>

                        <h3><?php esc_html_e('Site & Search', 'royal-mcp'); ?></h3>
                        <ul>
                            <li><code>GET /site</code> - <?php esc_html_e('Get site information', 'royal-mcp'); ?></li>
                            <li><code>GET /search</code> - <?php esc_html_e('Search content', 'royal-mcp'); ?></li>
                        </ul>

                        <p class="description">
                            <?php esc_html_e('All requests must include the API key in the X-Royal-MCP-API-Key header.', 'royal-mcp'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <?php submit_button(); ?>
    </form>
</div>

<!-- Platform Item Template -->
<script type="text/template" id="platform-item-template">
    <div class="platform-item" data-index="{{index}}" data-platform="{{platform_id}}">
        <div class="platform-header">
            <div class="platform-info">
                <span class="platform-icon" style="background-color: {{color}}">
                    {{icon_letter}}
                </span>
                <div class="platform-details">
                    <h3 class="platform-name">{{label}}</h3>
                    <span class="platform-description">{{description}}</span>
                </div>
            </div>
            <div class="platform-actions">
                <label class="switch small">
                    <input type="checkbox"
                           name="royal_mcp_settings[platforms][{{index}}][enabled]"
                           value="1"
                           checked>
                    <span class="slider"></span>
                </label>
                <button type="button" class="button platform-toggle">
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                </button>
                <button type="button" class="button remove-platform">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </div>
        </div>
        <div class="platform-config">
            <input type="hidden"
                   name="royal_mcp_settings[platforms][{{index}}][platform]"
                   value="{{platform_id}}">

            <table class="form-table platform-fields">
                {{fields_html}}
            </table>

            <div class="platform-footer">
                <div class="platform-links">
                    {{#api_key_url}}
                    <a href="{{api_key_url}}" target="_blank" class="button button-link">
                        <span class="dashicons dashicons-external"></span>
                        <?php esc_html_e('Get API Key', 'royal-mcp'); ?>
                    </a>
                    {{/api_key_url}}
                    {{#docs_url}}
                    <a href="{{docs_url}}" target="_blank" class="button button-link">
                        <span class="dashicons dashicons-book"></span>
                        <?php esc_html_e('Documentation', 'royal-mcp'); ?>
                    </a>
                    {{/docs_url}}
                </div>
                <div class="platform-test">
                    <button type="button" class="button test-connection">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Test Connection', 'royal-mcp'); ?>
                    </button>
                    <span class="connection-status"></span>
                </div>
            </div>
        </div>
    </div>
</script>
