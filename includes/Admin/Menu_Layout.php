<?php
/**
 * The plugin's admin menu as six sections (Dashboard, Connections, Context,
 * Safety, Activity, Skills). Screens that belong to a section are reached from a row
 * of tabs at the top of that section instead of from the side menu.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu_Layout {

	const PARENT = 'royal-mcp';

	const CONNECTIONS = 'royal-mcp-connections';

	const SAFETY = 'royal-mcp-safety';

	/** Addresses of screens that moved => where they are now. */
	const MOVED = [
		'royal-mcp-dashboard' => self::PARENT,
		'royal-mcp-tools'     => self::PARENT,
	];

	public static function register() {
		add_action( 'admin_menu', [ __CLASS__, 'arrange' ], 9999 );
		add_filter( 'submenu_file', [ __CLASS__, 'highlight' ], 10, 2 );
		add_action( 'all_admin_notices', [ __CLASS__, 'render_tabs' ], 1 );
	}

	/**
	 * The sections in menu order: slug of the screen the menu entry opens => label and tabs
	 * (screen slug => tab label). A section without tabs is a single screen.
	 */
	public static function sections() {
		return [
			self::PARENT                   => [ 'label' => __( 'Dashboard', 'royal-mcp' ), 'tabs' => [] ],
			self::CONNECTIONS              => [
				'label' => __( 'Connections', 'royal-mcp' ),
				'tabs'  => [
					self::CONNECTIONS                   => __( 'Connection settings', 'royal-mcp' ),
					Connected_Clients_Page::MENU_SLUG   => __( 'Connected Clients', 'royal-mcp' ),
					Pending_Clients_Page::MENU_SLUG     => __( 'Pending Clients', 'royal-mcp' ),
				],
			],
			Context_Page::PAGE_SLUG        => [
				'label' => __( 'Context', 'royal-mcp' ),
				'tabs'  => [
					Context_Page::PAGE_SLUG          => __( 'Site context', 'royal-mcp' ),
					Plugin_Abilities_Page::PAGE_SLUG => __( 'Plugin Abilities', 'royal-mcp' ),
				],
			],
			self::SAFETY                   => [ 'label' => __( 'Safety', 'royal-mcp' ), 'tabs' => [] ],
			Changes_Page::MENU_SLUG        => [
				'label' => __( 'Activity', 'royal-mcp' ),
				'tabs'  => [
					Changes_Page::MENU_SLUG      => __( 'Recent Changes', 'royal-mcp' ),
					'royal-mcp-logs'             => __( 'Activity Log', 'royal-mcp' ),
					Protocol_Insights::PAGE_SLUG => __( 'Protocol Insights', 'royal-mcp' ),
				],
			],
			Skills_Page::PAGE_SLUG         => [ 'label' => __( 'Skills', 'royal-mcp' ), 'tabs' => [] ],
		];
	}

	/** The section a screen belongs to, or '' for a screen that is not part of one. */
	public static function section_of( $slug ) {
		foreach ( self::sections() as $section => $def ) {
			if ( $slug === $section || isset( $def['tabs'][ $slug ] ) ) {
				return $section;
			}
		}
		return '';
	}

	/**
	 * Runs after every screen is registered: sends a moved address to its new place and puts the
	 * five sections first in the side menu under their names. Tab screens stay in the list here:
	 * WordPress decides who may open a screen from this list, so they are only taken out of it
	 * when the menu is printed (see highlight()).
	 */
	public static function arrange() {
		global $submenu;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads which screen was asked for, changes nothing
		$asked = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( isset( self::MOVED[ $asked ] ) && current_user_can( Settings_Page::admin_capability() ) ) {
			wp_safe_redirect( add_query_arg( [ 'page' => self::MOVED[ $asked ] ], admin_url( 'admin.php' ) ) );
			exit;
		}
		if ( empty( $submenu[ self::PARENT ] ) || ! is_array( $submenu[ self::PARENT ] ) ) {
			return;
		}
		$sections = self::sections();
		$first    = [];
		$rest     = [];
		foreach ( $submenu[ self::PARENT ] as $item ) {
			$slug = (string) ( $item[2] ?? '' );
			if ( isset( $sections[ $slug ] ) ) {
				$item[0]        = $sections[ $slug ]['label'];
				$first[ $slug ] = $item;
			} else {
				$rest[] = $item;
			}
		}
		$ordered = [];
		foreach ( array_keys( $sections ) as $section ) {
			if ( isset( $first[ $section ] ) ) {
				$ordered[] = $first[ $section ];
			}
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering this plugin's own submenu is what this method is for
		$submenu[ self::PARENT ] = array_merge( $ordered, $rest );
	}

	/** Screens reached from a section's tabs or from the Help link, not from the side menu. */
	public static function tab_screens() {
		$hidden = [ Help_Page::SLUG => true ];
		foreach ( self::sections() as $section => $def ) {
			foreach ( array_keys( $def['tabs'] ) as $tab ) {
				if ( $tab !== $section ) {
					$hidden[ $tab ] = true;
				}
			}
		}
		return $hidden;
	}

	/**
	 * Runs as the side menu is printed, after WordPress has decided the open screen may be
	 * shown: takes the tab screens out of the printed menu and keeps a section's entry marked
	 * while one of its tab screens is open.
	 */
	public static function highlight( $submenu_file, $parent_file ) {
		global $submenu;
		if ( ! empty( $submenu[ self::PARENT ] ) && is_array( $submenu[ self::PARENT ] ) ) {
			$hidden = self::tab_screens();
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- trimming this plugin's own printed submenu is what this method is for
			$submenu[ self::PARENT ] = array_values( array_filter( $submenu[ self::PARENT ], static function ( $item ) use ( $hidden ) {
				return ! isset( $hidden[ (string) ( $item[2] ?? '' ) ] );
			} ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads which screen is open, changes nothing
		$open    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$section = '' !== $open ? self::section_of( $open ) : '';
		if ( Help_Page::SLUG === $open ) {
			$section = self::PARENT;
		}
		return '' !== $section ? $section : $submenu_file;
	}

	/** The row of tabs at the top of a section that has more than one screen. */
	public static function render_tabs() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads which screen is open, changes nothing
		$open    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$section = '' !== $open ? self::section_of( $open ) : '';
		// Only on this plugin's own screens: the same page name under another menu is not one of them.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ours   = $screen && ( 'toplevel_page_' . self::PARENT === $screen->id || 0 === strpos( (string) $screen->id, self::PARENT . '_page_' ) );
		if ( ! $ours || ! current_user_can( Settings_Page::admin_capability() ) ) {
			return;
		}
		// The Dashboard and the Help screen have no tabs.
		if ( Help_Page::SLUG === $open || '' === $section || self::PARENT === $section ) {
			return;
		}
		$tabs = self::sections()[ $section ]['tabs'];
		if ( ! $tabs ) {
			return;
		}
		echo '<div class="royal-mcp-section-nav">';
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr( self::sections()[ $section ]['label'] ) . '">';
		foreach ( $tabs as $slug => $label ) {
			if ( ! self::registered( $slug ) ) {
				continue;
			}
			echo '<a class="nav-tab' . ( $slug === $open ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( [ 'page' => $slug ], admin_url( 'admin.php' ) ) ) . '"' . ( $slug === $open ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		echo '</div>';
	}

	/** Whether a screen of this plugin is registered on this site (some exist only with a setting on). */
	private static function registered( $slug ) {
		global $_registered_pages;
		return isset( $_registered_pages[ get_plugin_page_hookname( $slug, self::PARENT ) ] );
	}
}
