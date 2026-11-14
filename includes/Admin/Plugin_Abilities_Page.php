<?php
/**
 * "Plugin Abilities" screen: turn other plugins' WordPress abilities on as
 * MCP tools, a whole plugin (ability namespace) or single abilities at a time.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\Admin;

use Royal_MCP\Abilities\Plugin_Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin_Abilities_Page {

	const PAGE_SLUG    = 'royal-mcp-abilities';
	const NONCE_ACTION = 'royal_mcp_plugin_abilities';
	const SAVE_ACTION  = 'royal_mcp_save_plugin_abilities';

	public static function register() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ], 20 );
		add_action( 'admin_post_' . self::SAVE_ACTION, [ __CLASS__, 'handle_save' ] );
	}

	public static function add_menu() {
		if ( Plugin_Abilities::handled_elsewhere() ) {
			return;
		}
		add_submenu_page(
			'royal-mcp',
			__( 'Plugin Abilities', 'royal-mcp' ),
			__( 'Plugin Abilities', 'royal-mcp' ),
			Settings_Page::admin_capability(),
			self::PAGE_SLUG,
			[ __CLASS__, 'render' ]
		);
	}

	public static function render() {
		if ( ! current_user_can( Settings_Page::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from admin_capability(); the choice is stored per site
			wp_die( esc_html__( 'You do not have permission to view this page.', 'royal-mcp' ) );
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Plugin Abilities', 'royal-mcp' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Plugin abilities saved.', 'royal-mcp' ) . '</p></div>';
		}

		echo '<p style="max-width:800px;">' . esc_html__( 'Other plugins can register WordPress abilities: actions an AI client can run, such as an SEO plugin\'s site audit. Turn a plugin on here and its abilities appear as tools on your Royal MCP endpoint, so clients that connect to Royal MCP (Claude, ChatGPT and others) can use them.', 'royal-mcp' ) . '</p>';
		echo '<p style="max-width:800px;">' . esc_html__( 'Turning a plugin on turns on its abilities that only read. Abilities that can change the site are each allowed on their own under Choose abilities. To offer only some read abilities, leave the plugin unticked and tick the ones you want.', 'royal-mcp' ) . '</p>';
		echo '<p style="max-width:800px;">' . esc_html__( 'When a plugin adds abilities in an update, they stay off and are marked New until you save this page again.', 'royal-mcp' ) . '</p>';
		echo '<div class="notice notice-warning inline" style="padding:10px 12px;margin:12px 0;max-width:800px;"><p style="margin:0;">' . esc_html__( 'These tools run the other plugin\'s own code and are available to administrators only. Royal MCP logs every call, hides passwords and API keys in the results, and applies read-only mode, drafts-only mode and each connection\'s access level, but it cannot undo what another plugin\'s ability changes. Abilities that do not say they only read are treated as changes.', 'royal-mcp' ) . '</p></div>';

		$available = Plugin_Abilities::available();
		$enabled   = Plugin_Abilities::enabled_namespaces();
		$writes    = Plugin_Abilities::enabled_writes();
		// By ability name: a renamed tool (two abilities that read alike) still counts.
		$live_names = array_flip( array_map( static function ( $a ) {
			return (string) $a->get_name();
		}, array_values( (array) Plugin_Abilities::bridged() ) ) );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		echo '<table class="wp-list-table widefat fixed striped" style="max-width:1000px;">';
		echo '<colgroup><col style="width:28%;"><col style="width:12%;"><col style="width:60%;"></colgroup>';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Plugin', 'royal-mcp' ) . '</th><th scope="col">' . esc_html__( 'Turned on', 'royal-mcp' ) . '</th><th scope="col">' . esc_html__( 'Abilities', 'royal-mcp' ) . '</th></tr></thead><tbody>';
		if ( ! $available ) {
			echo '<tr><td colspan="3"><em>' . esc_html__( 'No other plugin on this site registers WordPress abilities yet.', 'royal-mcp' ) . '</em></td></tr>';
		}
		foreach ( $available as $ns => $group ) {
			$id       = 'royal-mcp-ns-' . $ns;
			$all_on   = in_array( $ns, $enabled, true );
			$on_count = 0;
			foreach ( array_keys( $group['abilities'] ) as $name ) {
				if ( isset( $live_names[ $name ] ) ) {
					++$on_count;
				}
			}
			// Ticking the whole plugin covers its read abilities, so their single boxes are locked while it is ticked.
			$toggle = 'var b=this.closest(\'tr\').querySelectorAll(\'.royal-mcp-ability-single\');for(var i=0;i<b.length;i++){b[i].disabled=this.checked;}';
			echo '<tr>';
			echo '<td><label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="namespaces[]" value="' . esc_attr( $ns ) . '"' . checked( $all_on, true, false ) . ' onchange="' . esc_attr( $toggle ) . '" /> <strong>' . esc_html( $group['label'] ) . '</strong></label><br><code>' . esc_html( $ns ) . '/*</code><br><span class="description">' . esc_html__( 'Tick to turn on every ability that only reads.', 'royal-mcp' ) . '</span>' . self::own_tools_note( $ns, $group['label'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- own_tools_note() returns escaped markup.
			/* translators: 1: abilities turned on, 2: abilities the plugin registers */
			echo '<td>' . esc_html( sprintf( __( '%1$d of %2$d', 'royal-mcp' ), $on_count, count( $group['abilities'] ) ) ) . '</td>';
			echo '<td><details' . ( $on_count && ! $all_on ? ' open' : '' ) . '><summary style="cursor:pointer;">' . esc_html__( 'Choose abilities', 'royal-mcp' ) . '</summary><ul style="margin:8px 0 0;">';
			foreach ( $group['abilities'] as $name => $label ) {
				$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
				$reads   = $ability ? Plugin_Abilities::ability_reads( $ability ) : false;
				$is_new  = Plugin_Abilities::is_new_since_saved( $name );
				$box_id  = 'royal-mcp-ab-' . md5( $name );
				$text    = esc_html( '' !== $label ? $label : $name );
				$badge   = $is_new ? ' <span style="display:inline-block;padding:0 6px;border-radius:3px;background:#dba617;color:#1d2327;font-size:11px;font-weight:600;">' . esc_html__( 'New', 'royal-mcp' ) . '</span>' : '';
				if ( $reads ) {
					$box  = '<input type="checkbox" class="royal-mcp-ability-single" id="' . esc_attr( $box_id ) . '" name="abilities[]" value="' . esc_attr( $name ) . '"' . checked( isset( $live_names[ $name ] ), true, false ) . disabled( $all_on, true, false ) . ' />';
					$note = $is_new ? esc_html__( 'reads only; added after you last saved, off until you save', 'royal-mcp' ) : esc_html__( 'reads only', 'royal-mcp' );
				} else {
					$box  = '<input type="checkbox" class="royal-mcp-ability-write" id="' . esc_attr( $box_id ) . '" name="writes[]" value="' . esc_attr( $name ) . '"' . checked( in_array( $name, $writes, true ), true, false ) . ' />';
					$note = esc_html__( 'can change the site; allowed only when ticked here', 'royal-mcp' );
				}
				echo '<li style="margin:0 0 4px;"><label for="' . esc_attr( $box_id ) . '">' . $box . ' ' . $text . '</label>' . $badge . ' <code style="font-size:11px;">' . esc_html( $name ) . '</code> <span class="description">' . $note . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $box, $text, $badge and $note are built from escaped parts above.
			}
			echo '</ul></details></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( $available ) {
			echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'royal-mcp' ) . '</button></p>';
		}
		echo '</form>';
		echo '</div>';
	}

	/** A note on a plugin Royal MCP already has its own tools for: turning its abilities on lists a second set. */
	private static function own_tools_note( $ns, $label ) {
		$own = Plugin_Abilities::own_tools_for( $ns );
		if ( '' === $own ) {
			return '';
		}
		/* translators: 1: plugin name, 2: Royal MCP tool names such as wc_* */
		$text = sprintf( __( 'Royal MCP already has its own %1$s tools (%2$s), with undo and the read-only and drafts-only checks. Turning this on lists %1$s\'s own abilities next to them, so the AI sees two sets of tools for the same job. Most sites do not need it.', 'royal-mcp' ), $label, $own );
		return '<br><span class="description" style="display:inline-block;margin-top:4px;color:#8a5a00;">' . esc_html( $text ) . '</span>';
	}

	public static function handle_save() {
		if ( ! current_user_can( Settings_Page::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from admin_capability(); the choice is stored per site
			wp_die( esc_html__( 'Permission denied.', 'royal-mcp' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_ACTION );
		$posted         = isset( $_POST['namespaces'] ) && is_array( $_POST['namespaces'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['namespaces'] ) ) : [];
		$posted_singles = isset( $_POST['abilities'] ) && is_array( $_POST['abilities'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['abilities'] ) ) : [];
		$posted_writes  = isset( $_POST['writes'] ) && is_array( $_POST['writes'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['writes'] ) ) : [];
		// Only namespaces and abilities that are registered on the site right now are kept.
		Plugin_Abilities::save_selection( $posted, $posted_singles, $posted_writes );
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'saved' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
