<?php
/**
 * Royal MCP — What's New modal template.
 *
 * Whats-New-For-Version: 2.0.1
 *
 * Rendered from Whats_New::render_modal() on admin_footer (Royal MCP pages
 * only). JS (assets/js/whats-new.js) handles show/hide + AJAX dismissal.
 *
 * Editing this file requires no PHP changes as long as the surrounding
 * class structure stays. Update slides in place for each release; the
 * modal auto-opens on next admin page view for every user because
 * ROYAL_MCP_VERSION is stamped into user_meta on dismiss.
 * Each slide uses a different visual (see assets/css/whats-new.css, SLIDE VISUALS).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$rmcp_wn_img_base    = ROYAL_MCP_PLUGIN_URL . 'assets/img/whats-new/';
$rmcp_wn_royally_url = 'https://royalplugins.com/royal-mcp-pro/?utm_source=royal_mcp_free&utm_medium=free_plugin&utm_content=whats_new_footer&utm_campaign=royal_mcp_admin';
$rmcp_wn_check       = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
$rmcp_wn_svg_allowed = [
    'svg'      => [ 'viewbox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'class' => true ],
    'polyline' => [ 'points' => true ],
];
$rmcp_wn_tag         = __( 'New in 2.0.1', 'royal-mcp' );
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( '2.0.1: Feature and Security Release', 'royal-mcp' ); ?></p>
            </div>
            <button type="button" class="rmcp-wn-close" data-royal-mcp-wn-close aria-label="<?php esc_attr_e( 'Close', 'royal-mcp' ); ?>">&times;</button>
        </div>

        <div class="rmcp-wn-slides">

            <!-- SLIDE 0: Royal MCP 2.0 celebration -->
            <div class="rmcp-wn-slide is-celebration" data-royal-mcp-wn-confetti>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-circle is-dark">
                        <img src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Version 2.0', 'royal-mcp' ); ?></span>
                    <p class="rmcp-wn-big-number"><?php esc_html_e( '400+ MCP tools', 'royal-mcp' ); ?></p>
                    <h3><?php esc_html_e( 'Royal MCP 2.0 is here', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'A new dashboard, a simpler menu and the biggest tool expansion Royal MCP has had. It is still free, and it still runs only on your site.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 1: Three new integrations — plugin tiles + tool count -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-intg" aria-hidden="true">
                        <div class="rmcp-wn-intg-count">+16<span><?php esc_html_e( 'tools', 'royal-mcp' ); ?></span></div>
                        <div class="rmcp-wn-intg-tile">
                            <span class="rmcp-wn-intg-mono">JE</span>
                            <span class="rmcp-wn-intg-name">JetEngine<em><?php esc_html_e( 'Fields, content types, relations', 'royal-mcp' ); ?></em></span>
                            <span class="rmcp-wn-intg-num">10</span>
                        </div>
                        <div class="rmcp-wn-intg-tile">
                            <span class="rmcp-wn-intg-mono">MC</span>
                            <span class="rmcp-wn-intg-name">Mailchimp for WP<em><?php esc_html_e( 'Audiences and forms', 'royal-mcp' ); ?></em></span>
                            <span class="rmcp-wn-intg-num">4</span>
                        </div>
                        <div class="rmcp-wn-intg-tile">
                            <span class="rmcp-wn-intg-mono">FS</span>
                            <span class="rmcp-wn-intg-name">FluentSMTP<em><?php esc_html_e( 'Status and test email', 'royal-mcp' ); ?></em></span>
                            <span class="rmcp-wn-intg-num">2</span>
                        </div>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php echo esc_html( $rmcp_wn_tag ); ?></span>
                    <h3><?php esc_html_e( 'JetEngine, Mailchimp for WP and FluentSMTP', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Your AI can read JetEngine post types, meta fields, content types and relations, and change field values with undo. It can list Mailchimp for WP audiences and forms and change which audiences a form adds people to, and check FluentSMTP and send a test email.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'The tools appear by themselves as soon as the plugin is active.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 2: Safety switches — toggle rows, all off -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php echo esc_html( $rmcp_wn_tag ); ?></span>
                    <h3><?php esc_html_e( 'Payouts, mail and bulk store changes wait for your switch', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Tools that change what affiliates are paid, send mail to many people or change many products at once each have a switch under Royal MCP → Safety → What AI clients may do. On new sites they start off. On updated sites a group stays on only if your AI used it in the last 30 days.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Large discounts, big price cuts and marking an unpaid order as paid now also need an explicit confirmation.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-switches" aria-hidden="true">
                        <div class="rmcp-wn-switches-head"><?php esc_html_e( 'What AI clients may do', 'royal-mcp' ); ?></div>
                        <div class="rmcp-wn-switch">
                            <span class="rmcp-wn-switch-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="2" x2="12" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
                            <span class="rmcp-wn-switch-label"><?php esc_html_e( 'Affiliate money', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-toggle"></span>
                        </div>
                        <div class="rmcp-wn-switch">
                            <span class="rmcp-wn-switch-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><polyline points="3 7 12 13 21 7"/></svg></span>
                            <span class="rmcp-wn-switch-label"><?php esc_html_e( 'Mail and subscriber lists', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-toggle"></span>
                        </div>
                        <div class="rmcp-wn-switch">
                            <span class="rmcp-wn-switch-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v11h16V9"/><path d="M9 20v-6h6v6"/></svg></span>
                            <span class="rmcp-wn-switch-label"><?php esc_html_e( 'Bulk store changes', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-toggle"></span>
                        </div>
                        <div class="rmcp-wn-switches-foot"><span class="rmcp-wn-switches-pill"><?php esc_html_e( 'Off by default', 'royal-mcp' ); ?></span></div>
                    </div>
                </div>
            </div>

            <!-- SLIDE 3: WP-CLI — terminal -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-term" aria-hidden="true">
                        <div class="rmcp-wn-term-bar"><span></span><span></span><span></span></div>
                        <div class="rmcp-wn-term-body">
                            <div class="rmcp-wn-term-cmd">wp royal-mcp setup</div>
                            <div class="rmcp-wn-term-out">MCP address: …/royal-mcp/v1/mcp</div>
                            <div class="rmcp-wn-term-out">Connection Doctor:</div>
                            <div class="rmcp-wn-term-out is-ok">&nbsp;&nbsp;[ok] Royal MCP is switched on</div>
                            <div class="rmcp-wn-term-out">Claude Desktop (stdio):</div>
                            <div class="rmcp-wn-term-out is-dim">&nbsp;&nbsp;{ "mcpServers": { … } }</div>
                            <div class="rmcp-wn-term-cmd">wp royal-mcp serve --user=admin<span class="rmcp-wn-term-caret"></span></div>
                        </div>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php echo esc_html( $rmcp_wn_tag ); ?></span>
                    <h3><?php esc_html_e( 'Connect a local site through WP-CLI', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Run wp royal-mcp setup to check the connection and get settings ready to paste into Claude Desktop, Claude Code or Cursor. With wp royal-mcp serve, an AI app on the same computer starts Royal MCP itself: no sign-in page, and nothing listening on the network.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Every safety setting still applies, and each call shows in the Activity Log as a local WP-CLI connection.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 4: Task prompts — slash command menu -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php echo esc_html( $rmcp_wn_tag ); ?></span>
                    <h3><?php esc_html_e( 'Ready-made tasks in your AI app', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Royal MCP now offers four task prompts: weekly site check, launch checklist, security review and content audit. Apps that show prompts as slash commands list them when you type a slash.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Each one tells the AI which tools to use and what to report, and asks it to only read.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-slash" aria-hidden="true">
                        <div class="rmcp-wn-slash-menu">
                            <div class="rmcp-wn-slash-item is-on"><span class="rmcp-wn-slash-name">/weekly-site-check</span><em><?php esc_html_e( 'Health, updates, backups', 'royal-mcp' ); ?></em></div>
                            <div class="rmcp-wn-slash-item"><span class="rmcp-wn-slash-name">/launch-checklist</span><em><?php esc_html_e( 'Ready to go live?', 'royal-mcp' ); ?></em></div>
                            <div class="rmcp-wn-slash-item"><span class="rmcp-wn-slash-name">/security-review</span><em><?php esc_html_e( 'Admins, passwords, findings', 'royal-mcp' ); ?></em></div>
                            <div class="rmcp-wn-slash-item"><span class="rmcp-wn-slash-name">/content-audit</span><em><?php esc_html_e( 'Drafts, SEO, alt text', 'royal-mcp' ); ?></em></div>
                        </div>
                        <div class="rmcp-wn-slash-input"><span class="rmcp-wn-slash-typed">/</span><span class="rmcp-wn-slash-caret"></span>
                            <span class="rmcp-wn-slash-send"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SLIDE 5: Activity Log export + undo of a Clear — step timeline -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-timeline" aria-hidden="true">
                        <div class="rmcp-wn-step">
                            <span class="rmcp-wn-step-dot is-gold"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><polyline points="7 10 12 15 17 10"/><path d="M5 21h14"/></svg></span>
                            <span class="rmcp-wn-step-text"><?php esc_html_e( 'Export', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-badge">CSV · JSON</span>
                        </div>
                        <div class="rmcp-wn-step">
                            <span class="rmcp-wn-step-dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg></span>
                            <span class="rmcp-wn-step-text"><?php esc_html_e( 'Clear the log', 'royal-mcp' ); ?></span>
                        </div>
                        <div class="rmcp-wn-step is-last">
                            <span class="rmcp-wn-step-dot is-gold"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.5 15a9 9 0 1 0 2.1-9.4L1 10"/></svg></span>
                            <span class="rmcp-wn-step-text"><?php esc_html_e( 'Restore', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-badge"><?php esc_html_e( '24 hours', 'royal-mcp' ); ?></span>
                        </div>
                        <div class="rmcp-wn-lockline">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
                            <span><?php esc_html_e( 'Activity Log', 'royal-mcp' ); ?> <em><?php esc_html_e( 'cleared entries kept a day', 'royal-mcp' ); ?></em></span>
                        </div>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php echo esc_html( $rmcp_wn_tag ); ?></span>
                    <h3><?php esc_html_e( 'Export the Activity Log, and undo a Clear', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Under Royal MCP → Activity → Activity Log, download the whole log as CSV or JSON before you clear it.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Cleared by mistake? Restore the cleared entries any time in the next 24 hours.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 6: Short tool list — length bars -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php echo esc_html( $rmcp_wn_tag ); ?></span>
                    <h3><?php esc_html_e( 'A short tool list for AI apps that need one', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Add ?tools=compact to your MCP address and the AI app gets 18 tools: everyday reads plus the three tools that find and run every other one, with the same permission checks.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Apps that slow down or cut off long tool lists stay quick, and nothing is out of reach. See Royal MCP → Help → Other Clients → Shorter tool lists.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-bars" aria-hidden="true">
                        <div class="rmcp-wn-bar-row">
                            <div class="rmcp-wn-bar-label"><span><?php esc_html_e( 'Full list', 'royal-mcp' ); ?></span><span class="rmcp-wn-bar-num"><?php esc_html_e( 'every tool', 'royal-mcp' ); ?></span></div>
                            <div class="rmcp-wn-bar"><span class="rmcp-wn-bar-fill is-long"></span></div>
                        </div>
                        <div class="rmcp-wn-bar-row">
                            <div class="rmcp-wn-bar-label"><span class="rmcp-wn-bar-code">?tools=compact</span><span class="rmcp-wn-bar-num is-gold">18</span></div>
                            <div class="rmcp-wn-bar"><span class="rmcp-wn-bar-fill is-short"></span></div>
                        </div>
                        <div class="rmcp-wn-bar-chips">
                            <span>discover_tools</span><span>get_tool_info</span><span>execute_tool</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SLIDE 7: Connection Doctor sign-in check — diagnostic results -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-doctor" aria-hidden="true">
                        <div class="rmcp-wn-doctor-head">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                            <span><?php esc_html_e( 'Connection Doctor', 'royal-mcp' ); ?></span>
                        </div>
                        <div class="rmcp-wn-doctor-row"><?php echo wp_kses( $rmcp_wn_check, $rmcp_wn_svg_allowed ); ?><span><?php esc_html_e( 'Royal MCP is switched on', 'royal-mcp' ); ?></span></div>
                        <div class="rmcp-wn-doctor-row"><?php echo wp_kses( $rmcp_wn_check, $rmcp_wn_svg_allowed ); ?><span><?php esc_html_e( 'Discovery and sign-in', 'royal-mcp' ); ?></span></div>
                        <div class="rmcp-wn-doctor-row"><?php echo wp_kses( $rmcp_wn_check, $rmcp_wn_svg_allowed ); ?><span><?php esc_html_e( 'Client registration answers', 'royal-mcp' ); ?></span></div>
                        <div class="rmcp-wn-doctor-row is-new"><?php echo wp_kses( $rmcp_wn_check, $rmcp_wn_svg_allowed ); ?><span><?php esc_html_e( 'Sign-in requests keep their PKCE parameters', 'royal-mcp' ); ?></span><span class="rmcp-wn-doctor-new"><?php esc_html_e( 'New', 'royal-mcp' ); ?></span></div>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php echo esc_html( $rmcp_wn_tag ); ?></span>
                    <h3><?php esc_html_e( 'Connection Doctor checks sign-in through your host', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Some hosts and firewalls drop part of the sign-in request an AI app sends, and sign-in then fails without a clear reason. Connection Doctor on the Dashboard now tests for this and links to the fix when it finds it.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'This release also tightens sign-in limits and safeguards across undo, preview links and plugin tools.', 'royal-mcp' ); ?></p>
                </div>
            </div>

        </div>

        <!-- FOOTER -->
        <div class="rmcp-wn-upsell">
            <div class="rmcp-wn-upsell-copy">
                <h4><?php esc_html_e( 'Upgrade To 530+ MCP tools!', 'royal-mcp' ); ?></h4>
                <p>
                    <?php esc_html_e( 'One MCP connection for all your sites and your full stack: hosting, Cloudflare, UptimeRobot, Jira and more, with up to 168-hour undo and full governance.', 'royal-mcp' ); ?>
                </p>
            </div>
            <a href="<?php echo esc_url( $rmcp_wn_royally_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-upsell-btn">
                <?php esc_html_e( 'See Royal MCP Pro →', 'royal-mcp' ); ?>
            </a>
        </div>

    </div>
</div>
