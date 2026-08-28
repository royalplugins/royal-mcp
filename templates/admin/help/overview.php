<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// $royal_mcp_help_endpoint is provided by Help_Page::render().
$royal_mcp_help_settings_url = admin_url( 'admin.php?page=royal-mcp' );
$royal_mcp_help_endpoint     = isset( $royal_mcp_help_endpoint ) ? $royal_mcp_help_endpoint : rest_url( 'royal-mcp/v1/mcp' );
$royal_mcp_help_endpoint     = preg_replace( '/^http:/', 'https:', $royal_mcp_help_endpoint );
?>
<div class="royal-mcp-help-tab-content royal-mcp-help-tab-overview">

	<div class="royal-mcp-help-hero">
		<h2>
			<svg class="royal-mcp-help-hero-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<circle cx="5" cy="12" r="2.5"/>
				<circle cx="19" cy="12" r="2.5"/>
				<circle cx="12" cy="5" r="2.5"/>
				<circle cx="12" cy="19" r="2.5"/>
				<line x1="7.5" y1="12" x2="16.5" y2="12"/>
				<line x1="12" y1="7.5" x2="12" y2="16.5"/>
			</svg>
			<?php esc_html_e( 'Connect your AI assistant to WordPress', 'royal-mcp' ); ?>
		</h2>
		<p class="royal-mcp-help-hero-lede">
			<?php esc_html_e( 'Royal MCP turns your WordPress site into a Model Context Protocol (MCP) server. Any MCP-compatible client — Claude, ChatGPT, Cursor, VS Code, Claude Code — can then read from and write to your site through a single secure endpoint.', 'royal-mcp' ); ?>
		</p>
	</div>

	<div class="royal-mcp-help-section royal-mcp-help-quickstart">
		<h3><?php esc_html_e( 'Quick start (3 steps)', 'royal-mcp' ); ?></h3>
		<ol class="royal-mcp-help-steps">
			<li>
				<strong><?php esc_html_e( 'Enable the integration.', 'royal-mcp' ); ?></strong>
				<?php
				printf(
					wp_kses(
						/* translators: %s: link to Royal MCP Settings page */
						__( 'Open %s and toggle Royal MCP on. Generate an API key or leave OAuth enabled depending on which client you\'ll connect.', 'royal-mcp' ),
						[ 'a' => [ 'href' => [] ] ]
					),
					'<a href="' . esc_url( $royal_mcp_help_settings_url ) . '">' . esc_html__( 'Royal MCP → Settings', 'royal-mcp' ) . '</a>'
				);
				?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Copy your MCP endpoint URL.', 'royal-mcp' ); ?></strong>
				<code class="royal-mcp-help-endpoint"><?php echo esc_html( $royal_mcp_help_endpoint ); ?></code>
				<span class="description"><?php esc_html_e( 'Same URL for every MCP client.', 'royal-mcp' ); ?></span>
			</li>
			<li>
				<strong><?php esc_html_e( 'Add it to your AI client.', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'Pick your client below for step-by-step setup.', 'royal-mcp' ); ?>
			</li>
		</ol>
	</div>

	<div class="royal-mcp-help-section royal-mcp-help-clients">
		<h3><?php esc_html_e( 'Pick your client', 'royal-mcp' ); ?></h3>
		<div class="royal-mcp-help-client-grid">

			<a class="royal-mcp-help-client-card" href="<?php echo esc_url( \Royal_MCP\Admin\Help_Page::tab_url( 'claude' ) ); ?>">
				<div class="royal-mcp-help-client-icon">
					<img src="<?php echo esc_url( ROYAL_MCP_PLUGIN_URL . 'assets/img/clients/claude.svg' ); ?>" alt="" width="48" height="48" />
				</div>
				<h4><?php esc_html_e( 'Claude', 'royal-mcp' ); ?></h4>
				<p><?php esc_html_e( 'Claude Desktop, Claude.ai (Web), and Claude Code CLI.', 'royal-mcp' ); ?></p>
				<span class="royal-mcp-help-client-cta"><?php esc_html_e( 'Setup guide', 'royal-mcp' ); ?> &rarr;</span>
			</a>

			<a class="royal-mcp-help-client-card" href="<?php echo esc_url( \Royal_MCP\Admin\Help_Page::tab_url( 'chatgpt' ) ); ?>">
				<div class="royal-mcp-help-client-icon">
					<img src="<?php echo esc_url( ROYAL_MCP_PLUGIN_URL . 'assets/img/clients/chatgpt.svg' ); ?>" alt="" width="48" height="48" />
				</div>
				<h4><?php esc_html_e( 'ChatGPT', 'royal-mcp' ); ?></h4>
				<p><?php esc_html_e( 'Custom GPTs (Plus/Team/Enterprise) and Dev Mode plugins.', 'royal-mcp' ); ?></p>
				<span class="royal-mcp-help-client-cta"><?php esc_html_e( 'Setup guide', 'royal-mcp' ); ?> &rarr;</span>
			</a>

			<a class="royal-mcp-help-client-card" href="<?php echo esc_url( \Royal_MCP\Admin\Help_Page::tab_url( 'other-clients' ) ); ?>">
				<div class="royal-mcp-help-client-icon royal-mcp-help-client-icon-multi">
					<img src="<?php echo esc_url( ROYAL_MCP_PLUGIN_URL . 'assets/img/clients/cursor.svg' ); ?>" alt="" width="24" height="24" />
					<img src="<?php echo esc_url( ROYAL_MCP_PLUGIN_URL . 'assets/img/clients/vscode.svg' ); ?>" alt="" width="24" height="24" />
				</div>
				<h4><?php esc_html_e( 'Other Clients', 'royal-mcp' ); ?></h4>
				<p><?php esc_html_e( 'Cursor, VS Code (via Continue), and any generic MCP client.', 'royal-mcp' ); ?></p>
				<span class="royal-mcp-help-client-cta"><?php esc_html_e( 'Setup guide', 'royal-mcp' ); ?> &rarr;</span>
			</a>

		</div>
	</div>

	<div class="royal-mcp-help-section royal-mcp-help-nextstep">
		<p>
			<?php
			printf(
				wp_kses(
					/* translators: 1: link to Troubleshooting tab, 2: link to Support tab */
					__( 'Having trouble? See %1$s for the 4-step diagnostic that fixes 90%% of connection issues, or head to %2$s for the full documentation hub.', 'royal-mcp' ),
					[ 'a' => [ 'href' => [] ] ]
				),
				'<a href="' . esc_url( \Royal_MCP\Admin\Help_Page::tab_url( 'troubleshooting' ) ) . '">' . esc_html__( 'Troubleshooting', 'royal-mcp' ) . '</a>',
				'<a href="' . esc_url( \Royal_MCP\Admin\Help_Page::tab_url( 'support' ) ) . '">' . esc_html__( 'Support', 'royal-mcp' ) . '</a>'
			);
			?>
		</p>
	</div>

</div>
