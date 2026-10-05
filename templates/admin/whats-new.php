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
$rmcp_wn_pro_url    = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_6&utm_content=footer_cta';
$rmcp_wn_pro_slide_url = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_6&utm_content=slide_1_cta';
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( 'Version 1.5.5-1.5.6 Dry run & Oauth connector hotfix', 'royal-mcp' ); ?></p>
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
                    <p class="rmcp-wn-big-number"><?php esc_html_e( 'Supercharge your workflow', 'royal-mcp' ); ?></p>
                    <h3><?php esc_html_e( '300+ MCP tools, bulk operations, undo tokens. Agency ready.', 'royal-mcp' ); ?></h3>
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
                    <h3><?php esc_html_e( 'Preview the exact change before you write anything', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( 'Two of the highest-blast-radius write tools now accept a <code>dry_run</code> flag. <code>wp_update_option</code> preview returns the current value, the proposed value, whether the option is autoloaded, and the size delta in bytes. <code>wp_update_permalink_structure</code> preview returns the current and proposed structures, every public post type that would resolve through them, and a sample of current URLs.', 'royal-mcp' ),
                            [ 'code' => [] ]
                        );
                        ?>
                    </p>
                    <p><?php esc_html_e( 'The preview response never writes and never flushes rewrite rules, so the caller gets the full picture and can decide whether to run the real call in a second step.', 'royal-mcp' ); ?></p>
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

            <!-- SLIDE 3 — OAUTH CONNECTOR COMPATIBILITY (the 1.5.6 headline) -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle" role="img" aria-label="<?php esc_attr_e( 'Multiple AI connectors linked to a central endpoint', 'royal-mcp' ); ?>">
                        <svg viewBox="0 0 200 200" width="170" height="170" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="100" cy="100" r="30" fill="#2C2C2C" stroke="#C9A227" stroke-width="2.5"/>
                            <text x="100" y="97" text-anchor="middle" font-family="Inter, sans-serif" font-size="8" font-weight="700" fill="#C9A227">Royal MCP</text>
                            <text x="100" y="108" text-anchor="middle" font-family="Inter, sans-serif" font-size="7" fill="#FAF8F5">/mcp</text>
                            <circle cx="100" cy="30"  r="18" fill="#FEFCF7" stroke="#C9A227" stroke-width="2"/>
                            <text x="100" y="33" text-anchor="middle" font-family="Inter, sans-serif" font-size="7" font-weight="700" fill="#2C2C2C">Claude</text>
                            <circle cx="170" cy="100" r="18" fill="#FEFCF7" stroke="#C9A227" stroke-width="2"/>
                            <text x="170" y="103" text-anchor="middle" font-family="Inter, sans-serif" font-size="7" font-weight="700" fill="#2C2C2C">ChatGPT</text>
                            <circle cx="100" cy="170" r="18" fill="#FEFCF7" stroke="#C9A227" stroke-width="2"/>
                            <text x="100" y="173" text-anchor="middle" font-family="Inter, sans-serif" font-size="7" font-weight="700" fill="#2C2C2C">Cursor</text>
                            <circle cx="30"  cy="100" r="18" fill="#FEFCF7" stroke="#C9A227" stroke-width="2"/>
                            <text x="30" y="103" text-anchor="middle" font-family="Inter, sans-serif" font-size="7" font-weight="700" fill="#2C2C2C">VS Code</text>
                            <line x1="100" y1="48"  x2="100" y2="70"  stroke="#C9A227" stroke-width="1.5"/>
                            <line x1="152" y1="100" x2="130" y2="100" stroke="#C9A227" stroke-width="1.5"/>
                            <line x1="100" y1="152" x2="100" y2="130" stroke="#C9A227" stroke-width="1.5"/>
                            <line x1="48"  y1="100" x2="70"  y2="100" stroke="#C9A227" stroke-width="1.5"/>
                        </svg>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Compatibility', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Every major AI connector completes OAuth cleanly', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( "Recent updates on the connector side introduced strict compatibility requirements around how OAuth redirect URIs, resource identifiers, and refresh tokens are validated. This release brings Royal MCP's OAuth surface fully in line with the latest client behavior so setup completes on the first attempt in Claude, ChatGPT, Cursor, and VS Code.", 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Loopback redirect handling now follows the native-app spec so desktop connectors that pick a fresh local port each session succeed on every attempt. Private-use URI schemes are accepted at registration for editor connectors that use them.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'ChatGPT connector setup passes the resource-parameter check that was previously failing on some site configurations.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 4 — DISCOVERY + HOSTING -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle" role="img" aria-label="<?php esc_attr_e( 'Discovery through HTTPS proxy', 'royal-mcp' ); ?>">
                        <svg viewBox="0 0 200 200" width="170" height="170" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                            <rect x="10" y="80" width="42" height="40" rx="5" fill="#FEFCF7" stroke="#C9A227" stroke-width="2"/>
                            <text x="31" y="104" text-anchor="middle" font-family="Inter, sans-serif" font-size="7" font-weight="700" fill="#2C2C2C">Client</text>
                            <rect x="72" y="70" width="56" height="60" rx="6" fill="#C9A227" stroke="#A8871D" stroke-width="1.5"/>
                            <text x="100" y="94" text-anchor="middle" font-family="Inter, sans-serif" font-size="8" font-weight="700" fill="#FEFCF7">HTTPS</text>
                            <text x="100" y="107" text-anchor="middle" font-family="Inter, sans-serif" font-size="8" font-weight="700" fill="#FEFCF7">proxy</text>
                            <rect x="148" y="80" width="42" height="40" rx="5" fill="#2C2C2C" stroke="#C9A227" stroke-width="2"/>
                            <text x="169" y="98" text-anchor="middle" font-family="Inter, sans-serif" font-size="6.5" font-weight="700" fill="#C9A227">WordPress</text>
                            <text x="169" y="109" text-anchor="middle" font-family="Inter, sans-serif" font-size="6.5" fill="#FAF8F5">/mcp</text>
                            <line x1="52" y1="100" x2="72" y2="100" stroke="#C9A227" stroke-width="1.5" marker-end="url(#arrow)"/>
                            <line x1="128" y1="100" x2="148" y2="100" stroke="#C9A227" stroke-width="1.5" marker-end="url(#arrow)"/>
                            <defs>
                                <marker id="arrow" markerWidth="6" markerHeight="6" refX="5" refY="3" orient="auto">
                                    <polygon points="0 0, 6 3, 0 6" fill="#C9A227"/>
                                </marker>
                            </defs>
                            <circle cx="100" cy="52" r="10" fill="#FEFCF7" stroke="#C9A227" stroke-width="1.5"/>
                            <rect x="96" y="50" width="8" height="7" rx="1" fill="#C9A227"/>
                            <path d="M97 50 v-3 a3 3 0 0 1 6 0 v3" fill="none" stroke="#C9A227" stroke-width="1.2"/>
                            <line x1="100" y1="62" x2="100" y2="70" stroke="#C9A227" stroke-width="1.5" stroke-dasharray="2 2"/>
                            <text x="169" y="145" text-anchor="middle" font-family="monospace" font-size="6" fill="#787c82">openid-configuration</text>
                            <text x="169" y="154" text-anchor="middle" font-family="monospace" font-size="6" fill="#787c82">served</text>
                        </svg>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Broader hosting compatibility', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'OAuth discovery works on more host configurations', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( 'Sites hosted behind an SSL-terminating proxy with an <code>http://</code> site address configured in WordPress now advertise <code>https://</code> discovery endpoints correctly. Token exchange no longer gets rewritten to a GET request on those setups.', 'royal-mcp' ),
                            [ 'code' => [] ]
                        );
                        ?>
                    </p>
                    <p><?php esc_html_e( 'OpenID Connect discovery is now served alongside the existing OAuth authorization-server discovery, giving SDK-based clients a fallback discovery path on subdirectory installs and hosts that intercept the primary well-known path.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 5 — UNDER THE HOOD RELIABILITY -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-mark" role="img" aria-label="Royal Plugins">
                        <span>R</span>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Under the hood', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Fewer re-consent prompts, longer-lived connections', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Refresh-token grace window.</strong> A short reuse-interval on refresh-token rotation absorbs the concurrent refresh-token presentations that connectors make when reactive and proactive refresh overlap, so the second presentation no longer forces a re-consent.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Idle-connector retention.</strong> Registered clients that have completed at least one authorization are no longer garbage-collected between sessions. Long-idle connectors reconnect against their existing registration instead of failing with an unknown-client error.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Setup guidance refresh.</strong> The in-plugin Claude setup guidance was refreshed to reflect current connector behavior.', 'royal-mcp' ),
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
