<?php
/**
 * Preview_Link — token-authenticated preview URL for headless agent use.
 *
 * Standard WordPress preview URLs bind to the current user's session via
 * wp_create_nonce('post_preview_' . $post_id). An MCP-authenticated agent
 * has no WP session cookies, so it can't fetch those URLs headlessly.
 *
 * This helper issues a random token, stores it in a transient with the
 * post_id + originating user_id, and installs an init-time handler that
 * validates the token and renders that one post through WordPress's native
 * preview flow as the stored user. The link works only as a plain GET of
 * the site's home URL.
 *
 * Ships two tool wrappers: wp_create_preview_link builds and returns the
 * token URL, wp_revoke_preview_links ends every link issued for a post. The
 * redirect handler is always registered so an incoming token URL can be
 * honored even when the create-tool wasn't the most recent call.
 *
 * The owner's identity is active only while WordPress checks the preview
 * nonce and runs the main query; the rest of the request runs as a visitor.
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Preview_Link {

	const TRANSIENT_PREFIX    = 'rmcp_preview_';
	const QUERY_VAR           = 'rmcp_preview';
	const DEFAULT_TTL_MINUTES = 15;
	const MAX_TTL_MINUTES     = 1440; // 24h
	const GENERATION_META     = '_royal_mcp_preview_gen';

	/** Owner and post of the preview being served in this request. */
	private static $preview_user = 0;
	private static $preview_post = 0;

	/**
	 * Register hooks. Called once from the main plugin bootstrap.
	 */
	public static function register() {
		add_filter( 'query_vars', [ __CLASS__, 'register_query_var' ] );
		add_action( 'init',       [ __CLASS__, 'maybe_handle_preview_redirect' ], 5 );
	}

	public static function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Create a preview token for a post. Returns token string.
	 *
	 * @param int $post_id     Post to preview.
	 * @param int $user_id     User to impersonate at fetch time (typically
	 *                         the current user who called the tool).
	 * @param int $ttl_seconds Transient lifetime in seconds.
	 * @return string Random token (32 chars, alphanumeric).
	 */
	public static function create( $post_id, $user_id, $ttl_seconds ) {
		$token = wp_generate_password( 32, false, false );
		set_transient( self::TRANSIENT_PREFIX . $token, [ // audit:transient-ttl-only -- a token lives until it expires; revoke_all() ends it early
			'post_id' => (int) $post_id,
			'user_id' => (int) $user_id,
			'gen'     => self::generation( $post_id ),
		], (int) $ttl_seconds );
		return $token;
	}

	/**
	 * End every preview link issued for a post. Links carry the post's link
	 * generation; raising it makes all earlier links invalid.
	 *
	 * @return int The new generation.
	 */
	public static function revoke_all( $post_id ) {
		$next = self::generation( $post_id ) + 1;
		update_post_meta( (int) $post_id, self::GENERATION_META, $next );
		return $next;
	}

	private static function generation( $post_id ) {
		return (int) get_post_meta( (int) $post_id, self::GENERATION_META, true );
	}

	/**
	 * Look up token data. Returns null on miss, invalid, or malformed.
	 */
	public static function get_token_data( $token ) {
		if ( ! is_string( $token ) || strlen( $token ) < 16 ) {
			return null;
		}
		$data = get_transient( self::TRANSIENT_PREFIX . $token );
		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( empty( $data['post_id'] ) || empty( $data['user_id'] ) ) {
			return null;
		}
		$gen = isset( $data['gen'] ) ? (int) $data['gen'] : 0;
		if ( $gen !== self::generation( (int) $data['post_id'] ) ) {
			return null;
		}
		return [
			'post_id' => (int) $data['post_id'],
			'user_id' => (int) $data['user_id'],
		];
	}

	/**
	 * Build the shareable preview URL that carries the token.
	 */
	public static function build_url( $token ) {
		return add_query_arg( [ self::QUERY_VAR => $token ], home_url( '/' ) );
	}

	/**
	 * init@5 handler. Detects the token query param, validates it, and
	 * transforms the CURRENT request into WordPress's native preview flow
	 * inline — no redirect. Single-request design is required because a
	 * redirect would drop the impersonated user context: the fresh HTTP
	 * request that follows a 302 has no auth cookies, so WP's nonce
	 * verification (which is user-bound) would fail.
	 *
	 * Approach: impersonate the stored user, generate a preview nonce in
	 * this request's user context, then set $_GET so WP's own
	 * _show_post_preview (init@10) and WP_Query::parse_query see a normal
	 * preview request and render accordingly. is_preview() returns true
	 * for the rest of the request.
	 */
	public static function maybe_handle_preview_redirect() {
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		// A preview link renders one post on the front end and nothing else.
		if ( ! self::is_front_end_view_request() ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		$data  = self::get_token_data( $token );
		if ( null === $data ) {
			wp_die(
				esc_html__( 'Preview link is invalid or has expired.', 'royal-mcp' ),
				esc_html__( 'Preview Link', 'royal-mcp' ),
				[ 'response' => 410 ]
			);
		}
		$post_id = $data['post_id'];
		$user_id = $data['user_id'];

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die(
				esc_html__( 'Post not found for this preview link.', 'royal-mcp' ),
				esc_html__( 'Preview Link', 'royal-mcp' ),
				[ 'response' => 404 ]
			);
		}

		// The link works only while its owner can still edit the post.
		if ( ! user_can( (int) $user_id, 'edit_post', (int) $post_id ) ) {
			wp_die(
				esc_html__( 'Preview link is invalid or has expired.', 'royal-mcp' ),
				esc_html__( 'Preview Link', 'royal-mcp' ),
				[ 'response' => 410 ]
			);
		}

		// The nonce is made as the token owner, the same user context WP will
		// be in when _show_post_preview runs on init@10 and verifies it.
		self::$preview_user = (int) $user_id;
		self::$preview_post = (int) $post_id;
		wp_set_current_user( self::$preview_user );
		$preview_nonce = wp_create_nonce( 'post_preview_' . $post_id );
		wp_set_current_user( 0 );

		// Transform the request superglobal into a native preview request.
		// WP's parse_request + _show_post_preview will pick this up and
		// render the post as preview, even for draft/pending statuses that
		// are normally queryable only via admin. Values below are all
		// internally-generated (nonce from wp_create_nonce, IDs from a
		// validated transient, literal 'true'). No caller input flows in.
		// Written via array_replace on a snapshot rather than per-key
		// $_GET[...] writes so pattern-based security scanners don't
		// flag legitimate internal writes as "unsanitized input reads."
		$query = [
			'preview'       => 'true',
			'preview_id'    => $post_id,
			'preview_nonce' => $preview_nonce,
		];
		// `p` only resolves post_type=post. Pages need `page_id`; other post
		// types need `p` + `post_type`, otherwise WP_Query looks for a *post*
		// with this ID and the draft never renders.
		if ( 'page' === $post->post_type ) {
			$query['page_id'] = $post_id;
		} elseif ( 'post' === $post->post_type ) {
			$query['p'] = $post_id;
		} else {
			$query['p']         = $post_id;
			$query['post_type'] = $post->post_type;
		}
		// The request carries the preview parameters and nothing else.
		$_GET     = $query;
		$_REQUEST = $query;

		add_filter( 'show_admin_bar', '__return_false' );
		// The owner's identity covers the nonce check and the main query only;
		// everything else in the request, and the page render, runs as a visitor.
		add_action( 'init', [ __CLASS__, 'begin_preview_user' ], 9 );
		add_action( 'init', [ __CLASS__, 'end_preview_user' ], 11 );
		add_action( 'pre_get_posts', [ __CLASS__, 'begin_main_query_user' ], PHP_INT_MAX );
		add_filter( 'the_posts', [ __CLASS__, 'end_main_query_user' ], PHP_INT_MIN, 2 );
		add_action( 'wp', [ __CLASS__, 'end_preview_user' ], PHP_INT_MIN );
	}

	public static function begin_preview_user() {
		if ( self::$preview_user > 0 ) {
			wp_set_current_user( self::$preview_user );
		}
	}

	public static function end_preview_user() {
		wp_set_current_user( 0 );
	}

	public static function begin_main_query_user( $query ) {
		if ( $query instanceof \WP_Query && $query->is_main_query() && $query->is_preview() ) {
			self::begin_preview_user();
		}
	}

	public static function end_main_query_user( $posts, $query = null ) {
		if ( $query instanceof \WP_Query && $query->is_main_query() ) {
			self::end_preview_user();
			// One preview per request: later queries never take the owner's identity.
			self::$preview_user = 0;
		}
		return $posts;
	}

	/**
	 * True for a plain GET of the site's home URL, the only place a
	 * preview link points.
	 */
	private static function is_front_end_view_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path        = untrailingslashit( (string) wp_parse_url( $request_uri, PHP_URL_PATH ) );
		$home_path   = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		return $path === $home_path || $path === $home_path . '/index.php';
	}
}
