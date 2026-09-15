<?php
namespace Royal_MCP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detects when the web server is stripping the Authorization header before
 * WordPress sees it. Apache with mod_rewrite requires an explicit
 * `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` rule to pass
 * `$_SERVER['HTTP_AUTHORIZATION']` through to PHP. Cloudflare with a
 * "Transform Rules" or "Managed Challenges" configuration can strip the
 * header at the edge as well. On affected hosts, every authenticated MCP
 * request returns 401 despite a valid Bearer token, and customers diagnose
 * the plugin as broken.
 *
 * Design mirrors Well_Known_Notice: rate-limited self-check via transient
 * caching, dedicated admin notice with copy-paste fix, per-user dismiss.
 */
class Authorization_Header_Notice {

    const TRANSIENT_KEY       = 'royal_mcp_auth_header_status';
    const TRANSIENT_TTL       = 12 * HOUR_IN_SECONDS;
    const PROBE_TRANSIENT_KEY = 'royal_mcp_auth_header_probe_';
    const PROBE_TTL           = 60; // 60 seconds — probe_id must be consumed by the loopback POST that follows.
    const USER_DISMISS_KEY    = 'royal_mcp_auth_header_dismissed';
    const SUPPORT_URL         = 'https://royalplugins.com/support/royal-mcp/authorization-header-stripped.html';

    public function __construct() {
        add_action( 'admin_init', [ $this, 'maybe_probe' ] );
        add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
        add_action( 'admin_init', [ $this, 'maybe_dismiss' ] );
    }

    /**
     * Rate-limited probe. Fires the loopback canary at most once per
     * TRANSIENT_TTL window per site. Site owners who want to force a re-probe
     * can delete the transient via WP-CLI or a fresh Royal MCP settings save.
     */
    public function maybe_probe() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( is_multisite() && ! is_main_site() ) {
            return;
        }
        $cached = get_transient( self::TRANSIENT_KEY );
        if ( false !== $cached ) {
            return; // Within TTL, use cached result.
        }

