<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Vars provided by Help_Page::render():
//   $royal_mcp_help_tabs         array<string,string>  slug => label
//   $royal_mcp_help_current_view string                active tab slug
//   $royal_mcp_help_support_urls array<string,string>  key => url
//   $royal_mcp_help_diagnostic   array<int,array>      diagnostic step list
//   $royal_mcp_help_endpoint     string                rest_url('royal-mcp/v1/mcp')

$royal_mcp_help_tab_template = dirname( __FILE__ ) . '/' . $royal_mcp_help_current_view . '.php';
?>
<div class="wrap royal-mcp-help">

	<h1 class="royal-mcp-help-title">
		<?php esc_html_e( 'Royal MCP Help', 'royal-mcp' ); ?>
	</h1>

	<nav class="royal-mcp-help-tabs nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Help sections', 'royal-mcp' ); ?>">
		<?php foreach ( $royal_mcp_help_tabs as $royal_mcp_tab_slug => $royal_mcp_tab_label ) :
			$royal_mcp_tab_is_active = ( $royal_mcp_tab_slug === $royal_mcp_help_current_view );
			$royal_mcp_tab_class     = 'nav-tab' . ( $royal_mcp_tab_is_active ? ' nav-tab-active' : '' );
			?>
			<a class="<?php echo esc_attr( $royal_mcp_tab_class ); ?>"
			   href="<?php echo esc_url( \Royal_MCP\Admin\Help_Page::tab_url( $royal_mcp_tab_slug ) ); ?>"
			   <?php echo $royal_mcp_tab_is_active ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $royal_mcp_tab_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="royal-mcp-help-body">
		<?php
		if ( file_exists( $royal_mcp_help_tab_template ) ) {
			include $royal_mcp_help_tab_template;
		} else {
			echo '<div class="notice notice-error inline"><p>';
			printf(
				/* translators: %s: missing template filename. */
				esc_html__( 'Help template missing: %s', 'royal-mcp' ),
				esc_html( basename( $royal_mcp_help_tab_template ) )
			);
			echo '</p></div>';
		}
		?>
	</div>

</div>
