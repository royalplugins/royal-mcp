<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$royal_mcp_help_support_urls  = isset( $royal_mcp_help_support_urls ) ? $royal_mcp_help_support_urls : \Royal_MCP\Admin\Help_Page::SUPPORT_URLS;
$royal_mcp_help_settings_url  = admin_url( 'admin.php?page=royal-mcp' );
$royal_mcp_help_permalinks    = admin_url( 'options-permalink.php' );
$royal_mcp_unknown_client_url = $royal_mcp_help_support_urls['unknown_client_id'] ?? 'https://royalplugins.com/support/royal-mcp/';
?>
<div class="royal-mcp-help-tab-content royal-mcp-help-tab-common-errors">

	<div class="royal-mcp-help-tab-intro">
		<h2><?php esc_html_e( 'Common Errors', 'royal-mcp' ); ?></h2>
		<p><?php esc_html_e( 'The most frequent errors reported by AI clients when reconnecting, with the exact steps that resolve them. Each entry lists the symptom, what triggers it, and the fix.', 'royal-mcp' ); ?></p>
	</div>

	<div class="royal-mcp-help-section">
		<h3><?php esc_html_e( 'Unknown client_id', 'royal-mcp' ); ?></h3>

		<h4><?php esc_html_e( 'Symptom', 'royal-mcp' ); ?></h4>
		<p>
			<?php
			echo wp_kses_post( sprintf(
				/* translators: %s: literal error string in a <code> tag */
				__( 'The AI client shows %s (or a variation, e.g. "Unknown client_id: <hash>") when you try to reconnect a connector that used to work.', 'royal-mcp' ),
				'<code>' . esc_html__( 'Unknown client_id', 'royal-mcp' ) . '</code>'
			) );
			?>
		</p>

		<h4><?php esc_html_e( 'What triggers it', 'royal-mcp' ); ?></h4>
		<ul>
			<li><?php esc_html_e( 'The connector has been idle for 2 or more days, so its OAuth client record has expired.', 'royal-mcp' ); ?></li>
			<li><?php esc_html_e( 'Rapid Royal MCP updates or an OAuth-state reset invalidated the old client between reconnects.', 'royal-mcp' ); ?></li>
			<li><?php esc_html_e( 'You switched between Royal MCP Free and Royal MCP Pro, and the connector still references a client_id from the previous plugin.', 'royal-mcp' ); ?></li>
		</ul>

		<h4><?php esc_html_e( 'The fix', 'royal-mcp' ); ?></h4>
		<ol>
			<li>
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
			</li>
			<li>
				<?php
				printf(
					wp_kses(
						/* translators: %s: link to Permalinks admin page */
						__( 'Open %s and click Save Changes without editing anything. This forces WordPress to flush rewrite rules so the OAuth discovery endpoints route correctly.', 'royal-mcp' ),
						[ 'a' => [ 'href' => [] ] ]
					),
					'<a href="' . esc_url( $royal_mcp_help_permalinks ) . '">' . esc_html__( 'Settings → Permalinks', 'royal-mcp' ) . '</a>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'In the AI client (Claude / ChatGPT / other), delete the existing Royal MCP connector entirely.', 'royal-mcp' ); ?></li>
			<li><?php esc_html_e( 'Re-add the connector from scratch. Enter only the MCP endpoint URL — leave every optional field empty so the client requests a fresh OAuth registration.', 'royal-mcp' ); ?></li>
		</ol>

		<p>
			<a href="<?php echo esc_url( $royal_mcp_unknown_client_url ); ?>" target="_blank" rel="noopener noreferrer" class="button">
				<?php esc_html_e( 'Read the full guide', 'royal-mcp' ); ?>
			</a>
		</p>
	</div>

</div>
