<?php
/**
 * Royal MCP — What's New modal template.
 *
 * Whats-New-For-Version: 1.5.10
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
$rmcp_wn_pro_url       = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_10&utm_content=footer_cta';
$rmcp_wn_pro_slide_url = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_10&utm_content=slide_1_cta';
$rmcp_wn_offer_open    = current_datetime()->format( 'Y-m-d' ) <= \Royal_MCP\Admin\Pro_Notice::OFFER_ENDS;
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( '1.5.10: who can connect, three new integrations, WooCommerce paging.', 'royal-mcp' ); ?></p>
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
                    <p class="rmcp-wn-big-number"><?php esc_html_e( 'Let your AI do more, safely', 'royal-mcp' ); ?></p>
                    <h3><?php esc_html_e( 'Previews, 7-day undo and bulk edits', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'See every destructive change before it runs and undo it for up to 7 days. Bulk-edit WooCommerce catalogs and Elementor or Divi sites with layouts that stay editable in the builder, and hand clients a 90-day log of everything the AI did.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Royally, one dashboard for every client site, is in beta and coming soon to Royal MCP Pro members.', 'royal-mcp' ); ?></p>
                    <a href="<?php echo esc_url( $rmcp_wn_pro_slide_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-btn">
                        <?php esc_html_e( 'See Pro features →', 'royal-mcp' ); ?>
                    </a>
                </div>
            </div>

            <!-- SLIDE 2 — WHO CAN CONNECT (the 1.5.10 headline) -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v1.5.10', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'You choose who can connect AI to your site', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Two new settings under Royal MCP > Settings decide which WordPress users can connect an AI client, and which can use Royal MCP tools through other MCP servers and browser agents. Both start at Administrators only.', 'royal-mcp' ); ?></p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Editors connect on your site?</strong> Choose "Editors and above" in both settings and they carry on as before.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p><?php esc_html_e( 'On every connection, AI clients only see and change the posts, pages, comments and templates their WordPress account is allowed to.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="c"><?php esc_html_e( '// Royal MCP > Settings', 'royal-mcp' ); ?></span>
<span class="k"><?php esc_html_e( 'Who can connect AI clients', 'royal-mcp' ); ?></span>
  <span class="s hl">&#9679; <?php esc_html_e( 'Administrators only', 'royal-mcp' ); ?></span>
  <span class="c">&#9675; <?php esc_html_e( 'Editors and above', 'royal-mcp' ); ?></span>
  <span class="c">&#9675; <?php esc_html_e( 'Any user', 'royal-mcp' ); ?></span>

<span class="k"><?php esc_html_e( 'Other MCP servers and', 'royal-mcp' ); ?></span>
<span class="k"><?php esc_html_e( 'browser agents', 'royal-mcp' ); ?></span>
  <span class="s hl">&#9679; <?php esc_html_e( 'Administrators only', 'royal-mcp' ); ?></span></div>
                </div>
            </div>

            <!-- SLIDE 3 — TABLEPRESS, CODE SNIPPETS, WPCODE -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="k">tablepress_update_cells</span>
  <span class="s">table_id</span>  <span class="c">3</span>
  <span class="s">row 2, column 2</span>
    <span class="c">$19</span> <span class="k">&rarr;</span> <span class="c">$29</span>
  <span class="s">dry_run</span>   <span class="c">true</span>
<span class="k">&rarr;</span> <span class="c"><?php esc_html_e( '1 cell would change', 'royal-mcp' ); ?> &#10003;</span>

<span class="k">snippets_get</span>  <span class="c">id 12</span>
  <span class="s">$api_key =</span> <span class="s hl">'[REDACTED]'</span></div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New integrations', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'TablePress, Code Snippets and WPCode', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>TablePress.</strong> AI clients can list, read, create, copy and export tables and change cells, with a dry run first and undo afterwards.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Code Snippets and WPCode.</strong> Read your snippets and the errors they raised, and deactivate a Code Snippets snippet that breaks the site. Passwords and API keys in snippet code are hidden, and Royal MCP never writes or runs snippet code.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                </div>
            </div>

            <!-- SLIDE 4 — WOOCOMMERCE PAGING -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'WooCommerce', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Whole catalogs, page by page', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Product, customer and variation lists now come in pages with a total, so an AI client can check stock or prices across a catalog of any size instead of the first 100 items.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Products can also be filtered by ID, SKU and stock status, and every row shows its stock quantity.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="k">wc_get_products</span>
  <span class="s">category</span>      <span class="c">shoes</span>
  <span class="s">stock_status</span>  <span class="c">instock</span>
  <span class="s">page</span>          <span class="c">2</span>
  <span class="s">per_page</span>      <span class="c">100</span>

<span class="k">&rarr;</span> <span class="c"><?php esc_html_e( 'page 2 of 3', 'royal-mcp' ); ?></span>
  <span class="s hl"><?php esc_html_e( 'total 250 products', 'royal-mcp' ); ?></span> &#10003;</div>
                </div>
            </div>

        </div>

        <!-- PRO UPSELL FOOTER -->
        <div class="rmcp-wn-upsell">
            <div class="rmcp-wn-upsell-copy">
                <h4><?php esc_html_e( 'Ready to let AI do more on every client site?', 'royal-mcp' ); ?></h4>
                <p>
                    <?php esc_html_e( 'Royal MCP Pro adds change previews, 7-day undo, bulk edits for WooCommerce, Elementor and Divi with layouts that stay editable, and a 90-day activity log your clients can inspect. Royally, one dashboard for every client site, is in beta and coming soon to Pro members.', 'royal-mcp' ); ?>
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
