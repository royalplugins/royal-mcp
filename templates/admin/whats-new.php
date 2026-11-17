<?php
/**
 * Royal MCP — What's New modal template.
 *
 * Whats-New-For-Version: 2.0.0
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

$rmcp_wn_img_base          = ROYAL_MCP_PLUGIN_URL . 'assets/img/whats-new/';
$rmcp_wn_royally_url       = 'https://royalplugins.com/royally/?utm_source=royal_mcp_free&utm_medium=free_plugin&utm_content=whats_new_footer&utm_campaign=royal_mcp_admin';
$rmcp_wn_royally_slide_url = 'https://royalplugins.com/royally/?utm_source=royal_mcp_free&utm_medium=free_plugin&utm_content=whats_new_slide&utm_campaign=royal_mcp_admin';
$rmcp_wn_check             = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
$rmcp_wn_svg_allowed       = [
    'svg'      => [ 'viewbox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'class' => true ],
    'polyline' => [ 'points' => true ],
];
?>
<div class="rmcp-wn-backdrop" data-royal-mcp-wn-backdrop hidden>
    <div class="rmcp-wn-modal" role="dialog" aria-modal="true" aria-labelledby="rmcp-wn-title">
        <div class="rmcp-wn-header">
            <img class="rmcp-wn-header-logo" src="<?php echo esc_url( $rmcp_wn_img_base . 'royal-shield.png' ); ?>" alt="">
            <div class="rmcp-wn-header-titles">
                <h2 id="rmcp-wn-title"><?php esc_html_e( "What's New in Royal MCP", 'royal-mcp' ); ?></h2>
                <p><?php esc_html_e( '2.0: Dashboard, Context, Skills, 100 More Tools and Safer Setting Changes', 'royal-mcp' ); ?></p>
            </div>
            <button type="button" class="rmcp-wn-close" data-royal-mcp-wn-close aria-label="<?php esc_attr_e( 'Close', 'royal-mcp' ); ?>">&times;</button>
        </div>

        <div class="rmcp-wn-slides">

            <!-- SLIDE 0: 2.0 (the one dark visual) -->
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

            <!-- SLIDE 1: Dashboard and menu — settings panel -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v2.0', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'A dashboard and a simpler menu', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'The Dashboard shows your MCP address with the steps to connect, which AI clients are connected, what they changed lately and how the site is protected.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Settings now sit on two screens: Connections for who can connect and how, and Safety for what a connected AI may do.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-panel" aria-hidden="true">
                        <div class="rmcp-wn-panel-head"><?php esc_html_e( 'Dashboard', 'royal-mcp' ); ?></div>
                        <div class="rmcp-wn-kv">
                            <span class="rmcp-wn-kv-key"><?php esc_html_e( 'address', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-kv-val">/wp-json/royal-mcp/v1/mcp</span>
                        </div>
                        <div class="rmcp-wn-kv">
                            <span class="rmcp-wn-kv-key"><?php esc_html_e( 'clients', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-kv-val"><?php esc_html_e( 'Claude, ChatGPT', 'royal-mcp' ); ?></span>
                            <?php echo wp_kses( str_replace( '<svg ', '<svg class="rmcp-wn-kv-ok" ', $rmcp_wn_check ), $rmcp_wn_svg_allowed ); ?>
                        </div>
                        <div class="rmcp-wn-kv">
                            <span class="rmcp-wn-kv-key"><?php esc_html_e( 'changes', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-kv-val"><?php esc_html_e( '5 today, each can be undone', 'royal-mcp' ); ?></span>
                        </div>
                        <div class="rmcp-wn-panel-foot">
                            <span><?php esc_html_e( 'Connections · Context · Safety · Activity · Skills', 'royal-mcp' ); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SLIDE 2: Context and Skills — skills grid -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-skills" aria-hidden="true">
                        <div class="rmcp-wn-vis-label"><?php esc_html_e( 'Sent when an AI connects', 'royal-mcp' ); ?></div>
                        <div class="rmcp-wn-skills-grid">
                            <div class="rmcp-wn-skill is-new"><span class="rmcp-wn-skill-new"><?php esc_html_e( 'New', 'royal-mcp' ); ?></span><span class="rmcp-wn-skill-name"><?php esc_html_e( 'Site context', 'royal-mcp' ); ?></span><span class="rmcp-wn-toggle is-on"></span></div>
                            <div class="rmcp-wn-skill"><span class="rmcp-wn-skill-name">Elementor</span><span class="rmcp-wn-toggle is-on"></span></div>
                            <div class="rmcp-wn-skill"><span class="rmcp-wn-skill-name">WooCommerce</span><span class="rmcp-wn-toggle is-on"></span></div>
                            <div class="rmcp-wn-skill"><span class="rmcp-wn-skill-name">Divi</span><span class="rmcp-wn-toggle"></span></div>
                            <div class="rmcp-wn-skill"><span class="rmcp-wn-skill-name">SiteVault</span><span class="rmcp-wn-toggle is-on"></span></div>
                            <div class="rmcp-wn-skill"><span class="rmcp-wn-skill-name"><?php esc_html_e( 'Tone and limits', 'royal-mcp' ); ?></span><span class="rmcp-wn-toggle is-on"></span></div>
                        </div>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v2.0', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Tell your AI about your site', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'On the Context screen, write what your site is for, its tone and what to leave alone. Every connected AI client gets it when it connects.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'On the Skills screen, switch on a playbook for Elementor, Divi, WooCommerce or SiteVault. Connected AI clients read it before that kind of work, with nothing to download.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 3: 100 more tools — big count + pills -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v2.0', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( '100 more tools, and a shorter list for your AI', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Your AI can now install, update and switch plugins and themes, check a site before launch, write a monthly maintenance report, change theme colours and fonts, and work with Akismet, Jetpack, WP Mail SMTP, WP-Optimize, Really Simple Security and image optimisers.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Tools for plugins that are not active on your site are left out, so your AI sees only what it can use here.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-tools" aria-hidden="true">
                        <div class="rmcp-wn-tools-count">+100<span><?php esc_html_e( 'tools', 'royal-mcp' ); ?></span></div>
                        <div class="rmcp-wn-pills">
                            <span class="rmcp-wn-pill is-site"><?php esc_html_e( 'Plugins and themes', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-pill is-site"><?php esc_html_e( 'Launch checks', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-pill is-site"><?php esc_html_e( 'Maintenance report', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-pill is-site"><?php esc_html_e( 'Theme design', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-pill">Akismet</span>
                            <span class="rmcp-wn-pill">Jetpack</span>
                            <span class="rmcp-wn-pill">WP Mail SMTP</span>
                            <span class="rmcp-wn-pill">WP-Optimize</span>
                            <span class="rmcp-wn-pill">Really Simple Security</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SLIDE 4: Safety — shield + checklist (light) -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-shield" aria-hidden="true">
                        <div class="rmcp-wn-shield-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="8.5 12 11 14.5 15.5 9.5"/></svg></div>
                        <ul class="rmcp-wn-checklist">
                            <li><?php echo wp_kses( $rmcp_wn_check, $rmcp_wn_svg_allowed ); ?><?php esc_html_e( 'Full, read-only or drafts-only', 'royal-mcp' ); ?></li>
                            <li><?php echo wp_kses( $rmcp_wn_check, $rmcp_wn_svg_allowed ); ?><?php esc_html_e( 'Every call in the Activity log', 'royal-mcp' ); ?></li>
                            <li><?php echo wp_kses( $rmcp_wn_check, $rmcp_wn_svg_allowed ); ?><?php echo esc_html( sprintf( /* translators: %s: undo window, e.g. "72 hours" */ __( 'Undo for %s', 'royal-mcp' ), \Royal_MCP\MCP\Undo_Store::window_label() ) ); ?></li>
                            <li><?php echo wp_kses( $rmcp_wn_check, $rmcp_wn_svg_allowed ); ?><?php esc_html_e( 'Plugin abilities, one at a time', 'royal-mcp' ); ?></li>
                        </ul>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v2.0', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'You decide what each AI may do', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Give each connection full access, read-only or drafts-only, or switch the whole site to one of them on the Safety screen. The Activity screen shows every call and every change, with the value before and after, and exports to CSV. Most undos will not write over edits made after the AI change unless you tell them to.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'On the Plugin Abilities screen, let your AI use the abilities other plugins add to WordPress, one plugin at a time. Those calls are logged and follow the same access levels.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 5: Safer setting changes — nested value, one leaf changed -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v2.0', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Change one part of a setting, not all of it', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'When a plugin setting holds a long list or many values, your AI can change just one value, add or remove one item, and leave the rest as it is. One undo puts back only what it changed.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Values Royal MCP keeps hidden from AI clients are never overwritten: a change that would replace them is refused. Under Return as stored on the Safety screen, you choose which settings your AI may read in full, with passwords and keys still hidden.', 'royal-mcp' ); ?></p>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-tree" aria-hidden="true">
                        <div class="rmcp-wn-crumbs"><span>schedule</span>&rsaquo;<span class="is-on">last_run</span></div>
                        <div class="rmcp-wn-tree-body">
                            <div class="rmcp-wn-tree-row">{</div>
                            <div class="rmcp-wn-tree-row is-l1">"schedule": {</div>
                            <div class="rmcp-wn-tree-row is-l2 is-dim">"interval": "daily",</div>
                            <div class="rmcp-wn-tree-row is-l2 is-leaf">"last_run": "today"</div>
                            <div class="rmcp-wn-tree-row is-l1">},</div>
                            <div class="rmcp-wn-tree-row is-l1 is-dim">"api_key": "&bull;&bull;&bull;&bull;&bull;&bull;"</div>
                            <div class="rmcp-wn-tree-row">}</div>
                        </div>
                        <div class="rmcp-wn-tree-foot"><?php esc_html_e( '1 value changed, the hidden key untouched', 'royal-mcp' ); ?></div>
                    </div>
                </div>
            </div>

            <!-- SLIDE 6: Clearer tools — rows of titled tools -->
            <div class="rmcp-wn-slide">
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-files" aria-hidden="true">
                        <div class="rmcp-wn-file">
                            <span class="rmcp-wn-file-name"><?php esc_html_e( 'Get posts', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-signed"><?php esc_html_e( 'Page 2', 'royal-mcp' ); ?></span>
                        </div>
                        <div class="rmcp-wn-file">
                            <span class="rmcp-wn-file-name"><?php esc_html_e( 'WooCommerce: Update order status', 'royal-mcp' ); ?></span>
                        </div>
                        <div class="rmcp-wn-file">
                            <span class="rmcp-wn-file-name"><?php esc_html_e( 'Elementor: Replace text', 'royal-mcp' ); ?></span>
                        </div>
                        <div class="rmcp-wn-files-foot">
                            <span>2026-11-17T09:30:00-05:00</span>
                        </div>
                    </div>
                </div>
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'New in v2.0', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'Clearer tools for every AI client', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Every tool now has a plain title, so AI clients and tool directories show "WooCommerce: Update order status" instead of a code name. Long lists of posts, pages, comments, users and terms come in pages, so nothing past the first hundred is out of reach.', 'royal-mcp' ); ?></p>
                    <p><?php esc_html_e( 'Dates include their time zone, and every tool and setting is described, so your AI picks the right one the first time.', 'royal-mcp' ); ?></p>
                </div>
            </div>

            <!-- SLIDE 7: Royal MCP Pro — connected services list -->
            <div class="rmcp-wn-slide is-reversed">
                <div class="rmcp-wn-slide-body">
                    <span class="rmcp-wn-tag"><?php esc_html_e( 'Royal MCP Pro', 'royal-mcp' ); ?></span>
                    <h3><?php esc_html_e( 'One connection for everything your AI manages', 'royal-mcp' ); ?></h3>
                    <p><?php esc_html_e( 'Royally puts your whole stack behind one MCP connection: every WordPress site you run, plus Cloudflare, UptimeRobot, Notion and other MCP servers. Each change can wait for your approval, is recorded and can be undone. Royal MCP Pro comes with it.', 'royal-mcp' ); ?></p>
                    <a href="<?php echo esc_url( $rmcp_wn_royally_slide_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-btn">
                        <?php esc_html_e( 'See Royal MCP Pro', 'royal-mcp' ); ?>
                    </a>
                </div>
                <div class="rmcp-wn-slide-visual">
                    <div class="rmcp-wn-vis rmcp-wn-conflicts" aria-hidden="true">
                        <div class="rmcp-wn-conflicts-head">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="12" cy="18" r="2.5"/><path d="M8 7.5l3 8"/><path d="M16 7.5l-3 8"/></svg>
                            <span><?php esc_html_e( 'One connection', 'royal-mcp' ); ?></span>
                            <span class="rmcp-wn-conflicts-count">5</span>
                        </div>
                        <div class="rmcp-wn-conflict is-ok"><span class="rmcp-wn-conflict-dot"></span><span class="rmcp-wn-conflict-text"><strong><?php esc_html_e( '3 WordPress sites', 'royal-mcp' ); ?></strong><em><?php esc_html_e( 'Royal MCP Pro', 'royal-mcp' ); ?></em></span></div>
                        <div class="rmcp-wn-conflict is-ok"><span class="rmcp-wn-conflict-dot"></span><span class="rmcp-wn-conflict-text"><strong>Cloudflare</strong><em><?php esc_html_e( 'DNS and cache', 'royal-mcp' ); ?></em></span></div>
                        <div class="rmcp-wn-conflict is-warn"><span class="rmcp-wn-conflict-dot"></span><span class="rmcp-wn-conflict-text"><strong>UptimeRobot</strong><em><?php esc_html_e( 'Change waits for approval', 'royal-mcp' ); ?></em></span></div>
                    </div>
                </div>
            </div>

        </div>

        <!-- FOOTER -->
        <div class="rmcp-wn-upsell">
            <div class="rmcp-wn-upsell-copy">
                <h4><?php esc_html_e( 'Running more than one site?', 'royal-mcp' ); ?></h4>
                <p>
                    <?php esc_html_e( 'One MCP connection for all your sites, Cloudflare, UptimeRobot and more, with approvals and undo.', 'royal-mcp' ); ?>
                </p>
            </div>
            <a href="<?php echo esc_url( $rmcp_wn_royally_url ); ?>" target="_blank" rel="noopener noreferrer" class="rmcp-wn-upsell-btn">
                <?php esc_html_e( 'See Royal MCP Pro →', 'royal-mcp' ); ?>
            </a>
        </div>

    </div>
</div>
