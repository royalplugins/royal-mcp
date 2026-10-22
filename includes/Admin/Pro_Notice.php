<?php
namespace Royal_MCP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Dismissible Royal MCP Pro notice shown at the top of every wp-admin page
 * to administrators of the free plugin.
 *
 * Dismissal is per user and stamped with the plugin version, so the notice
 * stays away until the next plugin update. Not shown while Royal MCP Pro is
 * active, nor before Pro's launch date.
 */
class Pro_Notice {

    const DISMISSED_META = 'royal_mcp_pro_launch_notice_dismissed';
    const DISMISS_ACTION = 'royal_mcp_dismiss_pro_notice';
    /** Last day of the launch offer, site time. */
    const OFFER_ENDS     = '2026-10-31';

    public function __construct() {
        add_action( 'admin_post_' . self::DISMISS_ACTION, [ $this, 'handle_dismiss' ] );
        add_action( 'admin_notices', [ $this, 'render' ] );
    }

    /**
     * Whether the notice applies to the current user on this request.
     */
    public static function should_show() {
        if ( class_exists( '\\Royal_MCP_Pro\\Tool_Registry', false ) ) {
            return false;
        }
        if ( ! current_user_can( 'manage_options' ) ) { // audit:multisite-manage-options-safe -- read-only per-user meta check (no state mutation)
            return false;
        }
        if ( current_datetime()->format( 'Y-m-d' ) < \Royal_MCP\Chrome\Royal_MCP_Chrome::PRO_LAUNCH_DATE ) {
            return false;
        }
        $seen = get_user_meta( get_current_user_id(), self::DISMISSED_META, true );
        return empty( $seen ) || version_compare( (string) $seen, ROYAL_MCP_VERSION, '<' );
    }

    public function render() {
        if ( ! self::should_show() ) {
            return;
        }
        $pro_url = add_query_arg(
            [
                'utm_source'   => 'admin_notice',
                'utm_medium'   => 'free_plugin',
                'utm_content'  => 'post_launch',
                'utm_campaign' => str_replace( '.', '', ROYAL_MCP_VERSION ) . '_postlaunch_notice',
            ],
            \Royal_MCP\Chrome\Royal_MCP_Chrome::PRO_POST_LAUNCH_URL_BASE
        );
        $dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION );
        ?>
        <div class="notice notice-info royal-mcp-pro-notice" style="padding: 14px 18px;">
            <p style="margin: 0 0 8px; font-size: 14px;">
                <strong><?php esc_html_e( 'Run AI across real client work with Royal MCP Pro: 350+ tools, bulk edits and a full audit trail.', 'royal-mcp' ); ?></strong>
            </p>
            <p style="margin: 0 0 12px;">
                <?php esc_html_e( 'Bulk-edit WooCommerce prices, stock and orders, build Elementor and Divi layouts that stay editable in the builder, set up ACF field groups and run a whole SEO launch in one call. Give each AI connection only the tools it needs, and hand clients a 90-day log of everything the AI did, with undo. Royally, one dashboard for every client site, is in beta and coming soon to Royal MCP Pro members.', 'royal-mcp' ); ?><?php if ( current_datetime()->format( 'Y-m-d' ) <= self::OFFER_ENDS ) : ?> <?php esc_html_e( '30-50% off ends Oct 31st.', 'royal-mcp' ); ?><?php endif; ?>
            </p>
            <p style="margin: 0;">
                <a href="<?php echo esc_url( $pro_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary" style="background:#00a32a;border-color:#008a20;color:#fff;"><?php esc_html_e( 'Get Royal MCP Pro &rarr;', 'royal-mcp' ); ?></a>
                &nbsp;
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="royal-mcp-pro-notice-dismiss" style="margin-left: 6px;"><?php esc_html_e( 'Dismiss', 'royal-mcp' ); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Record the dismissal for this user at the current plugin version and
     * return them to the page they were on.
     */
    public function handle_dismiss() {
        if ( ! current_user_can( 'manage_options' ) ) { // audit:multisite-manage-options-safe -- writes per-user meta only (no per-site or network-scope state)
            wp_die( esc_html__( 'You do not have permission to dismiss this notice.', 'royal-mcp' ) );
        }
        check_admin_referer( self::DISMISS_ACTION );
        update_user_meta( get_current_user_id(), self::DISMISSED_META, ROYAL_MCP_VERSION );
        wp_safe_redirect( wp_get_referer() ?: admin_url() );
        exit;
    }
}
