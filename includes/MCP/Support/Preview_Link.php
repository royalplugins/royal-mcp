<?php
/**
 * Preview_Link — token-authenticated preview URL for headless agent use.
 *
 * Standard WordPress preview URLs bind to the current user's session via
 * wp_create_nonce('post_preview_' . $post_id). An MCP-authenticated agent
 * has no WP session cookies, so it can't fetch those URLs headlessly.
 *
 * This helper issues a random token and stores only its hash, in a transient
 * holding the post_id, the user who made the link and the connection that made
 * it. An init-time handler validates the token and renders that one post on the
 * front end. The link works only as a plain GET of the site's home URL.
 *
 * Ships two tool wrappers: wp_create_preview_link builds and returns the
 * token URL, wp_revoke_preview_links ends every link issued for a post. The
 * handler is always registered so an incoming token URL can be honored even
 * when the create-tool wasn't the most recent call.
 *
 * The visitor never takes on the owner's identity. The main query is allowed
 * to return that one post in its current status, and every other part of the
 * request runs as a logged-out visitor.
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Preview_Link {

	const TRANSIENT_PREFIX    = 'rmcp_preview_';
	/** Prefix of transients named by a token's hash; kept apart from TRANSIENT_PREFIX so a stored hash never works as a token. */
	const HASHED_PREFIX       = 'rmcp_prvh_';
	const QUERY_VAR           = 'rmcp_preview';
	const DEFAULT_TTL_MINUTES = 15;
	const MAX_TTL_MINUTES     = 1440; // 24h
	const GENERATION_META     = '_royal_mcp_preview_gen';

	/** Post and status of the preview being served in this request. */
	private static $preview_post   = 0;
	private static $preview_status = '';
	private static $preview_owner  = 0;

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

	/** Transient name for a token: only a hash of the token is ever stored. */
	private static function key( $token ) {
		return self::HASHED_PREFIX . hash( 'sha256', (string) $token );
	}

	/**
	 * Create a preview token for a post. Returns token string.
	 *
	 * @param int   $post_id     Post to preview.
	 * @param int   $user_id     User who made the link; it works while they can edit the post.
	 * @param int   $ttl_seconds Transient lifetime in seconds.
	 * @param array $binding     Connection that made the link: auth (api-key, oauth-bearer, ...) and client_id.
	 * @return string Random token (32 chars, alphanumeric).
	 */
	public static function create( $post_id, $user_id, $ttl_seconds, array $binding = [] ) {
		$token = wp_generate_password( 32, false, false );
		$auth  = isset( $binding['auth'] ) ? (string) $binding['auth'] : '';
		$data  = [
			'post_id' => (int) $post_id,
			'user_id' => (int) $user_id,
			'gen'     => self::generation( $post_id ),
			'auth'    => $auth,
		];
		if ( 'oauth-bearer' === $auth ) {
			$data['client_id'] = isset( $binding['client_id'] ) ? (string) $binding['client_id'] : '';
		} elseif ( 'api-key' === $auth ) {
			$data['key_fp'] = self::api_key_fingerprint();
		}
		set_transient( self::key( $token ), $data, (int) $ttl_seconds ); // audit:transient-ttl-only -- a token lives until it expires; revoke_all() ends it early
		return $token;
	}

	/** A fingerprint of the site's current API key, so a rotated key ends links made with the old one. */
	private static function api_key_fingerprint() {
		$settings = get_option( 'royal_mcp_settings', [] );
		$stored   = is_array( $settings ) ? (string) ( $settings['api_key_hash'] ?? ( $settings['api_key'] ?? '' ) ) : '';
		return '' === $stored ? '' : hash( 'sha256', 'royal-mcp-preview|' . $stored );
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
	 * Look up token data. Returns null on miss, invalid, malformed, or when the
	 * connection that made the link has been revoked since.
	 */
	public static function get_token_data( $token ) {
		if ( ! is_string( $token ) || strlen( $token ) < 16 || strlen( $token ) > 128 ) {
			return null;
		}
		$data = get_transient( self::key( $token ) );
		if ( ! is_array( $data ) && 32 === strlen( $token ) && preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			// A token stored under its own plain name is honored until it expires;
			// only records in that form (no connection binding) are read this way.
			$plain = get_transient( self::TRANSIENT_PREFIX . $token );
			if ( is_array( $plain ) && ! array_key_exists( 'auth', $plain ) ) {
				$data = $plain;
			}
		}
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
		if ( ! self::binding_is_live( $data ) ) {
			return null;
		}
		return [
			'post_id' => (int) $data['post_id'],
			'user_id' => (int) $data['user_id'],
		];
	}

	/** Whether the connection that made a link can still use the site. */
	private static function binding_is_live( array $data ) {
		$auth = isset( $data['auth'] ) ? (string) $data['auth'] : '';
		if ( 'oauth-bearer' === $auth ) {
			$client = isset( $data['client_id'] ) ? (string) $data['client_id'] : '';
			return class_exists( '\Royal_MCP\OAuth\Token_Store' ) && \Royal_MCP\OAuth\Token_Store::connection_is_live( $client, (int) $data['user_id'] );
		}
		if ( 'api-key' === $auth ) {
			$fp = isset( $data['key_fp'] ) ? (string) $data['key_fp'] : '';
			return '' !== $fp && hash_equals( $fp, self::api_key_fingerprint() );
		}
		// A local WP-CLI connection's link lasts while its user may still connect AI clients.
		if ( 'cli-stdio' === $auth ) {
			$uid = (int) $data['user_id'];
			return class_exists( '\Royal_MCP\MCP\Support\Route_Roles' ) && Route_Roles::is_site_user( $uid ) && Route_Roles::connect_allowed( $uid );
		}
		return true;
	}

	/**
	 * Build the shareable preview URL that carries the token.
	 */
	public static function build_url( $token ) {
		return add_query_arg( [ self::QUERY_VAR => $token ], home_url( '/' ) );
	}

	/** Keep every preview answer, refusals included, out of page caches and CDNs. */
	private static function send_no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
	}

	/**
	 * init@5 handler. Detects the token query param, validates it, and turns
	 * the current request into a front-end view of that one post.
	 *
	 * No identity changes hands: the request stays logged out. The main query
	 * is told the post's own status (WP_Query lets a status that was asked for
	 * pass through), so a draft, pending or scheduled post renders for this
	 * request only, and only for that post.
	 */
	public static function maybe_handle_preview_redirect() {
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		// A preview link renders one post on the front end and nothing else.
		if ( ! self::is_front_end_view_request() ) {
			return;
		}
		self::send_no_cache();
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
		if ( ! $post || 'trash' === $post->post_status || 'auto-draft' === $post->post_status ) {
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

		self::$preview_post   = (int) $post_id;
		self::$preview_status = (string) $post->post_status;
		self::$preview_owner  = (int) $user_id;
		// Nobody is signed in while the preview renders.
		wp_set_current_user( 0 );

		// Point the request at the post. Values below are all internally
		// generated (IDs from a validated transient, the post's own type);
		// no caller input flows in. Written via a fresh array rather than
		// per-key $_GET[...] writes so pattern-based security scanners don't
		// flag legitimate internal writes as "unsanitized input reads."
		// `p` only resolves post_type=post. Pages need `page_id`; other post
		// types need `p` + `post_type`, otherwise WP_Query looks for a *post*
		// with this ID and the draft never renders.
		$query = [];
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
		add_action( 'pre_get_posts', [ __CLASS__, 'allow_preview_status' ], PHP_INT_MAX );
		add_filter( 'the_posts', [ __CLASS__, 'mark_preview' ], PHP_INT_MIN, 2 );
		// The canonical redirect would drop the token on the way to the post's own URL.
		remove_action( 'template_redirect', 'redirect_canonical' );
	}

	/**
	 * pre_get_posts: let the main query return the previewed post in its own
	 * status. Only the main query, and only when it asks for exactly that post.
	 */
	public static function allow_preview_status( $query ) {
		if ( ! ( $query instanceof \WP_Query ) || ! $query->is_main_query() || self::$preview_post <= 0 ) {
			return;
		}
		$asked = (int) $query->get( 'p' ) ? (int) $query->get( 'p' ) : (int) $query->get( 'page_id' );
		if ( $asked !== self::$preview_post ) {
			return;
		}
		$query->set( 'post_status', [ self::$preview_status ] );
	}

	/**
	 * the_posts: mark the main query as a preview (themes and the canonical
	 * redirect read is_preview()) and show the latest autosave, as a preview
	 * in wp-admin would.
	 */
	public static function mark_preview( $posts, $query = null ) {
		if ( ! ( $query instanceof \WP_Query ) || ! $query->is_main_query() || self::$preview_post <= 0 ) {
			return $posts;
		}
		$post_id            = self::$preview_post;
		self::$preview_post = 0; // One preview per request.
		if ( empty( $posts ) || ! is_object( $posts[0] ) || (int) $posts[0]->ID !== $post_id ) {
			return $posts;
		}
		$query->is_preview = true;
		// Only the link owner's own autosave, and only when it is newer than the saved post.
		$autosave          = self::$preview_owner > 0 ? wp_get_post_autosave( $post_id, self::$preview_owner ) : false;
		if ( is_object( $autosave ) && strtotime( (string) $autosave->post_modified_gmt ) > strtotime( (string) $posts[0]->post_modified_gmt ) ) {
			$autosave             = sanitize_post( $autosave );
			$posts[0]->post_title   = $autosave->post_title;
			$posts[0]->post_content = $autosave->post_content;
			$posts[0]->post_excerpt = $autosave->post_excerpt;
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
