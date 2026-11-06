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

    /**
     * The notice's Pro link with its campaign tags; query arguments already on
     * the base link are kept.
     */
    public static function pro_url() {
        return add_query_arg(
            [
                'utm_source'   => 'royal-mcp-free',
                'utm_medium'   => 'wp-admin-banner',
                'utm_campaign' => 'royally-audit-log',
            ],
            'https://royalplugins.com/royally/'
        );
    }

    public function render() {
        if ( ! self::should_show() ) {
            return;
        }
        $pro_url = self::pro_url();
        $dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION );
        ?>
        <div class="notice notice-info royal-mcp-pro-notice" style="padding: 14px 18px; background: #eff6ff; border-left-color: #1d4ed8;">
            <p style="margin: 0 0 8px; font-size: 14px;">
                <strong><?php esc_html_e( 'See everything your AI agent changed this week, and undo any of it.', 'royal-mcp' ); ?></strong>
            </p>
            <p style="margin: 0 0 8px;">
                <?php esc_html_e( 'Royal MCP Pro adds an audit log with one-click undo, a 7-day undo window, update rollback and 400+ tools for WooCommerce, Elementor, Divi and ACF.', 'royal-mcp' ); ?>
            </p>
            <p style="margin: 0 0 8px;">
                <?php esc_html_e( "Royally, in the cloud, brings all your sites together and connects Cloudflare and 9 other WordPress MCP servers, including Elementor's own. Updates and bulk changes can run on your linked staging copy first, and you approve them in one place.", 'royal-mcp' ); ?>
            </p>
            <p style="margin: 0 0 12px;">
                <strong><?php esc_html_e( 'One plan covers both, from $9/month.', 'royal-mcp' ); ?></strong>
            </p>
            <p style="margin: 0;">
                <a href="<?php echo esc_url( $pro_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary" style="background:#1d4ed8;border-color:#1e40af;color:#fff;"><?php esc_html_e( 'Compare Pro Plans Now', 'royal-mcp' ); ?></a>
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
