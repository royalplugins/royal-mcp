<?php
namespace Royal_MCP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Site Health tests for the things AI clients need from the web server:
 * pretty permalinks, a reachable discovery document, the Authorization
 * header reaching WordPress, and the sign-in addresses being routed.
 *
 * Each test reuses the detection the plugin's own admin notices run, and a
 * failing test links to the same support article the notice does. The three
 * tests that make a request to the site itself run asynchronously, so the
 * Site Health screen is not held up by them.
 */
class Site_Health_Tests {

    const BADGE = 'Royal MCP';

    public function __construct() {
        add_filter( 'site_status_tests', [ $this, 'register' ] );
        // Hyphenated test names: the Site Health script builds the action
        // name from the test name and only swaps the first underscore.
        add_action( 'wp_ajax_health-check-royal-mcp-well-known', [ $this, 'ajax_well_known' ] );
        add_action( 'wp_ajax_health-check-royal-mcp-authorization-header', [ $this, 'ajax_authorization_header' ] );
        add_action( 'wp_ajax_health-check-royal-mcp-sign-in-addresses', [ $this, 'ajax_sign_in_addresses' ] );
    }

    public function register( $tests ) {
        if ( ! is_array( $tests ) ) {
            $tests = [];
        }
        $tests['direct']['royal-mcp-permalinks'] = [
            'label' => __( 'Royal MCP: pretty permalinks', 'royal-mcp' ),
            'test'  => [ $this, 'test_permalinks' ],
        ];
        $tests['async']['royal-mcp-well-known'] = [
            'label'             => __( 'Royal MCP: discovery document', 'royal-mcp' ),
            'test'              => 'royal-mcp-well-known',
            'has_rest'          => false,
            'async_direct_test' => [ $this, 'test_well_known' ],
        ];
        $tests['async']['royal-mcp-authorization-header'] = [
            'label'             => __( 'Royal MCP: Authorization header', 'royal-mcp' ),
            'test'              => 'royal-mcp-authorization-header',
            'has_rest'          => false,
            'async_direct_test' => [ $this, 'test_authorization_header' ],
        ];
        $tests['async']['royal-mcp-sign-in-addresses'] = [
            'label'             => __( 'Royal MCP: sign-in addresses', 'royal-mcp' ),
            'test'              => 'royal-mcp-sign-in-addresses',
            'has_rest'          => false,
            'async_direct_test' => [ $this, 'test_sign_in_addresses' ],
        ];
        return $tests;
    }

    /* ------------------------------------------------------------------
     *  The tests
     * ----------------------------------------------------------------*/

    public function test_permalinks() {
        $slug = 'royal-mcp-permalinks';
        if ( '' === (string) get_option( 'permalink_structure' ) ) {
            return $this->result(
                $slug,
                'critical',
                __( 'Royal MCP needs pretty permalinks', 'royal-mcp' ),
                __( 'Permalinks are set to "Plain". The addresses AI clients use to find and sign in to this site only work with pretty permalinks, so no client can connect until this is changed.', 'royal-mcp' ),
                [
                    [ admin_url( 'options-permalink.php' ), __( 'Open Permalink Settings', 'royal-mcp' ) ],
                    [ Well_Known_Notice::PLAIN_PERMALINKS_SUPPORT_URL, __( 'Read the support article', 'royal-mcp' ) ],
                ]
            );
        }
        return $this->result(
            $slug,
            'good',
            __( 'Royal MCP: pretty permalinks are on', 'royal-mcp' ),
            __( 'The addresses AI clients use to find and sign in to this site can be routed.', 'royal-mcp' )
        );
    }

