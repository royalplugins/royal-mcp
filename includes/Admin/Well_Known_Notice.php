<?php
namespace Royal_MCP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detects when the host is blocking /.well-known/oauth-authorization-server
 * (typically because the web server has reserved the .well-known/ path prefix
 * for its own use, e.g. ACME) and surfaces an admin notice linking to the fix.
 */
class Well_Known_Notice {

    const TRANSIENT_KEY                = 'royal_mcp_well_known_status';
    const TRANSIENT_TTL                = 12 * HOUR_IN_SECONDS;
    // Adaptive TTL for WAF-fronted sites — state changes (allowlist adds,
    // exclusion rules) need to surface faster during active troubleshooting.
    const TRANSIENT_TTL_WAF            = 1 * HOUR_IN_SECONDS;
    const USER_DISMISS_KEY                 = 'royal_mcp_well_known_dismissed';
    const STALE_DISMISS_KEY                = 'royal_mcp_well_known_stale_dismissed';
    const HTML_BODY_DISMISS_KEY            = 'royal_mcp_well_known_html_body_dismissed';
    const REGISTER_301_TRANSIENT           = 'royal_mcp_register_301_status';
    const REGISTER_301_DISMISS_KEY         = 'royal_mcp_register_301_dismissed';
    const IMUNIFY360_DISMISS_KEY           = 'royal_mcp_imunify360_dismissed';
    const BITNINJA_DISMISS_KEY             = 'royal_mcp_bitninja_dismissed';
    const SUCURI_CLOUDPROXY_DISMISS_KEY    = 'royal_mcp_sucuri_cloudproxy_dismissed';
    const PLAIN_PERMALINKS_DISMISS_KEY     = 'royal_mcp_plain_permalinks_dismissed';
    const PAGE_SHADOW_DISMISS_KEY          = 'royal_mcp_oauth_page_shadow_dismissed';
    const MISSING_ENDPOINTS_TRANSIENT      = 'royal_mcp_missing_endpoints_list';
    const MISSING_ENDPOINTS_DISMISS_KEY    = 'royal_mcp_well_known_missing_endpoints_dismissed';
    const RECHECK_ACTION                   = 'royal_mcp_recheck_well_known';
    const RECHECK_NONCE                    = 'royal_mcp_recheck_well_known_nonce';
    const RECHECK_JUST_RAN_META_KEY        = 'royal_mcp_recheck_just_ran';
    const SUPPORT_URL                      = 'https://royalplugins.com/support/royal-mcp/siteground-well-known-404.html';
    const STALE_SUPPORT_URL                = 'https://royalplugins.com/support/royal-mcp/stale-well-known-static-files.html';
    const HTML_BODY_SUPPORT_URL            = 'https://royalplugins.com/support/royal-mcp/well-known-served-as-html.html';
    const REGISTER_301_SUPPORT_URL         = 'https://royalplugins.com/support/royal-mcp/oauth-register-trailing-slash-301.html';
    const IMUNIFY360_SUPPORT_URL           = 'https://royalplugins.com/support/royal-mcp/imunify360-blocks-mcp.html';
    const BITNINJA_SUPPORT_URL             = 'https://royalplugins.com/support/royal-mcp/bitninja-webshield-blocks-mcp.html';
    const SUCURI_CLOUDPROXY_SUPPORT_URL    = 'https://royalplugins.com/support/royal-mcp/sucuri-cloudproxy-blocks-mcp.html';
    const PLAIN_PERMALINKS_SUPPORT_URL     = 'https://royalplugins.com/support/royal-mcp/plain-permalinks-blocks-discovery.html';
    const PAGE_SHADOW_SUPPORT_URL          = 'https://royalplugins.com/support/royal-mcp/oauth-page-shadow.html';
    const MISSING_ENDPOINTS_SUPPORT_URL    = 'https://royalplugins.com/support/royal-mcp/oauth-discovery-missing-endpoints.html';

