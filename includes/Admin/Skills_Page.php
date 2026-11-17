<?php
/**
 * "Skills" screen: the free skills from royalplugins.com/skills. A skill is a
 * file of instructions an AI client loads so it uses this site's tools the
 * safe way. Skills for plugins that are active here are listed first.
 *
 * A skill for a plugin that is active here can be switched on, and is then
 * given to connected AI clients. The list and the texts ship with the plugin;
 * the screen makes no request to any server.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skills_Page {

	const PAGE_SLUG = 'royal-mcp-skills';

	const HUB_URL = 'https://royalplugins.com/skills/';

	const SAVE_ACTION = 'royal_mcp_save_skills';

	public static function register() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ], 16 );
		add_action( 'admin_post_' . self::SAVE_ACTION, [ __CLASS__, 'handle_save' ] );
	}

	/** Store which skills connected AI clients get. Only skills shown with a switch are changed. */
	public static function handle_save() {
		if ( ! current_user_can( Settings_Page::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from admin_capability(); the choice is stored per site
			wp_die( esc_html__( 'Permission denied.', 'royal-mcp' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::SAVE_ACTION );
		$shown   = isset( $_POST['royal_mcp_skills_shown'] ) && is_array( $_POST['royal_mcp_skills_shown'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['royal_mcp_skills_shown'] ) ) : [];
		$on      = isset( $_POST['royal_mcp_skills_on'] ) && is_array( $_POST['royal_mcp_skills_on'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['royal_mcp_skills_on'] ) ) : [];
		$choices = [];
		foreach ( self::catalog() as $id => $skill ) {
			// Only a skill that has a switch on the screen: for a plugin that is active here.
			if ( $skill['active'] && in_array( $id, $shown, true ) ) {
				$choices[ $id ] = in_array( $id, $on, true );
			}
		}
		\Royal_MCP\MCP\Support\Skills::save( $choices );
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'saved' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function add_menu() {
		add_submenu_page(
			'royal-mcp',
			__( 'Skills', 'royal-mcp' ),
			__( 'Skills', 'royal-mcp' ),
			Settings_Page::admin_capability(),
			self::PAGE_SLUG,
			[ __CLASS__, 'render' ]
		);
	}

	/**
	 * The skills, each with the plugin it is for and whether that plugin is active here.
	 *
	 * @return array<string, array{name:string, plugin:string, does:string, page:string, file:string, active:bool}>
	 */
	public static function catalog() {
		$skills = [
			'elementor'   => [
				'name'   => __( 'Elementor skill', 'royal-mcp' ),
				'plugin' => 'Elementor',
				'does'   => __( 'Safe Elementor editing: clone a page before changing it, replace text and images across a page, add widgets, use templates.', 'royal-mcp' ),
				'page'   => self::HUB_URL . 'elementor-claude-skill/',
				'file'   => self::HUB_URL . 'elementor-claude-skill/elementor-mcp.md',
				'check'  => [ '\\Royal_MCP\\Integrations\\Elementor', 'is_available' ],
			],
			'divi'        => [
				'name'   => __( 'Divi skill', 'royal-mcp' ),
				'plugin' => 'Divi',
				'does'   => __( 'Divi 4 and Divi 5: clone pages, apply library layouts, replace text and images, with undo.', 'royal-mcp' ),
				'page'   => self::HUB_URL . 'divi-claude-skill/',
				'file'   => self::HUB_URL . 'divi-claude-skill/divi-mcp.md',
				'check'  => [ '\\Royal_MCP\\Integrations\\Divi', 'is_available' ],
			],
			'woocommerce' => [
				'name'   => __( 'WooCommerce skill', 'royal-mcp' ),
				'plugin' => 'WooCommerce',
				'does'   => __( 'Safe store work: create and update products, change prices and stock, move orders between statuses.', 'royal-mcp' ),
				'page'   => self::HUB_URL . 'woocommerce-claude-skill/',
				'file'   => self::HUB_URL . 'woocommerce-claude-skill/woocommerce-mcp.md',
				'check'  => [ '\\Royal_MCP\\Integrations\\WooCommerce', 'is_available' ],
			],
			'sitevault'   => [
				'name'   => __( 'SiteVault skill', 'royal-mcp' ),
				'plugin' => 'SiteVault',
				'does'   => __( 'Backups before risky work: start a backup and wait for it, check the schedule, check how fresh the last backup is.', 'royal-mcp' ),
				'page'   => self::HUB_URL . 'sitevault-claude-skill/',
				'file'   => self::HUB_URL . 'sitevault-claude-skill/sitevault-mcp.md',
				'check'  => [ '\\Royal_MCP\\Integrations\\SiteVault', 'is_available' ],
			],
		];
		/**
		 * Filters the skills listed on the Skills screen.
		 *
		 * @param array $skills Skill id => [ name, plugin, does, page, file, check (callable, optional) ].
		 */
		$filtered = apply_filters( 'royal_mcp_skills_catalog', $skills );
		$out      = [];
		foreach ( is_array( $filtered ) ? $filtered : $skills as $id => $skill ) {
			if ( ! is_array( $skill ) || empty( $skill['name'] ) || ! is_string( $skill['name'] ) || empty( $skill['page'] ) || ! is_string( $skill['page'] ) ) {
				continue;
			}
			$active = false;
			if ( isset( $skill['check'] ) && is_callable( $skill['check'] ) ) {
				try {
					$active = (bool) call_user_func( $skill['check'] );
				} catch ( \Throwable $e ) {
					$active = false;
				}
			}
			$out[ sanitize_key( (string) $id ) ] = [
				'name'   => (string) $skill['name'],
				'plugin' => isset( $skill['plugin'] ) && is_string( $skill['plugin'] ) ? $skill['plugin'] : '',
				'does'   => isset( $skill['does'] ) && is_string( $skill['does'] ) ? $skill['does'] : '',
				'page'   => $skill['page'],
				'file'   => isset( $skill['file'] ) && is_string( $skill['file'] ) ? $skill['file'] : '',
				'active' => $active,
			];
		}
		return $out;
	}

	private static function tagged( $url, $content ) {
		return add_query_arg( [ 'utm_source' => 'royal_mcp_free', 'utm_medium' => 'free_plugin', 'utm_content' => $content, 'utm_campaign' => 'royal_mcp_admin' ], $url );
	}

	public static function render() {
		if ( ! current_user_can( Settings_Page::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from admin_capability(); the screen only lists links
			wp_die( esc_html__( 'You do not have permission to view this page.', 'royal-mcp' ) );
		}
		$skills = self::catalog();
		$here   = array_filter( $skills, static function ( $skill ) {
			return $skill['active'];
		} );
		$other  = array_diff_key( $skills, $here );

		echo '<div class="wrap royal-mcp-skills">';
		echo '<h1>' . esc_html__( 'Skills', 'royal-mcp' ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Skills saved.', 'royal-mcp' ) . '</p></div>';
		}
		echo '<p style="max-width:800px;">' . esc_html__( 'A skill is a set of instructions for your AI client. Royal MCP gives the client the tools; a skill teaches it the safe way to use them, for example to clone a page before editing it. Skills are free.', 'royal-mcp' ) . '</p>';
		echo '<p style="max-width:800px;">' . esc_html__( 'A skill that is switched on here is given to every AI client connected to this site: the client is told about it when it connects and reads it before that kind of work. Nothing to download or upload. You can still open a skill and add its file to your AI client yourself, to have it there on every site.', 'royal-mcp' ) . '</p>';

		echo '<h2>' . esc_html__( 'Skills for this site', 'royal-mcp' ) . '</h2>';
		if ( ! $here ) {
			echo '<p><em>' . esc_html__( 'None of the plugins these skills are for is active on this site yet.', 'royal-mcp' ) . '</em></p>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '">';
			wp_nonce_field( self::SAVE_ACTION );
			self::cards( $here, true );
			if ( array_filter( array_keys( $here ), [ '\\Royal_MCP\\MCP\\Support\\Skills', 'is_bundled' ] ) ) {
				submit_button( __( 'Save skills', 'royal-mcp' ) );
			}
			echo '</form>';
		}
		if ( $other ) {
			echo '<h2 style="margin-top:26px;">' . esc_html__( 'Other skills', 'royal-mcp' ) . '</h2>';
			self::cards( $other );
		}
		echo '<p style="margin-top:22px;"><a target="_blank" rel="noopener noreferrer" href="' . esc_url( self::tagged( self::HUB_URL, 'skills_page_hub' ) ) . '">' . esc_html__( 'All skills on royalplugins.com', 'royal-mcp' ) . '</a> &middot; <a target="_blank" rel="noopener noreferrer" href="' . esc_url( self::tagged( self::HUB_URL . 'submit/', 'skills_page_submit' ) ) . '">' . esc_html__( 'Submit a skill of your own', 'royal-mcp' ) . '</a></p>';
		echo '</div>';
	}

	private static function cards( array $skills, $switches = false ) {
		echo '<div class="royal-mcp-skill-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;max-width:1100px;">';
		foreach ( $skills as $id => $skill ) {
			echo '<div class="royal-mcp-skill" style="background:#fff;border:1px solid ' . ( $skill['active'] ? '#c9a227' : '#dcdcde' ) . ';border-radius:8px;padding:16px 18px;display:flex;flex-direction:column;gap:6px;">';
			echo '<strong style="font-size:14px;">' . esc_html( $skill['name'] ) . '</strong>';
			if ( '' !== $skill['plugin'] ) {
				/* translators: %s: plugin name */
				echo '<span class="description">' . esc_html( $skill['active'] ? sprintf( __( '%s is active on this site', 'royal-mcp' ), $skill['plugin'] ) : sprintf( __( 'For sites that run %s', 'royal-mcp' ), $skill['plugin'] ) ) . '</span>';
			}
			echo '<p style="margin:0;flex:1;">' . esc_html( $skill['does'] ) . '</p>';
			if ( $switches && \Royal_MCP\MCP\Support\Skills::is_bundled( $id ) ) {
				echo '<input type="hidden" name="royal_mcp_skills_shown[]" value="' . esc_attr( $id ) . '">';
				echo '<label style="margin-top:4px;"><input type="checkbox" name="royal_mcp_skills_on[]" value="' . esc_attr( $id ) . '"' . checked( \Royal_MCP\MCP\Support\Skills::is_switched_on( $id ), true, false ) . '> ' . esc_html__( 'Give this skill to connected AI clients', 'royal-mcp' ) . '</label>';
			} elseif ( ! $switches && \Royal_MCP\MCP\Support\Skills::is_bundled( $id ) && '' !== $skill['plugin'] ) {
				/* translators: %s: plugin name */
				echo '<span class="description">' . esc_html( sprintf( __( 'Given to connected AI clients once %s is active.', 'royal-mcp' ), $skill['plugin'] ) ) . '</span>';
			}
			echo '<p style="margin:6px 0 0;display:flex;align-items:center;gap:10px;">';
			echo '<a class="button' . ( $skill['active'] ? ' button-primary' : '' ) . '" target="_blank" rel="noopener noreferrer" href="' . esc_url( self::tagged( $skill['page'], 'skills_page_' . $id ) ) . '">' . esc_html__( 'Open the skill', 'royal-mcp' ) . '</a>';
			if ( '' !== $skill['file'] ) {
				echo '<a target="_blank" rel="noopener noreferrer" href="' . esc_url( $skill['file'] ) . '">' . esc_html__( 'Skill file', 'royal-mcp' ) . '</a>';
			}
			echo '</p></div>';
		}
		echo '</div>';
	}
}
