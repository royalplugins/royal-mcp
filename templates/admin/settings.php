<?php
if (!defined('ABSPATH')) {
    exit;
}

$royal_mcp_settings = isset($settings) ? $settings : get_option('royal_mcp_settings', []);
$royal_mcp_abilities_raw = get_option('royal_mcp_abilities_registration_enabled', null);
$royal_mcp_abilities_registration_enabled = (null === $royal_mcp_abilities_raw || '' === $royal_mcp_abilities_raw) ? true : (bool) $royal_mcp_abilities_raw;

// The MCP endpoint — same URL for every MCP client (Claude.ai, ChatGPT,
// Claude Desktop, Cursor, Gemini, etc.). Forced HTTPS because MCP backends
// reject plain HTTP origins.
$royal_mcp_url = rest_url('royal-mcp/v1/mcp');
$royal_mcp_url_https = preg_replace('/^http:/', 'https:', $royal_mcp_url);
$royal_mcp_is_localhost = strpos($royal_mcp_url, 'localhost') !== false || strpos($royal_mcp_url, '127.0.0.1') !== false;

// One-shot reveal: after activation or clicking Regenerate, the plaintext key
// sits in a short-lived per-user transient. Read it and clear it in the same
// request so the same admin doesn't see it again on the next page load.
$royal_mcp_reveal_key_uid = (int) get_current_user_id();
$royal_mcp_reveal_key     = $royal_mcp_reveal_key_uid > 0
    ? get_transient( 'royal_mcp_reveal_api_key_' . $royal_mcp_reveal_key_uid )
    : false;
if ( is_string( $royal_mcp_reveal_key ) && '' !== $royal_mcp_reveal_key ) {
    delete_transient( 'royal_mcp_reveal_api_key_' . $royal_mcp_reveal_key_uid );
} else {
    $royal_mcp_reveal_key = '';
}
$royal_mcp_has_stored_key = ! empty( $royal_mcp_settings['api_key_hash'] ) || ! empty( $royal_mcp_settings['api_key'] );
$royal_mcp_api_key_field  = '' !== $royal_mcp_reveal_key
    ? $royal_mcp_reveal_key
    : ( ! empty( $royal_mcp_settings['api_key'] ) ? (string) $royal_mcp_settings['api_key'] : '' );