    public function __construct() {
        add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
        add_action( 'admin_init', [ $this, 'maybe_dismiss' ] );
        add_action( 'update_option_royal_mcp_settings', [ $this, 'invalidate_check' ] );
        // changing permalink structure changes whether OAuth
        // discovery routes work at all (plain permalinks skip our rewrites).
        // Drop cached classification so the notice reflects the new state
        // immediately after an admin changes Settings → Permalinks.
        add_action( 'update_option_permalink_structure', [ $this, 'invalidate_check' ] );
        // Re-check button on any host-blocked notice invalidates the transient
        // and re-probes in the same request. Removes the 12h wait when an
        // admin is actively troubleshooting host/WAF exclusion changes.
        add_action( 'admin_post_' . self::RECHECK_ACTION, [ $this, 'handle_recheck' ] );
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

        // Plain-permalinks gate runs BEFORE the network probe. If WordPress
        // is on Plain permalinks, our OAuth rewrite rules never fire and
        // every downstream classification would misdiagnose the 404 as a
        // host-level block. Pure get_option() check — cheapest possible
        // early exit before any HTTP.
        if ( '' === (string) get_option( 'permalink_structure', '' )
            && ! get_user_meta( $user_id, self::PLAIN_PERMALINKS_DISMISS_KEY, true )
        ) {
            $this->render_plain_permalinks_notice();
            return;
        }

        $status = $this->check_well_known();

        // Sucuri/CloudProxy fires BEFORE generic 'blocked' because it needs
        // Sucuri-specific fix guidance (edge-CDN allowlist / IDS exclusion),
        // not host path-reservation. Signature is the Server header alone —
        // Sucuri's edge 404 body carries no branding, so body-content match
        // silently fails. Header match is the only reliable Sucuri tell.
        if ( 'sucuri_cloudproxy_blocked' === $status
            && ! get_user_meta( $user_id, self::SUCURI_CLOUDPROXY_DISMISS_KEY, true )
        ) {
            $this->render_sucuri_cloudproxy_notice();
            return;
        }

        // Imunify360 fires BEFORE 'blocked' because the misdiagnosis cost
        // is high: 'blocked' guides the admin toward host path-reservation
        // adjustments, but Imunify360 needs a completely different allowlist
        // request (bot-protection Ignore list, not path reservation).
        if ( 'imunify360_blocked' === $status
            && ! get_user_meta( $user_id, self::IMUNIFY360_DISMISS_KEY, true )
        ) {
            $this->render_imunify360_notice();
            return;
        }

        // BitNinja WebShield is a host-level bot-protection product that
        // stacks on top of Imunify360 on many CloudLinux/cPanel installs.
        // It serves a JavaScript CAPTCHA challenge for GET requests to
        // /.well-known/*, /authorize, /register — the OAuth discovery paths.
        // MCP clients can't execute JS, so the handshake dies at discovery.
        // Fires before 'body_is_html' because the BitNinja challenge starts
        // with an HTML doctype that would otherwise route into the generic
        // membership-plugin classifier and produce the wrong fix guidance.
        if ( 'bitninja_blocked' === $status
            && ! get_user_meta( $user_id, self::BITNINJA_DISMISS_KEY, true )
        ) {
            $this->render_bitninja_notice();
            return;
        }

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
            return;
        }

        if ( 'missing_endpoints' === $status
            && ! get_user_meta( $user_id, self::MISSING_ENDPOINTS_DISMISS_KEY, true )
        ) {
            $this->render_missing_endpoints_notice();
            return;
        }

        if ( 'body_is_html' === $status
            && ! get_user_meta( $user_id, self::HTML_BODY_DISMISS_KEY, true )
        ) {
            $this->render_html_body_notice();
            return;
        }

        // Second self-check — POST /register to detect host-side trailing-slash 301.
        // OAuth POSTs don't follow 301 so claude.ai's /register call dies pre-PHP on
        // hosts with default canonicalization rules. Distinct from the well-known
        // probe above (different URL, different method, different failure mode).
        if ( $this->check_register_301()
            && ! get_user_meta( $user_id, self::REGISTER_301_DISMISS_KEY, true )
        ) {
            $this->render_register_301_notice();
        }

