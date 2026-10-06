<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LiteSpeed Cache MCP Integration
 *
 * Everything goes through LiteSpeed Cache's public hooks: the `litespeed_conf`
 * filter for reading settings and the `litespeed_purge_*` actions for
 * purging. A purge is reported as done only when LiteSpeed's own
 * `litespeed_purged_*` action fired for it.
 */
class LiteSpeed {

	const MAX_ITEMS = 50;

	/** Both purge tools say this when the web server has no LiteSpeed page cache. */
	const NO_PAGE_CACHE_NOTE = 'This web server has no LiteSpeed page cache, so no cached pages exist to purge. LiteSpeed Cache still provides its generated CSS/JS, object cache and opcode cache here, which litespeed_purge_cache clears.';

	/** Settings returned by litespeed_get_config, grouped. Credentials are never listed here. */
	const CONFIG_KEYS = [
		'cache'    => [
			'enabled'            => 'cache',
			'logged_in_users'    => 'cache-priv',
			'commenters'         => 'cache-commenter',
			'rest_api'           => 'cache-rest',
			'login_page'         => 'cache-page_login',
			'mobile'             => 'cache-mobile',
			'browser_cache'      => 'cache-browser',
			'drop_query_strings' => 'cache-drop_qs',
			'excluded_uris'      => 'cache-exc',
		],
		'ttl'      => [
			'public'     => 'cache-ttl_pub',
			'private'    => 'cache-ttl_priv',
			'front_page' => 'cache-ttl_frontpage',
			'feed'       => 'cache-ttl_feed',
			'rest'       => 'cache-ttl_rest',
			'browser'    => 'cache-ttl_browser',
		],
		'purge'    => [
			'purge_all_on_upgrade' => 'purge-upgrade',
		],
		'object'   => [
			'enabled' => 'object',
		],
		'cdn'      => [
			'enabled'    => 'cdn',
			'cloudflare' => 'cdn-cloudflare',
		],
		'optimize' => [
			'css_minify'  => 'optm-css_min',
			'css_combine' => 'optm-css_comb',
			'unique_css'  => 'optm-ucss',
			'js_minify'   => 'optm-js_min',
			'js_combine'  => 'optm-js_comb',
			'html_minify' => 'optm-html_min',
		],
		'media'    => [
			'lazy_load_images'        => 'media-lazy',
			'image_optimization_auto' => 'img_optm-auto',
			'webp_replacement'        => 'img_optm-webp',
		],
		'other'    => [
			'guest_mode' => 'guest',
			'esi'        => 'esi',
			'crawler'    => 'crawler',
			'debug_log'  => 'debug',
		],
	];

	public static function is_available() {
		return defined( 'LSCWP_V' ) && class_exists( '\\LiteSpeed\\Purge' );
	}

