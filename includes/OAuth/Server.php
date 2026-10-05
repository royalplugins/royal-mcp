<?php
namespace Royal_MCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * OAuth 2.0 Authorization Server for MCP.
 *
 * Implements the OAuth 2.1 authorization code flow with PKCE
 * per the MCP specification.
 *
 * Endpoints (served at domain root via rewrite rules):
 *  - GET  /.well-known/oauth-authorization-server  → metadata()
 *  - POST /register                                → register()
 *  - GET  /authorize                               → authorize_get()
 *  - POST /authorize                               → authorize_post()
 *  - POST /token                                   → token()
 */
class Server {

    /**
     * The current OAuth action being handled (metadata|authorize|token|register|protected_resource).
     * Set by dispatch() so error logging knows which endpoint fired.
     */
    private $current_action = 'unknown';

    /**
     * Query vars captured from the rewrite match, handed in by dispatch().
     * get_query_var() is NOT usable here: handle_oauth_request() runs on
     * parse_request, before WP_Query populates the global query vars.
     */
    private $query_vars = [];

    /**
     * Dispatch an OAuth request based on the query var value.
     *
     * @param string $action The royal_mcp_oauth query var (metadata|authorize|token|register).
     */
    public function dispatch( $action, array $query_vars = [] ) {
        $this->current_action = $action;
        $this->query_vars     = $query_vars;
        $request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';

        // Set CORS headers for token and register endpoints (may be called cross-origin).
        if ( in_array( $action, [ 'token', 'register', 'metadata', 'metadata_mcp', 'metadata_oidc', 'jwks', 'protected_resource', 'protected_resource_endpoint', 'method_not_allowed' ], true ) ) {
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Content-Type, Authorization' );

            if ( 'OPTIONS' === $request_method ) {
                status_header( 204 );
                exit;
            }
        }

        switch ( $action ) {
            case 'protected_resource':
                $this->protected_resource_metadata();
                break;

            case 'protected_resource_endpoint':
                $this->protected_resource_metadata_endpoint();
                break;

            case 'metadata':
                $this->metadata();
                break;

            case 'metadata_mcp':
                $this->metadata_mcp();
                break;

            case 'metadata_oidc':
                $this->metadata_oidc();
                break;

            case 'jwks':
                $this->jwks();
                break;

            case 'register':
                $this->register( $request_method );
                break;

            case 'authorize':
                if ( 'POST' === $request_method ) {
                    $this->authorize_post();
                } else {
                    $this->authorize_get();
                }
                break;

            case 'token':
                $this->token( $request_method );
                break;

            case 'method_not_allowed':
                $this->method_not_allowed();
                break;

            default:
                status_header( 404 );
                exit;
        }
    }

    /**
     * Emit 405 Method Not Allowed for GET/HEAD requests to POST-only OAuth
     * endpoints (/register, /token). Includes an Allow header per RFC 9110
     * so probing clients can retry with the right method.
     */
    private function method_not_allowed() {
        header( 'Allow: POST, OPTIONS' );
        $this->json_error( 'method_not_allowed', 'This endpoint only accepts POST requests.', 405 );
    }

    /* ------------------------------------------------------------------
     *  GET /.well-known/oauth-protected-resource  (RFC 9728)
     *  Tells the client which authorization server protects this resource.
     * ----------------------------------------------------------------*/

    /**
     * Build the RFC 9728 Protected Resource metadata payload.
     *
     * `resource` per RFC 9728 §2 identifies the protected resource. The
     * scanner probing convention (isitagentready.com + CF Agent Readiness)
     * fetches the well-known path at site root and verifies `resource`
     * matches the URL being accessed (site root itself). Serving `resource
     * = site root` satisfies that scanner check; agents still discover the
     * /mcp endpoint through the auth-server metadata's `resource` indicator
     * + the WWW-Authenticate header on /mcp 401 responses.
     *
     * Extracted as a public static so REST-route callbacks that dual-serve
     * the same payload under /wp-json/royal-mcp/v1/.well-known/ can reuse
     * it verbatim — every path returns byte-identical JSON.
     *
     * @return array Protected Resource metadata document.
     */
    public static function build_protected_resource_metadata() {
        $base = self::canonical_resource_url( '/' );
        /**
         * Which resource identifier the ROOT protected-resource document names.
         *
         *   'endpoint'  (default) — the canonical MCP endpoint URL. This is what
         *                OAuth clients compare against the server URL the user
         *                pasted; a mismatch is fatal ("invalid_target").
         *   'site-root' — the bare site origin. Only useful for agent-readiness
         *                scanners that fetch the root document and expect the
         *                origin; OAuth clients that read the root document then
         *                fail discovery. Opt in via this filter if you need it.
         *
         * @param string $mode 'endpoint' or 'site-root'.
         */
        $mode     = apply_filters( 'royal_mcp_prm_root_resource', 'endpoint' );
        $resource = 'site-root' === $mode
            ? rtrim( $base, '/' )
            : self::canonical_resource_url( self::default_resource_path() );
        return [
            'resource'                 => $resource,
            'authorization_servers'    => [ $base ],
            'bearer_methods_supported' => [ 'header' ],
            'scopes_supported'         => [ 'mcp:full' ],
        ];
    }