        // Third self-check — is a published page at slug 'authorize', 'token',
        // or 'register' being shadowed by our rewrite rules? Common on
        // membership sites (MemberPress, Paid Memberships Pro, etc.) that
        // default to /register. The method-filter fix (option_rewrite_rules)
        // handles GET/HEAD collision for the visitor-facing case, but this
        // notice tells the admin so they know why the plugin looks in charge
        // of a page they created.
        $shadowed = $this->check_oauth_page_shadow();
        if ( ! empty( $shadowed )
            && ! get_user_meta( $user_id, self::PAGE_SHADOW_DISMISS_KEY, true )
        ) {
            $this->render_oauth_page_shadow_notice( $shadowed );
        }
    }

    /**
     * Detect published pages at OAuth endpoint slugs. Returns an array
     * keyed by action (authorize/token/register) with the shadowed page's
     * ID + title, or empty array when no collision.
     */
    public function check_oauth_page_shadow() {
        $paths = \Royal_MCP_Plugin::get_oauth_rewrite_paths();
        $shadowed = [];
        foreach ( $paths as $action => $slug ) {
            $slug = ltrim( trim( (string) $slug ), '/' );
            // Skip nested paths — those can't collide with a top-level page slug.
            if ( $slug === '' || strpos( $slug, '/' ) !== false ) {
                continue;
            }
            $page = get_page_by_path( $slug );
            if ( $page instanceof \WP_Post && $page->post_status === 'publish' ) {
                $shadowed[ $action ] = [
                    'slug'    => $slug,
                    'page_id' => (int) $page->ID,
                    'title'   => (string) $page->post_title,
                ];
            }
        }
        return $shadowed;
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
     *                    (/wp-json/royal-mcp/v1/...) — leftover static file from an earlier layout
     *  - body_is_html  : status 200 but body is an HTML document — a membership plugin or
     *                    theme template intercepted the request (e.g. a login page)
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

        // WAF-signature-driven adaptive TTL — sites behind an edge CDN see
        // faster re-probes so allowlist / exclusion changes surface within
        // an hour instead of half a day. Static self-hosted installs keep
        // the 12h default to avoid unnecessary loopback probes.
        $ttl = self::TRANSIENT_TTL;

        if ( is_wp_error( $response ) ) {
            $status = 'unknown';
        } else {
            $code    = (int) wp_remote_retrieve_response_code( $response );
            $body    = (string) wp_remote_retrieve_body( $response );
            $headers = wp_remote_retrieve_headers( $response );
            $headers = is_array( $headers ) ? $headers : iterator_to_array( $headers );
            $status  = self::classify_response( $code, $body, $headers, rtrim( home_url(), '/' ) );
            $ttl     = self::determine_ttl( $headers );
        }

        set_transient( self::TRANSIENT_KEY, $status, $ttl );

        return $status;
    }

    /**
     * Adaptive TTL based on WAF/edge-CDN signature in response headers.
     * Shorter TTL for WAF-fronted sites so that host-layer exclusion changes
     * surface faster during active troubleshooting. Public static for
     * testability.
     *
     * @param array $headers Response headers (key-value array; keys are
     *                       case-insensitive per HTTP spec but stored
     *                       lowercase by wp_remote_retrieve_headers).
     * @return int TTL in seconds.
     */
    public static function determine_ttl( array $headers ) {
        return self::is_waf_signature_header( $headers )
            ? self::TRANSIENT_TTL_WAF
            : self::TRANSIENT_TTL;
    }

    /**
     * True if response headers carry a known WAF / edge-CDN fingerprint.
     * Case-insensitive header-name + value matching. Public static for
     * testability + shared reuse across TTL decision + classifier hints.
     *
     * @param array $headers Response headers.
     * @return bool
     */
    public static function is_waf_signature_header( array $headers ) {
        // Normalize header keys to lowercase for reliable lookup — WP's
        // wp_remote_retrieve_headers returns a Requests_Utility_CaseInsensitiveDictionary
        // or array with lowercase keys, but we accept any casing when
        // called from tests.
        $lookup = [];
        foreach ( $headers as $k => $v ) {
            $lookup[ strtolower( (string) $k ) ] = is_array( $v ) ? implode( ',', $v ) : (string) $v;
        }

        $server = isset( $lookup['server'] ) ? strtolower( (string) $lookup['server'] ) : '';
        if ( '' !== $server ) {
            // Sucuri / CloudProxy — SG fleet-default, extremely common.
            if ( false !== strpos( $server, 'sucuri' ) || false !== strpos( $server, 'cloudproxy' ) ) {
                return true;
            }
            // Cloudflare — includes both free tier and Enterprise. If Cloudflare
            // is fronting the site and returned a 404, allowlist changes need
            // to propagate; short TTL reflects that.
            if ( false !== strpos( $server, 'cloudflare' ) ) {
                return true;
            }
        }

        // Cloudflare also sets these regardless of Server header contents.
        if ( isset( $lookup['cf-ray'] ) || isset( $lookup['cf-cache-status'] ) ) {
            return true;
        }

        return false;
    }

    /**
     * True if response headers indicate the response came from PHP (either
     * via x-httpd or x-powered-by: PHP*). Used by 404 classifier to
     * distinguish "PHP served the 404" (plugin problem, not host) from
     * "host served the 404 pre-PHP" (host allowlist required).
     *
     * @param array $headers Response headers.
     * @return bool
     */
    public static function has_php_fingerprint( array $headers ) {
        $lookup = [];
        foreach ( $headers as $k => $v ) {
            $lookup[ strtolower( (string) $k ) ] = is_array( $v ) ? implode( ',', $v ) : (string) $v;
        }
        if ( ! empty( $lookup['x-httpd'] ) ) {
            return true;
        }
        $powered = isset( $lookup['x-powered-by'] ) ? strtolower( (string) $lookup['x-powered-by'] ) : '';
        if ( '' !== $powered && false !== strpos( $powered, 'php' ) ) {
            return true;
        }
        return false;
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
            // BitNinja WebShield JS challenge — host-level bot protection that
            // returns HTTP 200 with an obfuscated JS interstitial ("One moment,
            // please..." title, auto-reload after 5s). Runs BEFORE the generic
            // HTML detection because the response IS HTML but the fix guidance
            // is completely different (host must disable WebShield for OAuth
            // paths — no in-plugin remedy). Signature is two markers that
            // co-occur only in WebShield's obfuscated JS: the wsidchk form
            // parameter and the webdriverCheck fingerprinting function name.
            if ( false !== stripos( $body, 'wsidchk' )
                && false !== strpos( $body, 'webdriverCheck' )
            ) {
                return 'bitninja_blocked';
            }

            // Body-is-HTML detection — a membership plugin or theme template intercepted
            // the request after rewrite resolution and served its own HTML (e.g. a membership plugin
            // login page, MemberPress access-denied template). Discovery clients that
            // strictly require JSON metadata fail silently here. Anchor checks at
            // position 0 so a valid JSON body containing `<html>` as a string value
            // doesn't false-positive.
            $body_head = strtolower( ltrim( $body ) );
            $html_prefixes = [ '<!doctype html', '<html', '<head', '<?xml' ];
            foreach ( $html_prefixes as $prefix ) {
                if ( 0 === strpos( $body_head, $prefix ) ) {
                    return 'body_is_html';
                }
            }

            $data = json_decode( $body, true );

            // Imunify360 bot-protection (CloudLinux, common on shared
            // cPanel hosts) intercepts /.well-known/* and /wp-json/* BEFORE PHP
            // runs and returns HTTP 200 with a JSON denial body containing a
            // "message" key. Distinct from 'mismatch' (which is a semantic-issuer
            // problem) — the host is intercepting pre-PHP so no plugin setting
            // can fix it; site admins must ask their host to allowlist the paths.
            // Broad prefix match on "Imunify360" (case-insensitive) — the
            // denial-message copy has drifted across versions but always
            // contains the product name.
            if ( is_array( $data )
                && isset( $data['message'] )
                && false !== stripos( (string) $data['message'], 'Imunify360' )
            ) {
                return 'imunify360_blocked';
            }

            if ( ! is_array( $data ) || empty( $data['issuer'] ) ) {
                return 'mismatch';
            }

            $issuer_ok = rtrim( $data['issuer'], '/' ) === $expected_issuer;

            // Stale-static detection: earlier layouts advertised REST-namespace
            // OAuth endpoints (/wp-json/royal-mcp/v1/authorize). Current code serves
            // them at root (/authorize). If a stale file is still on disk with old
            // paths, discovery clients follow the bad URL and 404.
            // Runs BEFORE missing-endpoints so a file that's both stale AND
            // missing keys surfaces as stale_static (delete-and-let-PHP-serve
            // is the stronger fix).
            $endpoints = [
                $data['authorization_endpoint'] ?? '',
                $data['token_endpoint']         ?? '',
                $data['registration_endpoint']  ?? '',
            ];
            foreach ( $endpoints as $endpoint ) {
                if ( is_string( $endpoint ) && '' !== $endpoint && false !== strpos( $endpoint, '/wp-json/royal-mcp/v1/' ) ) {
                    return 'stale_static';
                }
            }

            // Missing-endpoints detection: a hand-authored static file that
            // omits one of the required RFC 8414 / RFC 7591 endpoint keys
            // leaves discovery clients with no URL to reach that leg of the
            // flow (DCR fails silently when registration_endpoint is absent,
            // authorize fails silently when authorization_endpoint is absent).
            // Cache the specific missing key list in a companion transient so
            // the renderer can name them without re-parsing the response.
            $required = [
                'authorization_endpoint',
                'token_endpoint',
                'registration_endpoint',
            ];
            $missing = [];
            foreach ( $required as $key ) {
                if ( empty( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
                    $missing[] = $key;
                }
            }
            if ( ! empty( $missing ) ) {
                set_transient( self::MISSING_ENDPOINTS_TRANSIENT, $missing, self::TRANSIENT_TTL );
                return 'missing_endpoints';
            }

            return $issuer_ok ? 'ok' : 'mismatch';
        }

        if ( 404 === $code ) {
            // PHP served the 404 → this is a plugin/route problem, not a
            // host-layer block. Bail early so we don't misdiagnose.
            if ( self::has_php_fingerprint( $headers ) ) {
                return 'unknown';
            }

            // Sucuri / CloudProxy Server header — reliable Sucuri tell.
            // Their edge 404 body carries NO Sucuri branding (unbranded
            // ~80KB HTML template), so body-content match fails. Header
            // match is the only signal that survives.
            $lookup = [];
            foreach ( $headers as $k => $v ) {
                $lookup[ strtolower( (string) $k ) ] = is_array( $v ) ? implode( ',', $v ) : (string) $v;
            }
            $server = isset( $lookup['server'] ) ? strtolower( (string) $lookup['server'] ) : '';
            if ( '' !== $server
                && ( false !== strpos( $server, 'sucuri' )
                    || false !== strpos( $server, 'cloudproxy' ) )
            ) {
                return 'sucuri_cloudproxy_blocked';
            }

            // Generic HTML 404 with no PHP fingerprint → edge/WAF 404 template
            // regardless of body size. Sucuri's 83KB body used to fall through
            // to 'unknown' under the old size-based rule; the fix is to trust
            // the content-type + no-PHP-fingerprint signal alone.
            $content_type = isset( $lookup['content-type'] ) ? strtolower( (string) $lookup['content-type'] ) : '';
            $is_html      = false !== strpos( $content_type, 'text/html' );
            if ( $is_html ) {
                return 'blocked';
            }

            // Tiny-body-no-PHP path preserved as one satisfying case for
            // hosts that return bare nginx / minimal-template 404s without
            // a content-type header (rare but observed on custom-configured
            // edge servers).
            $is_tiny_body = strlen( $body ) < 500;
            if ( $is_tiny_body ) {
                return 'blocked';
            }

            return 'unknown';
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
        delete_transient( self::REGISTER_301_TRANSIENT );
    }

    /**
     * Probe POST /register and detect host-side trailing-slash 301.
     *
     * Self-hosted Nginx, Apache mod_dir, and .htaccess-based hosts often emit 301
     * on any non-file path that lacks a trailing slash — including /register,
     * /authorize, /token. OAuth clients don't follow 301 on POST, so the request
     * dies pre-PHP. claude.ai web hardcodes the bare path /register and ignores
     * registration_endpoint in our discovery doc, so we can't route around this
     * via metadata. Detection lets admins see the host-level config issue
     * without piecing it together from a "couldn't reach the MCP server" error.
     *
     * Returns true when /register returns a 301 Location pointing at /register/.
     */
    public function check_register_301() {
        $cached = get_transient( self::REGISTER_301_TRANSIENT );
        if ( false !== $cached ) {
            return 'redirect' === $cached;
        }

        $url = home_url( '/register' );

        // POST with no body — the response we care about is whatever happens
        // before the body parses (status code + Location header). Don't follow
        // redirects; the whole point is to observe the 301 ourselves.
        $response = wp_remote_post(
            $url,
            [
                'timeout'     => 5,
                'redirection' => 0,
                'sslverify'   => true,
                'user-agent'  => 'Royal MCP Self-Check',
                'headers'     => [ 'Content-Type' => 'application/json' ],
                'body'        => '{}',
            ]
        );

        $status = 'ok';
        if ( ! is_wp_error( $response ) ) {
            $code     = (int) wp_remote_retrieve_response_code( $response );
            $location = (string) wp_remote_retrieve_header( $response, 'location' );
            if ( 301 === $code && '' !== $location ) {
                $location_path = (string) wp_parse_url( $location, PHP_URL_PATH );
                if ( '/register/' === $location_path ) {
                    $status = 'redirect';
                }
            }
        }

        set_transient( self::REGISTER_301_TRANSIENT, $status, self::TRANSIENT_TTL );

        return 'redirect' === $status;
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

        if ( isset( $_GET['royal_mcp_dismiss_missing_endpoints'] )
            && isset( $_GET['_wpnonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'royal_mcp_dismiss_missing_endpoints' )
        ) {
            update_user_meta( get_current_user_id(), self::MISSING_ENDPOINTS_DISMISS_KEY, time() );
            wp_safe_redirect( remove_query_arg( [ 'royal_mcp_dismiss_missing_endpoints', '_wpnonce' ] ) );
            exit;
        }

        if ( isset( $_GET['royal_mcp_dismiss_html_body'] )
            && isset( $_GET['_wpnonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'royal_mcp_dismiss_html_body' )
        ) {
            update_user_meta( get_current_user_id(), self::HTML_BODY_DISMISS_KEY, time() );
            wp_safe_redirect( remove_query_arg( [ 'royal_mcp_dismiss_html_body', '_wpnonce' ] ) );
            exit;
        }

        if ( isset( $_GET['royal_mcp_dismiss_register_301'] )
            && isset( $_GET['_wpnonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'royal_mcp_dismiss_register_301' )
        ) {
            update_user_meta( get_current_user_id(), self::REGISTER_301_DISMISS_KEY, time() );
            wp_safe_redirect( remove_query_arg( [ 'royal_mcp_dismiss_register_301', '_wpnonce' ] ) );
            exit;
        }

        if ( isset( $_GET['royal_mcp_dismiss_page_shadow'] )
            && isset( $_GET['_wpnonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'royal_mcp_dismiss_page_shadow' )
        ) {
            update_user_meta( get_current_user_id(), self::PAGE_SHADOW_DISMISS_KEY, time() );
            wp_safe_redirect( remove_query_arg( [ 'royal_mcp_dismiss_page_shadow', '_wpnonce' ] ) );
            exit;
        }

        if ( isset( $_GET['royal_mcp_dismiss_imunify360'] )
            && isset( $_GET['_wpnonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'royal_mcp_dismiss_imunify360' )
        ) {
            update_user_meta( get_current_user_id(), self::IMUNIFY360_DISMISS_KEY, time() );
            wp_safe_redirect( remove_query_arg( [ 'royal_mcp_dismiss_imunify360', '_wpnonce' ] ) );
            exit;
        }

        if ( isset( $_GET['royal_mcp_dismiss_bitninja'] )
            && isset( $_GET['_wpnonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'royal_mcp_dismiss_bitninja' )
        ) {
            update_user_meta( get_current_user_id(), self::BITNINJA_DISMISS_KEY, time() );
            wp_safe_redirect( remove_query_arg( [ 'royal_mcp_dismiss_bitninja', '_wpnonce' ] ) );
            exit;
        }

        if ( isset( $_GET['royal_mcp_dismiss_plain_permalinks'] )
            && isset( $_GET['_wpnonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'royal_mcp_dismiss_plain_permalinks' )
        ) {
            update_user_meta( get_current_user_id(), self::PLAIN_PERMALINKS_DISMISS_KEY, time() );
            wp_safe_redirect( remove_query_arg( [ 'royal_mcp_dismiss_plain_permalinks', '_wpnonce' ] ) );
            exit;
        }

        if ( isset( $_GET['royal_mcp_dismiss_sucuri_cloudproxy'] )
            && isset( $_GET['_wpnonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'royal_mcp_dismiss_sucuri_cloudproxy' )
        ) {
            update_user_meta( get_current_user_id(), self::SUCURI_CLOUDPROXY_DISMISS_KEY, time() );
            wp_safe_redirect( remove_query_arg( [ 'royal_mcp_dismiss_sucuri_cloudproxy', '_wpnonce' ] ) );
            exit;
        }
    }

    /**
     * admin_post handler for the "Re-check now" button. Cap + nonce gated.
     * Drops the classification transient, re-probes in-request, and stores
     * the new status on user meta so the redirected admin_notices pass can
     * render a "just re-checked — status: X" toast alongside whatever notice
     * matches the fresh state.
     */
    public function handle_recheck() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die(
                esc_html__( 'You do not have permission to re-check OAuth discovery.', 'royal-mcp' ),
                '',
                [ 'response' => 403 ]
            );
        }
        check_admin_referer( self::RECHECK_NONCE );

        $this->invalidate_check();
        $status = $this->check_well_known();

        update_user_meta(
            get_current_user_id(),
            self::RECHECK_JUST_RAN_META_KEY,
            [ 'status' => (string) $status, 'at' => time() ]
        );

        $redirect = wp_get_referer();
        if ( ! $redirect ) {
            $redirect = admin_url();
        }
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Emit the "Re-check now" button as a self-contained POST form. Uses
     * admin-post with cap + nonce to avoid a GET-based state-mutation
     * pattern. Shown inside every host-blocked notice so admins can re-probe
     * from wherever they see the problem.
     */
    private function render_recheck_button() {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:0.5rem;">
            <input type="hidden" name="action" value="<?php echo esc_attr( self::RECHECK_ACTION ); ?>" />
            <?php wp_nonce_field( self::RECHECK_NONCE ); ?>
            <button type="submit" class="button">
                <?php esc_html_e( 'Re-check now', 'royal-mcp' ); ?>
            </button>
        </form>
        <?php
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
                <?php $this->render_recheck_button(); ?>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link" style="margin-left: 1rem;">
                    <?php esc_html_e( 'Dismiss', 'royal-mcp' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Sucuri / CloudProxy edge-CDN-level 404. Names the product so the
     * admin knows the fix is a Sucuri firewall configuration change (allow
     * URL paths / IDS exclusion), not a host path-reservation.
     */
    private function render_sucuri_cloudproxy_notice() {
        $dismiss_url = wp_nonce_url(
            add_query_arg( 'royal_mcp_dismiss_sucuri_cloudproxy', '1' ),
            'royal_mcp_dismiss_sucuri_cloudproxy'
        );

        ?>
        <div class="notice notice-warning royal-mcp-sucuri-cloudproxy-notice">
            <p>
                <strong><?php esc_html_e( 'Royal MCP: OAuth discovery is being blocked at the Sucuri firewall edge.', 'royal-mcp' ); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: 1: literal URL path code, 2: literal URL path code */
                    esc_html__( 'Sucuri / CloudProxy is intercepting %1$s and returning a 404 before your site receives the request. Claude.ai and other MCP clients will fail to connect until Sucuri allows this path through. This is an edge-CDN configuration change, not a WordPress or host-server setting.', 'royal-mcp' ),
                    '<code>/.well-known/oauth-authorization-server</code>',
                    '<code>/wp-json/*</code>'
                );
                ?>
            </p>
            <p>
                <?php esc_html_e( 'In your Sucuri firewall dashboard, add these paths to Allow URL Paths (or ask Sucuri support to allowlist them):', 'royal-mcp' ); ?>
                <code>/.well-known/*</code>, <code>/authorize</code>, <code>/token</code>, <code>/register</code>.
            </p>
            <p>
                <em><?php esc_html_e( 'Sucuri\'s "Allow URL Paths" is a bypass rule (allows the path from any IP, still subject to IDS signatures), not an intersection. Path-level allowlist is preferred over IP allowlist because MCP client IPs rotate.', 'royal-mcp' ); ?></em>
            </p>
            <p>
                <a href="<?php echo esc_url( self::SUCURI_CLOUDPROXY_SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
                    <?php esc_html_e( 'See the Sucuri fix guide', 'royal-mcp' ); ?>
                </a>
                <?php $this->render_recheck_button(); ?>
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
                <?php $this->render_recheck_button(); ?>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link" style="margin-left: 1rem;">
                    <?php esc_html_e( 'Dismiss', 'royal-mcp' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    private function render_missing_endpoints_notice() {
        $dismiss_url = wp_nonce_url(
            add_query_arg( 'royal_mcp_dismiss_missing_endpoints', '1' ),
            'royal_mcp_dismiss_missing_endpoints'
        );
        $missing = get_transient( self::MISSING_ENDPOINTS_TRANSIENT );
        if ( ! is_array( $missing ) || empty( $missing ) ) {
            $missing = [ 'authorization_endpoint', 'token_endpoint', 'registration_endpoint' ];
        }
        $missing_pretty = implode( ', ', array_map(
            function ( $k ) { return '<code>' . esc_html( $k ) . '</code>'; },
            $missing
        ) );
        $issuer = rtrim( (string) home_url(), '/' );

        ?>
        <div class="notice notice-error royal-mcp-missing-endpoints-notice">
            <p>
                <strong><?php esc_html_e( 'Royal MCP: your OAuth discovery file is missing required fields.', 'royal-mcp' ); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: 1: discovery URL, 2: comma-separated list of missing field code tags */
                    wp_kses(
                        __( 'Your %1$s file is being served but is missing the following required field(s): %2$s. Discovery clients like Claude.ai read this file, find no URL to reach these endpoints, and fail to connect silently.', 'royal-mcp' ),
                        [ 'code' => [] ]
                    ),
                    '<code>/.well-known/oauth-authorization-server</code>',
                    $missing_pretty // already HTML-safe (values escaped in closure above)
                );
                ?>
            </p>
            <p>
                <strong><?php esc_html_e( 'Preferred fix — delete the static file so Royal MCP serves fresh, complete metadata from PHP automatically:', 'royal-mcp' ); ?></strong><br>
                <code>rm /path/to/your/webroot/.well-known/oauth-authorization-server</code>
            </p>
            <p>
                <?php esc_html_e( 'If your host reserves the .well-known/ path prefix and you must keep the file static, it must include all three required endpoint URLs. Use this template (substitute your domain):', 'royal-mcp' ); ?>
            </p>
            <pre><code><?php
                $template_json = [
                    'issuer'                 => $issuer,
                    'authorization_endpoint' => $issuer . '/authorize',
                    'token_endpoint'         => $issuer . '/token',
                    'registration_endpoint'  => $issuer . '/register',
                    'response_types_supported' => [ 'code' ],
                    'grant_types_supported'    => [ 'authorization_code', 'refresh_token' ],
                    'code_challenge_methods_supported' => [ 'S256' ],
                ];
                echo esc_html( wp_json_encode( $template_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
            ?></code></pre>
            <p>
                <a href="<?php echo esc_url( self::MISSING_ENDPOINTS_SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
                    <?php esc_html_e( 'See the full fix', 'royal-mcp' ); ?>
                </a>
                <?php $this->render_recheck_button(); ?>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link" style="margin-left: 1rem;">
                    <?php esc_html_e( 'Dismiss', 'royal-mcp' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    private function render_html_body_notice() {
        $dismiss_url = wp_nonce_url(
            add_query_arg( 'royal_mcp_dismiss_html_body', '1' ),
            'royal_mcp_dismiss_html_body'
        );

        ?>
        <div class="notice notice-warning royal-mcp-html-body-notice">
            <p>
                <strong><?php esc_html_e( 'Royal MCP: OAuth discovery is being served as HTML by another plugin or theme.', 'royal-mcp' ); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: %s: literal URL path code */
                    esc_html__( '%s returned an HTML document instead of JSON. A membership plugin (ARMember, MemberPress, Restrict Content Pro) or a theme template is intercepting the request and serving its own page. Discovery clients require JSON, so claude.ai and other MCP clients will fail to connect.', 'royal-mcp' ),
                    '<code>/.well-known/oauth-authorization-server</code>'
                );
                ?>
            </p>
            <p>
                <?php esc_html_e( 'Quick things to try:', 'royal-mcp' ); ?>
            </p>
            <ul style="margin-left: 1.5rem; list-style: disc;">
                <li><?php esc_html_e( 'Add the OAuth paths (/.well-known/, /register, /authorize, /token) to your membership plugin\'s unrestricted-URL list.', 'royal-mcp' ); ?></li>
                <li><?php esc_html_e( 'Re-save Permalinks (Settings → Permalinks → Save) to flush rewrite rules.', 'royal-mcp' ); ?></li>
                <li><?php esc_html_e( 'Temporarily deactivate suspect plugins one at a time to identify the culprit.', 'royal-mcp' ); ?></li>
            </ul>
            <p>
                <a href="<?php echo esc_url( self::HTML_BODY_SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
                    <?php esc_html_e( 'See the troubleshooting guide', 'royal-mcp' ); ?>
                </a>
                <?php $this->render_recheck_button(); ?>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link" style="margin-left: 1rem;">
                    <?php esc_html_e( 'Dismiss', 'royal-mcp' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    private function render_imunify360_notice() {
        $dismiss_url = wp_nonce_url(
            add_query_arg( 'royal_mcp_dismiss_imunify360', '1' ),
            'royal_mcp_dismiss_imunify360'
        );

        ?>
        <div class="notice notice-warning royal-mcp-imunify360-notice">
            <p>
                <strong><?php esc_html_e( 'Royal MCP: OAuth discovery is being blocked by Imunify360 bot-protection.', 'royal-mcp' ); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: 1: literal URL path code, 2: literal URL path code */
                    esc_html__( 'Your host runs Imunify360 (a CloudLinux security layer on many shared cPanel hosts), and it is intercepting %1$s and %2$s before WordPress can respond. Claude.ai and other MCP clients will fail to connect until your host allowlists these paths — no WordPress setting can fix this.', 'royal-mcp' ),
                    '<code>/.well-known/*</code>',
                    '<code>/wp-json/*</code>'
                );
                ?>
            </p>
            <p>
                <?php esc_html_e( 'Ask your host to allowlist these paths in Imunify360:', 'royal-mcp' ); ?>
                <code>/.well-known/*</code>, <code>/wp-json/*</code>, <code>/authorize</code>, <code>/token</code>, <code>/register</code>.
            </p>
            <p>
                <a href="<?php echo esc_url( self::IMUNIFY360_SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
                    <?php esc_html_e( 'Copy-paste hosting request', 'royal-mcp' ); ?>
                </a>
                <?php $this->render_recheck_button(); ?>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link" style="margin-left: 1rem;">
                    <?php esc_html_e( 'Dismiss', 'royal-mcp' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    private function render_bitninja_notice() {
        $dismiss_url = wp_nonce_url(
            add_query_arg( 'royal_mcp_dismiss_bitninja', '1' ),
            'royal_mcp_dismiss_bitninja'
        );

        ?>
        <div class="notice notice-warning royal-mcp-bitninja-notice">
            <p>
                <strong><?php esc_html_e( 'Royal MCP: OAuth discovery is being blocked by BitNinja WebShield.', 'royal-mcp' ); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: 1: literal URL path code, 2: literal URL path code */
                    esc_html__( 'Your host runs BitNinja WebShield, a bot-protection layer that serves a JavaScript challenge page in place of %1$s and %2$s. MCP clients (Claude, ChatGPT, Cursor) do not execute JavaScript and cannot solve the challenge, so the OAuth handshake fails at discovery. No WordPress setting can fix this — the exclusion must happen at the host layer.', 'royal-mcp' ),
                    '<code>/.well-known/*</code>',
                    '<code>/authorize</code>'
                );
                ?>
            </p>
            <p>
                <?php esc_html_e( 'Ask your host to disable BitNinja WebShield for these paths — or for the whole domain if per-path exclusion isn\'t offered:', 'royal-mcp' ); ?>
                <code>/.well-known/*</code>, <code>/wp-json/*</code>, <code>/authorize</code>, <code>/token</code>, <code>/register</code>.
            </p>
            <p>
                <em><?php esc_html_e( 'Insist on a path-based or domain-level exclusion, not an IP allowlist. Claude.ai\'s outbound IPs rotate, so an IP-only rule silently breaks again in weeks.', 'royal-mcp' ); ?></em>
            </p>
            <p>
                <a href="<?php echo esc_url( self::BITNINJA_SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
                    <?php esc_html_e( 'Copy-paste hosting request', 'royal-mcp' ); ?>
                </a>
                <?php $this->render_recheck_button(); ?>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link" style="margin-left: 1rem;">
                    <?php esc_html_e( 'Dismiss', 'royal-mcp' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    private function render_plain_permalinks_notice() {
        $dismiss_url = wp_nonce_url(
            add_query_arg( 'royal_mcp_dismiss_plain_permalinks', '1' ),
            'royal_mcp_dismiss_plain_permalinks'
        );

        $permalinks_admin_url = admin_url( 'options-permalink.php' );

        ?>
        <div class="notice notice-warning royal-mcp-plain-permalinks-notice">
            <p>
                <strong><?php esc_html_e( 'Royal MCP: OAuth discovery requires pretty permalinks.', 'royal-mcp' ); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: 1: literal URL path code, 2: literal URL path code, 3: literal URL path code, 4: literal URL path code */
                    esc_html__( 'WordPress is currently set to Plain permalinks. Royal MCP serves its OAuth endpoints (%1$s, %2$s, %3$s, %4$s) from the domain root via rewrite rules, and rewrite rules don\'t fire on Plain. Claude.ai cannot complete the connection until this is changed.', 'royal-mcp' ),
                    '<code>/.well-known/oauth-authorization-server</code>',
                    '<code>/authorize</code>',
                    '<code>/token</code>',
                    '<code>/register</code>'
                );
                ?>
            </p>
            <p>
                <?php esc_html_e( 'The fix takes 10 seconds: open Settings → Permalinks, choose any option except Plain (Post name is a safe default), and Save Changes.', 'royal-mcp' ); ?>
            </p>
            <p>
                <a href="<?php echo esc_url( $permalinks_admin_url ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Fix in Permalink Settings', 'royal-mcp' ); ?>
                </a>
                <a href="<?php echo esc_url( self::PLAIN_PERMALINKS_SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer" class="button" style="margin-left: 0.5rem;">
                    <?php esc_html_e( 'Read full explanation', 'royal-mcp' ); ?>
                </a>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link" style="margin-left: 1rem;">
                    <?php esc_html_e( 'Dismiss', 'royal-mcp' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    private function render_register_301_notice() {
        $dismiss_url = wp_nonce_url(
            add_query_arg( 'royal_mcp_dismiss_register_301', '1' ),
            'royal_mcp_dismiss_register_301'
        );

        ?>
        <div class="notice notice-warning royal-mcp-register-301-notice">
            <p>
                <strong><?php esc_html_e( 'Royal MCP: OAuth registration may be blocked by your web server.', 'royal-mcp' ); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: 1: literal URL path code, 2: literal URL path code */
                    esc_html__( 'Your web server is redirecting %1$s to %2$s with a 301. OAuth clients don\'t follow 301s on POST, so claude.ai\'s registration request dies before it reaches Royal MCP. This is a web-server config issue (Nginx, Apache mod_dir, or .htaccess canonicalization), not a Royal MCP setting.', 'royal-mcp' ),
                    '<code>/register</code>',
                    '<code>/register/</code>'
                );
                ?>
            </p>
            <p>
                <a href="<?php echo esc_url( self::REGISTER_301_SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
                    <?php esc_html_e( 'See Nginx and Apache fixes', 'royal-mcp' ); ?>
                </a>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link" style="margin-left: 1rem;">
                    <?php esc_html_e( 'Dismiss', 'royal-mcp' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    private function render_oauth_page_shadow_notice( array $shadowed ) {
        $dismiss_url = wp_nonce_url(
            add_query_arg( 'royal_mcp_dismiss_page_shadow', '1' ),
            'royal_mcp_dismiss_page_shadow'
        );

        // Build a human list: "the page \"Register\" (/register)"
        $items = [];
        foreach ( $shadowed as $entry ) {
            $items[] = sprintf(
                '"%s" (/%s)',
                esc_html( $entry['title'] ),
                esc_html( $entry['slug'] )
            );
        }
        $items_html = implode( ', ', $items );

        // Snippet the admin can drop into a mu-plugin to relocate the OAuth
        // endpoint. Uses whichever slug is currently shadowed as the key.
        $relocate_lines = [];
        foreach ( $shadowed as $action => $entry ) {
            $relocate_lines[] = sprintf(
                "    \$paths['%s'] = 'royal-mcp-oauth/%s';",
                esc_html( $action ),
                esc_html( $action )
            );
        }
        $relocate_snippet  = "add_filter( 'royal_mcp_oauth_rewrite_paths', function( \$paths ) {\n";
        $relocate_snippet .= implode( "\n", $relocate_lines ) . "\n";
        $relocate_snippet .= "    return \$paths;\n";
        $relocate_snippet .= "} );";

        ?>
        <div class="notice notice-warning royal-mcp-oauth-page-shadow-notice">
            <p>
                <strong><?php esc_html_e( 'Royal MCP: OAuth endpoints overlap with existing pages on your site.', 'royal-mcp' ); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: %s: comma-separated list of page-title/slug pairs */
                    esc_html__( 'Royal MCP serves OAuth endpoints at %s, which also match pages you published. Visitor GET requests to those URLs fall through to your pages correctly; MCP POST requests to /register and /token still reach Royal MCP. If a page at /register also accepts POST form submissions (some membership plugins do), you can relocate the OAuth endpoint via the filter below.', 'royal-mcp' ),
                    // items already escaped above
                    wp_kses( $items_html, [ 'em' => [] ] )
                );
                ?>
            </p>
            <p><code style="display:block; white-space:pre; padding:8px; background:#f6f7f7;"><?php echo esc_html( $relocate_snippet ); ?></code></p>
            <p>
                <a href="<?php echo esc_url( self::PAGE_SHADOW_SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
                    <?php esc_html_e( 'Read the full guidance', 'royal-mcp' ); ?>
                </a>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link" style="margin-left: 1rem;">
                    <?php esc_html_e( 'Dismiss', 'royal-mcp' ); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
