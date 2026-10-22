<?php
namespace Royal_MCP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells site admins when a connection still sends the API key in the
 * deprecated X-Royal-MCP-API-Key header, so they can move it to
 * Authorization: Bearer. Shows on Royal MCP screens only, and stops showing
 * 14 days after the header was last used.
 */
class Legacy_Key_Header_Notice {

	const SUPPORT_URL = 'https://royalplugins.com/support/royal-mcp/api-keys/';

	public function __construct() {
		add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
	}

	public function maybe_render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$seen = (int) get_transient( \Royal_MCP\MCP\Server::LEGACY_KEY_HEADER_SEEN );
		if ( $seen <= 0 ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, [ 'toplevel_page_royal-mcp', 'royal-mcp_page_royal-mcp-logs' ], true ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Royal MCP: a connection still sends your API key in the X-Royal-MCP-API-Key header.', 'royal-mcp' ); ?></strong>
				<?php
				printf(
					/* translators: %s: how long ago the header was last used, e.g. "3 hours". */
					esc_html__( 'Last seen %s ago. That header is deprecated: update the connection to send the key as Authorization: Bearer <your API key> instead.', 'royal-mcp' ),
					esc_html( human_time_diff( $seen, time() ) )
				);
				?>
				<a href="<?php echo esc_url( self::SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'How to update a connection', 'royal-mcp' ); ?></a>
			</p>
		</div>
		<?php
	}
}
