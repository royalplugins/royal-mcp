<?php
/**
 * Royal MCP — What's New modal template.
 *
 * Whats-New-For-Version: 1.5.13
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
$rmcp_wn_pro_url       = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_13&utm_content=footer_cta';
$rmcp_wn_pro_slide_url = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_13&utm_content=slide_1_cta';
$rmcp_wn_offer_open    = current_datetime()->format( 'Y-m-d' ) <= \Royal_MCP\Admin\Pro_Notice::OFFER_ENDS;
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( '1.5.13: Security Release', 'royal-mcp' ); ?></p>
            </div>
            <button type="button" class="rmcp-wn-close" data-royal-mcp-wn-close aria-label="<?php esc_attr_e( 'Close', 'royal-mcp' ); ?>">&times;</button>
        </div>

        <div class="rmcp-wn-slides">

            <!-- SLIDE 1 — ROYAL MCP PRO PITCH (flagship marketing spot) -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle is-dark">
                        <img src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="Royal MCP Pro">
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Royal MCP Pro + Royally', 'royal-mcp' ); ?></span>
                    <p class="rmcp-wn-big-number"><?php esc_html_e( 'AI you can hold to account, on every client site', 'royal-mcp' ); ?></p>
                    <h3><?php esc_html_e( 'The plugin on each site, the guardrails in the cloud', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'On each WordPress site, Royal MCP Pro gives your AI client 350+ tools, from bulk WooCommerce, SEO and content edits to Elementor and Divi layouts that stay editable in the builder, and records every change with undo.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'In the cloud, Royally is the accountability layer across your whole fleet: run the same bulk action or edit on many sites at once, approve changes before they go out, undo them across sites, and keep one record of everything the AI did.', 'royal-mcp' ); ?></p>
                    <a href="<?php echo esc_url( $rmcp_wn_pro_slide_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-btn">
                        <?php esc_html_e( 'See Pro and Royally →', 'royal-mcp' ); ?>
                    </a>
                </div>
            </div>

            <!-- SLIDE 2 — KEYS AND TOKENS HIDDEN IN EVERY REPLY (the 1.5.13 headline) -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v1.5.13', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Keys and tokens never leave your site', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Royal MCP now checks every tool reply for the API keys, licence keys, tokens and passwords that your other plugins store, including values saved as JSON or encoded text, and replaces them with [REDACTED] before the reply leaves your server.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Ordinary settings, labels and content come through unchanged, so your AI client still understands how the site is set up.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="k">wp_get_plugin_settings</span>
  <span class="s">licenseKey</span>      <span class="s hl">[REDACTED]</span>
  <span class="s">aiAccessToken</span>   <span class="s hl">[REDACTED]</span>
  <span class="s">siteTitle</span>       <span class="c">#site_title</span>
  <span class="s">separator</span>       <span class="c">-</span>
<span class="k">&rarr;</span> <span class="c"><?php esc_html_e( 'settings readable, secrets hidden', 'royal-mcp' ); ?></span></div>
                </div>
            </div>

            <!-- SLIDE 3 — API KEY CHANGES ONLY ON REGENERATE -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="c"><?php esc_html_e( '// Settings → Save', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'API key', 'royal-mcp' ); ?></span>        <span class="c"><?php esc_html_e( 'unchanged', 'royal-mcp' ); ?></span> &#10003;
<span class="c"><?php esc_html_e( '// Regenerate → OK', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'new key', 'royal-mcp' ); ?></span>        <span class="c"><?php esc_html_e( 'shown once', 'royal-mcp' ); ?></span>
  <span class="s hl"><?php esc_html_e( 'Activity Log', 'royal-mcp' ); ?>   <?php esc_html_e( 'key rotated', 'royal-mcp' ); ?></span></div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'API key', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Your API key changes only when you say so', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Saving the settings screen never touches the key, even if you press Enter in a field. Regenerate is its own button with its own confirmation, and the new key is shown once.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Every new key is recorded in the Activity Log, so you can always see when and by whom it was changed.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 4 — LIMITS STAY WHERE YOU SET THEM -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Access levels', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Limits stay where you set them', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'An AI client that connects again with a new registration keeps the access level you gave it on the Connected Clients screen. Choose Full there whenever you want to lift it.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'The post editing tools also leave code snippets and unpublished Customizer changes alone: those change only in their own screens.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="c"><?php esc_html_e( '// Connected Clients', 'royal-mcp' ); ?></span>
  <span class="s">Claude Code</span>    <span class="c"><?php esc_html_e( 'Read-only', 'royal-mcp' ); ?></span>
<span class="c"><?php esc_html_e( '// registers again', 'royal-mcp' ); ?></span>
  <span class="s">Claude Code</span>    <span class="s hl"><?php esc_html_e( 'Read-only', 'royal-mcp' ); ?> &#10003;</span>
<span class="k">wp_update_post</span>  <span class="c"><?php esc_html_e( 'code snippet', 'royal-mcp' ); ?></span>
<span class="k">&rarr;</span> <span class="c"><?php esc_html_e( 'left alone', 'royal-mcp' ); ?></span></div>
                </div>
            </div>

        </div>

        <!-- PRO UPSELL FOOTER -->
        <div class="rmcp-wn-upsell">
            <div class="rmcp-wn-upsell-copy">
                <h4><?php esc_html_e( 'Ready to let AI do more on every client site?', 'royal-mcp' ); ?></h4>
                <p>
                    <?php esc_html_e( 'Royal MCP Pro adds 350+ tools: bulk edits for WooCommerce, Elementor and Divi with layouts that stay editable, ACF setup, one-call SEO launches, per-connection tool sets and a 90-day activity log your clients can inspect.', 'royal-mcp' ); ?>
                    <?php if ( $rmcp_wn_offer_open ) : ?>
                        <strong><?php esc_html_e( '30-50% off ends Oct 31st.', 'royal-mcp' ); ?></strong>
                    <?php endif; ?>
                </p>
            </div>
            <a href="<?php echo esc_url( $rmcp_wn_pro_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-upsell-btn">
                <?php esc_html_e( 'Upgrade to Pro →', 'royal-mcp' ); ?>
            </a>
        </div>

    </div>
</div>
