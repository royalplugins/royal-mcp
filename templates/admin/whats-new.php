<?php
/**
 * Royal MCP — What's New modal template.
 *
 * Whats-New-For-Version: 1.5.17
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

$rmcp_wn_img_base      = ROYAL_MCP_PLUGIN_URL . 'assets/img/whats-new/';
$rmcp_wn_pro_url       = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_17&utm_content=footer_cta';
$rmcp_wn_pro_slide_url = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_17&utm_content=slide_1_cta';
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( '1.5.17: Activity Log Controls and Stronger Safeguards', 'royal-mcp' ); ?></p>
            </div>
            <button type="button" class="rmcp-wn-close" data-royal-mcp-wn-close aria-label="<?php esc_attr_e( 'Close', 'royal-mcp' ); ?>">&times;</button>
        </div>

        <div class="rmcp-wn-slides">

            <!-- SLIDE 0: 200,000 downloads and the 2.0 teaser -->
            <div class="rmcp-wn-slide is-celebration" data-royal-mcp-wn-confetti>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle is-dark">
                        <img src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Thank you', 'royal-mcp' ); ?></span>
                    <p class="rmcp-wn-big-number"><?php esc_html_e( '200,000 downloads', 'royal-mcp' ); ?></p>
                    <h3><?php esc_html_e( 'Royal MCP 2.0 is on the way', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'A new dashboard, a redesigned layout and the biggest tool expansion Royal MCP has had. Shaped by 200,000 downloads of feedback.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 1 — ROYAL MCP PRO PITCH (flagship marketing spot) -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle is-dark">
                        <img src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="Royal MCP Pro">
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Royal MCP Pro + Royally', 'royal-mcp' ); ?></span>
                    <p class="rmcp-wn-big-number"><?php esc_html_e( 'Upgrade Your AI Agent', 'royal-mcp' ); ?></p>
                    <h3><?php esc_html_e( 'The plugin on each site, the guardrails in the cloud', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'On each WordPress site, Royal MCP Pro gives your AI client 400+ tools, from bulk WooCommerce, SEO and content edits to Elementor and Divi layouts that stay editable in the builder, and records every change with undo.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'In the cloud, Royally is the accountability layer across your whole fleet: run the same bulk action or edit on many sites at once, approve changes before they go out, undo them across sites, and keep one record of everything the AI did.', 'royal-mcp' ); ?></p>
                    <a href="<?php echo esc_url( $rmcp_wn_pro_slide_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-btn">
                        <?php esc_html_e( 'Starts at $9/Month', 'royal-mcp' ); ?>
                    </a>
                </div>
            </div>

            <!-- SLIDE 2: Activity Log retention and Clear -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v1.5.17', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'An Activity Log that looks after itself', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'The Activity Log now keeps 90 days of entries and removes older ones once a day, so the record of what your AI clients did stays useful without growing forever.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Want a clean start? Royal MCP > Activity Log has a Clear log button. It asks you to confirm first and leaves one entry saying who cleared it.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="c"><?php esc_html_e( '// Activity Log', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'entries kept', 'royal-mcp' ); ?></span>     <span class="s hl"><?php esc_html_e( '90 days', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'older entries', 'royal-mcp' ); ?></span>    <span class="c"><?php esc_html_e( 'removed', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'Clear log', 'royal-mcp' ); ?></span>        <span class="s hl"><?php esc_html_e( 'ready', 'royal-mcp' ); ?> &#10003;</span></div>
                </div>
            </div>

            <!-- SLIDE 3: safeguards for sign-in, undo and integrations -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="k">mcp_undo_last_operation</span>
  <span class="s">undo_token</span>   <span class="c">a1b2c3&hellip;</span>
<span class="k">&rarr;</span> <span class="s hl"><?php esc_html_e( 'change undone', 'royal-mcp' ); ?> &#10003;</span>

<span class="c"><?php esc_html_e( '// Account removed', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'its AI connections', 'royal-mcp' ); ?></span>  <span class="s hl"><?php esc_html_e( 'ended', 'royal-mcp' ); ?> &#10003;</span></div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Security', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Stronger safeguards for sign-in, undo and integrations', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'AI connections follow the WordPress account behind them: remove a user, or their right to connect, and their OAuth 2.1 sign-ins and API key stop working. The sign-in screen can only be opened directly, never inside another site.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'The undo tool takes the token under either name your AI client sends, and the Redirection, ACF, Elementor and WooCommerce order tools apply stricter checks to what an AI client may save or read.', 'royal-mcp' ); ?></p>
                </div>
            </div>

        </div>

        <!-- PRO UPSELL FOOTER -->
        <div class="rmcp-wn-upsell">
            <div class="rmcp-wn-upsell-copy">
                <h4><?php esc_html_e( 'Ready to let AI do more on every client site?', 'royal-mcp' ); ?></h4>
                <p>
                    <?php esc_html_e( '1,400+ tools and 4,500+ actions, including the whole Cloudflare API, across every MCP server Royally connects.', 'royal-mcp' ); ?>
                </p>
            </div>
            <a href="<?php echo esc_url( $rmcp_wn_pro_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-upsell-btn">
                <?php esc_html_e( 'Upgrade to Pro →', 'royal-mcp' ); ?>
            </a>
        </div>

    </div>
</div>
