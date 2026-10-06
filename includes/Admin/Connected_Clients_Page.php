<?php
namespace Royal_MCP\Admin;

use Royal_MCP\OAuth\Token_Store;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin submenu listing the AI clients that are connected through OAuth
 * right now: one row per client and WordPress user holding a live token.
 *
 * Each row can be revoked on its own. Revoking ends that client's access for
 * that user and keeps the client's registration, so the user can connect it
 * again from the client. Connections that use the API key are not OAuth
 * clients and are not listed.
 */
class Connected_Clients_Page {

    const MENU_SLUG         = 'royal-mcp-connected-clients';
    const REVOKE_ACTION     = 'royal_mcp_revoke_connection';
    const REVOKE_NONCE      = 'royal_mcp_revoke_connection_nonce';
    const REVOKE_ALL_ACTION = 'royal_mcp_revoke_all_connections';
    const REVOKE_ALL_NONCE  = 'royal_mcp_revoke_all_connections_nonce';
    const SCRIPT_HANDLE     = 'royal-mcp-connected-clients';

    public function __construct() {
        // Priority 12 places this submenu after Pending Clients (priority 11).
        add_action( 'admin_menu', [ $this, 'add_menu' ], 12 );
        add_action( 'admin_post_' . self::REVOKE_ACTION, [ $this, 'handle_revoke' ] );
        add_action( 'admin_post_' . self::REVOKE_ALL_ACTION, [ $this, 'handle_revoke_all' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    /**
     * A confirmation step on the Revoke all button; nothing else on the page needs script.
     */
    public function enqueue( $hook_suffix ) {
        if ( 'royal-mcp_page_' . self::MENU_SLUG !== $hook_suffix ) {
            return;
        }
        wp_register_script( self::SCRIPT_HANDLE, false, [], ROYAL_MCP_VERSION, true );
        wp_enqueue_script( self::SCRIPT_HANDLE );
        $message = __( 'This disconnects every AI client signed in through OAuth, including any you are using right now. Each one must sign in again. Continue?', 'royal-mcp' );
        wp_add_inline_script(
            self::SCRIPT_HANDLE,
            'document.addEventListener("DOMContentLoaded",function(){var f=document.getElementById("royal-mcp-revoke-all-form");if(f){f.addEventListener("submit",function(e){if(!window.confirm(' . wp_json_encode( $message ) . ')){e.preventDefault();}});}});'
        );
    }

    public function add_menu() {
        add_submenu_page(
            'royal-mcp',
            __( 'Connected Clients', 'royal-mcp' ),
            __( 'Connected Clients', 'royal-mcp' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render' ]
        );
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) { // audit:multisite-manage-options-safe -- per-site OAuth tokens only (wp_royal_mcp_oauth_tokens is per-site-prefixed); sub-site admins can manage their own site's connections
            wp_die( esc_html__( 'You do not have permission to access this page.', 'royal-mcp' ), '', [ 'response' => 403 ] );
        }
        $rows = Token_Store::list_connections();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only result flags set by this page's own redirect.
        $revoked     = isset( $_GET['revoked'] ) ? absint( wp_unslash( $_GET['revoked'] ) ) : null;
        $revoked_all = isset( $_GET['revoked_all'] ) ? absint( wp_unslash( $_GET['revoked_all'] ) ) : null;
        $blocked     = ! empty( $_GET['revoke_blocked'] );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $format  = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Connected Clients', 'royal-mcp' ); ?></h1>
            <p><?php esc_html_e( 'AI clients that are signed in through OAuth and can use this site right now. Revoking a row ends that client\'s access for that user; the client stays registered, so the user can connect it again from the client. Connections that use the API key are not listed here.', 'royal-mcp' ); ?></p>

            <?php if ( $blocked ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Session revocation is disabled by a filter on this site.', 'royal-mcp' ); ?></p></div>
            <?php elseif ( null !== $revoked_all ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    /* translators: %d: number of tokens revoked */
                    echo esc_html( sprintf( _n( 'Every connected client has been signed out (%d token revoked).', 'Every connected client has been signed out (%d tokens revoked).', $revoked_all, 'royal-mcp' ), $revoked_all ) );
                    ?>
                </p></div>
            <?php elseif ( null !== $revoked && $revoked > 0 ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    /* translators: %d: number of tokens revoked */
                    echo esc_html( sprintf( _n( 'Access revoked (%d token).', 'Access revoked (%d tokens).', $revoked, 'royal-mcp' ), $revoked ) );
                    ?>
                </p></div>
            <?php elseif ( null !== $revoked ) : ?>
                <div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'That connection had no live tokens left to revoke.', 'royal-mcp' ); ?></p></div>
            <?php endif; ?>

            <?php if ( empty( $rows ) ) : ?>
                <p><strong><?php esc_html_e( 'No AI clients are connected through OAuth right now.', 'royal-mcp' ); ?></strong></p>
            <?php else : ?>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:0 0 12px;padding:12px 16px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #d63638;box-shadow:0 1px 1px rgba(0,0,0,.04);">
                    <div style="min-width:0;">
                        <strong style="display:block;font-size:14px;margin-bottom:2px;"><?php esc_html_e( 'Sign out every connected client', 'royal-mcp' ); ?></strong>
                        <span style="color:#50575e;"><?php esc_html_e( 'Ends every connection listed below at once. Clients stay registered and can sign in again. Use it after changing the session length or when responding to an incident.', 'royal-mcp' ); ?></span>
                    </div>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="royal-mcp-revoke-all-form" style="margin:0;flex-shrink:0;">
                        <input type="hidden" name="action" value="<?php echo esc_attr( self::REVOKE_ALL_ACTION ); ?>">
                        <?php wp_nonce_field( self::REVOKE_ALL_NONCE ); ?>
                        <button type="submit" class="button button-primary" style="background:#d63638;border-color:#d63638;color:#fff;box-shadow:none;text-shadow:none;">
                            <?php
                            /* translators: %d: number of connections */
                            echo esc_html( sprintf( _n( 'Revoke all (%d connection)', 'Revoke all (%d connections)', count( $rows ), 'royal-mcp' ), count( $rows ) ) );
                            ?>
                        </button>
                    </form>
                </div>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Client', 'royal-mcp' ); ?></th>
                            <th><?php esc_html_e( 'Connected as', 'royal-mcp' ); ?></th>
                            <th><?php esc_html_e( 'Registered', 'royal-mcp' ); ?></th>
                            <th><?php esc_html_e( 'Last signed in or renewed', 'royal-mcp' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'royal-mcp' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $rows as $row ) :
                        $user = get_userdata( $row['user_id'] );
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( '' !== $row['client_name'] ? $row['client_name'] : __( 'Unknown client', 'royal-mcp' ) ); ?></strong><br>
                                <code style="font-size:11px;word-break:break-all;"><?php echo esc_html( $row['client_id'] ); ?></code>
                            </td>
                            <td>
                                <?php if ( $user ) : ?>
                                    <?php echo esc_html( $user->display_name ); ?><br>
                                    <code style="font-size:11px;"><?php echo esc_html( $user->user_login ); ?></code>
                                <?php else : ?>
                                    <?php
                                    /* translators: %d: WordPress user ID */
                                    echo esc_html( sprintf( __( 'Deleted user (ID %d)', 'royal-mcp' ), $row['user_id'] ) );
                                    ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( null === $row['registered_age'] ? __( 'Set in plugin settings', 'royal-mcp' ) : self::describe_age( $row['registered_age'], $format ) ); ?></td>
                            <td><?php echo esc_html( self::describe_age( $row['last_issued_age'], $format ) ); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
                                    <input type="hidden" name="action" value="<?php echo esc_attr( self::REVOKE_ACTION ); ?>">
                                    <input type="hidden" name="client_id" value="<?php echo esc_attr( $row['client_id'] ); ?>">
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $row['user_id'] ); ?>">
                                    <?php wp_nonce_field( self::REVOKE_NONCE ); ?>
                                    <button type="submit" class="button" style="color:#b32d2e;"><?php esc_html_e( 'Revoke access', 'royal-mcp' ); ?></button>
                                </form>
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
     * A point in the past given as seconds ago: the date in the site's time
     * zone and format, followed by how long ago that was.
     */
    private static function describe_age( $seconds_ago, $format ) {
        $when = time() - max( 0, (int) $seconds_ago );
        /* translators: 1: date and time, 2: time difference such as "5 mins" */
        return sprintf( __( '%1$s (%2$s ago)', 'royal-mcp' ), wp_date( $format, $when ), human_time_diff( $when, time() ) );
    }

    public function handle_revoke() {
        if ( ! current_user_can( 'manage_options' ) ) { // audit:multisite-manage-options-safe -- per-site OAuth tokens only (wp_royal_mcp_oauth_tokens is per-site-prefixed); sub-site admins can manage their own site's connections
            wp_die( esc_html__( 'You do not have permission.', 'royal-mcp' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( self::REVOKE_NONCE );

        $client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
        $user_id   = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
        if ( '' === $client_id || $user_id < 1 ) {
            wp_die( esc_html__( 'client_id and user_id required.', 'royal-mcp' ), '', [ 'response' => 400 ] );
        }

        $page = admin_url( 'admin.php?page=' . self::MENU_SLUG );

        // The same switch that lets a site turn off "Revoke all".
        if ( ! apply_filters( 'royal_mcp_revoke_all_sessions_allowed', true, get_current_user_id() ) ) {
            wp_safe_redirect( add_query_arg( 'revoke_blocked', '1', $page ) );
            exit;
        }

        $revoked = Token_Store::revoke_connection( $client_id, $user_id );

        global $wpdb;
        $current_user = wp_get_current_user();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct insert to the logs table.
        $wpdb->insert(
            $wpdb->prefix . 'royal_mcp_logs',
            [
                'mcp_server'    => 'OAuth Server',
                'action'        => 'oauth:revoke_connection',
                'request_data'  => wp_json_encode( [
                    'user_id'        => (int) $current_user->ID,
                    'user_login'     => $current_user->user_login,
                    'client_id'      => $client_id,
                    'target_user_id' => $user_id,
                ] ),
                'response_data' => wp_json_encode( [ 'revoked_count' => $revoked ] ),
                'status'        => 'success',
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        wp_safe_redirect( add_query_arg( 'revoked', (string) $revoked, $page ) );
        exit;
    }

    /**
     * Sign out every OAuth client at once. Registrations and settings stay;
     * only issued tokens are revoked, so each client can sign in again.
     */
    public function handle_revoke_all() {
        if ( ! current_user_can( 'manage_options' ) ) { // audit:multisite-manage-options-safe -- per-site OAuth tokens only (wp_royal_mcp_oauth_tokens is per-site-prefixed)
            wp_die( esc_html__( 'You do not have permission.', 'royal-mcp' ), '', [ 'response' => 403 ] );
        }
        // Only the form's POST may do this; a followed link must not.
        if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
            wp_die( esc_html__( 'Use the Revoke all button on the Connected Clients screen.', 'royal-mcp' ), '', [ 'response' => 405 ] );
        }
        check_admin_referer( self::REVOKE_ALL_NONCE );

        $page = admin_url( 'admin.php?page=' . self::MENU_SLUG );

        if ( ! apply_filters( 'royal_mcp_revoke_all_sessions_allowed', true, get_current_user_id() ) ) {
            wp_safe_redirect( add_query_arg( 'revoke_blocked', '1', $page ) );
            exit;
        }

        $revoked = Token_Store::revoke_all_tokens();

        global $wpdb;
        $current_user = wp_get_current_user();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct insert to the logs table.
        $wpdb->insert(
            $wpdb->prefix . 'royal_mcp_logs',
            [
                'mcp_server'    => 'OAuth Server',
                'action'        => 'oauth:revoke_all_sessions',
                'request_data'  => wp_json_encode( [
                    'user_id'    => (int) $current_user->ID,
                    'user_login' => $current_user->user_login,
                ] ),
                'response_data' => wp_json_encode( [ 'revoked_count' => $revoked ] ),
                'status'        => 'success',
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        wp_safe_redirect( add_query_arg( 'revoked_all', (string) $revoked, $page ) );
        exit;
    }
}
