<?php
/**
 * "Context" screen: the site owner's own context for AI clients, and what a
 * client is sent when it connects (how many tools, and which were left out).
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\Admin;

use Royal_MCP\MCP\Support\Site_Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Context_Page {

	const PAGE_SLUG    = 'royal-mcp-context';
	const NONCE_ACTION = 'royal_mcp_site_context';
	const SAVE_ACTION  = 'royal_mcp_save_site_context';

	public static function register() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ], 15 );
		add_action( 'admin_post_' . self::SAVE_ACTION, [ __CLASS__, 'handle_save' ] );
	}

	public static function add_menu() {
		add_submenu_page(
			'royal-mcp',
			__( 'Context', 'royal-mcp' ),
			__( 'Context', 'royal-mcp' ),
			Settings_Page::admin_capability(),
			self::PAGE_SLUG,
			[ __CLASS__, 'render' ]
		);
	}

	/** Headings the owner can drop into the box to get started. */
	private static function starters() {
		return [
			__( 'What this site is for', 'royal-mcp' )        => __( "What this site is for:\n", 'royal-mcp' ),
			__( 'Tone of voice', 'royal-mcp' )                => __( "Tone of voice:\n", 'royal-mcp' ),
			__( 'Never change', 'royal-mcp' )                 => __( "Never change:\n", 'royal-mcp' ),
			__( 'Always check first', 'royal-mcp' )           => __( "Always check first:\n", 'royal-mcp' ),
			__( 'How content is organised', 'royal-mcp' )     => __( "How content is organised:\n", 'royal-mcp' ),
		];
	}

	public static function render() {
		if ( ! current_user_can( Settings_Page::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from admin_capability(); the text is stored per site
			wp_die( esc_html__( 'You do not have permission to view this page.', 'royal-mcp' ) );
		}
		$text = Site_Context::get();
		$max  = Site_Context::MAX_CHARS;
		$used = (int) preg_match_all( '/./us', $text );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Context', 'royal-mcp' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Site context saved. AI clients receive it the next time they connect.', 'royal-mcp' ) . '</p></div>';
		}
		$masked = Site_Context::masked_parts();
		if ( $masked > 0 ) {
			/* translators: %s: number of parts */
			echo '<div class="notice notice-warning inline" style="margin:12px 0;max-width:800px;"><p>' . esc_html( sprintf( _n( '%s part of this text has the shape of a password or key. AI clients receive it as [REDACTED]. Take it out: this text is not a place for secrets.', '%s parts of this text have the shape of a password or key. AI clients receive them as [REDACTED]. Take them out: this text is not a place for secrets.', $masked, 'royal-mcp' ), number_format_i18n( $masked ) ) ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Site context for AI clients', 'royal-mcp' ) . '</h2>';
		echo '<p style="max-width:800px;">' . esc_html__( 'Write what an AI should know before it works on this site: what the site is for, the tone to write in, what it must never change, what to check first. Royal MCP gives this text to every AI client when it connects, so you do not have to explain your site in each conversation.', 'royal-mcp' ) . '</p>';
		echo '<p style="max-width:800px;" class="description">' . esc_html__( 'One text for the whole site. Every connection receives it, whatever its access level, so do not put passwords, keys or private details in it. Royal MCP hides the most recognisable key formats before sending, but it cannot recognise every password.', 'royal-mcp' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:800px;">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		echo '<p style="margin:8px 0;">';
		foreach ( self::starters() as $label => $insert ) {
			echo '<button type="button" class="button button-small royal-mcp-context-starter" data-insert="' . esc_attr( $insert ) . '" style="margin:0 6px 6px 0;">' . esc_html( $label ) . '</button>';
		}
		echo '</p>';
		echo '<textarea id="royal-mcp-site-context" name="royal_mcp_site_context" rows="14" data-max="' . esc_attr( (string) $max ) . '" class="large-text" style="font-family:inherit;">' . esc_textarea( $text ) . '</textarea>';
		/* translators: 1: characters used, 2: characters allowed */
		echo '<p class="description"><span id="royal-mcp-site-context-count">' . esc_html( sprintf( __( '%1$s of %2$s characters', 'royal-mcp' ), number_format_i18n( $used ), number_format_i18n( $max ) ) ) . '</span> ' . esc_html__( 'Text beyond the limit is cut when you save.', 'royal-mcp' ) . '</p>';
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save context', 'royal-mcp' ) . '</button></p>';
		echo '</form>';

		$script = '(function(){var b=document.getElementById("royal-mcp-site-context"),c=document.getElementById("royal-mcp-site-context-count");if(!b){return;}'
			. 'function n(){if(!c){return;}var l=Array.from(b.value).length;c.textContent=l.toLocaleString()+" / ' . (int) $max . '";c.style.color=l>' . (int) $max . '?"#b32d2e":"";}'
			. 'b.addEventListener("input",n);'
			. 'var s=document.querySelectorAll(".royal-mcp-context-starter");for(var i=0;i<s.length;i++){s[i].addEventListener("click",function(){var t=this.getAttribute("data-insert")||"";b.value=(b.value&&!/\\n$/.test(b.value)?b.value+"\\n\\n":b.value)+t;b.focus();n();});}'
			. '})();';
		wp_print_inline_script_tag( $script );

		self::render_sent_summary();
		echo '</div>';
	}

	/** What a client connecting as this administrator is sent. */
	private static function render_sent_summary() {
		echo '<h2 style="margin-top:28px;">' . esc_html__( 'What an AI client is sent', 'royal-mcp' ) . '</h2>';
		echo '<p style="max-width:800px;">' . esc_html__( 'When a client connects it receives your site context and the list of tools it can use. For a client that connects as an administrator, tools for plugins that are not active on this site are left out, so it is not sent tools that cannot work here. A connection that is not an administrator is sent the whole list, so that the list does not show which plugins the site runs.', 'royal-mcp' ) . '</p>';
		try {
			$sum = \Royal_MCP\MCP\Server::list_summary();
		} catch ( \Throwable $e ) {
			echo '<p><em>' . esc_html__( 'The tool list could not be worked out on this page load.', 'royal-mcp' ) . '</em></p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:600px;"><tbody>';
		echo '<tr><td>' . esc_html__( 'Tools sent to an administrator connection', 'royal-mcp' ) . '</td><td><strong>' . esc_html( number_format_i18n( $sum['listed'] ) ) . '</strong></td></tr>';
		/* translators: %s: number of tokens, in thousands */
		echo '<tr><td>' . esc_html__( 'Size of the tool list', 'royal-mcp' ) . '</td><td>' . esc_html( sprintf( __( 'about %sk tokens', 'royal-mcp' ), number_format_i18n( (int) round( $sum['tokens'] / 1000 ) ) ) ) . '</td></tr>';
		echo '<tr><td>' . esc_html__( 'Tools left out (plugin not active)', 'royal-mcp' ) . '</td><td>' . esc_html( number_format_i18n( $sum['left_out'] ) ) . '</td></tr>';
		echo '</tbody></table>';
		if ( $sum['left_out_by_plugin'] ) {
			echo '<details style="max-width:600px;margin-top:8px;"><summary style="cursor:pointer;">' . esc_html__( 'Left out, by plugin', 'royal-mcp' ) . '</summary><ul style="margin:8px 0 0 18px;list-style:disc;">';
			foreach ( $sum['left_out_by_plugin'] as $plugin => $count ) {
				/* translators: 1: plugin name, 2: number of tools */
				echo '<li>' . esc_html( sprintf( _n( '%1$s: %2$s tool', '%1$s: %2$s tools', $count, 'royal-mcp' ), $plugin, number_format_i18n( $count ) ) ) . '</li>';
			}
			echo '</ul></details>';
		}
		if ( ! \Royal_MCP\Abilities\Plugin_Abilities::handled_elsewhere() ) {
			echo '<p style="margin-top:14px;"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . Plugin_Abilities_Page::PAGE_SLUG ) ) . '">' . esc_html__( 'Plugin Abilities', 'royal-mcp' ) . '</a> <span class="description">' . esc_html__( 'Offer other plugins\' abilities to AI clients as tools.', 'royal-mcp' ) . '</span></p>';
		}
	}

	public static function handle_save() {
		if ( ! current_user_can( Settings_Page::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from admin_capability(); the text is stored per site
			wp_die( esc_html__( 'Permission denied.', 'royal-mcp' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_ACTION );
		// Plain text, kept as typed: Site_Context::clean() checks the encoding and removes control characters. Nothing in it is ever output as HTML.
		$posted = isset( $_POST['royal_mcp_site_context'] ) && is_string( $_POST['royal_mcp_site_context'] ) ? Site_Context::clean( wp_unslash( $_POST['royal_mcp_site_context'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned by Site_Context::clean()
		Site_Context::save( $posted );
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'saved' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
