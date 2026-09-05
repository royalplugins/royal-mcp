<?php
/**
 * Royal MCP — What's New modal.
 *
 * A per-user version-tracked modal that surfaces release news, roadmap
 * teasers, and the Pro upsell without competing with WP's admin_notices
 * stack. Auto-opens once per user per plugin version on any Royal MCP
 * admin page; a chrome-header trigger button re-opens on demand.
 *
 * Dismissal state: user_meta royal_mcp_whats_new_seen_version stores the
 * plugin version the user last dismissed. Bumping ROYAL_MCP_VERSION and
 * shipping updated slide content auto-triggers the modal for every user
 * on their next Royal MCP page visit.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\Chrome;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Whats_New {

    const SEEN_VERSION_META  = 'royal_mcp_whats_new_seen_version';
    const DISMISS_AJAX_ACTION = 'royal_mcp_dismiss_whats_new';
    const DISMISS_NONCE      = 'royal_mcp_whats_new_nonce';
    const CSS_HANDLE         = 'royal-mcp-whats-new';
    const JS_HANDLE          = 'royal-mcp-whats-new';

    private static ?Whats_New $instance = null;

    public static function instance(): Whats_New {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_footer',          [ $this, 'render_modal' ] );
        add_action( 'wp_ajax_' . self::DISMISS_AJAX_ACTION, [ $this, 'handle_dismiss' ] );
    }

    /**
     * Reuse the chrome class's page-detection helper so the modal fires on
     * exactly the same page set as the chrome header.
     */
    private function is_royal_mcp_admin_page(): bool {
        if ( ! class_exists( '\Royal_MCP\Chrome\Royal_MCP_Chrome' ) ) {
            return false;
        }
        return \Royal_MCP\Chrome\Royal_MCP_Chrome::get_instance()->is_royal_mcp_admin_page();
    }

    /**
     * True when the current user hasn't yet dismissed the modal at the
     * current plugin version. Used to decide auto-open on page load.
     */
    public function should_auto_open(): bool {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        $seen = get_user_meta( get_current_user_id(), self::SEEN_VERSION_META, true );
        if ( empty( $seen ) ) {
            return true;
        }
        return version_compare( (string) $seen, ROYAL_MCP_VERSION, '<' );
    }

    public function enqueue_assets(): void {
        if ( ! $this->is_royal_mcp_admin_page() ) {
            return;
        }
        $base_url = ROYAL_MCP_PLUGIN_URL;
        $base_dir = ROYAL_MCP_PLUGIN_DIR;
        $version  = defined( 'ROYAL_MCP_VERSION' ) ? ROYAL_MCP_VERSION : '1.0.0';

        $css_rel = 'assets/css/whats-new.css';
        if ( file_exists( $base_dir . $css_rel ) ) {
            wp_enqueue_style(
                self::CSS_HANDLE,
                $base_url . $css_rel,
                [],
                $version . '.' . filemtime( $base_dir . $css_rel )
            );
        }

        $js_rel = 'assets/js/whats-new.js';
        if ( file_exists( $base_dir . $js_rel ) ) {
            wp_enqueue_script(
                self::JS_HANDLE,
                $base_url . $js_rel,
                [],
                $version . '.' . filemtime( $base_dir . $js_rel ),
                true
            );
            wp_localize_script(
                self::JS_HANDLE,
                'RoyalMcpWhatsNew',
                [
                    'autoOpen' => $this->should_auto_open(),
                    'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
                    'action'   => self::DISMISS_AJAX_ACTION,
                    'nonce'    => wp_create_nonce( self::DISMISS_NONCE ),
                ]
            );
        }

        // Cormorant Garamond for the "R" mark on Slide 4 (rendered inline
        // via CSS — matches the youtube-watermark HTML generator).
        wp_enqueue_style(
            self::CSS_HANDLE . '-cormorant',
            'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@700&display=swap',
            [],
            null
        );
    }

    /**
     * Render the "What's New?" trigger button. Called from the chrome
     * header render (see class-royal-mcp-chrome.php) so the button sits
     * alongside Docs / Support / Newsletter.
     */
    public function render_trigger_button(): void {
        if ( ! $this->is_royal_mcp_admin_page() ) {
            return;
        }
        ?>
        <button type="button" class="royal-mcp-chrome-header-btn royal-mcp-wn-trigger" data-royal-mcp-wn-trigger>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <?php esc_html_e( "What's New?", 'royal-mcp' ); ?>
        </button>
        <?php
    }

    /**
     * Render the modal HTML on admin_footer (Royal MCP pages only). JS
     * handles show/hide based on the RoyalMcpWhatsNew.autoOpen flag and
     * trigger-button clicks.
     */
    public function render_modal(): void {
        if ( ! $this->is_royal_mcp_admin_page() ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $template = ROYAL_MCP_PLUGIN_DIR . 'templates/admin/whats-new.php';
        if ( ! file_exists( $template ) ) {
            return;
        }
        include $template;
    }

    /**
     * AJAX handler for modal dismissal. Stamps the current plugin version
     * onto user_meta so the modal doesn't auto-open again until the next
     * plugin version ships.
     */
    public function handle_dismiss(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }
        check_ajax_referer( self::DISMISS_NONCE, 'nonce' );
        update_user_meta( get_current_user_id(), self::SEEN_VERSION_META, ROYAL_MCP_VERSION );
        wp_send_json_success( [ 'seen_version' => ROYAL_MCP_VERSION ] );
    }
}
