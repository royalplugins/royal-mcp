<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$royal_mcp_help_endpoint     = isset( $royal_mcp_help_endpoint ) ? $royal_mcp_help_endpoint : rest_url( 'royal-mcp/v1/mcp' );
$royal_mcp_help_endpoint     = preg_replace( '/^http:/', 'https:', $royal_mcp_help_endpoint );
$royal_mcp_help_settings_url = admin_url( 'admin.php?page=royal-mcp' );
$royal_mcp_help_logs_url     = defined( 'ROYAL_MCP_LOADED_BY_PRO' )
	? admin_url( 'admin.php?page=royal-mcp-pro' )
	: admin_url( 'admin.php?page=royal-mcp-logs' );
?>
<div class="royal-mcp-help-tab-content royal-mcp-help-tab-claude">

	<div class="royal-mcp-help-tab-intro">
		<h2><?php esc_html_e( 'Connect Claude', 'royal-mcp' ); ?></h2>
		<p><?php esc_html_e( 'Claude Desktop and claude.ai (browser) use the same connector flow — one set of steps covers both. Claude Code (CLI) is a separate one-command install.', 'royal-mcp' ); ?></p>
	</div>

	<!-- =====================================================
	     Claude Desktop + claude.ai — CURRENT wizard (as of Aug 2026)
	     Anthropic redesigned the Add custom connector experience mid-Aug 2026.
	     The wizard now has up to 4 screens depending on how fast your server
	     responds to OAuth discovery within a 5-second budget.
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-client-section">
		<div class="royal-mcp-help-section-header">
			<img class="royal-mcp-help-section-icon" src="<?php echo esc_url( ROYAL_MCP_PLUGIN_URL . 'assets/img/clients/claude.svg' ); ?>" alt="" width="32" height="32" />
			<h3><?php esc_html_e( 'Claude Desktop + claude.ai (Web)', 'royal-mcp' ); ?></h3>
		</div>

		<p><?php esc_html_e( 'Anthropic redesigned the Add custom connector wizard mid-August 2026. The flow now has up to 4 screens depending on how fast your server responds to OAuth discovery — with a 5-second budget most WordPress sites will exceed at least occasionally. Both outcomes work; here is what each looks like.', 'royal-mcp' ); ?></p>

		<div class="royal-mcp-help-callout royal-mcp-help-callout-warning">
			<strong><?php esc_html_e( 'Expect this to take 2 tries.', 'royal-mcp' ); ?></strong>
			<?php esc_html_e( 'The 5-second OAuth-discovery budget in the wizard is aggressive for shared hosting + Cloudflare-fronted sites. Timing out is normal, not broken. The wizard shows a full options screen you can complete manually.', 'royal-mcp' ); ?>
		</div>

		<h4><?php esc_html_e( 'Step 1 — Open Settings → Connectors → Add custom connector', 'royal-mcp' ); ?></h4>
		<p><?php esc_html_e( 'claude.ai: profile menu (bottom-left) → Settings → Connectors → Add custom connector. Claude Desktop: Settings (gear) → Connectors → Add custom connector. Or click the + button in any chat window → Add connectors → Add custom connector.', 'royal-mcp' ); ?></p>

		<h4><?php esc_html_e( 'Step 2 — Name it, paste your MCP endpoint', 'royal-mcp' ); ?></h4>
		<p><?php esc_html_e( 'Give it any name (e.g. "My Site MCP"). Paste this endpoint into the URL field:', 'royal-mcp' ); ?></p>
		<code class="royal-mcp-help-endpoint"><?php echo esc_html( $royal_mcp_help_endpoint ); ?></code>
		<p><?php esc_html_e( 'The wizard fires a 3-step check: Connect to the server → Find the authorization server → Verifying OAuth configuration. Two things can happen next.', 'royal-mcp' ); ?></p>

		<!-- Path A: Fast check succeeds -->
		<h4><?php esc_html_e( 'Path A — Check finishes in under 5 seconds (fastest path)', 'royal-mcp' ); ?></h4>
		<p><?php esc_html_e( 'The wizard closes and Claude opens a new tab / dialog with the OAuth authorization prompt from your WordPress site. Click Authorize. Skip to Step 4 below.', 'royal-mcp' ); ?></p>

		<!-- Path B: 5-second timeout dumps to options screen -->
		<h4><?php esc_html_e( 'Path B — Check exceeds 5 seconds (very common — this is normal)', 'royal-mcp' ); ?></h4>
		<p><?php esc_html_e( 'The wizard dumps you to a full options screen with a yellow banner: "Some checks were skipped because the server took longer than 5 seconds. The settings below may need adjusting." This is not an error — it is a fallback flow. Configure it like this:', 'royal-mcp' ); ?></p>

		<div class="royal-mcp-help-options-card">
			<div class="royal-mcp-help-options-card-heading"><?php esc_html_e( 'Recommended settings for Royal MCP', 'royal-mcp' ); ?></div>

			<div class="royal-mcp-help-option-row">
				<div class="royal-mcp-help-option-label"><?php esc_html_e( 'Authentication', 'royal-mcp' ); ?></div>
				<div class="royal-mcp-help-option-value"><?php esc_html_e( 'Always required', 'royal-mcp' ); ?></div>
				<div class="royal-mcp-help-option-note"><?php esc_html_e( 'Usually pre-selected as "Detected." Every session prompts for OAuth sign-in before Royal MCP tools can run — the correct behavior for a WordPress site.', 'royal-mcp' ); ?></div>
			</div>

			<div class="royal-mcp-help-option-row">
				<div class="royal-mcp-help-option-label"><?php esc_html_e( 'OAuth client', 'royal-mcp' ); ?></div>
				<div class="royal-mcp-help-option-value"><?php esc_html_e( 'No client ID — register one automatically', 'royal-mcp' ); ?></div>
				<div class="royal-mcp-help-option-note"><?php esc_html_e( 'Standard Dynamic Client Registration (DCR, RFC 7591) — the OAuth pattern Royal MCP has always supported. The other option, "Use Anthropic\'s hosted client metadata (Recommended)," is a newer pattern Claude silently falls back to DCR against Royal MCP anyway, so DCR is the clean choice today.', 'royal-mcp' ); ?></div>
			</div>

			<div class="royal-mcp-help-option-row">
				<div class="royal-mcp-help-option-label"><?php esc_html_e( 'Additional request headers', 'royal-mcp' ); ?></div>
				<div class="royal-mcp-help-option-value"><?php esc_html_e( 'Leave empty', 'royal-mcp' ); ?></div>
				<div class="royal-mcp-help-option-note"><?php esc_html_e( 'Only used if you want to authenticate with a fixed API key instead of OAuth.', 'royal-mcp' ); ?></div>
			</div>

			<div class="royal-mcp-help-option-row">
				<div class="royal-mcp-help-option-label"><?php esc_html_e( 'Advanced', 'royal-mcp' ); ?></div>
				<div class="royal-mcp-help-option-value"><?php esc_html_e( 'Leave collapsed', 'royal-mcp' ); ?></div>
				<div class="royal-mcp-help-option-note"><?php esc_html_e( 'Not needed for standard Royal MCP setup.', 'royal-mcp' ); ?></div>
			</div>
		</div>

		<h4><?php esc_html_e( 'Step 3 — Click Add, then Connect', 'royal-mcp' ); ?></h4>
		<p><?php esc_html_e( 'After Path B: clicking Add does NOT complete the connection. The wizard closes to the connector detail page showing "You are not connected to [name] yet" and a Connect button. Click Connect — a new tab / dialog opens with the OAuth authorization prompt from your WordPress site.', 'royal-mcp' ); ?></p>
		<p class="description"><?php esc_html_e( 'If the OAuth prompt does not appear after Connect, click Connect again. The wizard sometimes needs a second attempt.', 'royal-mcp' ); ?></p>

		<h4><?php esc_html_e( 'Step 4 — Authorize on the WordPress-hosted OAuth prompt', 'royal-mcp' ); ?></h4>
		<p><?php esc_html_e( 'You will see "Claude wants to connect to your WordPress site" with the scopes listed. Click Authorize. The tab closes and Claude flips the connector to Connected.', 'royal-mcp' ); ?></p>

		<h4><?php esc_html_e( 'Step 5 — Verify in a new chat', 'royal-mcp' ); ?></h4>
		<p><?php esc_html_e( 'Open a new conversation → click the + button next to the input → Connectors → toggle your Royal MCP connector on. Ask something WordPress-specific ("List my most recent posts") to confirm tools fire.', 'royal-mcp' ); ?></p>

		<div class="royal-mcp-help-callout">
			<strong><?php esc_html_e( 'Tip:', 'royal-mcp' ); ?></strong>
			<?php
			printf(
				wp_kses(
					/* translators: %s: link to Activity Log admin page */
					__( 'Keep %s open in another browser tab while you connect. You will see live entries fire (oauth:register → oauth:authorize → oauth:token) — if something fails, the log shows which step and why.', 'royal-mcp' ),
					[ 'a' => [ 'href' => [] ] ]
				),
				'<a href="' . esc_url( $royal_mcp_help_logs_url ) . '">' . esc_html__( 'Royal MCP → Activity Log', 'royal-mcp' ) . '</a>'
			);
			?>
		</div>

		<div class="royal-mcp-help-callout royal-mcp-help-callout-warning">
			<strong><?php esc_html_e( 'Windows users:', 'royal-mcp' ); ?></strong>
			<?php esc_html_e( 'Install Claude Desktop from claude.ai/download, NOT the Microsoft Store. The Store version is sandboxed and reads config from a non-standard path — any edits to the documented config path get silently ignored.', 'royal-mcp' ); ?>
		</div>

		<p class="royal-mcp-help-section-cta">
			<?php echo \Royal_MCP\Admin\Help_Page::full_guide_link( 'claude_web', __( 'Full Claude Connection Guide (with screenshots)', 'royal-mcp' ) ); ?>
		</p>
	</div>

	<!-- =====================================================
	     Claude Code CLI — unchanged flow (wizard redesign only affects web/desktop)
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-client-section">
		<div class="royal-mcp-help-section-header">
			<img class="royal-mcp-help-section-icon" src="<?php echo esc_url( ROYAL_MCP_PLUGIN_URL . 'assets/img/clients/claude.svg' ); ?>" alt="" width="32" height="32" />
			<h3><?php esc_html_e( 'Claude Code (CLI)', 'royal-mcp' ); ?></h3>
		</div>

		<p><?php esc_html_e( 'Claude Code is Anthropic\'s official CLI (also available as a Mac/Windows app, VS Code extension, and JetBrains plugin — all share the same MCP config). Get it from claude.com/claude-code if you don\'t have it installed. The CLI uses a direct one-command install, not the browser wizard.', 'royal-mcp' ); ?></p>

		<ol class="royal-mcp-help-steps">
			<li>
				<strong><?php esc_html_e( 'Add the server.', 'royal-mcp' ); ?></strong>
				<pre class="royal-mcp-help-code"><code>claude mcp add --transport http royal-mcp <?php echo esc_html( $royal_mcp_help_endpoint ); ?></code></pre>
			</li>
			<li>
				<strong><?php esc_html_e( 'Restart your Claude Code session.', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'Exit the current session and start a new one so Claude Code reloads the MCP config.', 'royal-mcp' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Type /mcp in the session.', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'This opens the Manage MCP servers screen. Every connector is listed with its state: ✓ connected (working) / △ needs authentication (stale token) / ✗ failed (server didn\'t respond).', 'royal-mcp' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Authorize on first tool call.', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'When Claude Code first calls a Royal MCP tool, your browser opens for the OAuth consent screen. Click Authorize — the terminal receives the token and the connector activates.', 'royal-mcp' ); ?>
			</li>
		</ol>

		<p class="description">
			<?php esc_html_e( 'Prefer a shell view? Run', 'royal-mcp' ); ?>
			<code>claude mcp list</code>
			<?php esc_html_e( 'to see all registered servers non-interactively.', 'royal-mcp' ); ?>
		</p>

		<div class="royal-mcp-help-callout">
			<strong><?php esc_html_e( 'Stale connector?', 'royal-mcp' ); ?></strong>
			<?php esc_html_e( 'Type /mcp → arrow to the affected connector → Enter → select "Authenticate". Browser opens for a fresh OAuth handshake. About 80% of "tool not working" issues in Claude Code are stale tokens.', 'royal-mcp' ); ?>
		</div>
	</div>

	<!-- =====================================================
	     Trouble? Trouble.
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-nextstep">
		<p>
			<?php
			printf(
				wp_kses(
					/* translators: %s: link to Troubleshooting tab */
					__( 'Connection failing? Start with the 4-step diagnostic on the %s tab — it fixes most Claude connection issues.', 'royal-mcp' ),
					[ 'a' => [ 'href' => [] ] ]
				),
				'<a href="' . esc_url( \Royal_MCP\Admin\Help_Page::tab_url( 'troubleshooting' ) ) . '">' . esc_html__( 'Troubleshooting', 'royal-mcp' ) . '</a>'
			);
			?>
		</p>
	</div>

</div>