    /**
     * Build a Protected Resource Metadata document scoped to a specific
     * endpoint resource URL, per RFC 9728 §3.1 path-suffixed URL semantics.
     *
     * The bare `.well-known/oauth-protected-resource` endpoint returns
     * `resource: home_url()` for backwards compatibility with agent-readiness
     * scanners (isitagentready.com, CF Agent Readiness) that fetch the bare
     * path and verify `resource` matches the URL they accessed (site root).
     * Strict clients per RFC 8707 pass a specific `resource=<canonical URL>`
     * on their authorization + token requests and expect the PRM document
     * for THAT resource to name that same URL — which the bare document
     * cannot do without breaking scanner compat. This helper produces the
     * per-endpoint PRM served at RFC 9728 §3.1 path-suffixed URLs.
     *
     * The output shares `authorization_servers`, `bearer_methods_supported`,
     * `scopes_supported` with the bare document — only `resource` differs.
     *
     * @param string $endpoint_url The canonical endpoint URL the PRM covers.
     * @return array PRM document with `resource` set to $endpoint_url.
     */
    public static function build_protected_resource_metadata_for_endpoint( $endpoint_url ) {
        $bare               = self::build_protected_resource_metadata();
        $bare['resource']   = rtrim( (string) $endpoint_url, '/' );
        return $bare;
    }

    /**
     * Site-relative paths at which the MCP endpoint is reachable. Each one
     * is a distinct RFC 8707 resource identifier once prefixed with the
     * canonical scheme + host (see canonical_resource_url()). A client that
     * connects to any of these must be handed a PRM whose `resource` names
     * exactly that URL — strict RFC 8707 clients reject mismatches per
     * RFC 9728 §3.3 (host, scheme, path, and trailing-slash all matter).
     *
     * @return string[]
     */
    public static function mcp_resource_paths() {
        $paths = apply_filters( 'royal_mcp_resource_paths', [
            '/wp-json/royal-mcp/v1/mcp',      // canonical — what the Help page tells users to paste
            '/wp-json/royal-mcp/v1',          // namespace-root alias
            '/wp-json/royal-mcp/v1/messages', // legacy alias
            '/mcp',                           // root alias (Cloudflare WebMCP bridge default)
        ] );
        return array_values( array_filter( array_map( static function ( $p ) {
            $p = '/' . trim( (string) $p, '/' );
            return '/' === $p ? '' : $p;
        }, (array) $paths ) ) );
    }

