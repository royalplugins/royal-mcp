<?php
/**
 * Royal MCP — What's New modal template.
 *
 * Whats-New-For-Version: 1.5.14
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
$rmcp_wn_pro_url       = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_14&utm_content=footer_cta';
$rmcp_wn_pro_slide_url = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_14&utm_content=slide_1_cta';
$rmcp_wn_offer_open    = current_datetime()->format( 'Y-m-d' ) <= \Royal_MCP\Admin\Pro_Notice::OFFER_ENDS;
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( '1.5.14: Works With Other MCP Plugins', 'royal-mcp' ); ?></p>
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
                    <p><?php esc_html_e( 'On each WordPress site, Royal MCP Pro gives your AI client 400+ tools, from bulk WooCommerce, SEO and content edits to Elementor and Divi layouts that stay editable in the builder, and records every change with undo.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'In the cloud, Royally is the accountability layer across your whole fleet: run the same bulk action or edit on many sites at once, approve changes before they go out, undo them across sites, and keep one record of everything the AI did.', 'royal-mcp' ); ?></p>
                    <a href="<?php echo esc_url( $rmcp_wn_pro_slide_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-btn">
                        <?php esc_html_e( 'Starts at $9/Month', 'royal-mcp' ); ?>
                    </a>
                </div>
            </div>

            <!-- SLIDE 2 — WORKS NEXT TO OTHER MCP PLUGINS (the 1.5.14 headline) -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v1.5.14', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Works next to your other MCP plugins', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Royal MCP now recognises other MCP plugins on the site, including ones that bring their own sign-in, and keeps answering at its own addresses beside them, so Claude and ChatGPT connect to the right server.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'AI clients that were connected earlier move to the same sign-in address automatically, and the sign-in self-check names anything that still gets in the way.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="k">wp_get_mcp_coexistence</span>
  <span class="s"><?php esc_html_e( 'other MCP servers', 'royal-mcp' ); ?></span>   <span class="c">2</span>
  <span class="s"><?php esc_html_e( 'Royal MCP sign-in', 'royal-mcp' ); ?></span>   <span class="s hl"><?php esc_html_e( 'own address', 'royal-mcp' ); ?> &#10003;</span>
<span class="k">&rarr;</span> <span class="c"><?php esc_html_e( 'Claude connects to Royal MCP', 'royal-mcp' ); ?></span></div>
                </div>
            </div>

            <!-- SLIDE 3 — SIGN-IN AND KEYS -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="c"><?php esc_html_e( '// Password changed', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'connected AI apps', 'royal-mcp' ); ?></span>   <span class="s hl"><?php esc_html_e( 'signed out', 'royal-mcp' ); ?> &#10003;</span>
<span class="c"><?php esc_html_e( '// Settings → API key', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'Turn off', 'royal-mcp' ); ?></span>            <span class="c"><?php esc_html_e( 'key refused', 'royal-mcp' ); ?></span></div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Sign-in and keys', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'You stay in control of every connection', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Changing or resetting a password signs that user out of every connected AI app. The API key can now be turned off on the Settings screen, and an AI app you reject stays rejected.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'If you send the API key in the old X-Royal-MCP-API-Key header, switch to Authorization: Bearer, which is what most AI clients already use.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 4 — UNDO THAT KEEPS LATER EDITS -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Undo', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Undo that keeps your later edits', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Deleting a page or a custom field can now be undone. When someone has edited an Elementor page, a Divi image or template change, or a field after the AI changed it, undo leaves that newer edit alone and says so.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Elementor page data saved by an administrator is stored exactly as sent, and text replaced in blocks never touches the page markup.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="k">wp_delete_page</span>
  <span class="s">undo</span>   <span class="c"><?php esc_html_e( 'available', 'royal-mcp' ); ?></span>
<span class="k">mcp_undo_last_operation</span>
  <span class="s hl"><?php esc_html_e( 'page restored', 'royal-mcp' ); ?> &#10003;</span>
  <span class="c"><?php esc_html_e( 'later edits kept', 'royal-mcp' ); ?></span></div>
                </div>
            </div>

        </div>

        <!-- PRO UPSELL FOOTER -->
        <div class="rmcp-wn-upsell">
            <div class="rmcp-wn-upsell-copy">
                <h4><?php esc_html_e( 'Ready to let AI do more on every client site?', 'royal-mcp' ); ?></h4>
                <p>
                    <?php esc_html_e( 'Royal MCP Pro has 400+ tools: bulk edits for WooCommerce, Elementor and Divi with layouts that stay editable, ACF setup, one-call SEO launches, per-connection tool sets and a 90-day activity log your clients can inspect.', 'royal-mcp' ); ?>
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
