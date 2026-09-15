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
$rmcp_wn_pro_url    = 'https://royalplugins.com/royal-mcp-pro/?utm_source=whats_new_modal&utm_medium=free_plugin&utm_campaign=whats_new_1_5_2&utm_content=footer_cta';
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( 'Version 1.5.2: approval-gated AI clients and modern OAuth discovery', 'royal-mcp' ); ?></p>
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

            <!-- SLIDE 2 — DCR PRE-APPROVAL TOGGLE (flagship 1.5.2 feature) -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v1.5.2', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Approve new AI clients before they connect', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( 'New optional setting under Royal MCP &rsaquo; Settings: <strong>Require approval before new AI clients can connect</strong>. Turn it on and every dynamically-registered OAuth client lands in a <strong>Pending Clients</strong> queue instead of connecting straight away.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p><?php esc_html_e( 'Each pending row shows client name, requested redirect URIs, source IP, and user agent. Approve or reject per row. An admin bar badge tells you when the queue has anything waiting so nothing slips through unnoticed.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Default is off, so nothing changes for existing sites. Turn it on when you want every AI-client connection reviewed before it goes live.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle" role="img" aria-label="<?php esc_attr_e( 'Pending clients queue', 'royal-mcp' ); ?>">
                        <svg viewBox="0 0 200 200" width="170" height="170" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                            <rect x="30" y="35" width="140" height="130" rx="10" fill="#FEFCF7" stroke="#C9A227" stroke-width="2.5"/>
                            <rect x="30" y="35" width="140" height="26" rx="10" fill="#C9A227"/>
                            <text x="100" y="53" text-anchor="middle" font-family="Inter, -apple-system, sans-serif" font-size="12" font-weight="700" fill="#FEFCF7">PENDING CLIENTS</text>
                            <rect x="42" y="72" width="116" height="24" rx="4" fill="#F6F7F7" stroke="#DDD" stroke-width="1"/>
                            <circle cx="53" cy="84" r="5" fill="#FBBF24"/>
                            <rect x="63" y="79" width="55" height="4" rx="1" fill="#2C2C2C" opacity="0.35"/>
                            <rect x="63" y="87" width="35" height="3" rx="1" fill="#2C2C2C" opacity="0.2"/>
                            <rect x="125" y="77" width="14" height="14" rx="2" fill="#22C55E"/>
                            <rect x="142" y="77" width="14" height="14" rx="2" fill="#EF4444" opacity="0.85"/>
                            <rect x="42" y="104" width="116" height="24" rx="4" fill="#F6F7F7" stroke="#DDD" stroke-width="1"/>
                            <circle cx="53" cy="116" r="5" fill="#FBBF24"/>
                            <rect x="63" y="111" width="45" height="4" rx="1" fill="#2C2C2C" opacity="0.35"/>
                            <rect x="63" y="119" width="30" height="3" rx="1" fill="#2C2C2C" opacity="0.2"/>
                            <rect x="125" y="109" width="14" height="14" rx="2" fill="#22C55E"/>
                            <rect x="142" y="109" width="14" height="14" rx="2" fill="#EF4444" opacity="0.85"/>
                            <rect x="42" y="136" width="116" height="24" rx="4" fill="#F6F7F7" stroke="#DDD" stroke-width="1"/>
                            <circle cx="53" cy="148" r="5" fill="#FBBF24"/>
                            <rect x="63" y="143" width="60" height="4" rx="1" fill="#2C2C2C" opacity="0.35"/>
                            <rect x="63" y="151" width="42" height="3" rx="1" fill="#2C2C2C" opacity="0.2"/>
                            <rect x="125" y="141" width="14" height="14" rx="2" fill="#22C55E"/>
                            <rect x="142" y="141" width="14" height="14" rx="2" fill="#EF4444" opacity="0.85"/>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- SLIDE 3 — CIMD + OAUTH DISCOVERY POLISH -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-code-snippet rmcp-wn-code-snippet-standalone">
<span class="c">// OAuth discovery metadata</span>
<span class="k">{</span>
  <span class="s">"issuer"</span>: <span class="s">"https://yoursite.com"</span>,
  <span class="s">"authorization_endpoint"</span>: <span class="s">"..."</span>,
  <span class="s">"token_endpoint"</span>: <span class="s">"..."</span>,
  <span class="s hl">"client_id_metadata_document_supported"</span>: <span class="k">true</span>,
  <span class="s">"code_challenge_methods_supported"</span>: <span class="k">[</span><span class="s">"S256"</span><span class="k">]</span>
<span class="k">}</span>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v1.5.2', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Modern OAuth discovery, on any host', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( 'Royal MCP now advertises <strong>client_id_metadata_document</strong> support so AI clients using that flow (the one Claude\'s connector wizard promotes as "Recommended") can point at a metadata document URL instead of running a full dynamic-registration handshake.', 'royal-mcp' ),
                            [ 'strong' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( 'OAuth discovery is also served under <code>/wp-json/royal-mcp/v1/.well-known/oauth-authorization-server</code> as a fallback for managed hosts (SiteGround, WP Engine, some cPanel setups) that block the root <code>.well-known/</code> path at their edge.', 'royal-mcp' ),
                            [ 'code' => [] ]
                        );
                        ?>
                    </p>
                    <p><?php esc_html_e( 'A new admin notice also detects when your web server strips Authorization headers before WordPress sees them, with copy-paste Apache and nginx fix guidance.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 4 — TOOL SURFACE POLISH -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v1.5.2', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Sharper tools for real workflows', 'royal-mcp' ); ?></h3>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Twitter card fields on wp_update_seo_meta.</strong> Set <code>twitter_title</code>, <code>twitter_description</code>, and <code>twitter_image</code> alongside the existing Open Graph fields. Routed per active SEO plugin (Yoast, Rank Math, SEOPress, SEObolt).', 'royal-mcp' ),
                            [ 'strong' => [], 'code' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Targeted custom CSS edits.</strong> <code>wp_replace_in_post</code> now works against the WordPress custom_css post so a two-line stylesheet change no longer sends the whole file over the wire.', 'royal-mcp' ),
                            [ 'strong' => [], 'code' => [] ]
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        echo wp_kses(
                            __( '<strong>Core Web Vitals via ForgeCache.</strong> New <code>fc_get_rum_stats</code> tool exposes ForgeCache\'s real-visitor INP, LCP, CLS, and TTFB data so AI assistants can identify the worst-performing pages on your site.', 'royal-mcp' ),
                            [ 'strong' => [], 'code' => [] ]
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
