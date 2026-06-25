<?php
if (!defined('ABSPATH')) {
    exit;
}

use Royal_MCP\Platform\Registry;

$royal_mcp_settings = isset($settings) ? $settings : get_option('royal_mcp_settings', []);
$royal_mcp_platforms = isset($platforms) ? $platforms : Registry::get_platforms();
$royal_mcp_platform_groups = isset($royal_mcp_platform_groups) ? $royal_mcp_platform_groups : Registry::get_platform_groups();
$royal_mcp_configured_platforms = $royal_mcp_settings['platforms'] ?? [];

// The MCP endpoint — same URL for every MCP client (Claude.ai, ChatGPT,
// Claude Desktop, Cursor, Gemini, etc.). Forced HTTPS because MCP backends
// reject plain HTTP origins.
$royal_mcp_url = rest_url('royal-mcp/v1/mcp');
$royal_mcp_url_https = preg_replace('/^http:/', 'https:', $royal_mcp_url);
$royal_mcp_is_localhost = strpos($royal_mcp_url, 'localhost') !== false || strpos($royal_mcp_url, '127.0.0.1') !== false;
$royal_mcp_rest_base = rest_url('royal-mcp/v1/');
?>

<div class="wrap royal-mcp-settings">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php \Royal_MCP\Admin\Settings_Page::render_review_banner(); ?>

    <?php settings_errors(); ?>

    <form method="post" action="options.php" id="royal-mcp-settings-form">
        <?php settings_fields('royal_mcp_settings_group'); ?>

        <div class="royal-mcp-settings-container">

            <!-- ==========================================================
                 General Settings
                 ========================================================== -->
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
                                    <?php esc_html_e('When enabled, AI clients can connect to your WordPress site via the MCP Server URL below.', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="allow_option_writes"><?php esc_html_e('Allow AI to write WordPress options', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <label class="switch">
                                    <input type="checkbox"
                                           name="royal_mcp_settings[allow_option_writes]"
                                           id="allow_option_writes"
                                           value="1"
                                           <?php checked(!empty($royal_mcp_settings['allow_option_writes'])); ?>>
                                    <span class="slider"></span>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When enabled, AI agents can write to allowlisted WordPress options via the wp_update_option tool. Sensitive options (siteurl, secret keys, license keys, etc.) are permanently denylisted regardless of this setting. Plugin authors opt their settings in via the royal_mcp_writable_options filter.', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="allow_theme_writes"><?php esc_html_e('Allow AI to modify theme appearance', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <label class="switch">
                                    <input type="checkbox"
                                           name="royal_mcp_settings[allow_theme_writes]"
                                           id="allow_theme_writes"
                                           value="1"
                                           <?php checked(!empty($royal_mcp_settings['allow_theme_writes'])); ?>>
                                    <span class="slider"></span>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When enabled, AI agents can update theme customizer settings (theme_mods) and the active theme\'s custom CSS. Theme mod writes also require the mod name to be in the allowlist (extend via the royal_mcp_writable_theme_mods filter — default allowlist is empty, opt-in only). Custom CSS is filtered through wp_kses so script tags are stripped.', 'royal-mcp'); ?>
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
                                    <span class="dashicons dashicons-clipboard"></span>
                                    <?php esc_html_e('Copy', 'royal-mcp'); ?>
                                </button>
                                <button type="submit"
                                        name="royal_mcp_settings[regenerate_api_key]"
                                        value="1"
                                        class="button"
                                        id="rmcp-regenerate-key">
                                    <span class="dashicons dashicons-update"></span>
                                    <?php esc_html_e('Regenerate', 'royal-mcp'); ?>
                                </button>
                                <p class="description">
                                    <?php esc_html_e('Use this key in MCP clients that don\'t support OAuth (e.g., Claude Desktop config, raw REST calls). Most modern MCP clients negotiate auth automatically via OAuth.', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <!-- Prominent MCP Server URL block -->
                    <div class="mcp-url-block">
                        <label for="mcp-server-url" class="mcp-url-label">
                            <span class="dashicons dashicons-admin-links"></span>
                            <?php esc_html_e('MCP Server URL', 'royal-mcp'); ?>
                        </label>
                        <div class="mcp-url-input-group">
                            <input type="text"
                                   id="mcp-server-url"
                                   value="<?php echo esc_attr($royal_mcp_url_https); ?>"
                                   class="large-text code"
                                   readonly>
                            <button type="button" class="button button-primary copy-btn" data-target="mcp-server-url">
                                <span class="dashicons dashicons-clipboard"></span>
                                <?php esc_html_e('Copy', 'royal-mcp'); ?>
                            </button>
                        </div>
                        <p class="mcp-url-hint">
                            <?php esc_html_e('The canonical URL for connecting any MCP-compatible client to this site. The same URL works for Claude.ai, ChatGPT, Claude Desktop, Cursor, Gemini, and any other MCP host. See setup guides below.', 'royal-mcp'); ?>
                        </p>

                        <?php if ($royal_mcp_is_localhost) : ?>
                            <div class="cloudflare-warning warning-error">
                                <span class="dashicons dashicons-warning"></span>
                                <p>
                                    <strong><?php esc_html_e('Localhost URL detected.', 'royal-mcp'); ?></strong>
                                    <?php esc_html_e('MCP clients require a publicly accessible HTTPS URL. This URL will only work for local testing — deploy your site with SSL before connecting from Claude.ai, ChatGPT, etc.', 'royal-mcp'); ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <div class="cloudflare-warning">
                            <span class="dashicons dashicons-shield-alt"></span>
                            <p>
                                <strong><?php esc_html_e('Behind Cloudflare?', 'royal-mcp'); ?></strong>
                                <?php esc_html_e('Turn off "Block AI Bots" in your Cloudflare Security settings — it blocks every MCP backend (Claude, ChatGPT, others) from completing the handshake. Enabled by default on new Cloudflare domains.', 'royal-mcp'); ?>
                            </p>
                        </div>
                    </div>

                    <!-- Advanced (REST API base + OAuth credentials) -->
                    <button type="button" class="advanced-toggle" id="general-advanced-toggle" aria-expanded="false">
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                        <?php esc_html_e('Advanced (Legacy REST API base URL, manual OAuth credentials)', 'royal-mcp'); ?>
                    </button>
                    <div class="advanced-content" id="general-advanced-content" hidden>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label><?php esc_html_e('Legacy REST API Base URL', 'royal-mcp'); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           value="<?php echo esc_attr($royal_mcp_rest_base); ?>"
                                           class="regular-text code"
                                           id="rest-api-url"
                                           readonly>
                                    <button type="button" class="button" id="copy-rest-url">
                                        <span class="dashicons dashicons-clipboard"></span>
                                        <?php esc_html_e('Copy', 'royal-mcp'); ?>
                                    </button>
                                    <p class="description">
                                        <?php esc_html_e('For per-endpoint REST integrations (older style: GET /posts, POST /media, etc., authenticated with the X-Royal-MCP-API-Key header). Most users should ignore this and connect via the MCP Server URL above.', 'royal-mcp'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="oauth_client_id"><?php esc_html_e('OAuth Client ID', 'royal-mcp'); ?> <span class="optional">(<?php esc_html_e('optional', 'royal-mcp'); ?>)</span></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="royal_mcp_settings[oauth_client_id]"
                                           id="oauth_client_id"
                                           value="<?php echo esc_attr($royal_mcp_settings['oauth_client_id'] ?? ''); ?>"
                                           class="regular-text code"
                                           placeholder="<?php esc_attr_e('Leave empty for Dynamic Client Registration', 'royal-mcp'); ?>">
                                    <button type="button" class="button copy-btn" data-target="oauth_client_id">
                                        <span class="dashicons dashicons-clipboard"></span>
                                    </button>
                                    <?php if (empty($royal_mcp_settings['oauth_client_id'])) : ?>
                                        <button type="button" class="button generate-oauth" data-field="oauth_client_id">
                                            <?php esc_html_e('Generate', 'royal-mcp'); ?>
                                        </button>
                                    <?php else : ?>
                                        <button type="button" class="button clear-oauth" data-field="oauth_client_id">
                                            <?php esc_html_e('Clear', 'royal-mcp'); ?>
                                        </button>
                                    <?php endif; ?>
                                    <p class="description">
                                        <?php esc_html_e('Optional — most MCP clients register themselves automatically via Dynamic Client Registration. Set a static Client ID only if your client requires it.', 'royal-mcp'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="oauth_client_secret"><?php esc_html_e('OAuth Client Secret', 'royal-mcp'); ?> <span class="optional">(<?php esc_html_e('optional', 'royal-mcp'); ?>)</span></label>
                                </th>
                                <td>
                                    <input type="password"
                                           name="royal_mcp_settings[oauth_client_secret]"
                                           id="oauth_client_secret"
                                           value="<?php echo esc_attr($royal_mcp_settings['oauth_client_secret'] ?? ''); ?>"
                                           class="regular-text code"
                                           placeholder="<?php esc_attr_e('Leave empty for Dynamic Client Registration', 'royal-mcp'); ?>">
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
                                    <?php else : ?>
                                        <button type="button" class="button clear-oauth" data-field="oauth_client_secret">
                                            <?php esc_html_e('Clear', 'royal-mcp'); ?>
                                        </button>
                                    <?php endif; ?>
                                    <p class="description">
                                        <?php esc_html_e('Optional companion to OAuth Client ID. Leave blank unless your client requires it.', 'royal-mcp'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ==========================================================
                 MCP Client Setup Guides
                 ========================================================== -->
            <div class="postbox">
                <div class="postbox-header">
                    <h2><?php esc_html_e('MCP Client Setup Guides', 'royal-mcp'); ?></h2>
                </div>
                <div class="inside">
                    <p class="description setup-guides-intro">
                        <?php esc_html_e('Step-by-step instructions for connecting each MCP host to this site. All clients use the same MCP Server URL from General Settings above.', 'royal-mcp'); ?>
                    </p>

                    <div class="setup-guides-list">

                        <!-- Claude.ai (web) -->
                        <div class="setup-guide-item" data-guide="claude-web">
                            <button type="button" class="setup-guide-header" aria-expanded="false">
                                <span class="setup-guide-icon icon-claude">C</span>
                                <span class="setup-guide-name">
                                    <?php esc_html_e('Claude.ai (web)', 'royal-mcp'); ?>
                                    <small><?php esc_html_e('Connect via custom connector in Claude.ai Settings', 'royal-mcp'); ?></small>
                                </span>
                                <span class="dashicons dashicons-arrow-down-alt2 setup-guide-chevron"></span>
                            </button>
                            <div class="setup-guide-body">
                                <ol>
                                    <li><?php echo wp_kses(__('Go to <a href="https://claude.ai" target="_blank" rel="noopener noreferrer">claude.ai</a> and open <strong>Settings</strong>', 'royal-mcp'), ['a' => ['href' => [], 'target' => [], 'rel' => []], 'strong' => []]); ?></li>
                                    <li><?php echo wp_kses(__('Click <strong>Connectors</strong> in the sidebar', 'royal-mcp'), ['strong' => []]); ?></li>
                                    <li><?php echo wp_kses(__('Click <strong>Add custom connector</strong>', 'royal-mcp'), ['strong' => []]); ?></li>
                                    <li><?php esc_html_e('Enter a name (e.g., "My WordPress Site")', 'royal-mcp'); ?></li>
                                    <li><?php echo wp_kses(__('Paste the <strong>MCP Server URL</strong> from General Settings above', 'royal-mcp'), ['strong' => []]); ?></li>
                                    <li><?php echo wp_kses(__('Click <strong>Add</strong> — Claude will run the OAuth handshake automatically', 'royal-mcp'), ['strong' => []]); ?></li>
                                </ol>
                                <a href="https://royalplugins.com/support/royal-mcp/connecting-to-claude/" target="_blank" rel="noopener noreferrer" class="setup-guide-full-link">
                                    <?php esc_html_e('Full walkthrough with screenshots', 'royal-mcp'); ?>
                                    <span class="dashicons dashicons-external"></span>
                                </a>
                            </div>
                        </div>

                        <!-- ChatGPT -->
                        <div class="setup-guide-item" data-guide="chatgpt">
                            <button type="button" class="setup-guide-header" aria-expanded="false">
                                <span class="setup-guide-icon icon-chatgpt">O</span>
                                <span class="setup-guide-name">
                                    <?php esc_html_e('ChatGPT (Connectors)', 'royal-mcp'); ?>
                                    <small><?php esc_html_e('Connect via OpenAI ChatGPT custom connectors', 'royal-mcp'); ?></small>
                                </span>
                                <span class="dashicons dashicons-arrow-down-alt2 setup-guide-chevron"></span>
                            </button>
                            <div class="setup-guide-body">
                                <ol>
                                    <li><?php echo wp_kses(__('Open <a href="https://chatgpt.com" target="_blank" rel="noopener noreferrer">chatgpt.com</a> → <strong>Settings</strong> → <strong>Connectors</strong>', 'royal-mcp'), ['a' => ['href' => [], 'target' => [], 'rel' => []], 'strong' => []]); ?></li>
                                    <li><?php echo wp_kses(__('Click <strong>+ Add</strong> and choose <strong>Custom connector</strong> (or "MCP Server")', 'royal-mcp'), ['strong' => []]); ?></li>
                                    <li><?php esc_html_e('Enter a name (e.g., "My WordPress Site")', 'royal-mcp'); ?></li>
                                    <li><?php echo wp_kses(__('Paste the <strong>MCP Server URL</strong> from General Settings above', 'royal-mcp'), ['strong' => []]); ?></li>
                                    <li><?php esc_html_e('Authorize when prompted — ChatGPT will run the OAuth handshake against your WordPress site', 'royal-mcp'); ?></li>
                                    <li><?php esc_html_e('The connector becomes available across new ChatGPT conversations', 'royal-mcp'); ?></li>
                                </ol>
                                <a href="https://royalplugins.com/support/royal-mcp/connecting-to-chatgpt/" target="_blank" rel="noopener noreferrer" class="setup-guide-full-link">
                                    <?php esc_html_e('Full walkthrough with screenshots', 'royal-mcp'); ?>
                                    <span class="dashicons dashicons-external"></span>
                                </a>
                            </div>
                        </div>

                        <!-- Claude Desktop -->
                        <div class="setup-guide-item" data-guide="claude-desktop">
                            <button type="button" class="setup-guide-header" aria-expanded="false">
                                <span class="setup-guide-icon icon-claude-desktop">CD</span>
                                <span class="setup-guide-name">
                                    <?php esc_html_e('Claude Desktop', 'royal-mcp'); ?>
                                    <small><?php esc_html_e('Connect via stdio bridge using mcp-remote (requires Node.js)', 'royal-mcp'); ?></small>
                                </span>
                                <span class="dashicons dashicons-arrow-down-alt2 setup-guide-chevron"></span>
                            </button>
                            <div class="setup-guide-body">
                                <p>
                                    <?php esc_html_e('Claude Desktop uses a stdio bridge to talk to HTTPS MCP servers. The bridge is a small Node.js package called mcp-remote that wraps the connection.', 'royal-mcp'); ?>
                                </p>
                                <ol>
                                    <li><?php echo wp_kses(__('Install <a href="https://nodejs.org" target="_blank" rel="noopener noreferrer">Node.js</a> if not already installed', 'royal-mcp'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]); ?></li>
                                    <li><?php echo wp_kses(__('Open Claude Desktop → <strong>Settings</strong> → <strong>Developer</strong> → <strong>Edit Config</strong>', 'royal-mcp'), ['strong' => []]); ?></li>
                                    <li><?php echo wp_kses(__('Add a server entry pointing to your <strong>MCP Server URL</strong> + <strong>WordPress API Key</strong> from above:', 'royal-mcp'), ['strong' => []]); ?>
                                        <pre class="setup-guide-code-block"><code>{
  "mcpServers": {
    "royal-mcp": {
      "command": "npx",
      "args": [
        "-y", "mcp-remote",
        "<?php echo esc_html($royal_mcp_url_https); ?>",
        "--header",
        "X-Royal-MCP-API-Key:<?php echo esc_html($royal_mcp_settings['api_key'] ?? 'YOUR_API_KEY'); ?>"
      ]
    }
  }
}</code></pre>
                                    </li>
                                    <li><?php esc_html_e('Save the config file and restart Claude Desktop', 'royal-mcp'); ?></li>
                                    <li><?php esc_html_e('Royal MCP tools appear in your Claude Desktop tool list', 'royal-mcp'); ?></li>
                                </ol>
                                <a href="https://royalplugins.com/support/royal-mcp/connect-claude-desktop-api-key/" target="_blank" rel="noopener noreferrer" class="setup-guide-full-link">
                                    <?php esc_html_e('Full walkthrough with screenshots', 'royal-mcp'); ?>
                                    <span class="dashicons dashicons-external"></span>
                                </a>
                            </div>
                        </div>

                        <!-- Cursor -->
                        <div class="setup-guide-item" data-guide="cursor">
                            <button type="button" class="setup-guide-header" aria-expanded="false">
                                <span class="setup-guide-icon icon-cursor">CR</span>
                                <span class="setup-guide-name">
                                    <?php esc_html_e('Cursor', 'royal-mcp'); ?>
                                    <small><?php esc_html_e('Connect Cursor IDE to your WordPress site as an MCP tool', 'royal-mcp'); ?></small>
                                </span>
                                <span class="dashicons dashicons-arrow-down-alt2 setup-guide-chevron"></span>
                            </button>
                            <div class="setup-guide-body">
                                <ol>
                                    <li><?php echo wp_kses(__('Open Cursor → <strong>Settings</strong> → <strong>MCP</strong>', 'royal-mcp'), ['strong' => []]); ?></li>
                                    <li><?php echo wp_kses(__('Click <strong>+ Add new MCP server</strong>', 'royal-mcp'), ['strong' => []]); ?></li>
                                    <li><?php esc_html_e('Enter a name (e.g., "Royal MCP — My Site")', 'royal-mcp'); ?></li>
                                    <li><?php echo wp_kses(__('Paste the <strong>MCP Server URL</strong> from General Settings above', 'royal-mcp'), ['strong' => []]); ?></li>
                                    <li><?php echo wp_kses(__('Add an HTTP header: <code>X-Royal-MCP-API-Key</code> with your <strong>WordPress API Key</strong> as the value', 'royal-mcp'), ['strong' => [], 'code' => []]); ?></li>
                                    <li><?php esc_html_e('Save — Cursor connects automatically and Royal MCP tools become available', 'royal-mcp'); ?></li>
                                </ol>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- ==========================================================
                 Outbound AI Provider Configuration
                 (your WP site calling AI providers — distinct from
                 the inbound MCP server flow above)
                 ========================================================== -->
            <div class="postbox">
                <div class="postbox-header">
                    <h2><?php esc_html_e('Outbound AI Provider Configuration', 'royal-mcp'); ?></h2>
                </div>
                <div class="inside">
                    <div class="outbound-banner">
                        <span class="dashicons dashicons-info"></span>
                        <p>
                            <strong><?php esc_html_e('This section is for OUTBOUND calls only.', 'royal-mcp'); ?></strong>
                            <?php esc_html_e('Configure how this WordPress site calls AI providers (e.g., generating post content with ChatGPT, summarizing comments with Claude). To connect Claude, ChatGPT, or other tools TO this site as an MCP server, use the MCP Server URL in General Settings above — no configuration needed in this section.', 'royal-mcp'); ?>
                        </p>
                    </div>

                    <div id="platforms-list">
                        <?php
                        if (empty($royal_mcp_configured_platforms)) {
                            ?>
                            <div class="platform-empty-state">
                                <div class="empty-icon">
                                    <span class="dashicons dashicons-cloud"></span>
                                </div>
                                <h3><?php esc_html_e('No outbound AI providers configured', 'royal-mcp'); ?></h3>
                                <p><?php esc_html_e('Add a provider below to give this site outbound API access — purely optional, only needed if you want WordPress to call out to AI services.', 'royal-mcp'); ?></p>
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
                                        <button type="button" class="button platform-toggle" aria-label="<?php esc_attr_e('Expand / collapse', 'royal-mcp'); ?>">
                                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                                        </button>
                                        <button type="button" class="button remove-platform" aria-label="<?php esc_attr_e('Remove provider', 'royal-mcp'); ?>">
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
                                <option value=""><?php esc_html_e('Select a provider to add...', 'royal-mcp'); ?></option>
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
                                <?php esc_html_e('Add Provider', 'royal-mcp'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==========================================================
                 Available API Endpoints (reference)
                 ========================================================== -->
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
                            <?php esc_html_e('All legacy REST requests must include the API key in the X-Royal-MCP-API-Key header. Modern MCP clients negotiate auth via OAuth automatically.', 'royal-mcp'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <?php submit_button(); ?>
    </form>

    <!-- Troubleshooting / Reset OAuth State -->
    <div class="postbox royal-mcp-troubleshooting" style="margin-top: 20px;">
        <div class="postbox-header">
            <h2><?php esc_html_e('Troubleshooting', 'royal-mcp'); ?></h2>
        </div>
        <div class="inside">
            <h3><?php esc_html_e('Reset OAuth State', 'royal-mcp'); ?></h3>
            <p class="description">
                <?php esc_html_e('Wipes all registered OAuth clients, issued access/refresh tokens, and pending authorization codes. Use this when a Claude.ai, Claude Desktop, or ChatGPT connector gets stuck mid-handshake and won\'t complete authorization. Your Royal MCP settings, API key, and Activity Log are NOT affected.', 'royal-mcp'); ?>
            </p>
            <p class="description warning-text">
                <strong><?php esc_html_e('Warning:', 'royal-mcp'); ?></strong>
                <?php esc_html_e('All currently-connected MCP clients will need to re-authorize after running this. Only use this if you\'re actively troubleshooting a stuck connection.', 'royal-mcp'); ?>
            </p>
            <p>
                <button type="button"
                        class="button button-secondary"
                        id="royal-mcp-reset-oauth-state">
                    <span class="dashicons dashicons-trash"></span>
                    <?php esc_html_e('Reset OAuth State', 'royal-mcp'); ?>
                </button>
                <span id="royal-mcp-reset-oauth-state-status" style="margin-left: 10px;"></span>
            </p>
        </div>
    </div>

    <?php \Royal_MCP\Admin\Settings_Page::render_founders_banner(); ?>
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
                <button type="button" class="button platform-toggle" aria-label="<?php esc_attr_e('Expand / collapse', 'royal-mcp'); ?>">
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                </button>
                <button type="button" class="button remove-platform" aria-label="<?php esc_attr_e('Remove provider', 'royal-mcp'); ?>">
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
