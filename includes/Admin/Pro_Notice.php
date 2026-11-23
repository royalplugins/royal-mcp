<?php
namespace Royal_MCP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Dismissible Royally notice for administrators, shown on this plugin's own
 * screens only (not on the Dashboard, which has its own upgrade card).
 *
 * Dismissal is per user and stamped with the plugin version, so the notice
 * stays away until the next plugin update. Not shown while Royal MCP Pro is
 * active.
 */
class Pro_Notice {

    const DISMISSED_META = 'royal_mcp_pro_launch_notice_dismissed';
    const DISMISS_ACTION = 'royal_mcp_dismiss_pro_notice';

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
        if ( ! self::on_own_screen() ) {
            return false;
        }
        $seen = get_user_meta( get_current_user_id(), self::DISMISSED_META, true );
        return empty( $seen ) || version_compare( (string) $seen, ROYAL_MCP_VERSION, '<' );
    }

    /** Whether this request is one of the plugin's own screens, other than its Dashboard. */
    private static function on_own_screen() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        // Screens under this plugin's menu have ids that start with the menu's own prefix.
        return $screen && ! empty( $screen->id ) && 0 === strpos( (string) $screen->id, Menu_Layout::sub_page_prefix() . 'royal-mcp-' );
    }

    /** The notice's link with its campaign tags. */
    public static function pro_url() {
        return add_query_arg(
            [
                'utm_source'   => 'royal_mcp_free',
                'utm_medium'   => 'free_plugin',
                'utm_content'  => 'admin_notice',
                'utm_campaign' => 'royal_mcp_admin',
            ],
            'https://royalplugins.com/royal-mcp-pro/'
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
            <p style="margin: 0 0 6px; font-size: 14px;">
                <strong><?php esc_html_e( '1,700+ MCP tools. 4,800+ actions. One connection.', 'royal-mcp' ); ?></strong>
            </p>
            <p style="margin: 0 0 12px;">
                <?php esc_html_e( 'With Royally your AI reaches more than 1,700 MCP tools and 4,800 actions across your WordPress sites and the services you run them with, from one place instead of a connector per app. The Royal MCP Pro plugin is included: Royally is a hybrid plugin and cloud solution.', 'royal-mcp' ); ?>
            </p>
            <p style="margin: 0;">
                <a href="<?php echo esc_url( $pro_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary" style="background:#1d4ed8;border-color:#1e40af;color:#fff;"><?php esc_html_e( 'See Royal MCP Pro', 'royal-mcp' ); ?></a>
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
