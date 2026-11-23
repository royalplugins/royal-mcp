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
		'unknown_client_id'     => 'https://royalplugins.com/support/royal-mcp/unknown-client-id-error.html',
		'pkce_stripped'         => 'https://royalplugins.com/support/royal-mcp/pkce-query-params-stripped-by-host/',
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
			'body'  => 'Royal MCP → Connections → Connection settings → Reset OAuth State button. This clears stale OAuth clients, issued tokens, and pending authorization codes without touching your API key or Activity Log.',
		],
		[
			'title' => 'Check the Activity Log',
			'body'  => 'Royal MCP → Activity → Activity Log: the most recent oauth: row records exactly which validation rule fired. Copy that line into any support request.',
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

	/** The tab labels, translated. */
	public static function tab_labels() {
		return [
			'overview'        => __( 'Getting Started', 'royal-mcp' ),
			'claude'          => __( 'Claude', 'royal-mcp' ),
			'chatgpt'         => __( 'ChatGPT', 'royal-mcp' ),
			'other-clients'   => __( 'Other Clients', 'royal-mcp' ),
			'troubleshooting' => __( 'Troubleshooting', 'royal-mcp' ),
			'support'         => __( 'Support', 'royal-mcp' ),
		] + self::TABS;
	}

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
			Settings_Page::admin_capability(),
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
		$royal_mcp_help_tabs         = self::tab_labels();
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

	/** What the main settings on each screen are, one line per screen slug. */
	private static function screen_notes(): array {
		return [
			Menu_Layout::PARENT                 => __( 'Connect an AI client, Connection Doctor, what needs your attention, recent changes, Royal plugins on this site.', 'royal-mcp' ),
			Menu_Layout::CONNECTIONS            => __( 'Switch Royal MCP on, MCP server URL, client approval, OAuth client, API key, session length, Reset OAuth State.', 'royal-mcp' ),
			Connected_Clients_Page::MENU_SLUG   => __( 'Signed-in AI apps and Application Passwords: access level per client, revoke one or all.', 'royal-mcp' ),
			Pending_Clients_Page::MENU_SLUG     => __( 'New AI clients waiting for your approval.', 'royal-mcp' ),
			Context_Page::PAGE_SLUG             => __( 'What AI clients should know about this site, given to every client when it connects.', 'royal-mcp' ),
			Plugin_Abilities_Page::PAGE_SLUG    => __( 'Let AI clients use the abilities other plugins register.', 'royal-mcp' ),
			Menu_Layout::SAFETY                 => __( 'What AI clients may do (read-only, drafts only, tool groups, access levels, who can connect), Extra write permissions (options, theme, protected meta), Advanced (WebMCP, Abilities API, PHP error recording, data on uninstall).', 'royal-mcp' ),
			Changes_Page::MENU_SLUG             => __( 'What AI clients changed, with undo.', 'royal-mcp' ),
			'royal-mcp-logs'                    => __( 'Every tool call and sign-in event: filter, export, clear, how long entries are kept.', 'royal-mcp' ),
			Protocol_Insights::PAGE_SLUG        => __( 'Weekly counts of MCP methods and clients.', 'royal-mcp' ),
			Skills_Page::PAGE_SLUG              => __( 'Switch on playbooks for Elementor, Divi, WooCommerce and SiteVault.', 'royal-mcp' ),
			self::SLUG                          => __( 'Setup guides for each AI app, troubleshooting and support.', 'royal-mcp' ),
		];
	}

	/**
	 * Screens shown in the "Where things are" map, in menu order: each side menu section with the
	 * tabs inside it. Built from Menu_Layout::sections() so the map follows the menu.
	 * Screens not registered on this site are left out.
	 *
	 * @return array<int,array{label:string,screens:array<int,array{slug:string,label:string,note:string,path:string,url:string}>}>
	 */
	public static function where_things_are(): array {
		$notes = self::screen_notes();
		$map   = [];
		$add   = static function ( $section_label, $slug, $tab_label ) use ( $notes ) {
			$path = [ __( 'Royal MCP', 'royal-mcp' ), $section_label ];
			if ( '' !== $tab_label ) {
				$path[] = $tab_label;
			}
			return [
				'slug'  => (string) $slug,
				'label' => '' !== $tab_label ? $tab_label : $section_label,
				'note'  => $notes[ $slug ] ?? '',
				'path'  => implode( ' → ', $path ),
				'url'   => add_query_arg( [ 'page' => $slug ], admin_url( 'admin.php' ) ),
			];
		};
		foreach ( Menu_Layout::sections() as $section => $def ) {
			$screens = [];
			$tabs    = $def['tabs'] ? $def['tabs'] : [ $section => '' ];
			foreach ( $tabs as $slug => $tab_label ) {
				if ( Menu_Layout::registered( $slug ) ) {
					$screens[] = $add( $def['label'], $slug, $tab_label );
				}
			}
			if ( $screens ) {
				$map[] = [ 'label' => $def['label'], 'screens' => $screens ];
			}
		}
		return $map;
	}

	/**
	 * Names people may still look for => the screen it is on now and, for a part of a screen,
	 * the name of that part.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function old_names(): array {
		return [
			__( 'Settings', 'royal-mcp' )          => [ Menu_Layout::CONNECTIONS, '' ],
			__( 'Tools', 'royal-mcp' )             => [ Menu_Layout::SAFETY, __( 'What AI clients may do', 'royal-mcp' ) ],
			__( 'Royal Tools', 'royal-mcp' )       => [ Menu_Layout::PARENT, __( 'Royal plugins on this site', 'royal-mcp' ) ],
			__( 'Plugin Abilities', 'royal-mcp' )  => [ Plugin_Abilities_Page::PAGE_SLUG, '' ],
			__( 'Activity Log', 'royal-mcp' )      => [ 'royal-mcp-logs', '' ],
			__( 'Connected Clients', 'royal-mcp' ) => [ Connected_Clients_Page::MENU_SLUG, '' ],
			__( 'Pending Clients', 'royal-mcp' )   => [ Pending_Clients_Page::MENU_SLUG, '' ],
			__( 'Protocol Insights', 'royal-mcp' ) => [ Protocol_Insights::PAGE_SLUG, '' ],
		];
	}

	/** The "Where things are" map at the top of the Help page. */
	public static function render_map(): void {
		$map = self::where_things_are();
		if ( ! $map ) {
			return;
		}
		$by_slug = [];
		foreach ( $map as $section ) {
			foreach ( $section['screens'] as $screen ) {
				$by_slug[ $screen['slug'] ] = $screen;
			}
		}
		echo '<div class="royal-mcp-help-section royal-mcp-help-map" id="royal-mcp-where-things-are">';
		echo '<h3 id="royal-mcp-map-title">' . esc_html__( 'Where things are', 'royal-mcp' ) . '</h3>';
		echo '<p>' . esc_html__( 'The side menu shows one entry per section. Screens inside a section open from the row of tabs at the top of it.', 'royal-mcp' ) . '</p>';
		echo '<table class="widefat royal-mcp-help-map-table" aria-labelledby="royal-mcp-map-title">';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Menu', 'royal-mcp' ) . '</th><th scope="col">' . esc_html__( 'Tab', 'royal-mcp' ) . '</th><th scope="col">' . esc_html__( 'What you set there', 'royal-mcp' ) . '</th></tr></thead>';
		foreach ( $map as $section ) {
			echo '<tbody>';
			$first = true;
			foreach ( $section['screens'] as $screen ) {
				echo '<tr>';
				if ( $first ) {
					echo '<th scope="rowgroup" rowspan="' . (int) count( $section['screens'] ) . '">' . esc_html( $section['label'] ) . '</th>';
					$first = false;
				}
				echo '<td class="royal-mcp-help-map-tab"><a href="' . esc_url( $screen['url'] ) . '">' . esc_html( $screen['label'] ) . '</a></td>';
				echo '<td>' . esc_html( $screen['note'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody>';
		}
		echo '</table>';

		$old = [];
		foreach ( self::old_names() as $name => $where ) {
			if ( isset( $by_slug[ $where[0] ] ) ) {
				$old[ $name ] = $where;
			}
		}
		if ( $old ) {
			echo '<h4>' . esc_html__( 'Looking for a screen by its old name?', 'royal-mcp' ) . '</h4>';
			echo '<dl class="royal-mcp-help-map-old">';
			foreach ( $old as $name => $where ) {
				$screen = $by_slug[ $where[0] ];
				$path   = '' !== $where[1] ? $screen['path'] . ' → ' . $where[1] : $screen['path'];
				echo '<dt>' . esc_html( $name ) . '</dt>';
				echo '<dd><a href="' . esc_url( $screen['url'] ) . '">' . esc_html( $path ) . '</a></dd>';
			}
			echo '</dl>';
		}
		echo '</div>';
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
