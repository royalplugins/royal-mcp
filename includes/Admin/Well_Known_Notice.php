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

    const TRANSIENT_KEY        = 'royal_mcp_well_known_status';
    const TRANSIENT_TTL        = 12 * HOUR_IN_SECONDS;
    const USER_DISMISS_KEY     = 'royal_mcp_well_known_dismissed';
    const STALE_DISMISS_KEY    = 'royal_mcp_well_known_stale_dismissed';
    const SUPPORT_URL          = 'https://royalplugins.com/support/royal-mcp/siteground-well-known-404.html';
    const STALE_SUPPORT_URL    = 'https://royalplugins.com/support/royal-mcp/stale-well-known-static-files.html';

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

        $status = $this->check_well_known();

        if ( 'blocked' === $status
            && ! get_user_meta( $user_id, self::USER_DISMISS_KEY, true )
        ) {
            $this->render_blocked_notice();
            return;
        }

        if ( 'stale_static' === $status
            && ! get_user_meta( $user_id, self::STALE_DISMISS_KEY, true )
        ) {
            $this->render_stale_static_notice();
        }
    }

    /**
     * Probe the discovery endpoint and classify the response.
     *
     * Cached in a transient so we don't hit the loopback HTTP API on every admin page load.
     *
     * Returns one of:
     *  - ok            : status 200, body parses as JSON, issuer matches and endpoints are root paths
     *  - blocked       : status 404 with no PHP/WP fingerprint (nginx static 404)
     *  - stale_static  : status 200 with JSON but endpoints advertise REST-namespace paths
     *                    (/wp-json/royal-mcp/v1/...) — leftover static file from pre-1.4.0 era
     *  - unknown       : connection error, timeout, or non-2xx/non-404
     *  - mismatch      : status 200 but content unexpected for unrelated reasons (issuer mismatch)
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

        if ( is_wp_error( $response ) ) {
            $status = 'unknown';
        } else {
            $code    = (int) wp_remote_retrieve_response_code( $response );
            $body    = (string) wp_remote_retrieve_body( $response );
            $headers = wp_remote_retrieve_headers( $response );
            $headers = is_array( $headers ) ? $headers : iterator_to_array( $headers );
            $status  = self::classify_response( $code, $body, $headers, rtrim( home_url(), '/' ) );
        }

        set_transient( self::TRANSIENT_KEY, $status, self::TRANSIENT_TTL );

        return $status;
    }

    /**
     * Pure classifier for a probed `.well-known/oauth-authorization-server` response.
     *
     * Public + static so it can be exercised by unit tests without monkey-patching
     * wp_remote_get. Inputs come straight from the HTTP probe; output is one of
     * the status strings documented on check_well_known().
     *
     * @param int    $code             HTTP status code.
     * @param string $body             Response body.
     * @param array  $headers          Response headers (key-value array).
     * @param string $expected_issuer  Trailing-slash-trimmed home URL.
     */
    public static function classify_response( $code, $body, array $headers, $expected_issuer ) {
        if ( 200 === $code ) {
            $data = json_decode( $body, true );
            if ( ! is_array( $data ) || empty( $data['issuer'] ) ) {
                return 'mismatch';
            }

            $issuer_ok = rtrim( $data['issuer'], '/' ) === $expected_issuer;

            // Stale-static detection: pre-1.4.0 era files advertised REST-namespace
            // OAuth endpoints (/wp-json/royal-mcp/v1/authorize). Current code serves
            // them at root (/authorize). If the file is still on disk with old paths,
            // claude.ai follows the bad URL and 404s. See keyspure.com 2026-05-16.
            $endpoints = [
                $data['authorization_endpoint'] ?? '',
                $data['token_endpoint']         ?? '',
                $data['registration_endpoint']  ?? '',
            ];
            foreach ( $endpoints as $endpoint ) {
                if ( '' !== $endpoint && false !== strpos( $endpoint, '/wp-json/royal-mcp/v1/' ) ) {
                    return 'stale_static';
                }
            }

            return $issuer_ok ? 'ok' : 'mismatch';
        }

        if ( 404 === $code ) {
            $has_php_hdr  = ! empty( $headers['x-httpd'] );
            $is_tiny_body = strlen( $body ) < 500;
            return ( ! $has_php_hdr && $is_tiny_body ) ? 'blocked' : 'unknown';
        }

        return 'unknown';
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
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_GET['royal_mcp_dismiss_well_known'] )
            && isset( $_GET['_wpnonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'royal_mcp_dismiss_well_known' )
        ) {
            update_user_meta( get_current_user_id(), self::USER_DISMISS_KEY, time() );
            wp_safe_redirect( remove_query_arg( [ 'royal_mcp_dismiss_well_known', '_wpnonce' ] ) );
            exit;
        }

        if ( isset( $_GET['royal_mcp_dismiss_stale_static'] )
            && isset( $_GET['_wpnonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'royal_mcp_dismiss_stale_static' )
        ) {
            update_user_meta( get_current_user_id(), self::STALE_DISMISS_KEY, time() );
            wp_safe_redirect( remove_query_arg( [ 'royal_mcp_dismiss_stale_static', '_wpnonce' ] ) );
            exit;
        }
    }

    private function render_blocked_notice() {
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

    private function render_stale_static_notice() {
        $dismiss_url = wp_nonce_url(
            add_query_arg( 'royal_mcp_dismiss_stale_static', '1' ),
            'royal_mcp_dismiss_stale_static'
        );

        ?>
        <div class="notice notice-error royal-mcp-stale-static-notice">
            <p>
                <strong><?php esc_html_e( 'Royal MCP: stale OAuth discovery files detected in your webroot.', 'royal-mcp' ); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: 1: file path, 2: file path */
                    esc_html__( 'Static files at %1$s and %2$s are advertising old OAuth endpoint URLs (under /wp-json/royal-mcp/v1/) that no longer exist. Claude.ai reads these and tries to register against a 404, so connection silently fails.', 'royal-mcp' ),
                    '<code>/.well-known/oauth-authorization-server</code>',
                    '<code>/.well-known/oauth-protected-resource</code>'
                );
                ?>
            </p>
            <p>
                <?php esc_html_e( 'These files were likely placed by a host-support workaround for an earlier version. Delete them and Royal MCP will serve fresh metadata from PHP automatically.', 'royal-mcp' ); ?>
            </p>
            <p>
                <strong><?php esc_html_e( 'SSH/SFTP fix:', 'royal-mcp' ); ?></strong>
                <code>rm /path/to/your/webroot/.well-known/oauth-authorization-server /path/to/your/webroot/.well-known/oauth-protected-resource</code>
            </p>
            <p>
                <a href="<?php echo esc_url( self::STALE_SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
                    <?php esc_html_e( 'See the full fix', 'royal-mcp' ); ?>
                </a>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link" style="margin-left: 1rem;">
                    <?php esc_html_e( 'Dismiss', 'royal-mcp' ); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
