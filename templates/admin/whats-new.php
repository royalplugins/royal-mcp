<?php
/**
 * Royal MCP — What's New modal template.
 *
 * Whats-New-For-Version: 1.5.16
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
$rmcp_wn_pro_url       = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_16&utm_content=footer_cta';
$rmcp_wn_pro_slide_url = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_16&utm_content=slide_1_cta';
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( '1.5.16: 200,000 Downloads and Hidden Page Settings', 'royal-mcp' ); ?></p>
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

            <!-- SLIDE 2: hidden page settings -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v1.5.16', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Let AI change the hidden settings you choose', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'WordPress keeps some page settings in hidden meta keys that AI clients can\'t change. Now you can list the exact keys you want to open, under Royal MCP > Settings.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'On Divi, one button adds the page layout, gutter width, side navigation and title settings. Keys that WordPress and other plugins depend on stay closed, and every change can be undone.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="c"><?php esc_html_e( '// Protected meta keys', 'royal-mcp' ); ?></span>
  <span class="s">_et_pb_page_layout</span>  <span class="s hl"><?php esc_html_e( 'open', 'royal-mcp' ); ?> &#10003;</span>
  <span class="s">_et_pb_gutter_width</span> <span class="s hl"><?php esc_html_e( 'open', 'royal-mcp' ); ?> &#10003;</span>
  <span class="s">_price</span>              <span class="c"><?php esc_html_e( 'closed', 'royal-mcp' ); ?></span></div>
                </div>
            </div>

            <!-- SLIDE 3: page templates and smaller additions -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="k">wp_update_page</span>
  <span class="s">template</span>   <span class="c">full-width.php</span>
<span class="k">&rarr;</span> <span class="s hl"><?php esc_html_e( 'saved, undo available', 'royal-mcp' ); ?> &#10003;</span>
<span class="k">rl_get_renewals</span>
  <span class="s">days</span>               <span class="c">30</span>
  <span class="s">include_past_due</span>   <span class="c">true</span></div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Also new', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Set a page template from your AI client', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'wp_create_page and wp_update_page now take a template, checked against what your theme offers, and an update can be undone.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Royal Ledger renewals can look ahead by days and include overdue items, SiteVault backups can name single plugin or theme folders, and Rank Math canonical URLs now show in SEO reads.', 'royal-mcp' ); ?></p>
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
