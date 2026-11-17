<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Tool_Refusal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-Optimize: page cache status and page cache purging.
 *
 * Reads go through WP-Optimize's own cache, preload, minify and options
 * classes. Purges use WP-Optimize's own purge functions, so its hooks and
 * clean-up run exactly as when the purge is started from its admin screens.
 * No outside requests.
 */
class WPOptimize {

	const MAX_URLS       = 50;
	const MAX_URL_LENGTH = 2048;

	/** Files WP-Optimize keeps in every cache folder that are not cached pages. */
	const NOT_PAGES = [ 'index.php', '.htaccess', 'web.config' ];

	public static function is_available() {
		return defined( 'WPO_VERSION' ) && function_exists( 'WP_Optimize' ) && class_exists( 'WP_Optimize' ) && class_exists( 'WPO_Page_Cache' );
	}

	public static function get_tools() {
		return [
			[
				'name'        => 'wpo_get_cache_status',
				'description' => 'WP-Optimize status: whether the page cache is switched on and whether WordPress is set up to serve it, cache size and number of cache files (one page can have several: compressed, mobile and similar copies), how long pages stay cached, when the cache was last emptied, preload status (running, progress, scheduled preload, last preload), whether minify is on, when the database was last optimised, and the WP-Optimize version. Read-only. Administrators only (network administrators on multisite, where the cache is shared by all sites).',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ],
			],
			[
				'name'        => 'wpo_purge_cache',
				'description' => 'Delete pages from the WP-Optimize page cache, using WP-Optimize\'s own purge. scope "all" empties the whole page cache (administrators only; network administrators on multisite, where the cache is shared by all sites). scope "urls" deletes the cached copies of up to ' . self::MAX_URLS . ' addresses on this site (no query strings; addresses on other sites are refused): an address that is a post or page needs permission to edit that post, any other address (home page, archives) needs an administrator (a network administrator on multisite, where the cache is shared by all sites). A post\'s other pages (split with page breaks) are purged with it. Minified files and the database are not touched. Purges cannot be undone: pages are cached again as visitors load them, or by WP-Optimize\'s preload when it is set to do that. Refused in read-only mode.',
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => [
						'scope' => [ 'type' => 'string', 'enum' => [ 'all', 'urls' ], 'description' => '"all" empties the whole page cache; "urls" purges only the addresses in urls.' ],
						'urls'  => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'minItems'    => 1,
							'maxItems'    => self::MAX_URLS,
							'description' => 'Required with scope "urls", not allowed with "all". Full addresses on this site, e.g. https://example.com/about/.',
						],
					],
					'required'             => [ 'scope' ],
					'additionalProperties' => false,
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		// Lowest level a single-post purge needs; each tool checks its own capability below.
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new \Exception( 'You do not have permission to use WP-Optimize tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'WP-Optimize is not active' );
		}
		$args = is_array( $args ) ? $args : [];

		switch ( $name ) {
			case 'wpo_get_cache_status':
				self::check_args( $args, [] );
				self::require_site_admin( 'view the WP-Optimize status' );
				return self::status();

			case 'wpo_purge_cache':
				if ( \Royal_MCP\MCP\Server::read_only_mode() ) {
					throw new Tool_Refusal( 'read_only_mode', 'This site is in read-only mode: tools that change the site are switched off.' );
				}
				self::check_args( $args, [ 'scope', 'urls' ] );
				return self::purge( $args );

			default:
				throw new \Exception( 'Unknown WP-Optimize tool: ' . esc_html( $name ) );
		}
	}

	// ============ Access and arguments ============

	/** The cache folder is shared by every site of a network, so network-wide actions need a network administrator there. */
	private static function require_site_admin( $what ) {
		$cap = self::shared_cache_cap( is_multisite() );
		if ( ! current_user_can( $cap ) ) {
			throw new Tool_Refusal( 'insufficient_caps', sprintf( 'You do not have permission to %s: it needs an administrator%s.', $what, is_multisite() ? ' of the network' : '' ) );
		}
	}

