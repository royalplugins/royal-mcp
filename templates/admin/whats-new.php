<?php
/**
 * Royal MCP — What's New modal template.
 *
 * Whats-New-For-Version: 1.5.15
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
$rmcp_wn_pro_url       = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_15&utm_content=footer_cta';
$rmcp_wn_pro_slide_url = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_15&utm_content=slide_1_cta';
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( '1.5.15: MailPoet, Forms and Safer Undo', 'royal-mcp' ); ?></p>
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

            <!-- SLIDE 2 — NEW INTEGRATIONS (the 1.5.15 headline) -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v1.5.15', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'MailPoet, Relevanssi, Ninja Forms and Fluent Forms', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Your AI agent can now manage MailPoet lists, subscribers and newsletter drafts, send a test, read stats and schedule a send, which only goes out when it is confirmed with the exact number of recipients.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( "Relevanssi tools search with Relevanssi's own ranking and edit synonyms and stopwords with undo, and form entries from Ninja Forms, Fluent Forms and Contact Form 7 come back with names, emails, phone numbers and addresses hidden.", 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="k">mailpoet_schedule_newsletter</span>
  <span class="s">expected_recipients</span>   <span class="c">1,240</span>
  <span class="s"><?php esc_html_e( 'live count', 'royal-mcp' ); ?></span>            <span class="s hl">1,240 &#10003;</span>
<span class="k">&rarr;</span> <span class="c"><?php esc_html_e( 'scheduled', 'royal-mcp' ); ?></span></div>
                </div>
            </div>

            <!-- SLIDE 3 — CONNECTIONS -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="c"><?php esc_html_e( '// Connected Clients', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'Application Passwords', 'royal-mcp' ); ?></span>   <span class="c">2</span>
  <span class="s"><?php esc_html_e( '"Old laptop"', 'royal-mcp' ); ?></span>            <span class="s hl"><?php esc_html_e( 'Block', 'royal-mcp' ); ?> &#10003;</span>
<span class="c"><?php esc_html_e( '// Settings → Advanced', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'Browser agents', 'royal-mcp' ); ?></span>          <span class="c"><?php esc_html_e( 'delete tools off', 'royal-mcp' ); ?></span></div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Connections', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'See and control every way in', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Connected Clients now lists Application Password sign-ins, so you can block one from Royal MCP or delete it in WordPress.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'In Settings you can switch off any tool that changes the site for browser-agent connections, and Royal MCP can keep a record of PHP fatal errors that your AI agent reads with wp_get_php_errors.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 4 — UNDO AND RECENT EDITS -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Undo', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Undo that keeps your later edits, everywhere', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Undo now checks for later edits on WooCommerce products, coupons and orders, comments, widgets, theme settings, permalinks and redirects, and leaves a newer edit alone unless you ask it to force the change back.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'wp_get_posts and wp_get_pages show when each item last changed and can sort by it, so "what was edited this week" is one call.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="k">wp_get_posts</span>
  <span class="s">orderby</span>          <span class="c">modified</span>
  <span class="s">modified_after</span>   <span class="c">2026-11-01</span>
<span class="k">mcp_undo_last_operation</span>
  <span class="s hl"><?php esc_html_e( 'later edits kept', 'royal-mcp' ); ?> &#10003;</span></div>
                </div>
            </div>

        </div>

        <!-- PRO UPSELL FOOTER -->
        <div class="rmcp-wn-upsell">
            <div class="rmcp-wn-upsell-copy">
                <h4><?php esc_html_e( 'Ready to let AI do more on every client site?', 'royal-mcp' ); ?></h4>
                <p>
                    <?php esc_html_e( 'Royal MCP Pro has 400+ tools: bulk edits for WooCommerce, Elementor and Divi with layouts that stay editable, ACF setup, one-call SEO launches, per-connection tool sets and a 90-day activity log your clients can inspect.', 'royal-mcp' ); ?>
                </p>
            </div>
            <a href="<?php echo esc_url( $rmcp_wn_pro_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-upsell-btn">
                <?php esc_html_e( 'Upgrade to Pro →', 'royal-mcp' ); ?>
            </a>
        </div>

    </div>
</div>
