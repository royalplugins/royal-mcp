<?php
namespace Royal_MCP\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cloudflare-proposed Agent Skills Index served at
 * /.well-known/agent-skills/index.json. Publishes the site's MCP tool set
 * as agent-callable skills grouped by integration category. Auto-populates
 * from Server::get_all_tools() so newly-installed integrations show up
 * without a code change here.
 *
 * Categories only — full tool names + inputSchemas remain gated behind the
 * auth-required tools/list endpoint.
 */
class Agent_Skills_Index {

    const CACHE_KEY = 'royal_mcp_agent_skills_index_json';
    const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

    public static function handle_request( $request ) {
        unset( $request );
        $response = new \WP_REST_Response( self::build_or_cached(), 200 );
        $response->header( 'Content-Type', 'application/json; charset=utf-8' );
        $response->header( 'Cache-Control', 'public, max-age=300' );
        return $response;
    }

    public static function build_or_cached(): array {
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $doc = self::build();
        set_transient( self::CACHE_KEY, $doc, self::CACHE_TTL );
        return $doc;
    }

    public static function build(): array {
        $home     = rtrim( (string) home_url(), '/' );
        $server   = new \Royal_MCP\MCP\Server();
        $tools    = $server->get_all_tools();
        $card_url = $home . '/.well-known/mcp/server-card.json';

        // Reuse the same prefix → category mapping the server card uses so
        // scanners that cross-reference the two documents see consistent
        // category names.
        $by_category = [];
        foreach ( $tools as $tool ) {
            $name = isset( $tool['name'] ) ? (string) $tool['name'] : '';
            if ( $name === '' ) {
                continue;
            }
            $underscore = strpos( $name, '_' );
            $prefix     = $underscore === false ? $name : substr( $name, 0, $underscore );
            $category   = self::prefix_to_category( $prefix );
            $by_category[ $category ] = ( $by_category[ $category ] ?? 0 ) + 1;
        }
        ksort( $by_category );

        $skills = [];
        foreach ( $by_category as $category => $count ) {
            $skills[] = [
                'name'        => $category,
                'description' => self::category_description( $category ),
                'endpoint'    => $home . '/mcp',
                'protocol'    => 'mcp/2026-07-28',
                'auth'        => 'oauth2 | session-cookie',
                'tool_count'  => (int) $count,
                'tools_url'   => $card_url,
            ];
        }

        $doc = [
            'skills'       => $skills,
            'version'      => defined( 'ROYAL_MCP_VERSION' ) ? ROYAL_MCP_VERSION : '',
            'generated_at' => gmdate( 'c' ),
        ];

        /**
         * Filter the agent-skills index before caching.
         * @param array $doc Index contents.
         */
        return (array) apply_filters( 'royal_mcp_agent_skills_index', $doc );
    }

    private static function prefix_to_category( string $prefix ): string {
        // Delegate to Server_Card so mapping stays in one place.
        $reflected = new \ReflectionClass( \Royal_MCP\Discovery\Server_Card::class );
        $method    = $reflected->getMethod( 'prefix_to_category' );
        $method->setAccessible( true );
        return (string) $method->invokeArgs( null, [ $prefix ] );
    }

    private static function category_description( string $category ): string {
        $descriptions = [
            'wordpress-core'          => 'Read + write WordPress posts, pages, users, terms, options, media.',
            'woocommerce'             => 'Query + manage WooCommerce products, orders, customers, coupons, subscriptions.',
            'elementor'               => 'Inspect and edit Elementor pages, widgets, templates, and dynamic tags.',
            'divi'                    => 'Inspect and edit Divi layouts, templates, and page structure.',
            'acf'                     => 'Read + write Advanced Custom Fields values and field group definitions.',
            'yoast-seo'               => 'Read + write Yoast SEO meta, schema, and internal-link suggestions.',
            'guardpress'              => 'Query security posture, failed logins, blocked IPs, and vulnerability scan results.',
            'royal-ai-firewall'       => 'Manage AI-bot policies, view recent hits, and query rollup analytics.',
            'sitevault'               => 'Trigger and inspect Royal SiteVault backups + schedules.',
            'forgecache'              => 'Query and purge ForgeCache page cache.',
            'royal-links'             => 'Manage Royal Links smart links, track click stats.',
            'wpforms'                 => 'Inspect WPForms form definitions and submission entries.',
            'contact-form-7'          => 'Inspect Contact Form 7 forms and submission logs.',
        ];
        return $descriptions[ $category ] ?? 'MCP tool bundle for the ' . $category . ' integration.';
    }
}
