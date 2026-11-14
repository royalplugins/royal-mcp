<?php
/**
 * Royal MCP — What's New modal template.
 *
 * Whats-New-For-Version: 2.0.0
 *
 * Rendered from Whats_New::render_modal() on admin_footer (Royal MCP pages
 * only). JS (assets/js/whats-new.js) handles show/hide + AJAX dismissal.
 *
 * Editing this file requires no PHP changes as long as the surrounding
 * class structure stays. Update slides in place for each release; the
 * modal auto-opens on next admin page view for every user because
 * ROYAL_MCP_VERSION is stamped into user_meta on dismiss.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$rmcp_wn_img_base          = ROYAL_MCP_PLUGIN_URL . 'assets/img/whats-new/';
$rmcp_wn_royally_url       = 'https://royalplugins.com/royally/?utm_source=royal_mcp_free&utm_medium=free_plugin&utm_content=whats_new_footer&utm_campaign=royal_mcp_admin';
$rmcp_wn_royally_slide_url = 'https://royalplugins.com/royally/?utm_source=royal_mcp_free&utm_medium=free_plugin&utm_content=whats_new_slide&utm_campaign=royal_mcp_admin';
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( '2.0: Dashboard, Context, Skills and 79 More Tools', 'royal-mcp' ); ?></p>
            </div>
            <button type="button" class="rmcp-wn-close" data-royal-mcp-wn-close aria-label="<?php esc_attr_e( 'Close', 'royal-mcp' ); ?>">&times;</button>
        </div>

        <div class="rmcp-wn-slides">

            <!-- SLIDE 0: 2.0 -->
            <div class="rmcp-wn-slide is-celebration" data-royal-mcp-wn-confetti>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle is-dark">
                        <img src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Version 2.0', 'royal-mcp' ); ?></span>
                    <p class="rmcp-wn-big-number"><?php esc_html_e( '380+ tools', 'royal-mcp' ); ?></p>
                    <h3><?php esc_html_e( 'Royal MCP 2.0 is here', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'A new dashboard, a simpler menu and the biggest tool expansion Royal MCP has had. It is still free, and it still runs only on your site.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 1: Dashboard and menu -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v2.0', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'A dashboard and a simpler menu', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'The Dashboard shows your MCP address with the steps to connect, which AI clients are connected, what they changed lately and how the site is protected.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Settings now sit on two screens: Connections for who can connect and how, and Safety for what a connected AI may do.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="c"><?php esc_html_e( '// Royal MCP menu', 'royal-mcp' ); ?></span>
  <span class="s hl"><?php esc_html_e( 'Dashboard', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'Connections', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'Context', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'Safety', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'Activity', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'Skills', 'royal-mcp' ); ?></span></div>
                </div>
            </div>

            <!-- SLIDE 2: Context and Skills -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="c"><?php esc_html_e( '// When an AI client connects', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'your site context', 'royal-mcp' ); ?></span>   <span class="s hl"><?php esc_html_e( 'sent', 'royal-mcp' ); ?> &#10003;</span>
  <span class="s"><?php esc_html_e( 'skills for this site', 'royal-mcp' ); ?></span> <span class="s hl"><?php esc_html_e( 'offered', 'royal-mcp' ); ?> &#10003;</span>

<span class="k">mcp_get_skill</span>
  <span class="s">skill</span>   <span class="c">elementor</span></div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v2.0', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Tell your AI about your site', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'On the Context screen, write what your site is for, its tone and what to leave alone. Every connected AI client gets it when it connects.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'On the Skills screen, switch on a playbook for Elementor, Divi, WooCommerce or SiteVault. Connected AI clients read it before that kind of work, with nothing to download.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 3: more tools, shorter list -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v2.0', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( '79 more tools, and a shorter list for your AI', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Your AI can now install, update and switch plugins and themes, check a site before launch, write a monthly maintenance report and do more in ACF, GuardPress, SiteVault and other plugins.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Tools for plugins that are not active on your site are left out, so your AI sees only what it can use here.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="c"><?php esc_html_e( '// Tools', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'for WordPress itself', 'royal-mcp' ); ?></span>   <span class="s hl">140</span>
  <span class="s"><?php esc_html_e( 'across 38 plugins', 'royal-mcp' ); ?></span>      <span class="s hl">244</span>
  <span class="s"><?php esc_html_e( 'sent to your AI', 'royal-mcp' ); ?></span>        <span class="c"><?php esc_html_e( 'only what is active', 'royal-mcp' ); ?></span></div>
                </div>
            </div>

            <!-- SLIDE 4: Royally -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle is-dark">
                        <img src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Royally', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'One connection for everything your AI manages', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Royally puts your whole stack behind one MCP connection: every WordPress site you run, plus Cloudflare, UptimeRobot, Notion and other MCP servers. Each change can wait for your approval, is recorded and can be undone. Royal MCP Pro comes with it.', 'royal-mcp' ); ?></p>
                    <a href="<?php echo esc_url( $rmcp_wn_royally_slide_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-btn">
                        <?php esc_html_e( 'See Royally', 'royal-mcp' ); ?>
                    </a>
                </div>
            </div>

        </div>

        <!-- FOOTER -->
        <div class="rmcp-wn-upsell">
            <div class="rmcp-wn-upsell-copy">
                <h4><?php esc_html_e( 'Running more than one site?', 'royal-mcp' ); ?></h4>
                <p>
                    <?php esc_html_e( 'One MCP connection for all your sites, Cloudflare, UptimeRobot and more, with approvals and undo.', 'royal-mcp' ); ?>
                </p>
            </div>
            <a href="<?php echo esc_url( $rmcp_wn_royally_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-upsell-btn">
                <?php esc_html_e( 'See Royally →', 'royal-mcp' ); ?>
            </a>
        </div>

    </div>
</div>
