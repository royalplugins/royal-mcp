<?php
/**
 * Royal MCP — What's New modal template.
 *
 * Whats-New-For-Version: 1.5.9
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
$rmcp_wn_pro_url       = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_9&utm_content=footer_cta';
$rmcp_wn_pro_slide_url = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_9&utm_content=slide_1_cta';
$rmcp_wn_offer_open    = current_datetime()->format( 'Y-m-d' ) <= \Royal_MCP\Admin\Pro_Notice::OFFER_ENDS;
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( '1.5.9: drafts only, change history, block tools.', 'royal-mcp' ); ?></p>
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

            <!-- SLIDE 2 — DRAFTS-ONLY MODE + ACCESS LEVELS (the 1.5.9 headline) -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v1.5.9', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Drafts only: AI writes the drafts, a person publishes', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Turn on Drafts only in Royal MCP settings and AI clients can create posts and pages as drafts and edit drafts and pending posts, but cannot publish, change a live page, or touch settings, menus, users or plugins. To change a live page, the AI clones it as a draft and you publish the copy when it looks right.', 'royal-mcp' ); ?></p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>An access level for each connection.</strong> Give each AI client its own level, Full, Read-only or Drafts only, on the Connected Clients page, and set one for the WordPress API key in settings.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="c"><?php esc_html_e( '// Drafts only is on', 'royal-mcp' ); ?></span>
<span class="k">wp_update_post</span>  <span class="c">id 42</span>

<span class="s hl"><?php esc_html_e( 'post 42 is published.', 'royal-mcp' ); ?></span>
<span class="s hl"><?php esc_html_e( 'Clone it as a draft', 'royal-mcp' ); ?></span>
<span class="s hl"><?php esc_html_e( 'and edit the copy.', 'royal-mcp' ); ?></span>

<span class="k">elementor_clone_page</span>
  <span class="s">source_post_id</span> <span class="c">42</span>
  <span class="s">new_status</span>     <span class="c">draft</span>
<span class="k">&rarr;</span> <span class="c"><?php esc_html_e( 'draft 57 ready to edit', 'royal-mcp' ); ?> &#10003;</span></div>
                </div>
            </div>

            <!-- SLIDE 3 — RECENT CHANGES -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="c"><?php esc_html_e( '// Recent Changes', 'royal-mcp' ); ?></span>
<span class="k">wp_update_post</span>  <span class="c"><?php esc_html_e( 'Claude, 10:42', 'royal-mcp' ); ?></span>
<span class="s"><?php esc_html_e( 'Pricing page', 'royal-mcp' ); ?></span>

<span class="c">@@ before line 12 @@</span>
<span class="s">- <?php esc_html_e( 'Plans start at $19', 'royal-mcp' ); ?></span>
<span class="k">+ <?php esc_html_e( 'Plans start at $29', 'royal-mcp' ); ?></span>

<span class="c"><?php esc_html_e( 'Undo restores: content', 'royal-mcp' ); ?></span>
<span class="c"><?php esc_html_e( 'Status: can be undone', 'royal-mcp' ); ?></span></div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Recent Changes', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'See exactly what AI changed, and who made the change', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Royal MCP > Recent Changes lists every change AI clients made in the last 72 hours that can be undone: when, which tool, what it changed and which connection made it. Open one to see each value before the change, what the change wrote and what it is now, with a line diff for post content, CSS and page builder data.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'AI clients get the same view through two new tools, so an assistant can check its own work and undo a change that went wrong.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 4 — BLOCK EDITOR TOOLS -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Block editor', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Edit block pages one block at a time', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'AI clients can now read a page as its block tree and insert, replace, move or delete single blocks, change a block\'s settings, or replace text across blocks, without rewriting the whole page. Synced patterns, global styles and Site Editor templates can be read too, and synced patterns edited.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Every block change is checked before it is saved, so the page still opens cleanly in the editor, and each one can be undone for 72 hours.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone"><span class="k">wp_get_block_outline</span>  <span class="c">id 42</span>

  <span class="s">0</span>    <span class="c">core/cover</span>
  <span class="s">0.0</span>  <span class="c">core/heading</span>
  <span class="s">1</span>    <span class="c">core/columns</span>
  <span class="s hl">1.0</span>  <span class="c">core/paragraph</span>

<span class="k">wp_replace_block</span>
  <span class="s">path</span>  <span class="c">1.0</span>
<span class="k">&rarr;</span> <span class="c"><?php esc_html_e( 'saved, undo available', 'royal-mcp' ); ?> &#10003;</span></div>
                </div>
            </div>

            <!-- SLIDE 5 — ALSO NEW -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-mark" role="img" aria-label="Royal Plugins">
                        <span>R</span>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Also new', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Coding agents, media without duplicates, safer uninstall', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Connect a coding agent.</strong> The settings page has ready-to-copy commands that connect Claude Code or Codex to this site, with sign-in in the browser or the API key.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Media without duplicates.</strong> AI clients can find a file already in your media library by its address or its content, and reuse it instead of uploading a second copy. Media details now include file size and dimensions.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>SEO fields that stick.</strong> Titles and descriptions for Yoast, Rank Math and SEObolt go through the SEO tool and are read back after saving.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Keep your data on uninstall.</strong> Deleting the plugin now keeps your settings, activity log and connected clients unless you turn on "Delete all data when the plugin is deleted".', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
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
