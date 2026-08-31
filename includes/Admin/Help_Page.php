<?php
namespace Royal_MCP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Royal MCP → Help admin page.
 *
 * Tab nav driven by ?view=<slug>; templates under templates/admin/help/.
 * Menu slug: royal-mcp-help
 */
class Help_Page {

	public const SLUG        = 'royal-mcp-help';
	public const DEFAULT_TAB = 'overview';

	/**
	 * Single-source-of-truth URL map for the "Full guide →" links.
	 * Empty string = no external page yet, template inlines a snippet.
	 */
	public const SUPPORT_URLS = [
		'claude_desktop'        => 'https://royalplugins.com/support/royal-mcp/using-royal-mcp-with-claude-desktop.html',
		'claude_web'            => 'https://royalplugins.com/support/royal-mcp/connecting-to-claude.html',
		'claude_code_cli'       => '',
		'chatgpt'               => 'https://royalplugins.com/support/royal-mcp/connecting-to-chatgpt.html',
		'cursor'                => '',
		'vscode'                => '',
		'troubleshooting_start' => 'https://royalplugins.com/support/royal-mcp/troubleshooting-start-here.html',
		'oauth_managed_host'    => 'https://royalplugins.com/support/royal-mcp/oauth-fails-on-managed-host.html',
		'diagnose_curl'         => 'https://royalplugins.com/support/royal-mcp/diagnose-mcp-with-curl.html',
		'well_known_404'        => 'https://royalplugins.com/support/royal-mcp/siteground-well-known-404.html',
		'support_hub'           => 'https://royalplugins.com/support/royal-mcp/',
		'wporg_forum'           => 'https://wordpress.org/support/plugin/royal-mcp/',
	];

	/** 4-step diagnostic pattern; mirrored verbatim in readme.txt FAQ. */
	public const DIAGNOSTIC_STEPS = [
		[
			'title' => 'Update Royal MCP to the latest version',
			'body'  => 'Every recent release fixes meaningful OAuth edge cases. Plugins → Installed Plugins → check for updates.',
		],
		[
			'title' => 'Run a conflict test',
			'body'  => 'Deactivate all other plugins, switch to a default theme (Twenty Twenty-Five), and purge every cache layer (any cache plugin, your host\'s server-level cache, Cloudflare/CDN, browser cache).',
		],
		[
			'title' => 'Wipe stale OAuth state',
			'body'  => 'Royal MCP 1.4.17+: Royal MCP → Settings → Reset OAuth State button. This clears stale OAuth clients, issued tokens, and pending authorization codes without touching your API key or Activity Log.',
		],
		[
			'title' => 'Check the Activity Log',
			'body'  => 'Royal MCP → Activity Logs → most recent oauth: row records exactly which validation rule fired. Copy that line into any support request.',
		],
	];

	/**
	 * Tab definitions. Order here is the order rendered in the tab nav.
	 */
	public const TABS = [
		'overview'        => 'Getting Started',
		'claude'          => 'Claude',
		'chatgpt'         => 'ChatGPT',
		'other-clients'   => 'Other Clients',
		'troubleshooting' => 'Troubleshooting',
		'support'         => 'Support',
	];

	public function __construct() {
		// Priority 15 orders Help between core submenus (10) and Chrome cross-sell items (20+).
		add_action( 'admin_menu', [ $this, 'add_submenu' ], 15 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_submenu() {
		add_submenu_page(
			'royal-mcp',
			__( 'Royal MCP Help', 'royal-mcp' ),
			__( 'Help', 'royal-mcp' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function enqueue_assets( $hook_suffix ) {
		// Submenu hook suffix format: "<top-level-slug>_page_<submenu-slug>".
		// Parent slug varies when a wrapper plugin absorbs this submenu under
		// its own top-level menu, so match on the trailing "_page_<slug>" part.
		$expected_suffix = '_page_' . self::SLUG;
		if ( substr( (string) $hook_suffix, -strlen( $expected_suffix ) ) !== $expected_suffix ) {
			return;
		}
		$version = defined( 'ROYAL_MCP_VERSION' ) ? ROYAL_MCP_VERSION : '1.0.0';
		$base    = defined( 'ROYAL_MCP_PLUGIN_URL' ) ? ROYAL_MCP_PLUGIN_URL : plugins_url( '/', dirname( __DIR__, 2 ) . '/royal-mcp.php' );
		wp_enqueue_style(
			'royal-mcp-help',
			trailingslashit( $base ) . 'assets/css/help.css',
			[],
			$version
		);
	}

	/** Render the Help page — resolves current tab and delegates to _layout.php. */
	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$requested_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : self::DEFAULT_TAB;
		$view           = array_key_exists( $requested_view, self::TABS ) ? $requested_view : self::DEFAULT_TAB;

		// Vars exposed to templates.
		$royal_mcp_help_tabs         = self::TABS;
		$royal_mcp_help_current_view = $view;
		$royal_mcp_help_support_urls = self::SUPPORT_URLS;
		$royal_mcp_help_diagnostic   = self::DIAGNOSTIC_STEPS;
		$royal_mcp_help_endpoint     = rest_url( 'royal-mcp/v1/mcp' );

		$plugin_root = dirname( __DIR__, 2 );
		$layout      = $plugin_root . '/templates/admin/help/_layout.php';

		if ( ! file_exists( $layout ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Royal MCP Help', 'royal-mcp' ) . '</h1>';
			echo '<p>' . esc_html__( 'Help templates not found — reinstall the plugin.', 'royal-mcp' ) . '</p></div>';
			return;
		}

		include $layout;
	}

	/** Build a tab-switch URL for use in templates. */
	public static function tab_url( string $view ): string {
		return add_query_arg(
			[
				'page' => self::SLUG,
				'view' => $view,
			],
			admin_url( 'admin.php' )
		);
	}

	/** Render a "Full guide →" link; returns '' when URL key is empty. */
	public static function full_guide_link( string $key, string $label = 'Full guide' ): string {
		$url = self::SUPPORT_URLS[ $key ] ?? '';
		if ( $url === '' ) {
			return '';
		}
		return sprintf(
			'<a class="royal-mcp-help-guide-link" href="%s" target="_blank" rel="noopener noreferrer">%s <span aria-hidden="true">&rarr;</span></a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}
}
