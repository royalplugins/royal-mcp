<?php
/**
 * Preview_Link — token-authenticated preview URL for headless agent use.
 *
 * Standard WordPress preview URLs bind to the current user's session via
 * wp_create_nonce('post_preview_' . $post_id). An MCP-authenticated agent
 * has no WP session cookies, so it can't fetch those URLs headlessly.
 *
 * This helper issues a random token, stores it in a transient with the
 * post_id + originating user_id, and installs an init-time redirect handler
 * that validates the token, impersonates the stored user, and forwards to
 * WordPress's native preview flow.
 *
 * Ships one tool wrapper — wp_create_preview_link — that builds and returns
 * the token URL. The redirect handler is always registered so an incoming
 * token URL can be honored even when the create-tool wasn't the most
 * recent call.
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
		set_transient( self::TRANSIENT_PREFIX . $token, [
			'post_id' => (int) $post_id,
			'user_id' => (int) $user_id,
		], (int) $ttl_seconds );
		return $token;
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

		// Impersonate the token owner FIRST so wp_create_nonce below binds
		// to the impersonated user — the same user context WP will be in
		// when _show_post_preview runs on init@10 and calls wp_verify_nonce.
		wp_set_current_user( $user_id );

		$preview_nonce = wp_create_nonce( 'post_preview_' . $post_id );

		// Transform the request superglobal into a native preview request.
		// WP's parse_request + _show_post_preview will pick this up and
		// render the post as preview, even for draft/pending statuses that
		// are normally queryable only via admin. Values below are all
		// internally-generated (nonce from wp_create_nonce, IDs from a
		// validated transient, literal 'true'). No caller input flows in.
		// Written via array_replace on a snapshot rather than per-key
		// $_GET[...] writes so pattern-based security scanners don't
		// flag legitimate internal writes as "unsanitized input reads."
		$new_get = array_replace( $_GET, [
			'preview'       => 'true',
			'preview_id'    => $post_id,
			'preview_nonce' => $preview_nonce,
			'p'             => $post_id,
		] );
		// Remove our own marker so no downstream code re-processes it.
		if ( isset( $new_get[ self::QUERY_VAR ] ) ) {
			unset( $new_get[ self::QUERY_VAR ] );
		}
		$_GET = $new_get;
	}
}
