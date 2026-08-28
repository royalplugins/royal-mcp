<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$royal_mcp_help_endpoint = isset( $royal_mcp_help_endpoint ) ? $royal_mcp_help_endpoint : rest_url( 'royal-mcp/v1/mcp' );
$royal_mcp_help_endpoint = preg_replace( '/^http:/', 'https:', $royal_mcp_help_endpoint );
?>
<div class="royal-mcp-help-tab-content royal-mcp-help-tab-other-clients">

	<div class="royal-mcp-help-tab-intro">
		<h2><?php esc_html_e( 'Other MCP Clients', 'royal-mcp' ); ?></h2>
		<p><?php esc_html_e( 'Royal MCP speaks the standard Model Context Protocol over streamable HTTP — every MCP-compatible client uses the same endpoint URL. What changes between clients is where you put the config.', 'royal-mcp' ); ?></p>
		<p><strong><?php esc_html_e( 'Your endpoint:', 'royal-mcp' ); ?></strong>
			<code class="royal-mcp-help-endpoint"><?php echo esc_html( $royal_mcp_help_endpoint ); ?></code>
		</p>
	</div>

	<!-- =====================================================
	     Cursor
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-client-section">
		<div class="royal-mcp-help-section-header">
			<img class="royal-mcp-help-section-icon" src="<?php echo esc_url( ROYAL_MCP_PLUGIN_URL . 'assets/img/clients/cursor.svg' ); ?>" alt="" width="32" height="32" />
			<h3><?php esc_html_e( 'Cursor', 'royal-mcp' ); ?></h3>
		</div>

		<p><?php esc_html_e( 'Cursor reads MCP servers from a JSON config file. Global config (all projects) or per-project — pick either.', 'royal-mcp' ); ?></p>

		<ul class="royal-mcp-help-sublist">
			<li><strong><?php esc_html_e( 'Global:', 'royal-mcp' ); ?></strong> <code>~/.cursor/mcp.json</code></li>
			<li><strong><?php esc_html_e( 'Per-project:', 'royal-mcp' ); ?></strong> <code>.cursor/mcp.json</code> <?php esc_html_e( 'in your project root', 'royal-mcp' ); ?></li>
		</ul>

		<pre class="royal-mcp-help-code"><code>{
  "mcpServers": {
    "royal-mcp": {
      "url": "<?php echo esc_html( $royal_mcp_help_endpoint ); ?>"
    }
  }
}</code></pre>

		<p><?php esc_html_e( 'Save the file and restart Cursor. Open the chat panel — Royal MCP tools appear in the tool picker. First tool call opens the browser for OAuth authorization.', 'royal-mcp' ); ?></p>

		<p class="description">
			<?php esc_html_e( 'Cursor version 0.45+ recommended for streamable HTTP transport. Older versions may need the mcp-remote wrapper — see Cursor\'s own MCP docs for that path.', 'royal-mcp' ); ?>
		</p>
	</div>

	<!-- =====================================================
	     VS Code (Copilot Agent Mode)
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-client-section">
		<div class="royal-mcp-help-section-header">
			<img class="royal-mcp-help-section-icon" src="<?php echo esc_url( ROYAL_MCP_PLUGIN_URL . 'assets/img/clients/vscode.svg' ); ?>" alt="" width="32" height="32" />
			<h3><?php esc_html_e( 'VS Code (GitHub Copilot Agent Mode)', 'royal-mcp' ); ?></h3>
		</div>

		<p><?php esc_html_e( 'MCP support in VS Code routes through extensions. The most common path is GitHub Copilot Chat\'s Agent Mode, which reads MCP config from a workspace file.', 'royal-mcp' ); ?></p>

		<p><?php esc_html_e( 'Create', 'royal-mcp' ); ?> <code>.vscode/mcp.json</code> <?php esc_html_e( 'in your workspace:', 'royal-mcp' ); ?></p>

		<pre class="royal-mcp-help-code"><code>{
  "servers": {
    "royal-mcp": {
      "type": "http",
      "url": "<?php echo esc_html( $royal_mcp_help_endpoint ); ?>"
    }
  }
}</code></pre>

		<p><?php esc_html_e( 'Reload VS Code, open Copilot Chat, switch to Agent mode — Royal MCP tools appear in the tool selector. First invocation triggers the OAuth flow in your default browser.', 'royal-mcp' ); ?></p>

		<p class="description">
			<?php esc_html_e( 'The VS Code MCP ecosystem also includes Continue.dev, dedicated MCP extensions, and JetBrains-style IDE plugins. Config path and JSON shape vary — check your extension\'s own docs. The Royal MCP endpoint URL is always the same.', 'royal-mcp' ); ?>
		</p>
	</div>

	<!-- =====================================================
	     Continue.dev
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-client-section">
		<div class="royal-mcp-help-section-header">
			<h3><?php esc_html_e( 'Continue.dev (VS Code + JetBrains)', 'royal-mcp' ); ?></h3>
		</div>

		<p><?php esc_html_e( 'Continue reads MCP servers from', 'royal-mcp' ); ?> <code>~/.continue/config.yaml</code> <?php esc_html_e( '(global) or', 'royal-mcp' ); ?> <code>.continue/config.yaml</code> <?php esc_html_e( '(per-workspace). You can also drop individual server files into', 'royal-mcp' ); ?> <code>.continue/mcpServers/</code>.</p>

		<pre class="royal-mcp-help-code"><code>mcpServers:
  - name: royal-mcp
    type: streamable-http
    url: <?php echo esc_html( $royal_mcp_help_endpoint ); ?>
