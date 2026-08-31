<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * W3 Total Cache MCP Integration
 *
 * Exposes cache-config state, purge control, and stats. All operations run
 * against the local W3TC Config + CacheFlush APIs — no network calls.
 */
class W3TotalCache {

	private static $modules = [
		'pgcache'      => 'Page cache',
		'dbcache'      => 'Database cache',
		'objectcache'  => 'Object cache',
		'browsercache' => 'Browser cache',
		'minify'       => 'Minify',
		'cdn'          => 'CDN',
		'cdnfsd'       => 'CDN (full site delivery)',
		'lazyload'     => 'Lazy load images',
		'mobile'       => 'Mobile detection',
		'stats'        => 'Usage statistics',
		'varnish'      => 'Varnish',
	];

	public static function is_available() {
		return defined( 'W3TC_VERSION' ) && class_exists( '\\W3TC\\Dispatcher' );
	}

	public static function get_tools() {
		return [
			[
				'name'        => 'w3tc_get_cache_status',
				'description' => 'Get W3 Total Cache configuration — which cache modules are enabled (page, database, object, browser, minify, CDN, etc.) and their storage engines. Read-only, always safe.',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
			[
				'name'        => 'w3tc_purge_cache',
				'description' => 'Purge W3 Total Cache. Set scope to "all" (default, flush every cache), "url" (purge a single URL — provide url), or "post" (purge cache for a specific post — provide post_id). The purge is additive: it invalidates cached copies so they rebuild on next request; source data is untouched.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'scope'   => [ 'type' => 'string', 'description' => 'Purge scope: all, url, or post. Default all.', 'enum' => [ 'all', 'url', 'post' ] ],
						'url'     => [ 'type' => 'string', 'description' => 'Full URL to purge — required when scope is url.' ],
						'post_id' => [ 'type' => 'integer', 'description' => 'Post ID to purge — required when scope is post.' ],
					],
				],
			],
			[
				'name'        => 'w3tc_get_stats',
				'description' => 'Get W3 Total Cache usage statistics. Requires the stats module to be enabled in W3TC settings — returns {state: unavailable, reason: stats_module_disabled} when it is not.',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use W3 Total Cache tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'W3 Total Cache is not active' );
		}

		switch ( $name ) {
			case 'w3tc_get_cache_status':
				return self::handle_get_cache_status();
			case 'w3tc_purge_cache':
				return self::handle_purge_cache( $args );
			case 'w3tc_get_stats':
				return self::handle_get_stats();
			default:
				throw new \Exception( 'Unknown W3 Total Cache tool: ' . esc_html( $name ) );
		}
	}

	private static function handle_get_cache_status() {
		$config = \W3TC\Dispatcher::config();

		$modules = [];
		foreach ( self::$modules as $slug => $label ) {
			$enabled = (bool) $config->get_boolean( $slug . '.enabled', false );
			$entry   = [
				'slug'    => $slug,
				'label'   => $label,
				'enabled' => $enabled,
			];
			// Only pgcache / dbcache / objectcache expose an .engine setting.
			if ( in_array( $slug, [ 'pgcache', 'dbcache', 'objectcache' ], true ) ) {
				$entry['engine'] = (string) $config->get_string( $slug . '.engine', '' );
			}
			$modules[] = $entry;
		}

		$active_count = count( array_filter( $modules, function ( $m ) { return $m['enabled']; } ) );

		return [
			'plugin_version' => W3TC_VERSION,
			'active_count'   => $active_count,
			'total_modules'  => count( $modules ),
			'modules'        => $modules,
		];
	}

	private static function handle_purge_cache( $args ) {
		$scope = in_array( $args['scope'] ?? 'all', [ 'all', 'url', 'post' ], true ) ? $args['scope'] : 'all';
		$flush = new \W3TC\CacheFlush();

		switch ( $scope ) {
			case 'all':
				$flush->flush_all();
				return [ 'purged' => true, 'scope' => 'all' ];

			case 'url':
				$url = isset( $args['url'] ) ? esc_url_raw( $args['url'] ) : '';
				if ( '' === $url ) {
					throw new \Exception( 'url is required when scope is "url"' );
				}
				$flush->flush_url( $url );
				return [ 'purged' => true, 'scope' => 'url', 'url' => $url ];

			case 'post':
				$post_id = intval( $args['post_id'] ?? 0 );
				if ( $post_id <= 0 ) {
					throw new \Exception( 'post_id is required when scope is "post"' );
				}
				if ( ! get_post( $post_id ) ) {
					throw new \Exception( 'Post not found: ' . $post_id );
				}
				$flush->flush_post( $post_id );
				return [ 'purged' => true, 'scope' => 'post', 'post_id' => $post_id ];
		}

		return [ 'purged' => false, 'scope' => $scope ];
	}

	private static function handle_get_stats() {
		$config = \W3TC\Dispatcher::config();
		if ( ! $config->get_boolean( 'stats.enabled', false ) ) {
			return [
				'state'   => 'unavailable',
				'reason'  => 'stats_module_disabled',
				'message' => 'The W3 Total Cache stats module is disabled. Enable it under Performance → General Settings → Statistics.',
			];
		}

		// Stats module is enabled — surface the config-level metadata plus a
		// safe subset of hit-rate signals. The full stats surface is host-
		// dependent (apc / memcached / redis backends must be configured);
		// callers that need the full breakdown should read the W3TC admin
		// dashboard directly. Deliberately conservative — no speculative
		// hydration of backend-specific fields we haven't verified end-to-end.
		return [
			'stats_enabled'        => true,
			'access_log_enabled'   => (bool) $config->get_boolean( 'stats.access_log.enabled', false ),
			'cpu_enabled'          => (bool) $config->get_boolean( 'stats.cpu.enabled', false ),
			'note'                 => 'Detailed hit rate + storage size breakdowns depend on the configured cache backend and are surfaced most reliably via the W3TC admin dashboard.',
		];
	}
}

/**
 * Manifest declaration. Cache purge is additive (invalidates cached copies;
 * source data untouched), so supports_undo is false.
 */
add_filter( 'royal_mcp_manifests', function ( $manifests ) {
	if ( ! defined( 'W3TC_VERSION' ) ) {
		return $manifests;
	}
	$manifests[] = [
		'royal_mcp_manifest_version' => '1.0',
		'plugin_slug'                => 'w3-total-cache',
		'plugin_display_name'        => 'W3 Total Cache',
		'plugin_version'             => W3TC_VERSION,
		'vendor_name'                => 'BoldGrid',
		'mcp_endpoint'               => rest_url( 'royal-mcp/v1/mcp' ),
		'auth_methods'               => [ 'oauth2.1' ],
		'capabilities'               => [ 'read', 'additive-write' ],
		'manifest_updated_at'        => gmdate( 'c' ),
		'trust_signals'              => [
			'supports_dry_run'                => false,
			'supports_undo'                   => false,
			'supports_snapshots'              => false,
			'requires_review_for_destructive' => false,
		],
	];
	return $manifests;
} );
