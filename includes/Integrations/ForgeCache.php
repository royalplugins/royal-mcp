<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ForgeCache MCP Integration
 *
 * Registers MCP tools for the ForgeCache page caching plugin.
 * Only loaded when ForgeCache is active.
 */
class ForgeCache {

	/**
	 * Check if ForgeCache is available.
	 */
	public static function is_available() {
		return class_exists( 'ForgeCache_Cache' );
	}

	/**
	 * Get tool definitions for MCP tools/list response.
	 */
	public static function get_tools() {
		// Always register so tools appear in MCP tools/list regardless of the
		// underlying plugin activation state. execute_tool gates at call time
		// with a clean 'not active' throw. Prevents ghost-tools UX where
		// activating a plugin post-MCP-connection requires the client to
		// reconnect before the tools become discoverable.

		return [
			[
				'name'        => 'fc_clear_cache',
				'description' => 'Clear the entire ForgeCache page cache. Use after a major site update, content migration, or when troubleshooting stale content.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
			],
			[
				'name'        => 'fc_get_cache_stats',
				'description' => 'Get ForgeCache statistics: total cached files, total size on disk, oldest and newest cached entries.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
			],
			[
				'name'        => 'fc_purge_url',
				'description' => 'Purge the ForgeCache entry for a single URL on this site. Resolves the URL to a post first (uses ForgeCache\'s post-scoped invalidator, also clears the homepage + archive cache for that post type). Falls back to a direct cache-file hash + delete for URLs that do not resolve to a single post — homepage on posts-front-page installs, blog index, category / tag / author / date archives, paginated /page/N/ URLs, custom rewrites, search result pages. Response identifies which path was used.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'url' => [ 'type' => 'string', 'description' => 'Full URL on this site (e.g. https://yoursite.com/about/ or https://yoursite.com/ for homepage)' ],
					],
					'required'   => [ 'url' ],
				],
			],
		];
	}

	/**
	 * Execute a ForgeCache MCP tool.
	 *
	 * @param string $name Tool name.
	 * @param array  $args Tool arguments.
	 * @return mixed Result data.
	 * @throws \Exception If tool fails.
	 */
	public static function execute_tool( $name, $args ) {
		// umbrella cap check fires BEFORE the active-check. Without
		// this order a Subscriber-tier OAuth Bearer would receive "ForgeCache
		// is not active" and learn whether the integration is present. The
		// per-case caps below still enforce the finer-grained gate
		// (manage_options for site-wide flushes; edit_post for per-post purge).
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new \Exception( 'You do not have permission to use ForgeCache tools.' );
		}

		if ( ! self::is_available() ) {
			throw new \Exception( 'ForgeCache is not active' );
		}

		switch ( $name ) {
			case 'fc_clear_cache':
				// cache management is admin-tier; flushing site cache
				// is destructive (forces re-generation across the whole site).
				if ( ! current_user_can( 'manage_options' ) ) {
					throw new \Exception( 'You do not have permission to clear the ForgeCache page cache.' );
				}
				\ForgeCache_Cache::clear_all_cache_static();
				return [
					'success' => true,
					'message' => 'ForgeCache page cache cleared.',
				];

			case 'fc_get_cache_stats':
				if ( ! current_user_can( 'manage_options' ) ) {
					throw new \Exception( 'You do not have permission to view ForgeCache stats.' );
				}
				$stats = \ForgeCache_Cache::get_cache_stats();
				return [
					'total_files'      => (int) ( $stats['total_files'] ?? 0 ),
					'total_size_bytes' => (int) ( $stats['total_size'] ?? 0 ),
					'total_size_human' => size_format( (int) ( $stats['total_size'] ?? 0 ) ),
					'oldest_file'      => isset( $stats['oldest_file'] ) && $stats['oldest_file'] ? gmdate( 'Y-m-d H:i:s', (int) $stats['oldest_file'] ) : null,
					'newest_file'      => isset( $stats['newest_file'] ) && $stats['newest_file'] ? gmdate( 'Y-m-d H:i:s', (int) $stats['newest_file'] ) : null,
				];

			case 'fc_purge_url':
				$url = esc_url_raw( $args['url'] ?? '' );
				if ( empty( $url ) ) {
					throw new \Exception( 'url is required' );
				}
				$post_id = url_to_postid( $url );

				// Path A — URL resolves to a real post. Use ForgeCache's own
				// invalidator so the homepage + archive purges chain fires.
				if ( $post_id ) {
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						throw new \Exception( 'You do not have permission to purge the cache for this post.' );
					}
					$cache = \ForgeCache_Cache::instance();
					if ( method_exists( $cache, 'clear_post_cache' ) ) {
						$cache->clear_post_cache( $post_id );
					}
					return [
						'success'    => true,
						'url'        => $url,
						'post_id'    => $post_id,
						'path'       => 'post_scoped',
						'message'    => 'Cache cleared for post ID ' . $post_id,
					];
				}

				// Path B — URL does not resolve to a single post (homepage
				// on posts-front-page installs, blog index, archives,
				// paginated pages, custom rewrites). Replicate ForgeCache's
				// own cache-key hash and delete the file directly.
				// Sitewide invalidation is admin-tier; without cap the
				// caller could scan arbitrary URLs by probing purge success.
				if ( ! current_user_can( 'manage_options' ) ) {
					throw new \Exception( 'You do not have permission to purge non-post URLs.' );
				}

				if ( ! defined( 'FORGECACHE_CACHE_DIR' ) ) {
					throw new \Exception( 'ForgeCache cache directory constant is not defined.' );
				}

				$scheme    = wp_parse_url( home_url(), PHP_URL_SCHEME );
				$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
				$scheme    = is_string( $scheme )    ? $scheme    : 'http';
				$host      = is_string( $home_host ) ? $home_host : '';

				// Match ForgeCache's own logic: relative URL passed to
				// get_cache_file_path (strip home_url prefix, then hash
				// scheme + host + relative).
				$home_url_full = home_url();
				$relative_url  = ( $home_url_full && strpos( $url, $home_url_full ) === 0 )
					? substr( $url, strlen( $home_url_full ) )
					: ( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' );
				if ( $relative_url === '' ) {
					$relative_url = '/';
				}

				$hash       = md5( $scheme . '://' . $host . $relative_url );
				$cache_file = FORGECACHE_CACHE_DIR . 'pages/' . $hash . '.html';

				$found   = file_exists( $cache_file );
				$deleted = false;
				if ( $found ) {
					$deleted = (bool) wp_delete_file( $cache_file );
				}

				return [
					'success'      => true,
					'url'          => $url,
					'post_id'      => 0,
					'path'         => 'hash_direct',
					'cache_hit'    => $found,
					'file_deleted' => $deleted,
					'message'      => $found
						? ( $deleted ? 'Cache file deleted for non-post URL.' : 'Cache file found but delete failed (check filesystem permissions).' )
						: 'No cache file present for this URL — nothing to purge.',
				];

			default:
				throw new \Exception( 'Unknown ForgeCache tool: ' . esc_html( $name ) );
		}
	}
}
