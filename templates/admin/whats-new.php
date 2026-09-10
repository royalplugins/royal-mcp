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
$rmcp_wn_pro_url    = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_1&utm_content=footer_cta';
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( 'Version 1.5.1: WebMCP support + Agent Readiness signals', 'royal-mcp' ); ?></p>
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

            <!-- SLIDE 2 — WEBMCP BROWSER AGENTS -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Browser Agents', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Browser AI can now use your MCP tools', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( 'Royal MCP now supports the <strong>WebMCP browser-agent standard</strong> on the server side. When a visitor is signed into your site, browser-based AI agents from any WebMCP-compatible bridge can call the same Royal MCP tools that Claude Desktop and ChatGPT already use, without any extra login step. Cloudflare\'s WebMCP is the first bridge implementation shipping today; more are on the way.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( 'New <strong>Browser agents (WebMCP)</strong> section on the Royal MCP settings page turns it on or off with one toggle. Off by default so nothing changes for existing installs.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p><?php esc_html_e( 'External clients like Claude Desktop, ChatGPT, and Cursor keep using OAuth Bearer tokens as before. Fully backwards compatible.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone">
<span class="c">// Browser agent calls a Royal MCP tool</span>
<span class="k">fetch</span>(<span class="s">'/mcp'</span>, <span class="k">{</span>
  <span class="s">method</span>: <span class="s">'POST'</span>,
  <span class="s">credentials</span>: <span class="s">'same-origin'</span>,
  <span class="s">headers</span>: <span class="k">{</span>
    <span class="s">'Content-Type'</span>: <span class="s">'application/json'</span>,
    <span class="s">'X-WP-Nonce'</span>: <span class="s hl">royalMcpWebMcp.nonce</span>
  <span class="k">}</span>,
  <span class="s">body</span>: JSON.stringify(<span class="k">{</span>
    <span class="s">jsonrpc</span>: <span class="s">'2.0'</span>, <span class="s">id</span>: 1,
    <span class="s">method</span>: <span class="s">'tools/call'</span>,
    <span class="s">params</span>: <span class="k">{</span> <span class="s">name</span>: <span class="s">'wp_create_post'</span> <span class="k">}</span>
  <span class="k">}</span>)
<span class="k">}</span>)
                    </div>
                </div>
            </div>

            <!-- SLIDE 3 — AGENT READINESS DISCOVERY DOCS -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle" role="img" aria-label="<?php esc_attr_e( 'Agent Readiness metadata published', 'royal-mcp' ); ?>">
                        <svg viewBox="0 0 200 200" width="170" height="170" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                            <rect x="35" y="35" width="130" height="150" rx="10" fill="#FEFCF7" stroke="#C9A227" stroke-width="2.5"/>
                            <path d="M37 45 Q37 37 45 37 L155 37 Q163 37 163 45 L163 59 L37 59 Z" fill="#C9A227"/>
                            <circle cx="47" cy="48" r="2.5" fill="#FEFCF7"/>
                            <circle cx="56" cy="48" r="2.5" fill="#FEFCF7"/>
                            <circle cx="65" cy="48" r="2.5" fill="#FEFCF7"/>
                            <circle cx="55" cy="85" r="8" fill="#22C55E"/>
                            <path d="M51 85 L54 88 L60 81" stroke="white" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                            <rect x="72" y="81" width="72" height="8" rx="2" fill="#2C2C2C" opacity="0.15"/>
                            <circle cx="55" cy="110" r="8" fill="#22C55E"/>
                            <path d="M51 110 L54 113 L60 106" stroke="white" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                            <rect x="72" y="106" width="55" height="8" rx="2" fill="#2C2C2C" opacity="0.15"/>
                            <circle cx="55" cy="135" r="8" fill="#22C55E"/>
                            <path d="M51 135 L54 138 L60 131" stroke="white" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                            <rect x="72" y="131" width="80" height="8" rx="2" fill="#2C2C2C" opacity="0.15"/>
                            <text x="100" y="170" text-anchor="middle" font-family="Inter, -apple-system, sans-serif" font-size="9" font-weight="700" letter-spacing="1.5" fill="#C9A227">AGENT READY</text>
                        </svg>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Agent Readiness', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Your MCP server, discoverable by every AI scanner', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( 'Royal MCP now publishes an <strong>MCP Server Card</strong>, a <strong>Skills Index</strong>, and <strong>OAuth Protected Resource</strong> metadata at the standard well-known paths. Cloudflare\'s Agent Readiness scanner, Vercel\'s is-agentic, and Chrome Lighthouse\'s Agentic Browsing audit all recognize your site as agent-ready with no extra setup.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p><?php esc_html_e( 'Every /mcp response also carries an RFC 8288 Link header pointing at these documents so agent runtimes that hit the endpoint find the metadata without extra probing.', 'royal-mcp' ); ?></p>
                    <?php /* Button intentionally omitted until agent-readiness.html support doc ships; add back with real URL when that doc lands. */ ?>
                </div>
            </div>

            <!-- SLIDE 4 — WHAT'S COMING (1.5.2+ roadmap) -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'On the roadmap', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( "What's coming", 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Native WebMCP registration.</strong> Browser agents will call Royal MCP tools directly through <code>navigator.modelContext</code> without needing Cloudflare in the middle. Works in any browser that ships the WebMCP API (Chrome 149+ with the experimental flag today, stable rollout later this year).', 'royal-mcp' ),
                            [ 'strong' => [], 'code' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Deeper OAuth spec coverage.</strong> Client Identifier Metadata Documents (CIMD) so external MCP clients discover client registrations without a database round trip. OAuth client garbage collection for cleaner long-term audit logs.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>More integrations.</strong> Every popular WordPress plugin eventually becomes a first-class Royal MCP tool surface.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
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
