<?php
namespace Royal_MCP\Admin;

use Royal_MCP\OAuth\Token_Store;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin submenu that surfaces OAuth clients awaiting approval when the
 * "Require approval before new AI clients can connect" toggle is on
 * (royal_mcp_settings.require_client_approval).
 *
 * Each pending row displays client_name, requested redirect_uris, source
 * IP, User-Agent, and created_at. Admin picks approve or reject per row.
 * Approved clients flip to active and /authorize proceeds normally.
 * Rejected clients are deleted (identical outcome to never having
 * registered).
 *
 * Admin bar shows a count badge when pending > 0 so a site owner
 * notices without visiting the submenu.
 */
class Pending_Clients_Page {

    const MENU_SLUG          = 'royal-mcp-pending-clients';
    const APPROVE_ACTION     = 'royal_mcp_approve_pending_client';
    const REJECT_ACTION      = 'royal_mcp_reject_pending_client';
    const APPROVE_NONCE      = 'royal_mcp_approve_pending_client_nonce';
    const REJECT_NONCE       = 'royal_mcp_reject_pending_client_nonce';

    public function __construct() {
        // Priority 11 places this submenu directly after Settings + Activity Log
        // (both registered at default priority 10 in Settings_Page).
        add_action( 'admin_menu', [ $this, 'add_menu' ], 11 );
        add_action( 'admin_post_' . self::APPROVE_ACTION, [ $this, 'handle_approve' ] );
        add_action( 'admin_post_' . self::REJECT_ACTION, [ $this, 'handle_reject' ] );
        add_action( 'admin_bar_menu', [ $this, 'admin_bar_count' ], 90 );
    }

    public function add_menu() {
        $count = Token_Store::count_pending_clients();
        $label = __( 'Pending Clients', 'royal-mcp' );
        if ( $count > 0 ) {
            $label .= ' <span class="update-plugins count-' . intval( $count ) . '"><span class="update-count">' . intval( $count ) . '</span></span>';
        }
        add_submenu_page(
            'royal-mcp',
            __( 'Pending Clients', 'royal-mcp' ),
            $label,
            Settings_Page::admin_capability(),
            self::MENU_SLUG,
            [ $this, 'render' ]
        );
    }

