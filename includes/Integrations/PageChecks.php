<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Location_Filter;
use Royal_MCP\MCP\Support\Preview_Link;
use Royal_MCP\MCP\Support\Seo_Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks on the page a visitor is served.
 *
 * wp_validate_schema reads the JSON-LD structured data of a served page.
 * wp_purge_and_verify_url purges one page from the active cache plugins and
 * compares what visitors get with a fresh load.
 *
 * Only pages of this site are loaded, redirects are followed only while they
 * stay on it, and one call never spends more than CALL_BUDGET seconds loading.
 */
class PageChecks {

	const TOOLS = [
		'wp_validate_schema'      => 'handle_validate_schema',
		'wp_purge_and_verify_url' => 'handle_purge_and_verify',
	];

	/** Most bytes of a page that are downloaded and parsed. */
	const MAX_BYTES = 3145728;

	/** Seconds one call may spend loading pages. */
	const CALL_BUDGET = 25;

	const MAX_BLOCKS  = 50;
	const MAX_OBJECTS = 500;
	const MAX_TYPES   = 60;

	const ARTICLE_TYPES = [ 'Article', 'NewsArticle', 'BlogPosting', 'TechArticle', 'ScholarlyArticle', 'Report' ];

	/** Marks an element, and everything in it, that a visitor does not see. */
	const HIDDEN_ANCESTOR = "ancestor-or-self::*[@hidden or @aria-hidden='true' or contains(translate(@style,' ',''),'display:none') or contains(translate(@style,' ',''),'visibility:hidden') or contains(concat(' ',normalize-space(@class),' '),' screen-reader-text ') or contains(concat(' ',normalize-space(@class),' '),' sr-only ') or contains(concat(' ',normalize-space(@class),' '),' visually-hidden ')] or ancestor::noscript or ancestor::template";

	/** The page's canonical link: rel holds the word canonical, in any letter case, alone or among others. */
	const CANONICAL_XPATH = "//link[contains(concat(' ',translate(normalize-space(@rel),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),' '),' canonical ')]";

	/** Response headers that tell which cache answered. */
	const CACHE_HEADERS = [
		'cf-cache-status'   => 'cloudflare',
		'x-litespeed-cache' => 'litespeed',
		'x-forgecache'      => 'forgecache',
		'x-proxy-cache'     => 'server proxy cache',
		'x-fastcgi-cache'   => 'server fastcgi cache',
		'x-sucuri-cache'    => 'sucuri',
		'x-cache'           => 'proxy or CDN',
	];

	/** When the current call has to stop loading pages (unix time, 0 = no call running). */
	private static $deadline = 0.0;

	public static function handles( $name ) {
		return is_string( $name ) && isset( self::TOOLS[ $name ] );
	}

	public static function get_tools() {
		return [ self::validate_schema_schema(), self::purge_schema() ];
	}

	public static function execute_tool( $name, $args ) {
		if ( ! self::handles( $name ) ) {
			throw new \Exception( 'Unknown page tool: ' . esc_html( is_string( $name ) ? $name : '' ) );
		}
		self::$deadline = microtime( true ) + self::CALL_BUDGET;
		try {
			return call_user_func( [ __CLASS__, self::TOOLS[ $name ] ], is_array( $args ) ? $args : [] );
		} finally {
			self::$deadline = 0.0;
		}
	}

	private static function require_cap( $cap ) {
		if ( ! current_user_can( $cap ) ) {
			throw new \Exception( 'You do not have permission to use this page tool.' );
		}
	}

	// ------------------------------------------------------------------
	// Loading this site's pages
	// ------------------------------------------------------------------

