<?php
namespace Royal_MCP\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Public MCP Server Card manifest served at /.well-known/mcp/server-card.json.
 *
 * Complements the protocol-side tools/list (auth-gated) with a public discovery
 * document scanners like the Cloudflare Agent Readiness scanner, Vercel is-agentic,
 * and Chrome Lighthouse 13.3+ Agentic Browsing audit expect at a stable path.
 * No auth required — all fields are already discoverable elsewhere (endpoints,
 * protocol versions, category-level tool counts) so publishing them here is
 * pure signal to scanners, no new surface for attackers.
 *
 * Cached in a 5-minute transient to survive scanner hammering without
 * re-walking the tool registry on every request.
 */
class Server_Card {

    const CACHE_KEY = 'royal_mcp_server_card_json';
    const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

    /**
     * REST callback registered against /royal-mcp/v1/discovery/server-card.
     * Returns a WP_REST_Response so WP handles Content-Type + status.
     */
    public static function handle_request( $request ) {
        unset( $request );
        $response = new \WP_REST_Response( self::build_or_cached(), 200 );
        $response->header( 'Content-Type', 'application/json; charset=utf-8' );
        $response->header( 'Cache-Control', 'public, max-age=300' );
        return $response;
    }

    /**
     * Build the card (or return the cached version). Cache invalidation is
     * time-based (5 min) plus manual — options-page save handlers can call
     * delete_transient( self::CACHE_KEY ) when a change materially affects
     * the card contents (e.g. webmcp_enabled toggle).
     */
    public static function build_or_cached(): array {
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $card = self::build();
        set_transient( self::CACHE_KEY, $card, self::CACHE_TTL );
        return $card;
    }

    /**
     * Assemble the card from live server state. Runtime cost is
     * Server::get_all_tools() (walks integration tool bundles), so this
     * always runs behind the cache.
     */
    public static function build(): array {
        $home    = rtrim( (string) home_url(), '/' );
        $server  = new \Royal_MCP\MCP\Server();
        $tools   = $server->get_all_tools();
        $summary = self::tools_summary( $tools );

        $settings         = get_option( 'royal_mcp_settings', [] );
        $webmcp_enabled   = ! empty( $settings['webmcp_enabled'] );

        $auth = [
            'methods' => [ 'oauth2' ],
            'oauth2'  => [
                'flows' => [ 'authorization_code' ],
                'pkce'  => 'S256_required',
                'dcr'   => 'supported',
            ],
        ];
        if ( $webmcp_enabled ) {
            $auth['methods'][]  = 'session-cookie';
            $auth['session-cookie'] = [
                'requires'       => 'X-WP-Nonce',
                'opt_in_option'  => 'royal_mcp_settings.webmcp_enabled',
                'nonce_action'   => 'wp_rest',
            ];
        }

        // Empty in the unauthenticated card so this endpoint doesn't act as
        // an install-fingerprint source. The serverInfo.version key stays
        // present to satisfy the schema; authenticated callers can read the
        // real version from the royal_mcp_connection_health tool.
        $version = '';

        // Shape follows SEP-1649 (modelcontextprotocol/modelcontextprotocol #2127):
        //   - serverInfo.name + serverInfo.version REQUIRED
        //   - endpoint (scalar URL) REQUIRED, transport-agnostic single canonical URL
        //   - capabilities REQUIRED with tools/resources/prompts booleans
        // Top-level `name`/`version`/`endpoints` retained as extras for backward
        // compat with the earlier shape + downstream tooling that reads them.
        $card = [
            'serverInfo'       => [
                'name'    => 'Royal MCP',
                'version' => $version,
            ],
            'endpoint'         => $home . '/mcp',
            'capabilities'     => [
                'tools'       => true,
                'resources'   => false,
                'prompts'     => false,
                'completions' => false,
            ],
            'name'             => 'Royal MCP',
            'description'      => 'WordPress MCP server exposing tools for content, WooCommerce, page builders, SEO, and site operations.',
            'version'          => $version,
            'protocolVersions' => \Royal_MCP\MCP\Server::SUPPORTED_PROTOCOL_VERSIONS,
            'endpoints'        => [
                'mcp'                         => $home . '/mcp',
                'authorizationServer'         => $home . '/.well-known/oauth-authorization-server',
                'protectedResource'           => $home . '/.well-known/oauth-protected-resource',
                // wp-json fallback URLs for managed hosts whose edge layer
                // reserves the root /.well-known/* prefix before requests
                // reach PHP (SiteGround, WP Engine, some cPanel edge). Both
                // fallback paths return byte-identical JSON to the root
                // paths, so scanners that discover either URL succeed.
                'authorizationServerFallback' => $home . '/wp-json/royal-mcp/v1/.well-known/oauth-authorization-server',
                'protectedResourceFallback'   => $home . '/wp-json/royal-mcp/v1/.well-known/oauth-protected-resource',
            ],
            'auth'             => $auth,
            'tools_summary'    => $summary,
            'documentation'    => 'https://royalplugins.com/support/royal-mcp/',
            'vendor'           => [
                'name' => 'Royal Plugins',
                'url'  => 'https://royalplugins.com',
            ],
        ];

        /**
         * Filter the assembled server card before caching.
         * @param array $card Card contents.
         */
        return (array) apply_filters( 'royal_mcp_server_card', $card );
    }

