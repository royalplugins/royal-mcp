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
			<li><strong><?php esc_html_e( 'Transport:', 'royal-mcp' ); ?></strong> <?php esc_html_e( 'streamable HTTP (not SSE). For a site on your own machine, a local STDIO server through WP-CLI also works; see below.', 'royal-mcp' ); ?></li>
			<li><strong><?php esc_html_e( 'Authentication:', 'royal-mcp' ); ?></strong> <?php esc_html_e( 'OAuth 2.1 with dynamic client registration (recommended) or Bearer token via', 'royal-mcp' ); ?> <code>Authorization: Bearer &lt;api-key&gt;</code> <?php esc_html_e( 'header. Grab your API key from Royal MCP → Connections → Connection settings.', 'royal-mcp' ); ?></li>
		</ul>

		<p><?php esc_html_e( 'For OAuth-capable clients, Royal MCP publishes discovery metadata at', 'royal-mcp' ); ?>
			<code>/.well-known/oauth-authorization-server</code>
			<?php esc_html_e( 'and', 'royal-mcp' ); ?>
			<code>/.well-known/oauth-protected-resource</code>
			<?php esc_html_e( '— clients that speak the MCP OAuth discovery pattern will auto-configure.', 'royal-mcp' ); ?>
		</p>
	</div>

	<!-- =====================================================
	     Local sites: STDIO through WP-CLI
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-client-section royal-mcp-help-stdio">
		<div class="royal-mcp-help-section-header">
			<h3><?php esc_html_e( 'Local sites: STDIO through WP-CLI', 'royal-mcp' ); ?></h3>
		</div>

		<p><?php esc_html_e( 'When the site runs on the same machine as the AI client (Local, DevKinsta, Docker, or a server you are logged in to), the client can start Royal MCP itself with WP-CLI. Nothing listens on the network and there is no sign-in: the client acts as the WordPress user you name.', 'royal-mcp' ); ?></p>

		<pre class="royal-mcp-help-code"><code>wp royal-mcp serve --user=&lt;login&gt; --path=&lt;WordPress folder&gt;</code></pre>

		<p><?php esc_html_e( 'Claude Desktop, Cursor and other clients take it as a command entry:', 'royal-mcp' ); ?></p>

		<pre class="royal-mcp-help-code"><code>{
  "mcpServers": {
    "royal-mcp": {
      "command": "wp",
      "args": ["royal-mcp", "serve", "--user=admin", "--path=/path/to/wordpress"]
    }
  }
}</code></pre>

		<p><?php esc_html_e( 'Claude Code:', 'royal-mcp' ); ?> <code>claude mcp add royal-mcp -- wp royal-mcp serve --user=admin --path=/path/to/wordpress</code></p>

		<ul class="royal-mcp-help-sublist">
			<li><?php esc_html_e( 'The user must be a Royal MCP administrator. To serve as another user, add --allow-non-admin; each tool still needs its own capability.', 'royal-mcp' ); ?></li>
			<li><?php esc_html_e( 'Add --access=read-only or --access=drafts-only to limit the connection further. Read-only mode, drafts-only mode and tool groups apply as on every connection.', 'royal-mcp' ); ?></li>
			<li><?php esc_html_e( 'Every call is recorded in the Activity Log as a local WP-CLI connection, with the name the client gives.', 'royal-mcp' ); ?></li>
			<li><?php esc_html_e( 'If the client cannot find wp, put the full path to wp (or to php, with wp-cli.phar as the first argument) in "command".', 'royal-mcp' ); ?></li>
		</ul>

		<p><?php esc_html_e( 'On the site, wp royal-mcp setup runs the Connection Doctor and prints ready-to-paste settings for Claude Desktop, Claude Code, Cursor and other clients (add --create-key for a new API key, --format=json for scripts).', 'royal-mcp' ); ?></p>
	</div>

	<!-- =====================================================
	     Task prompts
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-prompts">
		<div class="royal-mcp-help-section-header">
			<h3><?php esc_html_e( 'Task prompts', 'royal-mcp' ); ?></h3>
		</div>

		<p><?php esc_html_e( 'Royal MCP offers ready-made tasks as MCP prompts, which many clients show as slash commands: weekly-site-check, launch-checklist, security-review and content-audit. Each one tells the AI which tools to call and what to report, and asks it to only read and to change nothing unless you ask after the report. The usual permissions and safety modes still decide what the AI can do.', 'royal-mcp' ); ?></p>
	</div>

	<!-- =====================================================
	     Shorter tool lists
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-profiles">
		<div class="royal-mcp-help-section-header">
			<h3><?php esc_html_e( 'Shorter tool lists', 'royal-mcp' ); ?></h3>
		</div>

		<p><?php esc_html_e( 'Some clients only accept a limited number of tools. Add one of these to the end of the endpoint URL:', 'royal-mcp' ); ?></p>

		<ul class="royal-mcp-help-sublist">
			<li><code>?tools=compact</code> — <?php esc_html_e( '18 tools: everyday reads plus discover_tools, get_tool_info and execute_tool. Every other tool is still reachable through execute_tool, with the same permission checks.', 'royal-mcp' ); ?></li>
			<li><code>?tools=core</code> — <?php esc_html_e( 'WordPress tools only (no plugin integrations), plus the same three routing tools.', 'royal-mcp' ); ?></li>
			<li><code>?tools=seopress</code> — <?php esc_html_e( 'the core list plus the SEOPress tools.', 'royal-mcp' ); ?></li>
		</ul>

		<pre class="royal-mcp-help-code"><code><?php echo esc_html( $royal_mcp_help_endpoint . '?tools=compact' ); ?></code></pre>
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