	public static function get_tools() {
		return [
			[
				'name'        => 'litespeed_get_cache_status',
				'description' => 'Get a summary of LiteSpeed Cache: plugin version, the web server type, whether page caching is available on this server and switched on, which features are enabled (object cache, browser cache, guest mode, ESI, crawler, CDN, CSS/JS/HTML minify, lazy load, image optimization) and the main cache lifetimes in seconds. Read-only.',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
			[
				'name'        => 'litespeed_get_config',
				'description' => 'Get the LiteSpeed Cache settings that shape caching behaviour, grouped: cache rules (who and what is cached, excluded URIs, dropped query strings), lifetimes, purge-on-upgrade, object cache, CDN, CSS/JS/HTML optimization, media, and guest mode / ESI / crawler / debug. Passwords, keys and tokens are never returned. Read-only.',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
			[
				'name'        => 'litespeed_purge_cache',
				'description' => 'Purge everything LiteSpeed Cache\'s own "Purge All" clears: the page cache, generated CSS/JS, the object cache and the opcode cache (and Cloudflare when LiteSpeed is set up to clear it). On a web server without LiteSpeed page caching the page cache is not part of it, and the answer says so (page_cache_purged false, with a note). Cached copies are rebuilt on the next request; site content is untouched. Expect slower responses until the caches refill. Use litespeed_purge_urls to purge single pages. Requires manage_options.',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
			[
				'name'        => 'litespeed_purge_urls',
				'description' => 'Purge the LiteSpeed page cache for specific pages, given as URLs on this site and/or post IDs (up to 50 of each). Each item is reported as purged or skipped with a reason; one bad item does not stop the rest. URLs are reported back as the path that was purged. On a web server without LiteSpeed page caching nothing is purged and every item is skipped with the reason no_page_cache_on_this_server (see page_cache_available in litespeed_get_cache_status). Site content is untouched. Requires manage_options.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'urls'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Full URLs on this site, or paths starting with "/". Query strings are ignored.' ],
						'post_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'IDs of posts, pages or other content whose cached pages should be purged.' ],
					],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		// Capability before availability, so a low-privilege caller cannot
		// use the "not active" answer to learn which plugins are installed.
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use LiteSpeed Cache tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'LiteSpeed Cache is not active' );
		}
		$args = is_array( $args ) ? $args : [];

		switch ( $name ) {
			case 'litespeed_get_cache_status':
				return self::handle_get_cache_status();
			case 'litespeed_get_config':
				return self::handle_get_config();
			case 'litespeed_purge_cache':
				return self::handle_purge_cache();
			case 'litespeed_purge_urls':
				return self::handle_purge_urls( $args );
			default:
				throw new \Exception( 'Unknown LiteSpeed Cache tool: ' . esc_html( $name ) );
		}
	}

	private static function handle_get_cache_status() {
		return array_merge(
			[ 'plugin_version' => LSCWP_V ],
			self::server_state(),
			[
				'features' => [
					'object_cache'            => self::flag( 'object' ),
					'browser_cache'           => self::flag( 'cache-browser' ),
					'mobile_cache'            => self::flag( 'cache-mobile' ),
					'guest_mode'              => self::flag( 'guest' ),
					'esi'                     => self::flag( 'esi' ),
					'crawler'                 => self::flag( 'crawler' ),
					'cdn'                     => self::flag( 'cdn' ),
					'css_minify'              => self::flag( 'optm-css_min' ),
					'js_minify'               => self::flag( 'optm-js_min' ),
					'html_minify'             => self::flag( 'optm-html_min' ),
					'lazy_load_images'        => self::flag( 'media-lazy' ),
					'image_optimization_auto' => self::flag( 'img_optm-auto' ),
				],
				'ttl_seconds' => [
					'public'     => (int) self::conf( 'cache-ttl_pub' ),
					'private'    => (int) self::conf( 'cache-ttl_priv' ),
					'front_page' => (int) self::conf( 'cache-ttl_frontpage' ),
					'feed'       => (int) self::conf( 'cache-ttl_feed' ),
				],
			]
		);
	}

	private static function handle_get_config() {
		$out = [ 'plugin_version' => LSCWP_V ];
		foreach ( self::CONFIG_KEYS as $group => $keys ) {
			$out[ $group ] = [];
			foreach ( $keys as $label => $id ) {
				$out[ $group ][ $label ] = self::clean( self::conf( $id ) );
			}
		}
		return $out;
	}

	private static function handle_purge_cache() {
		$done     = false;
		$listener = static function () use ( &$done ) {
			$done = true;
		};
		self::quiet();
		$queued = self::queue_if_output_started();
		add_action( 'litespeed_purged_all', $listener );
		try {
			do_action( 'litespeed_purge_all', 'Royal MCP' );
		} finally {
			remove_action( 'litespeed_purged_all', $listener );
			self::stop_queueing( $queued );
		}
		if ( ! $done ) {
			throw new \Exception( 'LiteSpeed Cache did not confirm the purge.' );
		}
		$state = self::server_state();
		$out   = [ 'purged' => true, 'scope' => 'all', 'page_cache_purged' => $state['page_cache_available'] ];
		if ( ! $state['page_cache_available'] ) {
			$out['note'] = self::NO_PAGE_CACHE_NOTE;
		}
		return array_merge( $out, $state );
	}

	private static function handle_purge_urls( $args ) {
		$urls     = isset( $args['urls'] ) ? array_values( (array) $args['urls'] ) : [];
		$post_ids = isset( $args['post_ids'] ) ? array_values( (array) $args['post_ids'] ) : [];
		if ( ! $urls && ! $post_ids ) {
			throw new \Exception( 'Provide urls and/or post_ids.' );
		}
		if ( count( $urls ) > self::MAX_ITEMS || count( $post_ids ) > self::MAX_ITEMS ) {
			throw new \Exception( 'At most ' . self::MAX_ITEMS . ' urls and ' . self::MAX_ITEMS . ' post_ids per call.' );
		}

		$state          = self::server_state();
		$has_page_cache = $state['page_cache_available'];
		$purged         = [];
		$skipped        = [];
		self::quiet();
		$queued = self::queue_if_output_started();

		$seen_url      = null;
		$url_listener  = static function ( $url ) use ( &$seen_url ) {
			$seen_url = $url;
		};
		$seen_post     = null;
		$post_listener = static function ( $pid ) use ( &$seen_post ) {
			$seen_post = (int) $pid;
		};
		add_action( 'litespeed_purged_link', $url_listener );
		add_action( 'litespeed_purged_post', $post_listener );
		try {
			foreach ( $urls as $raw ) {
				$path = is_string( $raw ) ? self::site_path_or_null( $raw ) : null;
				if ( null === $path ) {
					$skipped[] = [ 'url' => self::clean( is_scalar( $raw ) ? $raw : '' ), 'reason' => 'not_a_url_on_this_site' ];
					continue;
				}
				if ( ! $has_page_cache ) {
					$skipped[] = [ 'url' => $path, 'reason' => 'no_page_cache_on_this_server' ];
					continue;
				}
				// LiteSpeed keys a page by its path, so the path is what it is given.
				$seen_url = null;
				do_action( 'litespeed_purge_url', $path );
				if ( $path !== $seen_url ) {
					$skipped[] = [ 'url' => $path, 'reason' => 'not_accepted_by_litespeed' ];
				} else {
					$purged[] = [ 'url' => $path ];
				}
			}
			foreach ( $post_ids as $raw_id ) {
				$post_id = is_scalar( $raw_id ) && is_numeric( $raw_id ) ? (int) $raw_id : 0;
				if ( $post_id <= 0 || ! get_post( $post_id ) ) {
					$skipped[] = [ 'post_id' => $post_id, 'reason' => 'post_not_found' ];
					continue;
				}
				if ( ! $has_page_cache ) {
					$skipped[] = [ 'post_id' => $post_id, 'reason' => 'no_page_cache_on_this_server' ];
					continue;
				}
				$seen_post = null;
				do_action( 'litespeed_purge_post', $post_id );
				if ( $post_id !== $seen_post ) {
					$skipped[] = [ 'post_id' => $post_id, 'reason' => 'nothing_to_purge_for_this_post' ];
				} else {
					$purged[] = [ 'post_id' => $post_id ];
				}
			}
		} finally {
			remove_action( 'litespeed_purged_link', $url_listener );
			remove_action( 'litespeed_purged_post', $post_listener );
			self::stop_queueing( $queued );
		}

		$out = [
			'purged_count'  => count( $purged ),
			'skipped_count' => count( $skipped ),
			'purged'        => $purged,
			'skipped'       => $skipped,
		];
		if ( ! $has_page_cache ) {
			$out['note'] = self::NO_PAGE_CACHE_NOTE;
		}
		return array_merge( $out, $state );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/** A value from LiteSpeed's settings through its public filter. */
	private static function conf( $id ) {
		return apply_filters( 'litespeed_conf', $id );
	}

	private static function flag( $id ) {
		return (bool) self::conf( $id );
	}

	private static function server_state() {
		$type = defined( 'LITESPEED_SERVER_TYPE' ) ? (string) LITESPEED_SERVER_TYPE : 'NONE';
		$map  = [
			'LITESPEED_SERVER_ENT' => 'litespeed_enterprise',
			'LITESPEED_SERVER_OLS' => 'openlitespeed',
			'LITESPEED_SERVER_ADC' => 'litespeed_adc',
			'NONE'                 => 'not_litespeed',
		];
		return [
			'server'               => isset( $map[ $type ] ) ? $map[ $type ] : 'not_litespeed',
			'page_cache_available' => defined( 'LITESPEED_ALLOWED' ) && LITESPEED_ALLOWED,
			'page_cache_enabled'   => defined( 'LITESPEED_ON' ) && LITESPEED_ON,
			'cache_setting_on'     => self::flag( 'cache' ),
		];
	}

	/** Keeps LiteSpeed from queueing a wp-admin notice for a purge made here. */
	private static function quiet() {
		if ( ! defined( 'LITESPEED_PURGE_SILENT' ) ) {
			define( 'LITESPEED_PURGE_SILENT', true );
		}
	}

	/**
	 * LiteSpeed sends a purge as a response header. Once output has started
	 * that header can no longer be sent, so LiteSpeed is asked to hold the
	 * purge and send it with the next request instead.
	 */
	private static function queue_if_output_started() {
		if ( ! headers_sent() ) {
			return null;
		}
		$hold = static function () {
			return true;
		};
		add_filter( 'litespeed_delay_purge', $hold, 99 );
		return $hold;
	}

	private static function stop_queueing( $hold ) {
		if ( $hold ) {
			remove_filter( 'litespeed_delay_purge', $hold, 99 );
		}
	}

	/**
	 * The path of a URL on this site, or of a path starting with "/". Null
	 * for anything else, including URLs on another host.
	 */
	private static function site_path_or_null( $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > 2000 || preg_match( '/[\x00-\x20<>"\']/', $raw ) ) {
			return null;
		}
		if ( '/' === $raw[0] ) {
			if ( isset( $raw[1] ) && '/' === $raw[1] ) {
				return null;
			}
			$path = $raw;
		} else {
			$parts = wp_parse_url( $raw );
			$home  = wp_parse_url( home_url() );
			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $home['host'] )
				|| ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true )
				|| strtolower( $parts['host'] ) !== strtolower( $home['host'] ) ) {
				return null;
			}
			$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		}
		// The query string and fragment play no part in how a page is keyed.
		$path = (string) strtok( $path, '?#' );
		return '' === $path ? '/' : $path;
	}

	/** Settings values as plain data: scalars as they are, lists as lists of short strings. */
	private static function clean( $value ) {
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( array_slice( array_values( $value ), 0, 200 ) as $item ) {
				$out[] = is_scalar( $item ) ? self::clean( $item ) : null;
			}
			return $out;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		$text = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', wp_strip_all_tags( (string) $value ) ) );
		return strlen( $text ) > 500 ? substr( $text, 0, 500 ) : $text;
	}
}

/**
 * Manifest declaration. A purge invalidates cached copies only; source data
 * is untouched, so there is nothing to undo.
 */
add_filter( 'royal_mcp_manifests', function ( $manifests ) {
	if ( ! LiteSpeed::is_available() ) {
		return $manifests;
	}
	$manifests[] = [
		'royal_mcp_manifest_version' => '1.0',
		'plugin_slug'                => 'litespeed-cache',
		'plugin_display_name'        => 'LiteSpeed Cache',
		'plugin_version'             => LSCWP_V,
		'vendor_name'                => 'LiteSpeed Technologies',
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