	/**
	 * Whether a URL is on this site: same host, http or https, under the site's own path when it
	 * has one, on the site's port or the usual port of the URL's scheme, with no login in it.
	 */
	public static function on_site( $url ) {
		$home  = wp_parse_url( home_url() );
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $home ) || ! is_array( $parts ) || empty( $home['host'] ) || empty( $parts['host'] )
			|| strtolower( (string) $home['host'] ) !== strtolower( (string) $parts['host'] ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return false;
		}
		if ( ! empty( $parts['port'] ) ) {
			$port      = (int) $parts['port'];
			$home_port = (int) ( $home['port'] ?? 0 );
			$default   = 'https' === $scheme ? 443 : 80;
			if ( $port !== $default && ( 0 === $home_port || $port !== $home_port ) ) {
				return false;
			}
		}
		$path = (string) ( $parts['path'] ?? '/' );
		if ( '' === $path ) {
			$path = '/';
		}
		// Dot segments (plain or encoded) could step out of the home path once the server resolves them.
		if ( 1 === preg_match( '#(?:^|/)(?:\.|%2e){1,2}(?:/|$)#i', $path ) ) {
			return false;
		}
		// Encoded or backward slashes can be read as path separators by the server or a proxy.
		if ( false !== strpos( $path, '\\' ) || 1 === preg_match( '/%(?:2f|5c)/i', $path ) ) {
			return false;
		}
		$home_path = untrailingslashit( (string) ( $home['path'] ?? '' ) );
		if ( '' !== $home_path && $path !== $home_path && 0 !== strpos( $path, $home_path . '/' ) ) {
			return false;
		}
		// On a network whose sites share this host, a path under another site's path is that site's page.
		// Compared without case, since site paths are matched that way when the request is routed.
		$path_lc = strtolower( $path );
		foreach ( self::nested_site_paths( strtolower( (string) $home['host'] ), $home_path ) as $other ) {
			$other_lc = strtolower( (string) $other );
			if ( untrailingslashit( $path_lc ) === $other_lc || 0 === strpos( $path_lc, $other_lc . '/' ) ) {
				return false;
			}
		}
		return true;
	}

	/** @var array<string,string[]> Other sites' paths per host and home path, for this request. */
	private static $nested_paths = [];

	/**
	 * Paths (no trailing slash) of the other sites of this network on the same host that sit
	 * below this site's home path. Empty outside a subdirectory network.
	 *
	 * @param string $host      Lower-case host of this site.
	 * @param string $home_path This site's home path without a trailing slash.
	 * @return string[]
	 */
	private static function nested_site_paths( $host, $home_path ) {
		if ( ! is_multisite() || ( function_exists( 'is_subdomain_install' ) && is_subdomain_install() ) || ! function_exists( 'get_sites' ) ) {
			return [];
		}
		$memo = $host . '|' . $home_path;
		if ( isset( self::$nested_paths[ $memo ] ) ) {
			return self::$nested_paths[ $memo ];
		}
		$cache_key = 'nested_' . md5( (int) get_current_network_id() . '|' . $memo );
		$paths     = wp_cache_get( $cache_key, 'royal_mcp_sites' );
		if ( ! is_array( $paths ) ) {
			$paths = [];
			$sites = get_sites( [ 'network_id' => (int) get_current_network_id(), 'domain' => $host, 'number' => 1000, 'fields' => '', 'site__not_in' => [ (int) get_current_blog_id() ] ] );
			foreach ( (array) $sites as $site ) {
				$p = untrailingslashit( (string) ( is_object( $site ) ? $site->path : '' ) );
				if ( '' !== $p && strlen( $p ) > strlen( $home_path ) && ( '' === $home_path || 0 === strpos( $p, $home_path . '/' ) ) ) {
					$paths[] = $p;
				}
			}
			wp_cache_set( $cache_key, $paths, 'royal_mcp_sites', 300 );
		}
		self::$nested_paths[ $memo ] = $paths;
		return $paths;
	}

	/**
	 * A URL in one spelling for rate limiting: scheme and host in lower case, the default port
	 * and the fragment dropped, query parameters sorted.
	 */
	public static function limit_key_url( $url ) {
		$p = wp_parse_url( (string) $url );
		if ( ! is_array( $p ) ) {
			return strtolower( (string) $url );
		}
		$scheme = strtolower( (string) ( $p['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $p['host'] ?? '' ) );
		$port   = isset( $p['port'] ) ? (int) $p['port'] : 0;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}
		$path = self::decode_unreserved( (string) ( $p['path'] ?? '' ) );
		$path = (string) preg_replace( '#/{2,}#', '/', $path );
		$query = [];
		if ( isset( $p['query'] ) && '' !== $p['query'] ) {
			foreach ( explode( '&', (string) $p['query'] ) as $pair ) {
				if ( '' === $pair ) {
					continue;
				}
				$kv      = explode( '=', $pair, 2 );
				$name    = rawurlencode( rawurldecode( str_replace( '+', ' ', $kv[0] ) ) );
				$query[] = isset( $kv[1] ) ? $name . '=' . rawurlencode( rawurldecode( str_replace( '+', ' ', $kv[1] ) ) ) : $name;
			}
			sort( $query, SORT_STRING );
		}
		return $scheme . '://' . $host . ( $port ? ':' . $port : '' ) . untrailingslashit( $path ) . ( $query ? '?' . implode( '&', $query ) : '' );
	}

	/** Percent-escapes of letters, digits and - . _ ~ written as the characters; other escapes in upper case. */
	private static function decode_unreserved( $s ) {
		return (string) preg_replace_callback(
			'/%([0-9A-Fa-f]{2})/',
			static function ( $m ) {
				$c = chr( hexdec( $m[1] ) );
				return 1 === preg_match( '/[A-Za-z0-9\-._~]/', $c ) ? $c : '%' . strtoupper( $m[1] );
			},
			$s
		);
	}

	/**
	 * GET a page of this site as a visitor (no cookies), following up to 5 redirects that stay on it.
	 *
	 * @return array{status:int, headers:array, body:string, truncated:bool, final_url:string, redirect_chain:array}|\WP_Error
	 */
	public static function fetch( $url, array $headers = [], $timeout = 8 ) {
		if ( ! self::on_site( $url ) ) {
			return new \WP_Error( 'not_on_site', 'url must belong to this WordPress site.' );
		}
		$chain   = [];
		$current = (string) $url;
		$resp    = null;
		$status  = 0;
		$deadline = self::$deadline > 0 ? self::$deadline : microtime( true ) + min( 10, max( 1, (int) $timeout ) );
		for ( $hop = 0; $hop <= 5; $hop++ ) {
			$left = $deadline - microtime( true );
			if ( $left < 1 ) {
				return new \WP_Error( 'fetch_failed', 'The page took too long to load.' );
			}
			$resp = wp_remote_get( $current, [
				'timeout'             => max( 1, min( (int) $timeout, (int) floor( $left ) ) ),
				'redirection'         => 0,
				'sslverify'           => false,
				'cookies'             => [],
				'limit_response_size' => self::MAX_BYTES,
				'headers'             => array_merge( [ 'User-Agent' => 'Royal MCP page check/' . ( defined( 'ROYAL_MCP_VERSION' ) ? ROYAL_MCP_VERSION : '2' ) ], $headers ),
			] );
			if ( is_wp_error( $resp ) ) {
				// The transport's message can carry the server's address; the code is enough.
				return new \WP_Error( 'fetch_failed', 'The page could not be loaded (' . sanitize_key( (string) $resp->get_error_code() ) . ').' );
			}
			$status   = (int) wp_remote_retrieve_response_code( $resp );
			$location = wp_remote_retrieve_header( $resp, 'location' );
			$location = is_array( $location ) ? (string) reset( $location ) : (string) $location;
			if ( $status >= 300 && $status < 400 && '' !== $location && $hop < 5 ) {
				$next = \WP_Http::make_absolute_url( $location, $current );
				if ( self::on_site( $next ) ) {
					$chain[] = [ 'url' => $current, 'status' => $status ];
					$current = $next;
					continue;
				}
			}
			break;
		}
		$out_headers = [];
		$raw_headers = wp_remote_retrieve_headers( $resp );
		if ( is_object( $raw_headers ) && method_exists( $raw_headers, 'getAll' ) ) {
			$raw_headers = $raw_headers->getAll();
		}
		foreach ( is_array( $raw_headers ) ? $raw_headers : [] as $name => $value ) {
			$out_headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}
		$body = (string) wp_remote_retrieve_body( $resp );
		return [
			'status'         => $status,
			'headers'        => $out_headers,
			'body'           => strlen( $body ) > self::MAX_BYTES ? substr( $body, 0, self::MAX_BYTES ) : $body,
			// The download stops at MAX_BYTES, so a body of that size was most likely cut.
			'truncated'      => strlen( $body ) >= self::MAX_BYTES,
			'final_url'      => self::cut( $current, 500 ),
			'redirect_chain' => $chain,
		];
	}

	/** DOMDocument for an HTML string, or null when the DOM extension is missing or the text is empty. */
	public static function parse( $html ) {
		$html = (string) $html;
		if ( ! class_exists( '\\DOMDocument' ) || ! class_exists( '\\DOMXPath' ) || '' === trim( $html ) ) {
			return null;
		}
		if ( strlen( $html ) > self::MAX_BYTES ) {
			$html = substr( $html, 0, self::MAX_BYTES );
		}
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $ok ? $doc : null;
	}

	/** Text a visitor can read: script, style, noscript and template contents left out. */
	private static function visible_text( \DOMNode $node, $depth = 0 ) {
		if ( $node instanceof \DOMText ) {
			return (string) $node->nodeValue;
		}
		if ( $depth > 200 || ( $node instanceof \DOMElement && in_array( strtolower( $node->tagName ), [ 'script', 'style', 'noscript', 'template' ], true ) ) ) {
			return '';
		}
		$text = '';
		foreach ( $node->childNodes as $child ) {
			$text .= self::visible_text( $child, $depth + 1 ) . ( $child instanceof \DOMElement ? ' ' : '' );
		}
		return $text;
	}

	/**
	 * One line of text with single spaces; anything that is not text gives ''. Entities become
	 * characters unless $decoded says the text came out of the HTML parser, which did that already.
	 */
	private static function squash( $text, $decoded = false ) {
		if ( ! is_scalar( $text ) || is_bool( $text ) ) {
			return '';
		}
		$text = (string) wp_check_invalid_utf8( (string) $text, true );
		$text = preg_replace( '/[\s\x{00A0}]+/u', ' ', $decoded ? $text : html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return trim( is_string( $text ) ? $text : '' );
	}

	private static function cut( $text, $max ) {
		$text = (string) $text;
		return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) . '…' : $text;
	}

	/** A value from the page as short text, or null when it is not text. */
	private static function text_or_null( $value, $max = 200 ) {
		$value = is_array( $value ) && $value ? reset( $value ) : $value;
		$text  = self::squash( $value );
		return '' === $text ? null : self::cut( $text, $max );
	}

	/** The visible H1 headings of a parsed page; only the first 200 H1 elements are looked at. */
	public static function visible_h1s( \DOMXPath $xp ) {
		$texts = [];
		$nodes = $xp->query( '//h1' );
		$seen  = 0;
		foreach ( $nodes ? $nodes : [] as $h1 ) {
			if ( ++$seen > 200 ) {
				break;
			}
			if ( ! $xp->evaluate( 'boolean(' . self::HIDDEN_ANCESTOR . ')', $h1 ) ) {
				$texts[] = self::cut( self::squash( self::visible_text( $h1 ), true ), 160 );
			}
		}
		return $texts;
	}

	/**
	 * How many H1 headings a visitor sees on the page served at a URL, or null when it cannot be loaded.
	 *
	 * @param string $url URL on this site.
	 * @return int|null
	 */
	public static function served_h1_count( $url ) {
		$own = self::$deadline <= 0;
		if ( $own ) {
			self::$deadline = microtime( true ) + 10;
		}
		$page = self::fetch( $url, [], 5 );
		if ( $own ) {
			self::$deadline = 0.0;
		}
		if ( is_wp_error( $page ) || $page['status'] < 200 || $page['status'] >= 300 ) {
			return null;
		}
		$doc = self::parse( $page['body'] );
		return $doc ? count( self::visible_h1s( new \DOMXPath( $doc ) ) ) : null;
	}

	/** Calls per minute on one page, across all page tools. */
	const PAGE_LIMIT = 10;

	/** Calls per minute one user may make across all page tools. */
	const USER_LIMIT = 30;

	/**
	 * Seconds to wait before the next page-tool call, or 0 when it may run. Counts per page and
	 * per user. A page is the post the URL resolves to, otherwise the URL in one spelling; every
	 * page tool counts in the same per-page window, so the tool name and the post_id passed with
	 * a URL do not open a new one.
	 *
	 * @param string $tool    Tool name (kept for callers; the windows do not depend on it).
	 * @param string $url     URL the tool will fetch.
	 * @param int    $post_id Post the call names, used only when there is no URL.
	 * @return int
	 */
	private static function rate_limited( $tool, $url, $post_id = 0 ) {
		$norm     = self::limit_key_url( $url );
		$resolved = '' !== (string) $url ? (int) url_to_postid( $norm ) : (int) $post_id;
		$target   = $resolved > 0 ? 'post:' . $resolved : 'url:' . $norm;
		$page_key = self::limit_key( $target );
		$user_key = 'royal_mcp_page_u_' . (int) get_current_user_id();
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			$wait = self::cache_window( $page_key, self::PAGE_LIMIT, $user_key, self::USER_LIMIT );
			if ( null !== $wait ) {
				return $wait;
			}
		}
		// Without a shared object cache the count is read and written back, so requests that
		// arrive at the same moment can each pass one call over the limit.
		$wait = max( self::window_wait( $page_key, self::PAGE_LIMIT ), self::window_wait( $user_key, self::USER_LIMIT ) );
		if ( $wait > 0 ) {
			return $wait;
		}
		self::window_count( $page_key );
		self::window_count( $user_key );
		return 0;
	}

	/**
	 * Count one call in both windows with atomic increments in the shared object cache
	 * (fixed one-minute windows). A call over either limit is taken back out of both.
	 *
	 * @return int|null Seconds to wait, 0 when counted, null when the cache cannot count.
	 */
	private static function cache_window( $page_key, $page_limit, $user_key, $user_limit ) {
		$minute = (int) floor( time() / 60 );
		$wait   = max( 1, 60 - ( time() % 60 ) );
		$group  = 'royal_mcp_rate';
		$keys   = [ [ $page_key . '_' . $minute, $page_limit ], [ $user_key . '_' . $minute, $user_limit ] ];
		$done   = [];
		foreach ( $keys as $k ) {
			wp_cache_add( $k[0], 0, $group, 120 );
			$n = wp_cache_incr( $k[0], 1, $group );
			if ( false === $n ) {
				foreach ( $done as $d ) {
					wp_cache_decr( $d, 1, $group );
				}
				return null;
			}
			$done[] = $k[0];
			if ( (int) $n > $k[1] ) {
				foreach ( $done as $d ) {
					wp_cache_decr( $d, 1, $group );
				}
				return $wait;
			}
		}
		return 0;
	}

	/** Transient key for one page, shared by every page tool. */
	private static function limit_key( $target ) {
		return 'royal_mcp_page_' . md5( 'page|' . $target );
	}

	/** Seconds left in a full one-minute window, or 0. */
	private static function window_wait( $key, $limit ) {
		$state = get_transient( $key );
		$now   = time();
		if ( is_array( $state ) && ( $now - (int) $state['start'] ) < 60 && (int) $state['count'] >= $limit ) {
			return max( 1, 60 - ( $now - (int) $state['start'] ) );
		}
		return 0;
	}

	/** Counts one call in a one-minute window. */
	private static function window_count( $key ) {
		$state = get_transient( $key );
		$now   = time();
		if ( is_array( $state ) && ( $now - (int) $state['start'] ) < 60 ) {
			$state['count'] = (int) $state['count'] + 1;
		} else {
			$state = [ 'start' => $now, 'count' => 1 ];
		}
		set_transient( $key, $state, 60 ); // audit:transient-ttl-only -- rolling one-minute rate window
	}

	/**
	 * The URL and post a page tool works on, after the permission check.
	 *
	 * The server loads the URL itself, so the caller's rights must cover the page the URL shows,
	 * not only a post_id passed along with it.
	 *
	 * @return array{url:string, post_id:int}|array An error reply.
	 */
	private static function resolve_target( array $args, $tool ) {
		foreach ( [ 'post_id', 'url' ] as $key ) {
			if ( array_key_exists( $key, $args ) && null !== $args[ $key ] && ! is_scalar( $args[ $key ] ) ) {
				return Envelope::error( 'invalid_args', $key . ' must be a single value.' );
			}
		}
		$post_id = 0;
		if ( isset( $args['post_id'] ) && '' !== $args['post_id'] ) {
			if ( ! is_int( $args['post_id'] ) && ! ( is_string( $args['post_id'] ) && ctype_digit( $args['post_id'] ) ) || (int) $args['post_id'] < 1 ) {
				return Envelope::error( 'invalid_args', 'post_id must be a post ID.' );
			}
			$post_id = (int) $args['post_id'];
		}
		$raw       = isset( $args['url'] ) ? trim( (string) $args['url'] ) : '';
		$url       = '' !== $raw ? esc_url_raw( $raw, [ 'http', 'https' ] ) : '';
		$given_url = '' !== $raw;
		if ( $given_url && ( '' === $url || strlen( $url ) > 2000 ) ) {
			return Envelope::error( 'invalid_args', 'url must be an http or https address on this site.' );
		}
		if ( ! $post_id && ! $given_url ) {
			return Envelope::error( 'invalid_args', 'Pass url or post_id.' );
		}
		$missing = Envelope::error( 'not_found', 'No post you can edit was found for that request.' );
		if ( $post_id ) {
			$post = get_post( $post_id );
			// One answer for "no such post" and "not yours", so ids cannot be probed.
			if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
				return $missing;
			}
			if ( ! $given_url ) {
				if ( 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
					return Envelope::error( 'not_public', sprintf( 'Post %d is not public, so visitors are not served it. Pass a preview link from wp_create_preview_link as url, together with post_id.', $post_id ) );
				}
				$url = (string) get_permalink( $post_id );
			}
		}
		if ( ! self::on_site( $url ) ) {
			return Envelope::error( 'not_on_site', 'url must belong to this WordPress site.' );
		}
		if ( $given_url ) {
			$url_post = self::post_of_url( $url );
			$allowed  = $url_post ? current_user_can( 'edit_post', $url_post ) : current_user_can( 'manage_options' );
			if ( ! $allowed ) {
				return Envelope::error( 'insufficient_caps', $tool . ' needs edit access to the post a URL shows, or manage_options for any other page of the site.' );
			}
			if ( ! $post_id ) {
				$post_id = $url_post;
			}
		}
		// A fragment never reaches the server, so it is not part of the page that is loaded or counted.
		$url  = (string) strtok( $url, '#' );
		$wait = self::rate_limited( $tool, $url, (int) $post_id );
		if ( $wait > 0 ) {
			return Envelope::error( 'rate_limited', sprintf( 'Up to 10 calls a minute per page and 30 a minute in all. Try again in %d seconds.', $wait ) );
		}
		return [ 'url' => $url, 'post_id' => (int) $post_id ];
	}

	/**
	 * The post a URL shows: a preview link's post, or the post whose own link the URL is.
	 * 0 for any other page: the URL has to be the post's own link exactly, query string included.
	 */
	private static function post_of_url( $url ) {
		$query = [];
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$norm = static function ( $u ) {
			return strtolower( untrailingslashit( (string) preg_replace( '#^https?://#i', '', (string) strtok( (string) $u, '#' ) ) ) );
		};
		if ( ! empty( $query[ Preview_Link::QUERY_VAR ] ) && is_string( $query[ Preview_Link::QUERY_VAR ] ) ) {
			// The token stands for its post only on the preview link itself; on any other path it is just a stray argument.
			$data = Preview_Link::get_token_data( $query[ Preview_Link::QUERY_VAR ] );
			$real = is_array( $data ) && ! empty( $data['post_id'] ) ? (string) Preview_Link::build_url( $query[ Preview_Link::QUERY_VAR ] ) : '';
			return ( '' !== $real && $norm( $real ) === $norm( $url ) ) ? (int) $data['post_id'] : 0;
		}
		$id = (int) url_to_postid( $url );
		if ( ! $id ) {
			return 0;
		}
		return $norm( $url ) === $norm( get_permalink( $id ) ) ? $id : 0;
	}

	// ------------------------------------------------------------------
	// wp_validate_schema
	// ------------------------------------------------------------------

	public static function handle_validate_schema( array $args ) {
		self::require_cap( 'edit_posts' );
		$target = self::resolve_target( $args, 'wp_validate_schema' );
		if ( ! isset( $target['url'] ) ) {
			return $target;
		}
		$page = self::fetch( $target['url'] );
		if ( is_wp_error( $page ) ) {
			return Envelope::error( $page->get_error_code(), $page->get_error_message() );
		}
		$doc = self::parse( $page['body'] );
		if ( ! $doc ) {
			return Envelope::error( 'parse_unavailable', sprintf( 'The page (HTTP %d) could not be parsed: the response was empty or this server has no HTML parser.', $page['status'] ) );
		}
		$post_id   = (int) $target['post_id'];
		$given_url = isset( $args['url'] ) && '' !== trim( (string) $args['url'] );
		$xp        = new \DOMXPath( $doc );
		$findings  = [];
		$add       = static function ( $severity, $code, $message ) use ( &$findings ) {
			if ( count( $findings ) < 60 ) {
				$findings[] = [ 'severity' => $severity, 'code' => $code, 'message' => $message ];
			}
		};
		if ( $page['status'] < 200 || $page['status'] >= 300 ) {
			$add( 'error', 'page_not_ok', sprintf( 'The page answered HTTP %d, so this is not the page visitors normally get.', $page['status'] ) );
		}

		$objects = [];
		$blocks  = $xp->query( "//script[translate(normalize-space(@type),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='application/ld+json']" );
		$count   = $blocks ? $blocks->length : 0;
		if ( $count > self::MAX_BLOCKS ) {
			$add( 'warning', 'too_many_blocks', sprintf( 'The page has %d JSON-LD blocks; the first %d were read.', $count, self::MAX_BLOCKS ) );
		}
		if ( ! empty( $page['truncated'] ) ) {
			$add( 'warning', 'page_truncated', sprintf( 'The page is larger than %d MB; only the first part was read, so structured data further down was not checked.', (int) ( self::MAX_BYTES / 1048576 ) ) );
		}
		for ( $b = 0; $b < $count && $b < self::MAX_BLOCKS; $b++ ) {
			$json = json_decode( trim( (string) $blocks->item( $b )->textContent ), true, 64 );
			if ( ! is_array( $json ) ) {
				// The last block of a page that was cut short is most likely just cut.
				if ( empty( $page['truncated'] ) || $b < $count - 1 ) {
					$add( 'error', 'invalid_json', sprintf( 'JSON-LD block %d is not valid JSON.', $b + 1 ) );
				}
				continue;
			}
			foreach ( self::schema_entities( $json ) as $entity ) {
				if ( count( $objects ) < self::MAX_OBJECTS ) {
					$objects[] = $entity;
				}
			}
		}

		$by_type = [];
		$by_id   = [];
		$article = [];
		foreach ( $objects as $obj ) {
			$types = self::types_of( $obj );
			foreach ( $types as $type ) {
				if ( isset( $by_type[ $type ] ) || count( $by_type ) < self::MAX_TYPES ) {
					$by_type[ $type ] = ( $by_type[ $type ] ?? 0 ) + 1;
				}
			}
			if ( isset( $obj['@id'] ) && is_string( $obj['@id'] ) ) {
				$by_id[ $obj['@id'] ] = $obj;
			}
			if ( array_intersect( $types, self::ARTICLE_TYPES ) ) {
				$article[] = $obj;
			}
		}

		$permalink = $post_id ? (string) get_permalink( $post_id ) : '';
		$canonical = null;
		$canon     = $xp->query( self::CANONICAL_XPATH );
		if ( $canon && $canon->length ) {
			$href      = trim( (string) $canon->item( 0 )->getAttribute( 'href' ) );
			$canonical = self::cut( $href, 300 );
			// A relative canonical is read against the page it is on.
			$absolute  = '' !== $href ? (string) \WP_Http::make_absolute_url( $href, $target['url'] ) : '';
			if ( $permalink && ! $given_url && self::norm_url( $absolute ) !== self::norm_url( $permalink ) ) {
				$add( 'error', 'canonical_mismatch', sprintf( 'The canonical URL %s is not the post permalink %s.', $canonical, $permalink ) );
			}
			if ( $canon->length > 1 ) {
				$add( 'warning', 'duplicate_canonical', sprintf( 'The page has %d canonical links.', $canon->length ) );
			}
		}

		$label = static function ( array $a ) {
			return implode( '/', self::types_of( $a ) );
		};
		if ( count( $article ) > 1 ) {
			$add( 'error', 'duplicate_article', sprintf( 'The page has %d Article-type objects (%s); search engines expect one.', count( $article ), self::cut( implode( ', ', array_map( $label, $article ) ), 200 ) ) );
		}
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $article && $post && 'post' === $post->post_type ) {
			$add( 'warning', 'no_article', 'The page has no Article, NewsArticle or BlogPosting object.' );
		}

		$summary = null;
		if ( $article ) {
			$a       = $article[0];
			$images  = array_slice( array_map( static function ( $u ) { return self::cut( $u, 300 ); }, self::image_urls( $a['image'] ?? null, $by_id ) ), 0, 10 );
			$summary = [
				'type'              => $label( $a ),
				'headline'          => (string) self::text_or_null( $a['headline'] ?? null ),
				'date_published'    => self::text_or_null( $a['datePublished'] ?? null, 40 ),
				'date_modified'     => self::text_or_null( $a['dateModified'] ?? null, 40 ),
				'author_present'    => ! empty( $a['author'] ),
				'publisher_present' => ! empty( $a['publisher'] ),
				'image_urls'        => $images,
				'url'               => self::entity_url( $a ),
			];
			$titles = $post ? array_filter( [ self::squash( get_the_title( $post ) ), self::squash( self::seo_title( $post_id ) ), self::squash( self::page_title( $xp ), true ) ] ) : [];
			foreach ( $article as $each ) {
				// Compared whole; only what is shown is shortened.
				$headline = $each['headline'] ?? null;
				$headline = self::squash( is_array( $headline ) && $headline ? reset( $headline ) : $headline );
				if ( '' === $headline ) {
					$add( 'error', 'headline_missing', sprintf( 'The %s object has no headline.', $label( $each ) ) );
					continue;
				}
				$hit = ! $titles;
				foreach ( $titles as $t ) {
					// Equal, or one starts with the other (a title with the site name after it) when the shorter has some length.
					if ( 0 === strcasecmp( $t, $headline ) || ( min( strlen( $t ), strlen( $headline ) ) >= 10 && ( 0 === stripos( $t, $headline ) || 0 === stripos( $headline, $t ) ) ) ) {
						$hit = true;
					}
				}
				if ( ! $hit ) {
					$add( 'warning', 'headline_mismatch', sprintf( 'The %s headline "%s" matches neither the post title nor the SEO title.', $label( $each ), self::cut( $headline, 120 ) ) );
				}
			}
			if ( $post ) {
				foreach ( [ 'datePublished' => [ 'date_published', 'date' ], 'dateModified' => [ 'date_modified', 'modified' ] ] as $key => $pair ) {
					$value = self::text_or_null( $a[ $key ] ?? null, 40 );
					if ( null === $value ) {
						$add( 'warning', $pair[0] . '_missing', sprintf( 'The Article object has no %s.', $key ) );
						continue;
					}
					// A post that was never published has no date of its own yet; nothing to compare with.
					$post_ts = get_post_timestamp( $post, $pair[1] );
					$when    = self::schema_time( $value );
					if ( null === $when ) {
						$add( 'warning', $pair[0] . '_unreadable', sprintf( '%s is "%s", which is not a date.', $key, $value ) );
					} elseif ( false === $post_ts || $post_ts <= 0 ) {
						continue;
					} elseif ( $when['day_only'] ? wp_date( 'Y-m-d', $post_ts ) !== $when['day'] : abs( $when['ts'] - $post_ts ) > 120 ) {
						$add( 'warning', $pair[0] . '_mismatch', sprintf( '%s is %s; the post says %s.', $key, $value, wp_date( 'c', $post_ts ) ) );
					}
				}
				$entity_url = self::entity_url( $a );
				if ( $permalink && $entity_url && ! $given_url && self::norm_url( (string) \WP_Http::make_absolute_url( $entity_url, $target['url'] ) ) !== self::norm_url( $permalink ) ) {
					$add( 'warning', 'url_mismatch', sprintf( 'The Article points at %s, not the permalink %s.', $entity_url, $permalink ) );
				}
				$thumb = (int) get_post_thumbnail_id( $post_id );
				if ( $thumb ) {
					$featured = (string) wp_get_attachment_url( $thumb );
					$all      = self::image_urls( $a['image'] ?? null, $by_id );
					if ( ! $all ) {
						$add( 'warning', 'image_missing', 'The post has a featured image but the Article object has no image.' );
					} elseif ( ! in_array( self::image_key( $featured ), array_map( [ __CLASS__, 'image_key' ], $all ), true ) ) {
						$add( 'warning', 'image_mismatch', 'The Article image is not the featured image.' );
					}
				}
			}
			if ( empty( $a['author'] ) ) {
				$add( 'warning', 'author_missing', 'The Article object has no author.' );
			}
			if ( empty( $a['publisher'] ) ) {
				$add( 'warning', 'publisher_missing', 'The Article object has no publisher.' );
			}
		}

		$rank_math = $post_id ? self::rank_math_schema_meta( $post_id ) : [];
		foreach ( $rank_math['double_serialised'] ?? [] as $key ) {
			$add( 'error', 'double_serialised_meta', sprintf( '%s is stored serialised twice, so Rank Math cannot read it.', $key ) );
		}
		if ( ( $rank_math['article_entries'] ?? 0 ) > 1 ) {
			$add( 'warning', 'duplicate_rank_math_schema', sprintf( 'The post has %d Article-type schema entries in Rank Math.', $rank_math['article_entries'] ) );
		}

		$errors = count( array_filter( $findings, static function ( $f ) { return 'error' === $f['severity']; } ) );
		$data   = [
			'url'                   => $target['url'],
			'final_url'             => $page['final_url'],
			'response_status'       => $page['status'],
			'post_id'               => $post_id ? $post_id : null,
			'json_ld_blocks'        => $count,
			'objects'               => count( $objects ),
			'objects_by_type'       => $by_type ? $by_type : new \stdClass(),
			'article'               => $summary,
			'canonical'             => $canonical,
			'rank_math_schema_meta' => $rank_math ? [ 'entries' => (int) $rank_math['entries'], 'article_entries' => (int) $rank_math['article_entries'] ] : null,
			'truncated'             => ! empty( $page['truncated'] ),
			'findings'              => $findings,
			'valid'                 => 0 === $errors,
		];
		return Envelope::success( sprintf( '%d JSON-LD block(s), %d object(s); %d error(s), %d warning(s).', $count, count( $objects ), $errors, count( $findings ) - $errors ), $data );
	}

	/**
	 * A schema date: a day alone (compared as a calendar day in the site's time zone), or a moment.
	 * A time written without a zone is read in the site's time zone, as schema.org leaves it local.
	 *
	 * @return array{day_only:bool, day:string, ts:int}|null null when the text is not a date.
	 */
	private static function schema_time( $value ) {
		$value = trim( (string) $value );
		if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? [ 'day_only' => true, 'day' => $value, 'ts' => 0 ] : null;
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value ) ) {
			return null;
		}
		try {
			$at = new \DateTimeImmutable( $value, wp_timezone() );
		} catch ( \Exception $e ) {
			return null;
		}
		return [ 'day_only' => false, 'day' => '', 'ts' => $at->getTimestamp() ];
	}

	/** The @type values of an object that are text, shortened. */
	private static function types_of( array $obj ) {
		$out = [];
		foreach ( (array) ( $obj['@type'] ?? [] ) as $type ) {
			if ( is_string( $type ) && '' !== trim( $type ) ) {
				$out[] = self::cut( sanitize_text_field( $type ), 60 );
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 6 );
	}

	/** The entities of a JSON-LD document: the members of @graph, or the object(s) themselves. */
	private static function schema_entities( array $json, $depth = 0 ) {
		$out = [];
		if ( $depth > 4 ) {
			return $out;
		}
		if ( $json && array_keys( $json ) === range( 0, count( $json ) - 1 ) ) {
			foreach ( $json as $item ) {
				if ( is_array( $item ) && count( $out ) < self::MAX_OBJECTS ) {
					$out = array_merge( $out, self::schema_entities( $item, $depth + 1 ) );
				}
			}
			return $out;
		}
		if ( isset( $json['@graph'] ) && is_array( $json['@graph'] ) ) {
			foreach ( $json['@graph'] as $item ) {
				if ( is_array( $item ) && count( $out ) < self::MAX_OBJECTS ) {
					$out[] = $item;
				}
			}
		}
		if ( isset( $json['@type'] ) ) {
			$out[] = $json;
		}
		return $out;
	}

	/** Image URLs of a schema image value, following @id references within the page. */
	private static function image_urls( $image, array $by_id, $depth = 0 ) {
		if ( $depth > 3 || null === $image ) {
			return [];
		}
		if ( is_string( $image ) ) {
			return [ $image ];
		}
		if ( ! is_array( $image ) ) {
			return [];
		}
		if ( $image && array_keys( $image ) === range( 0, count( $image ) - 1 ) ) {
			$out = [];
			foreach ( array_slice( $image, 0, 20 ) as $item ) {
				$out = array_merge( $out, self::image_urls( $item, $by_id, $depth + 1 ) );
			}
			return array_values( array_unique( $out ) );
		}
		if ( isset( $image['url'] ) && is_string( $image['url'] ) ) {
			return [ $image['url'] ];
		}
		if ( isset( $image['contentUrl'] ) && is_string( $image['contentUrl'] ) ) {
			return [ $image['contentUrl'] ];
		}
		if ( isset( $image['@id'] ) && is_string( $image['@id'] ) && isset( $by_id[ $image['@id'] ] ) && $by_id[ $image['@id'] ] !== $image ) {
			return self::image_urls( $by_id[ $image['@id'] ], $by_id, $depth + 1 );
		}
		return [];
	}

	/** An image file's identity without the size suffix WordPress adds to resized copies. */
	public static function image_key( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$path = (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $path );
		return strtolower( (string) preg_replace( '/-scaled(?=\.[a-z0-9]+$)/i', '', $path ) );
	}

	private static function entity_url( array $a ) {
		$m = $a['mainEntityOfPage'] ?? null;
		if ( is_array( $m ) ) {
			$m = $m['@id'] ?? ( $m['url'] ?? null );
		}
		if ( ! is_string( $m ) || '' === $m ) {
			$m = isset( $a['url'] ) && is_string( $a['url'] ) ? $a['url'] : null;
		}
		return is_string( $m ) && '' !== $m ? self::cut( $m, 300 ) : null;
	}

	/**
	 * A URL as compared: host and path without scheme, fragment or trailing slash, plus the query
	 * arguments WordPress uses to pick a post (on plain permalinks they are the whole address).
	 */
	private static function norm_url( $url ) {
		$p = wp_parse_url( (string) $url );
		if ( ! is_array( $p ) ) {
			return (string) $url;
		}
		$query = [];
		wp_parse_str( (string) ( $p['query'] ?? '' ), $query );
		$picks = array_intersect_key( $query, array_flip( [ 'p', 'page_id', 'post_type', 'name', 'pagename', 'attachment_id', 'cat', 'tag' ] ) );
		ksort( $picks );
		return strtolower( ( $p['host'] ?? '' ) . untrailingslashit( $p['path'] ?? '' ) ) . ( $picks ? '?' . (string) wp_json_encode( $picks ) : '' );
	}

	private static function page_title( \DOMXPath $xp ) {
		$t = $xp->query( '//head/title' );
		return $t && $t->length ? (string) $t->item( 0 )->textContent : '';
	}

	/**
	 * The SEO value stored for a post by the active SEO plugin, when it is plain text and not a
	 * template: AIOSEO's from its own table (where its tools write), the others' from post meta.
	 */
	private static function stored_seo_value( $post_id, $field ) {
		$plugin = Seo_Plugin::detect();
		if ( 'aioseo' === $plugin ) {
			$value = Seo_Plugin::aioseo_value( (int) $post_id, $field );
			if ( null !== $value ) {
				$value = is_string( $value ) ? $value : '';
				return preg_match( '/#[a-z_]+/i', $value ) ? '' : $value;
			}
		}
		$maps   = Seo_Plugin::field_maps();
		$key    = $maps[ $plugin ][ $field ] ?? '';
		$value  = '' !== $key ? get_post_meta( $post_id, $key, true ) : '';
		$value  = is_string( $value ) ? $value : '';
		return ( false !== strpos( $value, '%' ) || false !== strpos( $value, '#' ) ) ? '' : $value;
	}

	private static function seo_title( $post_id ) {
		return self::stored_seo_value( $post_id, 'title' );
	}

	/**
	 * Rank Math schema entries for a post, read as stored text: how many there are, how many are
	 * Article types, and which are serialised twice.
	 */
	private static function rank_math_schema_meta( $post_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s LIMIT 50", $post_id, $wpdb->esc_like( 'rank_math_schema_' ) . '%' ), ARRAY_A );
		if ( ! $rows ) {
			return [];
		}
		$double   = [];
		$articles = 0;
		foreach ( $rows as $row ) {
			$raw = (string) $row['meta_value'];
			if ( preg_match( '/^s:\d+:"(?:a|O):\d+:\{/', $raw ) ) {
				$double[] = sanitize_key( (string) $row['meta_key'] );
			}
			if ( preg_match( '/"@type";s:\d+:\\\\?"(?:' . implode( '|', self::ARTICLE_TYPES ) . ')\\\\?"/', $raw ) ) {
				$articles++;
			}
		}
		return [ 'entries' => count( $rows ), 'article_entries' => $articles, 'double_serialised' => $double ];
	}

	// ------------------------------------------------------------------
	// wp_purge_and_verify_url
	// ------------------------------------------------------------------

	public static function handle_purge_and_verify( array $args ) {
		self::require_cap( 'manage_options' );
		foreach ( [ 'expect_title', 'expect_description' ] as $key ) {
			if ( isset( $args[ $key ] ) && ( ! is_string( $args[ $key ] ) || strlen( $args[ $key ] ) > 1000 || ( '' !== $args[ $key ] && mb_strlen( self::squash( $args[ $key ] ) ) < 3 ) ) ) {
				return Envelope::error( 'invalid_args', $key . ' must be text of 3 to 1000 characters.' );
			}
		}
		$target = self::resolve_target( $args, 'wp_purge_and_verify_url' );
		if ( ! isset( $target['url'] ) ) {
			return $target;
		}
		$url     = $target['url'];
		$post_id = (int) $target['post_id'];

		$purges   = self::purge_layers( $url, $post_id );
		$expected = self::expected_meta( $args, $post_id );
		$fresh    = self::fetch( add_query_arg( 'rmcp_verify', strtolower( wp_generate_password( 10, false ) ), $url ), [ 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' ] );
		$served   = is_wp_error( $fresh ) ? $fresh : self::fetch( $url );
		if ( is_wp_error( $served ) ) {
			return Envelope::error( 'fetch_failed', $served->get_error_message() . ' The caches were purged first: ' . ( implode( ', ', array_column( $purges, 'layer' ) ) ?: 'none active' ) . '.', [ 'purged' => $purges ] );
		}
		$fresh_meta  = self::served_meta( $fresh['body'] );
		$served_meta = self::served_meta( $served['body'] );

		$compare = static function ( array $meta ) use ( $expected ) {
			$result = [];
			foreach ( [ 'title', 'description' ] as $field ) {
				if ( '' === self::squash( $expected[ $field ] ) ) {
					continue;
				}
				$have = (string) $meta[ $field ];
				$want = self::squash( $expected[ $field ] );
				$result[ $field ] = 'title' === $field ? ( '' !== $have && false !== stripos( $have, $want ) ) : ( 0 === strcasecmp( $have, $want ) );
			}
			return $result;
		};
		$fresh_ok  = $compare( $fresh_meta );
		$served_ok = $compare( $served_meta );
		$suspect   = self::cache_signal( $served['headers'] );
		$bad_load  = ( $fresh['status'] < 200 || $fresh['status'] >= 300 || $served['status'] < 200 || $served['status'] >= 300 );

		if ( $bad_load ) {
			$status = 'page_not_ok';
			$text   = sprintf( 'The page answered HTTP %d (fresh load) and HTTP %d (as visitors get it), so its SEO values could not be confirmed.', $fresh['status'], $served['status'] );
		} elseif ( $fresh_ok ) {
			if ( in_array( false, $fresh_ok, true ) ) {
				$status = 'update_failed';
				$text   = 'The page does not show the expected SEO values even on a fresh load, so the change did not reach the page output.';
			} elseif ( in_array( false, $served_ok, true ) ) {
				$status = 'saved_but_cached';
				$text   = 'The expected SEO values show on a fresh load, but visitors are still served the old page' . ( $suspect ? ' (cache: ' . $suspect . ')' : '' ) . '.';
			} else {
				$status = 'verified_live';
				$text   = 'Visitors are served the expected SEO values.';
			}
		} elseif ( '' === $fresh_meta['title'] && '' === $served_meta['title'] ) {
			$status = 'nothing_to_compare';
			$text   = 'No expected SEO values were given or stored, and the page has no title to compare, so nothing was verified.';
		} else {
			$same   = $fresh_meta['title'] === $served_meta['title'] && $fresh_meta['description'] === $served_meta['description'];
			$status = $same ? 'verified_live' : 'saved_but_cached';
			$text   = $same ? 'No expected SEO values to compare; the page visitors get matches a fresh load.' : 'No expected SEO values to compare, and the page visitors get differs from a fresh load' . ( $suspect ? ' (cache: ' . $suspect . ')' : '' ) . '.';
		}
		// LiteSpeed carries out a purge when this reply is sent, which is after the two loads above.
		if ( 'saved_but_cached' === $status && in_array( 'litespeed', array_column( $purges, 'layer' ), true ) ) {
			$text .= ' LiteSpeed Cache applies its purge once this reply has gone out, so run the check again before treating the page as stale.';
		}

		$data = [
			'url'             => $url,
			'post_id'         => $post_id ? $post_id : null,
			'status'          => $status,
			'suspected_layer' => 'saved_but_cached' === $status ? ( $suspect ? $suspect : 'unknown' ) : null,
			'purged'          => $purges,
			'expected'        => $expected,
			'fresh'           => array_map( static function ( $v ) { return self::cut( $v, 600 ); }, $fresh_meta ) + [ 'response_status' => $fresh['status'], 'matches' => $fresh_ok ? $fresh_ok : new \stdClass() ],
			'served'          => array_map( static function ( $v ) { return self::cut( $v, 600 ); }, $served_meta ) + [ 'response_status' => $served['status'], 'matches' => $served_ok ? $served_ok : new \stdClass(), 'cache_headers' => self::cache_headers( $served['headers'] ) ],
		];
		return Envelope::success( $status . ': ' . $text, $data );
	}

	/** Purge one URL (or its post) in every cache plugin that is active. Shared with the launch tools. */
	public static function purge_layers( $url, $post_id ) {
		$out = [];
		$try = static function ( $layer, callable $fn ) use ( &$out ) {
			// Whatever the cache plugin prints, or does to the output buffers, is undone afterwards.
			$level = ob_get_level();
			ob_start();
			try {
				$detail = $fn();
				// A layer may answer [ purged, detail, queued ] when it only queues the purge or caches nothing here.
				if ( is_array( $detail ) ) {
					$out[] = [ 'layer' => $layer, 'purged' => (bool) $detail[0], 'detail' => (string) ( $detail[1] ?? '' ), 'queued' => ! empty( $detail[2] ) ];
				} else {
					$out[] = [ 'layer' => $layer, 'purged' => true, 'detail' => is_string( $detail ) ? $detail : '' ];
				}
			} catch ( \Throwable $e ) {
				$out[] = [ 'layer' => $layer, 'purged' => false, 'detail' => (string) Location_Filter::clean( $e->getMessage(), 160 ) ];
			}
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			while ( ob_get_level() < $level ) {
				ob_start();
			}
		};
		if ( class_exists( '\\ForgeCache_Cache' ) && class_exists( '\\Royal_MCP\\Integrations\\ForgeCache' ) ) {
			$try( 'forgecache', static function () use ( $url ) {
				$r = ForgeCache::execute_tool( 'fc_purge_url', [ 'url' => $url ] );
				return is_array( $r ) && isset( $r['path'] ) && is_string( $r['path'] ) ? sanitize_key( $r['path'] ) : '';
			} );
		}
		if ( defined( 'LSCWP_V' ) || class_exists( '\\LiteSpeed\\Core' ) ) {
			$try( 'litespeed', static function () use ( $url, $post_id ) {
				// LiteSpeed keys a page by its path on the site.
				$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
				$qs   = (string) wp_parse_url( (string) $url, PHP_URL_QUERY );
				do_action( 'litespeed_purge_url', ( '' === $path ? '/' : $path ) . ( '' !== $qs ? '?' . $qs : '' ) );
				if ( $post_id ) {
					do_action( 'litespeed_purge_post', $post_id );
				}
				if ( defined( 'LITESPEED_ALLOWED' ) && ! LITESPEED_ALLOWED ) {
					return [ false, 'LiteSpeed is not serving pages on this server, so it caches nothing to purge', false ];
				}
				return [ true, 'purge queued; LiteSpeed carries it out when this reply is sent', true ];
			} );
		}
		if ( function_exists( 'w3tc_flush_url' ) ) {
			$try( 'w3tc', static function () use ( $url, $post_id ) {
				$on = true;
				if ( class_exists( '\\W3TC\\Dispatcher' ) && method_exists( '\\W3TC\\Dispatcher', 'config' ) ) {
					$config = \W3TC\Dispatcher::config();
					$on     = is_object( $config ) && method_exists( $config, 'get_boolean' ) ? (bool) $config->get_boolean( 'pgcache.enabled' ) : true;
				}
				w3tc_flush_url( $url );
				if ( $post_id && function_exists( 'w3tc_flush_post' ) ) {
					w3tc_flush_post( $post_id );
				}
				return $on ? [ true, 'purge queued; W3 Total Cache carries it out at the end of this request', true ] : [ false, 'the W3 Total Cache page cache is switched off', false ];
			} );
		}
		if ( function_exists( 'rocket_clean_files' ) ) {
			$try( 'wp_rocket', static function () use ( $url ) {
				rocket_clean_files( [ $url ] );
				return '';
			} );
		}
		if ( function_exists( 'wpsc_delete_url_cache' ) || function_exists( 'wp_cache_post_change' ) ) {
			$try( 'wp_super_cache', static function () use ( $url, $post_id ) {
				// By post where possible: WP Super Cache does not delete addresses with a query string (?p=123).
				if ( $post_id && function_exists( 'wp_cache_post_change' ) ) {
					wp_cache_post_change( $post_id );
					return 'by post';
				}
				if ( ! function_exists( 'wpsc_delete_url_cache' ) || false === wpsc_delete_url_cache( $url ) ) {
					throw new \Exception( 'WP Super Cache reported that the page was not removed.' );
				}
				return '';
			} );
		}
		if ( class_exists( '\\WPO_Page_Cache' ) && class_exists( '\\WPO_Cache_Config' ) && (bool) \WPO_Cache_Config::instance()->get_option( 'enable_page_caching', false ) ) {
			$try( 'wp_optimize', static function () use ( $url, $post_id ) {
				// By post where possible (its own address and numbered pages only, never the pages
				// below it), except the page shown as the home page, which is purged by address.
				$is_front = $post_id && 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $post_id;
				if ( $post_id && ! $is_front && class_exists( '\\Royal_MCP\\Integrations\\WPOptimize' ) && method_exists( '\\Royal_MCP\\Integrations\\WPOptimize', 'purge_post_page' ) ) {
					if ( ! \Royal_MCP\Integrations\WPOptimize::purge_post_page( $post_id ) ) {
						throw new \Exception( 'Some cached files could not be deleted.' );
					}
					return 'by post';
				}
				if ( ! method_exists( '\\WPO_Page_Cache', 'delete_cache_by_url' ) ) {
					throw new \Exception( 'This version of WP-Optimize has no per-page purge.' );
				}
				// Only this address, never the pages below it.
				\WPO_Page_Cache::delete_cache_by_url( $url, false );
				return '';
			} );
		}
		if ( $post_id && class_exists( '\\CF\\WordPress\\Hooks' ) ) {
			$try( 'cloudflare', static function () use ( $post_id ) {
				$hooks = new \CF\WordPress\Hooks();
				if ( ! method_exists( $hooks, 'purgeCacheByRelevantURLs' ) ) {
					throw new \Exception( 'This version of the Cloudflare plugin has no per-page purge.' );
				}
				$hooks->purgeCacheByRelevantURLs( $post_id );
				return 'through the Cloudflare plugin';
			} );
		}
		if ( $post_id ) {
			clean_post_cache( $post_id );
			$out[] = [ 'layer' => 'object_cache', 'purged' => true, 'detail' => 'post cache cleared' ];
		}
		return $out;
	}

	/** The title and description the page should show: the caller's, else the SEO plugin's stored plain values. */
	private static function expected_meta( array $args, $post_id ) {
		$out = [
			'title'       => isset( $args['expect_title'] ) ? self::squash( $args['expect_title'] ) : '',
			'description' => isset( $args['expect_description'] ) ? self::squash( $args['expect_description'] ) : '',
		];
		if ( $post_id ) {
			foreach ( array_keys( $out ) as $field ) {
				if ( '' === $out[ $field ] ) {
					$out[ $field ] = self::cut( self::squash( self::stored_seo_value( $post_id, $field ) ), 1000 );
				}
			}
		}
		return $out;
	}

	/** Title, meta description, og:title and canonical of a served page. */
	private static function served_meta( $body ) {
		$out = [ 'title' => '', 'description' => '', 'og_title' => '', 'canonical' => '' ];
		$doc = self::parse( (string) $body );
		if ( ! $doc ) {
			return $out;
		}
		$xp           = new \DOMXPath( $doc );
		$out['title'] = self::cut( self::squash( self::page_title( $xp ), true ), 2000 );
		$metas        = $xp->query( '//meta' );
		foreach ( $metas ? $metas : [] as $meta ) {
			$name = strtolower( trim( (string) ( $meta->getAttribute( 'name' ) ?: $meta->getAttribute( 'property' ) ) ) );
			if ( 'description' === $name && '' === $out['description'] ) {
				$out['description'] = self::cut( self::squash( $meta->getAttribute( 'content' ), true ), 2000 );
			} elseif ( 'og:title' === $name && '' === $out['og_title'] ) {
				$out['og_title'] = self::cut( self::squash( $meta->getAttribute( 'content' ), true ), 2000 );
			}
		}
		$canon = $xp->query( self::CANONICAL_XPATH );
		if ( $canon && $canon->length ) {
			$out['canonical'] = self::cut( (string) $canon->item( 0 )->getAttribute( 'href' ), 300 );
		}
		return $out;
	}

	private static function cache_headers( array $headers ) {
		$out = [];
		foreach ( array_merge( array_keys( self::CACHE_HEADERS ), [ 'age', 'cache-control' ] ) as $h ) {
			if ( isset( $headers[ $h ] ) ) {
				$out[ $h ] = self::cut( sanitize_text_field( $headers[ $h ] ), 120 );
			}
		}
		return $out ? $out : new \stdClass();
	}

	/** The cache whose response header says the page came from cache. */
	private static function cache_signal( array $headers ) {
		foreach ( self::CACHE_HEADERS as $h => $layer ) {
			if ( isset( $headers[ $h ] ) && preg_match( '/\bhit\b/i', $headers[ $h ] ) ) {
				return $layer;
			}
		}
		if ( isset( $headers['x-varnish'] ) && preg_match( '/^\d+\s+\d+$/', trim( $headers['x-varnish'] ) ) ) {
			return 'varnish';
		}
		return null;
	}

	// ------------------------------------------------------------------
	// Tool definitions
	// ------------------------------------------------------------------

	private static function validate_schema_schema() {
		return [
			'name'        => 'wp_validate_schema',
			'description' => 'Check the JSON-LD structured data on the page this site serves for a post or URL. Counts objects by @type and flags: JSON-LD blocks that are not valid JSON, more than one Article / NewsArticle / BlogPosting object, a missing headline, a headline that matches neither the post title nor the SEO title, publish and modified dates that are missing, unreadable or differ from the post, an Article that points at another URL, a canonical that is not the permalink, a missing or different image when the post has a featured image, a missing author or publisher, and Rank Math schema entries that are duplicated or stored unreadably. The page is loaded as a visitor gets it; only pages of this site are loaded. A post that is not public needs a preview link from wp_create_preview_link passed as url. Pages over 3 MB are read up to that size and marked truncated. Up to 10 calls a minute per page and 30 a minute in all. Read-only. Requires edit access to the post, or manage_options for a URL that is not a post.',
			'inputSchema' => [
				'type'                 => 'object',
				'properties'           => [
					'post_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The post to check. Its permalink is loaded unless url is given.' ],
					'url'     => [ 'type' => 'string', 'description' => 'URL on this site to load instead of the permalink, for example a preview link.' ],
				],
				'additionalProperties' => false,
			],
			'outputSchema' => [
				'type'       => 'object',
				'properties' => [
					'url'                   => [ 'type' => 'string' ],
					'final_url'             => [ 'type' => 'string' ],
					'response_status'       => [ 'type' => 'integer' ],
					'post_id'               => [ 'type' => [ 'integer', 'null' ] ],
					'json_ld_blocks'        => [ 'type' => 'integer' ],
					'objects'               => [ 'type' => 'integer' ],
					'objects_by_type'       => [ 'type' => 'object' ],
					'article'               => [ 'type' => [ 'object', 'null' ] ],
					'canonical'             => [ 'type' => [ 'string', 'null' ] ],
					'rank_math_schema_meta' => [ 'type' => [ 'object', 'null' ] ],
					'truncated'             => [ 'type' => 'boolean' ],
					'findings'              => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'properties' => [ 'severity' => [ 'type' => 'string', 'enum' => [ 'error', 'warning' ] ], 'code' => [ 'type' => 'string' ], 'message' => [ 'type' => 'string' ] ] ] ],
					'valid'                 => [ 'type' => 'boolean' ],
				],
			],
		];
	}

	private static function purge_schema() {
		return [
			'name'        => 'wp_purge_and_verify_url',
			'description' => 'After an SEO or content change, purge one page from the active cache plugins and check what visitors are served. Purges the URL (or its post) in ForgeCache, LiteSpeed Cache, W3 Total Cache, WP Rocket, WP Super Cache and, through its plugin, Cloudflare, plus the object cache for the post; then loads the page twice, once past the caches and once as visitors get it, and compares title and meta description with the expected values. status is verified_live, saved_but_cached (with suspected_layer from the response headers), update_failed (the values are wrong even on a fresh load), page_not_ok (the page did not answer normally) or nothing_to_compare (no expected values and no title on the page). LiteSpeed Cache carries out its purge after the reply is sent, so on LiteSpeed a saved_but_cached answer needs a second check. A cache at the host or a CDN with no plugin on the site cannot be purged from here. It changes no content; a purge cannot be undone and the page is rebuilt on the next visit. Not available in read-only mode. Up to 10 calls a minute per page and 30 a minute in all. Requires manage_options.',
			'inputSchema' => [
				'type'                 => 'object',
				'properties'           => [
					'url'                => [ 'type' => 'string', 'description' => 'URL on this site to purge and check.' ],
					'post_id'            => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Post to purge and check (its permalink), instead of url.' ],
					'expect_title'       => [ 'type' => 'string', 'minLength' => 3, 'maxLength' => 1000, 'description' => 'Title the page should show. The served <title> must contain it. Default: the SEO plugin\'s stored title when it is plain text.' ],
					'expect_description' => [ 'type' => 'string', 'minLength' => 3, 'maxLength' => 1000, 'description' => 'Meta description the page should show. Default: the SEO plugin\'s stored description when it is plain text.' ],
				],
				'additionalProperties' => false,
			],
			'outputSchema' => [
				'type'       => 'object',
				'properties' => [
					'url'             => [ 'type' => 'string' ],
					'post_id'         => [ 'type' => [ 'integer', 'null' ] ],
					'status'          => [ 'type' => 'string', 'enum' => [ 'update_failed', 'saved_but_cached', 'verified_live', 'page_not_ok', 'nothing_to_compare' ] ],
					'suspected_layer' => [ 'type' => [ 'string', 'null' ] ],
					'purged'          => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'properties' => [ 'layer' => [ 'type' => 'string' ], 'purged' => [ 'type' => 'boolean' ], 'detail' => [ 'type' => 'string' ] ] ] ],
					'expected'        => [ 'type' => 'object' ],
					'fresh'           => [ 'type' => 'object' ],
					'served'          => [ 'type' => 'object' ],
				],
			],
		];
	}
}
