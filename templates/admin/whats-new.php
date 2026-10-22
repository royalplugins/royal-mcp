<?php
/**
 * Royal MCP — What's New modal template.
 *
 * Whats-New-For-Version: 1.5.11
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
$rmcp_wn_pro_url       = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_11&utm_content=footer_cta';
$rmcp_wn_pro_slide_url = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_11&utm_content=slide_1_cta';
$rmcp_wn_dev_url       = 'https://royalplugins.com/support/royal-mcp/add-your-plugin-tools/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_11&utm_content=slide_3_developers';
$rmcp_wn_offer_open    = current_datetime()->format( 'Y-m-d' ) <= \Royal_MCP\Admin\Pro_Notice::OFFER_ENDS;
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( '1.5.11: Complianz, other MCP tools, site checks.', 'royal-mcp' ); ?></p>
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

            <!-- SLIDE 2 — COMPLIANZ (the 1.5.11 headline) -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v1.5.11', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Complianz: check your consent setup in one question', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Ask your AI client whether the cookie banner shows to visitors, which regions you cover, which cookies still have no description and whether your cookie policy is in place.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'It can also rewrite the banner texts and describe your cookies for the cookie policy, with undo for every change. Scripts, custom CSS and consent records stay out of reach.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="k">complianz_status</span>
  <span class="s">regions</span>          <span class="c">eu</span>
  <span class="s">banner shows</span>     <span class="c">yes</span> &#10003;
  <span class="s hl"><?php esc_html_e( 'cookies without a description', 'royal-mcp' ); ?>  3</span>

<span class="k">complianz_update_cookie_description</span>
  <span class="s">_ga</span>  <span class="c"><?php esc_html_e( 'Counts visits', 'royal-mcp' ); ?></span>
<span class="k">&rarr;</span> <span class="c"><?php esc_html_e( 'saved, undo token returned', 'royal-mcp' ); ?></span></div>
                </div>
            </div>

            <!-- SLIDE 3 — WORKS ALONGSIDE OTHER MCP TOOLS + DEVELOPERS -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="c"><?php esc_html_e( '// one AI client, several MCP tools', 'royal-mcp' ); ?></span>
<span class="k">Elementor MCP</span>    <span class="c"><?php esc_html_e( 'new layouts', 'royal-mcp' ); ?></span>
<span class="k">WooCommerce MCP</span>  <span class="c"><?php esc_html_e( 'store basics', 'royal-mcp' ); ?></span>
<span class="k">Royal MCP</span>        <span class="s hl"><?php esc_html_e( 'everything else', 'royal-mcp' ); ?></span>

<span class="c"><?php esc_html_e( '// your plugin, two filters', 'royal-mcp' ); ?></span>
<span class="s">royal_mcp_tools</span>
<span class="s">royal_mcp_execute_tool_external</span></div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Works with other MCP tools', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Built to sit next to the MCP tools you already use', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Royal MCP answers on its own endpoint and never switches off another plugin\'s tools, so your AI client can use Elementor\'s and WooCommerce\'s own MCP tools for what they do best and Royal MCP for the rest.', 'royal-mcp' ); ?></p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Build a WordPress plugin?</strong> Add its tools to Royal MCP with two filters. They follow read-only mode, drafts-only mode and each connection\'s access level like Royal MCP\'s own tools.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <a href="<?php echo esc_url( $rmcp_wn_dev_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-btn">
                        <?php esc_html_e( 'Read the developer guide →', 'royal-mcp' ); ?>
                    </a>
                </div>
            </div>

            <!-- SLIDE 4 — SPOT WHAT DOESN'T BELONG + API KEY HEADER -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Site checks', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Spot what doesn\'t belong', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'The plugin list now includes must-use plugins and drop-ins with the date each was last changed, and the user list shows when each account was created and any capabilities granted beyond its role.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'WooCommerce coupon lists come in pages with a total, and orders can be filtered by date.', 'royal-mcp' ); ?></p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Using the X-Royal-MCP-API-Key header?</strong> Switch the connection to Authorization: Bearer. The old header still works in this release, and Royal MCP shows a notice while a connection uses it.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="k">wp_get_plugins</span>
  <span class="s">type</span>         <span class="c">must_use</span>
  <span class="s">file</span>         <span class="c">loader.php</span>
  <span class="s hl">modified_at  <?php esc_html_e( 'yesterday', 'royal-mcp' ); ?></span>

<span class="k">wp_get_users</span>
  <span class="s">roles</span>        <span class="c">subscriber</span>
  <span class="s hl">extra_caps   manage_options</span></div>
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