    public function test_well_known() {
        $slug = 'royal-mcp-well-known';
        if ( $this->plugin_off() ) {
            return $this->off_result( $slug, __( 'Royal MCP: discovery document', 'royal-mcp' ) );
        }
        $status = ( new Well_Known_Notice() )->check_well_known();
        if ( 'ok' === $status ) {
            return $this->result(
                $slug,
                'good',
                __( 'Royal MCP: the discovery document is served', 'royal-mcp' ),
                __( 'AI clients can read how to sign in to this site.', 'royal-mcp' )
            );
        }
        if ( 'unknown' === $status ) {
            return $this->result(
                $slug,
                'recommended',
                __( 'Royal MCP: the discovery document could not be checked', 'royal-mcp' ),
                __( 'The site could not fetch its own discovery document within a few seconds. This is often a temporary condition or a server that cannot reach itself; try again later.', 'royal-mcp' ),
                [ [ Well_Known_Notice::SUPPORT_URL, __( 'Read the support article', 'royal-mcp' ) ] ]
            );
        }

        $problems = [
            'blocked'                  => [ __( 'Something in front of the site answers the discovery address instead of WordPress, so AI clients cannot find out how to sign in.', 'royal-mcp' ), Well_Known_Notice::SUPPORT_URL ],
            'body_is_html'             => [ __( 'The discovery address returns a web page instead of the sign-in document, so AI clients cannot find out how to sign in.', 'royal-mcp' ), Well_Known_Notice::HTML_BODY_SUPPORT_URL ],
            'stale_static'             => [ __( 'A static copy of the discovery document on the server points AI clients at addresses that no longer exist.', 'royal-mcp' ), Well_Known_Notice::STALE_SUPPORT_URL ],
            'missing_endpoints'        => [ __( 'The discovery document served is missing addresses AI clients need to sign in.', 'royal-mcp' ), Well_Known_Notice::MISSING_ENDPOINTS_SUPPORT_URL ],
            'foreign_as'               => [ __( 'Another plugin answers the discovery address, so AI clients sign in against it instead of Royal MCP and fail.', 'royal-mcp' ), Well_Known_Notice::FOREIGN_AS_SUPPORT_URL ],
            'imunify360_blocked'       => [ __( 'Server-side bot protection answers the discovery address instead of WordPress, so AI clients cannot find out how to sign in.', 'royal-mcp' ), Well_Known_Notice::IMUNIFY360_SUPPORT_URL ],
            'bitninja_blocked'         => [ __( 'Server-side bot protection answers the discovery address instead of WordPress, so AI clients cannot find out how to sign in.', 'royal-mcp' ), Well_Known_Notice::BITNINJA_SUPPORT_URL ],
            'sucuri_cloudproxy_blocked' => [ __( 'The site\'s firewall service answers the discovery address instead of WordPress, so AI clients cannot find out how to sign in.', 'royal-mcp' ), Well_Known_Notice::SUCURI_CLOUDPROXY_SUPPORT_URL ],
            'mismatch'                 => [ __( 'The discovery document served does not match this site, so AI clients cannot find out how to sign in.', 'royal-mcp' ), Well_Known_Notice::SUPPORT_URL ],
        ];
        $problem = isset( $problems[ $status ] ) ? $problems[ $status ] : $problems['mismatch'];
        return $this->result(
            $slug,
            'critical',
            __( 'Royal MCP: the discovery document is not served correctly', 'royal-mcp' ),
            $problem[0],
            [
                [ $problem[1], __( 'Read the support article', 'royal-mcp' ) ],
                [ admin_url( 'admin.php?page=royal-mcp' ), __( 'Open Royal MCP settings', 'royal-mcp' ) ],
            ]
        );
    }

