<?php
/**
 * Royal MCP — What's New modal template.
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

$rmcp_wn_img_base   = ROYAL_MCP_PLUGIN_URL . 'assets/img/whats-new/';
$rmcp_wn_review_url = 'https://wordpress.org/support/plugin/royal-mcp/reviews/?rate=5#new-post';
$rmcp_wn_help_url   = admin_url( 'admin.php?page=royal-mcp-help&view=troubleshooting' );
$rmcp_wn_pro_url    = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_5&utm_content=footer_cta';
$rmcp_wn_pro_slide_url = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_5&utm_content=slide_1_cta';
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( 'Version 1.5.5: Safer Writes, Page Verification, and Compact Tool Discovery', 'royal-mcp' ); ?></p>
            </div>
            <button type="button" class="rmcp-wn-close" data-royal-mcp-wn-close aria-label="<?php esc_attr_e( 'Close', 'royal-mcp' ); ?>">&times;</button>
        </div>

        <div class="rmcp-wn-slides">

            <!-- SLIDE 1 — ROYAL MCP PRO PITCH (flagship marketing spot) -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle is-confetti">
                        <img src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="Royal MCP Pro">
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Royal MCP Pro', 'royal-mcp' ); ?></span>
                    <p class="rmcp-wn-big-number"><?php esc_html_e( 'Supercharge your workflow', 'royal-mcp' ); ?></p>
                    <h3><?php esc_html_e( '300+ MCP tools, bulk operations, undo tokens — agency ready', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( "Bulk-edit thousands of WooCommerce products or Elementor pages in a single tool call. Manage ACF field groups programmatically. Scope MCP endpoints per project so your AI's write access can't cross client boundaries.", 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( "Every destructive Pro tool returns an undo token good for 3\xE2\x80\x937 days. A 90-day activity log lets clients audit what your AI actually touched. Priority support, no data sharing, no token pricing.", 'royal-mcp' ); ?></p>
                    <a href="<?php echo esc_url( $rmcp_wn_pro_slide_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-btn">
                        <?php esc_html_e( 'See Pro features →', 'royal-mcp' ); ?>
                    </a>
                </div>
            </div>

            <!-- SLIDE 2 — DRY-RUN PREVIEW MODE (safer writes) -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Preview First', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'See the effect before you commit', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( 'Two of the highest-blast-radius write tools now accept a <code>dry_run</code> flag. <code>wp_update_option</code> preview returns the current value, the proposed value, whether the option is autoloaded, and the size delta in bytes. <code>wp_update_permalink_structure</code> preview returns the current and proposed structures, every public post type that would resolve through them, and a sample of current URLs.', 'royal-mcp' ),
                            [ 'code' => [] ]
                        );
                        ?>
                    </p>
                    <p><?php esc_html_e( 'The preview response never writes and never flushes rewrite rules — the caller gets the full picture and can decide whether to run the real call in a second step.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Great for AI agents that want to explain the change to the site owner before executing it, and for scripted deployments that want a plan-then-apply flow.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone">
<span class="c">// dry_run preview response</span>
<span class="k">{</span>
  <span class="s hl">"state"</span>: <span class="s">"dry_run"</span>,
  <span class="s">"preview"</span>: <span class="k">{</span>
    <span class="s">"option_name"</span>: <span class="s">"blogname"</span>,
    <span class="s">"current_value"</span>: <span class="s">"My Site"</span>,
    <span class="s">"proposed_value"</span>: <span class="s">"New Name"</span>,
    <span class="s">"is_autoloaded"</span>: <span class="k">true</span>,
    <span class="s">"size_delta_bytes"</span>: <span class="k">+1</span>
  <span class="k">}</span>,
  <span class="s">"would_execute"</span>: <span class="k">true</span>
<span class="k">}</span>
                    </div>
                </div>
            </div>

            <!-- SLIDE 3 — WP_VERIFY_RENDERED_PAGE -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle" role="img" aria-label="<?php esc_attr_e( 'Rendered page verification', 'royal-mcp' ); ?>">
                        <svg viewBox="0 0 200 200" width="170" height="170" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                            <rect x="24" y="30" width="152" height="140" rx="10" fill="#FEFCF7" stroke="#C9A227" stroke-width="2.5"/>
                            <rect x="24" y="30" width="152" height="22" rx="10" fill="#C9A227"/>
                            <circle cx="36" cy="41" r="3" fill="#FEFCF7"/>
                            <circle cx="46" cy="41" r="3" fill="#FEFCF7"/>
                            <circle cx="56" cy="41" r="3" fill="#FEFCF7"/>
                            <rect x="68" y="37" width="98" height="8" rx="2" fill="#FEFCF7" opacity="0.35"/>
                            <text x="36" y="72" font-family="Inter, sans-serif" font-size="8" font-weight="700" fill="#2C2C2C">TITLE</text>
                            <rect x="36" y="76" width="90" height="6" rx="1" fill="#2271B1"/>
                            <text x="36" y="98" font-family="Inter, sans-serif" font-size="8" font-weight="700" fill="#2C2C2C">META DESCRIPTION</text>
                            <rect x="36" y="102" width="120" height="4" rx="1" fill="#787c82"/>
                            <rect x="36" y="109" width="105" height="4" rx="1" fill="#787c82"/>
                            <text x="36" y="130" font-family="Inter, sans-serif" font-size="8" font-weight="700" fill="#2C2C2C">H1</text>
                            <rect x="36" y="134" width="70" height="6" rx="1" fill="#2271B1"/>
                            <line x1="36" y1="150" x2="164" y2="150" stroke="#dcdcde" stroke-width="0.6"/>
                            <text x="36" y="162" font-family="monospace" font-size="6.5" fill="#2C2C2C">script:</text>
                            <text x="72" y="162" font-family="monospace" font-size="6.5" font-weight="700" fill="#00a32a">12</text>
                            <text x="90" y="162" font-family="monospace" font-size="6.5" fill="#2C2C2C">style:</text>
                            <text x="120" y="162" font-family="monospace" font-size="6.5" font-weight="700" fill="#00a32a">8</text>
                            <text x="138" y="162" font-family="monospace" font-size="6.5" fill="#2C2C2C">200 OK</text>
                        </svg>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Post-write check', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Verify what WordPress actually served', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( 'New <code>wp_verify_rendered_page</code> tool fetches a URL on this site via a loopback request and reports the response status, headers subset, page title, meta description, first heading, script and stylesheet counts, and an optional 500-character body excerpt. Optional selector arg checks whether an <code>#id</code> or <code>.class</code> is present in the served HTML.', 'royal-mcp' ),
                            [ 'code' => [] ]
                        );
                        ?>
                    </p>
                    <p><?php esc_html_e( 'Use it after any write to catch cases where a page-builder cache, object cache, or edge cache is still serving stale HTML even though the DB write returned success.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Rate-limited to ten fetches per minute per URL so an agent in a retry loop can\'t turn your site into its own load tester.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 4 — COMPACT TOOL DISCOVERY -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Smaller context, faster start', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Discover 300+ tools without loading 300+ schemas', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( 'Set the request header <code>X-MCP-Profile: compact</code> and the <code>tools/list</code> response advertises only three routing tools: <code>discover_tools</code> (filter by category, plugin, capability class, or undo support), <code>get_tool_info</code> (return the full inputSchema for a single named tool), and <code>execute_tool</code> (pass-through dispatcher).', 'royal-mcp' ),
                            [ 'code' => [] ]
                        );
                        ?>
                    </p>
                    <p><?php esc_html_e( 'Context-limited clients no longer pay for hundreds of full schemas at connection time. Callers pull just the schema they need at the moment they need it, then dispatch through the same connection.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Clients that keep the header off see the existing flat tool list unchanged — no breaking change for anything that\'s already connected.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-mark" role="img" aria-label="Royal Plugins">
                        <span>R</span>
                    </div>
                </div>
            </div>

            <!-- SLIDE 5 — HARDENING + POLISH -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-mark" role="img" aria-label="Royal Plugins">
                        <span>R</span>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Under the hood', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Tighter authorization + audit trails', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>API key hashed at rest.</strong> Only the SHA-256 digest of your key sits in the settings option. The full key is shown once at generation via a short-lived reveal transient — save it in your client config the same way you would a GitHub PAT.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Capability gates on post + meta writes.</strong> Status transitions to publish now check publish_posts, author reassignment checks edit_others_posts, and writes to protected meta keys (underscore-prefixed and plugin-owned) require edit_post_meta on that specific key.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Response payloads carry saved_fields.</strong> Every write tool reads the row back after committing and returns what actually landed, so silent modifications by WP core hooks or third-party filters surface in the tool response instead of getting lost.', 'royal-mcp' ),
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
                <h4><?php esc_html_e( 'Ready to scale MCP across every client site?', 'royal-mcp' ); ?></h4>
                <p>
                    <?php
                    echo wp_kses(
                        __( 'Royal MCP Pro includes 300+ MCP tools with bulk operations, per-project endpoint scoping, advanced integrations for WooCommerce and Elementor, undo tokens on destructive actions, and a 90-day Activity Log clients can inspect. <strong>30-50% off launch price.</strong>', 'royal-mcp' ),
                        [ 'strong' => [] ]
                    );
                    ?>
                </p>
            </div>
            <a href="<?php echo esc_url( $rmcp_wn_pro_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-upsell-btn">
                <?php esc_html_e( 'Upgrade to Pro →', 'royal-mcp' ); ?>
            </a>
        </div>

    </div>
</div>
