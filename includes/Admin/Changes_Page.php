<?php
namespace Royal_MCP\Admin;

use Royal_MCP\MCP\Undo_Store;
use Royal_MCP\MCP\Support\Change_View;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin submenu showing what AI clients changed in the last 72 hours: every
 * undo-tracked write, with a before / after / now view per change. Reads the
 * undo snapshots only; nothing here changes the site.
 */
class Changes_Page {

    const MENU_SLUG = 'royal-mcp-changes';

    public function __construct() {
        // After Connected Clients (priority 12).
        add_action( 'admin_menu', [ $this, 'add_menu' ], 13 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function add_menu() {
        add_submenu_page(
            'royal-mcp',
            __( 'Recent Changes', 'royal-mcp' ),
            __( 'Recent Changes', 'royal-mcp' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render' ]
        );
    }

    /** WordPress's own diff table styles. */
    public function enqueue( $hook_suffix ) {
        if ( 'royal-mcp_page_' . self::MENU_SLUG === $hook_suffix ) {
            wp_enqueue_style( 'revisions' );
        }
    }

    /** Link to one change. */
    public static function change_url( $change_id ) {
        return add_query_arg( [ 'page' => self::MENU_SLUG, 'change' => $change_id ], admin_url( 'admin.php' ) );
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) { // audit:multisite-manage-options-safe -- per-site undo snapshots in this site's options table only
            wp_die( esc_html__( 'You do not have permission to access this page.', 'royal-mcp' ), '', [ 'response' => 403 ] );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector.
        $change_id = isset( $_GET['change'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['change'] ) ) ) : '';
        echo '<div class="wrap royal-mcp-changes">';
        if ( '' !== $change_id ) {
            $this->render_change( $change_id );
        } else {
            $this->render_list();
        }
        echo '</div>';
    }

    private function render_list() {
        $format  = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
        $changes = array_values( array_filter( Undo_Store::all_changes(), [ Change_View::class, 'can_view' ] ) );
        ?>
        <h1><?php esc_html_e( 'Recent Changes', 'royal-mcp' ); ?></h1>
        <p><?php esc_html_e( 'Changes AI clients made through Royal MCP in the last 72 hours that can be reviewed and undone. Open one to see each value before the change, what the change wrote, and what it is now. Undo is done by the AI client that made the change, using the undo token it was given. Changes older than 72 hours are not kept.', 'royal-mcp' ); ?></p>
        <?php if ( empty( $changes ) ) : ?>
            <p><strong><?php esc_html_e( 'No changes in the last 72 hours.', 'royal-mcp' ); ?></strong></p>
            <?php return; ?>
        <?php endif; ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" style="width:15%"><?php esc_html_e( 'Time', 'royal-mcp' ); ?></th>
                    <th scope="col" style="width:17%"><?php esc_html_e( 'Tool', 'royal-mcp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Changed', 'royal-mcp' ); ?></th>
                    <th scope="col" style="width:18%"><?php esc_html_e( 'Made by', 'royal-mcp' ); ?></th>
                    <th scope="col" style="width:9%"><?php esc_html_e( 'Status', 'royal-mcp' ); ?></th>
                    <th scope="col" style="width:9%"><span class="screen-reader-text"><?php esc_html_e( 'View', 'royal-mcp' ); ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $changes as $change ) : ?>
                    <?php $row = Change_View::summary( $change, false ); ?>
                    <tr>
                        <td><?php echo esc_html( wp_date( $format, (int) $change['created_at'] ) ); ?></td>
                        <td><code><?php echo esc_html( $row['tool'] ); ?></code></td>
                        <td><?php echo esc_html( $row['target_label'] ); ?></td>
                        <td><?php echo esc_html( $this->made_by( $change, $row ) ); ?></td>
                        <td><?php echo esc_html( $this->status_label( $row['status'] ) ); ?></td>
                        <td><a class="button button-small" href="<?php echo esc_url( self::change_url( $row['change_id'] ) ); ?>"><?php esc_html_e( 'View change', 'royal-mcp' ); ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_change( $change_id ) {
        $back   = add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) );
        $change = Undo_Store::find_change( $change_id, null, true );
        ?>
        <h1><?php esc_html_e( 'Change', 'royal-mcp' ); ?></h1>
        <p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'All recent changes', 'royal-mcp' ); ?></a></p>
        <?php
        if ( ! $change || ! Change_View::can_view( $change ) ) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__( 'This change is no longer kept. Changes are kept for 72 hours.', 'royal-mcp' ) . '</p></div>';
            return;
        }
        $server = new \Royal_MCP\MCP\Server();
        $view   = Change_View::detail( $change, static function ( $value, $key ) use ( $server ) {
            return $server->redact_for_display( $value, (string) $key );
        }, false, true );
        $format = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
        $diffed = array_column( $view['diffs'], 'field' );
        ?>
        <table class="form-table" role="presentation">
            <tr><th scope="row"><?php esc_html_e( 'Tool', 'royal-mcp' ); ?></th><td><code><?php echo esc_html( $view['tool'] ); ?></code></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Changed', 'royal-mcp' ); ?></th><td><?php echo esc_html( $view['target_label'] ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Undo restores', 'royal-mcp' ); ?></th><td><?php echo esc_html( $view['undo_summary'] ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Time', 'royal-mcp' ); ?></th><td><?php echo esc_html( wp_date( $format, (int) $change['created_at'] ) ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Made by', 'royal-mcp' ); ?></th><td><?php echo esc_html( $this->made_by( $change, $view ) ); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e( 'Status', 'royal-mcp' ); ?></th><td><?php echo esc_html( $this->status_label( $view['status'] ) ); ?></td></tr>
            <?php if ( ! empty( $view['note'] ) ) : ?>
                <tr><th scope="row"><?php esc_html_e( 'Note', 'royal-mcp' ); ?></th><td><?php echo esc_html( $view['note'] ); ?></td></tr>
            <?php endif; ?>
        </table>

        <?php if ( ! empty( $view['fields'] ) ) : ?>
            <h2><?php esc_html_e( 'Values', 'royal-mcp' ); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" style="width:18%"><?php esc_html_e( 'Field', 'royal-mcp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Before', 'royal-mcp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'The change wrote', 'royal-mcp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Now', 'royal-mcp' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $view['fields'] as $field ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $field['field'] ); ?></code>
                                <?php if ( ! empty( $field['changed_since'] ) ) : ?>
                                    <br><em><?php esc_html_e( 'changed again since', 'royal-mcp' ); ?></em>
                                <?php endif; ?>
                            </td>
                            <?php foreach ( [ 'before', 'after', 'current' ] as $part ) : ?>
                                <td><?php $this->cell( $field, $part, in_array( $field['field'], $diffed, true ) ); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ( ! empty( $view['unchanged_fields'] ) ) : ?>
            <p><?php echo esc_html( sprintf( /* translators: %s: comma-separated field names */ __( 'Not changed: %s', 'royal-mcp' ), implode( ', ', $view['unchanged_fields'] ) ) ); ?></p>
        <?php endif; ?>

        <?php foreach ( $view['diffs'] as $diff ) : ?>
            <h2>
                <?php
                /* translators: %s: field name, for example post_content */
                echo esc_html( sprintf( __( '%s: before and now', 'royal-mcp' ), $diff['field'] ) );
                ?>
            </h2>
            <?php
            if ( strlen( $diff['before_text'] ) + strlen( $diff['current_text'] ) > 400000 ) {
                echo '<pre style="white-space:pre-wrap;max-height:480px;overflow:auto;">' . esc_html( $diff['unified'] ) . '</pre>';
                if ( ! empty( $diff['truncated'] ) ) {
                    echo '<p><em>' . esc_html__( 'Shortened: the text is too large to show in full here.', 'royal-mcp' ) . '</em></p>';
                }
            } else {
                $table = wp_text_diff( $diff['before_text'], $diff['current_text'], [ 'title_left' => __( 'Before', 'royal-mcp' ), 'title_right' => __( 'Now', 'royal-mcp' ) ] );
                echo $table ? wp_kses_post( $table ) : '<p>' . esc_html__( 'No line differences.', 'royal-mcp' ) . '</p>';
            }
            ?>
        <?php endforeach; ?>
        <?php
    }

    /** One value cell. */
    private function cell( array $field, $part, $diffed ) {
        if ( in_array( $part, (array) ( $field['absent'] ?? [] ), true ) ) {
            echo '<em>' . esc_html( 'current' === $part ? __( 'does not exist', 'royal-mcp' ) : __( 'did not exist', 'royal-mcp' ) ) . '</em>';
            return;
        }
        if ( ! array_key_exists( $part, $field ) ) {
            echo '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'not recorded', 'royal-mcp' ) . '</span>';
            return;
        }
        $value = $field[ $part ];
        if ( $diffed && is_string( $value ) && strlen( $value ) > 200 ) {
            echo '<em>' . esc_html__( 'long text, see the line diff below', 'royal-mcp' ) . '</em>';
            return;
        }
        if ( is_array( $value ) || is_object( $value ) ) {
            echo '<pre style="white-space:pre-wrap;margin:0;max-height:240px;overflow:auto;">' . esc_html( (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
            return;
        }
        if ( is_bool( $value ) ) {
            $value = $value ? 'true' : 'false';
        } elseif ( null === $value ) {
            $value = 'null';
        }
        echo '<span style="white-space:pre-wrap;word-break:break-word;">' . esc_html( (string) $value ) . '</span>';
        if ( ! empty( $field['truncated'] ) ) {
            echo ' <em>' . esc_html__( '(shortened)', 'royal-mcp' ) . '</em>';
        }
    }

    private function made_by( array $change, array $row ) {
        $user  = get_userdata( (int) ( $change['user_id'] ?? 0 ) );
        $login = $user ? $user->user_login : __( 'unknown user', 'royal-mcp' );
        /* translators: 1: connection, for example Claude or API key; 2: WordPress user login */
        return sprintf( __( '%1$s as %2$s', 'royal-mcp' ), $row['connection']['label'], $login );
    }

    private function status_label( $status ) {
        $labels = [
            'active'  => __( 'Can be undone', 'royal-mcp' ),
            'undone'  => __( 'Undone', 'royal-mcp' ),
            'expired' => __( 'Expired', 'royal-mcp' ),
        ];
        return $labels[ $status ] ?? (string) $status;
    }
}