    /**
     * Add a top-level admin-bar item so admins on any page see pending
     * requests without visiting the Royal MCP menu.
     */
    public function admin_bar_count( \WP_Admin_Bar $bar ) {
        if ( ! current_user_can( Settings_Page::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from Settings_Page::admin_capability(); per-site OAuth clients only (wp_royal_mcp_oauth_clients is per-site-prefixed); sub-site admins can manage their own site's clients
            return;
        }
        $count = Token_Store::count_pending_clients();
        if ( $count <= 0 ) {
            return;
        }
        $bar->add_node( [
            'id'    => 'royal-mcp-pending-clients',
            'title' => sprintf(
                '<span class="ab-icon dashicons dashicons-shield-alt" style="top:2px;"></span> %s <span class="update-plugins count-%d" style="margin-left:4px;"><span class="update-count">%d</span></span>',
                esc_html__( 'MCP', 'royal-mcp' ),
                intval( $count ),
                intval( $count )
            ),
            'href'  => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
            'meta'  => [
                'title' => sprintf(
                    /* translators: %d: number of pending clients */
                    _n( '%d MCP client waiting for approval', '%d MCP clients waiting for approval', $count, 'royal-mcp' ),
                    $count
                ),
            ],
        ] );
    }

    public function render() {
        if ( ! current_user_can( Settings_Page::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from Settings_Page::admin_capability(); per-site OAuth clients only (wp_royal_mcp_oauth_clients is per-site-prefixed); sub-site admins can manage their own site's clients
            wp_die( esc_html__( 'You do not have permission to access this page.', 'royal-mcp' ) );
        }
        $rows = Token_Store::get_pending_clients();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Pending Clients', 'royal-mcp' ); ?></h1>
            <p><?php esc_html_e( 'AI clients that registered via dynamic client registration while the "Require approval" toggle was enabled. Approve to allow connection, reject to remove the registration.', 'royal-mcp' ); ?></p>
            <?php if ( empty( $rows ) ) : ?>
                <p><strong><?php esc_html_e( 'No pending clients.', 'royal-mcp' ); ?></strong></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Client', 'royal-mcp' ); ?></th>
                            <th><?php esc_html_e( 'Redirect URIs', 'royal-mcp' ); ?></th>
                            <th><?php esc_html_e( 'Source IP', 'royal-mcp' ); ?></th>
                            <th><?php esc_html_e( 'User Agent', 'royal-mcp' ); ?></th>
                            <th><?php esc_html_e( 'Registered', 'royal-mcp' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'royal-mcp' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $row['client_name'] ); ?></strong><br>
                                <code style="font-size:11px;"><?php echo esc_html( $row['client_id'] ); ?></code>
                            </td>
                            <td>
                                <?php foreach ( (array) $row['redirect_uris'] as $uri ) : ?>
                                    <code style="font-size:11px;display:block;"><?php echo esc_html( $uri ); ?></code>
                                <?php endforeach; ?>
                            </td>
                            <td><code><?php echo esc_html( $row['ip_address'] ); ?></code></td>
                            <td style="max-width:280px;word-break:break-word;"><?php echo esc_html( $row['user_agent'] ); ?></td>
                            <td><?php echo esc_html( $row['created_at'] ); ?></td>
                            <td>
                                <?php
                                self::action_form( 'approve', (string) $row['client_id'], __( 'Approve', 'royal-mcp' ), 'button button-primary' );
                                self::action_form( 'reject', (string) $row['client_id'], __( 'Reject', 'royal-mcp' ), 'button', 'color:#b32d2e;' );
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The nonce action for approving or rejecting one client: a nonce for one client cannot be
     * used on another.
     *
     * @param string $kind      approve | reject.
     * @param string $client_id Client id.
     */
    public static function nonce_action( $kind, $client_id ) {
        return ( 'approve' === $kind ? self::APPROVE_NONCE : self::REJECT_NONCE ) . '|' . (string) $client_id;
    }

    /**
     * An Approve or Reject button as a small POST form (a link would act on a plain GET).
     *
     * @param string $kind      approve | reject.
     * @param string $client_id Client id.
     * @param string $label     Button text.
     * @param string $class     Button classes.
     * @param string $style     Optional inline style for the button.
     */
    public static function action_form( $kind, $client_id, $label, $class, $style = '' ) {
        $action = 'approve' === $kind ? self::APPROVE_ACTION : self::REJECT_ACTION;
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin:0;">
            <input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
            <input type="hidden" name="client_id" value="<?php echo esc_attr( $client_id ); ?>">
            <?php wp_nonce_field( self::nonce_action( $kind, $client_id ) ); ?>
            <button type="submit" class="<?php echo esc_attr( $class ); ?>"<?php echo '' !== $style ? ' style="' . esc_attr( $style ) . '"' : ''; ?>><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }

    /** The client id of a POSTed approve / reject form, after the capability and its own nonce are checked. */
    private static function posted_client( $kind ) {
        if ( ! current_user_can( Settings_Page::admin_capability() ) ) { // audit:multisite-manage-options-safe -- capability from Settings_Page::admin_capability(); per-site OAuth clients only (wp_royal_mcp_oauth_clients is per-site-prefixed); sub-site admins can manage their own site's clients
            wp_die( esc_html__( 'You do not have permission.', 'royal-mcp' ) );
        }
        if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only
            wp_die( esc_html__( 'Use the Approve or Reject button on the Pending clients screen.', 'royal-mcp' ), '', [ 'response' => 405 ] );
        }
        $client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked next, bound to this client id
        if ( '' === $client_id ) {
            wp_die( esc_html__( 'client_id required.', 'royal-mcp' ) );
        }
        check_admin_referer( self::nonce_action( $kind, $client_id ) );
        return $client_id;
    }

    public function handle_approve() {
        $client_id = self::posted_client( 'approve' );
        Token_Store::approve_client( $client_id );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&approved=1' ) );
        exit;
    }

    public function handle_reject() {
        $client_id = self::posted_client( 'reject' );
        Token_Store::reject_client( $client_id );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&rejected=1' ) );
        exit;
    }
}
