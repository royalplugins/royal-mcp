<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$royal_mcp_help_diagnostic   = isset( $royal_mcp_help_diagnostic ) ? $royal_mcp_help_diagnostic : \Royal_MCP\Admin\Help_Page::DIAGNOSTIC_STEPS;
$royal_mcp_help_settings_url = admin_url( 'admin.php?page=royal-mcp' );
$royal_mcp_help_logs_url     = admin_url( 'admin.php?page=royal-mcp-logs' );
$royal_mcp_help_permalinks   = admin_url( 'options-permalink.php' );
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
