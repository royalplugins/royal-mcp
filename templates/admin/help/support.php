<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$royal_mcp_help_endpoint = isset( $royal_mcp_help_endpoint ) ? $royal_mcp_help_endpoint : rest_url( 'royal-mcp/v1/mcp' );
$royal_mcp_help_endpoint = preg_replace( '/^http:/', 'https:', $royal_mcp_help_endpoint );
$royal_mcp_help_urls     = isset( $royal_mcp_help_support_urls ) ? $royal_mcp_help_support_urls : \Royal_MCP\Admin\Help_Page::SUPPORT_URLS;

// Diagnostic info — surfaced for copy-paste into support requests.
$royal_mcp_plugin_version = defined( 'ROYAL_MCP_VERSION' ) ? ROYAL_MCP_VERSION : 'unknown';
$royal_mcp_wp_version     = get_bloginfo( 'version' );
$royal_mcp_php_version    = phpversion();
$royal_mcp_theme          = wp_get_theme();
$royal_mcp_theme_line     = trim( $royal_mcp_theme->get( 'Name' ) . ' ' . $royal_mcp_theme->get( 'Version' ) );
$royal_mcp_site_url       = home_url( '/' );

$royal_mcp_diag_lines = [
	'Royal MCP:   ' . $royal_mcp_plugin_version,
	'WordPress:   ' . $royal_mcp_wp_version,
	'PHP:         ' . $royal_mcp_php_version,
	'Theme:       ' . $royal_mcp_theme_line,
	'Site URL:    ' . $royal_mcp_site_url,
	'MCP Endpoint: ' . $royal_mcp_help_endpoint,
];
$royal_mcp_diag_text = implode( "\n", $royal_mcp_diag_lines );

// WP built-in plugin-details modal URL for changelog (opens inside admin,
// no round-trip to wp.org).
$royal_mcp_changelog_url = admin_url( 'plugin-install.php?tab=plugin-information&plugin=royal-mcp&TB_iframe=true&width=772&height=577&section=changelog' );
?>
<div class="royal-mcp-help-tab-content royal-mcp-help-tab-support">

	<div class="royal-mcp-help-tab-intro">
		<h2><?php esc_html_e( 'Support', 'royal-mcp' ); ?></h2>
		<p><?php esc_html_e( 'Two places to reach us + a diagnostic block that speeds up whichever channel you use.', 'royal-mcp' ); ?></p>
	</div>

	<!-- =====================================================
	     Card 1: Full documentation hub
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-support-card">
		<h3><?php esc_html_e( 'Full documentation hub', 'royal-mcp' ); ?></h3>
		<p><?php esc_html_e( 'Every support article, per-issue troubleshooting walkthrough, and host-specific fix lives on the marketing site — indexed and searchable.', 'royal-mcp' ); ?></p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $royal_mcp_help_urls['support_hub'] ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Visit royalplugins.com support hub', 'royal-mcp' ); ?> <span aria-hidden="true">&rarr;</span>
			</a>
		</p>
	</div>

	<!-- =====================================================
	     Card 2: WordPress.org forum
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-support-card">
		<h3><?php esc_html_e( 'WordPress.org community forum', 'royal-mcp' ); ?></h3>
		<p><?php esc_html_e( 'Ask publicly on the plugin\'s wp.org support forum. Common issues get faster answers here because the thread stays searchable for the next person who hits the same problem.', 'royal-mcp' ); ?></p>
		<p>
			<a class="button" href="<?php echo esc_url( $royal_mcp_help_urls['wporg_forum'] ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Ask on the wp.org forum', 'royal-mcp' ); ?> <span aria-hidden="true">&rarr;</span>
			</a>
		</p>
	</div>

	<!-- =====================================================
	     Card 3: Newsletter signup
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-support-card">
		<h3><?php esc_html_e( 'Royal Plugins newsletter', 'royal-mcp' ); ?></h3>
		<p><?php esc_html_e( 'Get release notes, MCP tips, and heads-up on upcoming Royal Plugins releases. Low volume, unsubscribe anytime.', 'royal-mcp' ); ?></p>
		<p>
			<a class="button" href="https://royalplugins.com/newsletter" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Subscribe to the newsletter', 'royal-mcp' ); ?> <span aria-hidden="true">&rarr;</span>
			</a>
		</p>
	</div>

	<!-- =====================================================
	     Card 4: Copy diagnostic info
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-support-card">
		<h3><?php esc_html_e( 'Diagnostic info', 'royal-mcp' ); ?></h3>
		<p><?php esc_html_e( 'Include this block when you post to the forum or open any support request. Cuts back-and-forth about your environment.', 'royal-mcp' ); ?></p>

		<pre class="royal-mcp-help-code" id="royal-mcp-help-diag-text"><?php echo esc_html( $royal_mcp_diag_text ); ?></pre>

		<p>
			<button type="button" class="button" id="royal-mcp-help-diag-copy">
				<?php esc_html_e( 'Copy diagnostic info', 'royal-mcp' ); ?>
			</button>
			<span class="description" id="royal-mcp-help-diag-status" aria-live="polite" style="margin-left: 8px;"></span>
		</p>

		<script>
		(function () {
			var btn    = document.getElementById('royal-mcp-help-diag-copy');
			var src    = document.getElementById('royal-mcp-help-diag-text');
			var status = document.getElementById('royal-mcp-help-diag-status');
			if (!btn || !src || !status) return;

			btn.addEventListener('click', function () {
				var text = src.innerText || src.textContent || '';
				var done = function (ok) {
					status.textContent = ok
						? <?php echo wp_json_encode( __( 'Copied to clipboard.', 'royal-mcp' ) ); ?>
						: <?php echo wp_json_encode( __( 'Copy failed — select the text manually.', 'royal-mcp' ) ); ?>;
					window.setTimeout(function () { status.textContent = ''; }, 3000);
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
				} else {
					// Legacy fallback for older admin browsers.
					var ta = document.createElement('textarea');
					ta.value = text;
					ta.setAttribute('readonly', '');
					ta.style.position = 'absolute';
					ta.style.left = '-9999px';
					document.body.appendChild(ta);
					ta.select();
					var ok = false;
					try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
					document.body.removeChild(ta);
					done(ok);
				}
			});
		})();
		</script>
	</div>

	<!-- =====================================================
	     Card 5: Recent changes
	     ===================================================== -->
	<div class="royal-mcp-help-section royal-mcp-help-support-card">
		<h3><?php esc_html_e( 'Recent changes', 'royal-mcp' ); ?></h3>
		<p>
			<?php
			printf(
				/* translators: %s: version number */
				esc_html__( 'You are running Royal MCP %s.', 'royal-mcp' ),
				'<strong>' . esc_html( $royal_mcp_plugin_version ) . '</strong>'
			);
			?>
		</p>
		<p><?php esc_html_e( 'The plugin\'s changelog covers every user-facing change per version — worth a scroll after updates to spot new features or behavior changes that affect you.', 'royal-mcp' ); ?></p>
		<p>
			<a class="button thickbox" href="<?php echo esc_url( $royal_mcp_changelog_url ); ?>">
				<?php esc_html_e( 'View plugin changelog', 'royal-mcp' ); ?>
			</a>
		</p>
	</div>

</div>
<?php
// Load the WP thickbox script for the changelog "View plugin" modal.
add_thickbox();
?>