    public function test_authorization_header() {
        $slug = 'royal-mcp-authorization-header';
        if ( $this->plugin_off() ) {
            return $this->off_result( $slug, __( 'Royal MCP: Authorization header', 'royal-mcp' ) );
        }
        if ( is_multisite() && ! is_main_site() ) {
            return $this->result(
                $slug,
                'good',
                __( 'Royal MCP: Authorization header is checked on the main site', 'royal-mcp' ),
                __( 'This check runs on the main site of the network, since the web server configuration is shared.', 'royal-mcp' )
            );
        }
        $status = ( new Authorization_Header_Notice() )->status();
        if ( 'ok' === $status ) {
            return $this->result(
                $slug,
                'good',
                __( 'Royal MCP: the Authorization header reaches WordPress', 'royal-mcp' ),
                __( 'AI clients that sign in with OAuth can authenticate on this server.', 'royal-mcp' )
            );
        }
        if ( 'stripped' === $status ) {
            return $this->result(
                $slug,
                'critical',
                __( 'Royal MCP: the web server removes the Authorization header', 'royal-mcp' ),
                __( 'The Authorization header is dropped before WordPress sees it, so AI clients that sign in with OAuth are rejected on every request. This is a web server setting; the support article shows the one-line fix for Apache and the settings to check behind a proxy.', 'royal-mcp' ),
                [ [ Authorization_Header_Notice::SUPPORT_URL, __( 'Read the support article', 'royal-mcp' ) ] ]
            );
        }
        return $this->result(
            $slug,
            'recommended',
            __( 'Royal MCP: the Authorization header could not be checked', 'royal-mcp' ),
            __( 'The site could not reach its own diagnostic address within a few seconds. This is often a temporary condition or a server that cannot reach itself; try again later.', 'royal-mcp' ),
            [ [ Authorization_Header_Notice::SUPPORT_URL, __( 'Read the support article', 'royal-mcp' ) ] ]
        );
    }

    public function test_sign_in_addresses() {
        $slug = 'royal-mcp-sign-in-addresses';
        if ( $this->plugin_off() ) {
            return $this->off_result( $slug, __( 'Royal MCP: sign-in addresses', 'royal-mcp' ) );
        }
        if ( '' === (string) get_option( 'permalink_structure' ) ) {
            return $this->result(
                $slug,
                'recommended',
                __( 'Royal MCP: sign-in addresses cannot be checked yet', 'royal-mcp' ),
                __( 'Pretty permalinks are off, so the sign-in addresses cannot be routed. Fix the permalinks check first.', 'royal-mcp' ),
                [ [ admin_url( 'options-permalink.php' ), __( 'Open Permalink Settings', 'royal-mcp' ) ] ]
            );
        }
        $actions = [
            [ admin_url( 'options-permalink.php' ), __( 'Open Permalink Settings and click Save Changes', 'royal-mcp' ) ],
            [ Well_Known_Notice::SUPPORT_URL, __( 'Read the support article', 'royal-mcp' ) ],
        ];
        if ( ! \Royal_MCP_Plugin::has_oauth_rewrite_rule( 'token' ) ) {
            return $this->result(
                $slug,
                'critical',
                __( 'Royal MCP: the sign-in addresses are not routed', 'royal-mcp' ),
                __( 'The site\'s stored URL rules do not include the sign-in addresses, so AI clients get a "page not found" when they try to sign in. Saving the permalink settings rebuilds the rules.', 'royal-mcp' ),
                $actions
            );
        }

        $code = $this->probe_token_address();
        if ( 204 === $code || 400 === $code ) {
            return $this->result(
                $slug,
                'good',
                __( 'Royal MCP: the sign-in addresses are reachable', 'royal-mcp' ),
                __( 'Requests to the sign-in address reach Royal MCP.', 'royal-mcp' )
            );
        }
        if ( 404 === $code ) {
            return $this->result(
                $slug,
                'critical',
                __( 'Royal MCP: the sign-in address answers "page not found"', 'royal-mcp' ),
                __( 'A request to the sign-in address did not reach Royal MCP. Saving the permalink settings rebuilds the site\'s URL rules; if that does not help, the support article covers server rules that can intercept the address.', 'royal-mcp' ),
                $actions
            );
        }
        if ( in_array( $code, [ 301, 302, 307, 308 ], true ) ) {
            return $this->result(
                $slug,
                'critical',
                __( 'Royal MCP: the web server redirects sign-in requests', 'royal-mcp' ),
                __( 'The web server answers the sign-in address with a redirect. AI clients do not follow redirects when signing in, so the sign-in fails before it reaches Royal MCP.', 'royal-mcp' ),
                [ [ Well_Known_Notice::REGISTER_301_SUPPORT_URL, __( 'Read the support article', 'royal-mcp' ) ] ]
            );
        }
        return $this->result(
            $slug,
            'recommended',
            __( 'Royal MCP: the sign-in addresses could not be checked', 'royal-mcp' ),
            0 === $code
                ? __( 'The site could not reach its own sign-in address within a few seconds. This is often a temporary condition or a server that cannot reach itself; try again later.', 'royal-mcp' )
                /* translators: %d: HTTP status code */
                : sprintf( __( 'A request to the sign-in address was answered with HTTP status %d, which is not what Royal MCP sends. Something between the web server and WordPress may be answering it.', 'royal-mcp' ), $code ),
            [ [ Well_Known_Notice::SUPPORT_URL, __( 'Read the support article', 'royal-mcp' ) ] ]
        );
    }