	/**
	 * Capability for actions that reach beyond a single post of this site: emptying the
	 * whole cache, or purging an address that is not a post. On a network every site's
	 * pages sit in one cache folder, so an address there could belong to another site.
	 */
	private static function shared_cache_cap( $multisite ) {
		return $multisite ? 'manage_network_options' : 'manage_options';
	}

	private static function check_args( array $args, array $allowed ) {
		foreach ( array_keys( $args ) as $k ) {
			if ( ! in_array( (string) $k, $allowed, true ) ) {
				throw new Tool_Refusal( 'unknown_argument', sprintf( 'Unknown argument: %s.', esc_html( mb_substr( (string) $k, 0, 60 ) ) ) );
			}
		}
	}

	/** The site's home address split into the parts a purge address must match. */
	private static function home_parts() {
		$home = wp_parse_url( home_url( '/' ) );
		return [
			'host' => strtolower( (string) ( $home['host'] ?? '' ) ),
			'port' => isset( $home['port'] ) ? (int) $home['port'] : null,
			'path' => trailingslashit( (string) ( $home['path'] ?? '/' ) ),
		];
	}

	/**
	 * One purge address, checked: http(s), this site's host and port, under the
	 * site's path, no query string, fragment, credentials or dot segments.
	 * Returns the address rebuilt from its checked parts.
	 */
	private static function check_url( $value, $i ) {
		$label = 'urls[' . (int) $i . ']';
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw new Tool_Refusal( 'invalid_url', $label . ' must be a full address on this site.' );
		}
		$value = trim( $value );
		if ( strlen( $value ) > self::MAX_URL_LENGTH ) {
			throw new Tool_Refusal( 'invalid_url', sprintf( '%s is longer than %d characters.', $label, self::MAX_URL_LENGTH ) );
		}
		if ( preg_match( '/[\x00-\x20\x7f\\\\]/', $value ) ) {
			throw new Tool_Refusal( 'invalid_url', $label . ' contains spaces, control characters or backslashes.' );
		}
		$p = wp_parse_url( $value );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) || ! in_array( strtolower( $p['scheme'] ), [ 'http', 'https' ], true ) ) {
			throw new Tool_Refusal( 'invalid_url', $label . ' must be a full http or https address on this site.' );
		}
		if ( isset( $p['user'] ) || isset( $p['pass'] ) ) {
			throw new Tool_Refusal( 'invalid_url', $label . ' must not contain a user name or password.' );
		}
		$home = self::home_parts();
		$port = isset( $p['port'] ) ? (int) $p['port'] : null;
		if ( strtolower( $p['host'] ) !== $home['host'] || $port !== $home['port'] ) {
			throw new Tool_Refusal( 'off_site_url', $label . ' is not an address on this site. Only this site\'s own pages can be purged.' );
		}
		if ( isset( $p['query'] ) || isset( $p['fragment'] ) ) {
			throw new Tool_Refusal( 'invalid_url', $label . ' has a query string or fragment. WP-Optimize caches pages by path: leave out everything from ? or #.' );
		}
		$path = isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/';
		$decoded = $path;
		for ( $n = 0; $n < 5; $n++ ) {
			$next = rawurldecode( $decoded );
			if ( $next === $decoded ) {
				break;
			}
			$decoded = $next;
		}
		if ( preg_match( '#(^|/)\.\.?(/|$)#', $decoded ) || preg_match( '/[\x00-\x1f\x7f\\\\]/', $decoded ) ) {
			throw new Tool_Refusal( 'invalid_url', $label . ' has an invalid path.' );
		}
		if ( 0 !== strpos( trailingslashit( $path ), $home['path'] ) ) {
			throw new Tool_Refusal( 'off_site_url', $label . ' is outside this site\'s address.' );
		}
		return strtolower( $p['scheme'] ) . '://' . $home['host'] . ( null !== $home['port'] ? ':' . $home['port'] : '' ) . $path;
	}

	// ============ Status ============

	private static function status() {
		$wpo    = \WP_Optimize();
		$cache  = $wpo->get_page_cache();
		$config = $cache->config;

		$enabled = (bool) $config->get_option( 'enable_page_caching', false );
		// The same delivery chain WP-Optimize checks before serving cached pages, read without writing its log.
		$serving = $enabled
			&& defined( 'WP_CACHE' ) && WP_CACHE
			&& defined( 'WPO_ADVANCED_CACHE' ) && WPO_ADVANCED_CACHE
			&& defined( 'WPO_CACHE_CONFIG_DIR' ) && file_exists( WPO_CACHE_CONFIG_DIR . '/' . $config->get_cache_config_filename() );

		$size = $cache->get_cache_size();
		$size = is_array( $size ) ? $size : [];

		// last_cleared is filled with the current time by default, so only a stored value counts.
		$raw          = is_multisite() ? get_site_option( 'wpo_cache_config', [] ) : get_option( 'wpo_cache_config', [] );
		$last_cleared = is_array( $raw ) && isset( $raw['last_cleared'] ) && is_numeric( $raw['last_cleared'] ) && (int) $raw['last_cleared'] > 0 ? (int) $raw['last_cleared'] : null;

		$length = (int) $config->get_option( 'page_cache_length', 0 );

		$options       = $wpo->get_options();
		$last_db       = $options->get_option( 'last-optimized', 'Never' );
		$last_db       = is_numeric( $last_db ) && (int) $last_db > 0 ? (int) $last_db : null;
		$last_preload  = $options->get_option( 'wpo_last_page_cache_preload', 0 );
		$last_preload  = is_numeric( $last_preload ) && (int) $last_preload > 0 ? (int) $last_preload : null;

		$preload = [
			'running'           => false,
			'urls_done'         => 0,
			'urls_total'        => 0,
			'scheduled_preload' => (bool) $config->get_option( 'enable_schedule_preload', false ),
			'schedule'          => (string) $config->get_option( 'preload_schedule_type', '' ),
			'preload_purged'    => (bool) $config->get_option( 'auto_preload_purged_contents', false ),
			'last_preload'      => $last_preload ? gmdate( 'c', $last_preload ) : null,
		];
		if ( class_exists( 'WP_Optimize_Page_Cache_Preloader' ) ) {
			$preloader = \WP_Optimize_Page_Cache_Preloader::instance();
			if ( is_object( $preloader ) && method_exists( $preloader, 'get_status' ) ) {
				$st = $preloader->get_status( 'load-url-task' );
				$preload['urls_done']  = (int) ( $st['complete_tasks'] ?? 0 );
				$preload['urls_total'] = (int) ( $st['all_tasks'] ?? 0 );
				$preload['running']    = $preload['urls_total'] > 0 && $preload['urls_done'] < $preload['urls_total'];
			}
		}

		$minify = [ 'enabled' => false, 'js' => false, 'css' => false, 'html' => false ];
		if ( function_exists( 'wp_optimize_minify_config' ) ) {
			$m = wp_optimize_minify_config();
			if ( is_object( $m ) && method_exists( $m, 'get' ) ) {
				$minify = [
					'enabled' => (bool) $m->get( 'enabled', false ),
					'js'      => (bool) $m->get( 'enable_js', false ),
					'css'     => (bool) $m->get( 'enable_css', false ),
					'html'    => (bool) $m->get( 'html_minification', false ),
				];
			}
		}

		return [
			'plugin_version' => (string) WPO_VERSION,
			'premium'        => method_exists( 'WP_Optimize', 'is_premium' ) ? (bool) \WP_Optimize::is_premium() : false,
			'page_cache'     => [
				'enabled'            => $enabled,
				'serving'            => $serving,
				'cache_files'        => (int) ( $size['file_count'] ?? 0 ),
				'cache_size_bytes'   => (int) ( $size['size'] ?? 0 ),
				'cache_size'         => size_format( (int) ( $size['size'] ?? 0 ) ),
				'lifespan_seconds'   => $length,
				'mobile_cache'       => (bool) $config->get_option( 'enable_mobile_caching', false ),
				'logged_in_cache'    => (bool) $config->get_option( 'enable_user_caching', false ),
				'last_cleared'       => $last_cleared ? gmdate( 'c', $last_cleared ) : null,
			],
			'preload'        => $preload,
			'minify'         => $minify,
			'database'       => [
				'last_optimized' => $last_db ? gmdate( 'c', $last_db ) : null,
			],
			'note'           => $serving || ! $enabled ? '' : 'The page cache is switched on in WP-Optimize, but WordPress is not loading it (WP_CACHE or WP-Optimize\'s advanced-cache.php is missing). Open WP-Optimize > Cache and save the settings to repair it.',
		];
	}

	// ============ Purge ============

	private static function purge( array $args ) {
		$scope = $args['scope'] ?? null;
		if ( ! is_string( $scope ) || ! in_array( $scope, [ 'all', 'urls' ], true ) ) {
			throw new Tool_Refusal( 'invalid_scope', 'scope must be "all" or "urls".' );
		}
		$cache   = \WP_Optimize()->get_page_cache();
		$enabled = (bool) $cache->config->get_option( 'enable_page_caching', false );

		if ( 'all' === $scope ) {
			if ( array_key_exists( 'urls', $args ) ) {
				throw new Tool_Refusal( 'invalid_arguments', 'urls is only used with scope "urls".' );
			}
			self::require_site_admin( 'empty the whole WP-Optimize page cache' );
			$before = $cache->get_cache_size();
			$ok     = (bool) $cache->purge();
			if ( $ok ) {
				$cache->file_log( 'Full Cache Purge triggered by: Royal MCP' );
			}
			$after = $cache->get_cache_size();
			return [
				'success'              => $ok,
				'scope'                => 'all',
				'page_cache_enabled'   => $enabled,
				'cache_files_before'   => (int) ( $before['file_count'] ?? 0 ),
				'cache_files_after'    => (int) ( $after['file_count'] ?? 0 ),
				'message'              => $ok ? 'The WP-Optimize page cache was emptied. This cannot be undone; pages are cached again as they are visited.' : 'WP-Optimize could not delete the cache files (check file permissions).',
			];
		}

		if ( ! array_key_exists( 'urls', $args ) || ! is_array( $args['urls'] ) || array() === $args['urls'] ) {
			throw new Tool_Refusal( 'invalid_arguments', 'scope "urls" needs urls: a list of 1 to ' . self::MAX_URLS . ' addresses on this site.' );
		}
		if ( count( $args['urls'] ) > self::MAX_URLS ) {
			throw new Tool_Refusal( 'too_many_urls', sprintf( 'urls takes up to %d addresses per call.', self::MAX_URLS ) );
		}
		if ( array_keys( $args['urls'] ) !== range( 0, count( $args['urls'] ) - 1 ) ) {
			throw new Tool_Refusal( 'invalid_arguments', 'urls must be a list of addresses.' );
		}

		// Check every address and permission first, so a refusal purges nothing.
		$home_path = self::home_parts()['path'];
		$targets   = [];
		$seen      = [];
		foreach ( $args['urls'] as $i => $raw ) {
			$url     = self::check_url( $raw, $i );
			$post_id = (int) url_to_postid( $url );
			if ( $post_id > 0 ) {
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					throw new Tool_Refusal( 'insufficient_caps', sprintf( 'You do not have permission to purge urls[%d]: it needs permission to edit that post.', (int) $i ) );
				}
				$permalink = (string) get_permalink( $post_id );
				$ppath     = trailingslashit( (string) wp_parse_url( $permalink, PHP_URL_PATH ) );
				// A post shown as the home page: purge that page only, never every page below it.
				$mode = $ppath === $home_path ? 'home' : 'post';
				$key  = 'p' . $post_id;
			} else {
				$multisite = is_multisite();
				if ( ! current_user_can( self::shared_cache_cap( $multisite ) ) ) {
					throw new Tool_Refusal( 'insufficient_caps', sprintf( 'You do not have permission to purge urls[%d]: addresses that are not a single post or page need an administrator%s.', (int) $i, $multisite ? ' of the network' : '' ) );
				}
				$mode = 'url';
				$key  = 'u' . \WPO_Page_Cache::get_full_path_from_url( $url );
			}
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$targets[]    = [ 'url' => $url, 'post_id' => $post_id, 'mode' => $mode ];
		}

		$results = [];
		$all_ok  = true;
		foreach ( $targets as $tg ) {
			if ( 'post' === $tg['mode'] ) {
				$folder = \WPO_Page_Cache::get_full_path_from_url( (string) get_permalink( $tg['post_id'] ) );
				$before = self::page_files( $folder );
				$ok     = self::purge_post_page( $tg['post_id'] );
			} else {
				$target = 'home' === $tg['mode'] ? home_url( '/' ) : $tg['url'];
				$folder = \WPO_Page_Cache::get_full_path_from_url( $target );
				$before = self::page_files( $folder );
				// Only the files of this one address; its sub-folders are other pages.
				$ok = (bool) \WPO_Page_Cache::delete_cache_by_url( $target, false );
			}
			if ( $ok ) {
				$cache->file_log( 'Cache for URL: ' . $tg['url'] . ' has been purged, triggered by: Royal MCP' );
			}
			$all_ok    = $all_ok && $ok;
			$results[] = [
				'url'          => $tg['url'],
				'post_id'      => $tg['post_id'] > 0 ? $tg['post_id'] : null,
				'was_cached'   => $before > 0,
				'files_before' => $before,
				'files_after'  => self::page_files( $folder ),
				'purged'       => $ok,
			];
		}

		return [
			'success'            => $all_ok,
			'scope'              => 'urls',
			'page_cache_enabled' => $enabled,
			'results'            => $results,
			'message'            => ( $all_ok ? 'Purged ' . count( $results ) . ' address(es) from the WP-Optimize page cache.' : 'Some cached files could not be deleted (check file permissions).' ) . ' This cannot be undone; pages are cached again as they are visited.',
		];
	}

	/** Cached page files directly inside one cache folder. */
	private static function page_files( $folder ) {
		if ( ! is_string( $folder ) || ! is_dir( $folder ) ) {
			return 0;
		}
		$n = 0;
		foreach ( (array) scandir( $folder ) as $f ) {
			if ( '.' === $f || '..' === $f || in_array( $f, self::NOT_PAGES, true ) ) {
				continue;
			}
			if ( is_file( trailingslashit( $folder ) . $f ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Delete the cached files of one post's own address, plus its own numbered pages when
	 * the post is split with <!--nextpage-->. The cached pages of addresses below it (child
	 * pages, for example) are left alone.
	 *
	 * @param int $post_id Post ID.
	 * @return bool False when a cached file could not be deleted or WP-Optimize has no per-address purge.
	 */
	public static function purge_post_page( $post_id ) {
		$post = get_post( (int) $post_id );
		$url  = $post ? (string) get_permalink( $post ) : '';
		if ( '' === $url || ! class_exists( '\\WPO_Page_Cache' ) || ! method_exists( '\\WPO_Page_Cache', 'delete_cache_by_url' ) ) {
			return false;
		}
		$ok    = (bool) \WPO_Page_Cache::delete_cache_by_url( $url, false );
		$pages = min( 100, substr_count( (string) $post->post_content, '<!--nextpage-->' ) + 1 );
		if ( $pages > 1 && false === strpos( $url, '?' ) ) {
			for ( $n = 2; $n <= $pages; $n++ ) {
				$ok = (bool) \WPO_Page_Cache::delete_cache_by_url( trailingslashit( $url ) . $n . '/', false ) && $ok;
			}
		}
		return $ok;
	}
}