</code></pre>

		<p><?php esc_html_e( 'Save, reload Continue in your IDE, open a chat — the Royal MCP tools appear in the tool list. First call opens the OAuth consent in your browser.', 'royal-mcp' ); ?></p>
	</div>

	<!-- =====================================================
	     Generic MCP client
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-client-section">
		<div class="royal-mcp-help-section-header">
			<h3><?php esc_html_e( 'Any other MCP client', 'royal-mcp' ); ?></h3>
		</div>

		<p><?php esc_html_e( 'Royal MCP implements the standard MCP protocol — any spec-compliant client works. Give it these three facts:', 'royal-mcp' ); ?></p>

		<ul class="royal-mcp-help-sublist">
			<li><strong><?php esc_html_e( 'Endpoint URL:', 'royal-mcp' ); ?></strong> <code><?php echo esc_html( $royal_mcp_help_endpoint ); ?></code></li>
			<li><strong><?php esc_html_e( 'Transport:', 'royal-mcp' ); ?></strong> <?php esc_html_e( 'streamable HTTP (not SSE, not stdio)', 'royal-mcp' ); ?></li>
			<li><strong><?php esc_html_e( 'Authentication:', 'royal-mcp' ); ?></strong> <?php esc_html_e( 'OAuth 2.1 with dynamic client registration (recommended) or Bearer token via', 'royal-mcp' ); ?> <code>Authorization: Bearer &lt;api-key&gt;</code> <?php esc_html_e( 'header. Grab your API key from Royal MCP → Settings.', 'royal-mcp' ); ?></li>
		</ul>

		<p><?php esc_html_e( 'For OAuth-capable clients, Royal MCP publishes discovery metadata at', 'royal-mcp' ); ?>
			<code>/.well-known/oauth-authorization-server</code>
			<?php esc_html_e( 'and', 'royal-mcp' ); ?>
			<code>/.well-known/oauth-protected-resource</code>
			<?php esc_html_e( '— clients that speak the MCP OAuth discovery pattern will auto-configure.', 'royal-mcp' ); ?>
		</p>
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
					__( 'Connection failing? Start with the 4-step diagnostic on the %s tab.', 'royal-mcp' ),
					[ 'a' => [ 'href' => [] ] ]
				),
				'<a href="' . esc_url( \Royal_MCP\Admin\Help_Page::tab_url( 'troubleshooting' ) ) . '">' . esc_html__( 'Troubleshooting', 'royal-mcp' ) . '</a>'
			);
			?>
		</p>
	</div>

</div>
