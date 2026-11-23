<?php
namespace Royal_MCP\MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Endpoint tool profiles — trims the tools/list response by name prefix so
 * MCP clients that fail on large tool sets can request a curated subset by
 * appending `?tools=<profile>` to the MCP endpoint URL.
 *
 * Ships three stock profiles at Free tier:
 *   ?tools=core     — WordPress core + protocol meta-tools (wp_*, royal_mcp_*, mcp_*)
 *                     and the three routing tools
 *   ?tools=seopress — core plus SEOPress integration (seopress_*)
 *   ?tools=compact  — the three routing tools plus a short list of everyday
 *                     read tools; every other tool stays reachable through
 *                     execute_tool, subject to the usual checks
 *
 * Extension points:
 *   royal_mcp_tools                  — final filter over the full tool list
 *                                       (applied in Server::get_tools)
 *   royal_mcp_tool_profile_prefixes  — filter to register additional profiles
 *                                       by mapping profile name → allowed
 *                                       tool-name prefixes. The Pro custom-
 *                                       profile builder hooks this.
 */
class Tool_Profiles {

    /**
     * Programmatic profile override for CLI / test contexts where $_GET is
     * not populated. Web requests read $_GET['tools'] instead.
     */
    private static $override_profile = null;

    /** The stock profiles: name → tool-name prefixes kept. */
    const STOCK_PROFILES = [
        'core'     => [ 'wp_', 'royal_mcp_', 'mcp_' ],
        'seopress' => [ 'wp_', 'royal_mcp_', 'mcp_', 'seopress_' ],
    ];

    /** Tools that let a client find and call any other tool; every stock profile keeps them. */
    const ROUTING_TOOLS = [ 'discover_tools', 'get_tool_info', 'execute_tool' ];

    /** Name of the profile that lists only the routing tools and COMPACT_TOOLS. */
    const COMPACT_PROFILE = 'compact';

    /** Everyday read tools the compact profile lists next to the routing tools (exact names). */
    const COMPACT_TOOLS = [
        'wp_get_site_info',
        'wp_search',
        'wp_get_posts',
        'wp_get_post',
        'wp_get_pages',
        'wp_get_page',
        'wp_get_media',
        'wp_find_media',
        'wp_get_categories',
        'wp_get_option',
        'wp_get_plugins',
        'mcp_list_recent_changes',
        'mcp_get_change',
        'mcp_get_skill',
        'royal_mcp_connection_health',
    ];

    /** Names of the stock profiles, which no custom profile may take. */
    public static function stock_profile_names() {
        return array_merge( array_keys( self::STOCK_PROFILES ), [ self::COMPACT_PROFILE ] );
    }

    /**
     * Whether the endpoint's profile only shortens the list and leaves every
     * other tool reachable through execute_tool.
     */
    public static function current_profile_is_compact() {
        return self::COMPACT_PROFILE === self::current_profile();
    }

    /**
     * Register the profile filter. Called from plugin bootstrap.
     */
    public static function register() {
        add_filter( 'royal_mcp_tools', [ __CLASS__, 'apply_profile' ], 20 );
    }

    /**
     * Set the active profile explicitly, bypassing $_GET lookup. Test-only.
     *
     * @param string|null $profile Profile name, or null to reset.
     */
    public static function set_override_profile( $profile ) {
        self::$override_profile = ( is_string( $profile ) && $profile !== '' ) ? $profile : null;
    }

    /**
     * Filter callback — trims tool list to the current profile's prefix set.
     * Fail-open: unknown profile names return the unfiltered list rather
     * than an empty list, so a typo doesn't silently break the client.
     *
     * @param array $tools Tool definitions.
     * @return array Filtered tool list.
     */
    public static function apply_profile( $tools ) {
        $profile = self::current_profile();
        if ( $profile === null ) {
            return $tools;
        }

        if ( self::COMPACT_PROFILE === $profile ) {
            $keep = array_fill_keys( array_merge( self::ROUTING_TOOLS, self::COMPACT_TOOLS ), true );
            return array_values( array_filter( $tools, static function ( $tool ) use ( $keep ) {
                return isset( $tool['name'] ) && isset( $keep[ (string) $tool['name'] ] );
            } ) );
        }

        $prefixes = self::get_profile_prefixes( $profile );
        if ( empty( $prefixes ) ) {
            return $tools;
        }

        $routing  = isset( self::STOCK_PROFILES[ $profile ] ) ? array_fill_keys( self::ROUTING_TOOLS, true ) : [];
        $filtered = [];
        foreach ( $tools as $tool ) {
            $name = isset( $tool['name'] ) ? (string) $tool['name'] : '';
            if ( isset( $routing[ $name ] ) ) {
                $filtered[] = $tool;
                continue;
            }
            foreach ( $prefixes as $prefix ) {
                if ( strpos( $name, $prefix ) === 0 ) {
                    $filtered[] = $tool;
                    break;
                }
            }
        }
        return $filtered;
    }

    /**
     * Resolve the active profile name from override or $_GET.
     *
     * @return string|null Profile name or null when no profile selected.
     */
    private static function current_profile() {
        if ( self::$override_profile !== null ) {
            return self::$override_profile;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tool-list filter; profile name is sanitized and treated as opaque prefix key.
        if ( ! empty( $_GET['tools'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return sanitize_key( wp_unslash( $_GET['tools'] ) );
        }
        return null;
    }

    /**
     * Get the prefix allowlist for a profile name. Extensible via the
     * royal_mcp_tool_profile_prefixes filter.
     *
     * @param string $profile Profile name.
     * @return array List of tool-name prefixes to keep.
     */
    private static function get_profile_prefixes( $profile ) {
        $profiles = self::STOCK_PROFILES;
        /**
         * Filter the profile → prefixes map. Pro adds custom profiles here.
         *
         * @param array<string,array<string>> $profiles Profile name → array of allowed name prefixes.
         */
        $filtered = apply_filters( 'royal_mcp_tool_profile_prefixes', $profiles );
        // Reject non-array returns from misbehaving callbacks — fall back to stock profiles.
        if ( ! is_array( $filtered ) ) {
            $filtered = $profiles;
        }
        return isset( $filtered[ $profile ] ) && is_array( $filtered[ $profile ] ) ? $filtered[ $profile ] : [];
    }
}
