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
			'divi' => array(
				'label'       => __( 'Royal MCP: Divi', 'royal-mcp' ),
				'description' => __( 'Divi page and library operations: outline, find and replace, clone, image swap, templates and presets.', 'royal-mcp' ),
			),
			'yoast' => array(
				'label'       => __( 'Royal MCP: Yoast SEO', 'royal-mcp' ),
				'description' => __( 'Yoast SEO meta, schema, internal links and redirects.', 'royal-mcp' ),
			),
			'wordfence' => array(
				'label'       => __( 'Royal MCP: Wordfence', 'royal-mcp' ),
				'description' => __( 'Wordfence security status, scan results, blocked IPs, failed logins and blocked requests.', 'royal-mcp' ),
			),
			'litespeed' => array(
				'label'       => __( 'Royal MCP: LiteSpeed Cache', 'royal-mcp' ),
				'description' => __( 'LiteSpeed Cache status, settings and purging.', 'royal-mcp' ),
			),
			'updraftplus' => array(
				'label'       => __( 'Royal MCP: UpdraftPlus', 'royal-mcp' ),
				'description' => __( 'UpdraftPlus backup history, status, schedule and backups on demand.', 'royal-mcp' ),
			),
			'wpforms' => array(
				'label'       => __( 'Royal MCP: WPForms', 'royal-mcp' ),
				'description' => __( 'WPForms forms and submissions.', 'royal-mcp' ),
			),
			'solid-security' => array(
				'label'       => __( 'Royal MCP: Solid Security', 'royal-mcp' ),
				'description' => __( 'Solid Security status, lockouts, events and IP bans.', 'royal-mcp' ),
			),
			'contact-form-7' => array(
				'label'       => __( 'Royal MCP: Contact Form 7', 'royal-mcp' ),
				'description' => __( 'Contact Form 7 forms and submissions.', 'royal-mcp' ),
			),
			'monsterinsights' => array(
				'label'       => __( 'Royal MCP: MonsterInsights', 'royal-mcp' ),
				'description' => __( 'MonsterInsights analytics overview, top pages, sources and search queries.', 'royal-mcp' ),
			),
			'w3-total-cache' => array(
				'label'       => __( 'Royal MCP: W3 Total Cache', 'royal-mcp' ),
				'description' => __( 'W3 Total Cache configuration, purging and statistics.', 'royal-mcp' ),
			),
			'duplicator' => array(
				'label'       => __( 'Royal MCP: Duplicator', 'royal-mcp' ),
				'description' => __( 'Duplicator migration packages and installers.', 'royal-mcp' ),
			),
			'buddypress' => array(
				'label'       => __( 'Royal MCP: BuddyPress', 'royal-mcp' ),
				'description' => __( 'BuddyPress members, groups and activity.', 'royal-mcp' ),
			),
			'tablepress'    => array(
				'label'       => __( 'Royal MCP: TablePress', 'royal-mcp' ),
				'description' => __( 'TablePress tables: list, read, create, copy, export and change cells.', 'royal-mcp' ),
			),
			'code-snippets' => array(
				'label'       => __( 'Royal MCP: Code Snippets', 'royal-mcp' ),
				'description' => __( 'Code Snippets: list and read snippets, recent deactivations, deactivate a snippet, change name, description and tags.', 'royal-mcp' ),
			),
			'wpcode'        => array(
				'label'       => __( 'Royal MCP: WPCode', 'royal-mcp' ),
				'description' => __( 'WPCode snippets, read only: list, read one snippet, read its status and last error.', 'royal-mcp' ),
			),
			'complianz'     => array(
				'label'       => __( 'Royal MCP: Complianz', 'royal-mcp' ),
				'description' => __( 'Complianz consent setup: status, cookies, services, banner texts and legal documents; change banner texts and cookie descriptions.', 'royal-mcp' ),
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
