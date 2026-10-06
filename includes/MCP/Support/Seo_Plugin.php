<?php
/**
 * Which SEO plugin owns SEO meta on this site — one answer for every tool.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seo_Plugin {

	/**
	 * When several SEO plugins are active, the first match wins:
	 * SEObolt, Yoast, Rank Math, SEOPress, AIOSEO.
	 *
	 * @return string 'seobolt' | 'yoast' | 'rankmath' | 'seopress' | 'aioseo' | 'none'
	 */
	public static function detect() {
		$detected = 'none';
		if ( self::seobolt_active() ) {
			$detected = 'seobolt';
		} elseif ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			$detected = 'yoast';
		} elseif ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$detected = 'rankmath';
		} elseif ( defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_get_service' ) ) {
			$detected = 'seopress';
		} elseif ( defined( 'AIOSEO_VERSION' ) || defined( 'AIOSEO_FILE' ) || function_exists( 'aioseo' ) ) {
			$detected = 'aioseo';
		}

		/**
		 * Filters the detected SEO plugin.
		 *
		 * @param string $detected Plugin key.
		 */
		$filtered = apply_filters( 'royal_mcp_seo_plugin_detected', $detected );
		return in_array( $filtered, [ 'seobolt', 'yoast', 'rankmath', 'seopress', 'aioseo', 'none' ], true ) ? $filtered : $detected;
	}

	/**
	 * SEObolt Pro or Lite active (plugin list first; a constant alone can
	 * linger from a plugin deactivated earlier in the same request).
	 *
	 * @return bool
	 */
	private static function seobolt_active() {
		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'is_plugin_active' ) ) {
			foreach ( [ 'seobolt-pro/seobolt-pro.php', 'seobolt/seobolt.php' ] as $slug ) {
				if ( is_plugin_active( $slug ) ) {
					return true;
				}
			}
			return false;
		}
		return defined( 'SEOBOLT_VERSION' );
	}
}
