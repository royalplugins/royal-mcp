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
        add_action( 'admin_menu', [ $this, 'add_menu' ], 30 );
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
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render' ]
        );
    }

    /**
     * Add a top-level admin-bar item so admins on any page see pending
     * requests without visiting the Royal MCP menu.
     */
    public function admin_bar_count( \WP_Admin_Bar $bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'royal-mcp' ) );
        }
        $rows = Token_Store::get_pending_clients();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Royal MCP: Pending Clients', 'royal-mcp' ); ?></h1>
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
                    <?php foreach ( $rows as $row ) :
                        $approve_url = wp_nonce_url(
                            admin_url( 'admin-post.php?action=' . self::APPROVE_ACTION . '&client_id=' . rawurlencode( $row['client_id'] ) ),
                            self::APPROVE_NONCE
                        );
                        $reject_url = wp_nonce_url(
                            admin_url( 'admin-post.php?action=' . self::REJECT_ACTION . '&client_id=' . rawurlencode( $row['client_id'] ) ),
                            self::REJECT_NONCE
                        );
                        ?>
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
                                <a href="<?php echo esc_url( $approve_url ); ?>" class="button button-primary"><?php esc_html_e( 'Approve', 'royal-mcp' ); ?></a>
                                <a href="<?php echo esc_url( $reject_url ); ?>" class="button" style="color:#b32d2e;"><?php esc_html_e( 'Reject', 'royal-mcp' ); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_approve() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission.', 'royal-mcp' ) );
        }
        check_admin_referer( self::APPROVE_NONCE );
        $client_id = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '';
        if ( '' === $client_id ) {
            wp_die( esc_html__( 'client_id required.', 'royal-mcp' ) );
        }
        Token_Store::approve_client( $client_id );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&approved=1' ) );
        exit;
    }

    public function handle_reject() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission.', 'royal-mcp' ) );
        }
        check_admin_referer( self::REJECT_NONCE );
        $client_id = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '';
        if ( '' === $client_id ) {
            wp_die( esc_html__( 'client_id required.', 'royal-mcp' ) );
        }
        Token_Store::reject_client( $client_id );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&rejected=1' ) );
        exit;
    }
}