    /**
     * Canonical RFC 8707 resource URL for a site-relative MCP endpoint path.
     *
     * Scheme is forced to https when the request arrived over TLS, so the
     * discovery documents advertise the same origin the client actually
     * reached. No trailing slash, per MCP spec guidance.
     *
     * @param string $rel_path e.g. '/wp-json/royal-mcp/v1/mcp'
     * @return string
     */
    public static function canonical_resource_url( $rel_path ) {
        $rel = '/' . trim( (string) $rel_path, '/' );
        $url = home_url( $rel );
        $forwarded = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
            ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) )
            : '';
        if ( is_ssl() || 'https' === $forwarded ) {
            $url = set_url_scheme( $url, 'https' );
        }
        return rtrim( $url, '/' );
    }

    /**
     * The canonical MCP endpoint path — the one the Help page tells users to
     * paste and the one every discovery document falls back to.
     */
    public static function default_resource_path() {
        $paths = self::mcp_resource_paths();
        return isset( $paths[0] ) ? $paths[0] : '/wp-json/royal-mcp/v1/mcp';
    }

    /**
     * Extract the path-suffix that follows `/.well-known/oauth-protected-resource`
     * in the CURRENT request URI. Rewrite-cache independent: works whether the
     * request was routed by the current generic rule (which sets the
     * royal_mcp_resource_path query var), by an older cached rule that does
     * not, or by the bare rule swallowing a suffixed URL. Returns '' when the
     * request has no suffix.
     */
    public static function request_uri_resource_suffix() {
        $path = isset( $_SERVER['REQUEST_URI'] )
            ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against an allowlist only
            : '';
        $marker = '/.well-known/oauth-protected-resource';
        $pos    = strpos( $path, $marker );
        if ( false === $pos ) {
            return '';
        }
        return trim( substr( $path, $pos + strlen( $marker ) ), '/' );
    }

    /**
     * Map a path-suffix (the part after `/.well-known/oauth-protected-resource`)
     * onto a known MCP endpoint path. Returns null for anything that is not
     * an MCP endpoint so we never mint a PRM for an arbitrary URL.
     *
     * @param string $suffix
     * @return string|null
     */
    public static function resolve_resource_path( $suffix ) {
        $path = (string) wp_parse_url( '/' . ltrim( (string) $suffix, '/' ), PHP_URL_PATH );
        $rel  = '/' . trim( $path, '/' );
        return in_array( $rel, self::mcp_resource_paths(), true ) ? $rel : null;
    }

    /**
     * Which MCP endpoint path the *current* request is hitting. Used by the
     * 401 challenge so `resource_metadata` points at the PRM for the exact
     * URL the client connected to. Falls back to the canonical wp-json path
     * when the request URI cannot be classified (e.g. plain permalinks).
     *
     * @return string site-relative path, always one of mcp_resource_paths().
     */
    public static function current_request_resource_path() {
        $path = isset( $_SERVER['REQUEST_URI'] )
            ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against an allowlist only
            : '';
        $path = '/' . trim( $path, '/' );

        // Subdirectory installs: strip the home path so aliases resolve.
        $home_path = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
        if ( '' !== $home_path && 0 === strpos( $path . '/', $home_path . '/' ) ) {
            $path = '/' . trim( substr( $path, strlen( $home_path ) ), '/' );
        }

        $resolved = self::resolve_resource_path( $path );
        return null !== $resolved ? $resolved : '/wp-json/royal-mcp/v1/mcp';
    }

    private function protected_resource_metadata() {
        // A stale rewrite cache can route a path-suffixed URL here. Honour the
        // suffix if it names a known endpoint instead of serving the root doc.
        $rel = self::resolve_resource_path( self::request_uri_resource_suffix() );
        if ( null !== $rel ) {
            $doc = self::build_protected_resource_metadata_for_endpoint( self::canonical_resource_url( $rel ) );
        } else {
            $doc = self::build_protected_resource_metadata();
        }
        $this->log_event( 'prm_served', 'resource=' . $doc['resource'], 200, 'success' );
        $this->json_response( $doc, 200, [ 'Cache-Control' => 'public, max-age=3600' ] );
    }

    /**
     * RFC 9728 §3.1 path-suffixed PRM handler scoped to the /mcp endpoint.
     * Serves the same PRM shape as the bare handler but with `resource` set
     * to the canonical wp-json /mcp URL so strict RFC 8707 clients whose
     * `resource=` request parameter names the endpoint URL find a matching
     * PRM document.
     *
     * URL: `GET /.well-known/oauth-protected-resource/wp-json/royal-mcp/v1/mcp`
     * (mirrored under wp-json fallback for managed-host coverage).
     */
    private function protected_resource_metadata_endpoint() {
        // Prefer the suffix visible in the request URI (rewrite-cache
        // independent); fall back to the query var the generic rule sets.
        $rel = self::resolve_resource_path( self::request_uri_resource_suffix() );
        if ( null === $rel ) {
            $suffix = isset( $this->query_vars['royal_mcp_resource_path'] ) ? (string) $this->query_vars['royal_mcp_resource_path'] : '';
            $rel    = self::resolve_resource_path( $suffix );
        }
        if ( null === $rel ) {
            // RFC 9728: no metadata document exists for this resource. A 404
            // (rather than the bare site-root document) keeps strict clients
            // from reading a `resource` that does not match what they asked for.
            $this->json_error( 'not_found', 'No protected resource metadata for that resource.', 404 );
        }
        $doc = self::build_protected_resource_metadata_for_endpoint( self::canonical_resource_url( $rel ) );
        $this->log_event( 'prm_served', 'resource=' . $doc['resource'], 200, 'success' );
        $this->json_response( $doc, 200, [ 'Cache-Control' => 'public, max-age=3600' ] );
    }

    /* ------------------------------------------------------------------
     *  GET /.well-known/oauth-authorization-server            (RFC 8414)
     *  GET /.well-known/oauth-authorization-server/mcp        (path-scoped)
     * ----------------------------------------------------------------*/

    /**
     * Build the OAuth 2.1 Authorization Server metadata payload.
     *
     * Reads endpoint paths from the same filterable source
     * register_oauth_rewrites() uses so discovery advertises whatever the
     * site actually serves — customers who relocate via
     * royal_mcp_oauth_rewrite_paths get correct discovery URLs
     * automatically, no separate metadata filter needed.
     *
     * @return array The AS metadata document.
     */
    public static function build_authorization_server_metadata() {
        // Same https-aware canonical base as the PRM `resource` — discovery
        // must match the origin the client used to reach the endpoint.
        $base     = self::canonical_resource_url( '/' );
        $paths    = \Royal_MCP_Plugin::get_oauth_rewrite_paths();
        $slug_for = static function ( array $paths, $action ) {
            $slug = isset( $paths[ $action ] ) ? ltrim( trim( (string) $paths[ $action ] ), '/' ) : $action;
            return $slug === '' ? $action : $slug;
        };

        return [
            'issuer'                                => $base,
            'authorization_endpoint'                => $base . '/' . $slug_for( $paths, 'authorize' ),
            'token_endpoint'                        => $base . '/' . $slug_for( $paths, 'token' ),
            'registration_endpoint'                 => $base . '/' . $slug_for( $paths, 'register' ),
            'response_types_supported'              => [ 'code' ],
            'grant_types_supported'                 => [ 'authorization_code', 'refresh_token' ],
            'token_endpoint_auth_methods_supported' => [ 'none', 'client_secret_post' ],
            'code_challenge_methods_supported'       => [ 'S256' ],
            'scopes_supported'                      => [ 'mcp:full' ],
            'service_documentation'                 => 'https://royalplugins.com/support/royal-mcp/',
            // Clients whose client_id is the URL of a JSON metadata document are
            // treated as registered when the document validates. Filterable off
            // via royal_mcp_cimd_enabled for site owners who want to lock to
            // dynamic-registration only.
            'client_id_metadata_document_supported' => (bool) apply_filters( 'royal_mcp_cimd_enabled', true ),
        ];
    }

    private function metadata() {
        $this->json_response( self::build_authorization_server_metadata(), 200, [ 'Cache-Control' => 'public, max-age=3600' ] );
    }

    /**
     * Path-scoped variant of the AS metadata document. Some MCP clients probe
     * the RFC 8414 path-scoped location `/.well-known/oauth-authorization-server/mcp`
     * rather than the RFC 9728 `/.well-known/oauth-protected-resource` endpoint.
     *
     * Serves the same AS metadata as the root variant, plus a `resource`
     * indicator (RFC 8707) pre-embedded so strict clients don't need a
     * separate protected-resource discovery round-trip.
     */
    private function metadata_mcp() {
        $metadata = self::build_authorization_server_metadata();
        // Same resource identifier as protected_resource_metadata — canonical
        // /mcp alias URL so both discovery paths agree on the resource URL.
        $metadata['resource'] = self::canonical_resource_url( '/mcp' );
        $this->json_response( $metadata, 200, [ 'Cache-Control' => 'public, max-age=3600' ] );
    }

    /**
     * OpenID Connect Discovery 1.0 shape of the same document.
     *
     * MCP SDK-based clients probe, in order:
     * /.well-known/oauth-authorization-server[/path],
     * /.well-known/openid-configuration[/path], then
     * [/path]/.well-known/openid-configuration. On a subdirectory WordPress
     * install only the last is under WordPress at all, and on hosts that
     * intercept oauth-authorization-server the second is the only fallback.
     * Clients validate this variant against the OIDC schema, so the extra
     * required OIDC fields are present (with an empty JWKS — no ID tokens
     * are issued).
     */
    public static function build_openid_configuration() {
        $metadata = self::build_authorization_server_metadata();
        $base     = $metadata['issuer'];
        return $metadata + [
            'jwks_uri'                              => $base . '/.well-known/jwks.json',
            'subject_types_supported'               => [ 'public' ],
            'id_token_signing_alg_values_supported' => [ 'RS256' ],
            'response_modes_supported'              => [ 'query' ],
        ];
    }

    private function metadata_oidc() {
        $this->json_response( self::build_openid_configuration(), 200, [ 'Cache-Control' => 'public, max-age=3600' ] );
    }

    private function jwks() {
        $this->json_response( [ 'keys' => [] ], 200, [ 'Cache-Control' => 'public, max-age=3600' ] );
    }

    /* ------------------------------------------------------------------
     *  POST /register  — Dynamic Client Registration (RFC 7591)
     * ----------------------------------------------------------------*/

    private function register( $request_method = 'GET' ) {
        if ( 'POST' !== $request_method ) {
            $this->json_error( 'invalid_request', 'POST method required.', 405 );
        }

        // Rate-limit registrations by IP.
        $ip            = $this->get_client_ip();
        $transient_key = 'royal_mcp_reg_rate_' . md5( $ip );
        $count         = (int) get_transient( $transient_key );
        if ( $count >= 10 ) {
            $this->json_error( 'rate_limit', 'Too many registration attempts. Try again later.', 429 );
        }
        set_transient( $transient_key, $count + 1, 60 );

        // Parse body.
        $body = json_decode( file_get_contents( 'php://input' ), true );
        if ( ! is_array( $body ) ) {
            $this->json_error( 'invalid_request', 'Invalid JSON body.', 400 );
        }

        // Validate redirect_uris. At least one URI is required — a client
        // with no registered destinations can't be authorized to anywhere,
        // and downstream matching in Token_Store::validate_redirect_uri
        // treats an empty list as a deny.
        $redirect_uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? $body['redirect_uris'] : [];
        if ( empty( $redirect_uris ) ) {
            $this->json_error( 'invalid_redirect_uri', 'redirect_uris is required and must include at least one URI.', 400 );
        }
        foreach ( $redirect_uris as $uri ) {
            if ( ! $this->is_valid_redirect_uri( $uri ) ) {
                $this->json_error( 'invalid_redirect_uri', 'Redirect URIs must be HTTPS, an http:// loopback address, or a private-use app scheme.', 400 );
            }
        }

        // Pending-approval gate. When the site owner enables the
        // "Require approval before new AI clients can connect" toggle,
        // /register still mints a client_id but saves it with status
        // pending_approval. /authorize then rejects that client until an
        // admin approves it. Requester metadata (IP + UA) is captured so
        // the admin surface can show the source of the request.
        $settings         = get_option( 'royal_mcp_settings', [] );
        $require_approval = is_array( $settings ) && ! empty( $settings['require_client_approval'] );
        $ua_header        = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        $body['_require_approval'] = $require_approval;
        $body['_ip_address']       = $ip;
        $body['_user_agent']       = $ua_header;

        $client = Token_Store::register_client( $body );

        // Defensive self-heal: if our tables are reported missing, attempt
        // create_tables() once and retry. Belt-and-suspenders for installs
        // where the autoloader transiently failed and the healer got latched.
        // Only runs on the failure path — happy path is unchanged. See
        // royal-mcp.php maybe_upgrade_db for the full invariant.
        if ( is_wp_error( $client ) && $client->get_error_code() === 'royal_mcp_register_failed' ) {
            Token_Store::create_tables();
            if ( class_exists( '\Royal_MCP\MCP\Session_Store' ) ) {
                \Royal_MCP\MCP\Session_Store::create_tables();
            }
            $client = Token_Store::register_client( $body );
        }

        if ( is_wp_error( $client ) ) {
            $this->json_error( 'server_error', $client->get_error_message(), 500 );
        }

        // If the site owner requires approval, decorate the successful DCR
        // response with a pending flag + throttled admin notification, then
        // fire a cross-plugin action so security-adjacent tools (Royal AI
        // Firewall, etc.) can log or react to the pending registration.
        if ( ! empty( $client['status'] ) && 'pending_approval' === $client['status'] ) {
            $client['pending'] = true;
            $client['message'] = 'Client registered but requires administrator approval before it can be used. The site owner has been notified.';
            $this->maybe_notify_admin_pending( $client, $ua_header );
            /**
             * Fires after a pending-approval OAuth client is persisted.
             * Cross-plugin hook for security-adjacent tools to log or react.
             *
             * @param string $client_id  The newly-registered client_id.
             * @param array  $metadata   [ 'client_name', 'redirect_uris', 'ip_address', 'user_agent' ]
             */
            do_action(
                'royal_mcp_oauth_client_pending_registered',
                $client['client_id'],
                [
                    'client_name'   => $client['client_name'] ?? '',
                    'redirect_uris' => $client['redirect_uris'] ?? [],
                    'ip_address'    => $ip,
                    'user_agent'    => $ua_header,
                ]
            );
            $this->log_event( 'client_registered_pending', 'Dynamic client registered pending admin approval.', 201, 'success' );
            $this->json_response( $client, 201 );
        }

        $this->log_event( 'client_registered', 'Dynamic client registered.', 201, 'success' );
        $this->json_response( $client, 201 );
    }

    /**
     * Send at most one email per hour when new clients arrive in the pending
     * queue. The transient rate-limit is per-site (not per-client) so a bot
     * flooding /register can't blow up the admin's inbox.
     */
    private function maybe_notify_admin_pending( array $client, string $ua_header ) {
        $notify_key = 'royal_mcp_pending_notify_lock';
        if ( false !== get_transient( $notify_key ) ) {
            return; // Within throttle window.
        }
        set_transient( $notify_key, 1, HOUR_IN_SECONDS );

        $to      = get_option( 'admin_email' );
        if ( ! is_email( $to ) ) {
            return;
        }
        $site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $subject = sprintf( '[%s] New MCP client waiting for approval', $site );
        $lines   = [
            'A new AI client has requested to connect to your site and is waiting for your approval.',
            '',
            sprintf( 'Client name: %s', $client['client_name'] ?? '(unnamed)' ),
            sprintf( 'Request source IP: %s', $client['_ip_address'] ?? '' ),
            sprintf( 'User agent: %s', $ua_header ?: '(none)' ),
            '',
            sprintf( 'Review pending clients: %s', esc_url_raw( admin_url( 'admin.php?page=royal-mcp-pending-clients' ) ) ),
            '',
            'Additional pending clients registered within the next hour are batched into this same notification.',
        ];
        wp_mail( $to, $subject, implode( "\n", $lines ) );
    }

    /* ------------------------------------------------------------------
     *  GET /authorize  — Show consent screen
     * ----------------------------------------------------------------*/

    private function authorize_get() {
        // OAuth authorize endpoint — params come from external MCP client, no WP nonce possible.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $response_type         = isset( $_GET['response_type'] ) ? sanitize_text_field( wp_unslash( $_GET['response_type'] ) ) : '';
        $client_id             = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '';
        $redirect_uri          = isset( $_GET['redirect_uri'] ) ? sanitize_text_field( wp_unslash( $_GET['redirect_uri'] ) ) : '';
        $code_challenge        = isset( $_GET['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) ) : '';
        $code_challenge_method = isset( $_GET['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ) ) : '';
        $state                 = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        $scope                 = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : 'mcp:full';
        $resource_indicator    = isset( $_GET['resource'] ) ? esc_url_raw( wp_unslash( $_GET['resource'] ) ) : '';
        if ( '' !== $resource_indicator ) {
            $this->log_event( 'resource_indicator', 'authorize resource=' . $resource_indicator, 200, 'success' );
        }

        // Resolve URL-shaped client_ids against their metadata document before
        // the standard lookup. Non-URL client_ids short-circuit to null so this
        // is a no-op for the dynamic-registration path.
        Token_Store::resolve_cimd_client( $client_id );

        // Validate client FIRST — never redirect to unvalidated redirect_uri (OAuth 2.1 §4.1.2.1).
        $client = Token_Store::get_client( $client_id );
        if ( ! $client ) {
            $this->log_event( 'invalid_client', 'Unknown client_id at /authorize.', 400 );
            wp_die(
                esc_html__( 'Unknown client_id. The application has not been registered.', 'royal-mcp' ),
                esc_html__( 'Authorization Error', 'royal-mcp' ),
                [ 'response' => 400 ]
            );
        }

        // Pending-approval gate. Client exists but is waiting for an admin
        // approve/reject decision. Refuse to redirect until it clears.
        if ( Token_Store::is_pending_approval( $client ) ) {
            $this->log_event( 'client_pending_approval', 'Authorize attempted on client awaiting admin approval.', 403 );
            wp_die(
                esc_html__( 'This client is registered but is waiting for the site owner to approve it before it can connect. Contact the site owner or try again after they have approved the request.', 'royal-mcp' ),
                esc_html__( 'Authorization Pending Approval', 'royal-mcp' ),
                [ 'response' => 403 ]
            );
        }

        // Validate redirect_uri BEFORE any redirects.
        if ( empty( $redirect_uri ) || ! Token_Store::validate_redirect_uri( $redirect_uri, $client ) ) {
            $this->log_event( 'invalid_redirect_uri', 'Invalid or missing redirect_uri at /authorize (GET).', 400 );
            wp_die(
                esc_html__( 'Invalid redirect_uri.', 'royal-mcp' ),
                esc_html__( 'Authorization Error', 'royal-mcp' ),
                [ 'response' => 400 ]
            );
        }

        // Now safe to redirect errors to the validated redirect_uri.
        if ( 'code' !== $response_type ) {
            $this->authorize_error( $redirect_uri, $state, 'unsupported_response_type', 'Only response_type=code is supported.' );
        }

        // PKCE is required.
        if ( empty( $code_challenge ) || 'S256' !== $code_challenge_method ) {
            $this->authorize_error( $redirect_uri, $state, 'invalid_request', 'PKCE with code_challenge_method=S256 is required.' );
        }

        // Ensure user is logged into WordPress.
        if ( ! is_user_logged_in() ) {
            // Build the full authorize URL with all params to come back after login.
            $authorize_url = add_query_arg(
                [
                    'response_type'         => $response_type,
                    'client_id'             => $client_id,
                    'redirect_uri'          => $redirect_uri,
                    'code_challenge'        => $code_challenge,
                    'code_challenge_method' => $code_challenge_method,
                    'state'                 => $state,
                    'scope'                 => $scope,
                ],
                home_url( '/authorize' )
            );

            wp_safe_redirect( wp_login_url( $authorize_url ) );
            exit;
        }

        // User is logged in — render consent screen.
        $current_user = wp_get_current_user();
        $site_name    = get_bloginfo( 'name' );

        // Extract the redirect URI host so the consent screen can show the
        // admin exactly where the authorization code will be delivered.
        $redirect_parts = wp_parse_url( $redirect_uri );
        $redirect_host  = isset( $redirect_parts['host'] ) ? $redirect_parts['host'] : $redirect_uri;
        if ( ! empty( $redirect_parts['port'] ) ) {
            $redirect_host .= ':' . (int) $redirect_parts['port'];
        }

        // Pass variables to the template.
        $rmcp_oauth = [
            'client_name'           => $client['client_name'] ?? $client_id,
            'client_id'             => $client_id,
            'redirect_uri'          => $redirect_uri,
            'redirect_host'         => $redirect_host,
            'code_challenge'        => $code_challenge,
            'code_challenge_method' => $code_challenge_method,
            'state'                 => $state,
            'scope'                 => $scope,
            'user_display_name'     => $current_user->display_name,
            'site_name'             => $site_name,
            'nonce'                 => wp_create_nonce( 'royal_mcp_authorize' ),
        ];

        // Load the consent template.
        include ROYAL_MCP_PLUGIN_DIR . 'templates/admin/authorize.php';
        exit;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /* ------------------------------------------------------------------
     *  POST /authorize  — Process consent
     * ----------------------------------------------------------------*/

    private function authorize_post() {
        // Verify nonce.
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'royal_mcp_authorize' ) ) {
            $this->log_event( 'invalid_request', 'CSRF nonce verification failed at /authorize (POST).', 403 );
            wp_die(
                esc_html__( 'Security check failed. Please try again.', 'royal-mcp' ),
                esc_html__( 'Authorization Error', 'royal-mcp' ),
                [ 'response' => 403 ]
            );
        }

        $redirect_uri          = isset( $_POST['redirect_uri'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_uri'] ) ) : '';
        $client_id             = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
        $code_challenge        = isset( $_POST['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_POST['code_challenge'] ) ) : '';
        $code_challenge_method = isset( $_POST['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_POST['code_challenge_method'] ) ) : '';
        $state                 = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
        $scope                 = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : 'mcp:full';
        $action                = isset( $_POST['authorize_action'] ) ? sanitize_text_field( wp_unslash( $_POST['authorize_action'] ) ) : '';

        // User denied.
        if ( 'deny' === $action ) {
            $this->authorize_error( $redirect_uri, $state, 'access_denied', 'The user denied the authorization request.' );
        }

        // Refresh CIMD metadata if the cached document has expired between
        // /authorize GET and this POST. Idempotent for DCR client_ids.
        Token_Store::resolve_cimd_client( $client_id );

        // Validate client still exists.
        $client = Token_Store::get_client( $client_id );
        if ( ! $client ) {
            $this->log_event( 'invalid_client', 'Unknown client_id at /authorize (POST).', 400 );
            wp_die( esc_html__( 'Unknown client.', 'royal-mcp' ), '', [ 'response' => 400 ] );
        }

        // Second-line pending-approval check. The GET path already blocks
        // pending clients, but a client that got approved mid-flow between
        // GET + POST would slip through without this check.
        if ( Token_Store::is_pending_approval( $client ) ) {
            $this->log_event( 'client_pending_approval', 'Authorize POST attempted on client awaiting admin approval.', 403 );
            wp_die( esc_html__( 'This client is still waiting for site-owner approval.', 'royal-mcp' ), '', [ 'response' => 403 ] );
        }

        // Validate redirect_uri again.
        if ( empty( $redirect_uri ) || ! Token_Store::validate_redirect_uri( $redirect_uri, $client ) ) {
            $this->log_event( 'invalid_redirect_uri', 'Invalid or missing redirect_uri at /authorize (POST).', 400 );
            wp_die( esc_html__( 'Invalid redirect URI.', 'royal-mcp' ), '', [ 'response' => 400 ] );
        }

        // Must be logged in.
        if ( ! is_user_logged_in() ) {
            $this->log_event( 'unauthorized', 'Authorize POST received without an authenticated WP user session.', 401 );
            wp_die( esc_html__( 'Not authenticated.', 'royal-mcp' ), '', [ 'response' => 401 ] );
        }

        // Generate authorization code.
        $code = bin2hex( random_bytes( 32 ) );

        Token_Store::store_auth_code( $code, [
            'user_id'               => get_current_user_id(),
            'client_id'             => $client_id,
            'redirect_uri'          => $redirect_uri,
            'code_challenge'        => $code_challenge,
            'code_challenge_method' => $code_challenge_method,
            'scope'                 => $scope,
        ] );

        // Redirect back to the client with the code.
        $redirect = self::build_authorize_redirect_url(
            $redirect_uri,
            [
                'code'  => $code,
                'state' => $state,
            ]
        );

        $this->log_event( 'code_issued', 'Authorization code issued; redirecting to client callback.', 302, 'success' );

        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- OAuth callback URI is external (e.g. claude.ai).
        wp_redirect( $redirect );
        exit;
    }

    /* ------------------------------------------------------------------
     *  POST /token  — Token exchange
     * ----------------------------------------------------------------*/

    private function token( $request_method = 'GET' ) {
        // OAuth token endpoint — external MCP clients cannot provide WP nonces.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if ( 'POST' !== $request_method ) {
            $this->json_error( 'invalid_request', 'POST method required.', 405 );
        }

        // Parse form-encoded body (standard OAuth).
        $grant_type = isset( $_POST['grant_type'] ) ? sanitize_text_field( wp_unslash( $_POST['grant_type'] ) ) : '';

        switch ( $grant_type ) {
            case 'authorization_code':
                $this->token_authorization_code();
                break;

            case 'refresh_token':
                $this->token_refresh();
                break;

            default:
                $this->json_error( 'unsupported_grant_type', 'Supported grant types: authorization_code, refresh_token.', 400 );
        }
    }

    /**
     * Exchange an authorization code for tokens.
     */
    private function token_authorization_code() {
        $code          = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
        $redirect_uri  = isset( $_POST['redirect_uri'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_uri'] ) ) : '';
        $client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
        $code_verifier = isset( $_POST['code_verifier'] ) ? sanitize_text_field( wp_unslash( $_POST['code_verifier'] ) ) : '';

        if ( empty( $code ) || empty( $client_id ) || empty( $code_verifier ) || empty( $redirect_uri ) ) {
            $this->json_error( 'invalid_request', 'Missing required parameters: code, client_id, code_verifier, redirect_uri.', 400 );
        }

        // Consume the code (single-use).
        $code_data = Token_Store::consume_auth_code( $code );
        if ( ! $code_data ) {
            $this->json_error( 'invalid_grant', 'Authorization code is invalid, expired, or already used.', 400 );
        }

        // Validate client_id.
        if ( ! hash_equals( $code_data['client_id'], $client_id ) ) {
            $this->json_error( 'invalid_grant', 'client_id mismatch.', 400 );
        }

        // Validate redirect_uri (must match exactly).
        if ( $redirect_uri !== $code_data['redirect_uri'] ) {
            $this->json_error( 'invalid_grant', 'redirect_uri mismatch.', 400 );
        }

        // Verify PKCE.
        if ( ! PKCE::verify( $code_verifier, $code_data['code_challenge'] ) ) {
            $this->json_error( 'invalid_grant', 'PKCE verification failed.', 400 );
        }

        // Refresh CIMD metadata for URL-shaped client_ids. Idempotent no-op
        // for standard dynamic-registration client_ids.
        Token_Store::resolve_cimd_client( $client_id );

        // Authenticate confidential clients.
        $client = Token_Store::get_client( $client_id );
        if ( $client && 'client_secret_post' === ( $client['token_endpoint_auth_method'] ?? 'none' ) ) {
            $client_secret = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';
            if ( empty( $client_secret ) || ! hash_equals( $client['client_secret_hash'], hash( 'sha256', $client_secret ) ) ) {
                $this->json_error( 'invalid_client', 'Client authentication failed.', 401 );
            }
        }

        // Issue tokens.
        $tokens = Token_Store::create_token_pair( $client_id, $code_data['user_id'], $code_data['scope'] ?? '' );

        // Include resource indicator if client sent one (RFC 8707).
        $resource = isset( $_POST['resource'] ) ? esc_url_raw( wp_unslash( $_POST['resource'] ) ) : '';
        if ( ! empty( $resource ) ) {
            $tokens['resource'] = $resource;
            $this->log_event( 'resource_indicator', 'token resource=' . $resource, 200, 'success' );
        }

        $this->log_event( 'token_issued', 'Access + refresh tokens issued via authorization_code grant.', 200, 'success' );

        $this->json_response( $tokens, 200, [ 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache' ] );
    }

    /**
     * Refresh an access token.
     */
    private function token_refresh() {
        $refresh_token = isset( $_POST['refresh_token'] ) ? sanitize_text_field( wp_unslash( $_POST['refresh_token'] ) ) : '';
        $client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';

        if ( empty( $refresh_token ) || empty( $client_id ) ) {
            $this->json_error( 'invalid_request', 'Missing required parameters: refresh_token, client_id.', 400 );
        }

        // Authenticate confidential clients before consuming the refresh
        // token so the rotation step only runs on requests that pass client
        // auth. Mirrors the block in token_authorization_code(). Unknown
        // client_ids fall through to the invalid_grant path below so this
        // doesn't act as a client-id enumeration oracle.
        $client = Token_Store::get_client( $client_id );
        if ( $client && 'client_secret_post' === ( $client['token_endpoint_auth_method'] ?? 'none' ) ) {
            $client_secret = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';
            if ( empty( $client_secret ) || ! hash_equals( $client['client_secret_hash'], hash( 'sha256', $client_secret ) ) ) {
                $this->json_error( 'invalid_client', 'Client authentication failed.', 401 );
            }
        }

        // Consume the refresh token (rotation — old one is revoked).
        $token_data = Token_Store::consume_refresh_token( $refresh_token );
        if ( ! $token_data ) {
            $this->json_error( 'invalid_grant', 'Refresh token is invalid, expired, or revoked.', 400 );
        }

        // Validate client_id (timing-safe).
        if ( ! hash_equals( $token_data['client_id'], $client_id ) ) {
            $this->json_error( 'invalid_grant', 'client_id mismatch.', 400 );
        }

        // Issue new token pair.
        $tokens = Token_Store::create_token_pair( $client_id, (int) $token_data['user_id'], $token_data['scope'] ?? '' );

        $this->log_event( 'token_refreshed', 'Access + refresh tokens rotated via refresh_token grant.', 200, 'success' );

        $this->json_response( $tokens, 200, [ 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache' ] );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    /**
     * Send a JSON response and exit.
     *
     * Default policy: no-store, to defeat aggressive edge caches (CDN, host-
     * level fastcgi cache, generic reverse proxies) that would otherwise key
     * cache by URL only and serve a stale 4xx response to subsequent requests
     * of any method.
     * Discovery/metadata endpoints opt-in to public caching by passing their
     * own Cache-Control header in $extra_headers.
     */
    private function json_response( $data, $status = 200, $extra_headers = [] ) {
        status_header( $status );
        header( 'Content-Type: application/json; charset=utf-8' );

        if ( ! isset( $extra_headers['Cache-Control'] ) ) {
            header( 'Cache-Control: no-store, no-cache, must-revalidate' );
            header( 'Pragma: no-cache' );
        }

        foreach ( $extra_headers as $key => $value ) {
            header( $key . ': ' . $value );
        }
        echo wp_json_encode( $data );
        exit;
    }

    /**
     * Send an OAuth error response and exit.
     */
    private function json_error( $error, $description, $status = 400 ) {
        $this->log_event( $error, $description, $status );

        $this->json_response(
            [
                'error'             => $error,
                'error_description' => $description,
            ],
            $status
        );
    }

    /**
     * Log an OAuth event to the royal_mcp_logs table.
     *
     * STRICT SAFELIST: this method only logs values that are already public
     * (client_id is in URLs, grant_type is a fixed enum, our code strings
     * are hardcoded) plus the request metadata WP already exposes (IP, UA,
     * REQUEST_URI). If a future change needs more context, expand this
     * safelist deliberately — DO NOT add the raw POST body, authorization
     * code, code_verifier, client_secret, refresh_token, or access_token
     * here under any circumstance. These are credentials in flight and
     * must never reach the logs table.
     *
     * @param string $code        Short machine-readable code (error name or success verb).
     * @param string $description Human-readable message.
     * @param int    $http_status HTTP status code returned to the client.
     * @param string $log_status  'error' (default) or 'success'.
     */
    private function log_event( $code, $description, $http_status, $log_status = 'error' ) {
        global $wpdb;

        // Read public OAuth identifiers from POST first (token/register/authorize-POST),
        // fall back to GET (authorize-GET). Avoid $_REQUEST — its contents depend on PHP's
        // request_order config and can include $_COOKIE on some hosts.
        $client_id     = $this->log_pick_param( 'client_id' );
        $grant_type    = $this->log_pick_param( 'grant_type' );
        $response_type = $this->log_pick_param( 'response_type' );

        $request_meta = [
            'method'        => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
            'uri'           => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
            'ip'            => $this->get_client_ip(),
            'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
            'client_id'     => $client_id,
            'grant_type'    => $grant_type,
            'response_type' => $response_type,
        ];

        $response_meta = [
            'http_status' => (int) $http_status,
            'code'        => $code,
            'description' => $description,
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct insert is intentional; this is the logs table.
        $wpdb->insert(
            $wpdb->prefix . 'royal_mcp_logs',
            [
                'mcp_server'    => 'OAuth Server',
                'action'        => 'oauth:' . $this->current_action,
                'request_data'  => wp_json_encode( $request_meta ),
                'response_data' => wp_json_encode( $response_meta ),
                'status'        => 'success' === $log_status ? 'success' : 'error',
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Read a public OAuth identifier from $_POST or $_GET (POST wins).
     * Used only by log_event() for fields we already treat as non-secret
     * (client_id, grant_type, response_type). Never use for secrets.
     */
    private function log_pick_param( $key ) {
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- OAuth endpoints are intentionally nonceless; logged values are public identifiers.
        if ( isset( $_POST[ $key ] ) ) {
            return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
        }
        if ( isset( $_GET[ $key ] ) ) {
            return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        return '';
    }

    /**
     * Redirect to the client with an error (authorize endpoint).
     */
    private function authorize_error( $redirect_uri, $state, $error, $description ) {
        // Log every authorize-error redirect; the client (e.g. claude.ai) sees this
        // as a query string but our server otherwise has no record of it.
        $this->log_event( $error, $description, 302 );

        if ( empty( $redirect_uri ) ) {
            wp_die( esc_html( $description ), esc_html__( 'Authorization Error', 'royal-mcp' ), [ 'response' => 400 ] );
        }

        $redirect = self::build_authorize_redirect_url(
            $redirect_uri,
            [
                'error'             => $error,
                'error_description' => $description,
                'state'             => $state,
            ]
        );

        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- OAuth error redirect to client's registered callback URI.
        wp_redirect( $redirect );
        exit;
    }

    /**
     * Build an /authorize response redirect URL with the RFC 9207 `iss`
     * parameter automatically appended. Both the success path (code + state)
     * and the error path (error + error_description + state) route through
     * this helper so every /authorize response carries the issuer identifier
     * RFC 9207 requires.
     *
     * The `iss` value is sourced from the AS metadata document so it stays
     * in lock-step with `.well-known/oauth-authorization-server` — clients
     * that pinned to the metadata issuer at registration will match the
     * redirect without a separate discovery round-trip.
     *
     * @param string $redirect_uri Client callback URI to append to.
     * @param array  $params       Response parameters (code/state, or error/state).
     * @return string The final redirect URL with iss appended.
     */
    public static function build_authorize_redirect_url( $redirect_uri, array $params ) {
        $metadata      = self::build_authorization_server_metadata();
        $params['iss'] = $metadata['issuer'];
        return add_query_arg( $params, $redirect_uri );
    }

    /**
     * Validate a redirect URI: HTTPS, http:// loopback, or a private-use app
     * scheme (RFC 8252). Shared with Token_Store so /register and /authorize
     * agree on what is acceptable.
     */
    private function is_valid_redirect_uri( $uri ) {
        return Token_Store::is_acceptable_redirect_uri( $uri );
    }

    /**
     * Get the client IP address. Delegates to the MCP-side resolver so both
     * OAuth-side and /mcp-side rate limits key off the same IP-resolution
     * policy — trusted-proxy allowlist for X-Forwarded-For, CF-Ray gate for
     * CF-Connecting-IP, REMOTE_ADDR fallback.
     */
    private function get_client_ip() {
        return \Royal_MCP\MCP\Server::resolve_client_ip();
    }
}