$royal_mcp_api_key_masked = $royal_mcp_has_stored_key && '' === $royal_mcp_api_key_field;
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
                    <h3 class="rmcp-settings-group-title is-first"><?php esc_html_e('Connection', 'royal-mcp'); ?></h3>
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
                                    <?php esc_html_e('When on, AI clients can connect to this site with the MCP Server URL below.', 'royal-mcp'); ?>
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
                            <?php
                            // Inline SVG with currentColor + inline flex.
                            $btn_style_copy = 'display:inline-flex;align-items:center;justify-content:center;gap:6px;line-height:1;';
                            $svg_style_copy = 'width:14px;height:14px;flex-shrink:0;';
                            ?>
                            <button type="button" class="button button-primary copy-btn" data-target="mcp-server-url"
                                    style="<?php echo esc_attr( $btn_style_copy ); ?>">
                                <svg style="<?php echo esc_attr( $svg_style_copy ); ?>" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round" aria-hidden="true">
                                    <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                                    <rect x="8" y="2" width="8" height="4" rx="1"/>
                                </svg>
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
                                <?php esc_html_e('Turn off "Block AI Bots" in your Cloudflare Security settings. It blocks every MCP backend (Claude, ChatGPT, others) from completing the handshake. Enabled by default on new Cloudflare domains.', 'royal-mcp'); ?>
                            </p>
                        </div>
                    </div>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="require_client_approval"><?php esc_html_e('Require approval before new AI clients can connect', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <label class="switch">
                                    <input type="checkbox"
                                           name="royal_mcp_settings[require_client_approval]"
                                           id="require_client_approval"
                                           value="1"
                                           <?php checked(!empty($royal_mcp_settings['require_client_approval'])); ?>>
                                    <span class="slider"></span>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When on, a new AI client that registers itself waits under Royal MCP > Pending Clients until you approve it.', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <!-- Advanced (manual OAuth credentials) -->
                    <button type="button" class="advanced-toggle" id="general-advanced-toggle" aria-expanded="false">
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                        <?php esc_html_e('Advanced (manual OAuth credentials)', 'royal-mcp'); ?>
                    </button>
                    <div class="advanced-content" id="general-advanced-content" hidden>
                        <table class="form-table">
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
                                        <?php esc_html_e('Optional. Most MCP clients register themselves automatically via Dynamic Client Registration. Set a static Client ID only if your client requires it.', 'royal-mcp'); ?>
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

                    <h3 class="rmcp-settings-group-title"><?php esc_html_e('What AI clients may do', 'royal-mcp'); ?></h3>
                    <p class="rmcp-settings-group-intro"><?php esc_html_e('Applies to every connection. Each OAuth client can also get its own level on the Connected Clients screen.', 'royal-mcp'); ?></p>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="read_only_mode"><?php esc_html_e('Read-only mode', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <label class="switch">
                                    <input type="checkbox"
                                           name="royal_mcp_settings[read_only_mode]"
                                           id="read_only_mode"
                                           value="1"
                                           <?php checked(!empty($royal_mcp_settings['read_only_mode'])); ?>>
                                    <span class="slider"></span>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When on, AI clients can read the site but not change it: every tool that creates, updates or deletes is refused for every client and every user, administrators included.', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="drafts_only_mode"><?php esc_html_e('Drafts only', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <label class="switch">
                                    <input type="checkbox"
                                           name="royal_mcp_settings[drafts_only_mode]"
                                           id="drafts_only_mode"
                                           value="1"
                                           <?php checked(!empty($royal_mcp_settings['drafts_only_mode'])); ?>>
                                    <span class="slider"></span>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When on, AI clients can create drafts, edit drafts and pending posts, and upload media for them. They cannot publish or change anything else. If Read-only mode is also on, Read-only mode wins.', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="other_routes_access"><?php esc_html_e('Access level: other MCP servers and browser agents', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <select name="royal_mcp_settings[other_routes_access]" id="other_routes_access">
                                    <?php foreach (['full', 'read-only', 'drafts-only'] as $royal_mcp_level) : ?>
                                        <option value="<?php echo esc_attr($royal_mcp_level); ?>" <?php selected(\Royal_MCP\MCP\Support\Access_Levels::normalize($royal_mcp_settings['other_routes_access'] ?? '') ?: 'full', $royal_mcp_level); ?>><?php echo esc_html(\Royal_MCP\MCP\Support\Access_Levels::label($royal_mcp_level)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('For tools reached through other MCP servers on this site (such as the WordPress MCP Adapter or Elementor) or through browser agents. Read-only mode and Drafts only still apply on top.', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                        <?php $royal_mcp_roles = \Royal_MCP\MCP\Support\Route_Roles::class; ?>
                        <tr>
                            <th scope="row">
                                <label for="connect_role"><?php esc_html_e('Who can connect AI clients', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <select name="royal_mcp_settings[connect_role]" id="connect_role">
                                    <?php foreach ($royal_mcp_roles::CONNECT_CHOICES as $royal_mcp_choice) : ?>
                                        <option value="<?php echo esc_attr($royal_mcp_choice); ?>" <?php selected($royal_mcp_roles::setting($royal_mcp_roles::CONNECT_KEY), $royal_mcp_choice); ?>><?php echo esc_html($royal_mcp_roles::label($royal_mcp_choice)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Which users may sign an AI client in to this site (OAuth). An AI client signed in by a user who no longer qualifies stops working.', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="outside_endpoint_role"><?php esc_html_e('Who can use tools through other MCP servers and browser agents', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <select name="royal_mcp_settings[outside_endpoint_role]" id="outside_endpoint_role">
                                    <?php foreach ($royal_mcp_roles::OUTSIDE_CHOICES as $royal_mcp_choice) : ?>
                                        <option value="<?php echo esc_attr($royal_mcp_choice); ?>" <?php selected($royal_mcp_roles::setting($royal_mcp_roles::OUTSIDE_KEY), $royal_mcp_choice); ?>><?php echo esc_html($royal_mcp_roles::label($royal_mcp_choice)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Royal MCP tools are also available through the WordPress Abilities API, which other MCP servers on this site (WordPress MCP Adapter, Elementor) and browser agents use. Each tool still checks the user\'s own permissions.', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <h3 class="rmcp-settings-group-title"><?php esc_html_e('API key', 'royal-mcp'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="api_key"><?php esc_html_e('WordPress API Key', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       name="royal_mcp_settings[api_key]"
                                       id="api_key"
                                       value="<?php echo esc_attr( $royal_mcp_api_key_field ); ?>"
                                       placeholder="<?php echo $royal_mcp_api_key_masked
                                           ? esc_attr__( 'Key is stored hashed — regenerate to view a new one', 'royal-mcp' )
                                           : esc_attr__( 'No API key set — regenerate to create one', 'royal-mcp' ); ?>"
                                       class="regular-text code"
                                       readonly>
                                <?php
                                $royal_mcp_btn_style = 'display:inline-flex;align-items:center;justify-content:center;gap:6px;line-height:1;vertical-align:middle;';
                                $royal_mcp_svg_style = 'width:14px;height:14px;flex-shrink:0;';
                                ?>
                                <button type="button" class="button" id="copy-api-key" style="<?php echo esc_attr( $royal_mcp_btn_style ); ?>"<?php echo $royal_mcp_api_key_masked ? ' disabled aria-disabled="true"' : ''; ?>>
                                    <svg style="<?php echo esc_attr( $royal_mcp_svg_style ); ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                                        <rect x="8" y="2" width="8" height="4" rx="1"/>
                                    </svg>
                                    <?php esc_html_e('Copy', 'royal-mcp'); ?>
                                </button>
                                <button type="submit"
                                        name="royal_mcp_settings[regenerate_api_key]"
                                        value="1"
                                        class="button"
                                        id="rmcp-regenerate-key"
                                        style="<?php echo esc_attr( $royal_mcp_btn_style ); ?>">
                                    <svg style="<?php echo esc_attr( $royal_mcp_svg_style ); ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <polyline points="23 4 23 10 17 10"/>
                                        <polyline points="1 20 1 14 7 14"/>
                                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                                    </svg>
                                    <?php esc_html_e('Regenerate', 'royal-mcp'); ?>
                                </button>
                                <?php if ( '' !== $royal_mcp_reveal_key ) : ?>
                                <div class="notice notice-warning inline" style="margin-top:10px;">
                                    <p style="margin:6px 0;">
                                        <strong><?php esc_html_e( 'Copy your API key now.', 'royal-mcp' ); ?></strong>
                                        <?php esc_html_e( 'The full key is shown once. After you leave this page, only a masked value will be visible — you\'ll need to regenerate to see a new one.', 'royal-mcp' ); ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                                <?php
                                $royal_mcp_bound_uid = (int) ( $royal_mcp_settings['api_key_user_id'] ?? 0 );
                                if ( $royal_mcp_bound_uid > 0 ) {
                                    $royal_mcp_bound_user = get_userdata( $royal_mcp_bound_uid );
                                    if ( $royal_mcp_bound_user ) {
                                        printf(
                                            '<p class="description"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span> %s</p>',
                                            esc_html( sprintf(
                                                /* translators: %s: WordPress username */
                                                __( 'API key attributed to: %s', 'royal-mcp' ),
                                                $royal_mcp_bound_user->user_login
                                            ) )
                                        );
                                    }
                                } elseif ( ! empty( $royal_mcp_settings['api_key'] ) ) {
                                    printf(
                                        '<p class="description" style="color:#b32d2e;"><span class="dashicons dashicons-warning" aria-hidden="true"></span> %s</p>',
                                        esc_html__( 'API key is not bound to a user. Regenerate to attribute tool calls to your account.', 'royal-mcp' )
                                    );
                                }
                                ?>
                                <p class="description">
                                    <?php esc_html_e('For MCP clients that cannot sign in with OAuth, such as a Claude Desktop config file or direct REST calls. Most clients sign in with OAuth and do not need it.', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="api_key_access"><?php esc_html_e('Access level: API key', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <select name="royal_mcp_settings[api_key_access]" id="api_key_access">
                                    <?php foreach (['full', 'read-only', 'drafts-only'] as $royal_mcp_level) : ?>
                                        <option value="<?php echo esc_attr($royal_mcp_level); ?>" <?php selected(\Royal_MCP\MCP\Support\Access_Levels::normalize($royal_mcp_settings['api_key_access'] ?? '') ?: 'full', $royal_mcp_level); ?>><?php echo esc_html(\Royal_MCP\MCP\Support\Access_Levels::label($royal_mcp_level)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('What clients using the API key may do. Each OAuth client gets its own level on the Connected Clients screen.', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <h3 class="rmcp-settings-group-title"><?php esc_html_e('Extra write permissions', 'royal-mcp'); ?></h3>
                    <p class="rmcp-settings-group-intro"><?php esc_html_e('Off by default. Turn on only what your AI clients need.', 'royal-mcp'); ?></p>
                    <table class="form-table">
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
                                    <?php esc_html_e('When on, AI clients can change WordPress options that are allowlisted. Sensitive options, such as the site address, secret keys and license keys, can never be changed.', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr id="rmcp-row-writable-options">
                            <th scope="row">
                                <label for="writable_options_admin"><?php esc_html_e('Allowlisted plugin options', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <?php
                                $wo_admin_arr = isset($royal_mcp_settings['writable_options_admin']) && is_array($royal_mcp_settings['writable_options_admin'])
                                    ? array_values(array_filter($royal_mcp_settings['writable_options_admin'], 'is_string'))
                                    : [];
                                $wo_admin_str = implode("\n", $wo_admin_arr);
                                ?>
                                <textarea name="royal_mcp_settings[writable_options_admin]"
                                          id="writable_options_admin"
                                          rows="5"
                                          class="large-text code"
                                          placeholder="my_plugin_settings&#10;another_option_key&#10;rank-math-options-general"><?php echo esc_textarea($wo_admin_str); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('One option name per line. AI clients can read and change the options listed here. Sensitive options stay blocked whatever is listed.', 'royal-mcp'); ?>
                                </p>
                                <?php
                                $wo_blocked = $wo_admin_arr ? ( new \Royal_MCP\MCP\Server() )->blocked_option_names( $wo_admin_arr ) : [];
                                if ( $wo_blocked ) :
                                    ?>
                                    <p class="description" id="rmcp-writable-options-blocked" style="color:#b32d2e;">
                                        <?php
                                        echo esc_html(
                                            sprintf(
                                                /* translators: %s: comma-separated option names */
                                                __( 'Blocked, so AI clients cannot read or change them: %s. These names look like credentials or protected settings.', 'royal-mcp' ),
                                                implode( ', ', $wo_blocked )
                                            )
                                        );
                                        ?>
                                    </p>
                                <?php endif; ?>
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
                                    <?php esc_html_e('When on, AI clients can edit the theme\'s custom CSS, with scripts stripped. Customizer settings can be changed only once a developer allowlists them with the royal_mcp_writable_theme_mods filter.', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <h3 class="rmcp-settings-group-title"><?php esc_html_e('Advanced', 'royal-mcp'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="webmcp_enabled"><?php esc_html_e('Enable browser agents (WebMCP)', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <label class="switch">
                                    <input type="checkbox"
                                           name="royal_mcp_settings[webmcp_enabled]"
                                           id="webmcp_enabled"
                                           value="1"
                                           <?php checked(!empty($royal_mcp_settings['webmcp_enabled'])); ?>>
                                    <span class="slider"></span>
                                </label>
                                <?php
                                // Render the bridge-detected pill only when the probe positively
                                // confirms a WebMCP bridge on this domain. The not_detected +
                                // unknown states are noise for the ~99% of admins who don't run
                                // one; the toggle description below already explains what WebMCP
                                // is and where to enable it. Probe path is the W3C WebMCP spec
                                // location (`/.webmcp/bridge.js`), so any conforming bridge
                                // implementation is detected here, not just Cloudflare's.
                                $webmcp_status = \Royal_MCP\Admin\Settings_Page::detect_webmcp_bridge();
                                if ( 'detected' === $webmcp_status ) : ?>
                                <p class="description" style="margin-top:6px;">
                                    <span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#008a20;color:#fff;font-size:11px;font-weight:600;">
                                        <?php esc_html_e('WebMCP bridge detected on this domain.', 'royal-mcp'); ?>
                                    </span>
                                </p>
                                <?php endif; ?>
                                <p class="description">
                                    <?php esc_html_e('When on, browser-based AI agents that use a WebMCP bridge can call Royal MCP tools as the signed-in WordPress user. Each call must carry a WordPress security token, so other websites cannot use it. Clients such as Claude, ChatGPT and Cursor sign in with OAuth either way.', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="abilities_registration_enabled"><?php esc_html_e('Register tools with the WordPress Abilities API', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <label class="switch">
                                    <input type="checkbox"
                                           name="royal_mcp_settings[abilities_registration_enabled]"
                                           id="abilities_registration_enabled"
                                           value="1"
                                           <?php checked($royal_mcp_abilities_registration_enabled); ?>>
                                    <span class="slider"></span>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When on (the default), other MCP servers on this site, such as a page builder\'s, can find Royal MCP tools through the WordPress Abilities API. Turn off to keep each server\'s tools separate. Needs WordPress 6.9 or later.', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr class="rmcp-danger-row">
                            <th scope="row">
                                <label for="delete_data_on_uninstall"><?php esc_html_e('Delete all data when the plugin is deleted', 'royal-mcp'); ?></label>
                            </th>
                            <td>
                                <label class="switch">
                                    <input type="checkbox"
                                           name="royal_mcp_settings[delete_data_on_uninstall]"
                                           id="delete_data_on_uninstall"
                                           value="1"
                                           <?php checked(!empty($royal_mcp_settings['delete_data_on_uninstall'])); ?>>
                                    <span class="slider"></span>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When off (the default), deleting the plugin keeps your settings, API key, activity log and connected clients, so a reinstall picks up where you left off. Turn on only if deleting the plugin should remove all of that permanently. It cannot be recovered.', 'royal-mcp'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <?php if ( ! empty( $royal_mcp_settings['enabled'] ) ) : ?>
            <?php
            // Commands for terminal coding agents, filled in with this site's
            // MCP URL. The API key is never printed: the key variants use a
            // placeholder the admin replaces.
            $royal_mcp_cmd_url = preg_match( '#^[A-Za-z0-9:/._~%-]+$#', $royal_mcp_url_https ) ? $royal_mcp_url_https : '"' . $royal_mcp_url_https . '"';
            $royal_mcp_connect_oauth = [
                'royal-mcp-cmd-claude-code' => [
                    'label' => __( 'Claude Code', 'royal-mcp' ),
                    'note'  => __( 'Start a Claude Code session. On the first Royal MCP call your browser opens the approval screen; click Authorize and the connection is saved for later sessions.', 'royal-mcp' ),
                    'cmd'   => 'claude mcp add --transport http royal-mcp ' . $royal_mcp_cmd_url,
                ],
                'royal-mcp-cmd-codex' => [
                    'label' => __( 'Codex', 'royal-mcp' ),
                    'note'  => __( 'The second command opens your browser to approve the connection.', 'royal-mcp' ),
                    'cmd'   => 'codex mcp add royal-mcp --url ' . $royal_mcp_cmd_url . "\n" . 'codex mcp login royal-mcp',
                ],
            ];
            $royal_mcp_connect_key = [
                'royal-mcp-cmd-claude-code-key' => [
                    'label' => __( 'Claude Code with an API key', 'royal-mcp' ),
                    'note'  => '',
                    'cmd'   => 'claude mcp add --transport http royal-mcp ' . $royal_mcp_cmd_url . ' --header "Authorization: Bearer your-api-key-here"',
                ],
                'royal-mcp-cmd-codex-key-unix' => [
                    'label' => __( 'Codex with an API key: save the key (macOS and Linux)', 'royal-mcp' ),
                    'note'  => __( 'Add the export line to your shell profile to keep it.', 'royal-mcp' ),
                    'cmd'   => 'export ROYAL_MCP_API_KEY="your-api-key-here"',
                ],
                'royal-mcp-cmd-codex-key-windows' => [
                    'label' => __( 'Codex with an API key: save the key (Windows PowerShell)', 'royal-mcp' ),
                    'note'  => __( 'Open a new terminal afterwards.', 'royal-mcp' ),
                    'cmd'   => 'setx ROYAL_MCP_API_KEY "your-api-key-here"',
                ],
                'royal-mcp-cmd-codex-key' => [
                    'label' => __( 'Codex with an API key: connect', 'royal-mcp' ),
                    'note'  => '',
                    'cmd'   => 'codex mcp add royal-mcp --url ' . $royal_mcp_cmd_url . ' --bearer-token-env-var ROYAL_MCP_API_KEY',
                ],
            ];
            $royal_mcp_render_cmd = static function ( $id, array $item ) {
                $btn_style = 'display:inline-flex;align-items:center;justify-content:center;gap:6px;line-height:1;';
                $svg_style = 'width:14px;height:14px;flex-shrink:0;';
                ?>
                <div class="royal-mcp-connect-command" style="margin:0 0 16px;">
                    <strong style="display:block;margin:0 0 6px;"><?php echo esc_html( $item['label'] ); ?></strong>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-start;">
                        <pre class="code" style="flex:1 1 280px;min-width:0;margin:0;padding:8px 10px;background:#fff;border:1px solid #8c8f94;border-radius:4px;white-space:pre-wrap;word-break:break-all;font-size:13px;line-height:1.5;"><?php echo esc_html( $item['cmd'] ); ?></pre>
                        <textarea id="<?php echo esc_attr( $id ); ?>" hidden aria-hidden="true" tabindex="-1"><?php echo esc_textarea( $item['cmd'] ); ?></textarea>
                        <button type="button" class="button copy-btn" data-target="<?php echo esc_attr( $id ); ?>" style="<?php echo esc_attr( $btn_style ); ?>"
                                aria-label="<?php echo esc_attr( sprintf( /* translators: %s: command name, for example Claude Code */ __( 'Copy: %s', 'royal-mcp' ), $item['label'] ) ); ?>">
                            <svg style="<?php echo esc_attr( $svg_style ); ?>" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" aria-hidden="true">
                                <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                                <rect x="8" y="2" width="8" height="4" rx="1"/>
                            </svg>
                            <?php esc_html_e( 'Copy', 'royal-mcp' ); ?>
                        </button>
                    </div>
                    <?php if ( '' !== $item['note'] ) : ?>
                        <p class="description" style="margin-top:4px;"><?php echo esc_html( $item['note'] ); ?></p>
                    <?php endif; ?>
                </div>
                <?php
            };
            ?>
            <!-- ==========================================================
                 Connect a coding agent
                 ========================================================== -->
            <div class="postbox" id="royal-mcp-connect-agents">
                <div class="postbox-header">
                    <h2><?php esc_html_e( 'Connect a coding agent', 'royal-mcp' ); ?></h2>
                </div>
                <div class="inside">
                    <p class="description">
                        <?php esc_html_e( 'Claude Code and Codex connect from the terminal. Copy the command for your agent and run it; these already contain this site\'s MCP Server URL. Signing in uses OAuth, so there is no key to copy or store.', 'royal-mcp' ); ?>
                    </p>
                    <?php
                    foreach ( $royal_mcp_connect_oauth as $royal_mcp_cmd_id => $royal_mcp_cmd_item ) {
                        $royal_mcp_render_cmd( $royal_mcp_cmd_id, $royal_mcp_cmd_item );
                    }
                    ?>
                    <details class="royal-mcp-connect-key-commands">
                        <summary style="cursor:pointer;font-weight:600;margin:0 0 12px;"><?php esc_html_e( 'Show API key commands', 'royal-mcp' ); ?></summary>
                        <p class="description" style="margin:0 0 12px;">
                            <?php esc_html_e( 'For agents that run where no browser can open, such as a server or CI. Replace your-api-key-here with the WordPress API Key from General Settings above; this page never shows the key in these commands.', 'royal-mcp' ); ?>
                        </p>
                        <?php
                        foreach ( $royal_mcp_connect_key as $royal_mcp_cmd_id => $royal_mcp_cmd_item ) {
                            $royal_mcp_render_cmd( $royal_mcp_cmd_id, $royal_mcp_cmd_item );
                        }
                        ?>
                    </details>
                    <a href="https://royalplugins.com/support/royal-mcp/claude-code-and-codex/" target="_blank" rel="noopener noreferrer" class="setup-guide-full-link">
                        <?php esc_html_e( 'Full guide: Claude Code and Codex', 'royal-mcp' ); ?>
                        <span class="dashicons dashicons-external"></span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

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
                                    <li><?php echo wp_kses(__('Click <strong>Add</strong>. Claude will run the OAuth handshake automatically', 'royal-mcp'), ['strong' => []]); ?></li>
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
                                    <?php esc_html_e('ChatGPT (Plugins)', 'royal-mcp'); ?>
                                    <small><?php esc_html_e('Connect via OpenAI ChatGPT plugins', 'royal-mcp'); ?></small>
                                </span>
                                <span class="dashicons dashicons-arrow-down-alt2 setup-guide-chevron"></span>
                            </button>
                            <div class="setup-guide-body">
                                <ol>
                                    <li><?php echo wp_kses(__('Open <a href="https://chatgpt.com" target="_blank" rel="noopener noreferrer">chatgpt.com</a> → <strong>Settings</strong> → <strong>Plugins</strong>', 'royal-mcp'), ['a' => ['href' => [], 'target' => [], 'rel' => []], 'strong' => []]); ?></li>
                                    <li><?php echo wp_kses(__('Click <strong>+ Add</strong> and choose <strong>Plugin</strong> (or "MCP Server")', 'royal-mcp'), ['strong' => []]); ?></li>
                                    <li><?php esc_html_e('Enter a name (e.g., "My WordPress Site")', 'royal-mcp'); ?></li>
                                    <li><?php echo wp_kses(__('Paste the <strong>MCP Server URL</strong> from General Settings above', 'royal-mcp'), ['strong' => []]); ?></li>
                                    <li><?php esc_html_e('Authorize when prompted. ChatGPT will run the OAuth handshake against your WordPress site', 'royal-mcp'); ?></li>
                                    <li><?php esc_html_e('The plugin becomes available across new ChatGPT conversations', 'royal-mcp'); ?></li>
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
        "X-Royal-MCP-API-Key:<?php echo esc_html( $royal_mcp_api_key_field !== '' ? $royal_mcp_api_key_field : 'YOUR_API_KEY' ); ?>"
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
                                    <li><?php esc_html_e('Save. Cursor connects automatically and Royal MCP tools become available', 'royal-mcp'); ?></li>
                                </ol>
                            </div>
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
                        <p class="description">
                            <?php esc_html_e('JSON-RPC endpoint for Claude Desktop, ChatGPT, Cursor, and any MCP-compatible client. Auth is negotiated via OAuth automatically. Every registered tool is discoverable via the tools/list method.', 'royal-mcp'); ?>
                        </p>
                        <ul>
                            <li><code>POST /royal-mcp/v1/mcp</code> - <?php esc_html_e('Primary MCP JSON-RPC endpoint (tools/list, tools/call, prompts/list, resources/list)', 'royal-mcp'); ?></li>
                            <li><code>GET /royal-mcp/v1/sse</code> - <?php esc_html_e('Server-Sent Events stream for streaming MCP clients', 'royal-mcp'); ?></li>
                            <li><code>POST /royal-mcp/v1/messages</code> - <?php esc_html_e('SSE session message channel', 'royal-mcp'); ?></li>
                        </ul>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: example endpoint-profile URL fragment. */
                                esc_html__('Endpoint profiles (Royal MCP Pro) trim the tools/list response to a whitelisted subset. Attach %s to any MCP URL to route calls through a specific profile.', 'royal-mcp'),
                                '<code>?tools=&lt;profile-slug&gt;</code>'
                            );
                            ?>
                        </p>
                    </div>
                </div>
            </div>

        <!-- OAuth Sessions -->
        <div class="postbox royal-mcp-oauth-sessions" style="margin-top: 20px;">
            <div class="postbox-header">
                <h2><?php esc_html_e('OAuth Sessions', 'royal-mcp'); ?></h2>
            </div>
            <div class="inside">
                <?php
                $ttl_choices = [
                    3600   => __('1 hour', 'royal-mcp'),
                    28800  => __('8 hours', 'royal-mcp'),
                    86400  => __('24 hours (default)', 'royal-mcp'),
                    604800 => __('7 days', 'royal-mcp'),
                ];
                $ttl_current = (int) ($royal_mcp_settings['access_token_ttl_seconds'] ?? \Royal_MCP\OAuth\Token_Store::ACCESS_TOKEN_TTL);
                if (!array_key_exists($ttl_current, $ttl_choices)) {
                    $ttl_current = \Royal_MCP\OAuth\Token_Store::ACCESS_TOKEN_TTL;
                }
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="access_token_ttl_seconds"><?php esc_html_e('Session length', 'royal-mcp'); ?></label>
                        </th>
                        <td>
                            <select name="royal_mcp_settings[access_token_ttl_seconds]" id="access_token_ttl_seconds">
                                <?php foreach ($ttl_choices as $seconds => $label) : ?>
                                    <option value="<?php echo esc_attr($seconds); ?>" <?php selected($ttl_current, $seconds); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('How long an AI client stays connected before it must sign in again. Applies to new sessions; existing sessions keep their original length.', 'royal-mcp'); ?>
                            </p>
                            <p class="description">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=royal-mcp-connected-clients' ) ); ?>"><?php esc_html_e('See who is connected, and revoke one client or all of them, on the Connected Clients screen', 'royal-mcp'); ?></a>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        </div><!-- /.royal-mcp-settings-container -->

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
                <?php esc_html_e('Wipes all registered OAuth clients, issued access/refresh tokens, and pending authorization codes. Use this when a Claude.ai, Claude Desktop, or ChatGPT plugin gets stuck mid-handshake and won\'t complete authorization. Your Royal MCP settings, API key, and Activity Log are NOT affected.', 'royal-mcp'); ?>
            </p>
            <p class="description warning-text">
                <strong><?php esc_html_e('Warning:', 'royal-mcp'); ?></strong>
                <?php esc_html_e('All currently-connected MCP clients will need to re-authorize after running this. Only use this if you\'re actively troubleshooting a stuck connection.', 'royal-mcp'); ?>
            </p>
            <p>
                <?php
                // Inline SVG with currentColor + inline flex.
                $btn_style_reset = 'display:inline-flex;align-items:center;justify-content:center;gap:6px;line-height:1;';
                $svg_style_reset = 'width:14px;height:14px;flex-shrink:0;';
                ?>
                <button type="button"
                        class="button button-secondary"
                        id="royal-mcp-reset-oauth-state"
                        style="<?php echo esc_attr( $btn_style_reset ); ?>">
                    <svg style="<?php echo esc_attr( $svg_style_reset ); ?>" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round"
                         stroke-linejoin="round" aria-hidden="true">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                        <path d="M10 11v6"/>
                        <path d="M14 11v6"/>
                        <path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/>
                    </svg>
                    <?php esc_html_e('Reset OAuth State', 'royal-mcp'); ?>
                </button>
                <span id="royal-mcp-reset-oauth-state-status" style="margin-left: 10px;"></span>
            </p>
        </div>
    </div>

</div>