    /**
     * Group tools by prefix (first token before the underscore). Publish
     * counts + category names — NOT full tool names or schemas. Tool names
     * are only exposed to authenticated tools/list callers so a scanner
     * can't enumerate the full attack surface from this document.
     */
    private static function tools_summary( array $tools ): array {
        $total = count( $tools );
        $by_category = [];

        foreach ( $tools as $tool ) {
            $name = isset( $tool['name'] ) ? (string) $tool['name'] : '';
            if ( $name === '' ) {
                continue;
            }
            $underscore = strpos( $name, '_' );
            $prefix     = $underscore === false ? $name : substr( $name, 0, $underscore );
            $category   = self::prefix_to_category( $prefix );
            if ( ! isset( $by_category[ $category ] ) ) {
                $by_category[ $category ] = 0;
            }
            $by_category[ $category ]++;
        }

        ksort( $by_category );
        return [
            'total'             => $total,
            'categories'        => array_keys( $by_category ),
            'count_by_category' => $by_category,
        ];
    }

    /**
     * Map tool-name prefixes to human-friendly category names used in the
     * card. Unknown prefixes fall through as-is so newly-added integrations
     * appear in the summary without requiring a code change here.
     */
    private static function prefix_to_category( string $prefix ): string {
        $map = [
            'wp'              => 'wordpress-core',
            'wc'              => 'woocommerce',
            'wcs'             => 'woocommerce-subscriptions',
            'elementor'       => 'elementor',
            'divi'            => 'divi',
            'divi5'           => 'divi-5',
            'acf'             => 'acf',
            'yoast'           => 'yoast-seo',
            'rankmath'        => 'rank-math-seo',
            'aioseo'          => 'all-in-one-seo',
            'seopress'        => 'seopress',
            'seobolt'         => 'seobolt',
            'seo'             => 'seo-tools',
            'gp'              => 'guardpress',
            'raif'            => 'royal-ai-firewall',
            'solid'           => 'solid-security',
            'fc'              => 'forgecache',
            'w3tc'            => 'w3-total-cache',
            'sv'              => 'sitevault',
            'updraftplus'     => 'updraftplus',
            'duplicator'      => 'duplicator',
            'wpforms'         => 'wpforms',
            'formforge'       => 'formforge',
            'cf7'             => 'contact-form-7',
            'bp'              => 'buddypress',
            'rlinks'          => 'royal-links',
            'rl'              => 'royal-ledger',
            'rafp'            => 'royal-affiliate',
            'monsterinsights' => 'monsterinsights',
            'redirection'     => 'redirection',
            'rmcp'            => 'royal-mcp-diagnostics',
            'mcp'             => 'royal-mcp-diagnostics',
            'royal'           => 'royal-mcp-diagnostics',
        ];
        return $map[ $prefix ] ?? $prefix;
    }
}
