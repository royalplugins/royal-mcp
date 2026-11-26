<?php
/**
 * Royal MCP — What's New modal template.
 *
 * Whats-New-For-Version: 2.0.2
 *
 * Rendered from Whats_New::render_modal() on admin_footer (Royal MCP pages
 * only). JS (assets/js/whats-new.js) handles show/hide + AJAX dismissal.
 *
 * Editing this file requires no PHP changes as long as the surrounding
 * class structure stays. Update slides in place for each release; the
 * modal auto-opens on next admin page view for every user because
 * ROYAL_MCP_VERSION is stamped into user_meta on dismiss.
 * Each slide uses a different visual (see assets/css/whats-new.css, SLIDE VISUALS).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$rmcp_wn_img_base    = ROYAL_MCP_PLUGIN_URL . 'assets/img/whats-new/';
$rmcp_wn_royally_url = 'https://royalplugins.com/royal-mcp-pro/?utm_source=royal_mcp_free&utm_medium=free_plugin&utm_content=whats_new_footer&utm_campaign=royal_mcp_admin';
$rmcp_wn_tag         = __( 'New in 2.0.2', 'royal-mcp' );
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( '2.0.2: Skills for Claude Code', 'royal-mcp' ); ?></p>
            </div>
            <button type="button" class="rmcp-wn-close" data-royal-mcp-wn-close aria-label="<?php esc_attr_e( 'Close', 'royal-mcp' ); ?>">&times;</button>
        </div>

        <div class="rmcp-wn-slides">

            <!-- SLIDE 1: Skills in Claude Code — slash command list (the one dark visual) -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-cc" aria-hidden="true">
                        <div class="rmcp-wn-cc-bar"><span class="rmcp-wn-cc-dots"><i></i><i></i><i></i></span><span class="rmcp-wn-cc-app">Claude Code</span></div>
                        <div class="rmcp-wn-cc-input"><span class="rmcp-wn-cc-ps">&gt;</span>/royal-mcp:<span class="rmcp-wn-cc-caret"></span></div>
                        <div class="rmcp-wn-cc-list">
                            <div class="rmcp-wn-cc-item is-on"><span class="rmcp-wn-cc-name">/royal-mcp:elementor</span><em><?php esc_html_e( 'Edit and build pages', 'royal-mcp' ); ?></em></div>
                            <div class="rmcp-wn-cc-item"><span class="rmcp-wn-cc-name">/royal-mcp:woocommerce</span><em><?php esc_html_e( 'Prices, stock, orders', 'royal-mcp' ); ?></em></div>
                            <div class="rmcp-wn-cc-item"><span class="rmcp-wn-cc-name">/royal-mcp:divi</span><em><?php esc_html_e( 'Divi pages and library', 'royal-mcp' ); ?></em></div>
                            <div class="rmcp-wn-cc-item"><span class="rmcp-wn-cc-name">/royal-mcp:sitevault</span><em><?php esc_html_e( 'Backups', 'royal-mcp' ); ?></em></div>
                        </div>
                        <div class="rmcp-wn-cc-foot">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M3.6 9h16.8"/><path d="M3.6 15h16.8"/><path d="M12 3a15 15 0 0 1 0 18"/><path d="M12 3a15 15 0 0 0 0 18"/></svg>
                            <?php esc_html_e( 'Loaded from your site', 'royal-mcp' ); ?>
                        </div>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php echo esc_html( $rmcp_wn_tag ); ?></span>
                    <h3><?php esc_html_e( 'Claude Code loads Royal MCP skills from your site', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Connect Claude Code to your site and the Royal MCP skills for the plugins you run come with it, ready to use. Nothing to download or copy.', 'royal-mcp' ); ?></p>
                    <p><?php
                    /* translators: 1: and 2: example slash commands, such as /royal-mcp:elementor */
                    echo wp_kses( sprintf( esc_html__( 'They show up as slash commands named after your connection, such as %1$s or %2$s.', 'royal-mcp' ), '<code class="rmcp-wn-cmd">/royal-mcp:elementor</code>', '<code class="rmcp-wn-cmd">/royal-mcp:woocommerce</code>' ), [ 'code' => [ 'class' => true ] ] );
                    ?></p>
                </div>
            </div>

            <!-- SLIDE 2: WordPress 7.2 + WPBakery AI on the coexistence report — report rows with status dots -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php echo esc_html( $rmcp_wn_tag ); ?></span>
                    <h3><?php esc_html_e( 'WordPress 7.2 and WPBakery AI', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Royal MCP is tested with WordPress 7.2.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'If WPBakery AI is installed, its MCP server now shows on the report of MCP plugins on your site. When WPBakery turns down a call, your AI gets WPBakery\'s own reason instead of a general error.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-coex" aria-hidden="true">
                        <div class="rmcp-wn-coex-wp">
                            <span class="rmcp-wn-coex-wp-num">7.2</span>
                            <span class="rmcp-wn-coex-wp-text"><?php esc_html_e( 'WordPress', 'royal-mcp' ); ?><em><?php esc_html_e( 'Tested', 'royal-mcp' ); ?></em></span>
                        </div>
                        <div class="rmcp-wn-coex-head"><?php esc_html_e( 'MCP on this site', 'royal-mcp' ); ?></div>
                        <div class="rmcp-wn-coex-row"><span class="rmcp-wn-coex-dot is-ok"></span><span class="rmcp-wn-coex-name">Royal MCP</span><span class="rmcp-wn-coex-state"><?php esc_html_e( 'This plugin', 'royal-mcp' ); ?></span></div>
                        <div class="rmcp-wn-coex-row is-new"><span class="rmcp-wn-coex-dot is-ok"></span><span class="rmcp-wn-coex-name">WPBakery AI<em><?php esc_html_e( 'Runs side by side', 'royal-mcp' ); ?></em></span><span class="rmcp-wn-coex-new"><?php esc_html_e( 'New', 'royal-mcp' ); ?></span></div>
                        <div class="rmcp-wn-coex-reason">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <span><?php esc_html_e( 'Refused calls say why', 'royal-mcp' ); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SLIDE 3: Connection Doctor checks the OpenID address — step path -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-path" aria-hidden="true">
                        <div class="rmcp-wn-path-step is-skip">
                            <span class="rmcp-wn-path-dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg></span>
                            <span class="rmcp-wn-path-text"><?php esc_html_e( 'Standard sign-in address', 'royal-mcp' ); ?><em><?php esc_html_e( 'Answered by your host', 'royal-mcp' ); ?></em></span>
                        </div>
                        <div class="rmcp-wn-path-step is-new">
                            <span class="rmcp-wn-path-dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
                            <span class="rmcp-wn-path-text"><?php esc_html_e( 'OpenID sign-in address', 'royal-mcp' ); ?><em><?php esc_html_e( 'Checked too', 'royal-mcp' ); ?></em></span>
                        </div>
                        <div class="rmcp-wn-path-step is-ok">
                            <span class="rmcp-wn-path-dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                            <span class="rmcp-wn-path-text"><?php esc_html_e( 'AI apps can sign in', 'royal-mcp' ); ?></span>
                        </div>
                        <div class="rmcp-wn-path-keep">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="14 3 14 9 20 9"/></svg>
                            <span><?php esc_html_e( 'Your host\'s files stay where they are', 'royal-mcp' ); ?></span>
                        </div>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php echo esc_html( $rmcp_wn_tag ); ?></span>
                    <h3><?php esc_html_e( 'Connection Doctor looks one step further', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Before Connection Doctor reports a blocked sign-in step, it now also checks the OpenID sign-in address that AI apps can use instead. If that one works, it tells you sign-in will work.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'It no longer suggests deleting files your host serves.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 4: Change history keeps the title and connection — record card -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php echo esc_html( $rmcp_wn_tag ); ?></span>
                    <h3><?php esc_html_e( 'Each change shows what it was and who made it', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Recent Changes now shows the title of the post or item and the AI connection, as they were when the change was made.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'If a page is renamed or a connection is removed later, you can still tell which change was which.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-rec" aria-hidden="true">
                        <div class="rmcp-wn-rec-head">
                            <span class="rmcp-wn-rec-label"><?php esc_html_e( 'Change', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-rec-status"><?php esc_html_e( 'Can be undone', 'royal-mcp' ); ?></span>
                        </div>
                        <div class="rmcp-wn-rec-title"><?php esc_html_e( 'Spring Sale landing page', 'royal-mcp' ); ?></div>
                        <div class="rmcp-wn-rec-row"><span class="rmcp-wn-rec-key"><?php esc_html_e( 'Tool', 'royal-mcp' ); ?></span><span class="rmcp-wn-rec-val is-code">wp_update_page</span></div>
                        <div class="rmcp-wn-rec-row is-new"><span class="rmcp-wn-rec-key"><?php esc_html_e( 'Connection', 'royal-mcp' ); ?></span><span class="rmcp-wn-rec-val">Claude · admin</span></div>
                        <div class="rmcp-wn-rec-row"><span class="rmcp-wn-rec-key"><?php esc_html_e( 'When', 'royal-mcp' ); ?></span><span class="rmcp-wn-rec-val"><?php esc_html_e( 'Today, 9:41', 'royal-mcp' ); ?></span></div>
                        <div class="rmcp-wn-rec-foot"><?php esc_html_e( 'Recorded at the time of the change', 'royal-mcp' ); ?></div>
                    </div>
                </div>
            </div>

            <!-- SLIDE 5: One undo removes a launched post with its image and terms — item rows with badges -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-bundle" aria-hidden="true">
                        <div class="rmcp-wn-bundle-row">
                            <span class="rmcp-wn-bundle-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="14 3 14 9 20 9"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></svg></span>
                            <span class="rmcp-wn-bundle-name"><?php esc_html_e( 'Launched post', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-bundle-badge"><?php esc_html_e( 'Removed', 'royal-mcp' ); ?></span>
                        </div>
                        <div class="rmcp-wn-bundle-row">
                            <span class="rmcp-wn-bundle-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></span>
                            <span class="rmcp-wn-bundle-name"><?php esc_html_e( 'Downloaded image', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-bundle-badge"><?php esc_html_e( 'Removed', 'royal-mcp' ); ?></span>
                        </div>
                        <div class="rmcp-wn-bundle-row">
                            <span class="rmcp-wn-bundle-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><line x1="7.5" y1="7.5" x2="7.51" y2="7.5"/></svg></span>
                            <span class="rmcp-wn-bundle-name"><?php esc_html_e( 'Categories and tags', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-bundle-badge"><?php esc_html_e( 'Removed', 'royal-mcp' ); ?></span>
                        </div>
                        <div class="rmcp-wn-bundle-undo">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.5 15a9 9 0 1 0 2.1-9.4L1 10"/></svg>
                            <?php esc_html_e( 'One undo', 'royal-mcp' ); ?>
                        </div>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php echo esc_html( $rmcp_wn_tag ); ?></span>
                    <h3><?php esc_html_e( 'Undo a launched post in one step', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'When your AI publishes a post with wp_publish_and_launch, one undo now removes the post together with the image it downloaded and any new categories or tags it created.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'This release also adds stricter safeguards across sign-in, API keys, redirects and plugin abilities.', 'royal-mcp' ); ?></p>
                </div>
            </div>

        </div>

        <!-- FOOTER -->
        <div class="rmcp-wn-upsell">
            <div class="rmcp-wn-upsell-copy">
                <h4><?php esc_html_e( 'Upgrade To 530+ MCP tools!', 'royal-mcp' ); ?></h4>
                <p>
                    <?php esc_html_e( 'One MCP connection for all your sites and your full stack: hosting, Cloudflare, UptimeRobot, Jira and more, with up to 168-hour undo and full governance.', 'royal-mcp' ); ?>
                </p>
            </div>
            <a href="<?php echo esc_url( $rmcp_wn_royally_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-upsell-btn">
                <?php esc_html_e( 'See Royal MCP Pro →', 'royal-mcp' ); ?>
            </a>
        </div>

    </div>
</div>
