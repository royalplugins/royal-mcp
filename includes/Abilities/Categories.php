<?php
/**
 * Pre-registers the 10 Royal MCP ability categories on wp_abilities_api_init (priority 5),
 * before ability registration walks the tool registry at priority 10.
 *
 * WP core requires every ability to reference a pre-registered category slug — an ability
 * whose `category` arg does not resolve to a registered category throws at registration time.
 */

namespace Royal_MCP\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Categories {

	const NAMESPACE_PREFIX = 'royal-mcp';

	/**
	 * Category slug (short key) → label + description.
	 *
	 * Keyed by the short slug; full registered slug is composed via {@see category_slug()}.
	 */
	private static function catalog(): array {
		return array(
			'core'         => array(
				'label'       => __( 'Royal MCP: Core', 'royal-mcp' ),
				'description' => __( 'Core WordPress operations: posts, pages, media, terms, comments, users, options, menus, themes, SEO meta, permalinks, revisions, cron, error log, connection health, search, site info.', 'royal-mcp' ),
			),
			'woocommerce'  => array(
				'label'       => __( 'Royal MCP: WooCommerce', 'royal-mcp' ),
				'description' => __( 'WooCommerce products, orders, coupons, variations, customers, and store stats.', 'royal-mcp' ),
			),
			'elementor'    => array(
				'label'       => __( 'Royal MCP: Elementor', 'royal-mcp' ),
				'description' => __( 'Elementor page operations: outline read, clone, replace text, replace image, import template, add widget, list local templates.', 'royal-mcp' ),
			),
			'forgecache'   => array(
				'label'       => __( 'Royal MCP: ForgeCache', 'royal-mcp' ),
				'description' => __( 'ForgeCache cache statistics, URL purge, and full cache clear.', 'royal-mcp' ),
			),
			'sitevault'    => array(
				'label'       => __( 'Royal MCP: SiteVault', 'royal-mcp' ),
				'description' => __( 'SiteVault backups: create, read, status, schedules, and stats.', 'royal-mcp' ),
			),
			'guardpress'   => array(
				'label'       => __( 'Royal MCP: GuardPress', 'royal-mcp' ),
				'description' => __( 'GuardPress security: audit log, blocked IPs, failed logins, vulnerability scan, and security status.', 'royal-mcp' ),
			),
			'royal-links'  => array(
				'label'       => __( 'Royal MCP: Royal Links', 'royal-mcp' ),
				'description' => __( 'Royal Links link management and click statistics.', 'royal-mcp' ),
			),
			'royal-ledger' => array(
				'label'       => __( 'Royal MCP: Royal Ledger', 'royal-mcp' ),
				'description' => __( 'Royal Ledger renewals, costs, and license keys.', 'royal-mcp' ),
			),
			'acf'          => array(
				'label'       => __( 'Royal MCP: ACF', 'royal-mcp' ),
				'description' => __( 'Advanced Custom Fields (ACF) field read/update and group enumeration.', 'royal-mcp' ),
			),
			'raif'         => array(
				'label'       => __( 'Royal MCP: Royal AI Firewall', 'royal-mcp' ),
				'description' => __( 'Royal AI Firewall bot classification, block list, and firewall statistics.', 'royal-mcp' ),
			),
			'redirection'  => array(
				'label'       => __( 'Royal MCP: Redirection', 'royal-mcp' ),
				'description' => __( 'Redirection plugin: list/create/update redirects, list groups.', 'royal-mcp' ),
			),
		);
	}

	/**
	 * Fires on wp_abilities_api_init at priority 5, before ability registration at priority 10.
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		foreach ( self::catalog() as $short_slug => $spec ) {
			wp_register_ability_category(
				self::category_slug( $short_slug ),
				array(
					'label'       => $spec['label'],
					'description' => $spec['description'],
				)
			);
		}
	}

	/**
	 * Compose the full registered category slug from a short key.
	 */
	public static function category_slug( string $short_slug ): string {
		return self::NAMESPACE_PREFIX . '-' . $short_slug;
	}

	/**
	 * All registered category slugs (full form). Used by e2e assertions and by the Registrar
	 * lookup path when dispatching an ability to its category.
	 */
	public static function get_all_slugs(): array {
		return array_map( array( __CLASS__, 'category_slug' ), array_keys( self::catalog() ) );
	}
}