        $result = $this->probe_authorization_header();
        set_transient( self::TRANSIENT_KEY, $result, self::TRANSIENT_TTL );
    }

    /**
     * Fire a loopback POST to the header-echo diagnostic route with a known
     * Authorization header value. If the server strips the header before PHP
     * sees it, the route reports `received: false`. Rate-limit at the caller
     * (maybe_probe) — this method itself always runs.
     *
     * @return string One of 'ok', 'stripped', 'unreachable'.
     */
    public function probe_authorization_header() {
        // Generate a per-probe token. Route uses this to distinguish real
        // Royal MCP canary requests from arbitrary probes hitting the
        // diagnostic endpoint. Single-use: consumed by the route callback.
        $probe_id      = bin2hex( random_bytes( 16 ) );
        $probe_token   = 'royal-mcp-canary-' . $probe_id;
        set_transient( self::PROBE_TRANSIENT_KEY . $probe_id, 1, self::PROBE_TTL );

        $url      = add_query_arg(
            'probe_id',
            $probe_id,
            rest_url( 'royal-mcp/v1/diagnostics/header-echo' )
        );
        $response = wp_remote_post(
            $url,
            [
                'timeout'     => 5,
                'redirection' => 0,
                'sslverify'   => false, // Loopback — cert may not match.
                'headers'     => [
                    'Authorization' => 'Bearer ' . $probe_token,
                    'Accept'        => 'application/json',
                ],
            ]
        );

        // Always clear the probe transient — belt-and-suspenders on top of
        // the single-use consume inside the route callback.
        delete_transient( self::PROBE_TRANSIENT_KEY . $probe_id );

        if ( is_wp_error( $response ) ) {
            return 'unreachable';
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            return 'unreachable';
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || ! isset( $body['received'] ) ) {
            return 'unreachable';
        }
        return $body['received'] ? 'ok' : 'stripped';
    }

    /**
     * REST callback for /royal-mcp/v1/diagnostics/header-echo.
     *
     * Reports whether the Authorization header made it through to WordPress.
     * No actual header value returned — just presence + prefix length so the
     * caller can distinguish header-stripped from header-present-but-mangled.
     * Nonce-gated via a single-use probe_id transient, so this endpoint can't
     * be used to scan header-passthrough state without knowing the probe_id.
     *
     * @param \WP_REST_Request $request Incoming REST request.
     * @return \WP_REST_Response
     */
    public static function handle_echo_request( $request ) {
        $probe_id = (string) $request->get_param( 'probe_id' );
        if ( '' === $probe_id || ! preg_match( '/^[a-f0-9]{32}$/', $probe_id ) ) {
            return new \WP_REST_Response( [ 'error' => 'invalid_probe_id' ], 400 );
        }
        $transient_key = self::PROBE_TRANSIENT_KEY . $probe_id;
        if ( ! get_transient( $transient_key ) ) {
            return new \WP_REST_Response( [ 'error' => 'unknown_or_expired_probe_id' ], 404 );
        }
        // Consume single-use so the same probe_id can't be replayed.
        delete_transient( $transient_key );

        $seen = $request->get_header( 'Authorization' );
        return new \WP_REST_Response(
            [
                'received'    => ! empty( $seen ),
                'seen_length' => is_string( $seen ) ? strlen( $seen ) : 0,
            ],
            200
        );
    }

    /**
     * Render the notice when the canary shows the header is being stripped.
     */
    public function maybe_render_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }
        if ( get_user_meta( $user_id, self::USER_DISMISS_KEY, true ) ) {
            return;
        }
        if ( 'stripped' !== get_transient( self::TRANSIENT_KEY ) ) {
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

        $dismiss_url = wp_nonce_url(
            add_query_arg( 'royal_mcp_dismiss_auth_header', '1' ),
            'royal_mcp_dismiss_auth_header'
        );
        ?>
        <div class="notice notice-error royal-mcp-auth-header-notice">
            <p>
                <strong><?php esc_html_e( 'Royal MCP: Your web server is stripping Authorization headers before WordPress receives them.', 'royal-mcp' ); ?></strong>
            </p>
            <p>
                <?php esc_html_e( 'Every authenticated MCP request will fail with a 401 error until this is fixed. This is a web-server configuration issue, not a Royal MCP bug. Two fixes cover almost every case:', 'royal-mcp' ); ?>
            </p>
            <p>
                <strong><?php esc_html_e( 'If your host uses Apache (most shared and managed hosts):', 'royal-mcp' ); ?></strong>
                <?php esc_html_e( 'Add the following line to your site\'s .htaccess file, above the WordPress rewrite block:', 'royal-mcp' ); ?>
            </p>
            <pre style="background:#f6f7f7;padding:8px 12px;border-left:3px solid #2271b1;overflow-x:auto;"><code>SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1</code></pre>
            <p>
                <strong><?php esc_html_e( 'If your host uses nginx:', 'royal-mcp' ); ?></strong>
                <?php esc_html_e( 'Add the following to the FastCGI parameters block in your server config (requires host cooperation for managed hosts):', 'royal-mcp' ); ?>
            </p>
            <pre style="background:#f6f7f7;padding:8px 12px;border-left:3px solid #2271b1;overflow-x:auto;"><code>fastcgi_param HTTP_AUTHORIZATION $http_authorization;</code></pre>
            <p>
                <a href="<?php echo esc_url( self::SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
                    <?php esc_html_e( 'See the full fix guide', 'royal-mcp' ); ?>
                </a>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link" style="margin-left:1rem;">
                    <?php esc_html_e( 'Dismiss', 'royal-mcp' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    public function maybe_dismiss() {
        if ( ! isset( $_GET['royal_mcp_dismiss_auth_header'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'royal_mcp_dismiss_auth_header' ) ) {
            return;
        }
        update_user_meta( get_current_user_id(), self::USER_DISMISS_KEY, 1 );
        wp_safe_redirect( remove_query_arg( [ 'royal_mcp_dismiss_auth_header', '_wpnonce' ] ) );
        exit;
    }
}
