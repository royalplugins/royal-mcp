<?php
/**
 * Royal MCP — What's New modal template.
 *
 * Whats-New-For-Version: 1.5.12
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
$rmcp_wn_pro_url       = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_12&utm_content=footer_cta';
$rmcp_wn_pro_slide_url = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_12&utm_content=slide_1_cta';
$rmcp_wn_offer_open    = current_datetime()->format( 'Y-m-d' ) <= \Royal_MCP\Admin\Pro_Notice::OFFER_ENDS;
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( '1.5.12: Native Sign-in Address & updated conflict detection', 'royal-mcp' ); ?></p>
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
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Royal MCP Pro', 'royal-mcp' ); ?></span>
                    <p class="rmcp-wn-big-number"><?php esc_html_e( 'Run AI across real client work', 'royal-mcp' ); ?></p>
                    <h3><?php esc_html_e( '350+ tools, bulk edits and a full audit trail', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Bulk-edit WooCommerce prices, stock and orders, build Elementor and Divi layouts that stay editable in the builder, set up ACF field groups and run a whole SEO launch in one call. Give each AI connection only the tools it needs, and hand clients a 90-day log of everything the AI did, with undo.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Royally, one dashboard for every client site, is in beta and coming soon to Royal MCP Pro members.', 'royal-mcp' ); ?></p>
                    <a href="<?php echo esc_url( $rmcp_wn_pro_slide_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-btn">
                        <?php esc_html_e( 'See Pro features →', 'royal-mcp' ); ?>
                    </a>
                </div>
            </div>

            <!-- SLIDE 2 — OWN SIGN-IN ADDRESS (the 1.5.12 headline) -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v1.5.12', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Claude and ChatGPT connect, whatever else is installed', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Royal MCP now signs AI clients in at its own address, so another plugin with its own MCP sign-in can no longer catch the connection. Connections you already have keep working.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'If something on the site still answers part of the sign-in, the Royal MCP screens name the plugin and show how to fix it.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="c"><?php esc_html_e( '// sign-in check', 'royal-mcp' ); ?></span>
<span class="k"><?php esc_html_e( 'challenge', 'royal-mcp' ); ?></span>       <span class="c">Royal MCP</span> &#10003;
<span class="k"><?php esc_html_e( 'resource', 'royal-mcp' ); ?></span>        <span class="c">Royal MCP</span> &#10003;
<span class="k"><?php esc_html_e( 'sign-in server', 'royal-mcp' ); ?></span>  <span class="c">Royal MCP</span> &#10003;
<span class="k"><?php esc_html_e( 'authorize', 'royal-mcp' ); ?></span>       <span class="s hl">Royal MCP &#10003;</span></div>
                </div>
            </div>

            <!-- SLIDE 3 — SECURITY CHECKLIST -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="k">wp_security_triage</span>
  <span class="s">since</span>             <span class="c">2026-10-20</span>
  <span class="s">must_use</span>          <span class="c">1</span>
  <span class="s hl"><?php esc_html_e( 'unlisted folders', 'royal-mcp' ); ?>  1</span>
  <span class="s"><?php esc_html_e( 'extra caps', 'royal-mcp' ); ?></span>        <span class="c">0</span>
<span class="k">&rarr;</span> <span class="c"><?php esc_html_e( '1 indicator found among the checks run', 'royal-mcp' ); ?></span></div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Security check', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Ask your AI client to check the site after a plugin scare', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'One read-only call lists must-use plugins and drop-ins, plugin folders WordPress does not list, plugins changed since a date, users with capabilities beyond their role and leftover update files.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'It reports facts, never passwords, emails or option values, and it never says a site is clean: it says what it checked.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 4 — CLONE AS DRAFT -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Drafts only', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Change a live page without touching it', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'wp_clone_post_as_draft copies a post or page into a new draft with its content, featured image, categories and custom fields. Your AI client edits the copy, and the live page stays as it is until you publish.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'With Drafts only on, that is now the way to prepare changes to published content, and undo moves the copy to the trash.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="k">wp_clone_post_as_draft</span>
  <span class="s">id</span>         <span class="c">42</span>
<span class="k">&rarr;</span> <span class="s hl"><?php esc_html_e( 'draft 87 created', 'royal-mcp' ); ?></span>
  <span class="s"><?php esc_html_e( 'original', 'royal-mcp' ); ?></span>   <span class="c"><?php esc_html_e( 'unchanged', 'royal-mcp' ); ?></span>
  <span class="s">undo</span>       <span class="c"><?php esc_html_e( 'token returned', 'royal-mcp' ); ?></span></div>
                </div>
            </div>

        </div>

        <!-- PRO UPSELL FOOTER -->
        <div class="rmcp-wn-upsell">
            <div class="rmcp-wn-upsell-copy">
                <h4><?php esc_html_e( 'Ready to let AI do more on every client site?', 'royal-mcp' ); ?></h4>
                <p>
                    <?php esc_html_e( 'Royal MCP Pro adds 350+ tools: bulk edits for WooCommerce, Elementor and Divi with layouts that stay editable, ACF setup, one-call SEO launches, per-connection tool sets and a 90-day activity log your clients can inspect. Royally, one dashboard for every client site, is in beta and coming soon to Pro members.', 'royal-mcp' ); ?>
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
