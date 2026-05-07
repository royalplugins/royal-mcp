<?php
namespace Royal_MCP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detects when the host is blocking /.well-known/oauth-authorization-server
 * (most commonly SiteGround's nginx claiming the .well-known/ path prefix
 * for ACME) and surfaces an admin notice linking to the manual fix.
 */
class Well_Known_Notice {

    const TRANSIENT_KEY    = 'royal_mcp_well_known_status';
    const TRANSIENT_TTL    = 12 * HOUR_IN_SECONDS;
    const USER_DISMISS_KEY = 'royal_mcp_well_known_dismissed';
    const SUPPORT_URL      = 'https://royalplugins.com/support/royal-mcp/siteground-well-known-404.html';

    public function __construct() {
        add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
        add_action( 'admin_init', [ $this, 'maybe_dismiss' ] );
        add_action( 'update_option_royal_mcp_settings', [ $this, 'invalidate_check' ] );
    }

    /**
     * Render the notice when the self-check confirms /.well-known/ is blocked.
     */
    public function maybe_render_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen ) {
            return;
        }

        $allowed_screens = [
            'plugins',
            'toplevel_page_royal-mcp',
            'royal-mcp_page_royal-mcp-logs',
        ];
        if ( ! in_array( $screen->id, $allowed_screens, true ) ) {
            return;
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }
        if ( get_user_meta( $user_id, self::USER_DISMISS_KEY, true ) ) {
            return;
        }

        $settings = get_option( 'royal_mcp_settings', [] );
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        if ( $this->is_dev_host() ) {
            return;
        }

        if ( is_multisite() && ! is_main_site() ) {
            return;
        }

        if ( 'blocked' !== $this->check_well_known() ) {
            return;
        }

        $this->render_notice();
    }

    /**
     * Probe the discovery endpoint and classify the response.
     *
     * Cached in a transient so we don't hit the loopback HTTP API on every admin page load.
     *
     * Returns one of:
     *  - ok        : status 200, body parses as JSON, issuer matches home_url()
     *  - blocked   : status 404 with no PHP/WP fingerprint (nginx static 404)
     *  - unknown   : connection error, timeout, or non-2xx/non-404
     *  - mismatch  : status 200 but content unexpected (e.g. issuer URL stale)
     */
    private function check_well_known() {
        $cached = get_transient( self::TRANSIENT_KEY );
        if ( false !== $cached ) {
            return $cached;
        }

        $url = home_url( '/.well-known/oauth-authorization-server' );

        $response = wp_remote_get(
            $url,
            [
                'timeout'     => 5,
                'redirection' => 0,
                'sslverify'   => true,
                'user-agent'  => 'Royal MCP Self-Check',
            ]
        );

        $status = 'unknown';

        if ( is_wp_error( $response ) ) {
            $status = 'unknown';
        } else {
            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = (string) wp_remote_retrieve_body( $response );

            if ( 200 === $code ) {
                $data = json_decode( $body, true );
                if ( is_array( $data )
                    && ! empty( $data['issuer'] )
                    && rtrim( $data['issuer'], '/' ) === rtrim( home_url(), '/' )
                ) {
                    $status = 'ok';
                } else {
                    $status = 'mismatch';
                }
            } elseif ( 404 === $code ) {
                $headers      = wp_remote_retrieve_headers( $response );
                $has_php_hdr  = ! empty( $headers['x-httpd'] );
                $is_tiny_body = strlen( $body ) < 500;
                $status       = ( ! $has_php_hdr && $is_tiny_body ) ? 'blocked' : 'unknown';
            }
        }

        set_transient( self::TRANSIENT_KEY, $status, self::TRANSIENT_TTL );

        return $status;
    }

    /**
     * True if the site looks like a local dev environment we shouldn't pester.
     */
    private function is_dev_host() {
        $host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        if ( '' === $host ) {
            return false;
        }
        if ( 'localhost' === $host || '127.0.0.1' === $host ) {
            return true;
        }
        $dev_tlds = [ '.test', '.local', '.localhost', '.dev' ];
        foreach ( $dev_tlds as $tld ) {
            if ( substr( $host, -strlen( $tld ) ) === $tld ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Drop the transient so the next admin page load re-probes. Wired to the
     * settings-save action so the user gets fresh feedback after toggling
     * enabled/disabled or changing OAuth-related config.
     */
    public function invalidate_check() {
        delete_transient( self::TRANSIENT_KEY );
    }

    /**
     * Persist a per-user dismissal of the notice when the dismiss link is followed.
     */
    public function maybe_dismiss() {
        if ( ! isset( $_GET['royal_mcp_dismiss_well_known'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! isset( $_GET['_wpnonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'royal_mcp_dismiss_well_known' )
        ) {
            return;
        }

        update_user_meta( get_current_user_id(), self::USER_DISMISS_KEY, time() );

        wp_safe_redirect( remove_query_arg( [ 'royal_mcp_dismiss_well_known', '_wpnonce' ] ) );
        exit;
    }

    private function render_notice() {
        $dismiss_url = wp_nonce_url(
            add_query_arg( 'royal_mcp_dismiss_well_known', '1' ),
            'royal_mcp_dismiss_well_known'
        );

        ?>
        <div class="notice notice-warning royal-mcp-well-known-notice">
            <p>
                <strong><?php esc_html_e( 'Royal MCP: OAuth discovery is being blocked by your host.', 'royal-mcp' ); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: %s: literal URL path code */
                    esc_html__( 'Your web server is returning a 404 for %s before WordPress sees the request. Claude.ai and other MCP clients will fail to connect until this is fixed. SiteGround and a few other managed hosts reserve this path for their own use.', 'royal-mcp' ),
                    '<code>/.well-known/oauth-authorization-server</code>'
                );
                ?>
            </p>
            <p>
                <a href="<?php echo esc_url( self::SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
                    <?php esc_html_e( 'See the 5-minute fix', 'royal-mcp' ); ?>
                </a>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link" style="margin-left: 1rem;">
                    <?php esc_html_e( 'Dismiss', 'royal-mcp' ); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
