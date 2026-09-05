<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$royal_mcp_help_diagnostic    = isset( $royal_mcp_help_diagnostic ) ? $royal_mcp_help_diagnostic : \Royal_MCP\Admin\Help_Page::DIAGNOSTIC_STEPS;
$royal_mcp_help_support_urls  = isset( $royal_mcp_help_support_urls ) ? $royal_mcp_help_support_urls : \Royal_MCP\Admin\Help_Page::SUPPORT_URLS;
$royal_mcp_help_settings_url  = admin_url( 'admin.php?page=royal-mcp' );
$royal_mcp_help_logs_url      = defined( 'ROYAL_MCP_LOADED_BY_PRO' )
	? admin_url( 'admin.php?page=royal-mcp-pro' )
	: admin_url( 'admin.php?page=royal-mcp-logs' );
$royal_mcp_help_permalinks    = admin_url( 'options-permalink.php' );
$royal_mcp_unknown_client_url = $royal_mcp_help_support_urls['unknown_client_id'] ?? 'https://royalplugins.com/support/royal-mcp/';
?>
<div class="royal-mcp-help-tab-content royal-mcp-help-tab-troubleshooting">

	<div class="royal-mcp-help-tab-intro">
		<h2><?php esc_html_e( 'Troubleshooting', 'royal-mcp' ); ?></h2>
		<p><?php esc_html_e( 'Most of the time, a broken connector is just an expired OAuth token — a 5-second reconnect fixes it. The 3-rung ladder below covers the actual failure frequency, escalating from cheap to nuclear.', 'royal-mcp' ); ?></p>
		<p class="description">
			<?php
			printf(
				wp_kses(
					/* translators: %s: link to Royal MCP Settings page */
					__( 'Session length defaults to 24 hours and is configurable in %s → Session length (1 hour, 8 hours, or 24 hours). Shorter sessions mean more frequent reconnects but tighter security.', 'royal-mcp' ),
					[ 'a' => [ 'href' => [] ] ]
				),
				'<a href="' . esc_url( $royal_mcp_help_settings_url ) . '">' . esc_html__( 'Royal MCP → Settings', 'royal-mcp' ) . '</a>'
			);
			?>
		</p>
	</div>

	<!-- =====================================================
	     Quick Fix — escalation ladder (start here)
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-quickfix">
		<h3><?php esc_html_e( 'Quick fix (start here)', 'royal-mcp' ); ?></h3>
		<p><?php esc_html_e( 'Try in order. Stop as soon as it works.', 'royal-mcp' ); ?></p>

		<ol class="royal-mcp-help-ladder">

			<!-- Rung 1: Just reconnect -->
			<li class="royal-mcp-help-ladder-rung">
				<h4><?php esc_html_e( 'Reconnect the connector', 'royal-mcp' ); ?></h4>
				<p><?php esc_html_e( 'In Claude or ChatGPT, open Settings → Connectors (Claude) or Settings → Plugins (ChatGPT) → find the Royal MCP entry → click it → Reconnect / Sign in again. OAuth access tokens expire on a schedule; a reconnect issues a fresh one.', 'royal-mcp' ); ?></p>
				<p class="royal-mcp-help-ladder-meta"><em><?php esc_html_e( 'Takes 5-10 seconds. This is the fix 80% of the time.', 'royal-mcp' ); ?></em></p>
			</li>

			<!-- Rung 2: Flush permalinks -->
			<li class="royal-mcp-help-ladder-rung">
				<h4><?php esc_html_e( 'If reconnect fails AND it\'s been 2+ days: flush permalinks', 'royal-mcp' ); ?></h4>
				<p>
					<?php
					printf(
						wp_kses(
							/* translators: %s: link to WP permalinks admin page */
							__( 'Rewrite rules occasionally go stale on managed hosts and long-lived installs. Go to %s → click Save Changes (no need to change the setting). Then retry the reconnect from step 1.', 'royal-mcp' ),
							[ 'a' => [ 'href' => [] ] ]
						),
						'<a href="' . esc_url( $royal_mcp_help_permalinks ) . '">' . esc_html__( 'Settings → Permalinks', 'royal-mcp' ) . '</a>'
					);
					?>
				</p>
			</li>

			<!-- Rung 3: Nuclear — Reset OAuth State + fresh setup -->
			<li class="royal-mcp-help-ladder-rung">
				<h4><?php esc_html_e( 'Still failing (OAuth errors, "OFID" errors, or broken after a backup restore): full reset', 'royal-mcp' ); ?></h4>
				<p><?php esc_html_e( 'Three steps, in order:', 'royal-mcp' ); ?></p>
				<ul class="royal-mcp-help-substeps">
					<li><?php esc_html_e( 'In your AI client (Claude / ChatGPT), delete the Royal MCP connector entirely.', 'royal-mcp' ); ?></li>
					<li>
						<?php
						printf(
							wp_kses(
								/* translators: %s: link to Royal MCP Settings */
								__( 'In %s, click the Reset OAuth State button. This revokes every issued access + refresh token and wipes stale OAuth clients on the WordPress side — no leftover state to conflict with.', 'royal-mcp' ),
								[ 'a' => [ 'href' => [] ] ]
							),
							'<a href="' . esc_url( $royal_mcp_help_settings_url ) . '">' . esc_html__( 'Royal MCP → Settings', 'royal-mcp' ) . '</a>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Wait 30 seconds, then set the connector up from scratch in your AI client. The full OAuth flow runs against clean state.', 'royal-mcp' ); ?></li>
				</ul>
				<p class="royal-mcp-help-ladder-meta"><em><?php esc_html_e( 'Also the fix if you restored from a backup or migrated hosts — the credentials your client stored no longer match anything WordPress-side.', 'royal-mcp' ); ?></em></p>
			</li>
		</ol>
	</div>

	<!-- =====================================================
	     Deeper diagnostic — 4-step
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-diagnostic">
		<h3><?php esc_html_e( 'Deeper diagnostic', 'royal-mcp' ); ?></h3>
		<p><?php esc_html_e( 'If the quick fix ladder didn\'t resolve it, walk through these to rule out plugin conflicts, host-level interference, and stale-state issues.', 'royal-mcp' ); ?></p>
		<ol class="royal-mcp-help-steps">
			<?php foreach ( $royal_mcp_help_diagnostic as $royal_mcp_step ) : ?>
				<li>
					<strong><?php echo esc_html( $royal_mcp_step['title'] ); ?></strong>
					<?php echo esc_html( $royal_mcp_step['body'] ); ?>
				</li>
			<?php endforeach; ?>
		</ol>

		<p class="royal-mcp-help-section-cta">
			<?php echo \Royal_MCP\Admin\Help_Page::full_guide_link( 'troubleshooting_start', __( 'Full troubleshooting walkthrough', 'royal-mcp' ) ); ?>
		</p>
	</div>

	<!-- =====================================================
	     Specific non-OAuth issues
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-issues">
		<h3><?php esc_html_e( 'Specific issues (not OAuth-related)', 'royal-mcp' ); ?></h3>

		<!-- Issue: Claude wizard 5-second timeout drops user on options screen -->
		<div class="royal-mcp-help-issue-card">
			<h4><?php esc_html_e( 'Claude wizard shows an options screen with authentication + OAuth client radios', 'royal-mcp' ); ?></h4>
			<p class="royal-mcp-help-issue-symptom">
				<?php esc_html_e( 'After pasting your MCP URL in Claude\'s Add custom connector dialog, instead of going straight to Authorize, you see a full options screen with a yellow banner: "Some checks were skipped because the server took longer than 5 seconds. The settings below may need adjusting."', 'royal-mcp' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Fix:', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'This is the current Claude wizard (as of August 2026) — not an error. The 5-second OAuth-discovery budget is aggressive for many WordPress hosts. On the options screen, leave', 'royal-mcp' ); ?>
				<em><?php esc_html_e( 'Authentication', 'royal-mcp' ); ?></em>
				<?php esc_html_e( 'set to', 'royal-mcp' ); ?>
				<em><?php esc_html_e( 'Always required', 'royal-mcp' ); ?></em>,
				<?php esc_html_e( 'set', 'royal-mcp' ); ?>
				<em><?php esc_html_e( 'OAuth client', 'royal-mcp' ); ?></em>
				<?php esc_html_e( 'to', 'royal-mcp' ); ?>
				<em><?php esc_html_e( 'No client ID — register one automatically', 'royal-mcp' ); ?></em>,
				<?php esc_html_e( 'leave headers and Advanced empty, then click Add. Claude drops you onto a connector detail page — click Connect to complete OAuth. Full walkthrough on the Claude tab.', 'royal-mcp' ); ?>
			</p>
			<p class="royal-mcp-help-issue-guide">
				<a href="<?php echo esc_url( \Royal_MCP\Admin\Help_Page::tab_url( 'claude' ) ); ?>"><?php esc_html_e( 'Full Claude connection guide →', 'royal-mcp' ); ?></a>
			</p>
		</div>

		<!-- Issue A: ChatGPT cosmetic banner -->
		<div class="royal-mcp-help-issue-card">
			<h4><?php esc_html_e( 'ChatGPT flashes "action discovery failed" during setup', 'royal-mcp' ); ?></h4>
			<p class="royal-mcp-help-issue-symptom">
				<?php esc_html_e( 'Red banner appears near the end of the connect flow, sometimes with "unknown error" wording.', 'royal-mcp' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Fix:', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'Usually cosmetic — verify before treating as broken. (1) Is your Plugin listed in ChatGPT → Settings → Plugins? (2) In a new conversation with the Plugin toggled on, does ChatGPT respond to a WordPress-specific prompt with real site data? If both yes, ignore the banner.', 'royal-mcp' ); ?>
			</p>
			<p class="royal-mcp-help-issue-guide">
				<?php echo \Royal_MCP\Admin\Help_Page::full_guide_link( 'chatgpt', __( 'Full ChatGPT connection guide', 'royal-mcp' ) ); ?>
			</p>
		</div>

		<!-- Issue B: 0 tools after successful connect -->
		<div class="royal-mcp-help-issue-card">
			<h4><?php esc_html_e( 'Connector shows Connected but tool count is 0', 'royal-mcp' ); ?></h4>
			<p class="royal-mcp-help-issue-symptom">
				<?php esc_html_e( 'OAuth succeeded, connector marked Connected, but no Royal MCP tools appear in the tool picker.', 'royal-mcp' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Fix:', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'Two most common causes: (1) Plain permalinks — see Quick Fix Rung 2 above. (2) Plugin conflict blocking REST — deactivate other plugins one at a time until tools appear.', 'royal-mcp' ); ?>
			</p>
			<p class="royal-mcp-help-issue-guide">
				<?php echo \Royal_MCP\Admin\Help_Page::full_guide_link( 'diagnose_curl', __( 'curl diagnostic walkthrough', 'royal-mcp' ) ); ?>
			</p>
		</div>

		<!-- Issue C: .well-known 404 -->
		<div class="royal-mcp-help-issue-card">
			<h4><?php esc_html_e( '.well-known/ URLs return 404, HTML, or wrong content-type', 'royal-mcp' ); ?></h4>
			<p class="royal-mcp-help-issue-symptom">
				<?php esc_html_e( 'OAuth discovery fails because the client can\'t reach /.well-known/oauth-authorization-server or /.well-known/oauth-protected-resource. Hosting-layer issue, not WordPress.', 'royal-mcp' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Fix:', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'Managed hosts (SiteGround, WP Engine, Kinsta) sometimes serve /.well-known/ as static files or block it via .htaccess. Fix is host-specific — the linked guide covers the SiteGround case and the general pattern that applies to any managed host doing the same thing.', 'royal-mcp' ); ?>
			</p>
			<p class="royal-mcp-help-issue-guide">
				<?php echo \Royal_MCP\Admin\Help_Page::full_guide_link( 'well_known_404', __( 'Full .well-known 404 guide', 'royal-mcp' ) ); ?>
			</p>
		</div>

	</div>

	<!-- =====================================================
	     Common Errors — Unknown client_id
	     ===================================================== -->
	<div class="royal-mcp-help-section">
		<h3><?php esc_html_e( 'Common errors — Unknown client_id', 'royal-mcp' ); ?></h3>

		<p>
			<?php
			echo wp_kses_post( sprintf(
				/* translators: %s: literal error string in a <code> tag */
				__( '<strong>Symptom:</strong> the AI client shows %s (or a variation, e.g. "Unknown client_id: &lt;hash&gt;") when you try to reconnect a connector that used to work.', 'royal-mcp' ),
				'<code>' . esc_html__( 'Unknown client_id', 'royal-mcp' ) . '</code>'
			) );
			?>
		</p>

		<p>
			<strong><?php esc_html_e( 'What triggers it:', 'royal-mcp' ); ?></strong>
			<?php esc_html_e( 'the connector has been idle for 2+ days (OAuth client record expired), rapid Royal MCP updates or an OAuth-state reset invalidated the old client between reconnects, or you switched between Royal MCP Free and Pro and the connector still references a client_id from the previous plugin.', 'royal-mcp' ); ?>
		</p>

		<p><strong><?php esc_html_e( 'The fix:', 'royal-mcp' ); ?></strong></p>

		<ol class="royal-mcp-help-ladder">
			<li class="royal-mcp-help-ladder-rung">
				<h4><?php esc_html_e( 'Reset OAuth State', 'royal-mcp' ); ?></h4>
				<p>
					<?php
					printf(
						wp_kses(
							/* translators: %s: link to Royal MCP Settings */
							__( 'Go to %s and click the Reset OAuth State button. This clears every stored OAuth client, issued access token, and pending authorization code.', 'royal-mcp' ),
							[ 'a' => [ 'href' => [] ] ]
						),
						'<a href="' . esc_url( $royal_mcp_help_settings_url ) . '">' . esc_html__( 'Royal MCP → Settings', 'royal-mcp' ) . '</a>'
					);
					?>
				</p>
			</li>
			<li class="royal-mcp-help-ladder-rung">
				<h4><?php esc_html_e( 'Flush permalinks', 'royal-mcp' ); ?></h4>
				<p>
					<?php
					printf(
						wp_kses(
							/* translators: %s: link to Permalinks admin page */
							__( 'Open %s and click Save Changes without editing anything. Forces WordPress to flush rewrite rules so the OAuth discovery endpoints route correctly.', 'royal-mcp' ),
							[ 'a' => [ 'href' => [] ] ]
						),
						'<a href="' . esc_url( $royal_mcp_help_permalinks ) . '">' . esc_html__( 'Settings → Permalinks', 'royal-mcp' ) . '</a>'
					);
					?>
				</p>
			</li>
			<li class="royal-mcp-help-ladder-rung">
				<h4><?php esc_html_e( 'Delete the connector', 'royal-mcp' ); ?></h4>
				<p><?php esc_html_e( 'In the AI client (Claude / ChatGPT / other), delete the existing Royal MCP connector entirely.', 'royal-mcp' ); ?></p>
			</li>
			<li class="royal-mcp-help-ladder-rung">
				<h4><?php esc_html_e( 'Re-add from scratch', 'royal-mcp' ); ?></h4>
				<p><?php esc_html_e( 'Add the connector back fresh. Enter only the MCP endpoint URL, leave every optional field empty so the client requests a new OAuth registration.', 'royal-mcp' ); ?></p>
			</li>
		</ol>

		<p style="margin-top: 16px;">
			<a href="<?php echo esc_url( $royal_mcp_unknown_client_url ); ?>" target="_blank" rel="noopener noreferrer" class="button">
				<?php esc_html_e( 'Read the full guide', 'royal-mcp' ); ?>
			</a>
		</p>
	</div>

	<!-- =====================================================
	     Reading the Activity Log
	     ===================================================== -->
	<div class="royal-mcp-help-section">
		<h3><?php esc_html_e( 'What the Activity Log shows', 'royal-mcp' ); ?></h3>
		<p><?php esc_html_e( 'The Activity Log is the single fastest diagnostic surface for connection issues. Three prefixes cover the connection lifecycle:', 'royal-mcp' ); ?></p>
		<ul class="royal-mcp-help-ladder">
			<li>
				<strong><code>oauth:*</code></strong>
				<?php esc_html_e( '— OAuth handshake events (register, authorize, token, revoke). Failed handshakes record the exact validation rule that fired, which is the most useful thing to paste into a support request.', 'royal-mcp' ); ?>
			</li>
			<li>
				<strong><code>mcp:*</code></strong>
				<?php esc_html_e( '— JSON-RPC method calls (initialize, tools/list, ping, notifications). A successful connection shows mcp:initialize followed by mcp:tools/list. If you see oauth:token succeed but no mcp:initialize row, the token was issued but the client never used it.', 'royal-mcp' ); ?>
			</li>
			<li>
				<strong><code>tools/call:*</code></strong>
				<?php esc_html_e( '— Individual tool invocations. Records the tool name and success or error status. Argument values are never logged.', 'royal-mcp' ); ?>
			</li>
		</ul>
	</div>

	<!-- =====================================================
	     Automatic host-compatibility detection
	     ===================================================== -->
	<div class="royal-mcp-help-section">
		<h3><?php esc_html_e( 'Automatic host-compatibility notices', 'royal-mcp' ); ?></h3>
		<p><?php esc_html_e( 'Royal MCP self-checks for the most common host and plugin configurations that would block the connection, and surfaces a specific admin notice with the fix when it finds one. Watch for these at the top of the Royal MCP admin pages:', 'royal-mcp' ); ?></p>
		<ul class="royal-mcp-help-ladder">
			<li><strong><?php esc_html_e( 'Managed-host /.well-known/ reservation', 'royal-mcp' ); ?></strong> &mdash; <?php esc_html_e( 'SiteGround, WP Engine, and similar hosts that intercept the path before WordPress can respond.', 'royal-mcp' ); ?></li>
			<li><strong><?php esc_html_e( 'Plain permalinks', 'royal-mcp' ); ?></strong> &mdash; <?php esc_html_e( 'Royal MCP\'s discovery routes need pretty permalinks to fire.', 'royal-mcp' ); ?></li>
			<li><strong><?php esc_html_e( 'REST API disabled by another plugin', 'royal-mcp' ); ?></strong> &mdash; <?php esc_html_e( 'Perfmatters, Solid Security, and other plugins that disable the REST API for unauthenticated callers.', 'royal-mcp' ); ?></li>
			<li><strong><?php esc_html_e( 'WAF interception on discovery paths', 'royal-mcp' ); ?></strong> &mdash; <?php esc_html_e( 'BitNinja, Imunify360, Sucuri edge, and similar WAF products that block or challenge the OAuth discovery endpoints.', 'royal-mcp' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'Each notice links to a specific fix. Dismiss individually once addressed. The check re-runs when you update Royal MCP settings or change WordPress permalinks.', 'royal-mcp' ); ?></p>
	</div>

	<!-- =====================================================
	     Still stuck? Point to Support tab + Activity Log
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-nextstep">
		<p>
			<?php
			printf(
				wp_kses(
					/* translators: 1: link to Activity Log, 2: link to Support tab */
					__( 'Still stuck? Check %1$s for the exact validation rule that fired on your most recent OAuth attempt — that error string is the single most useful thing to include in a support request. Then head to %2$s to reach out.', 'royal-mcp' ),
					[ 'a' => [ 'href' => [] ] ]
				),
				'<a href="' . esc_url( $royal_mcp_help_logs_url ) . '">' . esc_html__( 'Activity Log', 'royal-mcp' ) . '</a>',
				'<a href="' . esc_url( \Royal_MCP\Admin\Help_Page::tab_url( 'support' ) ) . '">' . esc_html__( 'Support', 'royal-mcp' ) . '</a>'
			);
			?>
		</p>
	</div>

</div>
