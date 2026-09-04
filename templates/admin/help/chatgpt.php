<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$royal_mcp_help_endpoint     = isset( $royal_mcp_help_endpoint ) ? $royal_mcp_help_endpoint : rest_url( 'royal-mcp/v1/mcp' );
$royal_mcp_help_endpoint     = preg_replace( '/^http:/', 'https:', $royal_mcp_help_endpoint );
$royal_mcp_help_logs_url     = defined( 'ROYAL_MCP_LOADED_BY_PRO' )
	? admin_url( 'admin.php?page=royal-mcp-pro' )
	: admin_url( 'admin.php?page=royal-mcp-logs' );
?>
<div class="royal-mcp-help-tab-content royal-mcp-help-tab-chatgpt">

	<div class="royal-mcp-help-tab-intro">
		<h2><?php esc_html_e( 'Connect ChatGPT', 'royal-mcp' ); ?></h2>
		<p><?php esc_html_e( 'ChatGPT exposes MCP servers as Plugins (previously called Apps, previously Connectors). You need a paid ChatGPT plan and a one-time Developer Mode toggle before you can add one.', 'royal-mcp' ); ?></p>

		<div class="royal-mcp-help-callout royal-mcp-help-callout-warning">
			<strong><?php esc_html_e( 'Plan tier gates:', 'royal-mcp' ); ?></strong>
			<?php esc_html_e( 'Free tier has no Plugins feature. Plus and Pro can READ (posts, settings, media) but write actions silently fail on those tiers — that\'s an OpenAI plan gate, not a Royal MCP issue. Business, Enterprise, and Edu tiers get full write support.', 'royal-mcp' ); ?>
		</div>
	</div>

	<!-- =====================================================
	     Step 1: Enable Developer Mode
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-client-section">
		<div class="royal-mcp-help-section-header">
			<img class="royal-mcp-help-section-icon" src="<?php echo esc_url( ROYAL_MCP_PLUGIN_URL . 'assets/img/clients/chatgpt.svg' ); ?>" alt="" width="32" height="32" />
			<h3><?php esc_html_e( 'Step 1 — Enable Developer Mode (once per account)', 'royal-mcp' ); ?></h3>
		</div>

		<ol class="royal-mcp-help-steps">
			<li>
				<strong><?php esc_html_e( 'Open Settings → Plugins.', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'chatgpt.com → profile icon (bottom-left) → Settings → Plugins tab in the left sidebar.', 'royal-mcp' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Open Advanced settings.', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'Near the bottom of the Plugins panel.', 'royal-mcp' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Toggle Developer Mode ON.', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'OpenAI shows an ELEVATED RISK warning — read it and confirm. Dev Mode stays on until you turn it off.', 'royal-mcp' ); ?>
			</li>
		</ol>

		<div class="royal-mcp-help-callout">
			<strong><?php esc_html_e( "Toggle isn't showing?", 'royal-mcp' ); ?></strong>
			<?php esc_html_e( 'Check three things: (1) you\'re on a paid tier, (2) if you\'re on Team/Business/Enterprise/Edu your org admin may have disabled it — check with them, (3) very recent Plus/Pro upgrades sometimes need a sign-out and sign-in to pick up the new capability.', 'royal-mcp' ); ?>
		</div>
	</div>

	<!-- =====================================================
	     Step 2: Add Royal MCP as a Plugin
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-client-section">
		<div class="royal-mcp-help-section-header">
			<img class="royal-mcp-help-section-icon" src="<?php echo esc_url( ROYAL_MCP_PLUGIN_URL . 'assets/img/clients/chatgpt.svg' ); ?>" alt="" width="32" height="32" />
			<h3><?php esc_html_e( 'Step 2 — Add Royal MCP as a Plugin', 'royal-mcp' ); ?></h3>
		</div>

		<p><?php esc_html_e( 'Once Developer Mode is on, an "Add more" button appears at the top-right of the Plugins panel.', 'royal-mcp' ); ?></p>

		<ol class="royal-mcp-help-steps">
			<li>
				<strong><?php esc_html_e( 'Click "Add more" → the New Plugin dialog opens.', 'royal-mcp' ); ?></strong>
			</li>
			<li>
				<strong><?php esc_html_e( 'Fill in the dialog.', 'royal-mcp' ); ?></strong>
				<ul class="royal-mcp-help-sublist">
					<li><strong>Name</strong> — <?php esc_html_e( 'a memorable label (e.g. "My WordPress Site").', 'royal-mcp' ); ?></li>
					<li><strong>Description</strong> — <?php esc_html_e( 'this actually matters. ChatGPT reads it to decide when to invoke the Plugin. Be specific ("Manages content, settings, and WooCommerce data on my WordPress site"), not generic ("WordPress MCP").', 'royal-mcp' ); ?></li>
					<li><strong>Server URL</strong> — <?php esc_html_e( 'paste your MCP endpoint:', 'royal-mcp' ); ?>
						<code class="royal-mcp-help-endpoint"><?php echo esc_html( $royal_mcp_help_endpoint ); ?></code>
						<span class="description"><?php esc_html_e( 'The dialog placeholder shows a /sse URL — IGNORE that, Royal MCP uses streamable HTTP, not SSE.', 'royal-mcp' ); ?></span>
					</li>
					<li><strong>Authentication</strong> — <?php esc_html_e( 'leave on OAuth (default). Once the URL is valid, Advanced OAuth settings shows "Review discovered OAuth settings" — that means auto-discovery worked. No Client ID or Client Secret to paste.', 'royal-mcp' ); ?></li>
				</ul>
			</li>
			<li>
				<strong><?php esc_html_e( 'Check "I understand and want to continue" and click Create.', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'OpenAI gates Create behind an explicit risk acknowledgment for unverified MCP servers.', 'royal-mcp' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Click "Sign in with [your Plugin name]".', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'ChatGPT shows a confirmation modal titled "Add [your Plugin name] to ChatGPT" with a single Sign in button plus three trust notes (permissions respected / you\'re in control / connectors may introduce risk). Nothing has happened on your WordPress site yet — the OAuth handshake starts the moment you click Sign in.', 'royal-mcp' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Authorize on your WordPress site.', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'A new browser tab opens the /authorize page on your site. Review the listed permissions (read posts/pages/media, create + edit content, manage taxonomies + menus, view site settings + user info) and click Authorize. Royal MCP issues an access token and the tab closes automatically.', 'royal-mcp' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Return to ChatGPT — Plugin is Connected.', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'Back on Settings → Plugins, your Plugin now shows a Connected state. Click it to open the detail view: Connection row, Permissions row (defaults to Allow low-risk actions — cover in the next step), and an Actions list showing every Royal MCP tool with its input schema. If you see an "action discovery failed" or "unknown error" banner during this step, it\'s usually cosmetic (see the note further down this tab) — verify by opening the Plugin detail view; if tools are listed, you\'re connected.', 'royal-mcp' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Set the Plugin\'s Permissions.', 'royal-mcp' ); ?></strong>
				<?php esc_html_e( 'In the Plugin detail view, click Permissions. Four levels: Always ask / Allow read actions / Allow low-risk actions (default) / Allow all actions. Pick what matches your workflow — default lets ChatGPT decide which actions are "low risk."', 'royal-mcp' ); ?>
			</li>
		</ol>

		<div class="royal-mcp-help-callout">
			<strong><?php esc_html_e( 'Tip:', 'royal-mcp' ); ?></strong>
			<?php
			printf(
				wp_kses(
					/* translators: %s: link to Activity Log */
					__( 'Open %s in another browser tab before you click Create. You\'ll see live entries fire (oauth:register → oauth:authorize → oauth:token). If anything fails, the log shows which step and why.', 'royal-mcp' ),
					[ 'a' => [ 'href' => [] ] ]
				),
				'<a href="' . esc_url( $royal_mcp_help_logs_url ) . '">' . esc_html__( 'Royal MCP → Activity Log', 'royal-mcp' ) . '</a>'
			);
			?>
		</div>
	</div>

	<!-- =====================================================
	     Step 3: Use in Conversations
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-client-section">
		<div class="royal-mcp-help-section-header">
			<img class="royal-mcp-help-section-icon" src="<?php echo esc_url( ROYAL_MCP_PLUGIN_URL . 'assets/img/clients/chatgpt.svg' ); ?>" alt="" width="32" height="32" />
			<h3><?php esc_html_e( 'Step 3 — Use it in conversations', 'royal-mcp' ); ?></h3>
		</div>

		<p><?php esc_html_e( 'Plugins are not auto-included in every chat — you toggle them on per-conversation.', 'royal-mcp' ); ?></p>

		<ol class="royal-mcp-help-steps">
			<li><?php esc_html_e( 'Start a new conversation.', 'royal-mcp' ); ?></li>
			<li><?php esc_html_e( 'Click the + button next to the message composer → More → pick your Royal MCP Plugin.', 'royal-mcp' ); ?></li>
			<li><?php esc_html_e( 'Ask something WordPress-specific to verify: "What\'s the title and tagline of my site?" — ChatGPT calls wp_get_site_info and returns your actual data.', 'royal-mcp' ); ?></li>
		</ol>

		<p class="description"><?php esc_html_e( 'Once added on chatgpt.com web, the Plugin is available on mobile and desktop apps signed into the same account with no re-configuration.', 'royal-mcp' ); ?></p>
	</div>

	<!-- =====================================================
	     Cosmetic banner note (memory-baked gotcha)
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-client-section">
		<div class="royal-mcp-help-section-header">
			<h3><?php esc_html_e( '"Action discovery failed" banner — usually cosmetic', 'royal-mcp' ); ?></h3>
		</div>

		<p><?php esc_html_e( "During setup, ChatGPT sometimes flashes an \"action discovery failed\" or \"unknown error\" banner near the end of the connect flow. In most cases, the Plugin still installs successfully and tool calls work anyway.", 'royal-mcp' ); ?></p>

		<p><strong><?php esc_html_e( 'Verify before treating as broken:', 'royal-mcp' ); ?></strong></p>
		<ol class="royal-mcp-help-steps">
			<li><?php esc_html_e( 'Is your Plugin listed in Settings → Plugins?', 'royal-mcp' ); ?></li>
			<li><?php esc_html_e( 'In a new conversation with the Plugin toggled on, does ChatGPT respond to a real tool-call prompt with real site data?', 'royal-mcp' ); ?></li>
		</ol>
		<p><?php esc_html_e( 'If both are YES → cosmetic, ignore the banner. If Plugin is listed but tool calls return "no tools/functions defined" — that\'s the real bug, check Activity Log for /mcp requests.', 'royal-mcp' ); ?></p>
	</div>

	<p class="royal-mcp-help-section-cta">
		<?php echo \Royal_MCP\Admin\Help_Page::full_guide_link( 'chatgpt', __( 'Full ChatGPT connection guide', 'royal-mcp' ) ); ?>
	</p>

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
