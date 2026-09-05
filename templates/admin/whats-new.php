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
$rmcp_wn_pro_url    = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_0&utm_content=footer_cta';
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( "Here's what changed and what's coming next.", 'royal-mcp' ); ?></p>
            </div>
            <button type="button" class="rmcp-wn-close" data-royal-mcp-wn-close aria-label="<?php esc_attr_e( 'Close', 'royal-mcp' ); ?>">&times;</button>
        </div>

        <div class="rmcp-wn-slides">

            <!-- SLIDE 1 — 100K DOWNLOADS -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle is-confetti" data-royal-mcp-wn-confetti>
                        <img src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="Royal Plugins">
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Milestone', 'royal-mcp' ); ?></span>
                    <p class="rmcp-wn-big-number"><?php esc_html_e( '100,000 Downloads', 'royal-mcp' ); ?></p>
                    <h3><?php esc_html_e( 'Thank You!', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'We recently crossed over 100,000 downloads and wanted to say thank you for using our tool. This has always been a dream of ours, and we\'re excited to keep building alongside you.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Every install, tool call, and support ticket helps us build a better plugin. Here\'s to the next 100k!', 'royal-mcp' ); ?></p>
                    <a href="<?php echo esc_url( $rmcp_wn_review_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-btn">
                        <?php esc_html_e( 'Leave us a review →', 'royal-mcp' ); ?>
                    </a>
                </div>
            </div>

            <!-- SLIDE 2 — MCP 2026-07-28 STANDARD -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Modern MCP', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Speaking the modern MCP wire', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '1.5.0 is our first step toward the <strong>MCP 2026-07-28 spec revision</strong> — the largest protocol change since MCP launched. The upgrade is a multi-release journey that ends in a 2.0 full changeover once every peer client has caught up.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>What changes today:</strong> newer AI clients (Claude.ai, Claude Code, ChatGPT connectors) can negotiate the modern wire on request. Stateless per-request transport removes the session-header requirement that was breaking recent Claude.ai connections.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>What doesn\'t change:</strong> every tool you use, every existing connection, every 1.4.x client. Fully backwards compatible.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone">
<span class="c">// initialize handshake response</span>
<span class="k">{</span>
  <span class="s">"jsonrpc"</span>: <span class="s">"2.0"</span>,
  <span class="s">"id"</span>: 1,
  <span class="s">"result"</span>: <span class="k">{</span>
    <span class="s">"protocolVersion"</span>: <span class="s hl">"2026-07-28"</span>,
    <span class="s">"serverInfo"</span>: <span class="k">{</span>
      <span class="s">"name"</span>: <span class="s">"Royal MCP"</span>,
      <span class="s">"version"</span>: <span class="s">"1.5.0"</span>
    <span class="k">}</span>
  <span class="k">}</span>
<span class="k">}</span>
                    </div>
                </div>
            </div>

            <!-- SLIDE 3 — HELP + TROUBLESHOOTING IMPROVEMENTS -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle is-screenshot">
                        <img src="<?php echo esc_url( $rmcp_wn_img_base . 'help-getting-started.png' ); ?>" alt="Royal MCP Help">
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Better diagnostics', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Faster answers when something breaks', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'New dedicated Help section walks through quick setup guides and common issues you will face setting up your MCP. Activity Log records every MCP method call (initialize, tools/list, ping) so diagnosing a stuck connection takes seconds instead of hours.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Admin notices auto-detect host and plugin configurations that block MCP (Perfmatters REST-API disable, SiteGround .well-known reservation, WAF interception) before they become support tickets.', 'royal-mcp' ); ?></p>
                    <a href="<?php echo esc_url( $rmcp_wn_help_url ); ?>" class="rmcp-wn-btn">
                        <?php esc_html_e( 'Open Help →', 'royal-mcp' ); ?>
                    </a>
                </div>
            </div>

            <!-- SLIDE 4 — WHAT'S COMING -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'On the roadmap', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( "What's coming", 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'More MCP tools supporting your favorite WordPress plugins and themes. Every popular integration eventually becomes a first-class Royal MCP surface.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Continued MCP spec tracking as the standard evolves. Deeper Activity Log visibility so admins can see exactly what AI clients are doing on their site, when, and why.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-mark" role="img" aria-label="Royal Plugins">
                        <span>R</span>
                    </div>
                </div>
            </div>

        </div>

        <!-- PRO UPSELL FOOTER -->
        <div class="rmcp-wn-upsell">
            <div class="rmcp-wn-upsell-copy">
                <h4><?php esc_html_e( 'Ready to scale MCP across every client site?', 'royal-mcp' ); ?></h4>
                <p><?php esc_html_e( 'Royal MCP Pro adds 80 agency-tier tools: bulk operations, per-project endpoint scoping, and a 90-day audit log clients can inspect. 30-50% off launch price.', 'royal-mcp' ); ?></p>
            </div>
            <a href="<?php echo esc_url( $rmcp_wn_pro_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-upsell-btn">
                <?php esc_html_e( 'Upgrade to Pro →', 'royal-mcp' ); ?>
            </a>
        </div>

    </div>
</div>