    /**
     * POST to the token address the way a client would. Royal MCP answers its
     * own self-check requests with 204 and nothing else, so no sign-in state
     * or activity-log row results.
     *
     * @return int HTTP status, 0 when the request failed.
     */
    private function probe_token_address() {
        $paths = \Royal_MCP_Plugin::get_oauth_rewrite_paths();
        $slug  = isset( $paths['token'] ) ? ltrim( trim( (string) $paths['token'] ), '/' ) : 'token';
        $response = wp_remote_post(
            home_url( '/' . $slug ),
            [
                'timeout'     => 5,
                'redirection' => 0,
                'sslverify'   => false,
                'user-agent'  => 'Royal MCP Self-Check',
                'body'        => [],
            ]
        );
        return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
    }

    /* ------------------------------------------------------------------
     *  AJAX entry points the Site Health screen calls for the async tests
     * ----------------------------------------------------------------*/

    public function ajax_well_known() {
        check_ajax_referer( 'health-check-site-status' );
        if ( ! current_user_can( 'view_site_health_checks' ) ) {
            wp_send_json_error();
        }
        wp_send_json_success( $this->test_well_known() );
    }

    public function ajax_authorization_header() {
        check_ajax_referer( 'health-check-site-status' );
        if ( ! current_user_can( 'view_site_health_checks' ) ) {
            wp_send_json_error();
        }
        wp_send_json_success( $this->test_authorization_header() );
    }

    public function ajax_sign_in_addresses() {
        check_ajax_referer( 'health-check-site-status' );
        if ( ! current_user_can( 'view_site_health_checks' ) ) {
            wp_send_json_error();
        }
        wp_send_json_success( $this->test_sign_in_addresses() );
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    private function plugin_off() {
        $settings = get_option( 'royal_mcp_settings', [] );
        return ! is_array( $settings ) || empty( $settings['enabled'] );
    }

    private function off_result( $slug, $label ) {
        return $this->result(
            $slug,
            'good',
            $label,
            __( 'Royal MCP is switched off in its settings, so this check was not run.', 'royal-mcp' ),
            [ [ admin_url( 'admin.php?page=royal-mcp' ), __( 'Open Royal MCP settings', 'royal-mcp' ) ] ]
        );
    }

    /**
     * A Site Health result in the shape core expects.
     *
     * @param string $slug        Test name.
     * @param string $status      good | recommended | critical.
     * @param string $label       Heading shown on the Site Health screen.
     * @param string $description Plain-text explanation.
     * @param array  $links       Each [ url, text ], shown as action links.
     */
    private function result( $slug, $status, $label, $description, array $links = [] ) {
        $colors  = [ 'good' => 'blue', 'recommended' => 'orange', 'critical' => 'red' ];
        $actions = '';
        foreach ( $links as $link ) {
            $actions .= sprintf(
                '<p><a href="%s"%s>%s</a></p>',
                esc_url( $link[0] ),
                0 === strpos( (string) $link[0], 'https://royalplugins.com/' ) ? ' target="_blank" rel="noopener noreferrer"' : '',
                esc_html( $link[1] )
            );
        }
        return [
            'label'       => $label,
            'status'      => $status,
            'badge'       => [
                'label' => self::BADGE,
                'color' => isset( $colors[ $status ] ) ? $colors[ $status ] : 'blue',
            ],
            'description' => '<p>' . esc_html( $description ) . '</p>',
            'actions'     => $actions,
            'test'        => $slug,
        ];
    }
}
