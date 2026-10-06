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
$rmcp_wn_pro_url    = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_7&utm_content=footer_cta';
$rmcp_wn_pro_slide_url = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_7&utm_content=slide_1_cta';
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( 'Version 1.5.7 — Read-only mode, Connected Clients & more', 'royal-mcp' ); ?></p>
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

            <!-- SLIDE 2 — READ-ONLY MODE (the 1.5.7 headline) -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Read-only mode', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Let AI read everything and change nothing', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'One switch in Royal MCP settings. When it is on, every tool that creates, updates or deletes is refused for every client and every user, administrators included, while every read keeps working.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( "The tools stay in your clients' tool lists, marked as switched off, so an assistant knows what it can and cannot do before it tries. Turn it on while you decide what AI may change, or leave it on for sites that should only ever be read.", 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone">
<span class="c">// wp_update_post, read-only mode on</span>
<span class="k">{</span>
  <span class="s hl">"isError"</span>: <span class="k">true</span>,
  <span class="s">"content"</span>: <span class="k">[{</span>
    <span class="s">"type"</span>: <span class="s">"text"</span>,
    <span class="s">"text"</span>: <span class="s">"This site is in read-only
      mode: tools that change the
      site are switched off."</span>
  <span class="k">}]</span>
<span class="k">}</span>
                    </div>
                </div>
            </div>

            <!-- SLIDE 3 — CONNECTED CLIENTS, SITE HEALTH, WP-CLI -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle" role="img" aria-label="<?php esc_attr_e( 'A list of connected AI clients with a revoke button', 'royal-mcp' ); ?>">
                        <svg viewBox="0 0 200 200" width="170" height="170" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                            <rect x="20" y="40" width="160" height="30" rx="5" fill="#FEFCF7" stroke="#C9A227" stroke-width="1.5"/>
                            <text x="30" y="59" font-family="Inter, sans-serif" font-size="8" font-weight="700" fill="#2C2C2C">Claude</text>
                            <text x="78" y="59" font-family="Inter, sans-serif" font-size="7" fill="#787c82">as jamie</text>
                            <rect x="20" y="85" width="160" height="30" rx="5" fill="#FEFCF7" stroke="#C9A227" stroke-width="1.5"/>
                            <text x="30" y="104" font-family="Inter, sans-serif" font-size="8" font-weight="700" fill="#2C2C2C">ChatGPT</text>
                            <text x="78" y="104" font-family="Inter, sans-serif" font-size="7" fill="#787c82">as jamie</text>
                            <rect x="128" y="91" width="44" height="18" rx="9" fill="#FEFCF7" stroke="#b32d2e" stroke-width="1.5"/>
                            <text x="150" y="103" text-anchor="middle" font-family="Inter, sans-serif" font-size="7" font-weight="700" fill="#b32d2e">Revoke</text>
                            <rect x="20" y="130" width="160" height="30" rx="5" fill="#FEFCF7" stroke="#C9A227" stroke-width="1.5"/>
                            <text x="30" y="149" font-family="Inter, sans-serif" font-size="8" font-weight="700" fill="#2C2C2C">Cursor</text>
                            <text x="78" y="149" font-family="Inter, sans-serif" font-size="7" fill="#787c82">as sam</text>
                        </svg>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( "Who's connected", 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'See every connected AI client, and revoke one at a time', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Royal MCP > Connected Clients lists each AI client signed in through OAuth, which WordPress user it acts as, when it registered and when it last signed in. One button ends that client\'s access without touching anyone else; the client can reconnect from its side.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Tools > Site Health now checks the four things AI clients need from your server: pretty permalinks, the discovery document, the Authorization header reaching WordPress, and the sign-in addresses, each with a link to the fix when it fails.', 'royal-mcp' ); ?></p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( 'For agencies: <code>wp royal-mcp connection-health</code>, <code>list-clients</code> and <code>rotate-api-key</code> do the same from the command line.', 'royal-mcp' ),
                            [ 'code' => [] ]
                        );
                        ?>
                    </p>
                </div>
            </div>

            <!-- SLIDE 4 — WORDFENCE + LITESPEED CACHE INTEGRATIONS -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Two new integrations', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Wordfence and LiteSpeed Cache: twelve new tools', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Wordfence.</strong> Read the security status, firewall mode and scan findings, list blocked IPs, block or unblock an address, read failed logins and blocked requests, and start a scan.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>LiteSpeed Cache.</strong> Read the cache status and the settings that shape caching, purge everything, or purge specific pages by URL or post.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p><?php esc_html_e( 'Both follow the rules every integration follows: administrators only, every change confirmed against what the plugin actually stored, and text from logs handed to the AI as text, never as instructions.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle" role="img" aria-label="<?php esc_attr_e( 'Wordfence and LiteSpeed Cache linked to Royal MCP', 'royal-mcp' ); ?>">
                        <svg viewBox="0 0 200 200" width="170" height="170" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="100" cy="100" r="30" fill="#2C2C2C" stroke="#C9A227" stroke-width="2.5"/>
                            <text x="100" y="97" text-anchor="middle" font-family="Inter, sans-serif" font-size="8" font-weight="700" fill="#C9A227">Royal MCP</text>
                            <text x="100" y="108" text-anchor="middle" font-family="Inter, sans-serif" font-size="7" fill="#FAF8F5">136 tools</text>
                            <circle cx="100" cy="32" r="22" fill="#FEFCF7" stroke="#C9A227" stroke-width="2"/>
                            <text x="100" y="30" text-anchor="middle" font-family="Inter, sans-serif" font-size="7" font-weight="700" fill="#2C2C2C">Wordfence</text>
                            <text x="100" y="40" text-anchor="middle" font-family="Inter, sans-serif" font-size="6.5" fill="#787c82">8 tools</text>
                            <circle cx="100" cy="168" r="22" fill="#FEFCF7" stroke="#C9A227" stroke-width="2"/>
                            <text x="100" y="166" text-anchor="middle" font-family="Inter, sans-serif" font-size="7" font-weight="700" fill="#2C2C2C">LiteSpeed</text>
                            <text x="100" y="176" text-anchor="middle" font-family="Inter, sans-serif" font-size="6.5" fill="#787c82">4 tools</text>
                            <line x1="100" y1="54" x2="100" y2="70" stroke="#C9A227" stroke-width="1.5"/>
                            <line x1="100" y1="146" x2="100" y2="130" stroke="#C9A227" stroke-width="1.5"/>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- SLIDE 5 — CLEARER SIGNALS FOR CLIENTS -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-mark" role="img" aria-label="Royal Plugins">
                        <span>R</span>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Under the hood', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Clearer signals for AI clients, smoother sign-in', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Read or change, declared up front.</strong> Every tool now tells clients whether it only reads or can change the site, so assistants can run reads freely and ask before a write.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Resources.</strong> Site info, the active theme, the tool catalog and connection health can be loaded by clients as context, without a tool call.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Sign-in.</strong> Clients can revoke their own access when you disconnect them, and a page you publish at <code>/register</code> or <code>/token</code> is shown to your visitors as usual.', 'royal-mcp' ),
                            [ 'strong' => [], 'code' => [] ]
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
