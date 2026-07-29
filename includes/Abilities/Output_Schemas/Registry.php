<?php
/**
 * Dispatcher for per-integration output-schema classes.
 *
 * Registrar calls Registry::get_for_tool( $tool_name ) and gets back a JSON schema
 * (or null when no schema is defined). Prefix routing mirrors Registrar::dispatch_category
 * so ownership of a tool's schema and its category always align.
 *
 * Returning null is safe — WP core WP_Ability::validate_output() skips validation
 * when output_schema is empty, so unmapped tools continue to work with the same
 * loose behavior as before this ship.
 */

namespace Royal_MCP\Abilities\Output_Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Registry {

	/**
	 * Resolve the output schema for a tool. Prefix-dispatch to per-integration class.
	 */
	public static function get_for_tool( string $tool_name ): ?array {
		// Integration prefixes come first — longest / most specific first.
		if ( strpos( $tool_name, 'wc_' ) === 0 )         return WooCommerce::get( $tool_name );
		if ( strpos( $tool_name, 'elementor_' ) === 0 )  return Elementor::get( $tool_name );
		if ( strpos( $tool_name, 'fc_' ) === 0 )         return ForgeCache::get( $tool_name );
		if ( strpos( $tool_name, 'sv_' ) === 0 )         return SiteVault::get( $tool_name );
		if ( strpos( $tool_name, 'gp_' ) === 0 )         return GuardPress::get( $tool_name );
		if ( strpos( $tool_name, 'rlinks_' ) === 0 )     return RoyalLinks::get( $tool_name );
		if ( strpos( $tool_name, 'rl_' ) === 0 )         return RoyalLedger::get( $tool_name );
		if ( strpos( $tool_name, 'acf_' ) === 0 )        return ACF::get( $tool_name );
		if ( strpos( $tool_name, 'raif_' ) === 0 )       return RAIF::get( $tool_name );
		if ( strpos( $tool_name, 'redirection_' ) === 0 ) return Redirection::get( $tool_name );

		// Core namespace: wp_* + royal_mcp_*.
		return Core::get( $tool_name );
	}
}
