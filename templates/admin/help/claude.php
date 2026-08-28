<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$royal_mcp_help_endpoint     = isset( $royal_mcp_help_endpoint ) ? $royal_mcp_help_endpoint : rest_url( 'royal-mcp/v1/mcp' );
$royal_mcp_help_endpoint     = preg_replace( '/^http:/', 'https:', $royal_mcp_help_endpoint );
$royal_mcp_help_settings_url = admin_url( 'admin.php?page=royal-mcp' );
$royal_mcp_help_logs_url     = admin_url( 'admin.php?page=royal-mcp-logs' );
?>
<div class="royal-mcp-help-tab-content royal-mcp-help-tab-claude">

	<div class="royal-mcp-help-tab-intro">
		<h2><?php esc_html_e( 'Connect Claude', 'royal-mcp' ); ?></h2>
		<p><?php esc_html_e( 'Claude Desktop and claude.ai (browser) use the same connector flow — one set of steps covers both. Claude Code (CLI) is a separate one-command install.', 'royal-mcp' ); ?></p>
	</div>

	<!-- =====================================================
	     Claude Desktop + claude.ai — IDENTICAL setup
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-client-section">
		<div class="royal-mcp-help-section-header">
			<img class="royal-mcp-help-section-icon" src="<?php echo esc_url( ROYAL_MCP_PLUGIN_URL . 'assets/img/clients/claude.svg' ); ?>" alt="" width="32" height="32" />
			<h3><?php esc_html_e( 'Claude Desktop + claude.ai (Web)', 'royal-mcp' ); ?></h3>
		</div>

		<p><?php esc_html_e( 'Anthropic unified the connector UI across the browser and the desktop app. Follow the same steps whichever you use.', 'royal-mcp' ); ?></p>

		<ol class="royal-mcp-help-steps">
			<li>
				<strong><?php esc_html_e( 'Open Settings → Connectors.', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'claude.ai: profile menu (bottom-left) → Settings → Connectors. Claude Desktop: Settings (gear) → Connectors. Or click the + button in any chat window → Add connectors.', 'royal-mcp' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Click "Add custom connector".', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'A small panel opens asking for a name and URL.', 'royal-mcp' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Name it, then paste your MCP endpoint.', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'Name it whatever you like (e.g. "My Site MCP").', 'royal-mcp' ); ?>
				<code class="royal-mcp-help-endpoint"><?php echo esc_html( $royal_mcp_help_endpoint ); ?></code>
				<span class="description"><?php esc_html_e( 'Leave the Advanced Settings (Client ID / Client Secret) empty — that signals Claude to use auto Dynamic Client Registration, the recommended path.', 'royal-mcp' ); ?></span>
			</li>
			<li>
				<strong><?php esc_html_e( 'Click Connect — a new tab opens for authorization.', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'You\'ll see the OAuth consent screen from your WordPress site with the requested scope (mcp:full). Click Authorize. The tab closes and Claude flips the connector to Connected.', 'royal-mcp' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Verify in a new chat.', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'Open a new conversation → click the + button next to the input → Connectors → toggle your Royal MCP connector on. Ask something WordPress-specific ("Do we have a connector to example.com?") to confirm tools fire.', 'royal-mcp' ); ?>
			</li>
		</ol>

		<div class="royal-mcp-help-callout">
			<strong><?php esc_html_e( 'Tip:', 'royal-mcp' ); ?></strong>
			<?php
			printf(
				wp_kses(
					/* translators: %s: link to Activity Log admin page */
					__( 'Keep %s open in another browser tab while you connect. You\'ll see live entries fire (oauth:register → oauth:authorize → oauth:token) — if something fails, the log shows which step and why.', 'royal-mcp' ),
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
			<?php echo \Royal_MCP\Admin\Help_Page::full_guide_link( 'claude_web', __( 'Full Claude connection guide', 'royal-mcp' ) ); ?>
		</p>
	</div>

	<!-- =====================================================
	     Claude Code CLI
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-client-section">
		<div class="royal-mcp-help-section-header">
			<img class="royal-mcp-help-section-icon" src="<?php echo esc_url( ROYAL_MCP_PLUGIN_URL . 'assets/img/clients/claude.svg' ); ?>" alt="" width="32" height="32" />
			<h3><?php esc_html_e( 'Claude Code (CLI)', 'royal-mcp' ); ?></h3>
		</div>

		<p><?php esc_html_e( 'Claude Code is Anthropic\'s official CLI (also available as a Mac/Windows app, VS Code extension, and JetBrains plugin — all share the same MCP config). Get it from claude.com/claude-code if you don\'t have it installed.', 'royal-mcp' ); ?></p>

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
