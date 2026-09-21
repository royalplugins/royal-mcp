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
$rmcp_wn_pro_url    = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_3&utm_content=footer_cta';
$rmcp_wn_pro_slide_url = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_3&utm_content=slide_1_cta';
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( 'Version 1.5.3: Protocol Insights Dashboard & More MCP compliance', 'royal-mcp' ); ?></p>
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

            <!-- SLIDE 2 — PROTOCOL INSIGHTS DASHBOARD (flagship 1.5.3 feature) -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Peakaboo', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( "See who's using your MCP endpoint", 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( 'New admin page under Royal MCP &rsaquo; <strong>Protocol Insights</strong> shows weekly rollups of protocol-version share, top MCP clients, and method-call frequency across every request hitting your site.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p><?php esc_html_e( 'Instantly answer questions like "is Anthropic\'s client still on the old protocol?" or "which methods are getting the most traffic this week?" without opening the raw activity log.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Includes a 12-week trend chart and a one-click JSON export of the current week for offline analysis. Data stays on your site, with no external calls or third-party analytics.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Royal MCP Pro will build on this dashboard, extending it with a security-first principle: classify real agents versus scanner traffic, then let you block suspicious clients right at your MCP endpoint.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle" role="img" aria-label="<?php esc_attr_e( 'Protocol Insights dashboard', 'royal-mcp' ); ?>">
                        <svg viewBox="0 0 200 200" width="170" height="170" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                            <rect x="30" y="35" width="140" height="130" rx="10" fill="#FEFCF7" stroke="#C9A227" stroke-width="2.5"/>
                            <rect x="30" y="35" width="140" height="26" rx="10" fill="#C9A227"/>
                            <text x="100" y="53" text-anchor="middle" font-family="Inter, -apple-system, sans-serif" font-size="11" font-weight="700" fill="#FEFCF7">PROTOCOL INSIGHTS</text>
                            <text x="42" y="82" font-family="monospace" font-size="6.5" fill="#2C2C2C">2026-07-28</text>
                            <rect x="42" y="85" width="88" height="8" rx="2" fill="#2271B1"/>
                            <text x="163" y="92" text-anchor="end" font-family="Inter, sans-serif" font-size="7" font-weight="600" fill="#2C2C2C">4,238</text>
                            <text x="42" y="105" font-family="monospace" font-size="6.5" fill="#2C2C2C">2025-11-25</text>
                            <rect x="42" y="108" width="54" height="8" rx="2" fill="#2271B1" opacity="0.75"/>
                            <text x="163" y="115" text-anchor="end" font-family="Inter, sans-serif" font-size="7" font-weight="600" fill="#2C2C2C">1,634</text>
                            <text x="42" y="128" font-family="monospace" font-size="6.5" fill="#2C2C2C">unknown</text>
                            <rect x="42" y="131" width="27" height="8" rx="2" fill="#787c82"/>
                            <text x="163" y="138" text-anchor="end" font-family="Inter, sans-serif" font-size="7" font-weight="600" fill="#2C2C2C">318</text>
                            <line x1="42" y1="156" x2="158" y2="156" stroke="#dcdcde" stroke-width="0.6"/>
                            <polyline points="42,153 60,149 78,151 96,146 114,142 132,145 150,140 158,138" fill="none" stroke="#2271B1" stroke-width="1.6"/>
                            <circle cx="158" cy="138" r="2" fill="#2271B1"/>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- SLIDE 3 — MCP 2026-07-28 SPEC COMPLIANCE -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone">
<span class="c">// server/discover — MCP 2026-07-28</span>
<span class="k">{</span>
  <span class="s hl">"resultType"</span>: <span class="s">"complete"</span>,
  <span class="s hl">"supportedVersions"</span>: <span class="k">[</span><span class="s">"2026-07-28"</span><span class="k">, ...]</span>,
  <span class="s">"capabilities"</span>: <span class="k">{ ... }</span>,
  <span class="s">"_meta"</span>: <span class="k">{</span>
    <span class="s">"io.modelcontextprotocol/serverInfo"</span>: <span class="k">{ ... }</span>
  <span class="k">}</span>
<span class="k">}</span>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Ready Freedy', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Ready for the newest MCP connectors', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( "Royal MCP now speaks the full <strong>MCP 2026-07-28</strong> wire shape, the newer spec that ChatGPT's connectors and the latest Anthropic clients expect. The <code>server/discover</code> response, OAuth <code>iss</code> parameter (RFC 9207), and RFC 9728 path-suffixed Protected Resource Metadata all land in this release.", 'royal-mcp' ),
                            [ 'strong' => [], 'code' => [] ]
                        );
                        ?>
                    </p>
                    <p><?php esc_html_e( 'Older MCP clients keep working unchanged. The era-gated handler returns the legacy shape for pre-2026-07-28 protocol versions, so nothing on your existing setup breaks.', 'royal-mcp' ); ?></p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( 'OAuth discovery also gets a wp-json fallback URL advertised in the server card and 401 responses, so managed hosts that reserve the root <code>.well-known/</code> prefix still route AI clients through successfully.', 'royal-mcp' ),
                            [ 'code' => [] ]
                        );
                        ?>
                    </p>
                </div>
            </div>

            <!-- SLIDE 4 — POLISH TRIO (Chunks 7 / 10 / 11) -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Polish', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Small wins that add up', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>ISO 8601 timestamps on the wire.</strong> ForgeCache cache-stats fields, UpdraftPlus backup start times, and undo-envelope expiry timestamps now use the ISO 8601 UTC format that MCP clients already parse everywhere else.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Cleaner settings page.</strong> The WebMCP bridge status pill only appears when a WebMCP bridge is actually detected on your domain, so the 99% of sites that don\'t run one no longer see an amber "not detected" nag.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Empty values stay empty.</strong> Reading a plugin setting via MCP now returns an empty string for unconfigured secret slots instead of masking them as "[REDACTED]", so AI assistants can tell a not-yet-set OAuth client ID from one that\'s actually populated.', 'royal-mcp' ),
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
