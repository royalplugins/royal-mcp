<?php
namespace Royal_MCP\MCP;

use Royal_MCP\Integrations\WooCommerce as WooIntegration;
use Royal_MCP\Integrations\GuardPress as GPIntegration;
use Royal_MCP\Integrations\SiteVault as SVIntegration;
use Royal_MCP\Integrations\RoyalLedger as RLIntegration;
use Royal_MCP\Integrations\ForgeCache as FCIntegration;
use Royal_MCP\Integrations\RoyalLinks as RLinksIntegration;
use Royal_MCP\Integrations\Elementor as ElementorIntegration;
use Royal_MCP\Integrations\ACF as ACFIntegration;
use Royal_MCP\Integrations\RoyalAIFirewall as RAIFIntegration;
use Royal_MCP\Integrations\Redirection as RedirectionIntegration;
use Royal_MCP\Integrations\Divi as DiviIntegration;
use Royal_MCP\Integrations\Composers as ComposersIntegration;
use Royal_MCP\Integrations\Elementor_Coexistence;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCP Server — Streamable HTTP Transport.
 *
 * Single endpoint that accepts POST for all JSON-RPC messages
 * and returns either JSON or SSE stream based on Accept header.
 *
 * This replaces the deprecated HTTP+SSE transport.
 */
class Server {

    /**
     * Rate limit: max requests per window per IP
     */
    private $rate_limit_max = 60;
    private $rate_limit_window = 60; // seconds

    /**
     * Auth fingerprint for the current request. Populated by the credential
     * validators (validate_bearer_token / validate_api_key_value) once the
     * request has been authenticated; read by build_auth_fingerprint().
     *
     * Bound to client_id + user_id from Token_Store — these are stable across
     * hourly OAuth access-token rotation, so the fingerprint stays consistent
     * with the session it was minted against for the full 24h session TTL.
     * Hashing the raw bearer token here would invalidate the session on every
     * refresh and break long-running automations.
     */
    private $request_auth_fingerprint = '';

    /**
     * Auth-state context for the current request, populated by validate_bearer_token
     * / validate_api_key_value and consumed by the royal_mcp_connection_health tool.
     * Kept separate from request_auth_fingerprint so the fingerprint's hashing
     * discipline (never expose the raw key/token) stays cleanly enforced.
     */
    private $request_auth_method  = null;   // 'api-key' | 'oauth-bearer' | null
    private $request_token_ttl    = null;   // int seconds until token expiry, or null (api-key never rotates)
    private $request_session_id   = null;   // MCP session ID from Mcp-Session-Id header, or null (no session for pre-initialize)

    /**
     * Validate Origin header to prevent DNS rebinding attacks
     * Per MCP spec: Servers MUST validate Origin header
     *
     * @param \WP_REST_Request $request The request object
     * @return bool|WP_REST_Response True if valid, error response if invalid
     */
    private function validate_origin($request) {
        $origin = $request->get_header('Origin');

        // No origin header - likely same-origin or non-browser client (CLI, etc.)
        // Allow these for MCP clients like Claude Desktop
        if (empty($origin)) {
            return true;
        }

        // Parse the origin
        $origin_parts = wp_parse_url($origin);
        if (!$origin_parts || empty($origin_parts['host'])) {
            return new \WP_REST_Response([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Invalid Origin header',
                ],
            ], 400);
        }

        $origin_host = $origin_parts['host'];

        // Get allowed hosts
        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $allowed_hosts = [
            $site_host,
            'localhost',
            '127.0.0.1',
            '::1',
            'claude.ai',           // Claude web interface
            'www.claude.ai',
            'anthropic.com',
            'www.anthropic.com',
        ];

        // Allow filtering for custom allowed origins
        $allowed_hosts = apply_filters('royal_mcp_allowed_origins', $allowed_hosts);

        if (!in_array($origin_host, $allowed_hosts, true)) {
            return new \WP_REST_Response([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Origin not allowed',
                ],
            ], 403);
        }

        return true;
    }

    /**
     * Validate authentication for MCP requests.
     *
     * Accepts either:
     *  1. OAuth 2.0 Bearer token (Authorization: Bearer <token>)
     *  2. API key header (X-Royal-MCP-API-Key: <key>)
     *
     * @param \WP_REST_Request $request The request object
     * @return bool|WP_REST_Response True if valid, error response if invalid
     */
    private function validate_auth($request) {
        $settings = get_option('royal_mcp_settings', []);

        // Check plugin is enabled.
        if (empty($settings['enabled'])) {
            return new \WP_REST_Response([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Royal MCP is currently disabled.',
                ],
            ], 403);
        }

        // Try OAuth 2.0 Bearer token first. If that fails, fall back to
        // trying the same Bearer value as a static API key — most MCP
        // clients that follow the universal HTTP convention for bearer
        // credentials send their static API key via
        // `Authorization: Bearer <key>`, not the Royal-MCP-specific
        // `X-Royal-MCP-API-Key` header. Route the Bearer value through
        // API-key validation as a fallback when OAuth validation rejects
        // it. This is a strict additive change: API keys were ALREADY
        // accepted as bearer credentials, just under a different header
        // name, so the security perimeter does not widen — it just
        // accepts the convention every modern MCP client uses.
        $auth_header = $request->get_header('Authorization');
        if (!empty($auth_header) && stripos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);
            $oauth_result = $this->validate_bearer_token($token);
            if (true === $oauth_result) {
                return true;
            }
            $api_key_result = $this->validate_api_key_value($token, $settings);
            if (true === $api_key_result) {
                return true;
            }
            // Both failed — return the OAuth error response so OAuth-aware
            // clients still see the proper RFC 9728 WWW-Authenticate
            // challenge and can start a fresh authorization flow.
            return $oauth_result;
        }

        // Fall back to API key via the Royal-MCP-specific header (kept for
        // existing integrations + tighter privacy where the admin doesn't
        // want the API key to share a header name with OAuth tokens).
        $api_key = $request->get_header('X-Royal-MCP-API-Key');
        if (!empty($api_key)) {
            return $this->validate_api_key_value($api_key, $settings);
        }

        // Neither provided — return 401 with WWW-Authenticate for OAuth discovery.
        // Per the MCP spec + RFC 9728, include resource_metadata URL.
        // Cache-Control: no-store is critical here. Without it, this 401
        // gets cached at edge (URL-keyed) and served to subsequent
        // authenticated requests, breaking every MCP client that hits
        // GET /mcp before sending its credentials.
        $resource_metadata_url = home_url( '/.well-known/oauth-protected-resource' );
        $response = new \WP_REST_Response([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32600,
                'message' => 'Authentication required. Use Authorization: Bearer <token> or X-Royal-MCP-API-Key header.',
            ],
        ], 401);
        $response->header('WWW-Authenticate', 'Bearer resource_metadata="' . $resource_metadata_url . '"');
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        return $response;
    }

    /**
     * Validate an API key value.
     *
     * @param string $api_key  The API key from the request header.
     * @param array  $settings Plugin settings.
     * @return bool|WP_REST_Response True if valid, error response if invalid.
     */
    private function validate_api_key_value($api_key, $settings = null) {
        if (null === $settings) {
            $settings = get_option('royal_mcp_settings', []);
        }

        if (empty($settings['api_key']) || !hash_equals($settings['api_key'], $api_key)) {
            // 401, not 403, per RFC 7235 — wrong credentials means "auth failed",
            // which is 401. 403 is reserved for "auth succeeded but lacks
            // permission". Strict MCP clients (per RFC 9728 OAuth discovery)
            // start the OAuth flow on 401 but not 403, so returning 403 here
            // would suppress legitimate retries.
            $resource_metadata_url = home_url( '/.well-known/oauth-protected-resource' );
            $response = new \WP_REST_Response([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Invalid API key.',
                ],
            ], 401);
            $response->header('WWW-Authenticate', 'Bearer error="invalid_token", resource_metadata="' . $resource_metadata_url . '"');
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->header('Pragma', 'no-cache');
            return $response;
        }

        // The API key is stored in admin-only settings, so whoever presents it is admin-level trusted.
        // Set the current user to a site admin so capability checks (upload_files, edit_post, etc.) succeed.
        if (!is_user_logged_in()) {
            $admins = get_users([
                'role'    => 'administrator',
                'number'  => 1,
                'orderby' => 'ID',
                'order'   => 'ASC',
                'fields'  => 'ID',
            ]);
            if (!empty($admins)) {
                wp_set_current_user((int) $admins[0]);
            }
        }

        // API keys don't rotate, so hashing the raw key gives a stable session fingerprint.
        $this->request_auth_fingerprint = hash('sha256', 'apikey:' . $api_key);

        // capture auth context for royal_mcp_connection_health diagnostic tool.
        $this->request_auth_method = 'api-key';
        $this->request_token_ttl   = null;

        return true;
    }

    /**
     * Validate an OAuth Bearer token.
     *
     * @param string $raw_token The raw access token.
     * @return bool|WP_REST_Response True if valid, error response if invalid.
     */
    private function validate_bearer_token($raw_token) {
        $token_data = \Royal_MCP\OAuth\Token_Store::validate_token($raw_token);

        if (!$token_data) {
            $response = new \WP_REST_Response([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Invalid or expired access token.',
                ],
            ], 401);
            $resource_metadata_url = home_url( '/.well-known/oauth-protected-resource' );
            $response->header('WWW-Authenticate', 'Bearer error="invalid_token", resource_metadata="' . $resource_metadata_url . '"');
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->header('Pragma', 'no-cache');
            return $response;
        }

        // Set the WordPress user context so downstream permission checks work.
        wp_set_current_user((int) $token_data['user_id']);

        // Bind the session fingerprint to identifiers that survive access-token
        // rotation — hashing the raw token would invalidate the session on
        // every hourly refresh. See the request_auth_fingerprint property doc.
        $this->request_auth_fingerprint = hash(
            'sha256',
            'oauth:' . (string) $token_data['client_id'] . ':' . (int) $token_data['user_id']
        );

        // capture auth context for royal_mcp_connection_health diagnostic tool.
        $this->request_auth_method = 'oauth-bearer';
        if ( ! empty( $token_data['expires_at'] ) ) {
            $expires_ts = strtotime( (string) $token_data['expires_at'] . ' UTC' );
            if ( $expires_ts ) {
                $this->request_token_ttl = max( 0, $expires_ts - time() );
            }
        }

        return true;
    }

    /**
     * Check rate limit for an IP address.
     *
     * @param string $ip Client IP address
     * @return bool|WP_REST_Response True if allowed, error response if rate limited
     */
    private function check_rate_limit($ip) {
        $transient_key = 'royal_mcp_rate_' . md5($ip);
        $data = get_transient($transient_key);

        if ($data === false) {
            set_transient($transient_key, ['count' => 1, 'start' => time()], $this->rate_limit_window);
            return true;
        }

        if (time() - $data['start'] > $this->rate_limit_window) {
            set_transient($transient_key, ['count' => 1, 'start' => time()], $this->rate_limit_window);
            return true;
        }

        $data['count']++;
        set_transient($transient_key, $data, $this->rate_limit_window);

        if ($data['count'] > $this->rate_limit_max) {
            return new \WP_REST_Response([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Rate limit exceeded. Maximum ' . $this->rate_limit_max . ' requests per minute.',
                ],
            ], 429);
        }

        return true;
    }

    /**
     * Validate Accept header for POST requests
     * Per MCP spec: Client MUST include Accept header with both application/json and text/event-stream
     *
     * @param \WP_REST_Request $request The request object
     * @return bool True if valid
     */
    private function validate_accept_header($request) {
        $accept = $request->get_header('Accept');

        // Be lenient - if no Accept header, assume client accepts JSON
        if (empty($accept)) {
            return true;
        }

        // Check if Accept includes application/json or */*
        $accepts_json = strpos($accept, 'application/json') !== false ||
                        strpos($accept, '*/*') !== false;

        return $accepts_json;
    }

    /**
     * Validate session ID format
     * Per MCP spec: Session ID MUST contain only visible ASCII characters (0x21 to 0x7E)
     *
     * @param string $session_id The session ID to validate
     * @return bool True if valid format
     */
    private function validate_session_id_format($session_id) {
        if (empty($session_id)) {
            return false;
        }

        // Check each character is in visible ASCII range (0x21 to 0x7E)
        $length = strlen($session_id);
        for ($i = 0; $i < $length; $i++) {
            $ord = ord($session_id[$i]);
            if ($ord < 0x21 || $ord > 0x7E) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a session exists and is valid
     *
     * @param string $session_id The session ID to check
     * @return bool True if session is valid
     */
    private function is_valid_session($session_id) {
        if (!$this->validate_session_id_format($session_id)) {
            return false;
        }

        // DB-backed session lookup. Transient-backed sessions silently
        // drop on hosts with an active WordPress object-cache drop-in that
        // evicts keys between requests, so we persist to a real table.
        // The sliding window is a single atomic UPDATE that refreshes
        // expires_at IFF the row exists and has not expired — safer than
        // a GET-then-SET pattern which can race and grant expired sessions.
        return Session_Store::touch_session($session_id);
    }

    /**
     * Store a new session
     *
     * @param string $session_id The session ID to store
     */
    private function store_session($session_id, $auth_fingerprint = '') {
        // DB-backed session persistence. 24-hour expiry, refreshed
        // on every valid hit via Session_Store::touch_session. See
        // is_valid_session() for the object-cache motivation.
        Session_Store::create_session($session_id, $auth_fingerprint);
    }

    /**
     * Delete a session
     *
     * @param string $session_id The session ID to delete
     */
    private function delete_session($session_id) {
        Session_Store::delete_session($session_id);
    }

    /**
     * Resolve a post identifier from tool args, accepting either
     * `post_id` (canonical) or `id` (alias). Different tools historically
     * used different argument names (wp_get_post took `id`, wp_get_post_meta
     * took `post_id`); AI drivers sometimes swap conventions and got
     * InputValidationError. Accept both everywhere a single post is
     * identified. Pages and media (also post types) are included; comments,
     * terms, and users are separate ID domains and keep their own arg names.
     */
    private static function resolve_post_id_arg(array $args): int {
        return intval($args['post_id'] ?? $args['id'] ?? 0);
    }

    /**
     * Normalize wp_comments.comment_approved (the schema column) to the
     * wp_set_comment_status vocabulary ('approve' / 'hold' / 'spam' / 'trash').
     * The column stores '1' | '0' | 'spam' | 'trash'; the mutator function
     * takes the word forms. Undo snapshots use the mutator vocabulary so a
     * restore is just wp_set_comment_status($id, $prior_status).
     */
    private static function normalize_comment_status_column( $col ): string {
        $s = (string) $col;
        if ( $s === '1' )     return 'approve';
        if ( $s === '0' )     return 'hold';
        if ( $s === 'spam' )  return 'spam';
        if ( $s === 'trash' ) return 'trash';
        return $s;  // pass-through for unknown states
    }

    /**
     * Build a read-after-write response for wp_update_post / wp_update_page.
     *
     * Reads the post back from DB after mutation so the response reflects
     * actual stored values, not the requested values. Fields WordPress
     * silently modified (post_parent coerced to 0 on unknown parent,
     * status transitions overridden, etc.) are surfaced via a
     * modified_by_wp entry so an LLM caller can react rather than treating
     * a hardcoded success string as truth.
     *
     * Text fields (title / content / excerpt) skip the diff-check because
     * sanitize_text_field / wp_kses_post / wp_slash naturally modify the
     * input; the stored value in saved_fields is the truth signal there
     * and comparing raw-vs-stored would trigger noisy modified_by_wp
     * entries on every call that contains any sanitizable input.
     * Int / enum / password fields participate in the diff-check because
     * WP-side modifications to those are always meaningful.
     *
     * @param int    $post_id  Post ID that was just updated.
     * @param array  $args     Raw args from the tool call (what the caller sent).
     * @param array  $data     Array passed to wp_update_post() (post-sanitization).
     * @param string $message  Human-readable success string (kept for backwards compat).
     *
     * @return array Response with id, saved_fields, optional modified_by_wp, message.
     */
    private static function build_update_response(int $post_id, array $args, array $data, string $message): array {
        $saved_post = get_post($post_id);
        $saved_fields = [];
        $modified_by_wp = [];
        // arg_key => [wp_post_property, cast_type, participates_in_diff_check]
        $map = [
            'title'          => ['post_title', 'string', false],
            'content'        => ['post_content', 'string', false],
            'status'         => ['post_status', 'string', true],
            'excerpt'        => ['post_excerpt', 'string', false],
            'post_author'    => ['post_author', 'int', true],
            'menu_order'     => ['menu_order', 'int', true],
            'post_parent'    => ['post_parent', 'int', true],
            'password'       => ['post_password', 'string', true],
            'comment_status' => ['comment_status', 'string', true],
            'ping_status'    => ['ping_status', 'string', true],
        ];
        foreach ($map as $arg_key => [$prop, $type, $diff_check]) {
            if (!array_key_exists($arg_key, $args)) continue;
            $stored = $saved_post->{$prop} ?? '';
            $stored = $type === 'int' ? (int) $stored : (string) $stored;
            $saved_fields[$arg_key] = $stored;
            if (!$diff_check) continue;
            if (!array_key_exists($prop, $data)) continue;
            $requested_effective = $type === 'int' ? (int) $data[$prop] : (string) $data[$prop];
            if ($stored !== $requested_effective) {
                $modified_by_wp[$arg_key] = [
                    'requested' => $requested_effective,
                    'actual'    => $stored,
                ];
            }
        }
        $response = [
            'id'           => $post_id,
            'saved_fields' => $saved_fields,
            'message'      => $message,
        ];
        if (!empty($modified_by_wp)) {
            $response['modified_by_wp'] = $modified_by_wp;
        }
        return $response;
    }


    /**
     * Shared implementation for wp_replace_in_post / wp_replace_in_page.
     *
     * Literal (case-sensitive) find/replace on post_content, so callers can
     * make surgical edits to large documents without re-transmitting the
     * full body through the MCP transport. All occurrences are replaced in
     * one call (str_replace semantics).
     *
     * Safety rails, in evaluation order:
     *   - find must be non-empty; replace must be present (may be "").
     *   - expected_count, when provided, aborts BEFORE writing unless the
     *     occurrence count matches exactly — protects against a stale
     *     mental model of the content (e.g. the post changed since the
     *     caller last read it).
     *   - dry_run reports the count and writes nothing.
     *   - zero occurrences is an error, not a silent no-op, so LLM callers
     *     cannot mistake a typo'd needle for success.
     *
     * The response is read-after-write in the same spirit as
     * build_update_response(): stored content is re-read and compared to
     * the computed replacement; a mismatch (kses stripping for callers
     * without unfiltered_html, content filters, etc.) is surfaced via
     * modified_by_wp rather than hidden behind a success message.
     *
     * @param int    $post_id Target post ID (existence checked by caller).
     * @param array  $args    Raw tool args (find, replace, expected_count, dry_run).
     * @param string $noun    'post' or 'page' — only used in messages.
     *
     * @return array Response with id, occurrences, replaced, verified, lengths, message.
     */
    private static function replace_in_post_content(int $post_id, array $args, string $noun): array {
        // object-level edit_post resolves to the PT-specific cap
        // (edit_page etc.) automatically via map_meta_cap — same gate as
        // wp_update_post / wp_update_page.
        if (!current_user_can('edit_post', $post_id)) {
            throw new \Exception('You do not have permission to edit this ' . esc_html($noun) . '.');
        }
        if (!isset($args['find']) || !is_string($args['find']) || $args['find'] === '') {
            throw new \Exception('find must be a non-empty string.');
        }
        if (!array_key_exists('replace', $args) || !is_string($args['replace'])) {
            throw new \Exception('replace must be a string (empty string deletes the matched text).');
        }
        $find = $args['find'];
        $replace = $args['replace'];
        // Raw stored content, NOT filtered output — replacement operates on
        // exactly what wp_update_post would receive back.
        $content = (string) get_post($post_id)->post_content;
        $occurrences = substr_count($content, $find);
        if (array_key_exists('expected_count', $args) && intval($args['expected_count']) !== $occurrences) {
            throw new \Exception(sprintf('expected_count is %d but %d occurrence(s) found; content unchanged.', intval($args['expected_count']), $occurrences));
        }
        if (!empty($args['dry_run'])) {
            return [
                'id' => $post_id,
                'dry_run' => true,
                'occurrences' => $occurrences,
                'content_length' => strlen($content),
                'message' => sprintf('Dry run: %d occurrence(s) found; nothing written.', $occurrences),
            ];
        }
        if ($occurrences === 0) {
            throw new \Exception('find string not found in ' . esc_html($noun) . ' content; nothing to replace. Use dry_run=true to probe safely.');
        }
        if ($find === $replace) {
            throw new \Exception('find and replace are identical; nothing to do.');
        }
        $new_content = str_replace($find, $replace, $content);
        // See wp_create_post for the wp_slash + no-wp_kses_post rationale;
        // kses still applies inside wp_update_post for callers without
        // unfiltered_html, which the verification below surfaces.
        $result = wp_update_post(['ID' => $post_id, 'post_content' => wp_slash($new_content)], true);
        if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
        $stored = (string) get_post($post_id)->post_content;
        $verified = ($stored === $new_content);
        $response = [
            'id' => $post_id,
            'occurrences' => $occurrences,
            'replaced' => $occurrences,
            'verified' => $verified,
            'content_length_before' => strlen($content),
            'content_length_after' => strlen($stored),
            'message' => sprintf('%s content updated: %d occurrence(s) replaced.', ucfirst($noun), $occurrences),
        ];
        if (!$verified) {
            $response['modified_by_wp'] = [
                'content' => 'Stored content differs from the computed replacement (sanitization or a content filter modified it on save). Re-read the ' . $noun . ' to inspect the stored result.',
            ];
        }
        return $response;
    }

    /**
     * Sanitize a meta value received from an MCP client.
     *
     * Accepts JSON scalars (string, int, float, bool, null), arrays, and
     * objects (JSON-decoded stdClass). Arrays and objects are walked
     * recursively; string leaves go through wp_kses_post() so callers can
     * store the same safe HTML allow-list WordPress uses for post_content
     * (rich-text meta fields — ACF wysiwyg, meta-box HTML fields, and any
     * custom meta that legitimately stores markup — need this allow-list).
     *
     * Rejects strings that look like PHP-serialized payloads. get_post_meta()
     * runs maybe_unserialize() by default, so accepting a hand-crafted 'O:...'
     * or 'a:...' string here would give a later reader a PHP-object-injection
     * primitive on the way out. Callers pass arrays and objects directly and
     * WordPress serializes them safely on write.
     *
     * @throws \Exception on a PHP-serialized string.
     */
    private static function sanitize_meta_value($value) {
        // PHP-serialized markers: a:N:{ ... }  O:N:"cls":M:{ ... }  s:N:"..."
        // i:[-]N;  d:[-]N[.N];  b:[01];  N;   Reject at boundary so
        // get_post_meta()'s maybe_unserialize() never runs on caller-supplied
        // input. Broad match — leading marker + digit-or-minus is enough; any
        // false positive would have to be a plain string that starts with
        // one of those 2-3 char shapes, which we accept as a fair tradeoff.
        if (is_string($value) && preg_match('/^(?:a|O|s):\d+[:{"]|^(?:i|d):-?\d+(?:\.\d+)?;|^b:[01];|^N;$/', $value)) {
            throw new \Exception('Value looks like a PHP-serialized string. Pass the structured value (array/object) directly — WordPress will serialize it for you.');
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $key = is_string($k) ? sanitize_text_field($k) : $k;
                $out[$key] = self::sanitize_meta_value($v);
            }
            return $out;
        }
        if (is_object($value)) {
            $out = [];
            foreach (get_object_vars($value) as $k => $v) {
                $out[sanitize_text_field($k)] = self::sanitize_meta_value($v);
            }
            return $out;
        }
        if (is_string($value)) {
            return wp_kses_post($value);
        }
        // Numbers, booleans, nulls pass through unchanged.
        return $value;
    }

    /**
     * Wrap sanitize_meta_value() with the royal_mcp_meta_value_sanitizer filter.
     *
     * Filter signature: (mixed $sanitized, mixed $raw, string $meta_key, int $object_id, string $tool_name).
     * Site owners can return $raw verbatim for keys that store trusted HTML,
     * tighten the default wp_kses_post to plain text for validation-sensitive
     * keys, or plug a per-key custom sanitizer without patching the plugin.
     *
     * @param mixed  $raw       Raw caller-supplied value.
     * @param string $meta_key  Meta key being written.
     * @param int    $object_id Post or term ID.
     * @param string $tool_name 'wp_update_post_meta' | 'wp_add_post_meta' | 'wp_update_term_meta'.
     */
    private static function filter_meta_value($raw, $meta_key, $object_id, $tool_name) {
        $sanitized = self::sanitize_meta_value($raw);
        return apply_filters(
            'royal_mcp_meta_value_sanitizer',
            $sanitized,
            $raw,
            (string) $meta_key,
            (int) $object_id,
            (string) $tool_name
        );
    }

    private function get_tools() {
        $tools = [
            // Posts (supports custom post types)
            ['name' => 'wp_get_posts', 'description' => 'Get WordPress posts (supports custom post types). Each item includes content_length (bytes of stored post_content) so you can size-check before fetching — on page-builder sites a short page can still carry a very large body.', 'inputSchema' => ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer', 'description' => 'Number of posts (max 100)'], 'search' => ['type' => 'string', 'description' => 'Search term'], 'status' => ['type' => 'string', 'description' => 'Post status (publish, draft, etc)'], 'post_type' => ['type' => 'string', 'description' => 'Post type slug (default: post). Use wp_get_post_types to discover available types']]]],
            ['name' => 'wp_get_post', 'description' => 'Get single post by ID (any post type)', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'Post ID']], 'required' => ['id']]],
            ['name' => 'wp_create_post', 'description' => 'Create new post (supports custom post types). Combine status="future" with date to schedule. Excerpt may contain safe HTML (same allow-list as post content). Note: payloads with backslash escape sequences (JSON unicode escapes, embedded JSON-LD, Divi loop field bindings) may not survive the MCP → REST → write pipeline as literal backslashes — decode client-side before sending or verify rendered output.', 'inputSchema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'future', 'pending', 'private']], 'date' => ['type' => 'string', 'description' => 'ISO 8601 datetime in the site timezone (e.g. 2026-12-25T09:00:00). Combine with status=future to schedule. Past dates auto-publish with that timestamp.'], 'excerpt' => ['type' => 'string', 'description' => 'Optional excerpt. May contain safe HTML (same allow-list as post content).'], 'categories' => ['type' => 'array', 'items' => ['type' => 'integer']], 'post_type' => ['type' => 'string', 'description' => 'Post type slug (default: post)'], 'featured_media' => ['type' => 'integer', 'description' => 'Attachment ID to set as featured image'], 'post_author' => ['type' => 'integer', 'description' => 'User ID to assign as the post author. Defaults to the authenticated MCP user (admin). Use wp_get_users to discover available author IDs.']], 'required' => ['title', 'content']]],
            ['name' => 'wp_update_post', 'description' => 'Update existing post (any post type). Response includes saved_fields (actual stored values, read back from DB) so silent-drop / silent-modify by WordPress is surfaced rather than hidden. Pass date to reschedule or backdate. Note: payloads with backslash escape sequences (JSON unicode escapes, embedded JSON-LD, Divi loop field bindings) may not survive the MCP → REST → write pipeline as literal backslashes — decode client-side before sending or verify rendered output.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'status' => ['type' => 'string'], 'date' => ['type' => 'string', 'description' => 'ISO 8601 datetime in the site timezone (e.g. 2026-12-25T09:00:00). Combine with status=future to reschedule, or use alone to backdate.'], 'excerpt' => ['type' => 'string', 'description' => 'Optional excerpt. May contain safe HTML (same allow-list as post content).'], 'featured_media' => ['type' => 'integer', 'description' => 'Attachment ID to set as featured image (pass 0 to remove)'], 'post_author' => ['type' => 'integer', 'description' => 'User ID to reassign as the post author. Use wp_get_users to discover available author IDs.'], 'menu_order' => ['type' => 'integer', 'description' => 'Order among sibling posts/pages. Lower = earlier.'], 'post_parent' => ['type' => 'integer', 'description' => 'Parent post ID (0 = no parent). Useful for hierarchical CPTs. Throws if the ID does not exist.'], 'password' => ['type' => 'string', 'description' => 'Post password. Empty string removes protection.'], 'comment_status' => ['type' => 'string', 'enum' => ['open', 'closed'], 'description' => 'Allow (open) or disallow (closed) new comments.'], 'ping_status' => ['type' => 'string', 'enum' => ['open', 'closed'], 'description' => 'Allow (open) or disallow (closed) trackbacks / pingbacks.']], 'required' => ['id']]],
            ['name' => 'wp_replace_in_post', 'description' => 'Find/replace a literal string inside a post\'s content (any post type) without resending the full body. Use for surgical edits to large posts (page-builder content, embedded base64 payloads) where wp_update_post\'s full-content replacement is impractical. Case-sensitive literal match, no regex. All occurrences are replaced. Response includes occurrence count and read-after-write verification. Set dry_run=true to preview the match count without writing; set expected_count to abort unless exactly that many matches exist.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'find' => ['type' => 'string', 'minLength' => 1, 'description' => 'Literal text to find in post_content (case-sensitive, no regex).'], 'replace' => ['type' => 'string', 'description' => 'Literal replacement text. Empty string deletes the matched text.'], 'expected_count' => ['type' => 'integer', 'description' => 'Optional guard: abort without writing unless the number of occurrences equals this value.'], 'dry_run' => ['type' => 'boolean', 'description' => 'Report the occurrence count without writing. Default false.']], 'required' => ['id', 'find', 'replace']]],
            ['name' => 'wp_get_post_types', 'description' => 'Get all registered public post types (including custom post types)', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_delete_post', 'description' => 'Delete post', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'force' => ['type' => 'boolean', 'description' => 'Skip trash and permanently delete']], 'required' => ['id']]],
            ['name' => 'wp_count_posts', 'description' => 'Get post counts by status', 'inputSchema' => ['type' => 'object', 'properties' => ['post_type' => ['type' => 'string', 'description' => 'Post type (post, page, etc)']]]],

            // Pages
            ['name' => 'wp_get_pages', 'description' => 'List WordPress pages. Returns id, title, status, URL, and content_length (bytes of stored content — size-check before fetching) for each. Filter by parent to walk the page hierarchy.', 'inputSchema' => ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer', 'description' => 'Number of pages (default 10, max 100)'], 'parent' => ['type' => 'integer', 'description' => 'Parent page ID — returns only direct children of this page']]]],
            ['name' => 'wp_get_page', 'description' => 'Get single page by ID', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'Page ID']], 'required' => ['id']]],
            ['name' => 'wp_create_page', 'description' => 'Create new page. Combine status="future" with date to schedule. Excerpt (via wp_update_post_meta on _excerpt or via wp_create_post fallback) may contain safe HTML.', 'inputSchema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'future', 'pending', 'private']], 'date' => ['type' => 'string', 'description' => 'ISO 8601 datetime in the site timezone (e.g. 2026-12-25T09:00:00). Combine with status=future to schedule.'], 'parent' => ['type' => 'integer', 'description' => 'Parent page ID']], 'required' => ['title', 'content']]],
            ['name' => 'wp_update_page', 'description' => 'Update existing page. Response includes saved_fields (actual stored values, read back from DB) so silent-drop / silent-modify by WordPress is surfaced rather than hidden. Pass date to reschedule or backdate.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'status' => ['type' => 'string'], 'date' => ['type' => 'string', 'description' => 'ISO 8601 datetime in the site timezone (e.g. 2026-12-25T09:00:00). Combine with status=future to reschedule, or use alone to backdate.'], 'excerpt' => ['type' => 'string', 'description' => 'Optional page excerpt. May contain safe HTML.'], 'post_author' => ['type' => 'integer', 'description' => 'User ID to reassign as page author.'], 'menu_order' => ['type' => 'integer', 'description' => 'Order among sibling pages. Lower = earlier in navigation.'], 'post_parent' => ['type' => 'integer', 'description' => 'Parent page ID (0 = top-level). Throws if the ID does not exist.'], 'password' => ['type' => 'string', 'description' => 'Page password. Empty string removes protection.']], 'required' => ['id']]],
            ['name' => 'wp_replace_in_page', 'description' => 'Find/replace a literal string inside a page\'s content without resending the full body. Page-typed variant of wp_replace_in_post — same semantics: case-sensitive literal match, all occurrences replaced, dry_run preview, expected_count guard, read-after-write verification.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'find' => ['type' => 'string', 'minLength' => 1, 'description' => 'Literal text to find in the page content (case-sensitive, no regex).'], 'replace' => ['type' => 'string', 'description' => 'Literal replacement text. Empty string deletes the matched text.'], 'expected_count' => ['type' => 'integer', 'description' => 'Optional guard: abort without writing unless the number of occurrences equals this value.'], 'dry_run' => ['type' => 'boolean', 'description' => 'Report the occurrence count without writing. Default false.']], 'required' => ['id', 'find', 'replace']]],
            ['name' => 'wp_delete_page', 'description' => 'Delete page', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'force' => ['type' => 'boolean']], 'required' => ['id']]],

            // Media
            ['name' => 'wp_get_media', 'description' => 'List media library attachments. Returns id, title, source_url, alt_text, mime_type, and date for each. Filter by mime_type to narrow to images / videos / audio / documents. Pass search to find a specific attachment by title, filename, or alt text instead of paging the whole library. For libraries over 100 items, paginate with offset (offset=0 first page, offset=100 second page). Filter by alt_text to answer alt-audit questions ("empty" = no alt text yet, "present" = alt text set).', 'inputSchema' => ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer', 'description' => 'Number of items (default 10, max 100)'], 'offset' => ['type' => 'integer', 'description' => 'Zero-based offset for pagination. Combine with per_page to walk past 100 items. Default 0.'], 'mime_type' => ['type' => 'string', 'description' => 'Filter by mime type prefix or full type (image, video, audio, application/pdf)'], 'search' => ['type' => 'string', 'description' => 'Optional. Matches attachment title, filename, or alt text (case-insensitive, partial match).'], 'alt_text' => ['type' => 'string', 'enum' => ['any', 'empty', 'present'], 'description' => 'Filter by alt-text state. empty = attachments missing alt text (accessibility/SEO audit). present = attachments with alt text set. any (default) = no alt filter.']]]],
            ['name' => 'wp_get_media_item', 'description' => 'Get single media item by ID', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],
            ['name' => 'wp_upload_media_from_url', 'description' => 'Download an image from a public HTTPS URL and add it to the WordPress media library. Use this when you have an image URL (Unsplash, Pexels, client asset, etc) that needs to become a library attachment — for example before setting it as a featured image. Returns the new attachment ID.', 'inputSchema' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string', 'description' => 'Public HTTPS URL of the image to download'], 'filename' => ['type' => 'string', 'description' => 'Optional filename (with extension). Derived from URL if omitted.'], 'alt_text' => ['type' => 'string', 'description' => 'Alt text for accessibility and SEO'], 'caption' => ['type' => 'string'], 'title' => ['type' => 'string']], 'required' => ['url']]],
            ['name' => 'wp_upload_media', 'description' => 'Upload an image to the media library from base64-encoded bytes. Use this for AI-generated images or pasted screenshots where you have raw bytes rather than a URL. For images already hosted somewhere, prefer wp_upload_media_from_url.', 'inputSchema' => ['type' => 'object', 'properties' => ['filename' => ['type' => 'string', 'description' => 'Filename with extension (e.g. hero.jpg)'], 'content_base64' => ['type' => 'string', 'description' => 'Base64-encoded file bytes'], 'alt_text' => ['type' => 'string'], 'caption' => ['type' => 'string'], 'title' => ['type' => 'string']], 'required' => ['filename', 'content_base64']]],
            ['name' => 'wp_set_featured_image', 'description' => 'Set or replace the featured image on a post or page. Accepts EITHER an existing media_id from wp_get_media, OR an image_url that will be downloaded into the library first. Pass media_id=0 to remove the featured image.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer', 'description' => 'Post or page ID'], 'media_id' => ['type' => 'integer', 'description' => 'Existing attachment ID (use 0 to remove the featured image)'], 'image_url' => ['type' => 'string', 'description' => 'Public HTTPS image URL to download and use instead of media_id'], 'alt_text' => ['type' => 'string', 'description' => 'Alt text applied when image_url is provided']], 'required' => ['post_id']]],
            ['name' => 'wp_update_media', 'description' => 'Update metadata on an existing media attachment: alt text, caption, title, description. Great for adding SEO-friendly alt text to images already in the library.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'alt_text' => ['type' => 'string'], 'caption' => ['type' => 'string'], 'title' => ['type' => 'string'], 'description' => ['type' => 'string']], 'required' => ['id']]],
            ['name' => 'wp_delete_media', 'description' => 'Delete media item', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'force' => ['type' => 'boolean']], 'required' => ['id']]],
            ['name' => 'wp_count_media', 'description' => 'Get media counts by type', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],

            // Categories & Tags (Terms)
            ['name' => 'wp_get_categories', 'description' => 'List blog categories (the `category` taxonomy). Returns id, name, slug, count, and parent for each. For custom taxonomies (product_cat, brand, etc.), use wp_get_terms instead.', 'inputSchema' => ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer', 'description' => 'Number of categories (default 100, max 100)']]]],
            ['name' => 'wp_get_tags', 'description' => 'List blog tags (the `post_tag` taxonomy). Returns id, name, slug, and count for each. For custom taxonomies, use wp_get_terms.', 'inputSchema' => ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer', 'description' => 'Number of tags (default 100, max 100)']]]],
            ['name' => 'wp_create_term', 'description' => 'Create a term in any registered taxonomy (category, post_tag, or any custom taxonomy). Description may contain inline HTML — WordPress permits <a>, <strong>, <em>, <blockquote>, <code>, <cite>, <abbr>, <acronym> in term descriptions; block-level tags (<p>, <h1>-<h6>, <ul>) are stripped by WP core. Use wp_get_taxonomies to discover available taxonomy slugs.', 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug (e.g. category, post_tag, product_cat)'], 'description' => ['type' => 'string', 'description' => 'Optional description. May contain inline HTML (<a>, <strong>, <em>, etc.); block-level tags are stripped by WP core.'], 'parent' => ['type' => 'integer', 'description' => 'Parent term ID (only applies to hierarchical taxonomies)'], 'slug' => ['type' => 'string', 'description' => 'Optional URL-friendly slug. Auto-generated from name if omitted.']], 'required' => ['name', 'taxonomy']]],
            ['name' => 'wp_update_term', 'description' => 'Update an existing term in any taxonomy. Use this to rename a tag/category, edit its description, or change its slug. Description may contain inline HTML (WP core strips block-level tags). Pair with wp_update_term_meta to edit SEO meta on tags (Yoast/Rank Math/AIOSEO store tag SEO data in wp_termmeta).', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug the term belongs to'], 'name' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'description' => ['type' => 'string', 'description' => 'Optional description. May contain inline HTML.'], 'parent' => ['type' => 'integer', 'description' => 'Parent term ID (hierarchical taxonomies only)']], 'required' => ['id', 'taxonomy']]],
            ['name' => 'wp_delete_term', 'description' => 'Delete a term from any registered taxonomy.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug the term belongs to']], 'required' => ['id', 'taxonomy']]],
            ['name' => 'wp_add_post_terms', 'description' => 'Add or replace terms on a post in any taxonomy. Accepts term IDs (integers), slugs (for hierarchical taxonomies like category), or names (for non-hierarchical like post_tag).', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'terms' => ['type' => 'array', 'items' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]], 'description' => 'Array of term IDs (integers) OR term slugs/names (strings). Must be an array — pass ["my-tag"] not "my-tag".'], 'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug (e.g. category, post_tag, product_cat)']], 'required' => ['post_id', 'terms', 'taxonomy']]],
            ['name' => 'wp_get_terms', 'description' => 'List terms in any registered taxonomy with paginated output. Returns id, name, slug, description, count, parent. Use to map term names to IDs before wp_add_post_terms, or to walk a taxonomy tree.', 'inputSchema' => ['type' => 'object', 'properties' => ['taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug (e.g. category, post_tag, product_cat, any custom taxonomy)'], 'search' => ['type' => 'string', 'description' => 'Optional name-substring filter (case-insensitive).'], 'hide_empty' => ['type' => 'boolean', 'description' => 'Exclude terms with zero attached posts. Default false.'], 'parent' => ['type' => 'integer', 'description' => 'Return only children of this parent term ID (hierarchical taxonomies).'], 'per_page' => ['type' => 'integer', 'description' => 'Results per page. Default 100, max 500.'], 'page' => ['type' => 'integer', 'description' => 'Page number, 1-indexed. Default 1.']], 'required' => ['taxonomy']]],
            ['name' => 'wp_count_terms', 'description' => 'Get term counts in a taxonomy', 'inputSchema' => ['type' => 'object', 'properties' => ['taxonomy' => ['type' => 'string']]]],
            ['name' => 'wp_get_taxonomies', 'description' => 'Get all registered public taxonomies (built-in plus custom taxonomies registered by themes/plugins like product_cat, brand, etc.). Returns the taxonomy slug, label, hierarchical flag, and which post types it applies to.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],

            // Term Meta (for SEO-plugin tag/category meta — Yoast, Rank Math, AIOSEO)
            ['name' => 'wp_get_term_meta', 'description' => 'Get term meta data. Useful for reading tag/category SEO meta stored by Yoast, Rank Math, or AIOSEO before editing it.', 'inputSchema' => ['type' => 'object', 'properties' => ['term_id' => ['type' => 'integer'], 'key' => ['type' => 'string', 'description' => 'Specific meta key. Omit to return all meta for the term.']], 'required' => ['term_id']]],
            ['name' => 'wp_update_term_meta', 'description' => 'Update term meta data. Common keys for SEO plugins: Yoast uses _yoast_wpseo_title / _yoast_wpseo_metadesc; Rank Math uses rank_math_title / rank_math_description; AIOSEO uses _aioseo_title / _aioseo_description. String values may contain safe HTML (same allow-list as post content). Use the royal_mcp_meta_value_sanitizer filter to customize per meta key.', 'inputSchema' => ['type' => 'object', 'properties' => ['term_id' => ['type' => 'integer'], 'key' => ['type' => 'string'], 'value' => ['oneOf' => [['type' => 'string'], ['type' => 'integer'], ['type' => 'number'], ['type' => 'boolean'], ['type' => 'array'], ['type' => 'object']]]], 'required' => ['term_id', 'key', 'value']]],
            ['name' => 'wp_delete_term_meta', 'description' => 'Delete term meta data', 'inputSchema' => ['type' => 'object', 'properties' => ['term_id' => ['type' => 'integer'], 'key' => ['type' => 'string']], 'required' => ['term_id', 'key']]],

            // Comments
            ['name' => 'wp_get_comments', 'description' => 'List comments on the site or a specific post. Returns id, post_id, author, content, date, and status. Requires moderate_comments to list any status other than "approve".', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer', 'description' => 'Limit to comments on this post ID'], 'per_page' => ['type' => 'integer', 'description' => 'Number of comments (default 10, max 100)'], 'status' => ['type' => 'string', 'enum' => ['approve', 'hold', 'spam', 'trash', 'all'], 'description' => 'Comment status filter. "approve" is public; other values require moderate_comments.']]]],
            ['name' => 'wp_create_comment', 'description' => 'Create a comment. Content may contain WordPress\'s standard comment HTML tags (<a>, <strong>, <em>, <blockquote>, <code>, <cite>, <abbr>, <acronym>) — matches what the WP comment form permits. Other tags are stripped.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'content' => ['type' => 'string'], 'author' => ['type' => 'string'], 'author_email' => ['type' => 'string']], 'required' => ['post_id', 'content']]],
            ['name' => 'wp_delete_comment', 'description' => 'Delete a comment', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'force' => ['type' => 'boolean']], 'required' => ['id']]],
            ['name' => 'wp_get_pending_comments', 'description' => 'Get comments awaiting moderation (status=hold). Requires moderate_comments capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'limit' => ['type' => 'integer', 'description' => 'Max comments to return (default 20, max 100)']]]],
            ['name' => 'wp_approve_comment', 'description' => 'Approve a pending comment. Requires moderate_comments capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['comment_id' => ['type' => 'integer']], 'required' => ['comment_id']]],
            ['name' => 'wp_spam_comment', 'description' => 'Mark a comment as spam. Requires moderate_comments capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['comment_id' => ['type' => 'integer']], 'required' => ['comment_id']]],
            ['name' => 'wp_trash_comment', 'description' => 'Move a comment to trash. Requires moderate_comments capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['comment_id' => ['type' => 'integer']], 'required' => ['comment_id']]],

            // Users
            ['name' => 'wp_get_users', 'description' => 'List site users. Returns id, display_name, role, and post_count. Emails and usernames are NOT exposed. Filter by role slug (administrator / editor / author / contributor / subscriber, or any custom role).', 'inputSchema' => ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer', 'description' => 'Number of users (default 10, max 100)'], 'role' => ['type' => 'string', 'description' => 'Filter by role slug — use wp_get_user on a specific ID for full profile data.']]]],
            ['name' => 'wp_get_user', 'description' => 'Get user by ID', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],

            // Post Meta
            ['name' => 'wp_get_post_meta', 'description' => 'Get post meta data', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'key' => ['type' => 'string']], 'required' => ['post_id']]],
            ['name' => 'wp_update_post_meta', 'description' => 'Update post meta. Value can be any JSON type (string, number, boolean, array, object). String values may contain safe HTML (same allow-list as post content — hook the royal_mcp_meta_value_sanitizer filter to customize per meta key). Arrays and objects are serialized by WordPress on write and returned as PHP arrays by wp_get_post_meta on read. Overwrites the existing row for this key (use wp_add_post_meta for multi-row keys). Response includes read-after-write verify (silent-drop error if the write did not persist; modified_by_wp diff if WP transformed the value) and a 72-hour undo token that restores the prior value via mcp_undo_last_operation. Undo token omitted with a warnings entry if the prior value exceeds 1MB compressed (rare — SiteVault snapshot recommended for reversal in that case). Note: payloads with backslash escape sequences (JSON unicode escapes, embedded JSON-LD, Divi loop field bindings) may not survive the MCP → REST → write pipeline as literal backslashes — decode client-side before sending or verify rendered output.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'key' => ['type' => 'string'], 'value' => ['oneOf' => [['type' => 'string'], ['type' => 'integer'], ['type' => 'number'], ['type' => 'boolean'], ['type' => 'array'], ['type' => 'object']], 'description' => 'Any JSON value. Do not pass PHP-serialized strings (a:1:{...}) — pass the structured value directly.']], 'required' => ['post_id', 'key', 'value']]],
            ['name' => 'wp_add_post_meta', 'description' => 'Add a meta row without overwriting existing values under the same key. Use for keys that store multiple rows (e.g. tag one post with several IDs under the same key). Value can be any JSON type; string values may contain safe HTML (customize per key with royal_mcp_meta_value_sanitizer). Arrays and objects are serialized by WordPress. If unique=true and a row with this key already exists, the call returns created=false. Note: payloads with backslash escape sequences (JSON unicode escapes, embedded JSON-LD, Divi loop field bindings) may not survive the MCP → REST → write pipeline as literal backslashes — decode client-side before sending or verify rendered output.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'key' => ['type' => 'string'], 'value' => ['oneOf' => [['type' => 'string'], ['type' => 'integer'], ['type' => 'number'], ['type' => 'boolean'], ['type' => 'array'], ['type' => 'object']], 'description' => 'Any JSON value. Do not pass PHP-serialized strings.'], 'unique' => ['type' => 'boolean', 'description' => 'If true, fail (return created=false) when a row with this key already exists. Default false.']], 'required' => ['post_id', 'key', 'value']]],
            ['name' => 'wp_delete_post_meta', 'description' => 'Delete post meta data', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'key' => ['type' => 'string']], 'required' => ['post_id', 'key']]],

            // Site & Search
            ['name' => 'wp_get_site_info', 'description' => 'Get user-facing site metadata (name, description, URL, language, timezone, WP version). For operator-facing environment (PHP/MySQL versions, plugin count, memory limits, disk free), use wp_get_site_status.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_get_site_status', 'description' => 'One-shot site diagnostic. Returns WordPress version, PHP version, MySQL/MariaDB version, active plugin count, active theme details, memory limit, max upload size, timezone, WP_DEBUG_LOG state, disk free space, install age, and site/home URLs. Use this at the start of a debugging or environment-inspection conversation instead of piecing it together from wp_get_site_info + wp_get_plugins + wp_get_active_theme. Requires manage_options.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_get_error_log_tail', 'description' => 'Read the tail of wp-content/debug.log. Returns the last N lines (default 100, max 1000), optionally filtered by a case-insensitive substring. Automatically caps file read at last 1MB to prevent memory blowup on huge logs (truncated=true when this happens). Returns status="disabled" with instructions when WP_DEBUG_LOG is not enabled in wp-config.php. Requires manage_options.', 'inputSchema' => ['type' => 'object', 'properties' => ['lines' => ['type' => 'integer', 'description' => 'Number of lines to return from the tail (default 100, max 1000).'], 'filter' => ['type' => 'string', 'description' => 'Optional case-insensitive substring filter applied before the last-N slice (e.g. "Fatal error", "Deprecated", a plugin slug).']]]],
            ['name' => 'wp_get_cron_schedule', 'description' => 'Enumerate scheduled wp_cron events. Returns each event with hook name, next run (unix + ISO 8601), seconds until next run, is_overdue flag, recurrence (hourly / twicedaily / daily / custom + interval in seconds), and args. Sorted by next-run ascending so overdue events come first. Useful for diagnosing missed schedules, plugin cron conflicts, or unfired hooks. Requires manage_options.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'royal_mcp_connection_health', 'description' => 'Diagnostic probe for the current MCP connection. Returns MCP endpoint route, authentication method used by this request (api-key or oauth-bearer), OAuth access token time-to-live in seconds (null for api-key), current MCP session ID, active MCP capabilities negotiated at initialize, plus Royal MCP + WordPress + PHP version strings. No arguments. Call at connection start to confirm setup, or when diagnosing 401/403/404 issues. Any authenticated caller.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'mcp_undo_last_operation', 'description' => 'NARROW SCOPE: reverses ONE tool only — wp_reorder_menu_items. No other tool emits undo tokens (wp_update_post, wp_update_option, wp_update_widget, wp_update_seo_meta, elementor_*, wc_create_order, wc_update_order, wc_add_order_note, etc. all write without undo). Pass the token from a wp_reorder_menu_items response\'s undo.token field. Tokens live 72 hours and are one-shot (consumed on successful undo). More tools gain undo retrofits in future releases. Cap requirement matches the original operation. Free basic mode — single-op restore, local storage.', 'inputSchema' => ['type' => 'object', 'properties' => ['token' => ['type' => 'string', 'description' => 'The undo token from a prior tool response (response.undo.token).']], 'required' => ['token']]],
            ['name' => 'wp_search', 'description' => 'Search all content. Pass snippet>0 to receive a content excerpt around each match (saves tokens vs. fetching each result with wp_get_page). Each result includes content_length (bytes of stored content) for size triage.', 'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string'], 'post_type' => ['type' => 'string'], 'per_page' => ['type' => 'integer', 'description' => 'Number of results (default 20, max 100)'], 'snippet' => ['type' => 'integer', 'description' => 'Snippet length in characters around the matched term (default 0 = off, recommended 160-240). When set, results include slug and snippet fields.']], 'required' => ['query']]],

            // Options
            ['name' => 'wp_get_option', 'description' => 'Get a single WordPress option value. Requires manage_options capability. The option name must be in the readable allowlist — 12 defaults (blogname, blogdescription, siteurl, home, admin_email, posts_per_page, date_format, time_format, timezone_string, googlesitekit_analytics-4_settings, show_on_front, page_on_front) plus any keys plugin authors opt in via the royal_mcp_readable_options filter. Sensitive keys inside the returned value are redacted regardless of what the option contains.', 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']]],
            ['name' => 'wp_get_plugin_settings', 'description' => 'Get all options stored by a plugin, looked up by slug. Sensitive keys (api keys, secrets, tokens, passwords) are redacted before return.', 'inputSchema' => ['type' => 'object', 'properties' => ['plugin_slug' => ['type' => 'string', 'description' => 'Plugin slug, e.g. royalcomply or royal-affiliate-pro']], 'required' => ['plugin_slug']]],
            ['name' => 'wp_update_option', 'description' => 'Update a WordPress option. Four gates in order: (1) manage_options capability on the caller; (2) master "Allow AI to write WordPress options" admin toggle enabled; (3) hard denylist (siteurl, home, admin_email, mailserver_*, upload_path, users_can_register, wp_user_roles, wp_capabilities, api_key/secret/*_pass/*_key patterns, royal_mcp_* namespace — permanent, cannot be filter-overridden); (4) write⊆readable invariant + writable allowlist (option must appear in both royal_mcp_readable_options AND royal_mcp_writable_options — plugin authors must opt into READS before opting into WRITES). Error text names which gate blocked. "Not in allowlist" is fixable via filter opt-in; "permanently denylisted" is not.', 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'value' => ['description' => 'New value (any JSON type). Full overwrite — read first, merge in your client, then write back.']], 'required' => ['name', 'value']]],

            // Menus
            ['name' => 'wp_get_menus', 'description' => 'List all registered navigation menus (nav_menu taxonomy). Returns id, name, slug, and item count for each. Use wp_get_menu_items to enumerate items within a specific menu.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_get_menu_items', 'description' => 'Get menu items', 'inputSchema' => ['type' => 'object', 'properties' => ['menu_id' => ['type' => 'integer']], 'required' => ['menu_id']]],
            ['name' => 'wp_create_menu', 'description' => 'Create a new WordPress navigation menu. Wraps wp_create_nav_menu(). Returns the new menu_id which can then be passed to wp_create_menu_item / wp_reorder_menu_items. Use this on a site that has no menus yet so the menu-item tools have a menu to write into. Requires edit_theme_options capability. Returns WP_Error on duplicate menu name.', 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'description' => 'Display name for the new menu. Must be unique among existing menus.']], 'required' => ['name']]],
            ['name' => 'wp_create_menu_item', 'description' => 'Create a menu item in a navigation menu. On a site with no menus, call wp_create_menu first to obtain a menu_id. Requires edit_theme_options capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['menu_id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'url' => ['type' => 'string', 'description' => 'External URL (leave empty if linking to a post/page via object_id)'], 'object_id' => ['type' => 'integer', 'description' => 'WordPress object ID (post, page, or term)'], 'object_type' => ['type' => 'string', 'enum' => ['post', 'page', 'category', 'custom'], 'description' => 'Type of object being linked (default: custom)'], 'parent_id' => ['type' => 'integer', 'description' => 'Parent menu item ID for nested items (0 = top level)'], 'position' => ['type' => 'integer', 'description' => 'Position in menu order (default: end)'], 'target' => ['type' => 'string', 'enum' => ['_self', '_blank'], 'description' => 'Link target']], 'required' => ['menu_id', 'title']]],
            ['name' => 'wp_update_menu_item', 'description' => 'Update an existing menu item. Only the fields you pass will change; unspecified fields are preserved from the existing item. The tool will refuse explicit-empty values for title or url that would destroy a non-empty existing value — to intentionally clear those, use wp_delete_menu_item then wp_create_menu_item. Requires edit_theme_options capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['menu_item_id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'url' => ['type' => 'string'], 'parent_id' => ['type' => 'integer'], 'position' => ['type' => 'integer'], 'target' => ['type' => 'string', 'enum' => ['_self', '_blank']]], 'required' => ['menu_item_id']]],
            ['name' => 'wp_delete_menu_item', 'description' => 'Delete a menu item. Requires edit_theme_options capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['menu_item_id' => ['type' => 'integer']], 'required' => ['menu_item_id']]],
            ['name' => 'wp_reorder_menu_items', 'description' => 'Reorder menu items by passing an array of menu_item_ids in the desired order. Existing titles, URLs, parents, and other fields are preserved on every item touched. Every response includes an "undo" envelope with a token that mcp_undo_last_operation can consume for 72 hours to restore the pre-op menu order. If the response includes a "skipped" array, those items could not be safely reordered (e.g. missing or recently deleted) — the rest were reordered correctly. Requires edit_theme_options capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['menu_id' => ['type' => 'integer'], 'item_order' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Array of menu_item_ids in the desired order']], 'required' => ['menu_id', 'item_order']]],

            // Plugins & Themes
            ['name' => 'wp_get_plugins', 'description' => 'List all installed plugins. Returns plugin file path, name, version, description, author, and active status for each. Useful for diagnosing plugin conflicts and building a compatibility picture at the start of a debugging conversation.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_get_themes', 'description' => 'List all installed themes. Returns theme slug, name, version, author, and active flag for each. Use wp_get_active_theme for details on the currently-active theme only.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],

            // Theme & Appearance
            ['name' => 'wp_get_active_theme', 'description' => 'Get the active theme with name, version, parent (if child theme), and screenshot URL', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_get_theme_mods', 'description' => 'Get all customizer settings (theme_mods) for the active theme', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_update_theme_mod', 'description' => 'Update a single theme customizer setting. Requires the "Allow AI to modify theme appearance" admin toggle AND the mod name must be in the allowlist (extend via the royal_mcp_writable_theme_mods filter).', 'inputSchema' => ['type' => 'object', 'properties' => ['mod_name' => ['type' => 'string'], 'value' => ['description' => 'New value (any JSON type compatible with set_theme_mod)']], 'required' => ['mod_name', 'value']]],
            ['name' => 'wp_get_custom_css', 'description' => 'Get the active theme\'s custom CSS', 'inputSchema' => ['type' => 'object', 'properties' => ['theme_slug' => ['type' => 'string', 'description' => 'Theme slug (defaults to active theme)']]]],
            ['name' => 'wp_update_custom_css', 'description' => 'Update the active theme\'s custom CSS. CSS is filtered through wp_kses (script tags stripped). Requires the "Allow AI to modify theme appearance" admin toggle and unfiltered_html capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['css' => ['type' => 'string'], 'theme_slug' => ['type' => 'string', 'description' => 'Theme slug (defaults to active theme)']], 'required' => ['css']]],
            ['name' => 'wp_get_widgets', 'description' => 'List widget instances. Uses the WordPress core /wp/v2/widgets REST endpoint in edit context so both rendered output AND full instance payload are returned uniformly for classic and block widgets. Classic widgets: instance carries the widget-specific settings (text, title, filter, etc.). Block widgets: instance.raw.content carries the raw block markup, and a blocks field is added with the parsed block tree for structured inspection. Omit sidebar to return widgets across ALL sidebars including wp_inactive_widgets (orphaned widgets from prior themes — these have rendered:"" and produce no front-end output). Filter by a specific sidebar ID (discover IDs via wp_get_sidebars) to scope results; a non-existent sidebar ID returns an empty array, not an error.', 'inputSchema' => ['type' => 'object', 'properties' => ['sidebar' => ['type' => 'string', 'description' => 'Optional sidebar ID to filter by. Omit to return widgets across all sidebars (includes wp_inactive_widgets).']]]],
            ['name' => 'wp_get_sidebars', 'description' => 'List registered sidebars (widget areas) on the active theme with their IDs, names, description, and status. Use to discover sidebar IDs before calling wp_get_widgets or wp_update_widget.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_update_widget', 'description' => 'Update a widget instance by ID. Requires the "Allow AI to modify theme appearance" admin toggle AND edit_theme_options capability. Uses WordPress core /wp/v2/widgets so classic and block widgets are handled uniformly. Pass the id returned by wp_get_widgets. Note: payloads with backslash escape sequences (JSON unicode escapes, embedded JSON-LD, Divi loop field bindings) may not survive the MCP → REST → write pipeline as literal backslashes — decode client-side before sending or verify rendered output.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'description' => 'Widget ID (e.g. text-2, block-15)'], 'sidebar' => ['type' => 'string', 'description' => 'Sidebar ID to place the widget in (omit to leave unchanged)'], 'instance' => ['type' => 'object', 'description' => 'Widget instance data. For classic widgets, either pass the same {encoded, hash} object returned by wp_get_widgets or wrap raw settings as {raw: {…}}.'], 'form_data' => ['type' => 'string', 'description' => 'Serialized form data (classic widgets alternative to instance)']], 'required' => ['id']]],

            // SEO Meta (auto-detects Yoast SEO / Rank Math / AIOSEO / SEObolt)
            ['name' => 'wp_get_seo_meta', 'description' => 'Get the SEO meta fields for a post (title, description, focus keyword, noindex, OG overrides where supported, URL slug). Auto-detects the active SEO plugin — Yoast SEO, Rank Math, AIOSEO, or SEObolt — and returns that plugin\'s fields plus the post slug (which is a WordPress-native field, returned regardless of SEO plugin). AIOSEO + SEObolt return the four core fields (title/description/focus_keyword/noindex); og_title/og_description are populated for Yoast + Rank Math only. Returns RAW stored templates (e.g. Yoast\'s default "%%page%% %%sep%% %%sitename%%") — do NOT measure length from these values, they contain template markup that never appears in the rendered <title> tag and will produce false-positive title_too_short / title_too_long flags. For measured/rendered SEO values, use Royal MCP Pro\'s wp_audit_seo_bulk which resolves per-engine template variables before measuring.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']], 'required' => ['post_id']]],
            ['name' => 'wp_update_seo_meta', 'description' => 'Update SEO meta fields on a post. Auto-routes title/description/focus_keyword/noindex to whichever SEO plugin is active (Yoast, Rank Math, AIOSEO, or SEObolt). og_title/og_description route to Yoast + Rank Math; for AIOSEO/SEObolt they are silently skipped since those plugins store OG data in different shapes. The slug field is a WordPress-native field and works regardless of SEO plugin. Requires edit_post capability on the target post.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'title' => ['type' => 'string', 'description' => 'SEO title (replaces the meta title used in browser tabs and SERPs)'], 'description' => ['type' => 'string', 'description' => 'SEO meta description (used in SERPs)'], 'focus_keyword' => ['type' => 'string', 'description' => 'Primary focus keyword for SEO scoring (AIOSEO stores this as focus_keyphrase internally)'], 'noindex' => ['type' => 'boolean', 'description' => 'Tell search engines not to index this URL'], 'og_title' => ['type' => 'string', 'description' => 'Open Graph title (Facebook / Slack / LinkedIn previews). Yoast + Rank Math only.'], 'og_description' => ['type' => 'string', 'description' => 'Open Graph description. Yoast + Rank Math only.'], 'slug' => ['type' => 'string', 'description' => 'URL slug (post_name). WordPress will sanitize and ensure uniqueness; the actually-saved value is returned in the response so the caller can confirm.']], 'required' => ['post_id']]],
            ['name' => 'seo_audit_meta_tags', 'description' => 'Fetch a post\'s actual rendered HTML and parse the head for title, meta description, canonical, viewport, Open Graph and Twitter Card tags. Catches theme/plugin/cache conflicts that only appear in the served output — duplicate title tags, mismatched canonicals, missing OG images, viewport misconfiguration. Complements wp_get_seo_meta (which reads DB fields) by validating what actually reaches crawlers. Pass a post_id (URL is resolved via get_permalink) or a same-site url. Read-only.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer', 'description' => 'Post ID whose permalink to audit. Either post_id or url is required.'], 'url' => ['type' => 'string', 'description' => 'Absolute URL to audit. Must be on this site (same host as home_url). Either post_id or url is required.']]]],

            // Permalink Structure
            ['name' => 'wp_get_permalink_structure', 'description' => 'Get the WordPress permalink structure (e.g. /%postname%/, /%year%/%monthnum%/%postname%/). Read-only.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_update_permalink_structure', 'description' => 'Update the WordPress permalink structure. Requires the "Allow AI to write WordPress options" admin toggle. Common values: /%postname%/, /%year%/%monthnum%/%postname%/, /%category%/%postname%/. Changing this rewrites every URL on the site — flushes rewrite rules automatically.', 'inputSchema' => ['type' => 'object', 'properties' => ['structure' => ['type' => 'string', 'description' => 'New permalink structure (e.g. /%postname%/)']], 'required' => ['structure']]],

            // Post Revisions
            ['name' => 'wp_get_post_revisions', 'description' => 'Get the revision history for a post — list of all saved revisions with author, date, revision ID, word_count, and content_length (raw byte size). content_length is the reliable "is this revision empty?" signal: word_count uses strip_tags and misses text stored inside attributes (Divi 5 block attrs, data-* attrs, alt text), so a full page-builder revision can show word_count=0 while content_length>0. Useful for "what changed?" or "revert to yesterday\'s version" workflows.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'limit' => ['type' => 'integer', 'description' => 'Max revisions to return (default 20)']], 'required' => ['post_id']]],
            ['name' => 'wp_create_preview_link', 'description' => 'Create a token-authenticated preview URL for a post/page/CPT so an agent can self-validate rendered output without WP session cookies. Wraps a random 32-char token stored in a transient + a redirect handler that impersonates the token owner and forwards to WordPress\'s native preview URL. preview_url is for AGENT self-validation — do NOT share with the end user; share edit_url instead. Cap: edit_post on target post_id.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer', 'description' => 'Post/page/CPT ID to build a preview link for.'], 'ttl_minutes' => ['type' => 'integer', 'description' => 'Token lifetime in minutes. Default 15, max 1440 (24h).']], 'required' => ['post_id']]],
            ['name' => 'wp_get_revision_content', 'description' => 'Read the full stored content of a single post revision by revision ID. Use with wp_get_post_revisions to inspect what a past version actually contained, or to recover content lost in a page-builder or plugin migration. Read-only.', 'inputSchema' => ['type' => 'object', 'properties' => ['revision_id' => ['type' => 'integer', 'description' => 'Revision ID from wp_get_post_revisions.']], 'required' => ['revision_id']]],
            ['name' => 'wp_diff_revisions', 'description' => 'Unified diff between two states of a post, computed server-side. Defaults to comparing the most recent revision against the current live content, which answers "what did my last write actually change?" without transferring both full bodies. Pass from_revision_id / to_revision_id to compare any two revisions of the same post. Output capped by max_lines; truncation is reported. Read-only.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'from_revision_id' => ['type' => 'integer', 'description' => 'Optional. Defaults to the most recent revision.'], 'to_revision_id' => ['type' => 'integer', 'description' => 'Optional. Defaults to the current live post content.'], 'max_lines' => ['type' => 'integer', 'description' => 'Maximum diff lines to return (default 500, max 5000).']], 'required' => ['post_id']]],
            ['name' => 'wp_restore_revision', 'description' => 'Restore a post to a specific revision. The current post content becomes the previous revision (so it can still be reverted again). Requires edit_post capability on the parent post.', 'inputSchema' => ['type' => 'object', 'properties' => ['revision_id' => ['type' => 'integer']], 'required' => ['revision_id']]],
        ];

        // Conditionally add integration tools
        $tools = array_merge( $tools, WooIntegration::get_tools() );
        $tools = array_merge( $tools, GPIntegration::get_tools() );
        $tools = array_merge( $tools, SVIntegration::get_tools() );
        $tools = array_merge( $tools, RLIntegration::get_tools() );
        $tools = array_merge( $tools, FCIntegration::get_tools() );
        $tools = array_merge( $tools, RLinksIntegration::get_tools() );
        $tools = array_merge( $tools, ElementorIntegration::get_tools() );
        $tools = array_merge( $tools, ACFIntegration::get_tools() );
        $tools = array_merge( $tools, RAIFIntegration::get_tools() );
        $tools = array_merge( $tools, RedirectionIntegration::get_tools() );
        $tools = array_merge( $tools, DiviIntegration::get_tools() );
        $tools = array_merge( $tools, ComposersIntegration::get_tools() );

        // 1.4.37 Candidate 5 — when Elementor's own MCP module is present,
        // prefix our elementor_* tool descriptions with a routing hint so
        // agents pick the canonical primitive per task. No behavior change
        // to our tools; opt-in defer only.
        $tools = Elementor_Coexistence::filter_elementor_tool_descriptions( $tools );

        /**
         * Filter the full tool list returned to tools/list. Applied last so
         * profile trims + custom filters see every registered tool.
         *
         * @param array $tools Tool definitions with name, description, inputSchema.
         */
        return apply_filters( 'royal_mcp_tools', $tools );
    }

    /**
     * Handle the MCP endpoint - Streamable HTTP transport
     * Single endpoint for all MCP communication
     */
    public function handle_mcp($request) {
        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET';

        // Handle OPTIONS for CORS preflight
        if ($method === 'OPTIONS') {
            return $this->cors_response();
        }

        // Validate Origin header to prevent DNS rebinding attacks
        $origin_check = $this->validate_origin($request);
        if ($origin_check !== true) {
            return $origin_check;
        }

        // Rate limiting
        $client_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '127.0.0.1';
        $rate_check = $this->check_rate_limit($client_ip);
        if ($rate_check !== true) {
            return $rate_check;
        }

        // GET request = client wants to listen for server-initiated messages
        if ($method === 'GET') {
            return $this->handle_get_stream($request);
        }

        // POST request = client sending JSON-RPC message
        if ($method === 'POST') {
            // Validate Accept header per MCP spec
            if (!$this->validate_accept_header($request)) {
                return $this->json_response([
                    'jsonrpc' => '2.0',
                    'error' => [
                        'code' => -32600,
                        'message' => 'Accept header must include application/json',
                    ],
                ], 400);
            }
            return $this->handle_post_message($request);
        }

        // DELETE request = terminate session
        if ($method === 'DELETE') {
            return $this->handle_delete_session($request);
        }

        return new \WP_REST_Response(['error' => 'Method not allowed'], 405);
    }

    /**
     * Handle CORS preflight
     */
    private function cors_response() {
        $response = new \WP_REST_Response(null, 204);
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, Mcp-Session-Id, X-Royal-MCP-API-Key');
        $response->header('Access-Control-Max-Age', '86400');
        return $response;
    }

    /**
     * Handle GET - tri-state response for the MCP Streamable HTTP endpoint
     *
     * The single /mcp endpoint is hit by multiple clients with conflicting
     * probe behavior. Each "fix" we've shipped has broken a different client.
     * This is the 4th iteration. Auth check FIRST (RFC 9728), then dispatch by
     * User-Agent so each client gets its expected behavior:
     *
     *  1. Unauthenticated GET (any UA) →
     *     401 + WWW-Authenticate: Bearer resource_metadata="..."
     *     (RFC 9728 — required for Claude.ai web / ChatGPT pre-OAuth discovery.
     *     Handled inside validate_auth().)
     *
     *  2. Authenticated GET + UA contains "Claude-User" →
     *     200 + Content-Type: text/event-stream + a minimal keepalive comment.
     *     This is Anthropic's post-OAuth session-establishment probe. Without
     *     it Anthropic retries the GET four times then gives up, so the entire
     *     OAuth flow (which succeeded at /token in 1.4.17) still fails to
     *     produce a working session. Added in 1.4.18.
     *
     *  3. Authenticated GET + any other UA (mcp-remote, node-fetch, etc.) →
     *     405 + Allow: POST, DELETE, OPTIONS.
     *     The MCP Streamable HTTP spec says servers MAY support GET for SSE
     *     and MUST return 405 if they don't — both are spec-compliant. Clients
     *     that don't implement SSE handle 405 cleanly by falling back to POST.
     */
    private function handle_get_stream($request) {
        $auth_check = $this->validate_auth($request);
        if ($auth_check !== true) {
            return $auth_check;
        }

        // Anthropic post-OAuth session probe — return 200 + SSE keepalive and
        // exit early. Can't use WP_REST_Response here because it auto-JSON-
        // encodes the body, which would corrupt SSE format. We have nothing
        // server-initiated to push, so a single keepalive comment followed by
        // clean termination is the minimum spec-compliant response.
        $user_agent = $request->get_header('User-Agent');
        if (is_string($user_agent) && stripos($user_agent, 'Claude-User') !== false) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            status_header(200);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, private');
            header('Pragma: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Disable nginx buffering
            echo ": royal-mcp-keepalive\n\n";
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            exit;
        }

        // All other authenticated clients (mcp-remote, custom scripts) — SSE
        // not hosted here. 405 with Allow header so they fall back to POST.
        $response = new \WP_REST_Response([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32600,
                'message' => 'Server-sent events (SSE) are not supported on this client. Use HTTP POST for all MCP communication.',
            ],
        ], 405);
        $response->header('Allow', 'POST, DELETE, OPTIONS');
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        return $response;

        // Unreachable below — preserved for future SSE support.
        $session_id = $request->get_header('Mcp-Session-Id');
        $accept = $request->get_header('Accept');

        // Validate Accept header must include text/event-stream
        if (empty($accept) || strpos($accept, 'text/event-stream') === false) {
            return new \WP_REST_Response([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Accept header must include text/event-stream for GET requests',
                ],
            ], 400);
        }

        // Session ID required for GET streams
        if (empty($session_id)) {
            return new \WP_REST_Response([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Mcp-Session-Id header required',
                ],
            ], 400);
        }

        // Validate session ID format
        if (!$this->validate_session_id_format($session_id)) {
            return new \WP_REST_Response([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Invalid session ID format',
                ],
            ], 400);
        }

        // Check if session exists
        if (!$this->is_valid_session($session_id)) {
            return new \WP_REST_Response([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Session not found or expired',
                ],
            ], 404);
        }

        // Check for Last-Event-ID for resumability
        $last_event_id = $request->get_header('Last-Event-ID');

        // Set SSE headers
        $response = new \WP_REST_Response(null, 200);
        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Access-Control-Expose-Headers', 'Mcp-Session-Id');
        $response->header('X-Accel-Buffering', 'no'); // Disable nginx buffering

        // Note: WordPress REST API doesn't support long-lived SSE connections well
        // For production SSE, consider a dedicated endpoint outside WP REST API
        // This implementation acknowledges the stream and returns empty
        // Server-initiated messages would require a different architecture

        return $response;
    }

    /**
     * Handle POST - Process JSON-RPC message
     */
    private function handle_post_message($request) {
        // Parse JSON-RPC message
        $body = $request->get_json_params();

        if (!$body || !isset($body['jsonrpc']) || $body['jsonrpc'] !== '2.0') {
            return $this->json_response([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Invalid JSON-RPC request',
                ],
            ], 400);
        }

        $method = $body['method'] ?? '';
        $params = $body['params'] ?? [];
        $id = $body['id'] ?? null;

        // Get session ID from header
        $session_id = $request->get_header('Mcp-Session-Id');

        // stash session id for royal_mcp_connection_health diagnostic tool.
        $this->request_session_id = $session_id ? (string) $session_id : null;

        // Authenticate EVERY request — API key or Bearer token required.
        $auth_check = $this->validate_auth($request);
        if ($auth_check !== true) {
            return $auth_check;
        }

        // Build auth fingerprint to bind sessions to credentials.
        $auth_fingerprint = $this->build_auth_fingerprint($request);

        // For non-initialize requests, validate session
        if ($method !== 'initialize') {
            // Per MCP spec: SHOULD respond with 400 Bad Request to requests without session ID
            if (empty($session_id)) {
                return $this->json_response([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => [
                        'code' => -32600,
                        'message' => 'Mcp-Session-Id header required. Please initialize first.',
                    ],
                ], 400);
            }

            // Validate session ID format
            if (!$this->validate_session_id_format($session_id)) {
                return $this->json_response([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => [
                        'code' => -32600,
                        'message' => 'Invalid session ID format',
                    ],
                ], 400);
            }

            // Check if session exists
            if (!$this->is_valid_session($session_id)) {
                return $this->json_response([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => [
                        'code' => -32600,
                        'message' => 'Session not found or expired. Please re-initialize.',
                    ],
                ], 404);
            }

            // Verify session is bound to the same credentials. 1.4.27 — DB-backed
            // lookup (see Session_Store class doc for the object-cache motivation).
            $stored_fingerprint = Session_Store::get_fingerprint($session_id);
            if (!empty($stored_fingerprint) && !hash_equals($stored_fingerprint, $auth_fingerprint)) {
                return $this->json_response([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => [
                        'code' => -32600,
                        'message' => 'Session credentials mismatch. Please re-initialize.',
                    ],
                ], 403);
            }
        }

        // Process the method
        $result = $this->process_method($method, $params, $id);

        // For initialize, generate and return session ID
        if ($method === 'initialize' && $result && isset($result['result'])) {
            $new_session_id = $this->generate_session_id();
            // Store the session bound to the authenticated credentials
            $this->store_session($new_session_id, $auth_fingerprint);
            $response = $this->json_response($result, 200);
            $response->header('Mcp-Session-Id', $new_session_id);
            return $response;
        }

        // Notifications don't get responses
        if ($id === null) {
            return new \WP_REST_Response(null, 202);
        }

        return $this->json_response($result, 200);
    }

    /**
     * Handle DELETE - Terminate session
     * Per MCP spec: Client SHOULD send DELETE to explicitly terminate session
     */
    private function handle_delete_session($request) {
        // Authenticate before allowing session termination.
        $auth_check = $this->validate_auth($request);
        if ($auth_check !== true) {
            return $auth_check;
        }

        $session_id = $request->get_header('Mcp-Session-Id');

        if (empty($session_id)) {
            return $this->json_response([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Mcp-Session-Id header required',
                ],
            ], 400);
        }

        // Validate session ID format
        if (!$this->validate_session_id_format($session_id)) {
            return $this->json_response([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Invalid session ID format',
                ],
            ], 400);
        }

        // Delete the session from storage
        $this->delete_session($session_id);

        // Return success
        $response = new \WP_REST_Response(null, 200);
        $response->header('Access-Control-Allow-Origin', '*');
        return $response;
    }

    /**
     * Return the fingerprint that binds this request's session to its
     * authenticated identity. Populated by validate_bearer_token /
     * validate_api_key_value, both of which run before this is read
     * (validate_auth is the first gate in handle_post_message).
     *
     * The $request argument is kept for API stability — the fingerprint is
     * now derived from the *validated* credential rather than the raw header
     * so a rotating OAuth access token still produces the same fingerprint.
     *
     * @param \WP_REST_Request $request The request object (unused; retained for signature stability).
     * @return string SHA-256 hash bound to stable auth identity, or '' if not authenticated.
     */
    private function build_auth_fingerprint($request) {
        unset($request); // Retained in signature; state comes from validate_auth().
        return $this->request_auth_fingerprint;
    }

    /**
     * Generate cryptographically secure session ID
     */
    private function generate_session_id() {
        return bin2hex(random_bytes(16));
    }

    /**
     * Create JSON response with proper headers.
     *
     * Cache-Control: no-store is mandatory on every MCP response. Without it,
     * edge caches (CDN, host-level fastcgi cache, generic intermediaries)
     * key on URL alone and serve a stale auth-error response regardless of
     * whether the Authorization / X-Royal-MCP-API-Key header is present on
     * the second request. This is the same class of cache-poisoning bug
     * that gates every authenticated JSON-RPC endpoint.
     */
    private function json_response($data, $status = 200) {
        $response = new \WP_REST_Response($data, $status);
        $response->header('Content-Type', 'application/json');
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Access-Control-Expose-Headers', 'Mcp-Session-Id');
        return $response;
    }

    /**
     * Process JSON-RPC method and return response object
     */
    private function process_method($method, $params, $id) {
        switch ($method) {
            case 'initialize':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'protocolVersion' => '2025-11-25',
                        'serverInfo' => [
                            'name' => 'Royal MCP WordPress',
                            'version' => ROYAL_MCP_VERSION,
                        ],
                        'capabilities' => [
                            'tools' => new \stdClass(),
                        ],
                    ],
                ];

            case 'notifications/initialized':
            case 'initialized':
                return null; // No response for notifications

            case 'tools/list':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'tools' => $this->get_tools(),
                    ],
                ];

            case 'tools/call':
                return $this->handle_tool_call($id, $params);

            case 'ping':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => new \stdClass(),
                ];

            case 'resources/list':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => ['resources' => []],
                ];

            case 'prompts/list':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => ['prompts' => []],
                ];

            default:
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => [
                        'code' => -32601,
                        'message' => 'Method not found: ' . $method,
                    ],
                ];
        }
    }

    private function handle_tool_call($id, $params) {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        $start = microtime(true);

        try {
            $result = $this->execute_tool($name, $args);
            $this->log_tool_call($name, $args, 'success', null, $start, $result, null);

            // If the tool already returned a pre-formed MCP envelope (isError
            // + content keys present), pass it through unwrapped so the
            // structuredContent + undo top-level fields reach the client. This
            // is the canonical MCP 2025-11-25 tools/call response shape — see
            // Royal_MCP\MCP\Support\Envelope. Legacy tools returning flat
            // arrays fall through to the JSON-encoded-text-block path below
            // (no back-compat break).
            if ( \Royal_MCP\MCP\Support\Envelope::is_envelope( $result ) ) {
                return [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => $result,
                ];
            }

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [[
                        'type' => 'text',
                        'text' => is_string($result) ? $result : wp_json_encode($result, JSON_PRETTY_PRINT),
                    ]],
                ],
            ];
        } catch (\Exception $e) {
            $this->log_tool_call($name, $args, 'error', $e->getMessage(), $start, null, $e);
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Error: ' . $e->getMessage(),
                    ]],
                    'isError' => true,
                ],
            ];
        }
    }

    /**
     * Log an MCP tool call to wp_royal_mcp_logs.
     *
     * STRICT SAFELIST: logs the tool name and the KEYS of the argument array,
     * but NEVER the argument VALUES. Tool arguments can contain arbitrary
     * customer data (post content, search queries with PII, user-supplied
     * credentials passed through, etc.) — keys alone tell us "what was called"
     * without leaking "what data." Error messages from the tool dispatcher are
     * our own strings, safe to log.
     *
     * Closes an observability gap where the modern /mcp endpoint would
     * otherwise produce zero Activity Log entries even when actively serving
     * tool calls — making admins think the connection was broken.
     */
    private function log_tool_call($tool_name, $args, $status, $error_message, $start_time = null, $result = null, $exception = null) {
        global $wpdb;

        $request_meta = [
            'tool'     => (string) $tool_name,
            'arg_keys' => is_array($args) ? array_keys($args) : [],
        ];

        $response_meta = [ 'status' => $status ];
        if ('error' === $status && $error_message) {
            $response_meta['error'] = (string) $error_message;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional direct insert to the logs table.
        $wpdb->insert(
            $wpdb->prefix . 'royal_mcp_logs',
            [
                'mcp_server'    => 'MCP Server',
                'action'        => 'tools/call:' . sanitize_text_field((string) $tool_name),
                'request_data'  => wp_json_encode($request_meta),
                'response_data' => wp_json_encode($response_meta),
                'status'        => 'success' === $status ? 'success' : 'error',
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        /**
         * Fires after every MCP tool invocation. Surfaces inbound MCP traffic
         * to observability plugins (Royal AI Firewall, etc.) so they can
         * record + classify the calling agent in real-time.
         *
         * @param string $tool_name     The MCP tool that was called.
         * @param string $status        'success' | 'error'
         * @param string $error_message Empty string on success; the dispatcher
         *                              error message on failure.
         */
        do_action('royal_mcp_tool_called', (string) $tool_name, (string) $status, (string) ($error_message ?? ''));

        /**
         * Fires after every MCP tool invocation with an enriched context payload.
         * Additive to `royal_mcp_tool_called` — that hook stays byte-compatible
         * for subscribers on older signatures. New subscribers should prefer
         * this hook for richer observability + risk-classification context.
         *
         * The payload NEVER contains raw argument values or raw result content —
         * `tool_args_hash` (SHA-256 of sanitized args) enables dedupe / replay
         * detection without leaking argument data; `response_size_bytes` is a
         * length only, not a body.
         *
         * @param string $tool_name The MCP tool that was called.
         * @param array  $context   Enriched payload:
         *                          - status: 'success' | 'error'
         *                          - error_message: string
         *                          - error_class: string|null — exception class on failure
         *                          - latency_ms: int
         *                          - response_size_bytes: int
         *                          - tool_args_hash: string — sha256 hex of sanitized args
         *                          - arg_keys: string[] — argument keys (no values)
         *                          - is_destructive: bool — tool name matches destructive allowlist
         */
        $latency_ms = ( null !== $start_time )
            ? (int) round( ( microtime(true) - (float) $start_time ) * 1000 )
            : 0;

        $response_size = 0;
        if ( null !== $result ) {
            $encoded = wp_json_encode( $result );
            $response_size = is_string( $encoded ) ? strlen( $encoded ) : 0;
        }

        $args_hash = '';
        if ( is_array( $args ) ) {
            $args_encoded = wp_json_encode( $args );
            if ( is_string( $args_encoded ) ) {
                $args_hash = hash( 'sha256', $args_encoded );
            }
        }

        $context = [
            'status'              => (string) $status,
            'error_message'       => (string) ( $error_message ?? '' ),
            'error_class'         => ( $exception instanceof \Throwable ) ? get_class( $exception ) : null,
            'latency_ms'          => $latency_ms,
            'response_size_bytes' => $response_size,
            'tool_args_hash'      => $args_hash,
            'arg_keys'            => is_array( $args ) ? array_keys( $args ) : [],
            'is_destructive'      => $this->is_destructive_tool( (string) $tool_name ),
        ];

        do_action( 'royal_mcp_tool_context', (string) $tool_name, $context );
    }

    /**
     * Static allowlist of tool names + name-prefix patterns that are considered
     * destructive for observability / approval-workflow classification.
     *
     * Not a security boundary — capability checks in the tool handlers own that.
     * Purpose is to give downstream subscribers (Royal AI Firewall approval
     * workflow, audit dashboards) a one-bit signal to distinguish read tools
     * from tools that mutate or remove state.
     *
     * Additions welcome as new destructive surfaces ship.
     */
    private function is_destructive_tool( $tool_name ) {
        // Expanded prefix classifier — covers the P7 SiteVault hook audit's
        // full destructive surface. Every prefix matches a write / mutation /
        // delete class:
        //   wp_delete_  wp_trash_  wp_spam_   → delete + status transitions
        //   wp_create_  wp_update_ wp_add_    → content + meta mutation
        //   wp_upload_                         → media create
        //   wp_approve_                        → moderation state change
        //   wp_replace_in_                     → surgical content mutation
        //   wp_restore_                        → revision restoration
        //   wc_delete_  wc_create_ wc_update_ → WC full surface
        //   wc_add_     wc_batch_  wc_set_    → WC add/bulk/set
        //   wc_empty_                          → WC bulk delete (trash empty)
        static $destructive_prefixes = [
            'wp_delete_',
            'wp_trash_',
            'wp_spam_',
            'wp_create_',
            'wp_update_',
            'wp_add_',
            'wp_upload_',
            'wp_approve_',
            'wp_replace_in_',
            'wp_restore_',
            'wc_delete_',
            'wc_create_',
            'wc_update_',
            'wc_add_',
            'wc_batch_',
            'wc_set_',
            'wc_empty_',
        ];

        // Exact list — irregular names that don't fit the prefix classifier,
        // plus cross-plugin destructive tools (raif_/fc_/rl_/sv_) surfaced
        // by companion plugins.
        static $destructive_exact = [
            'wp_reorder_menu_items',
            'wp_set_featured_image',
            'mcp_undo_last_operation',
            'raif_set_bot_policy',
            'raif_block_all_ai_bots',
            'fc_clear_cache',
            'fc_purge_url',
            'rl_create_cost',
            'sv_create_backup',
        ];

        if ( in_array( $tool_name, $destructive_exact, true ) ) {
            return true;
        }
        foreach ( $destructive_prefixes as $prefix ) {
            if ( 0 === strpos( $tool_name, $prefix ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Public accessor for the tool registry. Used by the Abilities API Registrar
     * to walk the full tool list without duplicating the tool definitions.
     */
    public function get_all_tools(): array {
        return $this->get_tools();
    }

    /**
     * Public entry point for tool invocation. Used by the Abilities API Registrar
     * to route ability calls through the same handler stack + per-tool capability
     * gates as MCP + REST calls.
     */
    public function invoke( string $name, array $args ) {
        return $this->execute_tool( $name, $args );
    }

    private function execute_tool($name, $args) {
        // Fire SiteVault pre-op backup hook for every destructive tool.
        // Non-blocking + fire-and-forget — see SiteVault_Hook::maybe_fire
        // + INVARIANTS §3. Fleet-wide via one call at the dispatcher
        // entry point rather than 40+ per-handler inline calls.
        if ( $this->is_destructive_tool( $name ) ) {
            \Royal_MCP\MCP\Support\SiteVault_Hook::maybe_fire( $name, $args );
        }
        switch ($name) {
            // ==================== POSTS ====================
            case 'wp_get_posts':
                // Per-tool capability gate. Require `read` to call at all;
                // restricted post statuses (draft / private / trash / etc.)
                // require read_private_posts on the target post type. Without
                // these gates, a Subscriber-level OAuth token could pass a
                // non-public status and receive admin-owned content.
                if (!current_user_can('read')) {
                    throw new \Exception('You do not have permission to list posts.');
                }
                $query_args = [
                    'numberposts' => min(intval($args['per_page'] ?? 10), 100),
                    's' => \Royal_MCP\MCP\Support\SafeText::field($args['search'] ?? ''),
                ];
                if (!empty($args['post_type'])) {
                    $pt = sanitize_text_field($args['post_type']);
                    $pto = get_post_type_object($pt);
                    // Accept viewable types OR non-public types the caller can edit
                    // (matches block editor + REST API listability logic — how
                    // Divi Theme Builder, ACF field groups, and similar
                    // non-public-but-editable types show up in admin lists).
                    if (!$pto || (!is_post_type_viewable($pto) && !current_user_can($pto->cap->edit_posts))) {
                        throw new \Exception('Invalid or non-accessible post type: ' . esc_html($pt));
                    }
                    $query_args['post_type'] = $pt;
                }
                if (!empty($args['status'])) {
                    $requested_status = sanitize_text_field($args['status']);
                    // Allowlist of public WP post statuses (defaults to ['publish'];
                    // honors any custom statuses registered as public). Anything
                    // else — including 'any', 'private', 'draft', 'pending',
                    // 'future', 'trash', unknown values, or typos — requires
                    // read_private_posts for the relevant post type. Fail closed
                    // on unexpected values. Denylists are the wrong shape here:
                    // `status=any` and typo'd status names slip past denylists
                    // but are caught cleanly by the public-status allowlist.
                    $public_statuses = get_post_stati(['public' => true]);
                    if (!in_array($requested_status, $public_statuses, true)) {
                        $pto_for_caps = !empty($args['post_type']) ? get_post_type_object(sanitize_text_field($args['post_type'])) : get_post_type_object('post');
                        $needed_cap = $pto_for_caps && !empty($pto_for_caps->cap->read_private_posts)
                            ? $pto_for_caps->cap->read_private_posts
                            : 'read_private_posts';
                        if (!current_user_can($needed_cap)) {
                            throw new \Exception('You do not have permission to list ' . esc_html($requested_status) . ' posts.');
                        }
                    }
                    $query_args['post_status'] = $requested_status;
                }
                $posts = get_posts($query_args);
                return array_map(function($p) {
                    return [
                        'id' => $p->ID,
                        'title' => $p->post_title,
                        'excerpt' => wp_trim_words($p->post_content, 50),
                        'status' => $p->post_status,
                        'type' => $p->post_type,
                        'url' => get_permalink($p),
                        'date' => $p->post_date,
                        'content_length' => strlen((string) $p->post_content),
                    ];
                }, $posts);

            case 'wp_get_post':
                $post = get_post(self::resolve_post_id_arg($args));
                if (!$post) throw new \Exception('Post not found');
                // per-post read check. read_post via map_meta_cap
                // resolves to read_private_posts for non-public statuses.
                if (!current_user_can('read_post', $post->ID)) {
                    throw new \Exception('You do not have permission to read this post.');
                }
                return [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'content' => $post->post_content,
                    'excerpt' => $post->post_excerpt,
                    'status' => $post->post_status,
                    'type' => $post->post_type,
                    'url' => get_permalink($post),
                    'date' => $post->post_date,
                    'modified' => $post->post_modified,
                    'author' => get_the_author_meta('display_name', $post->post_author),
                ];

            case 'wp_create_post':
                $post_type = sanitize_text_field($args['post_type'] ?? 'post');
                $pto = get_post_type_object($post_type);
                // Same relaxation as wp_get_posts — accept viewable types OR
                // non-public types the caller can edit. The per-PT edit cap
                // check on the next block still enforces the actual create
                // permission (edit_posts / edit_pages / custom).
                if (!$pto || (!is_post_type_viewable($pto) && !current_user_can($pto->cap->edit_posts))) {
                    throw new \Exception('Invalid or non-accessible post type: ' . esc_html($post_type));
                }
                // Per-post-type edit + publish capability gate. Without it, a
                // Subscriber-level OAuth token could create-as-self at
                // status=publish. The per-PT cap object maps `edit_posts` to
                // the correct cap for custom post types (e.g. `edit_pages`).
                $create_cap = !empty($pto->cap->edit_posts) ? $pto->cap->edit_posts : 'edit_posts';
                if (!current_user_can($create_cap)) {
                    throw new \Exception('You do not have permission to create ' . esc_html($post_type) . ' posts.');
                }
                $requested_status = isset($args['status']) ? sanitize_text_field($args['status']) : 'draft';
                // publish_posts cap now gates future + private in
                // addition to publish. When the enum expanded from
                // ['publish', 'draft'] to include future/pending/private the
                // pre-existing 'publish' check needed matching coverage: WP
                // core silently downgrades unauthorized future/private to
                // pending, which would surface as a confusing "why did my
                // scheduled post become pending" bug rather than a clear
                // permission error. pending stays uncapped — it's just an
                // unpublished proposal, no publish-tier trust required.
                if (in_array($requested_status, ['publish', 'future', 'private'], true)) {
                    $publish_cap = !empty($pto->cap->publish_posts) ? $pto->cap->publish_posts : 'publish_posts';
                    if (!current_user_can($publish_cap)) {
                        throw new \Exception('You do not have permission to publish ' . esc_html($post_type) . ' posts.');
                    }
                }
                // Pre-validate featured_media so we don't create an orphan post if the ID is bad.
                if (isset($args['featured_media']) && intval($args['featured_media']) > 0) {
                    $fm = get_post(intval($args['featured_media']));
                    if (!$fm || $fm->post_type !== 'attachment') throw new \Exception('featured_media attachment not found.');
                }
                // Pre-validate post_author so we don't create a post owned by a non-existent user.
                if (isset($args['post_author']) && intval($args['post_author']) > 0) {
                    if (!get_userdata(intval($args['post_author']))) {
                        throw new \Exception('post_author user ID not found.');
                    }
                }
                // Two-part shape required for Gutenberg block JSON round-trip.
                // (1) Do NOT wrap in wp_kses_post() here: WP's own content_save_pre
                //     filter inside wp_insert_post() runs wp_filter_post_kses for
                //     callers without `unfiltered_html` and is block-aware;
                //     pre-calling wp_kses_post() HTML-encodes block delimiters.
                // (2) wp_slash() the content: wp_insert_post() runs wp_unslash()
                //     internally, which would otherwise strip the literal
                //     backslashes inside escape sequences (`\n`, `&`) that
                //     per-block `style.css` depends on.
                // status allowlist expanded to match schema enum.
                // future/pending/private are all standard WP statuses that
                // wp_insert_post handles natively. future requires post_date to
                // be in the future or WP silently downgrades to publish with
                // that backdate — same behavior as wp-admin scheduling.
                $post_data = [
                    'post_title' => \Royal_MCP\MCP\Support\SafeText::field($args['title']),
                    'post_content' => wp_slash($args['content']),
                    'post_status' => in_array($args['status'] ?? 'draft', ['publish', 'draft', 'future', 'pending', 'private']) ? $args['status'] : 'draft',
                    'post_type' => $post_type,
                ];
                // wp_kses_post matches what wp_insert_post's excerpt_save_pre
                // filter would apply; sanitize_text_field flattened any <p>/<a>/<strong>
                // formatting that legitimately renders on category archives + RSS feeds.
                if (!empty($args['excerpt'])) $post_data['post_excerpt'] = wp_kses_post($args['excerpt']);
                // Guard against string input (an LLM passing categories=5 instead of [5])
                // — !empty is truthy for non-empty strings, and array_map on a string
                // throws a fatal TypeError. Normalize to array; if the caller passed
                // a bare number, wrap it.
                if (!empty($args['categories'])) {
                    $cats = is_array($args['categories']) ? $args['categories'] : [$args['categories']];
                    $post_data['post_category'] = array_map('intval', $cats);
                }
                if (isset($args['post_author']) && intval($args['post_author']) > 0) {
                    $post_data['post_author'] = intval($args['post_author']);
                }
                // scheduling support. Parse ISO-8601 in site TZ, derive
                // GMT from the same timestamp so the two fields never disagree.
                if (!empty($args['date'])) {
                    $ts = strtotime((string) $args['date']);
                    if (false === $ts) {
                        throw new \Exception('Invalid date: could not parse "' . esc_html((string) $args['date']) . '" as ISO 8601.');
                    }
                    $post_data['post_date'] = wp_date('Y-m-d H:i:s', $ts);
                    $post_data['post_date_gmt'] = gmdate('Y-m-d H:i:s', $ts);
                }
                $post_id = wp_insert_post($post_data);
                if (is_wp_error($post_id)) throw new \Exception(esc_html($post_id->get_error_message()));
                if (isset($args['featured_media'])) {
                    $this->apply_featured_media($post_id, intval($args['featured_media']));
                }
                return ['id' => $post_id, 'message' => ucfirst($post_type) . ' created successfully', 'url' => get_permalink($post_id)];

            case 'wp_update_post':
                $post_id = self::resolve_post_id_arg($args);
                // object-level edit_post resolves to edit_others_posts
                // when the target isn't owned by the current user, and to the
                // PT-specific cap (edit_page etc.) automatically via map_meta_cap.
                $up_existing_post = $post_id > 0 ? get_post($post_id) : null;
                if (!$up_existing_post) throw new \Exception('Post not found.');
                if (!current_user_can('edit_post', $post_id)) {
                    throw new \Exception('You do not have permission to edit this post.');
                }
                // Pre-validate featured_media before mutating the post.
                if (isset($args['featured_media']) && intval($args['featured_media']) > 0) {
                    $fm = get_post(intval($args['featured_media']));
                    if (!$fm || $fm->post_type !== 'attachment') throw new \Exception('featured_media attachment not found.');
                }
                // Pre-validate post_author before mutating the post.
                if (isset($args['post_author']) && intval($args['post_author']) > 0) {
                    if (!get_userdata(intval($args['post_author']))) {
                        throw new \Exception('post_author user ID not found.');
                    }
                }
                // Pre-validate post_parent: fail loudly on unknown parent rather
                // than let WP silently coerce to 0.
                if (isset($args['post_parent']) && intval($args['post_parent']) > 0) {
                    if (!get_post(intval($args['post_parent']))) {
                        throw new \Exception('post_parent post ID not found.');
                    }
                }
                // Validate comment_status / ping_status enums up-front.
                if (array_key_exists('comment_status', $args) && !in_array($args['comment_status'], ['open', 'closed'], true)) {
                    throw new \Exception('comment_status must be "open" or "closed".');
                }
                if (array_key_exists('ping_status', $args) && !in_array($args['ping_status'], ['open', 'closed'], true)) {
                    throw new \Exception('ping_status must be "open" or "closed".');
                }

                // Snapshot BEFORE-state for undo — every field the caller might
                // touch, plus WC product_type term for the product-downgrade
                // guard below.
                $up_prior = [
                    'post_title'     => (string) $up_existing_post->post_title,
                    'post_content'   => (string) $up_existing_post->post_content,
                    'post_status'    => (string) $up_existing_post->post_status,
                    'post_excerpt'   => (string) $up_existing_post->post_excerpt,
                    'post_author'    => (int)    $up_existing_post->post_author,
                    'menu_order'     => (int)    $up_existing_post->menu_order,
                    'post_parent'    => (int)    $up_existing_post->post_parent,
                    'post_password'  => (string) $up_existing_post->post_password,
                    'comment_status' => (string) $up_existing_post->comment_status,
                    'ping_status'    => (string) $up_existing_post->ping_status,
                    'post_date'      => (string) $up_existing_post->post_date,
                    'post_date_gmt'  => (string) $up_existing_post->post_date_gmt,
                ];
                // WC product-type preservation. sandrinne #30: generic
                // wp_update_post on a product silently downgrades product_type
                // to 'simple' because WC's save_post handler reads
                // $_POST['product-type'] and falls back to 'simple' when the
                // request context isn't the WC admin form. Snapshot the term
                // BEFORE the write so we can detect + restore drift.
                $up_is_product = ( $up_existing_post->post_type === 'product' );
                $up_prior_product_type = null;
                $up_prior_featured_id  = null;
                if ( $up_is_product ) {
                    $terms = wp_get_object_terms( $post_id, 'product_type', [ 'fields' => 'slugs' ] );
                    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                        $up_prior_product_type = (string) $terms[0];
                    }
                }
                // Featured media snapshot (post thumbnail id) — undo restores
                // when the caller explicitly changed it.
                if ( array_key_exists( 'featured_media', $args ) ) {
                    $up_prior_featured_id = (int) get_post_thumbnail_id( $post_id );
                }

                $data = ['ID' => $post_id];
                // empty-string-as-omit on text fields. AI drivers
                // sometimes template-fill optional text args with "" instead
                // of omitting; treating "" as "preserve" prevents silent
                // destructive writes (blanked post body, title, excerpt) on
                // what reads as a partial update. To explicitly clear a
                // field, edit via wp-admin or future dedicated clear tool.
                if (isset($args['title']) && $args['title'] !== '') $data['post_title'] = \Royal_MCP\MCP\Support\SafeText::field($args['title']);
                // See wp_create_post above for the wp_slash + no-wp_kses_post rationale.
                if (isset($args['content']) && $args['content'] !== '') $data['post_content'] = wp_slash($args['content']);
                if (isset($args['status'])) $data['post_status'] = sanitize_text_field($args['status']);
                // see wp_create_post above: preserve safe HTML in excerpts.
                if (isset($args['excerpt']) && $args['excerpt'] !== '') $data['post_excerpt'] = wp_kses_post($args['excerpt']);
                if (isset($args['post_author']) && intval($args['post_author']) > 0) {
                    $data['post_author'] = intval($args['post_author']);
                }
                if (array_key_exists('menu_order', $args)) $data['menu_order'] = intval($args['menu_order']);
                if (array_key_exists('post_parent', $args)) $data['post_parent'] = intval($args['post_parent']);
                if (array_key_exists('password', $args)) $data['post_password'] = (string) $args['password'];
                if (array_key_exists('comment_status', $args)) $data['comment_status'] = $args['comment_status'];
                if (array_key_exists('ping_status', $args)) $data['ping_status'] = $args['ping_status'];
                // scheduling support on the update path. edit_date=true
                // is REQUIRED on wp_update_post() to actually change post_date on
                // an existing post; without it WP silently ignores post_date args.
                if (!empty($args['date'])) {
                    $ts = strtotime((string) $args['date']);
                    if (false === $ts) {
                        throw new \Exception('Invalid date: could not parse "' . esc_html((string) $args['date']) . '" as ISO 8601.');
                    }
                    $data['post_date'] = wp_date('Y-m-d H:i:s', $ts);
                    $data['post_date_gmt'] = gmdate('Y-m-d H:i:s', $ts);
                    $data['edit_date'] = true;
                }
                $result = wp_update_post($data);
                if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
                if (isset($args['featured_media'])) {
                    $this->apply_featured_media($post_id, intval($args['featured_media']));
                }

                // Product-type restore — if the target is a product AND the
                // caller did NOT explicitly request a product_type change
                // (we don't accept product_type as an arg, so always true
                // for now) AND the term drifted, restore it. Track whether
                // we restored so the response + undo summary reflect it.
                $up_product_type_restored = false;
                if ( $up_is_product && $up_prior_product_type !== null ) {
                    $after_terms = wp_get_object_terms( $post_id, 'product_type', [ 'fields' => 'slugs' ] );
                    $up_after_product_type = ( ! is_wp_error( $after_terms ) && ! empty( $after_terms ) ) ? (string) $after_terms[0] : null;
                    if ( $up_after_product_type !== $up_prior_product_type ) {
                        wp_set_object_terms( $post_id, [ $up_prior_product_type ], 'product_type', false );
                        clean_post_cache( $post_id );
                        $up_product_type_restored = true;
                    }
                }

                // Build response — reuse existing legacy helper for saved_fields
                // shape, then wrap in envelope + attach undo token.
                $up_legacy = self::build_update_response($post_id, $args, $data, 'Post updated successfully');

                $up_undo_pre = [
                    'prior_values'      => $up_prior,
                    'is_product'        => $up_is_product,
                ];
                if ( $up_prior_product_type !== null ) {
                    $up_undo_pre['prior_product_type'] = $up_prior_product_type;
                }
                if ( array_key_exists( 'featured_media', $args ) ) {
                    $up_undo_pre['prior_featured_id'] = (int) $up_prior_featured_id;
                }
                $up_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                    'op'      => 'wp_update_post',
                    'summary' => sprintf( 'Restore post %d to prior state (%d field(s)%s).',
                        $post_id,
                        count( $up_prior ),
                        $up_prior_product_type !== null ? ', WC product_type snapshotted' : ''
                    ),
                    'target'  => [ 'post_id' => $post_id, 'post_type' => (string) $up_existing_post->post_type ],
                    'pre_op_state' => $up_undo_pre,
                ]);

                $up_struct = array_merge( $up_legacy, [
                    'post_type'                => (string) $up_existing_post->post_type,
                    'product_type_restored'    => $up_product_type_restored,
                ] );
                if ( $up_product_type_restored ) {
                    $up_struct['product_type_note'] = sprintf(
                        'Detected WC save_post downgrade of product_type. Restored to prior value "%s". If the intended write was a real type change, use WC-native tools instead.',
                        $up_prior_product_type
                    );
                }
                return \Royal_MCP\MCP\Support\Envelope::success(
                    sprintf( 'Updated post %d (%s)%s, undo available.',
                        $post_id,
                        (string) $up_existing_post->post_type,
                        $up_product_type_restored ? ' (WC product_type restored from silent-downgrade)' : ''
                    ),
                    $up_struct,
                    $up_undo_envelope
                );

            case 'wp_replace_in_post':
                $post_id = self::resolve_post_id_arg($args);
                if ($post_id <= 0 || !get_post($post_id)) throw new \Exception('Post not found.');
                return self::replace_in_post_content($post_id, $args, 'post');

            case 'wp_delete_post':
                $dp_pid = self::resolve_post_id_arg($args);
                if ($dp_pid <= 0) throw new \Exception('Post not found.');
                // cap check BEFORE existence check so an unauthorized
                // caller probing nonexistent IDs gets "permission" rather than
                // "not found" (don't leak existence to non-deleters).
                if (!current_user_can('delete_post', $dp_pid)) {
                    throw new \Exception('You do not have permission to delete this post.');
                }
                $dp_existing = get_post($dp_pid);
                if (!$dp_existing) throw new \Exception('Post not found.');
                $dp_force = !empty($args['force']);

                // Snapshot BEFORE. For soft trash (force=false), we just need
                // the post_id to untrash later — WP moves the row to trash
                // status and preserves everything else. For force (hard delete)
                // we capture the full post + all postmeta + all term
                // relationships so wp_insert_post + replay can rebuild.
                // NOTE: recreated post gets a NEW ID (auto-increment); undo
                // summary flags this since downstream references to the old
                // ID (in _elementor_data, ACF post refs, permalinks bookmarked
                // by users, etc.) won't repoint automatically.
                $dp_full = null;
                if ($dp_force) {
                    $dp_meta_raw = get_post_meta($dp_pid);  // [key => [val, val, ...]]
                    $dp_meta = [];
                    foreach ($dp_meta_raw as $mk => $mvals) {
                        // meta values come back serialized-string from get_post_meta;
                        // maybe_unserialize each one so add_post_meta on restore
                        // stores them the same shape as before.
                        $dp_meta[$mk] = array_map('maybe_unserialize', (array) $mvals);
                    }
                    // Term relationships per taxonomy the post_type supports.
                    $dp_taxonomies = get_object_taxonomies($dp_existing->post_type);
                    $dp_terms = [];
                    foreach ($dp_taxonomies as $tax) {
                        $slugs = wp_get_object_terms($dp_pid, $tax, [ 'fields' => 'slugs' ]);
                        if (!is_wp_error($slugs) && !empty($slugs)) {
                            $dp_terms[$tax] = array_values($slugs);
                        }
                    }
                    $dp_full = [
                        'post_type'      => (string) $dp_existing->post_type,
                        'post_title'     => (string) $dp_existing->post_title,
                        'post_content'   => (string) $dp_existing->post_content,
                        'post_excerpt'   => (string) $dp_existing->post_excerpt,
                        'post_status'    => (string) $dp_existing->post_status,
                        'post_name'      => (string) $dp_existing->post_name,
                        'post_author'    => (int)    $dp_existing->post_author,
                        'post_parent'    => (int)    $dp_existing->post_parent,
                        'menu_order'     => (int)    $dp_existing->menu_order,
                        'post_password'  => (string) $dp_existing->post_password,
                        'comment_status' => (string) $dp_existing->comment_status,
                        'ping_status'    => (string) $dp_existing->ping_status,
                        'post_date'      => (string) $dp_existing->post_date,
                        'post_date_gmt'  => (string) $dp_existing->post_date_gmt,
                        'post_mime_type' => (string) $dp_existing->post_mime_type,
                        'meta'           => $dp_meta,
                        'terms'          => $dp_terms,
                    ];
                }

                $dp_result = wp_delete_post($dp_pid, $dp_force);
                if (!$dp_result) throw new \Exception('Failed to delete post');

                $dp_summary = '';
                $dp_undo_envelope = null;
                if ($dp_force) {
                    $dp_reverse_json = (string) wp_json_encode(['row' => $dp_full]);
                    if (strlen(gzcompress($dp_reverse_json, 9)) > 1024 * 1024) {
                        $dp_summary = sprintf('Force-deleted post %d (no undo: snapshot > 1MB).', $dp_pid);
                    } else {
                        $dp_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                            'op'      => 'wp_delete_post_force',
                            'summary' => sprintf('Recreate the force-deleted %s (post_id was %d, %d meta key(s), %d taxonomy relationships). Note: the new post ID will differ from the original — downstream references to the old ID (Elementor data, ACF post refs, permalinks) will not repoint.',
                                $dp_full['post_type'], $dp_pid, count($dp_full['meta']), count($dp_full['terms'])),
                            'target'  => [ 'original_post_id' => $dp_pid, 'post_type' => $dp_full['post_type'] ],
                            'pre_op_state' => [
                                'row' => $dp_full,
                            ],
                        ]);
                        $dp_summary = sprintf('Force-deleted post %d (%s), undo available (recreates with new ID).', $dp_pid, $dp_full['post_type']);
                    }
                } else {
                    // Soft trash — undo via wp_untrash_post
                    $dp_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                        'op'      => 'wp_delete_post_trash',
                        'summary' => sprintf('Untrash post %d.', $dp_pid),
                        'target'  => [ 'post_id' => $dp_pid ],
                    ]);
                    $dp_summary = sprintf('Trashed post %d, undo available (untrash).', $dp_pid);
                }
                return \Royal_MCP\MCP\Support\Envelope::success(
                    $dp_summary,
                    [
                        'id'              => $dp_pid,
                        'deleted'         => true,
                        'force'           => $dp_force,
                        'undo_available'  => $dp_undo_envelope !== null,
                    ],
                    $dp_undo_envelope
                );

            case 'wp_count_posts':
                if (!current_user_can('read')) {
                    throw new \Exception('You do not have permission to view post counts.');
                }
                $type = sanitize_text_field($args['post_type'] ?? 'post');
                $counts = wp_count_posts($type);
                return (array) $counts;

            case 'wp_get_post_types':
                if (!current_user_can('read')) {
                    throw new \Exception('You do not have permission to list post types.');
                }
                $types = get_post_types(['public' => true], 'objects');
                return array_values(array_map(function($pt) {
                    return [
                        'name' => $pt->name,
                        'label' => $pt->label,
                        'description' => $pt->description,
                        'hierarchical' => $pt->hierarchical,
                        'has_archive' => (bool) $pt->has_archive,
                        'supports' => array_keys(array_filter(get_all_post_type_supports($pt->name))),
                    ];
                }, $types));

            case 'wp_get_taxonomies':
                if (!current_user_can('read')) {
                    throw new \Exception('You do not have permission to list taxonomies.');
                }
                $taxonomies = get_taxonomies(['public' => true], 'objects');
                return array_values(array_map(function($tax) {
                    // `slug` added as a clearer alias for the taxonomy
                    // identifier. WP_Taxonomy uses `name` for the slug for
                    // historical reasons, which surprises AI agents that
                    // expect a `slug` field on something called a "taxonomy".
                    // Both fields hold the same value; keep `name` for
                    // backward compat with anything already using it.
                    return [
                        'slug'         => $tax->name,
                        'name'         => $tax->name,
                        'label'        => $tax->label,
                        'description'  => $tax->description,
                        'hierarchical' => (bool) $tax->hierarchical,
                        'object_type'  => array_values((array) $tax->object_type),
                        'show_in_rest' => (bool) $tax->show_in_rest,
                    ];
                }, $taxonomies));

            // ==================== PAGES ====================
            case 'wp_get_pages':
                if (!current_user_can('read')) {
                    throw new \Exception('You do not have permission to list pages.');
                }
                $page_args = ['number' => min(intval($args['per_page'] ?? 10), 100)];
                if (!empty($args['parent'])) $page_args['parent'] = intval($args['parent']);
                $pages = get_pages($page_args);
                return array_map(function($p) {
                    return [
                        'id' => $p->ID,
                        'title' => $p->post_title,
                        'url' => get_permalink($p),
                        'status' => $p->post_status,
                        'parent' => $p->post_parent,
                        'content_length' => strlen((string) $p->post_content),
                    ];
                }, $pages);

            case 'wp_get_page':
                $page = get_post(self::resolve_post_id_arg($args));
                if (!$page || $page->post_type !== 'page') throw new \Exception('Page not found');
                if (!current_user_can('read_post', $page->ID)) {
                    throw new \Exception('You do not have permission to read this page.');
                }
                return [
                    'id' => $page->ID,
                    'title' => $page->post_title,
                    'content' => $page->post_content,
                    'status' => $page->post_status,
                    'url' => get_permalink($page),
                    'parent' => $page->post_parent,
                ];

            case 'wp_create_page':
                if (!current_user_can('edit_pages')) {
                    throw new \Exception('You do not have permission to create pages.');
                }
                // status allowlist expanded to match schema enum (same as wp_create_post).
                $page_status = in_array($args['status'] ?? 'draft', ['publish', 'draft', 'future', 'pending', 'private']) ? $args['status'] : 'draft';
                // publish_pages cap gates future + private too. See wp_create_post for rationale.
                if (in_array($page_status, ['publish', 'future', 'private'], true) && !current_user_can('publish_pages')) {
                    throw new \Exception('You do not have permission to publish pages.');
                }
                // See wp_create_post above for the wp_slash + no-wp_kses_post rationale.
                $page_data = [
                    'post_title' => \Royal_MCP\MCP\Support\SafeText::field($args['title']),
                    'post_content' => wp_slash($args['content']),
                    'post_status' => $page_status,
                    'post_type' => 'page',
                ];
                if (!empty($args['parent'])) $page_data['post_parent'] = intval($args['parent']);
                // scheduling support. See wp_create_post handler for rationale.
                if (!empty($args['date'])) {
                    $ts = strtotime((string) $args['date']);
                    if (false === $ts) {
                        throw new \Exception('Invalid date: could not parse "' . esc_html((string) $args['date']) . '" as ISO 8601.');
                    }
                    $page_data['post_date'] = wp_date('Y-m-d H:i:s', $ts);
                    $page_data['post_date_gmt'] = gmdate('Y-m-d H:i:s', $ts);
                }
                $page_id = wp_insert_post($page_data);
                if (is_wp_error($page_id)) throw new \Exception(esc_html($page_id->get_error_message()));
                return ['id' => $page_id, 'message' => 'Page created successfully', 'url' => get_permalink($page_id)];

            case 'wp_update_page':
                $page_id = self::resolve_post_id_arg($args);
                $existing_page = $page_id > 0 ? get_post($page_id) : null;
                if (!$existing_page || $existing_page->post_type !== 'page') throw new \Exception('Page not found.');
                if (!current_user_can('edit_post', $page_id)) {
                    throw new \Exception('You do not have permission to edit this page.');
                }
                // pre-validate post_author (new field).
                if (isset($args['post_author']) && intval($args['post_author']) > 0) {
                    if (!get_userdata(intval($args['post_author']))) {
                        throw new \Exception('post_author user ID not found.');
                    }
                }
                // pre-validate post_parent (new field).
                if (isset($args['post_parent']) && intval($args['post_parent']) > 0) {
                    if (!get_post(intval($args['post_parent']))) {
                        throw new \Exception('post_parent page ID not found.');
                    }
                }
                $data = ['ID' => $page_id];
                // see wp_update_post: "" preserves existing value.
                if (isset($args['title']) && $args['title'] !== '') $data['post_title'] = \Royal_MCP\MCP\Support\SafeText::field($args['title']);
                // See wp_create_post above for the wp_slash + no-wp_kses_post rationale.
                if (isset($args['content']) && $args['content'] !== '') $data['post_content'] = wp_slash($args['content']);
                if (isset($args['status'])) $data['post_status'] = sanitize_text_field($args['status']);
                if (isset($args['excerpt']) && $args['excerpt'] !== '') $data['post_excerpt'] = wp_kses_post($args['excerpt']);
                if (isset($args['post_author']) && intval($args['post_author']) > 0) {
                    $data['post_author'] = intval($args['post_author']);
                }
                if (array_key_exists('menu_order', $args)) $data['menu_order'] = intval($args['menu_order']);
                if (array_key_exists('post_parent', $args)) $data['post_parent'] = intval($args['post_parent']);
                if (array_key_exists('password', $args)) $data['post_password'] = (string) $args['password'];
                // scheduling / backdating support on the update path. See wp_update_post handler.
                if (!empty($args['date'])) {
                    $ts = strtotime((string) $args['date']);
                    if (false === $ts) {
                        throw new \Exception('Invalid date: could not parse "' . esc_html((string) $args['date']) . '" as ISO 8601.');
                    }
                    $data['post_date'] = wp_date('Y-m-d H:i:s', $ts);
                    $data['post_date_gmt'] = gmdate('Y-m-d H:i:s', $ts);
                    $data['edit_date'] = true;
                }
                $result = wp_update_post($data);
                if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
                return self::build_update_response($page_id, $args, $data, 'Page updated successfully');

            case 'wp_replace_in_page':
                $page_id = self::resolve_post_id_arg($args);
                $existing_page = $page_id > 0 ? get_post($page_id) : null;
                if (!$existing_page || $existing_page->post_type !== 'page') throw new \Exception('Page not found.');
                return self::replace_in_post_content($page_id, $args, 'page');

            case 'wp_delete_page':
                $page_id = self::resolve_post_id_arg($args);
                $existing_page = $page_id > 0 ? get_post($page_id) : null;
                if (!$existing_page || $existing_page->post_type !== 'page') throw new \Exception('Page not found.');
                if (!current_user_can('delete_post', $page_id)) {
                    throw new \Exception('You do not have permission to delete this page.');
                }
                $force = !empty($args['force']);
                $result = wp_delete_post($page_id, $force);
                if (!$result) throw new \Exception('Failed to delete page');
                return ['message' => $force ? 'Page permanently deleted' : 'Page moved to trash'];

            // ==================== MEDIA ====================
            case 'wp_get_media':
                if (!current_user_can('upload_files')) {
                    throw new \Exception('You do not have permission to view the media library.');
                }
                $media_args = [
                    'post_type' => 'attachment',
                    'numberposts' => min(intval($args['per_page'] ?? 10), 100),
                    'offset' => max(0, intval($args['offset'] ?? 0)),
                    'post_status' => 'inherit',
                ];
                if (!empty($args['mime_type'])) $media_args['post_mime_type'] = sanitize_text_field($args['mime_type']);

                // alt_text filter — empty/present/any. Enables alt-audit
                // workflow on image-led sites without pulling every row and
                // filtering client-side. Sits on the main query's meta_query
                // slot; composes with post__in from the search branch below via
                // WP_Query's implicit AND between post__in and meta_query.
                $alt_filter = isset($args['alt_text']) ? \Royal_MCP\MCP\Support\SafeText::field($args['alt_text']) : 'any';
                if ($alt_filter === 'empty') {
                    $media_args['meta_query'] = [
                        'relation' => 'OR',
                        ['key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS'],
                        ['key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '='],
                    ];
                } elseif ($alt_filter === 'present') {
                    $media_args['meta_query'] = [
                        ['key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '!='],
                    ];
                }

                $media_search = \Royal_MCP\MCP\Support\SafeText::field($args['search'] ?? '');
                if ($media_search !== '') {
                    // WP_Query's `s` does not reach attachment filenames or alt
                    // text, and a keyword search cannot be OR'd against a
                    // meta_query inside one WP_Query. Run the meta side (alt
                    // text + filename) and the keyword side (title/content) as
                    // separate ID-only lookups and merge. WP_Meta_Query applies
                    // $wpdb->esc_like() to LIKE values, so % and _ in the search
                    // term are matched literally, not as wildcards.
                    $media_limit = $media_args['numberposts'];
                    $meta_ids = get_posts([
                        'post_type'      => 'attachment',
                        'post_status'    => 'inherit',
                        'posts_per_page' => $media_limit,
                        'fields'         => 'ids',
                        'meta_query'     => [
                            'relation' => 'OR',
                            [
                                'key'     => '_wp_attachment_image_alt',
                                'value'   => $media_search,
                                'compare' => 'LIKE',
                            ],
                            [
                                'key'     => '_wp_attached_file',
                                'value'   => $media_search,
                                'compare' => 'LIKE',
                            ],
                        ],
                    ]);
                    $title_ids = get_posts([
                        'post_type'      => 'attachment',
                        'post_status'    => 'inherit',
                        'posts_per_page' => $media_limit,
                        'fields'         => 'ids',
                        's'              => $media_search,
                    ]);
                    $media_ids = array_slice(array_unique(array_merge($title_ids, $meta_ids)), 0, $media_limit);
                    if (empty($media_ids)) return [];
                    // post__in intersects with post_mime_type + meta_query in
                    // the main query, so search composes with mime_type + alt
                    // filters via WP_Query's AND semantics.
                    $media_args['post__in'] = $media_ids;
                    $media_args['orderby']  = 'post__in';
                }
                $media = get_posts($media_args);
                return array_map(function($m) {
                    return [
                        'id' => $m->ID,
                        'title' => $m->post_title,
                        'url' => wp_get_attachment_url($m->ID),
                        'mime_type' => $m->post_mime_type,
                        'alt' => get_post_meta($m->ID, '_wp_attachment_image_alt', true),
                    ];
                }, $media);

            case 'wp_get_media_item':
                $media = get_post(self::resolve_post_id_arg($args));
                if (!$media || $media->post_type !== 'attachment') throw new \Exception('Media not found');
                if (!current_user_can('read_post', $media->ID)) {
                    throw new \Exception('You do not have permission to read this media item.');
                }
                return [
                    'id' => $media->ID,
                    'title' => $media->post_title,
                    'url' => wp_get_attachment_url($media->ID),
                    'mime_type' => $media->post_mime_type,
                    'alt' => get_post_meta($media->ID, '_wp_attachment_image_alt', true),
                    'caption' => $media->post_excerpt,
                    'description' => $media->post_content,
                ];

            case 'wp_upload_media_from_url':
                if (!current_user_can('upload_files')) {
                    throw new \Exception('You do not have permission to upload files.');
                }
                $url = isset($args['url']) ? esc_url_raw(trim($args['url'])) : '';
                if (empty($url)) throw new \Exception('A url is required.');
                $attachment_id = $this->sideload_image_from_url(
                    $url,
                    isset($args['filename']) ? sanitize_file_name($args['filename']) : '',
                    isset($args['title']) ? \Royal_MCP\MCP\Support\SafeText::field($args['title']) : '',
                    isset($args['caption']) ? \Royal_MCP\MCP\Support\SafeText::field($args['caption']) : '',
                    isset($args['alt_text']) ? \Royal_MCP\MCP\Support\SafeText::field($args['alt_text']) : ''
                );
                return [
                    'id' => $attachment_id,
                    'url' => wp_get_attachment_url($attachment_id),
                    'message' => 'Image uploaded to media library.',
                ];

            case 'wp_upload_media':
                if (!current_user_can('upload_files')) {
                    throw new \Exception('You do not have permission to upload files.');
                }
                $filename = isset($args['filename']) ? sanitize_file_name($args['filename']) : '';
                $b64      = isset($args['content_base64']) ? (string) $args['content_base64'] : '';
                if (empty($filename) || empty($b64)) {
                    throw new \Exception('filename and content_base64 are required.');
                }
                // Strip data-URL prefix if present.
                if (strpos($b64, 'base64,') !== false) {
                    $b64 = substr($b64, strpos($b64, 'base64,') + 7);
                }
                $bytes = base64_decode($b64, true);
                if ($bytes === false) throw new \Exception('content_base64 is not valid base64.');
                $attachment_id = $this->sideload_image_from_bytes(
                    $bytes,
                    $filename,
                    isset($args['title']) ? \Royal_MCP\MCP\Support\SafeText::field($args['title']) : '',
                    isset($args['caption']) ? \Royal_MCP\MCP\Support\SafeText::field($args['caption']) : '',
                    isset($args['alt_text']) ? \Royal_MCP\MCP\Support\SafeText::field($args['alt_text']) : ''
                );
                return [
                    'id' => $attachment_id,
                    'url' => wp_get_attachment_url($attachment_id),
                    'message' => 'Image uploaded to media library.',
                ];

            case 'wp_set_featured_image':
                $post_id = intval($args['post_id'] ?? 0);
                if ($post_id <= 0 || !get_post($post_id)) throw new \Exception('Post not found.');
                if (!current_user_can('edit_post', $post_id)) {
                    throw new \Exception('You do not have permission to edit this post.');
                }
                // Smart dispatcher: image_url takes precedence when present.
                if (!empty($args['image_url'])) {
                    if (!current_user_can('upload_files')) {
                        throw new \Exception('You do not have permission to upload files.');
                    }
                    $media_id = $this->sideload_image_from_url(
                        esc_url_raw(trim($args['image_url'])),
                        '',
                        '',
                        '',
                        isset($args['alt_text']) ? \Royal_MCP\MCP\Support\SafeText::field($args['alt_text']) : ''
                    );
                } else {
                    $media_id = isset($args['media_id']) ? intval($args['media_id']) : -1;
                    if ($media_id < 0) throw new \Exception('Provide either media_id or image_url.');
                }
                $this->apply_featured_media($post_id, $media_id);
                return [
                    'post_id'  => $post_id,
                    'media_id' => $media_id,
                    'url'      => $media_id > 0 ? wp_get_attachment_url($media_id) : null,
                    'message'  => $media_id > 0 ? 'Featured image set.' : 'Featured image removed.',
                ];

            case 'wp_update_media':
                $media_id = self::resolve_post_id_arg($args);
                $media = $media_id > 0 ? get_post($media_id) : null;
                if (!$media || $media->post_type !== 'attachment') throw new \Exception('Media not found.');
                if (!current_user_can('edit_post', $media_id)) {
                    throw new \Exception('You do not have permission to edit this media item.');
                }

                // Requested-field extraction. Field-key convention matches the
                // MCP tool arg names (title/caption/description/alt_text), not
                // the underlying wp_posts column names — LLM callers see the
                // API they invoked, not the DB schema.
                $media_requested = [];
                if (isset($args['title']) && $args['title'] !== '')             $media_requested['title']       = \Royal_MCP\MCP\Support\SafeText::field($args['title']);
                if (isset($args['caption']) && $args['caption'] !== '')         $media_requested['caption']     = \Royal_MCP\MCP\Support\SafeText::field($args['caption']);
                if (isset($args['description']) && $args['description'] !== '') $media_requested['description'] = wp_kses_post($args['description']);
                if (isset($args['alt_text']) && $args['alt_text'] !== '')       $media_requested['alt_text']    = \Royal_MCP\MCP\Support\SafeText::field($args['alt_text']);
                if (empty($media_requested)) {
                    throw new \Exception('No update fields provided. Pass at least one of: title, caption, description, alt_text.');
                }

                // Snapshot BEFORE-state for the requested fields only.
                $media_before = [];
                foreach ( array_keys( $media_requested ) as $mfield ) {
                    switch ( $mfield ) {
                        case 'title':       $media_before[$mfield] = (string) $media->post_title;   break;
                        case 'caption':     $media_before[$mfield] = (string) $media->post_excerpt; break;
                        case 'description': $media_before[$mfield] = (string) $media->post_content; break;
                        case 'alt_text':    $media_before[$mfield] = (string) get_post_meta($media_id, '_wp_attachment_image_alt', true); break;
                    }
                }

                // Execute the write. Post-column update first (batched), then alt.
                $media_update = ['ID' => $media_id];
                if ( isset($media_requested['title']) )       $media_update['post_title']   = $media_requested['title'];
                if ( isset($media_requested['caption']) )     $media_update['post_excerpt'] = $media_requested['caption'];
                if ( isset($media_requested['description']) ) $media_update['post_content'] = $media_requested['description'];
                if ( count($media_update) > 1 ) {
                    $mres = wp_update_post($media_update, true);
                    if (is_wp_error($mres)) throw new \Exception(esc_html($mres->get_error_message()));
                }
                if ( isset($media_requested['alt_text']) ) {
                    update_post_meta($media_id, '_wp_attachment_image_alt', $media_requested['alt_text']);
                }
                clean_post_cache($media_id);
                wp_cache_delete($media_id, 'post_meta');

                // Re-read AFTER-state for the same requested fields.
                $media_fresh = get_post($media_id);
                $media_actual = [];
                foreach ( array_keys( $media_requested ) as $mfield ) {
                    switch ( $mfield ) {
                        case 'title':       $media_actual[$mfield] = (string) $media_fresh->post_title;   break;
                        case 'caption':     $media_actual[$mfield] = (string) $media_fresh->post_excerpt; break;
                        case 'description': $media_actual[$mfield] = (string) $media_fresh->post_content; break;
                        case 'alt_text':    $media_actual[$mfield] = (string) get_post_meta($media_id, '_wp_attachment_image_alt', true); break;
                    }
                }

                $media_diff = \Royal_MCP\MCP\Support\WriteVerifier::diff( $media_requested, $media_before, $media_actual );
                \Royal_MCP\MCP\Support\WriteVerifier::throw_if_dropped( $media_diff, 'wp_update_media' );

                $media_reverse_json     = (string) wp_json_encode( [ 'prior_values' => $media_before ] );
                $media_reverse_size_est = strlen( gzcompress( $media_reverse_json, 9 ) );
                $media_undo_envelope    = null;
                $media_warnings         = [];
                if ( $media_reverse_size_est > 1024 * 1024 ) {
                    $media_warnings[] = 'undo not available — prior values exceed 1MB storage cap. SiteVault snapshot recommended for reversal.';
                } else {
                    $media_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                        'op'      => 'wp_update_media',
                        'summary' => sprintf( 'Restore %d field(s) on media %d to prior values.', count( $media_before ), $media_id ),
                        'target'  => [ 'media_id' => $media_id ],
                        'pre_op_state' => [
                            'prior_values'   => $media_before,
                            'applied_values' => $media_actual,
                        ],
                    ]);
                }

                $media_struct = array_merge(
                    [
                        'id'      => $media_id,
                        'updated' => true,
                    ],
                    \Royal_MCP\MCP\Support\WriteVerifier::response_partial( $media_diff )
                );
                if ( ! empty( $media_warnings ) ) {
                    $media_struct['warnings'] = $media_warnings;
                }
                $media_summary = sprintf(
                    'Updated media %d (%d field(s) applied%s%s).',
                    $media_id,
                    count( $media_diff['applied'] ) + count( $media_diff['silent_modifies'] ),
                    ! empty( $media_diff['silent_modifies'] ) ? ', WP modified value' : '',
                    $media_undo_envelope !== null ? ', undo available' : ' (undo not available: prior values too large)'
                );
                return \Royal_MCP\MCP\Support\Envelope::success(
                    $media_summary,
                    $media_struct,
                    $media_undo_envelope
                );

            case 'wp_delete_media':
                $media_id = self::resolve_post_id_arg($args);
                $existing_media = $media_id > 0 ? get_post($media_id) : null;
                if (!$existing_media || $existing_media->post_type !== 'attachment') throw new \Exception('Media not found.');
                if (!current_user_can('delete_post', $media_id)) {
                    throw new \Exception('You do not have permission to delete this media item.');
                }
                $force = !empty($args['force']);
                $result = wp_delete_attachment($media_id, $force);
                if (!$result) throw new \Exception('Failed to delete media');
                return ['message' => 'Media deleted successfully'];

            case 'wp_count_media':
                if (!current_user_can('upload_files')) {
                    throw new \Exception('You do not have permission to view media library counts.');
                }
                $counts = wp_count_attachments();
                return (array) $counts;

            // ==================== CATEGORIES & TAGS ====================
            case 'wp_get_categories':
                if (!current_user_can('read')) {
                    throw new \Exception('You do not have permission to list categories.');
                }
                $cats = get_categories(['number' => min(intval($args['per_page'] ?? 100), 100), 'hide_empty' => false]);
                return array_map(function($c) {
                    return ['id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug, 'count' => $c->count, 'parent' => $c->parent];
                }, $cats);

            case 'wp_get_tags':
                if (!current_user_can('read')) {
                    throw new \Exception('You do not have permission to list tags.');
                }
                $tags = get_tags(['number' => min(intval($args['per_page'] ?? 100), 100), 'hide_empty' => false]);
                return array_map(function($t) {
                    return ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count];
                }, $tags ?: []);

            case 'wp_create_term':
                $taxonomy = sanitize_text_field($args['taxonomy']);
                if (!taxonomy_exists($taxonomy)) throw new \Exception('Unknown taxonomy: ' . esc_html($taxonomy) . '. Use wp_get_taxonomies to list available taxonomies.');
                $tax_obj = get_taxonomy($taxonomy);
                // per-taxonomy edit_terms cap (resolves to
                // manage_categories for the category taxonomy, manage_post_tags
                // for tags, custom caps for custom taxonomies).
                $edit_terms_cap = $tax_obj && !empty($tax_obj->cap->edit_terms) ? $tax_obj->cap->edit_terms : 'manage_categories';
                if (!current_user_can($edit_terms_cap)) {
                    throw new \Exception('You do not have permission to create terms in ' . esc_html($taxonomy) . '.');
                }
                $term_args = [];
                // wp_kses_post preserves the same safe HTML allow-list WP admin
                // permits in term descriptions; sanitize_text_field flattened links/formatting
                // that render on category-archive pages under WC / Yoast / theme templates.
                if (!empty($args['description'])) $term_args['description'] = wp_kses_post($args['description']);
                if (!empty($args['slug'])) $term_args['slug'] = sanitize_title($args['slug']);
                if (!empty($args['parent']) && $tax_obj && $tax_obj->hierarchical) $term_args['parent'] = intval($args['parent']);
                $result = wp_insert_term(\Royal_MCP\MCP\Support\SafeText::field($args['name']), $taxonomy, $term_args);
                if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
                return ['id' => $result['term_id'], 'taxonomy' => $taxonomy, 'message' => 'Term created successfully'];

            case 'wp_update_term':
                $taxonomy = sanitize_text_field($args['taxonomy']);
                if (!taxonomy_exists($taxonomy)) throw new \Exception('Unknown taxonomy: ' . esc_html($taxonomy) . '. Use wp_get_taxonomies to list available taxonomies.');
                $term_id = intval($args['id']);
                $term_obj = get_term($term_id, $taxonomy);
                if (!$term_obj || is_wp_error($term_obj)) throw new \Exception('Term not found in taxonomy ' . esc_html($taxonomy));
                if (!current_user_can('edit_term', $term_id)) {
                    throw new \Exception('You do not have permission to edit this term.');
                }

                // Requested-field extraction. Keys mirror the MCP tool arg names.
                $term_requested = [];
                if (isset($args['name']) && $args['name'] !== '')               $term_requested['name']        = \Royal_MCP\MCP\Support\SafeText::field($args['name']);
                if (isset($args['slug']) && $args['slug'] !== '')               $term_requested['slug']        = sanitize_title($args['slug']);
                if (isset($args['description']) && $args['description'] !== '') $term_requested['description'] = wp_kses_post($args['description']);
                if (isset($args['parent'])) {
                    $term_tax_obj = get_taxonomy($taxonomy);
                    if ($term_tax_obj && $term_tax_obj->hierarchical) $term_requested['parent'] = intval($args['parent']);
                }
                if (empty($term_requested)) throw new \Exception('No update fields provided. Pass at least one of: name, slug, description, parent.');

                // Snapshot BEFORE-state for requested fields.
                $term_before = [];
                foreach ( array_keys( $term_requested ) as $tfield ) {
                    switch ( $tfield ) {
                        case 'name':        $term_before[$tfield] = (string) $term_obj->name;        break;
                        case 'slug':        $term_before[$tfield] = (string) $term_obj->slug;        break;
                        case 'description': $term_before[$tfield] = (string) $term_obj->description; break;
                        case 'parent':      $term_before[$tfield] = (int)    $term_obj->parent;      break;
                    }
                }

                // Execute — map tool-arg names back to wp_update_term keys (identical here).
                $term_result = wp_update_term( $term_id, $taxonomy, $term_requested );
                if (is_wp_error($term_result)) throw new \Exception(esc_html($term_result->get_error_message()));

                // Cache invalidate + re-read.
                clean_term_cache( $term_id, $taxonomy );
                $term_fresh = get_term( $term_id, $taxonomy );
                $term_actual = [];
                foreach ( array_keys( $term_requested ) as $tfield ) {
                    switch ( $tfield ) {
                        case 'name':        $term_actual[$tfield] = (string) $term_fresh->name;        break;
                        case 'slug':        $term_actual[$tfield] = (string) $term_fresh->slug;        break;
                        case 'description': $term_actual[$tfield] = (string) $term_fresh->description; break;
                        case 'parent':      $term_actual[$tfield] = (int)    $term_fresh->parent;      break;
                    }
                }

                // Slug collisions hard-error (wp_update_term returns WP_Error → we
                // throw above). silent_modifies still catches WP's inline value
                // transforms: sanitize_title normalizing unicode/spaces in slugs,
                // parent=<n> being coerced to 0 when the requested parent no longer
                // exists, wp_kses_post stripping HTML the caller didn't realize
                // was disallowed, etc.
                $term_diff = \Royal_MCP\MCP\Support\WriteVerifier::diff( $term_requested, $term_before, $term_actual );
                \Royal_MCP\MCP\Support\WriteVerifier::throw_if_dropped( $term_diff, 'wp_update_term' );

                $term_reverse_json     = (string) wp_json_encode( [ 'prior_values' => $term_before ] );
                $term_reverse_size_est = strlen( gzcompress( $term_reverse_json, 9 ) );
                $term_undo_envelope    = null;
                $term_warnings         = [];
                if ( $term_reverse_size_est > 1024 * 1024 ) {
                    $term_warnings[] = 'undo not available — prior values exceed 1MB storage cap. SiteVault snapshot recommended for reversal.';
                } else {
                    $term_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                        'op'      => 'wp_update_term',
                        'summary' => sprintf( 'Restore %d field(s) on term %d (%s) to prior values.', count( $term_before ), $term_id, $taxonomy ),
                        'target'  => [ 'term_id' => $term_id, 'taxonomy' => $taxonomy ],
                        'pre_op_state' => [
                            'prior_values'   => $term_before,
                            'applied_values' => $term_actual,
                        ],
                    ]);
                }

                $term_struct = array_merge(
                    [
                        'id'       => $term_id,
                        'taxonomy' => $taxonomy,
                        'updated'  => true,
                    ],
                    \Royal_MCP\MCP\Support\WriteVerifier::response_partial( $term_diff )
                );
                if ( ! empty( $term_warnings ) ) {
                    $term_struct['warnings'] = $term_warnings;
                }
                $term_summary = sprintf(
                    'Updated term %d in %s (%d field(s) applied%s%s).',
                    $term_id,
                    $taxonomy,
                    count( $term_diff['applied'] ) + count( $term_diff['silent_modifies'] ),
                    ! empty( $term_diff['silent_modifies'] ) ? ', WP modified value (e.g. slug uniqueness suffix)' : '',
                    $term_undo_envelope !== null ? ', undo available' : ' (undo not available: prior values too large)'
                );
                return \Royal_MCP\MCP\Support\Envelope::success(
                    $term_summary,
                    $term_struct,
                    $term_undo_envelope
                );

            case 'wp_delete_term':
                $dt_tax = sanitize_text_field($args['taxonomy']);
                if (!taxonomy_exists($dt_tax)) throw new \Exception('Unknown taxonomy: ' . esc_html($dt_tax) . '. Use wp_get_taxonomies to list available taxonomies.');
                $dt_tid = intval($args['id']);
                $dt_term = get_term($dt_tid, $dt_tax);
                if (!$dt_term || is_wp_error($dt_term)) throw new \Exception('Term not found in taxonomy ' . esc_html($dt_tax));
                if (!current_user_can('delete_term', $dt_tid)) {
                    throw new \Exception('You do not have permission to delete this term.');
                }

                // Snapshot BEFORE. Terms have no trash — always immediate.
                // Capture the WP_Term fields + term_meta + count of object
                // relationships (informational only — undo recreates with a
                // NEW term_id so existing post→term relationships are lost).
                $dt_meta_raw = get_term_meta( $dt_tid );  // [key => [val, val, ...]]
                $dt_meta = [];
                foreach ( $dt_meta_raw as $mk => $mvals ) {
                    $dt_meta[ $mk ] = array_map( 'maybe_unserialize', (array) $mvals );
                }
                // count of objects using this term (posts, users — depends on
                // taxonomy's object_type). Informational for undo summary; NOT
                // restorable because recreated term gets new term_id.
                $dt_object_count = 0;
                $dt_object_types = get_taxonomy( $dt_tax )->object_type ?? [];
                foreach ( (array) $dt_object_types as $ot ) {
                    $objs = get_objects_in_term( $dt_tid, $dt_tax );
                    if ( ! is_wp_error( $objs ) ) {
                        $dt_object_count = count( $objs );
                        break;  // one taxonomy = one count
                    }
                }

                $dt_full = [
                    'taxonomy'    => $dt_tax,
                    'name'        => (string) $dt_term->name,
                    'slug'        => (string) $dt_term->slug,
                    'description' => (string) $dt_term->description,
                    'parent'      => (int)    $dt_term->parent,
                    'meta'        => $dt_meta,
                ];

                $dt_result = wp_delete_term( $dt_tid, $dt_tax );
                if (is_wp_error($dt_result)) throw new \Exception(esc_html($dt_result->get_error_message()));
                if (!$dt_result) throw new \Exception('Failed to delete term');

                // Undo envelope with 1MB cap
                $dt_undo_envelope = null;
                $dt_reverse_json  = (string) wp_json_encode( [ 'row' => $dt_full ] );
                if ( strlen( gzcompress( $dt_reverse_json, 9 ) ) > 1024 * 1024 ) {
                    // no undo — beyond storage cap
                } else {
                    $dt_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                        'op'      => 'wp_delete_term',
                        'summary' => sprintf( 'Recreate deleted term "%s" (slug: %s) in taxonomy %s. Note: new term_id will differ from original (%d); %d existing object→term relationship(s) will NOT be re-linked.',
                            $dt_full['name'], $dt_full['slug'], $dt_tax, $dt_tid, $dt_object_count ),
                        'target'  => [ 'original_term_id' => $dt_tid, 'taxonomy' => $dt_tax ],
                        'pre_op_state' => [
                            'row'                    => $dt_full,
                            'object_relations_count' => $dt_object_count,
                        ],
                    ]);
                }

                return \Royal_MCP\MCP\Support\Envelope::success(
                    sprintf( 'Deleted term %d (%s) from taxonomy %s%s.',
                        $dt_tid,
                        $dt_full['name'],
                        $dt_tax,
                        $dt_undo_envelope !== null ? ', undo available (new term_id + object relations NOT re-linked)' : ' (no undo: snapshot too large)'
                    ),
                    [
                        'id'                     => $dt_tid,
                        'taxonomy'               => $dt_tax,
                        'deleted'                => true,
                        'object_relations_count' => $dt_object_count,
                        'undo_available'         => $dt_undo_envelope !== null,
                    ],
                    $dt_undo_envelope
                );

            case 'wp_get_terms':
                if (!current_user_can('edit_posts')) {
                    throw new \Exception('You do not have permission to list terms.');
                }
                $taxonomy = sanitize_text_field($args['taxonomy'] ?? '');
                if ($taxonomy === '') throw new \Exception('A taxonomy slug is required.');
                if (!taxonomy_exists($taxonomy)) throw new \Exception('Unknown taxonomy: ' . esc_html($taxonomy) . '. Use wp_get_taxonomies to list available taxonomies.');
                $per_page = isset($args['per_page']) ? max(1, min(intval($args['per_page']), 500)) : 100;
                $page     = isset($args['page']) ? max(1, intval($args['page'])) : 1;
                $offset   = ($page - 1) * $per_page;
                $get_args = [
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => !empty($args['hide_empty']),
                    'number'     => $per_page,
                    'offset'     => $offset,
                    'orderby'    => 'name',
                    'order'      => 'ASC',
                ];
                if (!empty($args['search'])) {
                    $get_args['search'] = \Royal_MCP\MCP\Support\SafeText::field($args['search']);
                }
                if (isset($args['parent'])) {
                    $get_args['parent'] = intval($args['parent']);
                }
                $terms = get_terms($get_args);
                if (is_wp_error($terms)) throw new \Exception(esc_html($terms->get_error_message()));
                $total = (int) wp_count_terms([
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => !empty($args['hide_empty']),
                ]);
                return [
                    'taxonomy'    => $taxonomy,
                    'page'        => $page,
                    'per_page'    => $per_page,
                    // Return total_count alongside legacy 'total' to avoid a
                    // breaking rename for existing callers.
                    'total'       => $total,
                    'total_count' => $total,
                    'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 0,
                    'terms'       => array_map(function ($t) {
                        return [
                            'id'          => (int) $t->term_id,
                            'name'        => $t->name,
                            'slug'        => $t->slug,
                            'description' => $t->description,
                            'count'       => (int) $t->count,
                            'parent'      => (int) $t->parent,
                        ];
                    }, $terms),
                ];

            case 'wp_add_post_terms':
                $taxonomy = sanitize_text_field($args['taxonomy']);
                if (!taxonomy_exists($taxonomy)) throw new \Exception('Unknown taxonomy: ' . esc_html($taxonomy) . '. Use wp_get_taxonomies to list available taxonomies.');
                $post_id = self::resolve_post_id_arg($args);
                if ($post_id <= 0 || !get_post($post_id)) throw new \Exception('Post not found.');
                // assigning terms to a post requires the post's own
                // edit cap (so a Subscriber can't tag an admin's draft).
                if (!current_user_can('edit_post', $post_id)) {
                    throw new \Exception('You do not have permission to edit this post.');
                }
                // Plus the per-taxonomy assign_terms cap.
                $tax_obj = get_taxonomy($taxonomy);
                $assign_cap = $tax_obj && !empty($tax_obj->cap->assign_terms) ? $tax_obj->cap->assign_terms : 'edit_posts';
                if (!current_user_can($assign_cap)) {
                    throw new \Exception('You do not have permission to assign terms in ' . esc_html($taxonomy) . '.');
                }
                // Normalize terms input: wp_set_post_terms accepts an array
                // of term IDs (integers), slugs (non-numeric strings, for
                // hierarchical taxonomies), or names (for non-hierarchical
                // like tags). Reject non-array input with a clear error rather
                // than crashing on the following array_map. Numeric values
                // (int OR numeric string) coerce to int; non-numeric strings
                // pass through as slug/name.
                if ( ! isset( $args['terms'] ) || ! is_array( $args['terms'] ) ) {
                    throw new \Exception( 'terms must be an array of term IDs (integers) or term slugs/names (strings).' );
                }
                $normalized_terms = array_map( function ( $t ) {
                    if ( is_string( $t ) && ! is_numeric( $t ) ) {
                        return $t;
                    }
                    return (int) $t;
                }, $args['terms'] );
                $result = wp_set_post_terms( $post_id, $normalized_terms, $taxonomy, true );
                if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
                return ['message' => 'Terms added to post successfully'];

            case 'wp_count_terms':
                if (!current_user_can('read')) {
                    throw new \Exception('You do not have permission to count terms.');
                }
                $taxonomy = sanitize_text_field($args['taxonomy'] ?? 'category');
                $count = wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
                return ['taxonomy' => $taxonomy, 'count' => $count];

            case 'wp_get_term_meta':
                $term_id = intval($args['term_id']);
                if (!get_term($term_id)) throw new \Exception('Term not found');
                if (!current_user_can('manage_categories')) {
                    throw new \Exception('You do not have permission to read term meta.');
                }
                // wrap return in a structured object for consistency
                // with wp_update_term_meta / wp_delete_term_meta which return
                // structured arrays. Single-key get returns {term_id, key,
                // value}; full-meta get returns {term_id, meta: {...}}.
                if (!empty($args['key'])) {
                    $key = \Royal_MCP\MCP\Support\SafeText::field($args['key']);
                    return [
                        'term_id' => $term_id,
                        'key'     => $key,
                        'value'   => get_term_meta($term_id, $key, true),
                    ];
                }
                return [
                    'term_id' => $term_id,
                    'meta'    => (array) get_term_meta($term_id),
                ];

            case 'wp_update_term_meta':
                $term_id = intval($args['term_id']);
                if (!get_term($term_id)) throw new \Exception('Term not found');
                if (!current_user_can('edit_term', $term_id)) {
                    throw new \Exception('You do not have permission to edit this term.');
                }
                if (!array_key_exists('value', $args)) {
                    throw new \Exception('A value is required.');
                }
                $tmeta_key   = \Royal_MCP\MCP\Support\SafeText::field($args['key']);
                $tmeta_value = self::filter_meta_value($args['value'], $tmeta_key, $term_id, 'wp_update_term_meta');

                $tmeta_before = get_term_meta($term_id, $tmeta_key, true);

                $tmeta_result = update_term_meta($term_id, $tmeta_key, $tmeta_value);

                wp_cache_delete($term_id, 'term_meta');
                $tmeta_actual = get_term_meta($term_id, $tmeta_key, true);

                $tmeta_diff = \Royal_MCP\MCP\Support\WriteVerifier::diff(
                    ['value' => $tmeta_value],
                    ['value' => $tmeta_before],
                    ['value' => $tmeta_actual]
                );
                \Royal_MCP\MCP\Support\WriteVerifier::throw_if_dropped($tmeta_diff, 'wp_update_term_meta');

                $tmeta_reverse_json     = (string) wp_json_encode( [ 'prior_value' => $tmeta_before ] );
                $tmeta_reverse_size_est = strlen( gzcompress( $tmeta_reverse_json, 9 ) );
                $tmeta_undo_envelope    = null;
                $tmeta_warnings         = [];
                if ( $tmeta_reverse_size_est > 1024 * 1024 ) {
                    $tmeta_warnings[] = 'undo not available — prior value exceeds 1MB storage cap. SiteVault snapshot recommended for reversal.';
                } else {
                    $tmeta_prior_size_kb = round( strlen( $tmeta_reverse_json ) / 1024, 1 );
                    $tmeta_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                        'op'      => 'wp_update_term_meta',
                        'summary' => sprintf( 'Restore %s on term %d (prior value: %sKB)', $tmeta_key, $term_id, $tmeta_prior_size_kb ),
                        'target'  => [ 'term_id' => $term_id, 'meta_key' => $tmeta_key ],
                        'pre_op_state' => [
                            'prior_value'   => $tmeta_before,
                            'applied_value' => $tmeta_actual,
                        ],
                    ]);
                }

                $tmeta_struct = array_merge(
                    [
                        'term_id'  => $term_id,
                        'meta_key' => $tmeta_key,
                        'updated'  => (bool) $tmeta_result,
                    ],
                    \Royal_MCP\MCP\Support\WriteVerifier::response_partial( $tmeta_diff )
                );
                if ( ! empty( $tmeta_warnings ) ) {
                    $tmeta_struct['warnings'] = $tmeta_warnings;
                }
                $tmeta_summary = sprintf(
                    'Updated %s on term %d (%d field(s) applied%s%s).',
                    $tmeta_key,
                    $term_id,
                    count( $tmeta_diff['applied'] ) + count( $tmeta_diff['silent_modifies'] ),
                    ! empty( $tmeta_diff['silent_modifies'] ) ? ', WP modified value' : '',
                    $tmeta_undo_envelope !== null ? ', undo available' : ' (undo not available: value too large)'
                );
                return \Royal_MCP\MCP\Support\Envelope::success(
                    $tmeta_summary,
                    $tmeta_struct,
                    $tmeta_undo_envelope
                );

            case 'wp_delete_term_meta':
                $term_id = intval($args['term_id']);
                if (!get_term($term_id)) throw new \Exception('Term not found');
                if (!current_user_can('edit_term', $term_id)) {
                    throw new \Exception('You do not have permission to edit this term.');
                }
                $result = delete_term_meta($term_id, \Royal_MCP\MCP\Support\SafeText::field($args['key']));
                if (!$result) throw new \Exception('Failed to delete term meta (key may not exist)');
                return ['term_id' => $term_id, 'message' => 'Term meta deleted'];

            // ==================== COMMENTS ====================
            case 'wp_get_comments':
                if (!current_user_can('read')) {
                    throw new \Exception('You do not have permission to list comments.');
                }
                $comment_args = ['number' => min(intval($args['per_page'] ?? 10), 100)];
                if (!empty($args['post_id'])) $comment_args['post_id'] = intval($args['post_id']);
                if (!empty($args['status'])) {
                    $requested_comment_status = sanitize_text_field($args['status']);
                    // Only 'approve' is public. Anything else ('all', 'hold',
                    // 'spam', 'trash', unknown values) requires moderate_comments.
                    // WP_Comment_Query with status=all returns hold/spam/trash
                    // too, so an allowlist here is the only shape that catches
                    // status=all without a special case.
                    if (!in_array($requested_comment_status, ['approve'], true)
                        && !current_user_can('moderate_comments')) {
                        throw new \Exception('You do not have permission to list ' . esc_html($requested_comment_status) . ' comments.');
                    }
                    $comment_args['status'] = $requested_comment_status;
                }
                $comments = get_comments($comment_args);
                return array_map(function($c) {
                    return [
                        'id' => $c->comment_ID,
                        'post_id' => $c->comment_post_ID,
                        'author' => $c->comment_author,
                        'content' => $c->comment_content,
                        'date' => $c->comment_date,
                        'status' => $c->comment_approved,
                    ];
                }, $comments);

            case 'wp_create_comment':
                if (!current_user_can('read')) {
                    throw new \Exception('You do not have permission to create comments via the MCP API.');
                }
                $comment_post_id = intval($args['post_id']);
                $comment_target_post = $comment_post_id > 0 ? get_post($comment_post_id) : null;
                if (!$comment_target_post) throw new \Exception('Post not found.');
                // block commenting on closed/private posts unless the
                // user has the relevant edit cap on the target post.
                if ('open' !== $comment_target_post->comment_status
                    && !current_user_can('edit_post', $comment_post_id)) {
                    throw new \Exception('Comments are closed on this post.');
                }
                // wp_filter_kses uses WP's tight comment-form $allowedtags
                // (<a>, <strong>, <em>, <blockquote>, <code>, <cite>, <abbr>, <acronym>).
                // Matches what a user gets when submitting a comment through the standard
                // WP form; sanitize_text_field stripped all tags including the standard <a>.
                $comment_data = [
                    'comment_post_ID' => $comment_post_id,
                    'comment_content' => wp_filter_kses($args['content']),
                    'comment_author' => \Royal_MCP\MCP\Support\SafeText::field($args['author'] ?? 'Anonymous'),
                    'comment_author_email' => sanitize_email($args['author_email'] ?? ''),
                ];
                // Respect WordPress comment moderation settings
                $comment_data['comment_approved'] = wp_allow_comment($comment_data);
                $cc_new_id = wp_insert_comment($comment_data);
                if (!$cc_new_id) throw new \Exception('Failed to create comment');
                $cc_new_id     = (int) $cc_new_id;
                $cc_approved   = $comment_data['comment_approved'] === 1;
                $cc_status_label = $cc_approved ? 'approved' : 'pending moderation';

                // Undo removes the specific comment row we created via
                // wp_delete_comment(force=true). Row-scoped — no other
                // comments touched.
                $cc_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                    'op'      => 'wp_create_comment',
                    'summary' => sprintf( 'Remove the comment %d created by this operation on post %d.', $cc_new_id, $comment_post_id ),
                    'target'  => [ 'comment_id' => $cc_new_id, 'post_id' => $comment_post_id ],
                    'pre_op_state' => [
                        'created_by_op' => true,
                    ],
                ]);

                return \Royal_MCP\MCP\Support\Envelope::success(
                    sprintf( 'Created comment %d on post %d (%s), undo available.', $cc_new_id, $comment_post_id, $cc_status_label ),
                    [
                        'id'      => $cc_new_id,
                        'post_id' => $comment_post_id,
                        'status'  => $cc_status_label,
                        'created' => true,
                    ],
                    $cc_undo_envelope
                );

            case 'wp_delete_comment':
                $dc_id = intval($args['id']);
                $dc_comment = $dc_id > 0 ? get_comment($dc_id) : null;
                if (!$dc_comment) throw new \Exception('Comment not found.');
                if (!current_user_can('edit_comment', $dc_id)) {
                    throw new \Exception('You do not have permission to delete this comment.');
                }
                $dc_force = !empty($args['force']);

                // Snapshot BEFORE the delete. For force=false (soft trash), we
                // only need the prior status so we can restore approve↔hold↔spam
                // on undo. For force=true (hard delete), the whole row must be
                // captured — wp_insert_comment recreates it on undo, though
                // the new comment_ID will differ from the original (WP
                // auto-increments; no way to preserve the exact ID without
                // direct DB writes, which we don't do).
                $dc_prior_status = self::normalize_comment_status_column( $dc_comment->comment_approved );
                $dc_full_snapshot = null;
                if ( $dc_force ) {
                    $dc_full_snapshot = [
                        'comment_post_ID'      => (int) $dc_comment->comment_post_ID,
                        'comment_author'       => (string) $dc_comment->comment_author,
                        'comment_author_email' => (string) $dc_comment->comment_author_email,
                        'comment_author_url'   => (string) $dc_comment->comment_author_url,
                        'comment_author_IP'    => (string) $dc_comment->comment_author_IP,
                        'comment_date'         => (string) $dc_comment->comment_date,
                        'comment_date_gmt'     => (string) $dc_comment->comment_date_gmt,
                        'comment_content'      => (string) $dc_comment->comment_content,
                        'comment_karma'        => (int) $dc_comment->comment_karma,
                        'comment_approved'     => (string) $dc_comment->comment_approved,
                        'comment_agent'        => (string) $dc_comment->comment_agent,
                        'comment_type'         => (string) $dc_comment->comment_type,
                        'comment_parent'       => (int) $dc_comment->comment_parent,
                        'user_id'              => (int) $dc_comment->user_id,
                    ];
                }

                $dc_ok = wp_delete_comment( $dc_id, $dc_force );
                if (!$dc_ok) throw new \Exception('Failed to delete comment');

                $dc_summary = '';
                $dc_undo_envelope = null;
                if ( $dc_force ) {
                    // Snapshot may exceed the 1MB cap on huge comments (rare but
                    // possible for spam with embedded content). Fall back to
                    // no-undo warning in that case.
                    $dc_reverse_json     = (string) wp_json_encode( [ 'row' => $dc_full_snapshot ] );
                    $dc_reverse_size_est = strlen( gzcompress( $dc_reverse_json, 9 ) );
                    if ( $dc_reverse_size_est > 1024 * 1024 ) {
                        $dc_summary = sprintf( 'Force-deleted comment %d (no undo: snapshot > 1MB).', $dc_id );
                    } else {
                        $dc_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                            'op'      => 'wp_delete_comment_force',
                            'summary' => sprintf( 'Recreate the force-deleted comment on post %d. Note: the new comment ID will differ from the original (%d).', $dc_full_snapshot['comment_post_ID'], $dc_id ),
                            'target'  => [ 'original_comment_id' => $dc_id, 'post_id' => $dc_full_snapshot['comment_post_ID'] ],
                            'pre_op_state' => [
                                'row' => $dc_full_snapshot,
                            ],
                        ]);
                        $dc_summary = sprintf( 'Force-deleted comment %d (undo will recreate with new ID).', $dc_id );
                    }
                } else {
                    // Soft trash — undo is wp_untrash_comment, which restores
                    // to _wp_trash_meta_status (the pre-trash status WP stores
                    // automatically). We also record the prior status so we
                    // can normalize a mismatch.
                    $dc_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                        'op'      => 'wp_delete_comment_trash',
                        'summary' => sprintf( 'Untrash comment %d back to prior status (%s).', $dc_id, $dc_prior_status ),
                        'target'  => [ 'comment_id' => $dc_id ],
                        'pre_op_state' => [
                            'prior_status' => $dc_prior_status,
                        ],
                    ]);
                    $dc_summary = sprintf( 'Trashed comment %d (was %s), undo available.', $dc_id, $dc_prior_status );
                }
                return \Royal_MCP\MCP\Support\Envelope::success(
                    $dc_summary,
                    [
                        'comment_id' => $dc_id,
                        'deleted'    => true,
                        'force'      => $dc_force,
                        'prior_status' => $dc_prior_status,
                    ],
                    $dc_undo_envelope
                );

            case 'wp_get_pending_comments':
                if (!current_user_can('moderate_comments')) {
                    throw new \Exception('moderate_comments capability required.');
                }
                $limit = min(intval($args['limit'] ?? 20), 100);
                $get_args = ['status' => 'hold', 'number' => $limit];
                if (!empty($args['post_id'])) {
                    $get_args['post_id'] = intval($args['post_id']);
                }
                $comments = get_comments($get_args);
                return array_map(function($c) {
                    return [
                        'id' => (int) $c->comment_ID,
                        'post_id' => (int) $c->comment_post_ID,
                        'post_title' => get_the_title($c->comment_post_ID),
                        'author' => $c->comment_author,
                        'author_email_redacted' => $c->comment_author_email ? substr($c->comment_author_email, 0, 2) . '***@***' : '',
                        'content' => wp_strip_all_tags($c->comment_content),
                        'status' => 'pending',
                        'date' => $c->comment_date,
                    ];
                }, $comments);

            case 'wp_approve_comment':
            case 'wp_spam_comment':
            case 'wp_trash_comment':
                // Shared retrofit for the three comment status-transition tools.
                // wp_approve → 'approve', wp_spam → 'spam' (both via
                // wp_set_comment_status); wp_trash → 'trash' via wp_trash_comment
                // which also stores _wp_trash_meta_status = prior status
                // (so untrash can restore it). We snapshot the prior status
                // explicitly so undo can restore approve↔hold↔spam transitions
                // regardless of what WP's own trash-metadata does.
                if (!current_user_can('moderate_comments')) {
                    throw new \Exception('moderate_comments capability required.');
                }
                $comment_id = intval($args['comment_id']);
                $comment_obj = $comment_id > 0 ? get_comment($comment_id) : null;
                if (!$comment_obj) throw new \Exception('Comment not found.');

                // Normalize prior status to the wp_set_comment_status vocabulary
                // ('approve' / 'hold' / 'spam' / 'trash'). $comment_obj->comment_approved
                // is a schema-column string: '1' | '0' | 'spam' | 'trash'.
                $ctx_prior_status = self::normalize_comment_status_column( $comment_obj->comment_approved );

                // Execute the op. $name is the outer switch discriminator so
                // fall-through case labels can still identify their tool.
                $ctx_op          = $name;   // 'wp_approve_comment' | 'wp_spam_comment' | 'wp_trash_comment'
                $ctx_new_status  = '';
                $ctx_new_label   = '';
                // Do NOT pre-emptively throw on falsy return from
                // wp_set_comment_status / wp_trash_comment — those return
                // false when the comment is already at the target status
                // (WP's $wpdb->update returns false on 0 rows affected).
                // A "no-change write" is a legitimate applied outcome and
                // should not error. WriteVerifier's silent-drop detection
                // below catches real failures (requested !== before AND
                // actual === before).
                if ( $ctx_op === 'wp_approve_comment' ) {
                    wp_set_comment_status( $comment_id, 'approve' );
                    $ctx_new_status = 'approve';
                    $ctx_new_label  = 'approved';
                } elseif ( $ctx_op === 'wp_spam_comment' ) {
                    wp_set_comment_status( $comment_id, 'spam' );
                    $ctx_new_status = 'spam';
                    $ctx_new_label  = 'spam';
                } else { // wp_trash_comment
                    wp_trash_comment( $comment_id );
                    $ctx_new_status = 'trash';
                    $ctx_new_label  = 'trash';
                }
                clean_comment_cache( $comment_id );
                $comment_fresh = get_comment( $comment_id );
                $ctx_actual_status = $comment_fresh ? self::normalize_comment_status_column( $comment_fresh->comment_approved ) : '';

                $ctx_diff = \Royal_MCP\MCP\Support\WriteVerifier::diff(
                    [ 'status' => $ctx_new_status ],
                    [ 'status' => $ctx_prior_status ],
                    [ 'status' => $ctx_actual_status ]
                );
                \Royal_MCP\MCP\Support\WriteVerifier::throw_if_dropped( $ctx_diff, $ctx_op );

                // Undo envelope — stores prior status. Restore path is
                // wp_set_comment_status($id, $prior_status) for approve/spam/hold,
                // or wp_untrash_comment for coming out of trash.
                $ctx_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                    'op'      => 'wp_set_comment_status',   // unified undo op
                    'summary' => sprintf( 'Restore comment %d status from %s back to %s.', $comment_id, $ctx_actual_status, $ctx_prior_status ),
                    'target'  => [ 'comment_id' => $comment_id ],
                    'pre_op_state' => [
                        'prior_status'   => $ctx_prior_status,
                        'applied_status' => $ctx_actual_status,
                        'original_op'    => $ctx_op,
                    ],
                ]);

                $ctx_struct = array_merge(
                    [
                        'comment_id' => $comment_id,
                        'new_status' => $ctx_new_label,
                        'prior_status' => $ctx_prior_status,
                    ],
                    \Royal_MCP\MCP\Support\WriteVerifier::response_partial( $ctx_diff )
                );
                $ctx_summary = sprintf(
                    'Comment %d: %s → %s%s, undo available.',
                    $comment_id,
                    $ctx_prior_status,
                    $ctx_actual_status,
                    ! empty( $ctx_diff['silent_modifies'] ) ? ' (WP modified status)' : ''
                );
                return \Royal_MCP\MCP\Support\Envelope::success(
                    $ctx_summary,
                    $ctx_struct,
                    $ctx_undo_envelope
                );

            // ==================== USERS ====================
            case 'wp_get_users':
                // list_users is the cap WordPress core uses for the Users
                // admin screen and the WP REST users endpoint. Without this
                // gate a Subscriber-level OAuth token could enumerate every
                // account on the site.
                if (!current_user_can('list_users')) {
                    throw new \Exception('You do not have permission to list users.');
                }
                $user_args = ['number' => min(intval($args['per_page'] ?? 10), 100)];
                if (!empty($args['role'])) $user_args['role'] = sanitize_text_field($args['role']);
                $users = get_users($user_args);
                return array_map(function($u) {
                    return [
                        'id' => $u->ID,
                        'display_name' => $u->display_name,
                        'roles' => $u->roles,
                    ];
                }, $users);

            case 'wp_get_user':
                if (!current_user_can('list_users')) {
                    throw new \Exception('You do not have permission to view user accounts.');
                }
                $user = get_user_by('ID', intval($args['id']));
                if (!$user) throw new \Exception('User not found');
                return [
                    'id' => $user->ID,
                    'display_name' => $user->display_name,
                    'roles' => $user->roles,
                    'registered' => $user->user_registered,
                ];

            // ==================== POST META ====================
            case 'wp_get_post_meta':
                $post_id = self::resolve_post_id_arg($args);
                if ($post_id <= 0 || !get_post($post_id)) throw new \Exception('Post not found.');
                $key = !empty($args['key']) ? \Royal_MCP\MCP\Support\SafeText::field($args['key']) : '';
                // protected-meta gating. Mirrors WP core's
                // is_protected_meta() convention: underscore-prefixed keys
                // (_yoast_wpseo_*, _edit_lock, _wp_attached_file, ACF
                // internals, etc.) carry admin/SEO data and require
                // edit_post. Non-underscored keys keep read_post for
                // legitimate public-meta consumers. Empty-key requests
                // (return all meta) require edit_post too since the
                // response would otherwise leak underscored keys.
                // read_post on the parent gates the public path so a
                // Subscriber-level OAuth token cannot read meta on private
                // admin-owned posts.
                $needs_edit_cap = ($key === '' || strpos($key, '_') === 0);
                $cap = $needs_edit_cap ? 'edit_post' : 'read_post';
                if (!current_user_can($cap, $post_id)) {
                    throw new \Exception('You do not have permission to read meta on this post.');
                }
                if ($key !== '') {
                    $value = get_post_meta($post_id, $key, true);
                    return ['key' => $key, 'value' => $value];
                }
                return get_post_meta($post_id);

            case 'wp_update_post_meta':
                $post_id = self::resolve_post_id_arg($args);
                if ($post_id <= 0 || !get_post($post_id)) throw new \Exception('Post not found.');
                if (!current_user_can('edit_post', $post_id)) {
                    throw new \Exception('You do not have permission to edit meta on this post.');
                }
                if (!array_key_exists('value', $args)) {
                    throw new \Exception('A value is required. To remove a key entirely, use wp_delete_post_meta.');
                }
                $meta_key   = \Royal_MCP\MCP\Support\SafeText::field($args['key']);
                $meta_value = self::filter_meta_value($args['value'], $meta_key, $post_id, 'wp_update_post_meta');

                // Snapshot prior value BEFORE the write for both undo (restore
                // target) and INVARIANTS §11 verify (silent-drop detection).
                $before_value = get_post_meta($post_id, $meta_key, true);

                // Execute the write.
                $result = update_post_meta($post_id, $meta_key, $meta_value);

                // Fresh re-read — post-meta cache is per-post scoped.
                wp_cache_delete($post_id, 'post_meta');
                $actual_value = get_post_meta($post_id, $meta_key, true);

                // §11 diff — if the value we wrote didn't stick, silent-drop.
                // If it moved but not to our value, that's silent-modify (surfaced
                // via response_partial → modified_by_wp).
                $meta_diff = \Royal_MCP\MCP\Support\WriteVerifier::diff(
                    ['value' => $meta_value],
                    ['value' => $before_value],
                    ['value' => $actual_value]
                );
                \Royal_MCP\MCP\Support\WriteVerifier::throw_if_dropped($meta_diff, 'wp_update_post_meta');

                // Build undo envelope unless the prior value exceeds the 1MB
                // compressed-storage cap. Large-value case (e.g. _elementor_data
                // pages, ACF Pro complex fields) gets a graceful warning + no
                // undo token — caller can fall back to SiteVault snapshot.
                $reverse_json      = (string) wp_json_encode( [ 'prior_value' => $before_value ] );
                $reverse_size_est  = strlen( gzcompress( $reverse_json, 9 ) );
                $undo_envelope     = null;
                $warnings          = [];
                if ( $reverse_size_est > 1024 * 1024 ) {
                    $warnings[] = 'undo not available — prior value exceeds 1MB storage cap. SiteVault snapshot recommended for reversal.';
                } else {
                    $prior_size_kb = round( strlen( $reverse_json ) / 1024, 1 );
                    $undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                        'op'      => 'wp_update_post_meta',
                        'summary' => sprintf( 'Restore %s on post %d (prior value: %sKB)', $meta_key, $post_id, $prior_size_kb ),
                        'target'  => [ 'post_id' => $post_id, 'meta_key' => $meta_key ],
                        'pre_op_state' => [
                            'prior_value'   => $before_value,   // for restore
                            'applied_value' => $actual_value,   // for drift-detection at undo time
                        ],
                    ]);
                }

                $meta_struct = array_merge(
                    [
                        'post_id'  => $post_id,
                        'meta_key' => $meta_key,
                        'updated'  => (bool) $result,
                    ],
                    \Royal_MCP\MCP\Support\WriteVerifier::response_partial( $meta_diff )
                );
                if ( ! empty( $warnings ) ) {
                    $meta_struct['warnings'] = $warnings;
                }
                $meta_summary = sprintf(
                    'Updated %s on post %d (%d field(s) applied%s%s).',
                    $meta_key,
                    $post_id,
                    count( $meta_diff['applied'] ) + count( $meta_diff['silent_modifies'] ),
                    ! empty( $meta_diff['silent_modifies'] ) ? ', WP modified value' : '',
                    $undo_envelope !== null ? ', undo available' : ' (undo not available: value too large)'
                );
                return \Royal_MCP\MCP\Support\Envelope::success(
                    $meta_summary,
                    $meta_struct,
                    $undo_envelope
                );

            case 'wp_add_post_meta':
                $post_id = self::resolve_post_id_arg($args);
                if ($post_id <= 0 || !get_post($post_id)) throw new \Exception('Post not found.');
                if (!current_user_can('edit_post', $post_id)) {
                    throw new \Exception('You do not have permission to edit meta on this post.');
                }
                if (!array_key_exists('value', $args)) {
                    throw new \Exception('A value is required.');
                }
                $add_key = \Royal_MCP\MCP\Support\SafeText::field($args['key'] ?? '');
                if ($add_key === '') throw new \Exception('A meta key is required.');
                $add_value  = self::filter_meta_value($args['value'], $add_key, $post_id, 'wp_add_post_meta');
                $add_unique = !empty($args['unique']);
                $add_meta_id = add_post_meta($post_id, $add_key, $add_value, $add_unique);

                // add_post_meta returns false when unique=true and the key already exists.
                // That's a semantic conflict, not a runtime error — surface via envelope
                // error so clients can retry-classify without parsing an exception string.
                if ($add_meta_id === false) {
                    return \Royal_MCP\MCP\Support\Envelope::error(
                        'conflict',
                        sprintf( 'add_post_meta returned false — meta_key "%s" already exists on post %d with unique=true, or the write was blocked.', $add_key, $post_id ),
                        [ 'post_id' => $post_id, 'meta_key' => $add_key, 'unique' => $add_unique ]
                    );
                }

                // Verify the row exists at the returned meta_id with our value —
                // catches silent-drop from filter hooks that whitelisted the write
                // but ate the value. get_metadata_by_mid resolves to a single row
                // regardless of unique/multi state.
                wp_cache_delete( $post_id, 'post_meta' );
                $add_row = get_metadata_by_mid( 'post', (int) $add_meta_id );
                if ( ! $add_row || ! isset( $add_row->meta_value ) ) {
                    throw new \Exception( 'wp_add_post_meta: add reported success but row is missing at that meta_id. A hook may have deleted it during the same request.' );
                }
                // WP stores non-scalar meta serialized; get_metadata_by_mid returns
                // the raw string. Re-run through maybe_unserialize for equality vs
                // our filtered input value.
                $add_stored_value = maybe_unserialize( $add_row->meta_value );
                if ( $add_stored_value !== $add_value ) {
                    // Silent-modify: WP or a filter mutated our value at write time.
                    // Non-fatal — surface in structuredContent for caller diligence.
                    $add_modified_by_wp = [ 'requested' => $add_value, 'actual' => $add_stored_value ];
                } else {
                    $add_modified_by_wp = null;
                }

                // Undo envelope — delete the specific row by meta_id (does NOT
                // touch other rows sharing the same key on this post; safe with
                // unique=false additions).
                $add_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                    'op'      => 'wp_add_post_meta',
                    'summary' => sprintf( 'Remove the meta row added by this operation on post %d (key: %s, meta_id: %d).', $post_id, $add_key, (int) $add_meta_id ),
                    'target'  => [ 'post_id' => $post_id, 'meta_key' => $add_key, 'meta_id' => (int) $add_meta_id ],
                    'pre_op_state' => [
                        'added_value' => $add_stored_value,
                    ],
                ]);

                $add_struct = [
                    'post_id'  => $post_id,
                    'meta_key' => $add_key,
                    'meta_id'  => (int) $add_meta_id,
                    'created'  => true,
                    'unique'   => $add_unique,
                ];
                if ( $add_modified_by_wp !== null ) {
                    $add_struct['modified_by_wp'] = [ 'value' => $add_modified_by_wp ];
                }

                $add_summary = sprintf(
                    'Added %s to post %d (meta_id: %d%s, undo available).',
                    $add_key,
                    $post_id,
                    (int) $add_meta_id,
                    $add_modified_by_wp !== null ? ', WP modified value' : ''
                );
                return \Royal_MCP\MCP\Support\Envelope::success(
                    $add_summary,
                    $add_struct,
                    $add_undo_envelope
                );

            case 'wp_delete_post_meta':
                $post_id = self::resolve_post_id_arg($args);
                if ($post_id <= 0 || !get_post($post_id)) throw new \Exception('Post not found.');
                if (!current_user_can('edit_post', $post_id)) {
                    throw new \Exception('You do not have permission to edit meta on this post.');
                }
                $result = delete_post_meta($post_id, \Royal_MCP\MCP\Support\SafeText::field($args['key']));
                if (!$result) throw new \Exception('Failed to delete post meta');
                return ['message' => 'Post meta deleted successfully'];

            // ==================== SITE & SEARCH ====================
            case 'wp_get_site_info':
                if (!current_user_can('read')) {
                    throw new \Exception('You do not have permission to view site info.');
                }
                return [
                    'name' => get_bloginfo('name'),
                    'description' => get_bloginfo('description'),
                    'url' => home_url(),
                    'language' => get_locale(),
                    'timezone' => wp_timezone_string(),
                    'wp_version' => get_bloginfo('version'),
                ];

            // one-shot site diagnostic. Collapses the WP+PHP+MySQL+plugins+theme
                // discovery flurry (previously 3-5 separate tool calls at conversation start)
                // into a single read. Distinct from wp_get_site_info which is user-visible
                // metadata (name/description/URL); this is operator-visible environment.
            // 1.4.37 Candidate 1 — connection health probe. Any authenticated caller.
            // Every field is self-attributable (my auth method, my session, my token TTL),
            // no cap check needed — reaching execute_tool already required valid auth.
            case 'royal_mcp_connection_health':
                global $wp_version;
                // builders block lets an agent plan multi-step edits without probing.
                // Knowing "site is on Divi 5" or "Elementor 4.0.8" at connection time
                // means the agent can pick the right JSON block / widget schema path
                // up front instead of running a discovery call before every write.
                $builders = [
                    'divi_version'      => defined('ET_BUILDER_VERSION') ? (string) constant('ET_BUILDER_VERSION') : null,
                    'elementor_version' => defined('ELEMENTOR_VERSION') ? (string) constant('ELEMENTOR_VERSION') : null,
                    'gutenberg_version' => defined('GUTENBERG_VERSION') ? (string) constant('GUTENBERG_VERSION') : (string) get_bloginfo('version'),
                ];
                return [
                    'route'          => rest_url('royal-mcp/v1/mcp'),
                    'auth_method'    => $this->request_auth_method ?? 'unauthenticated',
                    'relay'          => null,
                    'token_ttl'      => $this->request_token_ttl,
                    'session_id'     => $this->request_session_id,
                    'active_scopes'  => ['tools'],
                    'server_version' => defined('ROYAL_MCP_VERSION') ? ROYAL_MCP_VERSION : 'unknown',
                    'wp_version'     => isset($wp_version) ? (string) $wp_version : (string) get_bloginfo('version'),
                    'php_version'    => PHP_VERSION,
                    'builders'       => $builders,
                ];

            case 'wp_get_site_status':
                if (!current_user_can('manage_options')) {
                    throw new \Exception('You do not have permission to read site status.');
                }
                global $wpdb;

                // MySQL / MariaDB version — use $wpdb->db_version() when available (WP 6.3+
                // returns the numeric server version), fall back to raw SELECT VERSION() so
                // this works on older WP too.
                $db_server_info = null;
                if (method_exists($wpdb, 'db_version')) {
                    $db_server_info = $wpdb->db_version();
                }
                if (empty($db_server_info)) {
                    $db_server_info = (string) $wpdb->get_var('SELECT VERSION()');
                }

                // Active plugin count — active_plugins is the primary list; also count
                // network-active plugins on multisite so the number reflects reality.
                $active_plugins = (array) get_option('active_plugins', []);
                if (is_multisite()) {
                    $network_active = (array) get_site_option('active_sitewide_plugins', []);
                    $active_plugin_count = count($active_plugins) + count($network_active);
                } else {
                    $active_plugin_count = count($active_plugins);
                }

                $theme = wp_get_theme();

                // Disk usage on ABSPATH — capture just the free-bytes reading. disk_free_space
                // can be disabled by hosts (returns false) so guard for that.
                $disk_free_bytes = @disk_free_space(ABSPATH);

                // Uptime days — WP doesn't record install time separately from siteurl, so
                // use the oldest post_date_gmt as a proxy (nearly always the "Hello World"
                // seed post from install). Falls back to null if the site has no posts.
                $oldest_ts = null;
                $oldest_row = $wpdb->get_var("SELECT UNIX_TIMESTAMP(MIN(post_date_gmt)) FROM {$wpdb->posts} WHERE post_status IN ('publish','private','draft','future','pending')");
                if ($oldest_row && (int) $oldest_row > 0) {
                    $oldest_ts = (int) $oldest_row;
                }

                return [
                    'wp_version'          => get_bloginfo('version'),
                    'php_version'         => PHP_VERSION,
                    'mysql_version'       => $db_server_info,
                    'is_multisite'        => is_multisite(),
                    'active_plugin_count' => $active_plugin_count,
                    'active_theme'        => [
                        'name'       => $theme->get('Name'),
                        'stylesheet' => get_stylesheet(),
                        'template'   => get_template(),
                        'version'    => $theme->get('Version'),
                    ],
                    'memory_limit'        => ini_get('memory_limit'),
                    'max_upload_size'     => size_format(wp_max_upload_size()),
                    'max_execution_time'  => (int) ini_get('max_execution_time'),
                    'timezone'            => wp_timezone_string(),
                    'debug_log_enabled'   => (defined('WP_DEBUG') && WP_DEBUG) && (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG),
                    'disk_free_bytes'     => $disk_free_bytes === false ? null : (int) $disk_free_bytes,
                    'disk_free_human'     => $disk_free_bytes === false ? null : size_format((int) $disk_free_bytes),
                    'install_age_days'    => $oldest_ts ? (int) floor((time() - $oldest_ts) / DAY_IN_SECONDS) : null,
                    'site_url'            => site_url(),
                    'home_url'            => home_url(),
                ];

            // tail wp-content/debug.log for AI-driven diagnosis.
            // Manage_options gate: log lines routinely contain paths + stack traces
            // + occasionally sensitive tokens that shouldn't reach read-tier callers.
            case 'wp_get_error_log_tail':
                if (!current_user_can('manage_options')) {
                    throw new \Exception('You do not have permission to read the error log.');
                }
                $lines = isset($args['lines']) ? max(1, min(intval($args['lines']), 1000)) : 100;
                $filter = isset($args['filter']) ? (string) $args['filter'] : '';

                $log_status = 'ok';
                $log_path   = defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== ''
                    ? (string) WP_DEBUG_LOG
                    : WP_CONTENT_DIR . '/debug.log';

                if (!(defined('WP_DEBUG') && WP_DEBUG) || !(defined('WP_DEBUG_LOG') && WP_DEBUG_LOG)) {
                    return [
                        'status'  => 'disabled',
                        'message' => 'WP_DEBUG_LOG is not enabled in wp-config.php. Add: define(\'WP_DEBUG\', true); define(\'WP_DEBUG_LOG\', true); define(\'WP_DEBUG_DISPLAY\', false);',
                        'path'    => $log_path,
                        'filter'  => $filter,
                        'lines'   => [],
                        'total_returned' => 0,
                    ];
                }

                if (!file_exists($log_path)) {
                    return [
                        'status'  => 'no_log_file',
                        'message' => 'debug.log does not exist yet (no errors logged since it was last cleared).',
                        'path'    => $log_path,
                        'filter'  => $filter,
                        'lines'   => [],
                        'total_returned' => 0,
                    ];
                }

                if (!is_readable($log_path)) {
                    return [
                        'status'  => 'unreadable',
                        'message' => 'debug.log exists but is not readable by PHP (check file permissions).',
                        'path'    => $log_path,
                        'filter'  => $filter,
                        'lines'   => [],
                        'total_returned' => 0,
                    ];
                }

                // Cap read at last 1MB to prevent memory blowup on multi-GB debug.log
                // files. AI callers get whatever N lines fit in that window.
                $filesize = filesize($log_path);
                $chunk_max = 1 * 1024 * 1024;
                $offset    = ($filesize > $chunk_max) ? ($filesize - $chunk_max) : 0;

                $fh = @fopen($log_path, 'r');
                if (!$fh) {
                    throw new \Exception('Could not open debug.log for reading.');
                }
                if ($offset > 0) {
                    fseek($fh, $offset);
                    // Drop first (partial) line after the seek so we don't return
                    // half a stack-trace line.
                    fgets($fh);
                }
                $raw = stream_get_contents($fh);
                fclose($fh);

                $all_lines = $raw === false ? [] : preg_split("/\r\n|\n|\r/", (string) $raw);
                // Drop trailing empty line from final \n.
                if (!empty($all_lines) && '' === end($all_lines)) {
                    array_pop($all_lines);
                }

                if ($filter !== '') {
                    $all_lines = array_values(array_filter($all_lines, function ($ln) use ($filter) {
                        return false !== stripos($ln, $filter);
                    }));
                }

                $tail = array_slice($all_lines, -$lines);

                return [
                    'status'         => $log_status,
                    'path'           => $log_path,
                    'filesize_bytes' => (int) $filesize,
                    'window_bytes'   => (int) ($filesize - $offset),
                    'truncated'      => $offset > 0,
                    'filter'         => $filter,
                    'total_returned' => count($tail),
                    'lines'          => array_values($tail),
                ];

            // enumerate scheduled cron events. Stuck cron is a routine WP
            // diagnosis (missed_schedule reports on WP core, unfired hooks on plugin
            // conflicts) and agents previously had no visibility. Manage_options gate
            // because cron args occasionally carry token-like identifiers.
            case 'wp_get_cron_schedule':
                if (!current_user_can('manage_options')) {
                    throw new \Exception('You do not have permission to read the cron schedule.');
                }
                $crons = _get_cron_array();
                if (!is_array($crons)) {
                    return ['events' => [], 'total_count' => 0];
                }
                $now = time();
                $events = [];
                foreach ($crons as $timestamp => $hooks) {
                    if (!is_array($hooks)) continue;
                    foreach ($hooks as $hook => $signatures) {
                        if (!is_array($signatures)) continue;
                        foreach ($signatures as $sig_key => $meta) {
                            $recurrence = null;
                            if (!empty($meta['schedule'])) {
                                $schedules = wp_get_schedules();
                                $recurrence = isset($schedules[$meta['schedule']])
                                    ? $meta['schedule'] . ' (' . (int) $schedules[$meta['schedule']]['interval'] . 's)'
                                    : (string) $meta['schedule'];
                            }
                            $events[] = [
                                'hook'          => (string) $hook,
                                'next_run_ts'   => (int) $timestamp,
                                'next_run_iso'  => wp_date('c', (int) $timestamp),
                                'seconds_until' => (int) $timestamp - $now,
                                'is_overdue'    => (int) $timestamp < $now,
                                'recurrence'    => $recurrence,
                                'args'          => $meta['args'] ?? [],
                            ];
                        }
                    }
                }
                // Sort by next-run ascending so overdue events + soonest come first.
                usort($events, function ($a, $b) {
                    return $a['next_run_ts'] <=> $b['next_run_ts'];
                });
                return [
                    'events'      => $events,
                    'total_count' => count($events),
                    'now_ts'      => $now,
                    'now_iso'     => wp_date('c', $now),
                ];

            case 'wp_search':
                if (!current_user_can('read')) {
                    throw new \Exception('You do not have permission to search.');
                }
                $query = \Royal_MCP\MCP\Support\SafeText::field($args['query']);
                $per_page = isset($args['per_page']) ? max(1, min(intval($args['per_page']), 100)) : 20;
                $snippet_len = isset($args['snippet']) ? max(0, min(intval($args['snippet']), 1000)) : 0;
                $search_args = [
                    's' => $query,
                    'post_type' => !empty($args['post_type']) ? sanitize_text_field($args['post_type']) : 'any',
                    'numberposts' => $per_page,
                ];
                $posts = get_posts($search_args);
                return array_map(function($p) use ($query, $snippet_len) {
                    $row = ['id' => $p->ID, 'title' => $p->post_title, 'type' => $p->post_type, 'url' => get_permalink($p), 'content_length' => strlen((string) $p->post_content)];
                    if ($snippet_len > 0) {
                        $row['slug'] = $p->post_name;
                        $row['snippet'] = $this->extract_snippet($p->post_content, $query, $snippet_len);
                    }
                    return $row;
                }, $posts);

            // ==================== OPTIONS ====================
            case 'wp_get_option':
                if (!current_user_can('manage_options')) {
                    throw new \Exception('You do not have permission to read site options.');
                }
                $name = \Royal_MCP\MCP\Support\SafeText::field($args['name']);
                $allowed = $this->get_readable_options_allowlist();
                if (!in_array($name, $allowed, true)) {
                    throw new \Exception('Option not in readable allowlist: ' . esc_html($name) . '. Plugin authors can opt their settings in via add_filter("royal_mcp_readable_options", ...).');
                }
                return ['name' => $name, 'value' => $this->redact_sensitive_keys(get_option($name))];

            case 'wp_get_plugin_settings':
                if (!current_user_can('manage_options')) {
                    throw new \Exception('You do not have permission to read plugin settings.');
                }
                $slug = sanitize_text_field($args['plugin_slug'] ?? '');
                if (empty($slug)) throw new \Exception('plugin_slug is required.');
                return [
                    'slug'    => $slug,
                    'options' => $this->find_plugin_options($slug),
                ];

            case 'wp_update_option':
                if (!current_user_can('manage_options')) {
                    throw new \Exception('You do not have permission to write site options.');
                }
                $name = \Royal_MCP\MCP\Support\SafeText::field($args['name'] ?? '');
                if (empty($name)) throw new \Exception('Option name is required.');

                // Gate 1: master toggle
                $rmcp_settings = get_option('royal_mcp_settings', []);
                if (empty($rmcp_settings['allow_option_writes'])) {
                    throw new \Exception('Option writes are disabled. Enable "Allow AI to write WordPress options" under Royal MCP > Settings > General Settings.');
                }

                // Gate 2: permanent denylist (overrides allowlist)
                if ($this->is_denylisted_option($name)) {
                    throw new \Exception('Option is permanently denylisted: ' . esc_html($name));
                }

                // Gate 3: write ⊆ readable INVARIANT — any option that cannot be
                // read via wp_get_option cannot be written either. Eliminates
                // the drift class where a plugin author opts a sensitive key
                // into the writable filter without also opting it into the
                // readable filter, leaving no way to safely diff-then-write.
                // Forces plugin authors to reason about both surfaces before
                // opting anything in.
                $readable = $this->get_readable_options_allowlist();
                if (!in_array($name, $readable, true)) {
                    throw new \Exception('Option cannot be written because it is not in the readable allowlist: ' . esc_html($name) . '. Plugin authors must opt into royal_mcp_readable_options BEFORE opting into royal_mcp_writable_options.');
                }

                // Gate 4: writable allowlist
                $default_writable = ['blogname', 'blogdescription', 'posts_per_page', 'date_format', 'time_format', 'show_on_front', 'page_on_front'];
                $writable = apply_filters('royal_mcp_writable_options', $default_writable);
                if (!is_array($writable)) $writable = $default_writable;
                if (!in_array($name, $writable, true)) {
                    throw new \Exception('Option not in writable allowlist: ' . esc_html($name) . '. Plugin authors can opt their settings in via add_filter("royal_mcp_writable_options", ...).');
                }

                // Value is intentionally accepted as-is (any JSON type). update_option will serialize.
                $opt_value    = $args['value'] ?? null;
                $opt_previous = get_option($name);

                // Detect whether the option row existed before the write. WP's
                // update_option returns false both for "no change" and "row not
                // present" — the same signal for different situations. We track
                // existence explicitly so undo can either restore the prior
                // value or delete_option to remove a row we created.
                $opt_existed_before = ( $opt_previous !== false );

                $opt_result   = update_option($name, $opt_value);
                wp_cache_delete( $name, 'options' );  // core also does this but be defensive
                $opt_actual   = get_option($name);

                $opt_diff = \Royal_MCP\MCP\Support\WriteVerifier::diff(
                    [ 'value' => $opt_value ],
                    [ 'value' => $opt_previous ],
                    [ 'value' => $opt_actual ]
                );
                \Royal_MCP\MCP\Support\WriteVerifier::throw_if_dropped( $opt_diff, 'wp_update_option' );

                $opt_reverse_json     = (string) wp_json_encode( [ 'prior_value' => $opt_previous, 'existed_before' => $opt_existed_before ] );
                $opt_reverse_size_est = strlen( gzcompress( $opt_reverse_json, 9 ) );
                $opt_undo_envelope    = null;
                $opt_warnings         = [];
                if ( $opt_reverse_size_est > 1024 * 1024 ) {
                    $opt_warnings[] = 'undo not available — prior value exceeds 1MB storage cap. SiteVault snapshot recommended for reversal.';
                } else {
                    $opt_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                        'op'      => 'wp_update_option',
                        'summary' => sprintf( 'Restore option %s to prior value.', $name ),
                        'target'  => [ 'option_name' => $name ],
                        'pre_op_state' => [
                            'prior_value'    => $opt_previous,
                            'applied_value'  => $opt_actual,
                            'existed_before' => $opt_existed_before,
                        ],
                    ]);
                }

                // Redact only on the LLM-visible surface. The undo snapshot
                // stores the unredacted value; the option itself is in wp_options
                // in cleartext anyway, so no new exposure. Redaction here is
                // defensive against accidental leakage into chat context.
                $opt_struct = array_merge(
                    [
                        'name'     => $name,
                        'updated'  => (bool) $opt_result,
                        'previous' => $this->redact_sensitive_keys($opt_previous),
                    ],
                    \Royal_MCP\MCP\Support\WriteVerifier::response_partial( $opt_diff )
                );
                if ( ! empty( $opt_warnings ) ) {
                    $opt_struct['warnings'] = $opt_warnings;
                }
                $opt_summary = sprintf(
                    'Updated option %s%s%s.',
                    $name,
                    ! empty( $opt_diff['silent_modifies'] ) ? ' (WP modified value)' : '',
                    $opt_undo_envelope !== null ? ', undo available' : ' (undo not available: value too large)'
                );
                return \Royal_MCP\MCP\Support\Envelope::success(
                    $opt_summary,
                    $opt_struct,
                    $opt_undo_envelope
                );

            // ==================== MENUS ====================
            case 'wp_get_menus':
                if (!current_user_can('edit_theme_options')) {
                    throw new \Exception('You do not have permission to list menus.');
                }
                $menus = wp_get_nav_menus();
                return array_map(function($m) {
                    return ['id' => $m->term_id, 'name' => $m->name, 'slug' => $m->slug];
                }, $menus);

            case 'wp_get_menu_items':
                if (!current_user_can('edit_theme_options')) {
                    throw new \Exception('You do not have permission to list menu items.');
                }
                $items = wp_get_nav_menu_items(intval($args['menu_id']));
                if (!$items) return [];
                return array_map(function($i) {
                    return [
                        'id' => $i->ID,
                        'title' => $i->title,
                        'url' => $i->url,
                        'parent' => $i->menu_item_parent,
                        'order' => $i->menu_order,
                    ];
                }, $items);

            case 'wp_create_menu':
                if (!current_user_can('edit_theme_options')) {
                    throw new \Exception('edit_theme_options capability required.');
                }
                $menu_name = \Royal_MCP\MCP\Support\SafeText::field((string) ($args['name'] ?? ''));
                if ($menu_name === '') {
                    throw new \Exception('name is required.');
                }
                $new_menu_id = wp_create_nav_menu($menu_name);
                if (is_wp_error($new_menu_id)) {
                    throw new \Exception(esc_html($new_menu_id->get_error_message()));
                }
                return [
                    'menu_id' => (int) $new_menu_id,
                    'name'    => $menu_name,
                ];

            case 'wp_create_menu_item':
                if (!current_user_can('edit_theme_options')) {
                    throw new \Exception('edit_theme_options capability required.');
                }
                $menu_id = intval($args['menu_id']);
                if (!wp_get_nav_menu_object($menu_id)) {
                    throw new \Exception('Menu not found: ' . esc_html((string) $menu_id));
                }
                $object_type = sanitize_text_field($args['object_type'] ?? 'custom');
                $item_args = [
                    'menu-item-title'     => \Royal_MCP\MCP\Support\SafeText::field($args['title']),
                    'menu-item-url'       => esc_url_raw($args['url'] ?? ''),
                    'menu-item-status'    => 'publish',
                    'menu-item-type'      => $object_type === 'category' ? 'taxonomy' : ($object_type === 'custom' ? 'custom' : 'post_type'),
                    'menu-item-object'    => $object_type === 'category' ? 'category' : ($object_type === 'custom' ? '' : $object_type),
                    'menu-item-object-id' => intval($args['object_id'] ?? 0),
                    'menu-item-parent-id' => intval($args['parent_id'] ?? 0),
                    'menu-item-position'  => intval($args['position'] ?? 0),
                    'menu-item-target'    => sanitize_text_field($args['target'] ?? ''),
                ];
                $item_id = wp_update_nav_menu_item($menu_id, 0, $item_args);
                if (is_wp_error($item_id)) throw new \Exception(esc_html($item_id->get_error_message()));
                return ['menu_item_id' => (int) $item_id, 'menu_id' => $menu_id];

            case 'wp_update_menu_item':
                if (!current_user_can('edit_theme_options')) {
                    throw new \Exception('edit_theme_options capability required.');
                }
                $mi_item_id = intval($args['menu_item_id']);
                $mi_existing = get_post($mi_item_id);
                if (!$mi_existing || $mi_existing->post_type !== 'nav_menu_item') {
                    throw new \Exception('Menu item not found.');
                }
                $mi_menus = wp_get_post_terms($mi_item_id, 'nav_menu', ['fields' => 'ids']);
                $mi_menu_id = (!empty($mi_menus) && !is_wp_error($mi_menus)) ? (int) $mi_menus[0] : 0;

                // Requested-field extraction. Field-key convention matches
                // the MCP tool arg names, not the wp_update_nav_menu_item
                // menu-item-* keys — the caller sees the API it invoked.
                $mi_requested = [];
                if ( isset( $args['title'] ) )     $mi_requested['title']     = \Royal_MCP\MCP\Support\SafeText::field( $args['title'] );
                if ( isset( $args['url'] ) )       $mi_requested['url']       = esc_url_raw( $args['url'] );
                if ( isset( $args['parent_id'] ) ) $mi_requested['parent_id'] = intval( $args['parent_id'] );
                if ( isset( $args['position'] ) )  $mi_requested['position']  = intval( $args['position'] );
                if ( isset( $args['target'] ) )    $mi_requested['target']    = sanitize_text_field( $args['target'] );
                if ( empty( $mi_requested ) ) {
                    throw new \Exception('No update fields provided. Pass at least one of: title, url, parent_id, position, target.');
                }

                // Reader closure — pulls current normalized values in the
                // same shape as the requested map. wp_setup_nav_menu_item
                // does the field mapping from post columns + meta.
                $mi_read = function( $arg_key ) use ( $mi_item_id ) {
                    $item = wp_setup_nav_menu_item( get_post( $mi_item_id ) );
                    if ( ! $item ) return null;
                    switch ( $arg_key ) {
                        case 'title':     return (string) $item->title;
                        case 'url':       return (string) $item->url;
                        case 'parent_id': return (int) $item->menu_item_parent;
                        case 'position':  return (int) $item->menu_order;
                        case 'target':    return (string) $item->target;
                    }
                    return null;
                };

                // Snapshot BEFORE.
                $mi_before = [];
                foreach ( array_keys( $mi_requested ) as $mf ) {
                    $mi_before[ $mf ] = $mi_read( $mf );
                }

                // Execute (existing merge+update path; preserves unspecified fields).
                $mi_overrides = [];
                if ( isset( $mi_requested['title'] ) )     $mi_overrides['menu-item-title']     = $mi_requested['title'];
                if ( isset( $mi_requested['url'] ) )       $mi_overrides['menu-item-url']       = $mi_requested['url'];
                if ( isset( $mi_requested['parent_id'] ) ) $mi_overrides['menu-item-parent-id'] = $mi_requested['parent_id'];
                if ( isset( $mi_requested['position'] ) )  $mi_overrides['menu-item-position']  = $mi_requested['position'];
                if ( isset( $mi_requested['target'] ) )    $mi_overrides['menu-item-target']    = $mi_requested['target'];
                $mi_merged = $this->build_safe_menu_item_args( $mi_item_id, $mi_overrides );
                if ( is_wp_error( $mi_merged ) ) {
                    throw new \Exception( esc_html( $mi_merged->get_error_message() ) );
                }
                $mi_res = wp_update_nav_menu_item( $mi_menu_id, $mi_item_id, $mi_merged );
                if ( is_wp_error( $mi_res ) ) throw new \Exception( esc_html( $mi_res->get_error_message() ) );
                clean_post_cache( $mi_item_id );

                // Re-read AFTER.
                $mi_actual = [];
                foreach ( array_keys( $mi_requested ) as $mf ) {
                    $mi_actual[ $mf ] = $mi_read( $mf );
                }

                $mi_diff = \Royal_MCP\MCP\Support\WriteVerifier::diff( $mi_requested, $mi_before, $mi_actual );
                \Royal_MCP\MCP\Support\WriteVerifier::throw_if_dropped( $mi_diff, 'wp_update_menu_item' );

                // Undo — snapshot small (5 scalar fields max), no size cap needed.
                $mi_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                    'op'      => 'wp_update_menu_item',
                    'summary' => sprintf( 'Restore %d field(s) on menu item %d.', count( $mi_before ), $mi_item_id ),
                    'target'  => [ 'menu_item_id' => $mi_item_id, 'menu_id' => $mi_menu_id ],
                    'pre_op_state' => [
                        'prior_values'   => $mi_before,
                        'applied_values' => $mi_actual,
                    ],
                ]);

                $mi_struct = array_merge(
                    [
                        'menu_item_id' => $mi_item_id,
                        'menu_id'      => $mi_menu_id,
                        'updated'      => true,
                    ],
                    \Royal_MCP\MCP\Support\WriteVerifier::response_partial( $mi_diff )
                );
                $mi_summary = sprintf(
                    'Updated menu item %d in menu %d (%d field(s) applied%s), undo available.',
                    $mi_item_id,
                    $mi_menu_id,
                    count( $mi_diff['applied'] ) + count( $mi_diff['silent_modifies'] ),
                    ! empty( $mi_diff['silent_modifies'] ) ? ', WP modified value' : ''
                );
                return \Royal_MCP\MCP\Support\Envelope::success(
                    $mi_summary,
                    $mi_struct,
                    $mi_undo_envelope
                );

            case 'wp_delete_menu_item':
                if (!current_user_can('edit_theme_options')) {
                    throw new \Exception('edit_theme_options capability required.');
                }
                $dmi_iid = intval($args['menu_item_id']);
                $dmi_existing = $dmi_iid > 0 ? get_post($dmi_iid) : null;
                if (!$dmi_existing || $dmi_existing->post_type !== 'nav_menu_item') {
                    throw new \Exception('Menu item not found.');
                }

                // Snapshot BEFORE. wp_setup_nav_menu_item resolves the
                // menu-item-* postmeta into standard properties (title, url,
                // parent, position, target, etc). Also capture the parent
                // menu term relationship so undo can re-attach to the same
                // menu — new item_id, same menu.
                $dmi_item = wp_setup_nav_menu_item( $dmi_existing );
                $dmi_menus = wp_get_post_terms( $dmi_iid, 'nav_menu', [ 'fields' => 'ids' ] );
                $dmi_menu_id = ( ! is_wp_error( $dmi_menus ) && ! empty( $dmi_menus ) ) ? (int) $dmi_menus[0] : 0;
                $dmi_full = [
                    'menu_id'   => $dmi_menu_id,
                    'title'     => (string) $dmi_item->title,
                    'url'       => (string) $dmi_item->url,
                    'parent_id' => (int)    $dmi_item->menu_item_parent,
                    'position'  => (int)    $dmi_item->menu_order,
                    'target'    => (string) $dmi_item->target,
                    'object_id' => (int)    $dmi_item->object_id,
                    'object'    => (string) $dmi_item->object,
                    'type'      => (string) $dmi_item->type,  // 'post_type' | 'taxonomy' | 'custom'
                    'xfn'       => (string) $dmi_item->xfn,
                    'classes'   => is_array( $dmi_item->classes ) ? array_values( $dmi_item->classes ) : [],
                    'description' => (string) ( $dmi_item->description ?? '' ),
                ];

                $dmi_result = wp_delete_post($dmi_iid, true);
                if (!$dmi_result) throw new \Exception('Failed to delete menu item.');

                // Undo envelope. Snapshot is small (11 scalar/short fields);
                // no 1MB cap concern.
                $dmi_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                    'op'      => 'wp_delete_menu_item',
                    'summary' => sprintf( 'Recreate menu item "%s" in menu %d. Note: new item_id (auto-increment) — child items pointing at the old parent_id will not re-link.',
                        $dmi_full['title'], $dmi_menu_id ),
                    'target'  => [ 'original_menu_item_id' => $dmi_iid, 'menu_id' => $dmi_menu_id ],
                    'pre_op_state' => [
                        'row' => $dmi_full,
                    ],
                ]);

                return \Royal_MCP\MCP\Support\Envelope::success(
                    sprintf( 'Deleted menu item %d ("%s") from menu %d, undo available (recreates with new item_id).',
                        $dmi_iid, $dmi_full['title'], $dmi_menu_id ),
                    [
                        'success'         => true,
                        'menu_item_id'    => $dmi_iid,
                        'menu_id'         => $dmi_menu_id,
                        'deleted'         => true,
                        'undo_available'  => true,
                    ],
                    $dmi_undo_envelope
                );

            case 'wp_reorder_menu_items':
                if (!current_user_can('edit_theme_options')) {
                    throw new \Exception('edit_theme_options capability required.');
                }
                $menu_id = intval($args['menu_id']);
                $menu_obj = wp_get_nav_menu_object($menu_id);
                if (!$menu_obj) {
                    throw new \Exception('Menu not found.');
                }
                $order = $args['item_order'] ?? [];
                if (!is_array($order)) throw new \Exception('item_order must be an array of menu_item_ids.');

                // Snapshot the pre-op state of every item in this menu BEFORE any
                // mutation. Captures menu_order + menu_item_parent per item so a
                // full restore is possible even when the caller reorders only a
                // subset. Stored in wp_options via Undo_Store, TTL 72h.
                $pre_op_items = wp_get_nav_menu_items($menu_id) ?: [];
                $pre_op_state = [];
                foreach ($pre_op_items as $item) {
                    $pre_op_state[(int) $item->db_id] = [
                        'menu_order'       => (int) $item->menu_order,
                        'menu_item_parent' => (int) $item->menu_item_parent,
                    ];
                }

                // For each item, read existing values then send a full args
                // payload with only menu-item-position overridden. Sending
                // partial args here was the 1.4.17 destructive bug — WP merges
                // with defaults, wiping title/url/parent on every item touched.
                $position = 1;
                $reordered = [];
                $skipped = [];
                foreach ($order as $iid) {
                    $iid = intval($iid);
                    if ($iid <= 0) {
                        continue;
                    }
                    $merged = $this->build_safe_menu_item_args($iid, [
                        'menu-item-position' => $position,
                    ]);
                    if (is_wp_error($merged)) {
                        $skipped[] = ['menu_item_id' => $iid, 'reason' => $merged->get_error_message()];
                        continue;
                    }
                    $result = wp_update_nav_menu_item($menu_id, $iid, $merged);
                    if (is_wp_error($result)) {
                        $skipped[] = ['menu_item_id' => $iid, 'reason' => $result->get_error_message()];
                        continue;
                    }
                    $reordered[] = $iid;
                    $position++;
                }

                // Persist the snapshot and build the undo envelope.
                $undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                    'op'      => 'wp_reorder_menu_items',
                    'summary' => sprintf('Restore menu "%s" (%d items) to prior order', $menu_obj->name, count($pre_op_state)),
                    'target'  => ['menu_id' => $menu_id, 'menu_name' => $menu_obj->name],
                    'pre_op_state' => $pre_op_state,
                ]);

                $response = ['success' => true, 'menu_id' => $menu_id, 'count' => count($reordered), 'reordered' => $reordered];
                if (!empty($skipped)) {
                    $response['skipped'] = $skipped;
                }
                $response['undo'] = $undo_envelope;
                return $response;

            // ==================== SEO — served-HTML audit ====================
            case 'seo_audit_meta_tags':
                if (!current_user_can('read')) {
                    throw new \Exception('read capability required.');
                }
                $seo_post_id = isset($args['post_id']) ? (int) $args['post_id'] : 0;
                $seo_url     = isset($args['url']) ? esc_url_raw((string) $args['url']) : '';
                if ($seo_post_id <= 0 && $seo_url === '') {
                    throw new \Exception('Either post_id or url is required.');
                }
                if ($seo_post_id > 0) {
                    if (!get_post($seo_post_id)) {
                        throw new \Exception('Post not found: ' . esc_html((string) $seo_post_id));
                    }
                    $seo_url = get_permalink($seo_post_id);
                    if (!$seo_url) {
                        throw new \Exception('Cannot resolve permalink for post ' . esc_html((string) $seo_post_id));
                    }
                }
                // SSRF guard — only allow same-host URLs (this site's own permalinks).
                // Two-layer defense: (1) hostname must match home_url's host, (2) we IGNORE
                // caller-supplied port + scheme and rebuild the fetch URL against home_url's
                // origin. Layer 2 defeats the same-host-different-port SSRF vector (e.g.
                // caller passing example.com:3306 or example.com:6379 to probe internal
                // services bound on the same hostname).
                $seo_parts  = wp_parse_url($seo_url);
                $home_parts = wp_parse_url(home_url());
                if (!$seo_parts || empty($seo_parts['host']) || empty($home_parts['host'])) {
                    throw new \Exception('Invalid URL.');
                }
                if (strcasecmp($seo_parts['host'], $home_parts['host']) !== 0) {
                    throw new \Exception('url must be on this site (same host as home_url). Cross-domain audits are not supported.');
                }
                // Rebuild the URL against home_url's origin — caller cannot override port or
                // scheme, only path/query. This is the port-SSRF fix.
                $safe_path  = isset($seo_parts['path'])  ? $seo_parts['path']         : '/';
                $safe_query = isset($seo_parts['query']) ? '?' . $seo_parts['query']  : '';
                $seo_url    = rtrim(home_url(), '/') . $safe_path . $safe_query;
                $seo_response = wp_remote_get($seo_url, [
                    'timeout'     => 10,
                    'redirection' => 3,
                    'user-agent'  => 'Royal MCP SEO Audit',
                    'sslverify'   => true,
                ]);
                if (is_wp_error($seo_response)) {
                    throw new \Exception('Failed to fetch URL: ' . esc_html($seo_response->get_error_message()));
                }
                $seo_status = (int) wp_remote_retrieve_response_code($seo_response);
                $seo_html   = (string) wp_remote_retrieve_body($seo_response);
                if ($seo_status < 200 || $seo_status >= 300) {
                    throw new \Exception('Non-2xx HTTP status ' . $seo_status . ' when fetching ' . esc_html($seo_url));
                }
                if ($seo_html === '') {
                    throw new \Exception('Empty response body from ' . esc_html($seo_url));
                }

                // Parse the head with DOMDocument — regex on HTML is fragile.
                $prev_libxml = libxml_use_internal_errors(true);
                $seo_dom     = new \DOMDocument();
                $seo_dom->loadHTML('<?xml encoding="UTF-8">' . $seo_html);
                libxml_clear_errors();
                libxml_use_internal_errors($prev_libxml);

                // Titles
                $title_nodes  = $seo_dom->getElementsByTagName('title');
                $title_first  = $title_nodes->length > 0 ? trim($title_nodes->item(0)->textContent) : '';

                // Metas — walk once, bucket by name/property.
                $meta_desc_values     = [];
                $viewport_content     = '';
                $og_fields            = ['title' => '', 'description' => '', 'image' => '', 'url' => '', 'type' => ''];
                $tw_fields            = ['card' => '', 'title' => '', 'description' => '', 'image' => ''];
                $meta_nodes           = $seo_dom->getElementsByTagName('meta');
                foreach ($meta_nodes as $m) {
                    $name     = strtolower((string) $m->getAttribute('name'));
                    $property = strtolower((string) $m->getAttribute('property'));
                    $content  = (string) $m->getAttribute('content');
                    if ($name === 'description') {
                        $meta_desc_values[] = $content;
                    } elseif ($name === 'viewport') {
                        $viewport_content = $content;
                    } elseif (str_starts_with($property, 'og:')) {
                        $og_key = substr($property, 3);
                        if (array_key_exists($og_key, $og_fields) && $og_fields[$og_key] === '') {
                            $og_fields[$og_key] = $content;
                        }
                    } elseif (str_starts_with($name, 'twitter:')) {
                        $tw_key = substr($name, 8);
                        if (array_key_exists($tw_key, $tw_fields) && $tw_fields[$tw_key] === '') {
                            $tw_fields[$tw_key] = $content;
                        }
                    }
                }

                // Canonicals
                $canonical_hrefs = [];
                $link_nodes      = $seo_dom->getElementsByTagName('link');
                foreach ($link_nodes as $l) {
                    if (strtolower((string) $l->getAttribute('rel')) === 'canonical') {
                        $canonical_hrefs[] = (string) $l->getAttribute('href');
                    }
                }
                $canonical_first = $canonical_hrefs[0] ?? '';
                $canonical_norm  = $canonical_first !== '' ? untrailingslashit($canonical_first) : '';
                $requested_norm  = untrailingslashit($seo_url);

                // mb_strlen for character count (not byte count). strlen on
                // UTF-8 content over-reports by 2-3x on non-Latin scripts (a
                // CJK site with 20-character titles would report 60 → false
                // title_too_long on every page). Defensive fallback to strlen
                // when mbstring isn't available on the host.
                $title_len_fn = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
                return [
                    'url'    => $seo_url,
                    'status' => $seo_status,
                    'title'  => [
                        'value'      => $title_first,
                        'length'     => (int) $title_len_fn($title_first),
                        'duplicates' => max(0, $title_nodes->length - 1),
                    ],
                    'description' => [
                        'value'      => $meta_desc_values[0] ?? '',
                        'length'     => isset($meta_desc_values[0]) ? (int) $title_len_fn($meta_desc_values[0]) : 0,
                        'duplicates' => max(0, count($meta_desc_values) - 1),
                    ],
                    'canonical' => [
                        'value'      => $canonical_first,
                        'duplicates' => max(0, count($canonical_hrefs) - 1),
                        'is_self'    => $canonical_norm !== '' && strcasecmp($canonical_norm, $requested_norm) === 0,
                    ],
                    'viewport' => [
                        'present' => $viewport_content !== '',
                        'content' => $viewport_content,
                    ],
                    'og'      => $og_fields,
                    'twitter' => $tw_fields,
                ];

            // ==================== UNDO (Free basic mode) ====================
            case 'mcp_undo_last_operation':
                $undo_token = isset($args['token']) ? sanitize_text_field((string) $args['token']) : '';
                if ($undo_token === '') {
                    throw new \Exception('token is required.');
                }
                $undo_snapshot = \Royal_MCP\MCP\Undo_Store::read($undo_token);
                if (!$undo_snapshot) {
                    // Single generic message covers all failure modes (malformed,
                    // unknown, expired, consumed) — see Undo_Store::read security note.
                    throw new \Exception('Undo token not found, expired, or already consumed.');
                }
                $undo_op = $undo_snapshot['op'] ?? '';

                switch ($undo_op) {
                    case 'wp_reorder_menu_items':
                        // Cap check matches the original destructive tool.
                        if (!current_user_can('edit_theme_options')) {
                            throw new \Exception('edit_theme_options capability required to undo this operation.');
                        }
                        $pre_op = isset($undo_snapshot['pre_op_state']) && is_array($undo_snapshot['pre_op_state'])
                            ? $undo_snapshot['pre_op_state']
                            : [];
                        if (empty($pre_op)) {
                            throw new \Exception('Undo snapshot has no pre_op_state to restore.');
                        }
                        $restored_count = 0;
                        $undo_skipped   = [];
                        foreach ($pre_op as $item_id => $prior) {
                            $item_id_int = (int) $item_id;
                            if ($item_id_int <= 0 || !is_array($prior)) {
                                $undo_skipped[] = ['menu_item_id' => $item_id_int, 'reason' => 'invalid_snapshot_entry'];
                                continue;
                            }
                            $update_result = wp_update_post([
                                'ID'          => $item_id_int,
                                'menu_order'  => (int) ($prior['menu_order'] ?? 0),
                                'post_parent' => (int) ($prior['menu_item_parent'] ?? 0),
                            ], true);
                            if (is_wp_error($update_result)) {
                                $undo_skipped[] = ['menu_item_id' => $item_id_int, 'reason' => $update_result->get_error_message()];
                                continue;
                            }
                            if ($update_result === 0) {
                                // Menu item was deleted after the snapshot — nothing to restore for this id.
                                $undo_skipped[] = ['menu_item_id' => $item_id_int, 'reason' => 'menu_item_not_found'];
                                continue;
                            }
                            $restored_count++;
                        }
                        wp_cache_flush();
                        // One-shot: consume the token so the undo can't be replayed.
                        \Royal_MCP\MCP\Undo_Store::delete($undo_token);

                        $undo_struct = [
                            'undone'   => true,
                            'op'       => $undo_op,
                            'target'   => $undo_snapshot['target'] ?? [],
                            'restored' => $restored_count,
                        ];
                        if (!empty($undo_skipped)) {
                            $undo_struct['skipped'] = $undo_skipped;
                        }
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Reverted menu order — %d item(s) restored.', $restored_count ),
                            $undo_struct
                        );

                    case 'wp_update_post_meta':
                        $meta_target      = $undo_snapshot['target'] ?? [];
                        $undo_meta_pid    = (int) ( $meta_target['post_id'] ?? 0 );
                        $undo_meta_key    = (string) ( $meta_target['meta_key'] ?? '' );
                        if ( $undo_meta_pid <= 0 || $undo_meta_key === '' ) {
                            throw new \Exception('Undo snapshot missing post_id or meta_key.');
                        }
                        if ( ! current_user_can( 'edit_post', $undo_meta_pid ) ) {
                            throw new \Exception('edit_post capability required to undo this operation.');
                        }
                        $undo_meta_pre = isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] )
                            ? $undo_snapshot['pre_op_state']
                            : [];
                        if ( ! array_key_exists( 'prior_value', $undo_meta_pre ) ) {
                            throw new \Exception('Undo snapshot has no prior_value to restore.');
                        }
                        // Drift-detection — if the current value on disk isn't
                        // what our tracked op wrote, a subsequent write mutated
                        // it. Refuse rather than clobber that later write.
                        wp_cache_delete( $undo_meta_pid, 'post_meta' );
                        $undo_current = get_post_meta( $undo_meta_pid, $undo_meta_key, true );
                        $undo_applied = $undo_meta_pre['applied_value'] ?? null;
                        if ( $undo_current !== $undo_applied ) {
                            throw new \Exception('Cannot undo: post meta value was modified after the tracked operation. Current value differs from what was written. Investigate before retrying or use SiteVault to restore a full-site snapshot.');
                        }
                        update_post_meta( $undo_meta_pid, $undo_meta_key, $undo_meta_pre['prior_value'] );
                        wp_cache_delete( $undo_meta_pid, 'post_meta' );
                        // One-shot: consume the token so the undo can't be replayed.
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored %s on post %d to prior value.', $undo_meta_key, $undo_meta_pid ),
                            [
                                'undone'   => true,
                                'op'       => $undo_op,
                                'target'   => $meta_target,
                                'restored' => true,
                            ]
                        );

                    case 'wp_update_term_meta':
                        $tmeta_target     = $undo_snapshot['target'] ?? [];
                        $undo_tmeta_tid   = (int) ( $tmeta_target['term_id'] ?? 0 );
                        $undo_tmeta_key   = (string) ( $tmeta_target['meta_key'] ?? '' );
                        if ( $undo_tmeta_tid <= 0 || $undo_tmeta_key === '' ) {
                            throw new \Exception('Undo snapshot missing term_id or meta_key.');
                        }
                        if ( ! current_user_can( 'edit_term', $undo_tmeta_tid ) ) {
                            throw new \Exception('edit_term capability required to undo this operation.');
                        }
                        $undo_tmeta_pre = isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] )
                            ? $undo_snapshot['pre_op_state']
                            : [];
                        if ( ! array_key_exists( 'prior_value', $undo_tmeta_pre ) ) {
                            throw new \Exception('Undo snapshot has no prior_value to restore.');
                        }
                        wp_cache_delete( $undo_tmeta_tid, 'term_meta' );
                        $undo_tmeta_current = get_term_meta( $undo_tmeta_tid, $undo_tmeta_key, true );
                        $undo_tmeta_applied = $undo_tmeta_pre['applied_value'] ?? null;
                        if ( $undo_tmeta_current !== $undo_tmeta_applied ) {
                            throw new \Exception('Cannot undo: term meta value was modified after the tracked operation. Current value differs from what was written. Investigate before retrying or use SiteVault to restore a full-site snapshot.');
                        }
                        update_term_meta( $undo_tmeta_tid, $undo_tmeta_key, $undo_tmeta_pre['prior_value'] );
                        wp_cache_delete( $undo_tmeta_tid, 'term_meta' );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored %s on term %d to prior value.', $undo_tmeta_key, $undo_tmeta_tid ),
                            [
                                'undone'   => true,
                                'op'       => $undo_op,
                                'target'   => $tmeta_target,
                                'restored' => true,
                            ]
                        );

                    case 'wp_update_media':
                        $mundo_target = $undo_snapshot['target'] ?? [];
                        $undo_mid     = (int) ( $mundo_target['media_id'] ?? 0 );
                        if ( $undo_mid <= 0 ) {
                            throw new \Exception('Undo snapshot missing media_id.');
                        }
                        // Existence check FIRST — current_user_can('edit_post', $id)
                        // on a missing ID returns false (map_meta_cap resolves to
                        // do_not_allow), which would surface as a misleading cap
                        // error instead of the accurate "target vanished" signal.
                        $mundo_media = get_post( $undo_mid );
                        if ( ! $mundo_media || $mundo_media->post_type !== 'attachment' ) {
                            throw new \Exception('Media no longer exists — cannot restore.');
                        }
                        if ( ! current_user_can( 'edit_post', $undo_mid ) ) {
                            throw new \Exception('edit_post capability required to undo this operation.');
                        }
                        $mundo_pre    = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $mundo_prior  = ( isset( $mundo_pre['prior_values'] ) && is_array( $mundo_pre['prior_values'] ) ) ? $mundo_pre['prior_values'] : [];
                        $mundo_apply  = ( isset( $mundo_pre['applied_values'] ) && is_array( $mundo_pre['applied_values'] ) ) ? $mundo_pre['applied_values'] : [];
                        if ( empty( $mundo_prior ) ) {
                            throw new \Exception('Undo snapshot has no prior_values to restore.');
                        }
                        // Drift-detection — each recorded field must still hold
                        // the value we wrote at op-time. Any drift means a later
                        // write superseded ours; refuse rather than clobber.
                        foreach ( $mundo_apply as $mfield => $mapplied ) {
                            $mcurrent = null;
                            switch ( $mfield ) {
                                case 'title':       $mcurrent = (string) $mundo_media->post_title;   break;
                                case 'caption':     $mcurrent = (string) $mundo_media->post_excerpt; break;
                                case 'description': $mcurrent = (string) $mundo_media->post_content; break;
                                case 'alt_text':    $mcurrent = (string) get_post_meta( $undo_mid, '_wp_attachment_image_alt', true ); break;
                            }
                            if ( $mcurrent !== $mapplied ) {
                                throw new \Exception( sprintf(
                                    'Cannot undo: media field %s was modified after the tracked operation. Current value differs from what was written. Investigate before retrying or use SiteVault to restore a full-site snapshot.',
                                    esc_html( $mfield )
                                ) );
                            }
                        }
                        // Restore. Batch post-column restores in one wp_update_post.
                        $mrestore_post = [ 'ID' => $undo_mid ];
                        if ( array_key_exists( 'title', $mundo_prior ) )       $mrestore_post['post_title']   = (string) $mundo_prior['title'];
                        if ( array_key_exists( 'caption', $mundo_prior ) )     $mrestore_post['post_excerpt'] = (string) $mundo_prior['caption'];
                        if ( array_key_exists( 'description', $mundo_prior ) ) $mrestore_post['post_content'] = (string) $mundo_prior['description'];
                        if ( count( $mrestore_post ) > 1 ) {
                            $mrres = wp_update_post( $mrestore_post, true );
                            if ( is_wp_error( $mrres ) ) throw new \Exception( esc_html( $mrres->get_error_message() ) );
                        }
                        if ( array_key_exists( 'alt_text', $mundo_prior ) ) {
                            update_post_meta( $undo_mid, '_wp_attachment_image_alt', (string) $mundo_prior['alt_text'] );
                        }
                        clean_post_cache( $undo_mid );
                        wp_cache_delete( $undo_mid, 'post_meta' );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored %d field(s) on media %d to prior values.', count( $mundo_prior ), $undo_mid ),
                            [
                                'undone'   => true,
                                'op'       => $undo_op,
                                'target'   => $mundo_target,
                                'restored' => array_keys( $mundo_prior ),
                            ]
                        );

                    case 'wp_update_term':
                        $tundo_target = $undo_snapshot['target'] ?? [];
                        $undo_tid     = (int)    ( $tundo_target['term_id']  ?? 0 );
                        $undo_ttax    = (string) ( $tundo_target['taxonomy'] ?? '' );
                        if ( $undo_tid <= 0 || $undo_ttax === '' ) {
                            throw new \Exception('Undo snapshot missing term_id or taxonomy.');
                        }
                        if ( ! taxonomy_exists( $undo_ttax ) ) {
                            throw new \Exception('Taxonomy no longer exists — cannot restore.');
                        }
                        // Existence check BEFORE cap check — current_user_can
                        // on a missing term returns false (do_not_allow), which
                        // would mask the true "target vanished" signal with a
                        // misleading cap error.
                        $tundo_term = get_term( $undo_tid, $undo_ttax );
                        if ( ! $tundo_term || is_wp_error( $tundo_term ) ) {
                            throw new \Exception('Term no longer exists in the recorded taxonomy — cannot restore.');
                        }
                        if ( ! current_user_can( 'edit_term', $undo_tid ) ) {
                            throw new \Exception('edit_term capability required to undo this operation.');
                        }
                        $tundo_pre   = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $tundo_prior = ( isset( $tundo_pre['prior_values'] ) && is_array( $tundo_pre['prior_values'] ) ) ? $tundo_pre['prior_values'] : [];
                        $tundo_apply = ( isset( $tundo_pre['applied_values'] ) && is_array( $tundo_pre['applied_values'] ) ) ? $tundo_pre['applied_values'] : [];
                        if ( empty( $tundo_prior ) ) {
                            throw new \Exception('Undo snapshot has no prior_values to restore.');
                        }
                        foreach ( $tundo_apply as $tfield => $tapplied ) {
                            $tcurrent = null;
                            switch ( $tfield ) {
                                case 'name':        $tcurrent = (string) $tundo_term->name;        break;
                                case 'slug':        $tcurrent = (string) $tundo_term->slug;        break;
                                case 'description': $tcurrent = (string) $tundo_term->description; break;
                                case 'parent':      $tcurrent = (int)    $tundo_term->parent;      break;
                            }
                            if ( $tcurrent !== $tapplied ) {
                                throw new \Exception( sprintf(
                                    'Cannot undo: term field %s was modified after the tracked operation. Current value differs from what was written. Investigate before retrying or use SiteVault to restore a full-site snapshot.',
                                    esc_html( $tfield )
                                ) );
                            }
                        }
                        // Restore in a single wp_update_term call.
                        $trestore_args = [];
                        if ( array_key_exists( 'name', $tundo_prior ) )        $trestore_args['name']        = (string) $tundo_prior['name'];
                        if ( array_key_exists( 'slug', $tundo_prior ) )        $trestore_args['slug']        = (string) $tundo_prior['slug'];
                        if ( array_key_exists( 'description', $tundo_prior ) ) $trestore_args['description'] = (string) $tundo_prior['description'];
                        if ( array_key_exists( 'parent', $tundo_prior ) )      $trestore_args['parent']      = (int)    $tundo_prior['parent'];
                        $trres = wp_update_term( $undo_tid, $undo_ttax, $trestore_args );
                        if ( is_wp_error( $trres ) ) throw new \Exception( esc_html( $trres->get_error_message() ) );
                        clean_term_cache( $undo_tid, $undo_ttax );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored %d field(s) on term %d (%s) to prior values.', count( $tundo_prior ), $undo_tid, $undo_ttax ),
                            [
                                'undone'   => true,
                                'op'       => $undo_op,
                                'target'   => $tundo_target,
                                'restored' => array_keys( $tundo_prior ),
                            ]
                        );

                    case 'wc_set_product_attributes':
                        $spa_target = $undo_snapshot['target'] ?? [];
                        $undo_spa_pid = (int) ( $spa_target['product_id'] ?? 0 );
                        if ( $undo_spa_pid <= 0 ) {
                            throw new \Exception('Undo snapshot missing product_id.');
                        }
                        $spa_post = get_post( $undo_spa_pid );
                        if ( ! $spa_post || $spa_post->post_type !== 'product' ) {
                            throw new \Exception('Product no longer exists — cannot restore attributes.');
                        }
                        if ( ! current_user_can( 'edit_product', $undo_spa_pid ) ) {
                            throw new \Exception('edit_product capability required to undo this operation.');
                        }
                        $spa_undo_prod = wc_get_product( $undo_spa_pid );
                        if ( ! $spa_undo_prod ) {
                            throw new \Exception('Product no longer resolvable via WC — cannot restore attributes.');
                        }
                        $spa_pre = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $spa_snap = ( isset( $spa_pre['attributes_before'] ) && is_array( $spa_pre['attributes_before'] ) ) ? $spa_pre['attributes_before'] : [];
                        // Empty snapshot is legal (product had zero attributes
                        // before) — restore an empty set.
                        $spa_restored = \Royal_MCP\Integrations\WooCommerce::deserialize_product_attributes_public( $spa_snap );
                        $spa_undo_prod->set_attributes( $spa_restored );
                        $spa_undo_prod->save();
                        wc_delete_product_transients( $undo_spa_pid );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored %d prior attribute(s) on product %d.', count( $spa_snap ), $undo_spa_pid ),
                            [
                                'undone'          => true,
                                'op'              => $undo_op,
                                'target'          => $spa_target,
                                'restored'        => true,
                                'attribute_count' => count( $spa_snap ),
                            ]
                        );

                    case 'wc_batch_update_variations':
                        $bv_target = $undo_snapshot['target'] ?? [];
                        $undo_bv_pid = (int) ( $bv_target['product_id'] ?? 0 );
                        if ( $undo_bv_pid <= 0 ) {
                            throw new \Exception('Undo snapshot missing product_id.');
                        }
                        if ( ! function_exists( 'wc_get_product' ) ) {
                            throw new \Exception('WooCommerce is no longer active — cannot reverse batch.');
                        }
                        $bv_undo_parent = wc_get_product( $undo_bv_pid );
                        if ( ! $bv_undo_parent ) {
                            throw new \Exception('Parent product no longer exists — cannot reverse batch.');
                        }
                        if ( ! current_user_can( 'edit_product', $undo_bv_pid ) ) {
                            throw new \Exception('edit_product capability required to undo this operation.');
                        }
                        $bv_undo_pre     = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $bv_undo_created = ( isset( $bv_undo_pre['created'] ) && is_array( $bv_undo_pre['created'] ) ) ? $bv_undo_pre['created'] : [];
                        $bv_undo_updated = ( isset( $bv_undo_pre['updated'] ) && is_array( $bv_undo_pre['updated'] ) ) ? $bv_undo_pre['updated'] : [];
                        $bv_undo_deleted = ( isset( $bv_undo_pre['deleted'] ) && is_array( $bv_undo_pre['deleted'] ) ) ? $bv_undo_pre['deleted'] : [];

                        $bv_undo_result = [
                            'created_deleted'  => 0,
                            'updated_restored' => 0,
                            'deleted_recreated' => [],
                            'skipped'          => [],
                        ];

                        // Reverse the CREATE ops → delete the created variations
                        foreach ( $bv_undo_created as $new_id ) {
                            $nid = (int) $new_id;
                            if ( $nid <= 0 ) continue;
                            $post = get_post( $nid );
                            if ( ! $post || $post->post_type !== 'product_variation' ) {
                                $bv_undo_result['skipped'][] = [ 'op' => 'delete_created', 'id' => $nid, 'reason' => 'already_gone' ];
                                continue;
                            }
                            $obj = wc_get_product( $nid );
                            if ( $obj ) {
                                $obj->delete( true );
                                $bv_undo_result['created_deleted']++;
                            }
                        }

                        // Reverse the UPDATE ops → restore prior values per variation
                        foreach ( $bv_undo_updated as $entry ) {
                            $var_id = (int) ( $entry['variation_id'] ?? 0 );
                            $prior  = ( isset( $entry['prior'] ) && is_array( $entry['prior'] ) ) ? $entry['prior'] : [];
                            if ( $var_id <= 0 || empty( $prior ) ) continue;
                            $post = get_post( $var_id );
                            if ( ! $post || $post->post_type !== 'product_variation' ) {
                                $bv_undo_result['skipped'][] = [ 'op' => 'restore_update', 'id' => $var_id, 'reason' => 'variation_gone' ];
                                continue;
                            }
                            $var = wc_get_product( $var_id );
                            if ( ! $var ) continue;
                            foreach ( $prior as $f => $v ) {
                                switch ( $f ) {
                                    case 'regular_price':  $var->set_regular_price( (string) $v ); break;
                                    case 'sale_price':     $var->set_sale_price( (string) $v ); break;
                                    case 'sku':            $var->set_sku( (string) $v ); break;
                                    case 'status':         $var->set_status( (string) $v ); break;
                                    case 'manage_stock':   $var->set_manage_stock( (bool) $v ); break;
                                    case 'stock_quantity': $var->set_stock_quantity( (int) $v ); break;
                                    case 'stock_status':   $var->set_stock_status( (string) $v ); break;
                                    case 'weight':         $var->set_weight( (string) $v ); break;
                                    case 'length':         $var->set_length( (string) $v ); break;
                                    case 'width':          $var->set_width( (string) $v ); break;
                                    case 'height':         $var->set_height( (string) $v ); break;
                                    case 'description':    $var->set_description( (string) $v ); break;
                                    case 'image_id':       $var->set_image_id( (int) $v ); break;
                                    case 'attributes_json':
                                        $decoded = json_decode( (string) $v, true );
                                        if ( is_array( $decoded ) ) $var->set_attributes( $decoded );
                                        break;
                                }
                            }
                            $var->save();
                            $bv_undo_result['updated_restored']++;
                        }

                        // Reverse the DELETE ops → recreate each deleted variation with a new ID
                        foreach ( $bv_undo_deleted as $row ) {
                            if ( ! is_array( $row ) ) continue;
                            $orig_id = (int) ( $row['original_id'] ?? 0 );
                            $var = new \WC_Product_Variation();
                            $var->set_parent_id( $undo_bv_pid );
                            if ( isset( $row['regular_price'] ) )  $var->set_regular_price( (string) $row['regular_price'] );
                            if ( isset( $row['sale_price'] ) )     $var->set_sale_price( (string) $row['sale_price'] );
                            if ( isset( $row['sku'] ) )            $var->set_sku( (string) $row['sku'] );
                            if ( isset( $row['status'] ) )         $var->set_status( (string) $row['status'] );
                            if ( isset( $row['manage_stock'] ) )   $var->set_manage_stock( (bool) $row['manage_stock'] );
                            if ( isset( $row['stock_quantity'] ) ) $var->set_stock_quantity( (int) $row['stock_quantity'] );
                            if ( isset( $row['stock_status'] ) )   $var->set_stock_status( (string) $row['stock_status'] );
                            if ( isset( $row['weight'] ) )         $var->set_weight( (string) $row['weight'] );
                            if ( isset( $row['length'] ) )         $var->set_length( (string) $row['length'] );
                            if ( isset( $row['width'] ) )          $var->set_width( (string) $row['width'] );
                            if ( isset( $row['height'] ) )         $var->set_height( (string) $row['height'] );
                            if ( isset( $row['description'] ) )    $var->set_description( (string) $row['description'] );
                            if ( isset( $row['image_id'] ) )       $var->set_image_id( (int) $row['image_id'] );
                            if ( isset( $row['menu_order'] ) )     $var->set_menu_order( (int) $row['menu_order'] );
                            if ( ! empty( $row['attributes_json'] ) ) {
                                $decoded = json_decode( (string) $row['attributes_json'], true );
                                if ( is_array( $decoded ) ) $var->set_attributes( $decoded );
                            }
                            $new_id = $var->save();
                            if ( $new_id ) {
                                $bv_undo_result['deleted_recreated'][] = [ 'original_id' => $orig_id, 'new_id' => (int) $new_id ];
                            } else {
                                $bv_undo_result['skipped'][] = [ 'op' => 'recreate_delete', 'original_id' => $orig_id, 'reason' => 'save_failed' ];
                            }
                        }

                        \WC_Product_Variable::sync( $bv_undo_parent );
                        wc_delete_product_transients( $undo_bv_pid );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );

                        $bv_undo_struct = array_merge(
                            [
                                'undone'   => true,
                                'op'       => $undo_op,
                                'target'   => $bv_target,
                                'restored' => ( $bv_undo_result['created_deleted'] + $bv_undo_result['updated_restored'] + count( $bv_undo_result['deleted_recreated'] ) ) > 0,
                            ],
                            $bv_undo_result
                        );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Reversed batch on product %d: %d created→deleted, %d updated→restored, %d deleted→recreated%s.',
                                $undo_bv_pid,
                                $bv_undo_result['created_deleted'],
                                $bv_undo_result['updated_restored'],
                                count( $bv_undo_result['deleted_recreated'] ),
                                count( $bv_undo_result['skipped'] ) > 0 ? sprintf( ' (%d skipped)', count( $bv_undo_result['skipped'] ) ) : ''
                            ),
                            $bv_undo_struct
                        );

                    case 'wc_delete_variation_trash':
                        $dvt_target      = $undo_snapshot['target'] ?? [];
                        $undo_dvt_id     = (int) ( $dvt_target['variation_id'] ?? 0 );
                        $undo_dvt_pid    = (int) ( $dvt_target['product_id']   ?? 0 );
                        if ( $undo_dvt_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing variation_id.');
                        }
                        $dvt_post = get_post( $undo_dvt_id );
                        if ( ! $dvt_post || $dvt_post->post_type !== 'product_variation' ) {
                            throw new \Exception('Variation no longer exists — cannot untrash.');
                        }
                        if ( ! current_user_can( 'delete_product', $undo_dvt_id ) ) {
                            throw new \Exception('delete_product capability required to undo this operation.');
                        }
                        if ( $dvt_post->post_status !== 'trash' ) {
                            throw new \Exception( sprintf(
                                'Cannot undo: variation is not in trash (current status: %s). Someone likely acted on it after the tracked trash.',
                                esc_html( $dvt_post->post_status )
                            ) );
                        }
                        wp_untrash_post( $undo_dvt_id );
                        if ( $undo_dvt_pid > 0 && class_exists( '\WC_Product_Variable' ) ) {
                            $dvt_parent = wc_get_product( $undo_dvt_pid );
                            if ( $dvt_parent ) \WC_Product_Variable::sync( $dvt_parent );
                        }
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Untrashed variation %d and re-synced parent product %d.', $undo_dvt_id, $undo_dvt_pid ),
                            [ 'undone' => true, 'op' => $undo_op, 'target' => $dvt_target, 'restored' => true ]
                        );

                    case 'wc_delete_variation_force':
                        $dvf_target = $undo_snapshot['target'] ?? [];
                        if ( ! current_user_can( 'edit_products' ) ) {
                            throw new \Exception('edit_products capability required to undo this operation.');
                        }
                        $dvf_pre = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $dvf_row = ( isset( $dvf_pre['row'] ) && is_array( $dvf_pre['row'] ) ) ? $dvf_pre['row'] : [];
                        if ( empty( $dvf_row ) ) {
                            throw new \Exception('Undo snapshot has no row data to recreate.');
                        }
                        $dvf_parent_id = (int) ( $dvf_row['parent_id'] ?? 0 );
                        if ( $dvf_parent_id <= 0 || ! wc_get_product( $dvf_parent_id ) ) {
                            throw new \Exception('Cannot undo: parent variable product no longer exists.');
                        }
                        $dvf_new = new \WC_Product_Variation();
                        $dvf_new->set_parent_id( $dvf_parent_id );
                        if ( isset( $dvf_row['regular_price'] ) )  $dvf_new->set_regular_price( (string) $dvf_row['regular_price'] );
                        if ( isset( $dvf_row['sale_price'] ) )     $dvf_new->set_sale_price( (string) $dvf_row['sale_price'] );
                        if ( isset( $dvf_row['sku'] ) )            $dvf_new->set_sku( (string) $dvf_row['sku'] );
                        if ( isset( $dvf_row['status'] ) )         $dvf_new->set_status( (string) $dvf_row['status'] );
                        if ( isset( $dvf_row['manage_stock'] ) )   $dvf_new->set_manage_stock( (bool) $dvf_row['manage_stock'] );
                        if ( isset( $dvf_row['stock_quantity'] ) ) $dvf_new->set_stock_quantity( (int) $dvf_row['stock_quantity'] );
                        if ( isset( $dvf_row['stock_status'] ) )   $dvf_new->set_stock_status( (string) $dvf_row['stock_status'] );
                        if ( isset( $dvf_row['weight'] ) )         $dvf_new->set_weight( (string) $dvf_row['weight'] );
                        if ( isset( $dvf_row['length'] ) )         $dvf_new->set_length( (string) $dvf_row['length'] );
                        if ( isset( $dvf_row['width'] ) )          $dvf_new->set_width( (string) $dvf_row['width'] );
                        if ( isset( $dvf_row['height'] ) )         $dvf_new->set_height( (string) $dvf_row['height'] );
                        if ( isset( $dvf_row['description'] ) )    $dvf_new->set_description( (string) $dvf_row['description'] );
                        if ( isset( $dvf_row['image_id'] ) )       $dvf_new->set_image_id( (int) $dvf_row['image_id'] );
                        if ( isset( $dvf_row['menu_order'] ) )     $dvf_new->set_menu_order( (int) $dvf_row['menu_order'] );
                        if ( ! empty( $dvf_row['attributes_json'] ) ) {
                            $dvf_attrs = json_decode( (string) $dvf_row['attributes_json'], true );
                            if ( is_array( $dvf_attrs ) ) $dvf_new->set_attributes( $dvf_attrs );
                        }
                        $dvf_new_id = $dvf_new->save();
                        if ( ! $dvf_new_id ) {
                            throw new \Exception('Failed to recreate variation via wc_create.');
                        }
                        $dvf_parent = wc_get_product( $dvf_parent_id );
                        if ( $dvf_parent ) \WC_Product_Variable::sync( $dvf_parent );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Recreated force-deleted variation on product %d. New variation ID: %d (original was %d).', $dvf_parent_id, (int) $dvf_new_id, (int) ( $dvf_target['original_variation_id'] ?? 0 ) ),
                            [
                                'undone'                => true,
                                'op'                    => $undo_op,
                                'target'                => $dvf_target,
                                'restored'              => true,
                                'new_variation_id'      => (int) $dvf_new_id,
                                'original_variation_id' => (int) ( $dvf_target['original_variation_id'] ?? 0 ),
                            ]
                        );

                    case 'wc_delete_coupon_trash':
                        $dct_target      = $undo_snapshot['target'] ?? [];
                        $undo_dct_id     = (int) ( $dct_target['coupon_id'] ?? 0 );
                        if ( $undo_dct_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing coupon_id.');
                        }
                        $dct_post = get_post( $undo_dct_id );
                        if ( ! $dct_post || $dct_post->post_type !== 'shop_coupon' ) {
                            throw new \Exception('Coupon no longer exists — cannot untrash.');
                        }
                        if ( ! current_user_can( 'edit_shop_coupon', $undo_dct_id ) && ! current_user_can( 'manage_woocommerce' ) ) {
                            throw new \Exception('edit_shop_coupon capability required to undo this operation.');
                        }
                        if ( $dct_post->post_status !== 'trash' ) {
                            throw new \Exception( sprintf(
                                'Cannot undo: coupon is not in trash (current status: %s).',
                                esc_html( $dct_post->post_status )
                            ) );
                        }
                        wp_untrash_post( $undo_dct_id );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Untrashed coupon %d.', $undo_dct_id ),
                            [ 'undone' => true, 'op' => $undo_op, 'target' => $dct_target, 'restored' => true ]
                        );

                    case 'wc_delete_coupon_force':
                        $dcf_target = $undo_snapshot['target'] ?? [];
                        if ( ! current_user_can( 'manage_woocommerce' ) ) {
                            throw new \Exception('manage_woocommerce capability required to undo this operation.');
                        }
                        $dcf_pre = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $dcf_row = ( isset( $dcf_pre['row'] ) && is_array( $dcf_pre['row'] ) ) ? $dcf_pre['row'] : [];
                        if ( empty( $dcf_row ) ) {
                            throw new \Exception('Undo snapshot has no row data to recreate.');
                        }
                        if ( ! class_exists( '\WC_Coupon' ) ) {
                            throw new \Exception('WooCommerce is no longer active — cannot recreate coupon.');
                        }
                        // If a coupon with the same code now exists (someone
                        // recreated it manually or another op raced us),
                        // refuse rather than clobber.
                        $dcf_existing = wc_get_coupon_id_by_code( (string) $dcf_row['code'] );
                        if ( $dcf_existing ) {
                            throw new \Exception( sprintf(
                                'Cannot undo: a coupon with code "%s" now exists (id %d). Delete or rename it before retrying.',
                                esc_html( (string) $dcf_row['code'] ),
                                (int) $dcf_existing
                            ) );
                        }
                        $dcf_new = new \WC_Coupon();
                        $dcf_new->set_code( (string) $dcf_row['code'] );
                        if ( isset( $dcf_row['discount_type'] ) )               $dcf_new->set_discount_type( (string) $dcf_row['discount_type'] );
                        if ( isset( $dcf_row['amount'] ) )                      $dcf_new->set_amount( (string) $dcf_row['amount'] );
                        if ( isset( $dcf_row['description'] ) )                 $dcf_new->set_description( (string) $dcf_row['description'] );
                        if ( isset( $dcf_row['usage_limit'] ) )                 $dcf_new->set_usage_limit( (int) $dcf_row['usage_limit'] );
                        if ( isset( $dcf_row['usage_limit_per_user'] ) )        $dcf_new->set_usage_limit_per_user( (int) $dcf_row['usage_limit_per_user'] );
                        if ( isset( $dcf_row['limit_usage_to_x_items'] ) )      $dcf_new->set_limit_usage_to_x_items( (int) $dcf_row['limit_usage_to_x_items'] );
                        if ( isset( $dcf_row['individual_use'] ) )              $dcf_new->set_individual_use( (bool) $dcf_row['individual_use'] );
                        if ( isset( $dcf_row['free_shipping'] ) )               $dcf_new->set_free_shipping( (bool) $dcf_row['free_shipping'] );
                        if ( isset( $dcf_row['exclude_sale_items'] ) )          $dcf_new->set_exclude_sale_items( (bool) $dcf_row['exclude_sale_items'] );
                        if ( isset( $dcf_row['minimum_amount'] ) )              $dcf_new->set_minimum_amount( (string) $dcf_row['minimum_amount'] );
                        if ( isset( $dcf_row['maximum_amount'] ) )              $dcf_new->set_maximum_amount( (string) $dcf_row['maximum_amount'] );
                        if ( array_key_exists( 'date_expires', $dcf_row ) )     $dcf_new->set_date_expires( $dcf_row['date_expires'] === null ? null : (int) $dcf_row['date_expires'] );
                        if ( isset( $dcf_row['product_ids'] ) )                 $dcf_new->set_product_ids( (array) $dcf_row['product_ids'] );
                        if ( isset( $dcf_row['excluded_product_ids'] ) )        $dcf_new->set_excluded_product_ids( (array) $dcf_row['excluded_product_ids'] );
                        if ( isset( $dcf_row['product_categories'] ) )          $dcf_new->set_product_categories( (array) $dcf_row['product_categories'] );
                        if ( isset( $dcf_row['excluded_product_categories'] ) ) $dcf_new->set_excluded_product_categories( (array) $dcf_row['excluded_product_categories'] );
                        if ( isset( $dcf_row['email_restrictions'] ) )          $dcf_new->set_email_restrictions( (array) $dcf_row['email_restrictions'] );
                        $dcf_new_id = $dcf_new->save();
                        if ( ! $dcf_new_id ) {
                            throw new \Exception('Failed to recreate coupon via wc_create.');
                        }
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Recreated force-deleted coupon "%s". New coupon ID: %d (original was %d).', (string) $dcf_row['code'], (int) $dcf_new_id, (int) ( $dcf_target['original_coupon_id'] ?? 0 ) ),
                            [
                                'undone'             => true,
                                'op'                 => $undo_op,
                                'target'             => $dcf_target,
                                'restored'           => true,
                                'new_coupon_id'      => (int) $dcf_new_id,
                                'original_coupon_id' => (int) ( $dcf_target['original_coupon_id'] ?? 0 ),
                                'code'               => (string) $dcf_row['code'],
                            ]
                        );

                    case 'wc_empty_coupon_trash':
                        if ( ! current_user_can( 'manage_woocommerce' ) ) {
                            throw new \Exception('manage_woocommerce capability required to undo this operation.');
                        }
                        $ect_pre  = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $ect_rows = ( isset( $ect_pre['rows'] ) && is_array( $ect_pre['rows'] ) ) ? $ect_pre['rows'] : [];
                        if ( empty( $ect_rows ) ) {
                            throw new \Exception('Undo snapshot has no rows to recreate.');
                        }
                        if ( ! class_exists( '\WC_Coupon' ) ) {
                            throw new \Exception('WooCommerce is no longer active — cannot recreate coupons.');
                        }
                        $ect_recreated = [];
                        $ect_skipped   = [];
                        foreach ( $ect_rows as $row ) {
                            if ( ! is_array( $row ) || empty( $row['code'] ) ) continue;
                            // Skip codes that have been re-created since delete
                            if ( wc_get_coupon_id_by_code( (string) $row['code'] ) ) {
                                $ect_skipped[] = [ 'code' => (string) $row['code'], 'original_id' => (int) $row['original_id'], 'reason' => 'code_already_exists' ];
                                continue;
                            }
                            $c = new \WC_Coupon();
                            $c->set_code( (string) $row['code'] );
                            if ( isset( $row['discount_type'] ) )               $c->set_discount_type( (string) $row['discount_type'] );
                            if ( isset( $row['amount'] ) )                      $c->set_amount( (string) $row['amount'] );
                            if ( isset( $row['description'] ) )                 $c->set_description( (string) $row['description'] );
                            if ( isset( $row['usage_limit'] ) )                 $c->set_usage_limit( (int) $row['usage_limit'] );
                            if ( isset( $row['usage_limit_per_user'] ) )        $c->set_usage_limit_per_user( (int) $row['usage_limit_per_user'] );
                            if ( isset( $row['limit_usage_to_x_items'] ) )      $c->set_limit_usage_to_x_items( (int) $row['limit_usage_to_x_items'] );
                            if ( isset( $row['individual_use'] ) )              $c->set_individual_use( (bool) $row['individual_use'] );
                            if ( isset( $row['free_shipping'] ) )               $c->set_free_shipping( (bool) $row['free_shipping'] );
                            if ( isset( $row['exclude_sale_items'] ) )          $c->set_exclude_sale_items( (bool) $row['exclude_sale_items'] );
                            if ( isset( $row['minimum_amount'] ) )              $c->set_minimum_amount( (string) $row['minimum_amount'] );
                            if ( isset( $row['maximum_amount'] ) )              $c->set_maximum_amount( (string) $row['maximum_amount'] );
                            if ( array_key_exists( 'date_expires', $row ) )     $c->set_date_expires( $row['date_expires'] === null ? null : (int) $row['date_expires'] );
                            if ( isset( $row['product_ids'] ) )                 $c->set_product_ids( (array) $row['product_ids'] );
                            if ( isset( $row['excluded_product_ids'] ) )        $c->set_excluded_product_ids( (array) $row['excluded_product_ids'] );
                            if ( isset( $row['product_categories'] ) )          $c->set_product_categories( (array) $row['product_categories'] );
                            if ( isset( $row['excluded_product_categories'] ) ) $c->set_excluded_product_categories( (array) $row['excluded_product_categories'] );
                            if ( isset( $row['email_restrictions'] ) )          $c->set_email_restrictions( (array) $row['email_restrictions'] );
                            $new_id = $c->save();
                            if ( $new_id ) {
                                $ect_recreated[] = [ 'code' => (string) $row['code'], 'original_id' => (int) $row['original_id'], 'new_id' => (int) $new_id ];
                            } else {
                                $ect_skipped[] = [ 'code' => (string) $row['code'], 'original_id' => (int) $row['original_id'], 'reason' => 'save_failed' ];
                            }
                        }
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        $ect_undo_struct = [
                            'undone'          => true,
                            'op'              => $undo_op,
                            'target'          => $undo_snapshot['target'] ?? [],
                            'restored'        => count( $ect_recreated ) > 0,
                            'recreated_count' => count( $ect_recreated ),
                            'skipped_count'   => count( $ect_skipped ),
                        ];
                        if ( ! empty( $ect_recreated ) ) $ect_undo_struct['recreated'] = $ect_recreated;
                        if ( ! empty( $ect_skipped ) )   $ect_undo_struct['skipped']   = $ect_skipped;
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Recreated %d coupon(s) from bulk-trash-empty snapshot%s.',
                                count( $ect_recreated ),
                                count( $ect_skipped ) > 0 ? sprintf( ', %d skipped (code collisions or save failures)', count( $ect_skipped ) ) : ''
                            ),
                            $ect_undo_struct
                        );

                    case 'wc_create_product':
                        $cp_target      = $undo_snapshot['target'] ?? [];
                        $undo_cp_id     = (int) ( $cp_target['product_id'] ?? 0 );
                        if ( $undo_cp_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing product_id.');
                        }
                        $cp_existing = get_post( $undo_cp_id );
                        if ( ! $cp_existing || $cp_existing->post_type !== 'product' ) {
                            \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                            return \Royal_MCP\MCP\Support\Envelope::success(
                                sprintf( 'No-op: product %d was already removed.', $undo_cp_id ),
                                [ 'undone' => true, 'op' => $undo_op, 'target' => $cp_target, 'restored' => false, 'reason' => 'row_already_gone' ]
                            );
                        }
                        if ( ! current_user_can( 'delete_product', $undo_cp_id ) ) {
                            throw new \Exception('delete_product capability required to undo this operation.');
                        }
                        if ( function_exists( 'wc_get_product' ) ) {
                            $cp_prod = wc_get_product( $undo_cp_id );
                            if ( $cp_prod ) $cp_prod->delete( true );
                            else wp_delete_post( $undo_cp_id, true );
                        } else {
                            wp_delete_post( $undo_cp_id, true );
                        }
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Deleted product %d.', $undo_cp_id ),
                            [ 'undone' => true, 'op' => $undo_op, 'target' => $cp_target, 'restored' => true ]
                        );

                    case 'wc_create_order':
                        $co_target  = $undo_snapshot['target'] ?? [];
                        $undo_co_id = (int) ( $co_target['order_id'] ?? 0 );
                        if ( $undo_co_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing order_id.');
                        }
                        if ( ! function_exists( 'wc_get_order' ) ) {
                            throw new \Exception('WooCommerce is no longer active — cannot delete order.');
                        }
                        $co_order = wc_get_order( $undo_co_id );
                        if ( ! $co_order || ! $co_order instanceof \WC_Order ) {
                            \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                            return \Royal_MCP\MCP\Support\Envelope::success(
                                sprintf( 'No-op: order %d was already removed.', $undo_co_id ),
                                [ 'undone' => true, 'op' => $undo_op, 'target' => $co_target, 'restored' => false, 'reason' => 'row_already_gone' ]
                            );
                        }
                        if ( ! current_user_can( 'edit_shop_orders' ) ) {
                            throw new \Exception('edit_shop_orders capability required to undo this operation.');
                        }
                        $co_order->delete( true );  // HPOS-aware hard delete
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Deleted order %d.', $undo_co_id ),
                            [ 'undone' => true, 'op' => $undo_op, 'target' => $co_target, 'restored' => true ]
                        );

                    case 'wc_create_variation':
                        $cv_target      = $undo_snapshot['target'] ?? [];
                        $undo_cv_id     = (int) ( $cv_target['variation_id'] ?? 0 );
                        $undo_cv_pid    = (int) ( $cv_target['product_id']   ?? 0 );
                        if ( $undo_cv_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing variation_id.');
                        }
                        $cv_existing = get_post( $undo_cv_id );
                        if ( ! $cv_existing || $cv_existing->post_type !== 'product_variation' ) {
                            \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                            return \Royal_MCP\MCP\Support\Envelope::success(
                                sprintf( 'No-op: variation %d was already removed.', $undo_cv_id ),
                                [ 'undone' => true, 'op' => $undo_op, 'target' => $cv_target, 'restored' => false, 'reason' => 'row_already_gone' ]
                            );
                        }
                        if ( ! current_user_can( 'delete_product', $undo_cv_id ) ) {
                            throw new \Exception('delete_product capability required to undo this operation.');
                        }
                        if ( function_exists( 'wc_get_product' ) ) {
                            $cv_var = wc_get_product( $undo_cv_id );
                            if ( $cv_var ) $cv_var->delete( true );
                            else wp_delete_post( $undo_cv_id, true );
                        } else {
                            wp_delete_post( $undo_cv_id, true );
                        }
                        // Re-sync parent to update price range + stock aggregation
                        if ( $undo_cv_pid > 0 && class_exists( '\WC_Product_Variable' ) ) {
                            $cv_parent = wc_get_product( $undo_cv_pid );
                            if ( $cv_parent ) \WC_Product_Variable::sync( $cv_parent );
                        }
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Deleted variation %d and re-synced parent product %d.', $undo_cv_id, $undo_cv_pid ),
                            [ 'undone' => true, 'op' => $undo_op, 'target' => $cv_target, 'restored' => true ]
                        );

                    case 'wc_create_coupon':
                        $cc_target  = $undo_snapshot['target'] ?? [];
                        $undo_cc_id = (int) ( $cc_target['coupon_id'] ?? 0 );
                        if ( $undo_cc_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing coupon_id.');
                        }
                        $cc_existing = get_post( $undo_cc_id );
                        if ( ! $cc_existing || $cc_existing->post_type !== 'shop_coupon' ) {
                            \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                            return \Royal_MCP\MCP\Support\Envelope::success(
                                sprintf( 'No-op: coupon %d was already removed.', $undo_cc_id ),
                                [ 'undone' => true, 'op' => $undo_op, 'target' => $cc_target, 'restored' => false, 'reason' => 'row_already_gone' ]
                            );
                        }
                        if ( ! current_user_can( 'delete_shop_coupon', $undo_cc_id ) && ! current_user_can( 'manage_woocommerce' ) ) {
                            throw new \Exception('delete_shop_coupon capability required to undo this operation.');
                        }
                        // Route through WC_Coupon::delete so WC's own coupon-code
                        // cache (wc_get_coupon_id_by_code) invalidates via the
                        // woocommerce_delete_coupon action. Plain wp_delete_post
                        // leaves the code lookup returning the stale ID, breaking
                        // subsequent wc_create_coupon calls with the same code.
                        if ( class_exists( '\WC_Coupon' ) ) {
                            $cc_undo_coupon = new \WC_Coupon( $undo_cc_id );
                            $cc_undo_coupon->delete( true );
                        } else {
                            wp_delete_post( $undo_cc_id, true );
                        }
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Deleted coupon %d.', $undo_cc_id ),
                            [ 'undone' => true, 'op' => $undo_op, 'target' => $cc_target, 'restored' => true ]
                        );

                    case 'wc_create_product_attribute':
                        $cpa_target  = $undo_snapshot['target'] ?? [];
                        $undo_cpa_id = (int) ( $cpa_target['attribute_id'] ?? 0 );
                        if ( $undo_cpa_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing attribute_id.');
                        }
                        if ( ! function_exists( 'wc_delete_attribute' ) ) {
                            throw new \Exception('WooCommerce is no longer active — cannot delete attribute.');
                        }
                        if ( ! current_user_can( 'manage_product_terms' ) ) {
                            throw new \Exception('manage_product_terms capability required to undo this operation.');
                        }
                        $cpa_ok = wc_delete_attribute( $undo_cpa_id );
                        if ( ! $cpa_ok ) {
                            // Attribute may already be gone — treat as idempotent
                            \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                            return \Royal_MCP\MCP\Support\Envelope::success(
                                sprintf( 'No-op: attribute %d was already removed.', $undo_cpa_id ),
                                [ 'undone' => true, 'op' => $undo_op, 'target' => $cpa_target, 'restored' => false, 'reason' => 'row_already_gone' ]
                            );
                        }
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Deleted product attribute %d.', $undo_cpa_id ),
                            [ 'undone' => true, 'op' => $undo_op, 'target' => $cpa_target, 'restored' => true ]
                        );

                    case 'wp_delete_menu_item':
                        $dmi_target = $undo_snapshot['target'] ?? [];
                        $undo_dmi_orig = (int) ( $dmi_target['original_menu_item_id'] ?? 0 );
                        $undo_dmi_mid  = (int) ( $dmi_target['menu_id'] ?? 0 );
                        if ( ! current_user_can( 'edit_theme_options' ) ) {
                            throw new \Exception('edit_theme_options capability required to undo this operation.');
                        }
                        $dmi_pre = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $dmi_row = ( isset( $dmi_pre['row'] ) && is_array( $dmi_pre['row'] ) ) ? $dmi_pre['row'] : [];
                        if ( empty( $dmi_row ) ) {
                            throw new \Exception('Undo snapshot has no row data to recreate.');
                        }
                        // Verify parent menu still exists
                        $dmi_menu_obj = $undo_dmi_mid > 0 ? wp_get_nav_menu_object( $undo_dmi_mid ) : null;
                        if ( ! $dmi_menu_obj ) {
                            throw new \Exception('Parent menu no longer exists — cannot recreate menu item.');
                        }
                        // Recreate via wp_update_nav_menu_item with menu-item-* args.
                        // Pass menu_item_id=0 to create a new item.
                        $dmi_args = [
                            'menu-item-title'     => (string) ( $dmi_row['title']       ?? '' ),
                            'menu-item-url'       => (string) ( $dmi_row['url']         ?? '' ),
                            'menu-item-parent-id' => (int)    ( $dmi_row['parent_id']   ?? 0 ),
                            'menu-item-position'  => (int)    ( $dmi_row['position']    ?? 0 ),
                            'menu-item-target'    => (string) ( $dmi_row['target']      ?? '' ),
                            'menu-item-object-id' => (int)    ( $dmi_row['object_id']   ?? 0 ),
                            'menu-item-object'    => (string) ( $dmi_row['object']      ?? '' ),
                            'menu-item-type'      => (string) ( $dmi_row['type']        ?? 'custom' ),
                            'menu-item-xfn'       => (string) ( $dmi_row['xfn']         ?? '' ),
                            'menu-item-classes'   => is_array( $dmi_row['classes'] ?? null ) ? implode( ' ', $dmi_row['classes'] ) : '',
                            'menu-item-description' => (string) ( $dmi_row['description'] ?? '' ),
                            'menu-item-status'    => 'publish',
                        ];
                        $dmi_new_id = wp_update_nav_menu_item( $undo_dmi_mid, 0, $dmi_args );
                        if ( is_wp_error( $dmi_new_id ) ) {
                            throw new \Exception( esc_html( $dmi_new_id->get_error_message() ) );
                        }
                        clean_post_cache( $dmi_new_id );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Recreated menu item "%s" in menu %d. New menu_item_id: %d (original was %d). Child items still pointing at old parent_id are NOT re-linked.',
                                (string) $dmi_row['title'],
                                $undo_dmi_mid,
                                (int) $dmi_new_id,
                                $undo_dmi_orig
                            ),
                            [
                                'undone'                => true,
                                'op'                    => $undo_op,
                                'target'                => $dmi_target,
                                'restored'              => true,
                                'new_menu_item_id'      => (int) $dmi_new_id,
                                'original_menu_item_id' => $undo_dmi_orig,
                                'menu_id'               => $undo_dmi_mid,
                            ]
                        );

                    case 'wp_delete_term':
                        $dt_target = $undo_snapshot['target'] ?? [];
                        $undo_dt_orig  = (int) ( $dt_target['original_term_id'] ?? 0 );
                        $undo_dt_tax   = (string) ( $dt_target['taxonomy'] ?? '' );
                        if ( $undo_dt_tax === '' ) {
                            throw new \Exception('Undo snapshot missing taxonomy.');
                        }
                        if ( ! taxonomy_exists( $undo_dt_tax ) ) {
                            throw new \Exception('Taxonomy no longer registered — cannot recreate term.');
                        }
                        if ( ! current_user_can( 'manage_categories' ) && ! current_user_can( 'edit_terms', $undo_dt_orig ) ) {
                            throw new \Exception('manage_categories or edit_terms capability required to undo this operation.');
                        }
                        $dt_pre = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $dt_row = ( isset( $dt_pre['row'] ) && is_array( $dt_pre['row'] ) ) ? $dt_pre['row'] : [];
                        if ( empty( $dt_row ) ) {
                            throw new \Exception('Undo snapshot has no row data to recreate.');
                        }
                        // If a term with the same slug now exists (someone
                        // recreated it manually or another op raced us),
                        // refuse rather than duplicate.
                        $dt_existing = get_term_by( 'slug', (string) $dt_row['slug'], $undo_dt_tax );
                        if ( $dt_existing ) {
                            throw new \Exception( sprintf(
                                'Cannot undo: a term with slug "%s" now exists in %s (term_id %d). Delete or rename it before retrying.',
                                esc_html( (string) $dt_row['slug'] ),
                                esc_html( $undo_dt_tax ),
                                (int) $dt_existing->term_id
                            ) );
                        }
                        // Recreate via wp_insert_term
                        $dt_insert_args = [
                            'description' => (string) ( $dt_row['description'] ?? '' ),
                            'parent'      => (int)    ( $dt_row['parent']      ?? 0 ),
                            'slug'        => (string) ( $dt_row['slug']        ?? '' ),
                        ];
                        $dt_res = wp_insert_term( (string) $dt_row['name'], $undo_dt_tax, $dt_insert_args );
                        if ( is_wp_error( $dt_res ) ) {
                            throw new \Exception( esc_html( $dt_res->get_error_message() ) );
                        }
                        $dt_new_tid = (int) $dt_res['term_id'];
                        // Replay term meta
                        if ( isset( $dt_row['meta'] ) && is_array( $dt_row['meta'] ) ) {
                            foreach ( $dt_row['meta'] as $mk => $mvals ) {
                                foreach ( (array) $mvals as $mv ) {
                                    add_term_meta( $dt_new_tid, (string) $mk, $mv, false );
                                }
                            }
                        }
                        clean_term_cache( $dt_new_tid, $undo_dt_tax );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Recreated term "%s" in %s. New term_id: %d (original was %d). Object relationships NOT re-linked.',
                                (string) $dt_row['name'],
                                $undo_dt_tax,
                                $dt_new_tid,
                                $undo_dt_orig
                            ),
                            [
                                'undone'             => true,
                                'op'                 => $undo_op,
                                'target'             => $dt_target,
                                'restored'           => true,
                                'new_term_id'        => $dt_new_tid,
                                'original_term_id'   => $undo_dt_orig,
                                'meta_keys_replayed' => count( $dt_row['meta'] ?? [] ),
                            ]
                        );

                    case 'wp_delete_post_trash':
                        $dpt_target = $undo_snapshot['target'] ?? [];
                        $undo_dpt_id = (int) ( $dpt_target['post_id'] ?? 0 );
                        if ( $undo_dpt_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing post_id.');
                        }
                        $dpt_post = get_post( $undo_dpt_id );
                        if ( ! $dpt_post ) {
                            throw new \Exception('Post no longer exists — cannot untrash.');
                        }
                        if ( ! current_user_can( 'delete_post', $undo_dpt_id ) ) {
                            throw new \Exception('delete_post capability required to undo this operation.');
                        }
                        if ( $dpt_post->post_status !== 'trash' ) {
                            throw new \Exception( sprintf(
                                'Cannot undo: post is not in trash (current status: %s). Someone likely acted on it after the tracked trash.',
                                esc_html( $dpt_post->post_status )
                            ) );
                        }
                        wp_untrash_post( $undo_dpt_id );
                        clean_post_cache( $undo_dpt_id );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Untrashed post %d.', $undo_dpt_id ),
                            [
                                'undone'   => true,
                                'op'       => $undo_op,
                                'target'   => $dpt_target,
                                'restored' => true,
                            ]
                        );

                    case 'wp_delete_post_force':
                        $dpf_target = $undo_snapshot['target'] ?? [];
                        if ( ! current_user_can( 'delete_posts' ) ) {
                            throw new \Exception('delete_posts capability required to undo this operation.');
                        }
                        $dpf_pre = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $dpf_row = ( isset( $dpf_pre['row'] ) && is_array( $dpf_pre['row'] ) ) ? $dpf_pre['row'] : [];
                        if ( empty( $dpf_row ) ) {
                            throw new \Exception('Undo snapshot has no row data to recreate.');
                        }
                        // Rebuild via wp_insert_post — auto-increment gives a new ID.
                        $dpf_insert_args = [
                            'post_type'      => (string) $dpf_row['post_type'],
                            'post_title'     => (string) ( $dpf_row['post_title']     ?? '' ),
                            'post_content'   => (string) ( $dpf_row['post_content']   ?? '' ),
                            'post_excerpt'   => (string) ( $dpf_row['post_excerpt']   ?? '' ),
                            'post_status'    => (string) ( $dpf_row['post_status']    ?? 'draft' ),
                            'post_name'      => (string) ( $dpf_row['post_name']      ?? '' ),
                            'post_author'    => (int)    ( $dpf_row['post_author']    ?? 0 ),
                            'post_parent'    => (int)    ( $dpf_row['post_parent']    ?? 0 ),
                            'menu_order'     => (int)    ( $dpf_row['menu_order']     ?? 0 ),
                            'post_password'  => (string) ( $dpf_row['post_password']  ?? '' ),
                            'comment_status' => (string) ( $dpf_row['comment_status'] ?? 'open' ),
                            'ping_status'    => (string) ( $dpf_row['ping_status']    ?? 'open' ),
                            'post_date'      => (string) ( $dpf_row['post_date']      ?? '' ),
                            'post_date_gmt'  => (string) ( $dpf_row['post_date_gmt']  ?? '' ),
                            'post_mime_type' => (string) ( $dpf_row['post_mime_type'] ?? '' ),
                        ];
                        $dpf_new_id = wp_insert_post( $dpf_insert_args, true );
                        if ( is_wp_error( $dpf_new_id ) ) {
                            throw new \Exception( esc_html( $dpf_new_id->get_error_message() ) );
                        }
                        // Replay postmeta (each key can have multiple values).
                        // Use add_post_meta with $unique=false so multi-row keys
                        // (like ACF Pro repeaters, _elementor_data replicas) survive.
                        if ( isset( $dpf_row['meta'] ) && is_array( $dpf_row['meta'] ) ) {
                            foreach ( $dpf_row['meta'] as $mk => $mvals ) {
                                foreach ( (array) $mvals as $mv ) {
                                    add_post_meta( $dpf_new_id, (string) $mk, $mv, false );
                                }
                            }
                        }
                        // Replay term relationships per taxonomy.
                        if ( isset( $dpf_row['terms'] ) && is_array( $dpf_row['terms'] ) ) {
                            foreach ( $dpf_row['terms'] as $tax => $slugs ) {
                                if ( taxonomy_exists( (string) $tax ) && is_array( $slugs ) && ! empty( $slugs ) ) {
                                    wp_set_object_terms( $dpf_new_id, $slugs, (string) $tax, false );
                                }
                            }
                        }
                        clean_post_cache( $dpf_new_id );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Recreated force-deleted %s. New post ID: %d (original was %d).',
                                (string) $dpf_row['post_type'],
                                (int) $dpf_new_id,
                                (int) ( $dpf_target['original_post_id'] ?? 0 )
                            ),
                            [
                                'undone'           => true,
                                'op'               => $undo_op,
                                'target'           => $dpf_target,
                                'restored'         => true,
                                'new_post_id'      => (int) $dpf_new_id,
                                'original_post_id' => (int) ( $dpf_target['original_post_id'] ?? 0 ),
                                'meta_keys_replayed' => count( $dpf_row['meta'] ?? [] ),
                                'taxonomies_replayed' => count( $dpf_row['terms'] ?? [] ),
                            ]
                        );

                    case 'wp_update_post':
                        $up_undo_target = $undo_snapshot['target'] ?? [];
                        $undo_up_pid    = (int) ( $up_undo_target['post_id'] ?? 0 );
                        if ( $undo_up_pid <= 0 ) {
                            throw new \Exception('Undo snapshot missing post_id.');
                        }
                        $up_undo_post = get_post( $undo_up_pid );
                        if ( ! $up_undo_post ) {
                            throw new \Exception('Post no longer exists — cannot restore.');
                        }
                        if ( ! current_user_can( 'edit_post', $undo_up_pid ) ) {
                            throw new \Exception('edit_post capability required to undo this operation.');
                        }
                        $up_undo_pre   = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $up_undo_prior = ( isset( $up_undo_pre['prior_values'] ) && is_array( $up_undo_pre['prior_values'] ) ) ? $up_undo_pre['prior_values'] : [];
                        if ( empty( $up_undo_prior ) ) {
                            throw new \Exception('Undo snapshot has no prior_values to restore.');
                        }
                        // Rebuild the update array from prior_values. edit_date=true
                        // is required for post_date restore (see forward-path
                        // scheduling handler comment).
                        $up_undo_data = [ 'ID' => $undo_up_pid ];
                        foreach ( $up_undo_prior as $prop => $val ) {
                            if ( in_array( $prop, [ 'post_title', 'post_content', 'post_status', 'post_excerpt', 'post_author', 'menu_order', 'post_parent', 'post_password', 'comment_status', 'ping_status', 'post_date', 'post_date_gmt' ], true ) ) {
                                $up_undo_data[ $prop ] = $val;
                            }
                        }
                        if ( isset( $up_undo_data['post_date'] ) ) {
                            $up_undo_data['edit_date'] = true;
                        }
                        $up_undo_res = wp_update_post( $up_undo_data, true );
                        if ( is_wp_error( $up_undo_res ) ) {
                            throw new \Exception( esc_html( $up_undo_res->get_error_message() ) );
                        }
                        // Restore WC product_type term if snapshotted (the
                        // product-downgrade preservation kicks in on the
                        // forward path; undo needs to reapply here too since
                        // wp_update_post above may have triggered save_post
                        // handlers that reset product_type again).
                        if ( ! empty( $up_undo_pre['is_product'] ) && ! empty( $up_undo_pre['prior_product_type'] ) ) {
                            wp_set_object_terms( $undo_up_pid, [ (string) $up_undo_pre['prior_product_type'] ], 'product_type', false );
                        }
                        // Restore featured media if snapshotted
                        if ( array_key_exists( 'prior_featured_id', $up_undo_pre ) ) {
                            $prior_fid = (int) $up_undo_pre['prior_featured_id'];
                            if ( $prior_fid > 0 ) {
                                set_post_thumbnail( $undo_up_pid, $prior_fid );
                            } else {
                                delete_post_thumbnail( $undo_up_pid );
                            }
                        }
                        clean_post_cache( $undo_up_pid );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored post %d to prior state (%d field(s)%s).',
                                $undo_up_pid,
                                count( $up_undo_prior ),
                                ! empty( $up_undo_pre['prior_product_type'] ) ? ', product_type restored' : ''
                            ),
                            [
                                'undone'   => true,
                                'op'       => $undo_op,
                                'target'   => $up_undo_target,
                                'restored' => array_keys( $up_undo_prior ),
                            ]
                        );

                    case 'wc_update_variation':
                        $uv_undo_target = $undo_snapshot['target'] ?? [];
                        $undo_uv_id     = (int) ( $uv_undo_target['variation_id'] ?? 0 );
                        $undo_uv_pid    = (int) ( $uv_undo_target['product_id']   ?? 0 );
                        if ( $undo_uv_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing variation_id.');
                        }
                        if ( ! function_exists( 'wc_get_product' ) ) {
                            throw new \Exception('WooCommerce is no longer active — cannot restore variation fields.');
                        }
                        // Existence check via get_post first — wc_get_product
                        // hits WC's own product cache which can return a stale
                        // object after a hard-delete, causing this branch to
                        // pass and the cap check below to fire with a
                        // misleading "cap required" error. get_post reads
                        // wp_posts directly, so vanished target is caught
                        // accurately.
                        $uv_undo_post = get_post( $undo_uv_id );
                        if ( ! $uv_undo_post || $uv_undo_post->post_type !== 'product_variation' ) {
                            throw new \Exception('Variation no longer exists — cannot restore.');
                        }
                        $uv_undo_var = wc_get_product( $undo_uv_id );
                        if ( ! $uv_undo_var || ! $uv_undo_var->is_type( 'variation' ) ) {
                            throw new \Exception('Variation no longer exists — cannot restore.');
                        }
                        if ( ! current_user_can( 'edit_product', $undo_uv_id ) ) {
                            throw new \Exception('edit_product capability required to undo this operation.');
                        }
                        $uv_undo_pre    = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $uv_undo_prior  = ( isset( $uv_undo_pre['prior_values'] ) && is_array( $uv_undo_pre['prior_values'] ) ) ? $uv_undo_pre['prior_values'] : [];
                        $uv_undo_apply  = ( isset( $uv_undo_pre['applied_values'] ) && is_array( $uv_undo_pre['applied_values'] ) ) ? $uv_undo_pre['applied_values'] : [];
                        if ( empty( $uv_undo_prior ) && empty( $uv_undo_pre['attributes_before_json'] ) ) {
                            throw new \Exception('Undo snapshot has no prior_values to restore.');
                        }
                        $uv_undo_read = function( $arg_key ) use ( $uv_undo_var ) {
                            switch ( $arg_key ) {
                                case 'regular_price':      return (string) $uv_undo_var->get_regular_price();
                                case 'sale_price':         return (string) $uv_undo_var->get_sale_price();
                                case 'sku':                return (string) $uv_undo_var->get_sku();
                                case 'status':             return (string) $uv_undo_var->get_status();
                                case 'manage_stock':       return (bool)   $uv_undo_var->get_manage_stock();
                                case 'stock_quantity':     return (int)    $uv_undo_var->get_stock_quantity();
                                case 'stock_status':       return (string) $uv_undo_var->get_stock_status();
                                case 'weight':             return (string) $uv_undo_var->get_weight();
                                case 'description':        return (string) $uv_undo_var->get_description();
                                case 'image_id':           return (int)    $uv_undo_var->get_image_id();
                                case 'dimensions.length':  return (string) $uv_undo_var->get_length();
                                case 'dimensions.width':   return (string) $uv_undo_var->get_width();
                                case 'dimensions.height':  return (string) $uv_undo_var->get_height();
                            }
                            return null;
                        };
                        foreach ( $uv_undo_apply as $arg_key => $applied ) {
                            $current = $uv_undo_read( $arg_key );
                            if ( $current !== $applied ) {
                                throw new \Exception( sprintf(
                                    'Cannot undo: variation field %s was modified after the tracked operation. Current value differs from what was written. Investigate before retrying or use SiteVault to restore.',
                                    esc_html( $arg_key )
                                ) );
                            }
                        }
                        foreach ( $uv_undo_prior as $arg_key => $prior ) {
                            switch ( $arg_key ) {
                                case 'regular_price':      $uv_undo_var->set_regular_price( (string) $prior ); break;
                                case 'sale_price':         $uv_undo_var->set_sale_price( (string) $prior ); break;
                                case 'sku':                $uv_undo_var->set_sku( (string) $prior ); break;
                                case 'status':             $uv_undo_var->set_status( (string) $prior ); break;
                                case 'manage_stock':       $uv_undo_var->set_manage_stock( (bool) $prior ); break;
                                case 'stock_quantity':     $uv_undo_var->set_stock_quantity( (int) $prior ); break;
                                case 'stock_status':       $uv_undo_var->set_stock_status( (string) $prior ); break;
                                case 'weight':             $uv_undo_var->set_weight( (string) $prior ); break;
                                case 'description':        $uv_undo_var->set_description( (string) $prior ); break;
                                case 'image_id':           $uv_undo_var->set_image_id( (int) $prior ); break;
                                case 'dimensions.length':  $uv_undo_var->set_length( (string) $prior ); break;
                                case 'dimensions.width':   $uv_undo_var->set_width( (string) $prior ); break;
                                case 'dimensions.height':  $uv_undo_var->set_height( (string) $prior ); break;
                            }
                        }
                        // Restore attributes if snapshotted (json-encoded to
                        // preserve mixed-key arrays through storage round-trip).
                        if ( ! empty( $uv_undo_pre['attributes_before_json'] ) ) {
                            $prior_attrs = json_decode( (string) $uv_undo_pre['attributes_before_json'], true );
                            if ( is_array( $prior_attrs ) ) {
                                $uv_undo_var->set_attributes( $prior_attrs );
                            }
                        }
                        $uv_undo_var->save();
                        if ( $undo_uv_pid > 0 ) {
                            $parent = wc_get_product( $undo_uv_pid );
                            if ( $parent ) \WC_Product_Variable::sync( $parent );
                        }
                        wc_delete_product_transients( $undo_uv_id );
                        if ( $undo_uv_pid > 0 ) wc_delete_product_transients( $undo_uv_pid );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored variation %d fields to prior values.', $undo_uv_id ),
                            [
                                'undone'   => true,
                                'op'       => $undo_op,
                                'target'   => $uv_undo_target,
                                'restored' => array_keys( $uv_undo_prior ),
                            ]
                        );

                    case 'wc_update_coupon':
                        $uc_undo_target = $undo_snapshot['target'] ?? [];
                        $undo_uc_id     = (int) ( $uc_undo_target['coupon_id'] ?? 0 );
                        if ( $undo_uc_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing coupon_id.');
                        }
                        if ( ! class_exists( '\WC_Coupon' ) ) {
                            throw new \Exception('WooCommerce is no longer active — cannot restore coupon.');
                        }
                        $uc_undo_coupon = new \WC_Coupon( $undo_uc_id );
                        if ( ! $uc_undo_coupon->get_id() || get_post_type( $uc_undo_coupon->get_id() ) !== 'shop_coupon' ) {
                            throw new \Exception('Coupon no longer exists — cannot restore.');
                        }
                        if ( ! current_user_can( 'edit_shop_coupon', $undo_uc_id ) && ! current_user_can( 'manage_woocommerce' ) ) {
                            throw new \Exception('edit_shop_coupon capability required to undo this operation.');
                        }
                        $uc_undo_pre    = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $uc_undo_prior  = ( isset( $uc_undo_pre['prior_values'] ) && is_array( $uc_undo_pre['prior_values'] ) ) ? $uc_undo_pre['prior_values'] : [];
                        $uc_undo_apply  = ( isset( $uc_undo_pre['applied_values'] ) && is_array( $uc_undo_pre['applied_values'] ) ) ? $uc_undo_pre['applied_values'] : [];
                        if ( empty( $uc_undo_prior ) ) {
                            throw new \Exception('Undo snapshot has no prior_values to restore.');
                        }
                        $uc_undo_read = function( $arg_key ) use ( $undo_uc_id ) {
                            $c = new \WC_Coupon( $undo_uc_id );
                            switch ( $arg_key ) {
                                case 'code':                        return (string) $c->get_code();
                                case 'discount_type':               return (string) $c->get_discount_type();
                                case 'amount':                      return (string) $c->get_amount();
                                case 'description':                 return (string) $c->get_description();
                                case 'usage_limit':                 return (int)    $c->get_usage_limit();
                                case 'usage_limit_per_user':        return (int)    $c->get_usage_limit_per_user();
                                case 'limit_usage_to_x_items':      return (int)    $c->get_limit_usage_to_x_items();
                                case 'individual_use':              return (bool)   $c->get_individual_use();
                                case 'free_shipping':               return (bool)   $c->get_free_shipping();
                                case 'exclude_sale_items':          return (bool)   $c->get_exclude_sale_items();
                                case 'minimum_amount':              return (string) $c->get_minimum_amount();
                                case 'maximum_amount':              return (string) $c->get_maximum_amount();
                                case 'date_expires':                $d = $c->get_date_expires(); return $d ? (int) $d->getTimestamp() : null;
                                case 'product_ids':                 return array_values( array_map( 'intval', (array) $c->get_product_ids() ) );
                                case 'excluded_product_ids':        return array_values( array_map( 'intval', (array) $c->get_excluded_product_ids() ) );
                                case 'product_categories':          return array_values( array_map( 'intval', (array) $c->get_product_categories() ) );
                                case 'excluded_product_categories': return array_values( array_map( 'intval', (array) $c->get_excluded_product_categories() ) );
                                case 'email_restrictions':          return array_values( (array) $c->get_email_restrictions() );
                            }
                            return null;
                        };
                        foreach ( $uc_undo_apply as $arg_key => $applied ) {
                            $current = $uc_undo_read( $arg_key );
                            if ( $current !== $applied ) {
                                throw new \Exception( sprintf(
                                    'Cannot undo: coupon field %s was modified after the tracked operation. Current value differs from what was written. Investigate before retrying or use SiteVault to restore.',
                                    esc_html( $arg_key )
                                ) );
                            }
                        }
                        foreach ( $uc_undo_prior as $arg_key => $prior ) {
                            switch ( $arg_key ) {
                                case 'code':                        $uc_undo_coupon->set_code( (string) $prior ); break;
                                case 'discount_type':               $uc_undo_coupon->set_discount_type( (string) $prior ); break;
                                case 'amount':                      $uc_undo_coupon->set_amount( (string) $prior ); break;
                                case 'description':                 $uc_undo_coupon->set_description( (string) $prior ); break;
                                case 'usage_limit':                 $uc_undo_coupon->set_usage_limit( (int) $prior ); break;
                                case 'usage_limit_per_user':        $uc_undo_coupon->set_usage_limit_per_user( (int) $prior ); break;
                                case 'limit_usage_to_x_items':      $uc_undo_coupon->set_limit_usage_to_x_items( (int) $prior ); break;
                                case 'individual_use':              $uc_undo_coupon->set_individual_use( (bool) $prior ); break;
                                case 'free_shipping':               $uc_undo_coupon->set_free_shipping( (bool) $prior ); break;
                                case 'exclude_sale_items':          $uc_undo_coupon->set_exclude_sale_items( (bool) $prior ); break;
                                case 'minimum_amount':              $uc_undo_coupon->set_minimum_amount( (string) $prior ); break;
                                case 'maximum_amount':              $uc_undo_coupon->set_maximum_amount( (string) $prior ); break;
                                case 'date_expires':                $uc_undo_coupon->set_date_expires( $prior === null ? null : (int) $prior ); break;
                                case 'product_ids':                 $uc_undo_coupon->set_product_ids( (array) $prior ); break;
                                case 'excluded_product_ids':        $uc_undo_coupon->set_excluded_product_ids( (array) $prior ); break;
                                case 'product_categories':          $uc_undo_coupon->set_product_categories( (array) $prior ); break;
                                case 'excluded_product_categories': $uc_undo_coupon->set_excluded_product_categories( (array) $prior ); break;
                                case 'email_restrictions':          $uc_undo_coupon->set_email_restrictions( (array) $prior ); break;
                            }
                        }
                        $uc_undo_coupon->save();
                        wp_cache_delete( $undo_uc_id, 'posts' );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored %d field(s) on coupon %d to prior values.', count( $uc_undo_prior ), $undo_uc_id ),
                            [
                                'undone'   => true,
                                'op'       => $undo_op,
                                'target'   => $uc_undo_target,
                                'restored' => array_keys( $uc_undo_prior ),
                            ]
                        );

                    case 'wc_update_product':
                        $up_undo_target = $undo_snapshot['target'] ?? [];
                        $undo_up_id     = (int) ( $up_undo_target['product_id'] ?? 0 );
                        if ( $undo_up_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing product_id.');
                        }
                        if ( ! function_exists( 'wc_get_product' ) ) {
                            throw new \Exception('WooCommerce is no longer active — cannot restore product fields.');
                        }
                        // Existence check via get_post first — wc_get_product
                        // may return a stale cached object after a hard-delete
                        // (same class as the variation undo bug). get_post reads
                        // wp_posts directly so vanished target is caught before
                        // the cap check fires with a misleading error.
                        $up_undo_post = get_post( $undo_up_id );
                        if ( ! $up_undo_post || $up_undo_post->post_type !== 'product' ) {
                            throw new \Exception('Product no longer exists — cannot restore fields.');
                        }
                        $up_undo_product = wc_get_product( $undo_up_id );
                        if ( ! $up_undo_product ) {
                            throw new \Exception('Product no longer exists — cannot restore fields.');
                        }
                        if ( ! current_user_can( 'edit_product', $undo_up_id ) ) {
                            throw new \Exception('edit_product capability required to undo this operation.');
                        }
                        $up_undo_pre    = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $up_undo_prior  = ( isset( $up_undo_pre['prior_values'] ) && is_array( $up_undo_pre['prior_values'] ) ) ? $up_undo_pre['prior_values'] : [];
                        $up_undo_apply  = ( isset( $up_undo_pre['applied_values'] ) && is_array( $up_undo_pre['applied_values'] ) ) ? $up_undo_pre['applied_values'] : [];
                        if ( empty( $up_undo_prior ) ) {
                            throw new \Exception('Undo snapshot has no prior_values to restore.');
                        }
                        $up_undo_read = function( $arg_key ) use ( $up_undo_product ) {
                            switch ( $arg_key ) {
                                case 'name':              return (string) $up_undo_product->get_name();
                                case 'description':       return (string) $up_undo_product->get_description();
                                case 'short_description': return (string) $up_undo_product->get_short_description();
                                case 'sku':               return (string) $up_undo_product->get_sku();
                                case 'status':            return (string) $up_undo_product->get_status();
                                case 'regular_price':     return (string) $up_undo_product->get_regular_price();
                                case 'sale_price':        return (string) $up_undo_product->get_sale_price();
                                case 'stock_quantity':    return (int) $up_undo_product->get_stock_quantity();
                            }
                            return null;
                        };
                        foreach ( $up_undo_apply as $arg_key => $applied ) {
                            $current = $up_undo_read( $arg_key );
                            if ( $current !== $applied ) {
                                throw new \Exception( sprintf(
                                    'Cannot undo: product field %s was modified after the tracked operation. Current value differs from what was written. Investigate before retrying or use SiteVault to restore.',
                                    esc_html( $arg_key )
                                ) );
                            }
                        }
                        // Restore per-field via WC setters.
                        foreach ( $up_undo_prior as $arg_key => $prior ) {
                            switch ( $arg_key ) {
                                case 'name':              $up_undo_product->set_name( (string) $prior ); break;
                                case 'description':       $up_undo_product->set_description( (string) $prior ); break;
                                case 'short_description': $up_undo_product->set_short_description( (string) $prior ); break;
                                case 'sku':               $up_undo_product->set_sku( (string) $prior ); break;
                                case 'status':            $up_undo_product->set_status( (string) $prior ); break;
                                case 'regular_price':     $up_undo_product->set_regular_price( (string) $prior ); break;
                                case 'sale_price':        $up_undo_product->set_sale_price( (string) $prior ); break;
                                case 'stock_quantity':    $up_undo_product->set_stock_quantity( (int) $prior ); break;
                            }
                        }
                        // Restore manage_stock side effect if the original op touched
                        // stock_quantity. Without this, an undo of a stock write on
                        // a previously-unmanaged product would leave manage_stock=true
                        // (setting stock_quantity flipped it).
                        if ( array_key_exists( 'manage_stock_before', $up_undo_pre ) ) {
                            $up_undo_product->set_manage_stock( (bool) $up_undo_pre['manage_stock_before'] );
                        }
                        $up_undo_product->save();
                        wc_delete_product_transients( $undo_up_id );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored %d field(s) on product %d to prior values.', count( $up_undo_prior ), $undo_up_id ),
                            [
                                'undone'   => true,
                                'op'       => $undo_op,
                                'target'   => $up_undo_target,
                                'restored' => array_keys( $up_undo_prior ),
                            ]
                        );

                    case 'wc_update_order':
                        $uo_undo_target = $undo_snapshot['target'] ?? [];
                        $undo_uo_id     = (int) ( $uo_undo_target['order_id'] ?? 0 );
                        if ( $undo_uo_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing order_id.');
                        }
                        if ( ! function_exists( 'wc_get_order' ) ) {
                            throw new \Exception('WooCommerce is no longer active — cannot restore order fields.');
                        }
                        $uo_undo_order = wc_get_order( $undo_uo_id );
                        if ( ! $uo_undo_order || ! $uo_undo_order instanceof \WC_Order ) {
                            throw new \Exception('Order no longer exists — cannot restore fields.');
                        }
                        if ( ! current_user_can( 'edit_shop_order', $undo_uo_id ) ) {
                            throw new \Exception('edit_shop_order capability required to undo this operation.');
                        }
                        $uo_undo_pre    = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $uo_undo_prior  = ( isset( $uo_undo_pre['prior_values'] ) && is_array( $uo_undo_pre['prior_values'] ) ) ? $uo_undo_pre['prior_values'] : [];
                        $uo_undo_apply  = ( isset( $uo_undo_pre['applied_values'] ) && is_array( $uo_undo_pre['applied_values'] ) ) ? $uo_undo_pre['applied_values'] : [];
                        if ( empty( $uo_undo_prior ) ) {
                            throw new \Exception('Undo snapshot has no prior_values to restore.');
                        }
                        // Per-field readers, mirroring the write-side reader map.
                        $uo_undo_read = function( $arg_key ) use ( $uo_undo_order ) {
                            if ( strpos( $arg_key, 'billing.' ) === 0 ) {
                                $g = 'get_billing_' . substr( $arg_key, 8 );
                                return (string) $uo_undo_order->$g();
                            }
                            if ( strpos( $arg_key, 'shipping.' ) === 0 ) {
                                $g = 'get_shipping_' . substr( $arg_key, 9 );
                                return (string) $uo_undo_order->$g();
                            }
                            if ( $arg_key === 'customer_note' ) return (string) $uo_undo_order->get_customer_note();
                            if ( $arg_key === 'status' )        return $uo_undo_order->get_status();
                            return null;
                        };
                        foreach ( $uo_undo_apply as $arg_key => $applied ) {
                            $current = $uo_undo_read( $arg_key );
                            if ( $current !== $applied ) {
                                throw new \Exception( sprintf(
                                    'Cannot undo: order field %s was modified after the tracked operation. Current value differs from what was written. Investigate before retrying or use SiteVault to restore.',
                                    esc_html( $arg_key )
                                ) );
                            }
                        }
                        // Restore per-field via WC's own setters.
                        $status_after = null;
                        foreach ( $uo_undo_prior as $arg_key => $prior ) {
                            if ( strpos( $arg_key, 'billing.' ) === 0 ) {
                                $s = 'set_billing_' . substr( $arg_key, 8 );
                                $uo_undo_order->$s( (string) $prior );
                            } elseif ( strpos( $arg_key, 'shipping.' ) === 0 ) {
                                $s = 'set_shipping_' . substr( $arg_key, 9 );
                                $uo_undo_order->$s( (string) $prior );
                            } elseif ( $arg_key === 'customer_note' ) {
                                $uo_undo_order->set_customer_note( (string) $prior );
                            } elseif ( $arg_key === 'status' ) {
                                // Defer status restore to update_status so hooks fire
                                // + audit note is added. Applied AFTER save() below
                                // to avoid double-save + double-hook interaction.
                                $status_after = (string) $prior;
                            }
                        }
                        $uo_undo_order->save();
                        if ( $status_after !== null ) {
                            $uo_undo_order->update_status(
                                $status_after,
                                sprintf( 'Reverted by Royal MCP undo (was %s).', $uo_undo_apply['status'] ?? '?' )
                            );
                        }
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored %d field(s) on order %d to prior values.', count( $uo_undo_prior ), $undo_uo_id ),
                            [
                                'undone'   => true,
                                'op'       => $undo_op,
                                'target'   => $uo_undo_target,
                                'restored' => array_keys( $uo_undo_prior ),
                            ]
                        );

                    case 'wc_update_order_status':
                        $oundo_target = $undo_snapshot['target'] ?? [];
                        $undo_os_id   = (int) ( $oundo_target['order_id'] ?? 0 );
                        if ( $undo_os_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing order_id.');
                        }
                        if ( ! function_exists( 'wc_get_order' ) ) {
                            throw new \Exception('WooCommerce is no longer active — cannot restore order status.');
                        }
                        $os_undo_order = wc_get_order( $undo_os_id );
                        if ( ! $os_undo_order || ! $os_undo_order instanceof \WC_Order ) {
                            throw new \Exception('Order no longer exists — cannot restore status.');
                        }
                        if ( ! current_user_can( 'edit_shop_order', $undo_os_id ) ) {
                            throw new \Exception('edit_shop_order capability required to undo this operation.');
                        }
                        $os_undo_pre     = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $os_undo_prior   = (string) ( $os_undo_pre['prior_status'] ?? '' );
                        $os_undo_applied = (string) ( $os_undo_pre['applied_status'] ?? '' );
                        if ( $os_undo_prior === '' ) {
                            throw new \Exception('Undo snapshot has no prior_status to restore.');
                        }
                        $os_undo_current = $os_undo_order->get_status();
                        if ( $os_undo_current !== $os_undo_applied ) {
                            throw new \Exception('Cannot undo: order status was modified after the tracked operation. Current status differs from what was set. Investigate before retrying or use SiteVault to restore.');
                        }
                        // Restore + explicit note so the audit trail records
                        // that the reverse transition was a Royal MCP undo, not
                        // a customer/moderator action.
                        $os_undo_order->update_status(
                            $os_undo_prior,
                            sprintf( 'Reverted by Royal MCP undo (was %s).', $os_undo_applied )
                        );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored order %d status from %s back to %s.', $undo_os_id, $os_undo_applied, $os_undo_prior ),
                            [
                                'undone'     => true,
                                'op'         => $undo_op,
                                'target'     => $oundo_target,
                                'restored'   => true,
                                'new_status' => $os_undo_prior,
                            ]
                        );

                    case 'wc_add_order_note':
                        $an_target      = $undo_snapshot['target'] ?? [];
                        $undo_an_nid    = (int) ( $an_target['note_id']  ?? 0 );
                        $undo_an_oid    = (int) ( $an_target['order_id'] ?? 0 );
                        if ( $undo_an_nid <= 0 ) {
                            throw new \Exception('Undo snapshot missing note_id.');
                        }
                        if ( ! current_user_can( 'edit_shop_order', $undo_an_oid ) ) {
                            throw new \Exception('edit_shop_order capability required to undo this operation.');
                        }
                        // Order notes live in wp_comments with comment_type=order_note
                        // (or 'shop_order_note' in older WC versions). Delete by
                        // comment_id — force=true because notes don't have a trash state.
                        $an_row = get_comment( $undo_an_nid );
                        if ( ! $an_row ) {
                            \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                            return \Royal_MCP\MCP\Support\Envelope::success(
                                sprintf( 'No-op: order note %d was already removed.', $undo_an_nid ),
                                [
                                    'undone'   => true,
                                    'op'       => $undo_op,
                                    'target'   => $an_target,
                                    'restored' => false,
                                    'reason'   => 'row_already_gone',
                                ]
                            );
                        }
                        $an_ok = wp_delete_comment( $undo_an_nid, true );
                        if ( ! $an_ok ) {
                            throw new \Exception('wp_delete_comment returned false — could not remove order note.');
                        }
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Removed order note %d from order %d.', $undo_an_nid, $undo_an_oid ),
                            [
                                'undone'   => true,
                                'op'       => $undo_op,
                                'target'   => $an_target,
                                'restored' => true,
                            ]
                        );

                    case 'wp_set_comment_status':
                        // Shared undo for wp_approve_comment, wp_spam_comment, wp_trash_comment
                        $cs_target   = $undo_snapshot['target'] ?? [];
                        $undo_cs_id  = (int) ( $cs_target['comment_id'] ?? 0 );
                        if ( $undo_cs_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing comment_id.');
                        }
                        $cs_comment = get_comment( $undo_cs_id );
                        if ( ! $cs_comment ) {
                            throw new \Exception('Comment no longer exists — cannot restore status.');
                        }
                        if ( ! current_user_can( 'moderate_comments' ) ) {
                            throw new \Exception('moderate_comments capability required to undo this operation.');
                        }
                        $cs_pre = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $cs_prior   = (string) ( $cs_pre['prior_status'] ?? '' );
                        $cs_applied = (string) ( $cs_pre['applied_status'] ?? '' );
                        if ( $cs_prior === '' ) {
                            throw new \Exception('Undo snapshot has no prior_status to restore.');
                        }
                        $cs_current = self::normalize_comment_status_column( $cs_comment->comment_approved );
                        if ( $cs_current !== $cs_applied ) {
                            throw new \Exception('Cannot undo: comment status was modified after the tracked operation. Current status differs from what was set. Investigate before retrying or use SiteVault to restore.');
                        }
                        // Coming OUT of trash requires wp_untrash_comment;
                        // wp_set_comment_status can put a comment INTO trash
                        // but not restore from _wp_trash_meta_status.
                        if ( $cs_applied === 'trash' ) {
                            $cs_ok = wp_untrash_comment( $undo_cs_id );
                            if ( ! $cs_ok ) throw new \Exception('wp_untrash_comment failed on undo.');
                            // untrash restores to _wp_trash_meta_status; if that
                            // doesn't match our recorded prior, apply an extra
                            // wp_set_comment_status to be exact.
                            $post_untrash = get_comment( $undo_cs_id );
                            if ( $post_untrash && self::normalize_comment_status_column( $post_untrash->comment_approved ) !== $cs_prior ) {
                                wp_set_comment_status( $undo_cs_id, $cs_prior );
                            }
                        } else {
                            $cs_ok = wp_set_comment_status( $undo_cs_id, $cs_prior );
                            if ( ! $cs_ok ) throw new \Exception('wp_set_comment_status failed on undo.');
                        }
                        clean_comment_cache( $undo_cs_id );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored comment %d status from %s back to %s.', $undo_cs_id, $cs_applied, $cs_prior ),
                            [
                                'undone'       => true,
                                'op'           => $undo_op,
                                'target'       => $cs_target,
                                'restored'     => true,
                                'new_status'   => $cs_prior,
                                'original_op'  => $cs_pre['original_op'] ?? '',
                            ]
                        );

                    case 'wp_create_comment':
                        $cc_target = $undo_snapshot['target'] ?? [];
                        $undo_cc_id  = (int) ( $cc_target['comment_id'] ?? 0 );
                        $undo_cc_pid = (int) ( $cc_target['post_id']    ?? 0 );
                        if ( $undo_cc_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing comment_id.');
                        }
                        $cc_existing = get_comment( $undo_cc_id );
                        if ( ! $cc_existing ) {
                            // Idempotent — comment already gone
                            \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                            return \Royal_MCP\MCP\Support\Envelope::success(
                                sprintf( 'No-op: comment %d was already removed.', $undo_cc_id ),
                                [
                                    'undone'   => true,
                                    'op'       => $undo_op,
                                    'target'   => $cc_target,
                                    'restored' => false,
                                    'reason'   => 'row_already_gone',
                                ]
                            );
                        }
                        if ( ! current_user_can( 'edit_comment', $undo_cc_id ) ) {
                            throw new \Exception('edit_comment capability required to undo this operation.');
                        }
                        // Hard-delete the created row.
                        $cc_ok = wp_delete_comment( $undo_cc_id, true );
                        if ( ! $cc_ok ) {
                            throw new \Exception('wp_delete_comment returned false on undo.');
                        }
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Removed comment %d on post %d.', $undo_cc_id, $undo_cc_pid ),
                            [
                                'undone'   => true,
                                'op'       => $undo_op,
                                'target'   => $cc_target,
                                'restored' => true,
                            ]
                        );

                    case 'wp_delete_comment_trash':
                        $dct_target = $undo_snapshot['target'] ?? [];
                        $undo_dct_id = (int) ( $dct_target['comment_id'] ?? 0 );
                        if ( $undo_dct_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing comment_id.');
                        }
                        $dct_comment = get_comment( $undo_dct_id );
                        if ( ! $dct_comment ) {
                            throw new \Exception('Comment no longer exists — cannot untrash.');
                        }
                        if ( ! current_user_can( 'edit_comment', $undo_dct_id ) ) {
                            throw new \Exception('edit_comment capability required to undo this operation.');
                        }
                        $dct_current = self::normalize_comment_status_column( $dct_comment->comment_approved );
                        if ( $dct_current !== 'trash' ) {
                            throw new \Exception( sprintf(
                                'Cannot undo: comment is not in trash (current status: %s). Someone likely acted on it after the tracked delete.',
                                esc_html( $dct_current )
                            ) );
                        }
                        $dct_pre = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $dct_prior = (string) ( $dct_pre['prior_status'] ?? 'approve' );
                        $dct_ok = wp_untrash_comment( $undo_dct_id );
                        if ( ! $dct_ok ) throw new \Exception('wp_untrash_comment failed on undo.');
                        $post_untrash = get_comment( $undo_dct_id );
                        if ( $post_untrash && self::normalize_comment_status_column( $post_untrash->comment_approved ) !== $dct_prior ) {
                            wp_set_comment_status( $undo_dct_id, $dct_prior );
                        }
                        clean_comment_cache( $undo_dct_id );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Untrashed comment %d back to %s.', $undo_dct_id, $dct_prior ),
                            [
                                'undone'     => true,
                                'op'         => $undo_op,
                                'target'     => $dct_target,
                                'restored'   => true,
                                'new_status' => $dct_prior,
                            ]
                        );

                    case 'wp_delete_comment_force':
                        // Force-delete recreates the row via wp_insert_comment.
                        // The new comment_ID differs from the original — WP
                        // auto-increments and we don't do direct DB writes.
                        $dcf_target = $undo_snapshot['target'] ?? [];
                        if ( ! current_user_can( 'moderate_comments' ) ) {
                            throw new \Exception('moderate_comments capability required to undo this operation.');
                        }
                        $dcf_pre = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $dcf_row = ( isset( $dcf_pre['row'] ) && is_array( $dcf_pre['row'] ) ) ? $dcf_pre['row'] : [];
                        if ( empty( $dcf_row ) ) {
                            throw new \Exception('Undo snapshot has no row data to recreate.');
                        }
                        $dcf_new_id = wp_insert_comment( $dcf_row );
                        if ( ! $dcf_new_id ) {
                            throw new \Exception('wp_insert_comment failed on undo — the row could not be recreated.');
                        }
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Recreated force-deleted comment on post %d. New comment ID: %d (original was %d).', (int) $dcf_row['comment_post_ID'], (int) $dcf_new_id, (int) ( $dcf_target['original_comment_id'] ?? 0 ) ),
                            [
                                'undone'              => true,
                                'op'                  => $undo_op,
                                'target'              => $dcf_target,
                                'restored'            => true,
                                'new_comment_id'      => (int) $dcf_new_id,
                                'original_comment_id' => (int) ( $dcf_target['original_comment_id'] ?? 0 ),
                            ]
                        );

                    case 'wp_update_menu_item':
                        $mi_target = $undo_snapshot['target'] ?? [];
                        $undo_mi_id  = (int) ( $mi_target['menu_item_id'] ?? 0 );
                        $undo_mi_mid = (int) ( $mi_target['menu_id']      ?? 0 );
                        if ( $undo_mi_id <= 0 ) {
                            throw new \Exception('Undo snapshot missing menu_item_id.');
                        }
                        $mi_existing = get_post( $undo_mi_id );
                        if ( ! $mi_existing || $mi_existing->post_type !== 'nav_menu_item' ) {
                            throw new \Exception('Menu item no longer exists — cannot restore.');
                        }
                        if ( ! current_user_can( 'edit_theme_options' ) ) {
                            throw new \Exception('edit_theme_options capability required to undo this operation.');
                        }
                        $mi_pre    = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $mi_prior  = ( isset( $mi_pre['prior_values'] ) && is_array( $mi_pre['prior_values'] ) ) ? $mi_pre['prior_values'] : [];
                        $mi_apply  = ( isset( $mi_pre['applied_values'] ) && is_array( $mi_pre['applied_values'] ) ) ? $mi_pre['applied_values'] : [];
                        if ( empty( $mi_prior ) ) {
                            throw new \Exception('Undo snapshot has no prior_values to restore.');
                        }
                        $mi_read_undo = function( $arg_key ) use ( $undo_mi_id ) {
                            $item = wp_setup_nav_menu_item( get_post( $undo_mi_id ) );
                            if ( ! $item ) return null;
                            switch ( $arg_key ) {
                                case 'title':     return (string) $item->title;
                                case 'url':       return (string) $item->url;
                                case 'parent_id': return (int) $item->menu_item_parent;
                                case 'position':  return (int) $item->menu_order;
                                case 'target':    return (string) $item->target;
                            }
                            return null;
                        };
                        foreach ( $mi_apply as $mf => $mapplied ) {
                            $mcur = $mi_read_undo( $mf );
                            if ( $mcur !== $mapplied ) {
                                throw new \Exception( sprintf(
                                    'Cannot undo: menu item field %s was modified after the tracked operation. Current value differs from what was written. Investigate before retrying.',
                                    esc_html( $mf )
                                ) );
                            }
                        }
                        // Restore via same merge+update path.
                        $mi_overrides = [];
                        if ( array_key_exists( 'title', $mi_prior ) )     $mi_overrides['menu-item-title']     = (string) $mi_prior['title'];
                        if ( array_key_exists( 'url', $mi_prior ) )       $mi_overrides['menu-item-url']       = (string) $mi_prior['url'];
                        if ( array_key_exists( 'parent_id', $mi_prior ) ) $mi_overrides['menu-item-parent-id'] = (int)    $mi_prior['parent_id'];
                        if ( array_key_exists( 'position', $mi_prior ) )  $mi_overrides['menu-item-position']  = (int)    $mi_prior['position'];
                        if ( array_key_exists( 'target', $mi_prior ) )    $mi_overrides['menu-item-target']    = (string) $mi_prior['target'];
                        $mi_merged_undo = $this->build_safe_menu_item_args( $undo_mi_id, $mi_overrides );
                        if ( is_wp_error( $mi_merged_undo ) ) throw new \Exception( esc_html( $mi_merged_undo->get_error_message() ) );
                        $mi_res_undo = wp_update_nav_menu_item( $undo_mi_mid, $undo_mi_id, $mi_merged_undo );
                        if ( is_wp_error( $mi_res_undo ) ) throw new \Exception( esc_html( $mi_res_undo->get_error_message() ) );
                        clean_post_cache( $undo_mi_id );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored %d field(s) on menu item %d.', count( $mi_prior ), $undo_mi_id ),
                            [
                                'undone'   => true,
                                'op'       => $undo_op,
                                'target'   => $mi_target,
                                'restored' => array_keys( $mi_prior ),
                            ]
                        );

                    case 'wp_update_seo_meta':
                        $seo_undo_target = $undo_snapshot['target'] ?? [];
                        $undo_seo_pid    = (int)    ( $seo_undo_target['post_id'] ?? 0 );
                        $undo_seo_adapt  = (string) ( $seo_undo_target['adapter'] ?? '' );
                        if ( $undo_seo_pid <= 0 || $undo_seo_adapt === '' ) {
                            throw new \Exception('Undo snapshot missing post_id or adapter.');
                        }
                        // Existence check before cap check (see 5f12e0e).
                        $undo_seo_post = get_post( $undo_seo_pid );
                        if ( ! $undo_seo_post ) {
                            throw new \Exception('Post no longer exists — cannot restore SEO meta.');
                        }
                        if ( ! current_user_can( 'edit_post', $undo_seo_pid ) ) {
                            throw new \Exception('edit_post capability required to undo this operation.');
                        }
                        // If the adapter isn't active anymore, most restore paths
                        // become no-ops (post_meta rows can still be written, but
                        // the detection has already changed). Slug always survives.
                        $undo_seo_pre    = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $undo_seo_prior  = ( isset( $undo_seo_pre['prior_values'] ) && is_array( $undo_seo_pre['prior_values'] ) ) ? $undo_seo_pre['prior_values'] : [];
                        $undo_seo_apply  = ( isset( $undo_seo_pre['applied_values'] ) && is_array( $undo_seo_pre['applied_values'] ) ) ? $undo_seo_pre['applied_values'] : [];
                        if ( empty( $undo_seo_prior ) ) {
                            throw new \Exception('Undo snapshot has no prior_values to restore.');
                        }
                        // Adapter-specific field map (mirrors the write handler).
                        $undo_seo_map = [
                            'yoast'    => [
                                'title'          => '_yoast_wpseo_title',
                                'description'    => '_yoast_wpseo_metadesc',
                                'focus_keyword'  => '_yoast_wpseo_focuskw',
                                'og_title'       => '_yoast_wpseo_opengraph-title',
                                'og_description' => '_yoast_wpseo_opengraph-description',
                            ],
                            'rankmath' => [
                                'title'          => 'rank_math_title',
                                'description'    => 'rank_math_description',
                                'focus_keyword'  => 'rank_math_focus_keyword',
                                'og_title'       => 'rank_math_facebook_title',
                                'og_description' => 'rank_math_facebook_description',
                            ],
                            'aioseo'   => [
                                'title'          => '_aioseo_title',
                                'description'    => '_aioseo_description',
                                'focus_keyword'  => '_aioseo_focus_keyphrase',
                            ],
                            'seobolt'  => [
                                'title'          => '_seobolt_meta_title',
                                'description'    => '_seobolt_meta_description',
                                'focus_keyword'  => '_seobolt_focus_keyword',
                            ],
                        ];
                        $undo_seo_field_map = $undo_seo_map[ $undo_seo_adapt ] ?? [];

                        // Closure for reading current normalized value (parity with
                        // the write handler's $seo_read).
                        $undo_seo_read = function( $arg_key ) use ( $undo_seo_pid, $undo_seo_adapt, $undo_seo_field_map ) {
                            if ( $arg_key === 'slug' ) {
                                return (string) get_post_field( 'post_name', $undo_seo_pid );
                            }
                            if ( $arg_key === 'noindex' ) {
                                if ( $undo_seo_adapt === 'yoast' )    return get_post_meta( $undo_seo_pid, '_yoast_wpseo_meta-robots-noindex', true ) === '1';
                                if ( $undo_seo_adapt === 'rankmath' ) return in_array( 'noindex', (array) get_post_meta( $undo_seo_pid, 'rank_math_robots', true ), true );
                                if ( $undo_seo_adapt === 'aioseo' )   return (bool) get_post_meta( $undo_seo_pid, '_aioseo_noindex', true );
                                if ( $undo_seo_adapt === 'seobolt' )  return (bool) get_post_meta( $undo_seo_pid, '_seobolt_noindex', true );
                                return false;
                            }
                            $mk = $undo_seo_field_map[ $arg_key ] ?? '';
                            return $mk !== '' ? (string) get_post_meta( $undo_seo_pid, $mk, true ) : '';
                        };

                        // Drift-detection — per-field current vs applied.
                        foreach ( $undo_seo_apply as $arg_key => $applied ) {
                            $current = $undo_seo_read( $arg_key );
                            if ( $current !== $applied ) {
                                throw new \Exception( sprintf(
                                    'Cannot undo: SEO field %s was modified after the tracked operation. Current value differs from what was written. Investigate before retrying or use SiteVault to restore.',
                                    esc_html( $arg_key )
                                ) );
                            }
                        }
                        // Restore per-field using the same adapter-branched logic.
                        foreach ( $undo_seo_prior as $arg_key => $prior ) {
                            if ( $arg_key === 'slug' ) {
                                $upd_slug = wp_update_post( [ 'ID' => $undo_seo_pid, 'post_name' => (string) $prior ], true );
                                if ( is_wp_error( $upd_slug ) ) throw new \Exception( 'Slug restore failed: ' . esc_html( $upd_slug->get_error_message() ) );
                                continue;
                            }
                            if ( $arg_key === 'noindex' ) {
                                $prior_bool = (bool) $prior;
                                if ( $undo_seo_adapt === 'yoast' ) {
                                    update_post_meta( $undo_seo_pid, '_yoast_wpseo_meta-robots-noindex', $prior_bool ? '1' : '0' );
                                } elseif ( $undo_seo_adapt === 'rankmath' ) {
                                    $robots = get_post_meta( $undo_seo_pid, 'rank_math_robots', true );
                                    $robots = is_array( $robots ) ? $robots : [];
                                    $robots = array_filter( $robots, fn( $r ) => $r !== '' && $r !== 'noindex' && $r !== 'index' );
                                    $robots[] = $prior_bool ? 'noindex' : 'index';
                                    $robots = array_values( array_unique( $robots ) );
                                    update_post_meta( $undo_seo_pid, 'rank_math_robots', $robots );
                                } elseif ( $undo_seo_adapt === 'aioseo' ) {
                                    update_post_meta( $undo_seo_pid, '_aioseo_noindex', $prior_bool ? '1' : '' );
                                } elseif ( $undo_seo_adapt === 'seobolt' ) {
                                    update_post_meta( $undo_seo_pid, '_seobolt_noindex', $prior_bool ? '1' : '' );
                                }
                                continue;
                            }
                            $mk = $undo_seo_field_map[ $arg_key ] ?? '';
                            if ( $mk !== '' ) {
                                update_post_meta( $undo_seo_pid, $mk, (string) $prior );
                            }
                        }
                        wp_cache_delete( $undo_seo_pid, 'post_meta' );
                        clean_post_cache( $undo_seo_pid );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored %d SEO field(s) on post %d via %s adapter.', count( $undo_seo_prior ), $undo_seo_pid, $undo_seo_adapt ),
                            [
                                'undone'   => true,
                                'op'       => $undo_op,
                                'target'   => $seo_undo_target,
                                'restored' => array_keys( $undo_seo_prior ),
                            ]
                        );

                    case 'wp_update_option':
                        $oundo_target  = $undo_snapshot['target'] ?? [];
                        $undo_opt_name = (string) ( $oundo_target['option_name'] ?? '' );
                        if ( $undo_opt_name === '' ) {
                            throw new \Exception('Undo snapshot missing option_name.');
                        }
                        if ( ! current_user_can( 'manage_options' ) ) {
                            throw new \Exception('manage_options capability required to undo this operation.');
                        }
                        $oundo_pre     = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        if ( ! array_key_exists( 'prior_value', $oundo_pre ) ) {
                            throw new \Exception('Undo snapshot has no prior_value to restore.');
                        }
                        wp_cache_delete( $undo_opt_name, 'options' );
                        $oundo_current = get_option( $undo_opt_name );
                        $oundo_applied = $oundo_pre['applied_value'] ?? null;
                        if ( $oundo_current !== $oundo_applied ) {
                            throw new \Exception('Cannot undo: option value was modified after the tracked operation. Current value differs from what was written. Investigate before retrying or use SiteVault to restore a full-site snapshot.');
                        }
                        // If the option didn't exist before the write we did,
                        // remove the row entirely rather than storing our
                        // prior_value (which was `false` — the "not found"
                        // sentinel, indistinguishable from a real false).
                        if ( empty( $oundo_pre['existed_before'] ) ) {
                            delete_option( $undo_opt_name );
                        } else {
                            update_option( $undo_opt_name, $oundo_pre['prior_value'] );
                        }
                        wp_cache_delete( $undo_opt_name, 'options' );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored option %s to prior state.', $undo_opt_name ),
                            [
                                'undone'         => true,
                                'op'             => $undo_op,
                                'target'         => $oundo_target,
                                'restored'       => true,
                                'row_removed'    => empty( $oundo_pre['existed_before'] ),
                            ]
                        );

                    case 'wp_update_theme_mod':
                        $tmundo_target  = $undo_snapshot['target'] ?? [];
                        $undo_tm_name   = (string) ( $tmundo_target['mod_name'] ?? '' );
                        if ( $undo_tm_name === '' ) {
                            throw new \Exception('Undo snapshot missing mod_name.');
                        }
                        if ( ! current_user_can( 'edit_theme_options' ) ) {
                            throw new \Exception('edit_theme_options capability required to undo this operation.');
                        }
                        $tmundo_pre    = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        $tmundo_all    = (array) get_theme_mods();
                        $tmundo_current = array_key_exists( $undo_tm_name, $tmundo_all ) ? $tmundo_all[ $undo_tm_name ] : null;
                        $tmundo_applied = $tmundo_pre['applied_value'] ?? null;
                        if ( $tmundo_current !== $tmundo_applied ) {
                            throw new \Exception('Cannot undo: theme mod was modified after the tracked operation. Current value differs from what was written. Investigate before retrying.');
                        }
                        if ( empty( $tmundo_pre['existed_before'] ) ) {
                            // Mod didn't exist before — remove_theme_mod is the only
                            // way to fully return to the "unset" state; set_theme_mod
                            // to null/false would store the value instead.
                            remove_theme_mod( $undo_tm_name );
                        } else {
                            set_theme_mod( $undo_tm_name, $tmundo_pre['prior_value'] );
                        }
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored theme mod %s to prior state.', $undo_tm_name ),
                            [
                                'undone'      => true,
                                'op'          => $undo_op,
                                'target'      => $tmundo_target,
                                'restored'    => true,
                                'mod_removed' => empty( $tmundo_pre['existed_before'] ),
                            ]
                        );

                    case 'wp_update_custom_css':
                        $cundo_target = $undo_snapshot['target'] ?? [];
                        $undo_css_theme = (string) ( $cundo_target['theme_slug'] ?? '' );
                        if ( $undo_css_theme === '' ) {
                            throw new \Exception('Undo snapshot missing theme_slug.');
                        }
                        if ( ! current_user_can( 'unfiltered_html' ) ) {
                            throw new \Exception('unfiltered_html capability required to undo this operation.');
                        }
                        $cundo_pre     = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        if ( ! array_key_exists( 'prior_css', $cundo_pre ) ) {
                            throw new \Exception('Undo snapshot has no prior_css to restore.');
                        }
                        $cundo_current = (string) wp_get_custom_css( $undo_css_theme );
                        $cundo_applied = (string) ( $cundo_pre['applied_css'] ?? '' );
                        if ( $cundo_current !== $cundo_applied ) {
                            throw new \Exception('Cannot undo: custom CSS was modified after the tracked operation. Current value differs from what was written. Investigate before retrying or use SiteVault to restore.');
                        }
                        $cundo_res = wp_update_custom_css_post( (string) $cundo_pre['prior_css'], [ 'stylesheet' => $undo_css_theme ] );
                        if ( is_wp_error( $cundo_res ) ) throw new \Exception( esc_html( $cundo_res->get_error_message() ) );
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored prior custom CSS for theme %s (%d bytes).', $undo_css_theme, strlen( (string) $cundo_pre['prior_css'] ) ),
                            [
                                'undone'      => true,
                                'op'          => $undo_op,
                                'target'      => $cundo_target,
                                'restored'    => true,
                                'byte_count'  => strlen( (string) $cundo_pre['prior_css'] ),
                            ]
                        );

                    case 'wp_update_permalink_structure':
                        if ( ! current_user_can( 'manage_options' ) ) {
                            throw new \Exception('manage_options capability required to undo this operation.');
                        }
                        $plundo_pre     = ( isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] ) ) ? $undo_snapshot['pre_op_state'] : [];
                        if ( ! array_key_exists( 'prior_structure', $plundo_pre ) ) {
                            throw new \Exception('Undo snapshot has no prior_structure to restore.');
                        }
                        $plundo_current = (string) get_option( 'permalink_structure', '' );
                        $plundo_applied = (string) ( $plundo_pre['applied_structure'] ?? '' );
                        if ( $plundo_current !== $plundo_applied ) {
                            throw new \Exception('Cannot undo: permalink structure was modified after the tracked operation. Current value differs from what was written. Investigate before retrying.');
                        }
                        $plundo_prior = (string) $plundo_pre['prior_structure'];
                        // Restore via the same code path as the original op —
                        // both set + flush must run together; skipping flush
                        // leaves the rewrite cache pointing at the wrong URLs.
                        global $wp_rewrite;
                        if ( $wp_rewrite ) {
                            $wp_rewrite->set_permalink_structure( $plundo_prior );
                            $wp_rewrite->flush_rules();
                        } else {
                            update_option( 'permalink_structure', $plundo_prior );
                        }
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Restored permalink structure to %s (rewrite rules flushed).', $plundo_prior !== '' ? $plundo_prior : '(plain)' ),
                            [
                                'undone'    => true,
                                'op'        => $undo_op,
                                'restored'  => true,
                                'structure' => $plundo_prior,
                            ]
                        );

                    case 'wp_add_post_meta':
                        $add_target      = $undo_snapshot['target'] ?? [];
                        $undo_add_pid    = (int) ( $add_target['post_id'] ?? 0 );
                        $undo_add_key    = (string) ( $add_target['meta_key'] ?? '' );
                        $undo_add_mid    = (int) ( $add_target['meta_id'] ?? 0 );
                        if ( $undo_add_pid <= 0 || $undo_add_key === '' || $undo_add_mid <= 0 ) {
                            throw new \Exception('Undo snapshot missing post_id, meta_key, or meta_id.');
                        }
                        if ( ! current_user_can( 'edit_post', $undo_add_pid ) ) {
                            throw new \Exception('edit_post capability required to undo this operation.');
                        }
                        $undo_add_pre = isset( $undo_snapshot['pre_op_state'] ) && is_array( $undo_snapshot['pre_op_state'] )
                            ? $undo_snapshot['pre_op_state']
                            : [];
                        // Drift-detection — verify the row at $meta_id still holds
                        // our added value. If someone updated that row afterwards
                        // via update_metadata_by_mid or a related tool, refuse to
                        // delete their write.
                        $undo_add_current_row = get_metadata_by_mid( 'post', $undo_add_mid );
                        if ( ! $undo_add_current_row || ! isset( $undo_add_current_row->meta_value ) ) {
                            // Row already gone — treat as idempotent success so the
                            // token can be consumed and cleared.
                            \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                            return \Royal_MCP\MCP\Support\Envelope::success(
                                sprintf( 'No-op: meta row %d on post %d was already removed.', $undo_add_mid, $undo_add_pid ),
                                [
                                    'undone'   => true,
                                    'op'       => $undo_op,
                                    'target'   => $add_target,
                                    'restored' => false,
                                    'reason'   => 'row_already_gone',
                                ]
                            );
                        }
                        $undo_add_current = maybe_unserialize( $undo_add_current_row->meta_value );
                        $undo_add_applied = $undo_add_pre['added_value'] ?? null;
                        if ( $undo_add_current !== $undo_add_applied ) {
                            throw new \Exception('Cannot undo: meta row was modified after the tracked add. Deleting would clobber a subsequent update. Investigate before retrying or use SiteVault to restore a full-site snapshot.');
                        }
                        $delete_ok = delete_metadata_by_mid( 'post', $undo_add_mid );
                        wp_cache_delete( $undo_add_pid, 'post_meta' );
                        if ( ! $delete_ok ) {
                            throw new \Exception('delete_metadata_by_mid returned false — undo did not remove the row.');
                        }
                        \Royal_MCP\MCP\Undo_Store::delete( $undo_token );
                        return \Royal_MCP\MCP\Support\Envelope::success(
                            sprintf( 'Removed meta row %d on post %d (key: %s).', $undo_add_mid, $undo_add_pid, $undo_add_key ),
                            [
                                'undone'   => true,
                                'op'       => $undo_op,
                                'target'   => $add_target,
                                'restored' => true,
                            ]
                        );

                    default:
                        return \Royal_MCP\MCP\Support\Envelope::error(
                            'unsupported_op',
                            sprintf( 'Unsupported op in undo snapshot: %s. This version of Royal MCP does not know how to undo that operation. Contact support if you saw this after a successful tool call.', (string) $undo_op ),
                            [ 'op' => (string) $undo_op ]
                        );
                }

            // ==================== PLUGINS & THEMES ====================
            case 'wp_get_plugins':
                if (!current_user_can('activate_plugins')) {
                    throw new \Exception('You do not have permission to list plugins.');
                }
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $plugins = get_plugins();
                $active = get_option('active_plugins', []);
                $result = [];
                foreach ($plugins as $path => $data) {
                    $result[] = [
                        'name' => $data['Name'],
                        'version' => $data['Version'],
                        'active' => in_array($path, $active),
                        'author' => $data['Author'],
                    ];
                }
                return $result;

            case 'wp_get_themes':
                if (!current_user_can('switch_themes')) {
                    throw new \Exception('You do not have permission to list themes.');
                }
                $themes = wp_get_themes();
                $active = get_stylesheet();
                $result = [];
                foreach ($themes as $slug => $theme) {
                    $result[] = [
                        'name' => $theme->get('Name'),
                        'version' => $theme->get('Version'),
                        'active' => ($slug === $active),
                        'author' => $theme->get('Author'),
                    ];
                }
                return $result;

            // ==================== THEME & APPEARANCE ====================
            case 'wp_get_active_theme':
                if (!current_user_can('read')) {
                    throw new \Exception('You do not have permission to view the active theme.');
                }
                $theme = wp_get_theme();
                if (!$theme->exists()) throw new \Exception('Active theme not found.');
                $parent = $theme->parent();
                return [
                    'name'           => $theme->get('Name'),
                    'slug'           => $theme->get_stylesheet(),
                    'template'       => $theme->get_template(),
                    'stylesheet'     => $theme->get_stylesheet(),
                    'version'        => $theme->get('Version'),
                    'author'         => wp_strip_all_tags((string) $theme->get('Author')),
                    'description'    => wp_strip_all_tags((string) $theme->get('Description')),
                    'parent_slug'    => $parent ? $parent->get_stylesheet() : null,
                    'screenshot_url' => $theme->get_screenshot(),
                    'status'         => $theme->get('Status'),
                ];

            case 'wp_get_theme_mods':
                if (!current_user_can('edit_theme_options')) {
                    throw new \Exception('You do not have permission to read theme mods.');
                }
                $mods = get_theme_mods();
                return is_array($mods) ? $mods : [];

            case 'wp_update_theme_mod':
                if (!current_user_can('edit_theme_options')) {
                    throw new \Exception('You do not have permission to update theme mods.');
                }
                $mod_name = \Royal_MCP\MCP\Support\SafeText::field($args['mod_name'] ?? '');
                if (empty($mod_name)) throw new \Exception('mod_name is required.');

                // Gate 1: master toggle
                $rmcp_settings = get_option('royal_mcp_settings', []);
                if (empty($rmcp_settings['allow_theme_writes'])) {
                    throw new \Exception('Theme writes are disabled. Enable "Allow AI to modify theme appearance" under Royal MCP > Settings.');
                }

                // Gate 2: allowlist (default empty — opt-in via filter)
                $writable = apply_filters('royal_mcp_writable_theme_mods', []);
                if (!is_array($writable)) $writable = [];
                if (!in_array($mod_name, $writable, true)) {
                    throw new \Exception('Theme mod not in allowlist: ' . esc_html($mod_name) . '. Theme/plugin authors can opt their mods in via add_filter("royal_mcp_writable_theme_mods", ...).');
                }

                // get_theme_mod returns the default (false when omitted) for
                // unset mods. Read all mods so we can distinguish "unset with
                // default of false" from "explicitly set to false" — matters
                // for undo, which needs to remove_theme_mod (unset) vs set
                // it back to false.
                $tm_all_before   = (array) get_theme_mods();
                $tm_existed      = array_key_exists( $mod_name, $tm_all_before );
                $tm_value        = $args['value'] ?? null;
                $tm_previous     = $tm_existed ? $tm_all_before[ $mod_name ] : null;

                set_theme_mod( $mod_name, $tm_value );

                $tm_all_after = (array) get_theme_mods();
                $tm_actual    = array_key_exists( $mod_name, $tm_all_after ) ? $tm_all_after[ $mod_name ] : null;

                $tm_diff = \Royal_MCP\MCP\Support\WriteVerifier::diff(
                    [ 'value' => $tm_value ],
                    [ 'value' => $tm_previous ],
                    [ 'value' => $tm_actual ]
                );
                \Royal_MCP\MCP\Support\WriteVerifier::throw_if_dropped( $tm_diff, 'wp_update_theme_mod' );

                $tm_reverse_json     = (string) wp_json_encode( [ 'prior_value' => $tm_previous, 'existed' => $tm_existed ] );
                $tm_reverse_size_est = strlen( gzcompress( $tm_reverse_json, 9 ) );
                $tm_undo_envelope    = null;
                $tm_warnings         = [];
                if ( $tm_reverse_size_est > 1024 * 1024 ) {
                    $tm_warnings[] = 'undo not available — prior value exceeds 1MB storage cap. SiteVault snapshot recommended for reversal.';
                } else {
                    $tm_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                        'op'      => 'wp_update_theme_mod',
                        'summary' => sprintf( 'Restore theme mod %s to prior state (%s).', $mod_name, $tm_existed ? 'prior value' : 'remove mod' ),
                        'target'  => [ 'mod_name' => $mod_name ],
                        'pre_op_state' => [
                            'prior_value'    => $tm_previous,
                            'applied_value'  => $tm_actual,
                            'existed_before' => $tm_existed,
                        ],
                    ]);
                }

                $tm_struct = array_merge(
                    [
                        'mod_name'       => $mod_name,
                        'previous_value' => $tm_previous,
                        'new_value'      => $tm_actual,
                        'existed_before' => $tm_existed,
                    ],
                    \Royal_MCP\MCP\Support\WriteVerifier::response_partial( $tm_diff )
                );
                if ( ! empty( $tm_warnings ) ) {
                    $tm_struct['warnings'] = $tm_warnings;
                }
                $tm_summary = sprintf(
                    'Updated theme mod %s%s%s.',
                    $mod_name,
                    ! empty( $tm_diff['silent_modifies'] ) ? ' (WP modified value)' : '',
                    $tm_undo_envelope !== null ? ', undo available' : ' (undo not available: value too large)'
                );
                return \Royal_MCP\MCP\Support\Envelope::success(
                    $tm_summary,
                    $tm_struct,
                    $tm_undo_envelope
                );

            case 'wp_get_custom_css':
                if (!current_user_can('read')) {
                    throw new \Exception('You do not have permission to read custom CSS.');
                }
                $theme_slug = isset($args['theme_slug']) ? sanitize_key($args['theme_slug']) : get_stylesheet();
                $css = wp_get_custom_css($theme_slug);
                $post = wp_get_custom_css_post($theme_slug);
                return [
                    'css'        => (string) $css,
                    'theme_slug' => $theme_slug,
                    'post_id'    => $post ? (int) $post->ID : 0,
                ];

            case 'wp_update_custom_css':
                if (!current_user_can('unfiltered_html')) {
                    throw new \Exception('unfiltered_html capability required to update custom CSS.');
                }
                $rmcp_settings = get_option('royal_mcp_settings', []);
                if (empty($rmcp_settings['allow_theme_writes'])) {
                    throw new \Exception('Theme writes are disabled. Enable "Allow AI to modify theme appearance" under Royal MCP > Settings.');
                }
                $css_new = $args['css'] ?? '';
                if (!is_string($css_new)) throw new \Exception('css must be a string.');
                $css_theme = isset($args['theme_slug']) ? sanitize_key($args['theme_slug']) : get_stylesheet();

                $css_before = (string) wp_get_custom_css( $css_theme );

                $css_result = wp_update_custom_css_post( $css_new, [ 'stylesheet' => $css_theme ] );
                if ( is_wp_error( $css_result ) ) throw new \Exception( esc_html( $css_result->get_error_message() ) );

                // wp_update_custom_css_post accepts the CSS as post_content but
                // sanitize_hook (wp_filter_pre_kses on save_post) may transform
                // it. Re-read via wp_get_custom_css to compare intent vs actual.
                $css_actual = (string) wp_get_custom_css( $css_theme );

                $css_diff = \Royal_MCP\MCP\Support\WriteVerifier::diff(
                    [ 'css' => $css_new ],
                    [ 'css' => $css_before ],
                    [ 'css' => $css_actual ]
                );
                \Royal_MCP\MCP\Support\WriteVerifier::throw_if_dropped( $css_diff, 'wp_update_custom_css' );

                $css_reverse_json     = (string) wp_json_encode( [ 'prior_css' => $css_before ] );
                $css_reverse_size_est = strlen( gzcompress( $css_reverse_json, 9 ) );
                $css_undo_envelope    = null;
                $css_warnings         = [];
                if ( $css_reverse_size_est > 1024 * 1024 ) {
                    $css_warnings[] = 'undo not available — prior CSS exceeds 1MB storage cap. SiteVault snapshot recommended for reversal.';
                } else {
                    $css_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                        'op'      => 'wp_update_custom_css',
                        'summary' => sprintf( 'Restore prior custom CSS for theme %s (%d bytes).', $css_theme, strlen( $css_before ) ),
                        'target'  => [ 'theme_slug' => $css_theme ],
                        'pre_op_state' => [
                            'prior_css'    => $css_before,
                            'applied_css'  => $css_actual,
                        ],
                    ]);
                }

                $css_struct = array_merge(
                    [
                        'success'    => true,
                        'post_id'    => (int) $css_result->ID,
                        'theme_slug' => $css_theme,
                        'byte_count' => strlen( $css_actual ),
                        'prior_byte_count' => strlen( $css_before ),
                    ],
                    \Royal_MCP\MCP\Support\WriteVerifier::response_partial( $css_diff )
                );
                if ( ! empty( $css_warnings ) ) {
                    $css_struct['warnings'] = $css_warnings;
                }
                $css_summary = sprintf(
                    'Updated custom CSS for theme %s (%d bytes → %d bytes%s%s).',
                    $css_theme,
                    strlen( $css_before ),
                    strlen( $css_actual ),
                    ! empty( $css_diff['silent_modifies'] ) ? ', WP filtered CSS' : '',
                    $css_undo_envelope !== null ? ', undo available' : ' (undo not available: CSS too large)'
                );
                return \Royal_MCP\MCP\Support\Envelope::success(
                    $css_summary,
                    $css_struct,
                    $css_undo_envelope
                );

            case 'wp_get_widgets':
                if (!current_user_can('edit_theme_options')) {
                    throw new \Exception('edit_theme_options capability required to list widgets.');
                }
                $request = new \WP_REST_Request('GET', '/wp/v2/widgets');
                // context=edit populates the instance payload for classic widgets
                // and the raw block-markup content for block widgets. Without it,
                // the default 'view' context returns only id/id_base/sidebar/rendered
                // and rendered can itself be empty depending on widget-render context —
                // agents doing before/after state diffs get an empty response.
                $request->set_param('context', 'edit');
                if (!empty($args['sidebar'])) {
                    $request->set_param('sidebar', sanitize_key((string) $args['sidebar']));
                }
                $response = rest_do_request($request);
                if ($response->is_error()) {
                    throw new \Exception(esc_html($response->as_error()->get_error_message()));
                }
                $widgets = $response->get_data();
                // For block widgets, parse the instance.content block markup into
                // structured block objects on a new `blocks` field for convenience.
                // Classic widgets pass through unchanged — their instance array
                // already carries the widget-specific settings verbatim.
                if (is_array($widgets)) {
                    foreach ($widgets as &$w) {
                        if (!is_array($w) || empty($w['id_base'])) continue;
                        if ($w['id_base'] === 'block'
                            && !empty($w['instance']['raw']['content'])
                            && function_exists('parse_blocks')
                        ) {
                            $w['blocks'] = parse_blocks((string) $w['instance']['raw']['content']);
                        }
                    }
                    unset($w);
                }
                return $widgets;

            case 'wp_get_sidebars':
                if (!current_user_can('edit_theme_options')) {
                    throw new \Exception('edit_theme_options capability required to list sidebars.');
                }
                $request = new \WP_REST_Request('GET', '/wp/v2/sidebars');
                $response = rest_do_request($request);
                if ($response->is_error()) {
                    throw new \Exception(esc_html($response->as_error()->get_error_message()));
                }
                return $response->get_data();

            case 'wp_update_widget':
                if (!current_user_can('edit_theme_options')) {
                    throw new \Exception('edit_theme_options capability required to update widgets.');
                }
                $rmcp_settings = get_option('royal_mcp_settings', []);
                if (empty($rmcp_settings['allow_theme_writes'])) {
                    throw new \Exception('Theme writes are disabled. Enable "Allow AI to modify theme appearance" under Royal MCP > Settings.');
                }
                $widget_id = isset($args['id']) ? sanitize_text_field((string) $args['id']) : '';
                if ($widget_id === '') {
                    throw new \Exception('Widget id is required.');
                }
                $request = new \WP_REST_Request('PUT', '/wp/v2/widgets/' . $widget_id);
                foreach (['sidebar', 'instance', 'form_data'] as $param) {
                    if (isset($args[$param])) {
                        $request->set_param($param, $args[$param]);
                    }
                }
                $response = rest_do_request($request);
                if ($response->is_error()) {
                    throw new \Exception(esc_html($response->as_error()->get_error_message()));
                }
                return $response->get_data();

            // ==================== SEO META (Yoast / Rank Math auto-detect) ====================
            case 'wp_get_seo_meta':
                $post_id = self::resolve_post_id_arg($args);
                if ($post_id <= 0) throw new \Exception('post_id (or id) is required.');
                if (!get_post($post_id)) throw new \Exception('Post not found: ' . esc_html((string) $post_id));
                // read_post on the parent gates SEO-meta reads
                // (private/draft posts' SEO meta is not public).
                if (!current_user_can('read_post', $post_id)) {
                    throw new \Exception('You do not have permission to read SEO meta on this post.');
                }
                // Slug is a WordPress-native field (post_name column) — always
                // returned regardless of whether an SEO plugin is detected.
                // Yoast and Rank Math both expose the slug in their post editors
                // alongside the SEO meta fields, so callers expect it here.
                $slug = (string) get_post_field('post_name', $post_id);
                $detected = $this->detect_seo_plugin();
                if ($detected === 'yoast') {
                    return [
                        'plugin'         => 'yoast',
                        'post_id'        => $post_id,
                        'title'          => (string) get_post_meta($post_id, '_yoast_wpseo_title', true),
                        'description'    => (string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true),
                        'focus_keyword'  => (string) get_post_meta($post_id, '_yoast_wpseo_focuskw', true),
                        'noindex'        => get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true) === '1',
                        'og_title'       => (string) get_post_meta($post_id, '_yoast_wpseo_opengraph-title', true),
                        'og_description' => (string) get_post_meta($post_id, '_yoast_wpseo_opengraph-description', true),
                        'slug'           => $slug,
                    ];
                }
                if ($detected === 'rankmath') {
                    return [
                        'plugin'         => 'rankmath',
                        'post_id'        => $post_id,
                        'title'          => (string) get_post_meta($post_id, 'rank_math_title', true),
                        'description'    => (string) get_post_meta($post_id, 'rank_math_description', true),
                        'focus_keyword'  => (string) get_post_meta($post_id, 'rank_math_focus_keyword', true),
                        'noindex'        => in_array('noindex', (array) get_post_meta($post_id, 'rank_math_robots', true), true),
                        'og_title'       => (string) get_post_meta($post_id, 'rank_math_facebook_title', true),
                        'og_description' => (string) get_post_meta($post_id, 'rank_math_facebook_description', true),
                        'slug'           => $slug,
                    ];
                }
                if ($detected === 'aioseo') {
                    // AIOSEO also mirrors to wp_aioseo_posts table but post_meta
                    // is populated for portability + read-back consistency.
                    // focus_keyphrase (not focus_keyword) is AIOSEO's naming.
                    return [
                        'plugin'        => 'aioseo',
                        'post_id'       => $post_id,
                        'title'         => (string) get_post_meta($post_id, '_aioseo_title', true),
                        'description'   => (string) get_post_meta($post_id, '_aioseo_description', true),
                        'focus_keyword' => (string) get_post_meta($post_id, '_aioseo_focus_keyphrase', true),
                        'noindex'       => (bool) get_post_meta($post_id, '_aioseo_noindex', true),
                        'slug'          => $slug,
                    ];
                }
                if ($detected === 'seobolt') {
                    return [
                        'plugin'        => 'seobolt',
                        'post_id'       => $post_id,
                        'title'         => (string) get_post_meta($post_id, '_seobolt_meta_title', true),
                        'description'   => (string) get_post_meta($post_id, '_seobolt_meta_description', true),
                        'focus_keyword' => (string) get_post_meta($post_id, '_seobolt_focus_keyword', true),
                        'noindex'       => (bool) get_post_meta($post_id, '_seobolt_noindex', true),
                        'slug'          => $slug,
                    ];
                }
                return [
                    'plugin'  => 'none',
                    'post_id' => $post_id,
                    'slug'    => $slug,
                    'note'    => 'No SEO plugin (Yoast SEO, Rank Math, AIOSEO, or SEObolt) detected on this site. The slug field is still returned because it is a WordPress-native field.',
                ];

            case 'wp_update_seo_meta':
                $post_id = self::resolve_post_id_arg($args);
                if ($post_id <= 0) throw new \Exception('post_id (or id) is required.');
                if (!get_post($post_id)) throw new \Exception('Post not found: ' . esc_html((string) $post_id));
                if (!current_user_can('edit_post', $post_id)) {
                    throw new \Exception('edit_post capability required for this post.');
                }
                $detected = $this->detect_seo_plugin();
                $seo_field_keys = ['title', 'description', 'focus_keyword', 'og_title', 'og_description', 'noindex'];
                $wants_seo_field = false;
                foreach ($seo_field_keys as $k) {
                    if (array_key_exists($k, $args)) { $wants_seo_field = true; break; }
                }
                if ($wants_seo_field && $detected === 'none') {
                    throw new \Exception('No SEO plugin (Yoast SEO, Rank Math, AIOSEO, or SEObolt) is active. Install one first, or pass only the slug field (which is WordPress-native and works without an SEO plugin).');
                }

                // Per-plugin field maps. AIOSEO + SEObolt omit og_* here because
                // they store OG data in different shapes (AIOSEO: nested wp_options,
                // SEObolt: same fields as core meta by default) — pass those to
                // wp_update_post_meta directly if needed for those plugins.
                $field_maps = [
                    'yoast'    => [
                        'title'          => '_yoast_wpseo_title',
                        'description'    => '_yoast_wpseo_metadesc',
                        'focus_keyword'  => '_yoast_wpseo_focuskw',
                        'og_title'       => '_yoast_wpseo_opengraph-title',
                        'og_description' => '_yoast_wpseo_opengraph-description',
                    ],
                    'rankmath' => [
                        'title'          => 'rank_math_title',
                        'description'    => 'rank_math_description',
                        'focus_keyword'  => 'rank_math_focus_keyword',
                        'og_title'       => 'rank_math_facebook_title',
                        'og_description' => 'rank_math_facebook_description',
                    ],
                    'aioseo'   => [
                        'title'          => '_aioseo_title',
                        'description'    => '_aioseo_description',
                        'focus_keyword'  => '_aioseo_focus_keyphrase',
                    ],
                    'seobolt'  => [
                        'title'          => '_seobolt_meta_title',
                        'description'    => '_seobolt_meta_description',
                        'focus_keyword'  => '_seobolt_focus_keyword',
                    ],
                ];
                $field_map = $field_maps[$detected] ?? [];

                // Closure: read a single normalized field value regardless of
                // adapter. Bool for noindex, string for everything else. Used
                // for both the pre-write snapshot and the post-write re-read
                // so the diff compares apples to apples.
                $seo_read = function( $arg_key ) use ( $post_id, $detected, $field_map ) {
                    if ( $arg_key === 'slug' ) {
                        return (string) get_post_field( 'post_name', $post_id );
                    }
                    if ( $arg_key === 'noindex' ) {
                        if ( $detected === 'yoast' )    return get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) === '1';
                        if ( $detected === 'rankmath' ) return in_array( 'noindex', (array) get_post_meta( $post_id, 'rank_math_robots', true ), true );
                        if ( $detected === 'aioseo' )   return (bool) get_post_meta( $post_id, '_aioseo_noindex', true );
                        if ( $detected === 'seobolt' )  return (bool) get_post_meta( $post_id, '_seobolt_noindex', true );
                        return false;
                    }
                    $meta_key = $field_map[ $arg_key ] ?? '';
                    return $meta_key !== '' ? (string) get_post_meta( $post_id, $meta_key, true ) : '';
                };

                // Requested set: only fields the caller actually passed AND that
                // are supported by the detected adapter. Fields the caller passed
                // that this adapter doesn't route are surfaced separately as
                // `unsupported_fields` — not a silent drop (we never tried to
                // write), just an adapter-capability signal.
                $seo_requested = [];
                $seo_before    = [];
                $unsupported_fields = [];
                foreach ( [ 'title', 'description', 'focus_keyword', 'og_title', 'og_description' ] as $arg_key ) {
                    if ( ! array_key_exists( $arg_key, $args ) ) continue;
                    if ( isset( $field_map[ $arg_key ] ) ) {
                        $seo_requested[ $arg_key ] = sanitize_text_field( (string) $args[ $arg_key ] );
                        $seo_before[ $arg_key ]    = $seo_read( $arg_key );
                    } else {
                        $unsupported_fields[] = $arg_key;
                    }
                }
                if ( array_key_exists( 'noindex', $args ) && in_array( $detected, [ 'yoast', 'rankmath', 'aioseo', 'seobolt' ], true ) ) {
                    $seo_requested['noindex'] = (bool) $args['noindex'];
                    $seo_before['noindex']    = (bool) $seo_read( 'noindex' );
                }
                if ( array_key_exists( 'slug', $args ) ) {
                    $requested_slug = sanitize_title( (string) $args['slug'] );
                    if ( $requested_slug === '' ) {
                        throw new \Exception('slug cannot be empty after sanitization. Pass a non-empty slug or omit the field.');
                    }
                    $seo_requested['slug'] = $requested_slug;
                    $seo_before['slug']    = $seo_read( 'slug' );
                }

                // Execute — per-adapter write branches, unchanged from original logic.
                foreach ( $field_map as $arg_key => $meta_key ) {
                    if ( array_key_exists( $arg_key, $seo_requested ) && $arg_key !== 'noindex' && $arg_key !== 'slug' ) {
                        update_post_meta( $post_id, $meta_key, $seo_requested[ $arg_key ] );
                    }
                }
                if ( array_key_exists( 'noindex', $seo_requested ) ) {
                    $noindex_bool = (bool) $seo_requested['noindex'];
                    if ( $detected === 'yoast' ) {
                        update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $noindex_bool ? '1' : '0' );
                    } elseif ( $detected === 'rankmath' ) {
                        $robots = get_post_meta( $post_id, 'rank_math_robots', true );
                        $robots = is_array( $robots ) ? $robots : [];
                        $robots = array_filter( $robots, fn( $r ) => $r !== '' && $r !== 'noindex' && $r !== 'index' );
                        $robots[] = $noindex_bool ? 'noindex' : 'index';
                        $robots = array_values( array_unique( $robots ) );
                        update_post_meta( $post_id, 'rank_math_robots', $robots );
                    } elseif ( $detected === 'aioseo' ) {
                        update_post_meta( $post_id, '_aioseo_noindex', $noindex_bool ? '1' : '' );
                    } elseif ( $detected === 'seobolt' ) {
                        update_post_meta( $post_id, '_seobolt_noindex', $noindex_bool ? '1' : '' );
                    }
                }
                if ( array_key_exists( 'slug', $seo_requested ) ) {
                    $update_result = wp_update_post( [
                        'ID'        => $post_id,
                        'post_name' => $seo_requested['slug'],
                    ], true );
                    if ( is_wp_error( $update_result ) ) {
                        throw new \Exception( 'Slug update failed: ' . $update_result->get_error_message() );
                    }
                }
                // Cache invalidate before re-read.
                wp_cache_delete( $post_id, 'post_meta' );
                clean_post_cache( $post_id );

                // Re-read AFTER-state for the same requested keys.
                $seo_actual = [];
                foreach ( array_keys( $seo_requested ) as $arg_key ) {
                    $seo_actual[ $arg_key ] = $seo_read( $arg_key );
                }

                // Diff. Slug is a well-known silent-modify (WP appends -2, -3
                // on collision), tracked via modified_by_wp. Other adapter
                // fields shouldn't silent-modify unless a downstream filter
                // hooks post_meta save.
                $seo_diff = \Royal_MCP\MCP\Support\WriteVerifier::diff( $seo_requested, $seo_before, $seo_actual );
                \Royal_MCP\MCP\Support\WriteVerifier::throw_if_dropped( $seo_diff, 'wp_update_seo_meta' );

                // Undo envelope — snapshot the BEFORE values (normalized form).
                // Restore path re-uses the same per-adapter write logic.
                $seo_undo_envelope = null;
                if ( ! empty( $seo_before ) ) {
                    $seo_undo_envelope = \Royal_MCP\MCP\Undo_Store::store( [
                        'op'      => 'wp_update_seo_meta',
                        'summary' => sprintf( 'Restore %d SEO field(s) on post %d (adapter: %s).', count( $seo_before ), $post_id, $detected ),
                        'target'  => [ 'post_id' => $post_id, 'adapter' => $detected ],
                        'pre_op_state' => [
                            'prior_values'   => $seo_before,
                            'applied_values' => $seo_actual,
                        ],
                    ] );
                }

                $seo_struct = array_merge(
                    [
                        'plugin'  => $detected,
                        'post_id' => $post_id,
                    ],
                    \Royal_MCP\MCP\Support\WriteVerifier::response_partial( $seo_diff )
                );
                if ( ! empty( $unsupported_fields ) ) {
                    $seo_struct['unsupported_fields'] = $unsupported_fields;
                    $seo_struct['unsupported_note']   = sprintf(
                        'These fields were passed but are not supported by the detected adapter (%s): %s. Use wp_update_post_meta with the plugin-specific meta_key if you need to write these directly.',
                        $detected,
                        implode( ', ', $unsupported_fields )
                    );
                }
                $seo_summary = sprintf(
                    'Updated %d SEO field(s) on post %d via %s adapter%s%s%s.',
                    count( $seo_diff['applied'] ) + count( $seo_diff['silent_modifies'] ),
                    $post_id,
                    $detected,
                    ! empty( $seo_diff['silent_modifies'] ) ? ' (WP modified value, e.g. slug uniqueness suffix)' : '',
                    ! empty( $unsupported_fields ) ? sprintf( ', %d unsupported field(s) skipped', count( $unsupported_fields ) ) : '',
                    $seo_undo_envelope !== null ? ', undo available' : ''
                );
                return \Royal_MCP\MCP\Support\Envelope::success(
                    $seo_summary,
                    $seo_struct,
                    $seo_undo_envelope
                );

            // ==================== PERMALINK STRUCTURE ====================
            case 'wp_get_permalink_structure':
                if (!current_user_can('manage_options')) {
                    throw new \Exception('You do not have permission to read permalink structure.');
                }
                return [
                    'permalink_structure' => (string) get_option('permalink_structure', ''),
                    'category_base'       => (string) get_option('category_base', ''),
                    'tag_base'            => (string) get_option('tag_base', ''),
                ];

            case 'wp_update_permalink_structure':
                $rmcp_settings = get_option('royal_mcp_settings', []);
                if (empty($rmcp_settings['allow_option_writes'])) {
                    throw new \Exception('Permalink writes are disabled. Enable "Allow AI to write WordPress options" under Royal MCP > Settings.');
                }
                if (!current_user_can('manage_options')) {
                    throw new \Exception('manage_options capability required.');
                }
                // sanitize_text_field() strips %XX percent sequences via its
                // /%[a-f0-9]{2}/i pattern — %ca in %category% matches and gets
                // eaten, corrupting valid permalink tokens. Approximate WP core's
                // own permalink-form handling (strip #, defensive UTF-8, tag
                // strip, whitespace) since the source is an MCP client. The
                // value ultimately flows through set_permalink_structure(),
                // which validates it.
                $pl_structure_raw = isset($args['structure']) ? (string) $args['structure'] : '';
                $pl_structure = wp_check_invalid_utf8( $pl_structure_raw );
                $pl_structure = wp_strip_all_tags( $pl_structure );
                $pl_structure = str_replace( '#', '', $pl_structure );
                $pl_structure = preg_replace( '/[\r\n\t]+/', '', $pl_structure );
                $pl_structure = trim( $pl_structure );
                if (empty($pl_structure)) {
                    throw new \Exception('structure is required (e.g. /%postname%/)');
                }
                $pl_previous = (string) get_option('permalink_structure', '');
                global $wp_rewrite;
                if ($wp_rewrite) {
                    $wp_rewrite->set_permalink_structure($pl_structure);
                    $wp_rewrite->flush_rules();
                } else {
                    update_option('permalink_structure', $pl_structure);
                }
                $pl_actual = (string) get_option('permalink_structure', '');

                // Permalink structures require strict input fidelity — any %<token>%
                // altered by the sanitizer breaks rewrite rules. Pass raw input into
                // diff() and fail-loud if the sanitizer changed it, as a belt-and-braces
                // second line of defense (the primary defense is the safe sanitizer above).
                $pl_diff = \Royal_MCP\MCP\Support\WriteVerifier::diff(
                    [ 'structure' => $pl_structure ],
                    [ 'structure' => $pl_previous ],
                    [ 'structure' => $pl_actual ],
                    [ 'structure' => $pl_structure_raw ]
                );
                \Royal_MCP\MCP\Support\WriteVerifier::throw_if_dropped( $pl_diff, 'wp_update_permalink_structure' );
                \Royal_MCP\MCP\Support\WriteVerifier::throw_if_input_mangled( $pl_diff, 'wp_update_permalink_structure' );

                // Permalink structures are always short strings — no size cap needed.
                $pl_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
                    'op'      => 'wp_update_permalink_structure',
                    'summary' => sprintf( 'Restore permalink structure to prior value (%s) and flush rewrite rules.', $pl_previous !== '' ? $pl_previous : '(plain)' ),
                    'target'  => [],
                    'pre_op_state' => [
                        'prior_structure'   => $pl_previous,
                        'applied_structure' => $pl_actual,
                    ],
                ]);

                $pl_struct = array_merge(
                    [
                        'success'  => true,
                        'previous' => $pl_previous,
                        'current'  => $pl_actual,
                    ],
                    \Royal_MCP\MCP\Support\WriteVerifier::response_partial( $pl_diff )
                );
                $pl_summary = sprintf(
                    'Updated permalink structure to %s (rewrite rules flushed%s), undo available.',
                    $pl_actual !== '' ? $pl_actual : '(plain)',
                    ! empty( $pl_diff['silent_modifies'] ) ? ', WP modified value' : ''
                );
                return \Royal_MCP\MCP\Support\Envelope::success(
                    $pl_summary,
                    $pl_struct,
                    $pl_undo_envelope
                );

            // ==================== POST REVISIONS ====================
            case 'wp_get_post_revisions':
                $post_id = self::resolve_post_id_arg($args);
                if ($post_id <= 0) throw new \Exception('post_id (or id) is required.');
                if (!get_post($post_id)) throw new \Exception('Post not found.');
                // revisions can contain past versions of admin-owned
                // content; gate behind the parent post's read cap.
                if (!current_user_can('read_post', $post_id)) {
                    throw new \Exception('You do not have permission to read revisions on this post.');
                }
                $limit = min(intval($args['limit'] ?? 20), 100);
                $revisions = wp_get_post_revisions($post_id, ['number' => $limit]);
                return array_map(function($r) {
                    // word_count uses strip_tags → misses text stored inside
                    // attributes (Divi 5 blocks nest text in attrs.content,
                    // element data-* attrs, alt text). content_length is the
                    // raw byte size — a non-zero value proves the revision
                    // is not empty even when word_count reports 0.
                    return [
                        'revision_id'    => (int) $r->ID,
                        'parent_id'      => (int) $r->post_parent,
                        'author_id'      => (int) $r->post_author,
                        'author_name'    => get_the_author_meta('display_name', $r->post_author),
                        'date'           => $r->post_date,
                        'title'          => $r->post_title,
                        'word_count'     => str_word_count(wp_strip_all_tags((string) $r->post_content)),
                        'content_length' => strlen((string) $r->post_content),
                    ];
                }, array_values($revisions));

            case 'wp_create_preview_link':
                $post_id = self::resolve_post_id_arg($args);
                if ($post_id <= 0) throw new \Exception('post_id (or id) is required.');
                if (!current_user_can('edit_post', $post_id)) {
                    throw new \Exception('edit_post capability required on this post.');
                }
                $post = get_post($post_id);
                if (!$post) throw new \Exception('Post not found.');

                $ttl_minutes = intval($args['ttl_minutes'] ?? \Royal_MCP\MCP\Support\Preview_Link::DEFAULT_TTL_MINUTES);
                if ($ttl_minutes < 1) $ttl_minutes = \Royal_MCP\MCP\Support\Preview_Link::DEFAULT_TTL_MINUTES;
                if ($ttl_minutes > \Royal_MCP\MCP\Support\Preview_Link::MAX_TTL_MINUTES) {
                    $ttl_minutes = \Royal_MCP\MCP\Support\Preview_Link::MAX_TTL_MINUTES;
                }
                $ttl_seconds = $ttl_minutes * 60;
                $expires_at_unix = time() + $ttl_seconds;

                $token = \Royal_MCP\MCP\Support\Preview_Link::create($post_id, get_current_user_id(), $ttl_seconds);

                // Latest revision (or null when no revisions exist yet — new
                // drafts saved once have no children in the revision table).
                $revisions = wp_get_post_revisions($post_id, ['number' => 1]);
                $revision_id = !empty($revisions) ? (int) reset($revisions)->ID : null;

                return [
                    'preview_url'     => \Royal_MCP\MCP\Support\Preview_Link::build_url($token),
                    'edit_url'        => admin_url('post.php?post=' . $post_id . '&action=edit'),
                    'post_id'         => $post_id,
                    'revision_id'    => $revision_id,
                    'ttl_minutes'     => $ttl_minutes,
                    'expires_at'      => gmdate('c', $expires_at_unix),
                    'expires_at_unix' => $expires_at_unix,
                ];

            case 'wp_get_revision_content':
                $revision_id = intval($args['revision_id'] ?? 0);
                if ($revision_id <= 0) throw new \Exception('revision_id is required.');
                $revision = wp_get_post_revision($revision_id);
                if (!$revision) throw new \Exception('Revision not found.');
                // Gate on the PARENT post's read cap, not the revision's own
                // ID — a revision inherits the sensitivity of the post it
                // belongs to. Same gate as wp_get_post_revisions above.
                if (!current_user_can('read_post', (int) $revision->post_parent)) {
                    throw new \Exception('You do not have permission to read revisions on this post.');
                }
                return [
                    'revision_id'    => (int) $revision->ID,
                    'parent_id'      => (int) $revision->post_parent,
                    'date'           => $revision->post_date,
                    'author_name'    => get_the_author_meta('display_name', $revision->post_author),
                    'title'          => $revision->post_title,
                    'content'        => $revision->post_content,
                    'excerpt'        => $revision->post_excerpt,
                    'content_length' => strlen((string) $revision->post_content),
                ];

            case 'wp_diff_revisions':
                $post_id = self::resolve_post_id_arg($args);
                if ($post_id <= 0) throw new \Exception('post_id (or id) is required.');
                $parent = get_post($post_id);
                if (!$parent) throw new \Exception('Post not found.');
                if (!current_user_can('read_post', $post_id)) {
                    throw new \Exception('You do not have permission to read revisions on this post.');
                }
                $max_lines = min(max(intval($args['max_lines'] ?? 500), 1), 5000);
                // "from" side: explicit revision, else the newest revision.
                $from_id = intval($args['from_revision_id'] ?? 0);
                if ($from_id > 0) {
                    $from_rev = wp_get_post_revision($from_id);
                    if (!$from_rev || (int) $from_rev->post_parent !== $post_id) {
                        throw new \Exception('from_revision_id does not belong to this post.');
                    }
                    $from_content = (string) $from_rev->post_content;
                    $from_label   = 'revision ' . (int) $from_rev->ID . ' (' . $from_rev->post_date . ')';
                } else {
                    $newest = wp_get_post_revisions($post_id, ['number' => 1]);
                    if (empty($newest)) throw new \Exception('This post has no stored revisions to compare against.');
                    $newest = array_values($newest)[0];
                    $from_content = (string) $newest->post_content;
                    $from_label   = 'revision ' . (int) $newest->ID . ' (' . $newest->post_date . ')';
                }
                // "to" side: explicit revision, else the current live content.
                $to_id = intval($args['to_revision_id'] ?? 0);
                if ($to_id > 0) {
                    $to_rev = wp_get_post_revision($to_id);
                    if (!$to_rev || (int) $to_rev->post_parent !== $post_id) {
                        throw new \Exception('to_revision_id does not belong to this post.');
                    }
                    $to_content = (string) $to_rev->post_content;
                    $to_label   = 'revision ' . (int) $to_rev->ID . ' (' . $to_rev->post_date . ')';
                } else {
                    $to_content = (string) $parent->post_content;
                    $to_label   = 'current content';
                }
                if ($from_content === $to_content) {
                    return [
                        'from' => $from_label, 'to' => $to_label, 'identical' => true,
                        'diff' => '', 'lines_added' => 0, 'lines_removed' => 0, 'truncated' => false,
                    ];
                }
                // Text_Diff ships with WordPress but is not loaded on REST requests.
                if (!class_exists('Text_Diff')) {
                    require_once ABSPATH . WPINC . '/wp-diff.php';
                }
                if (!class_exists('Text_Diff')) {
                    throw new \Exception('Diff engine unavailable on this installation.');
                }
                // Normalise line endings for comparison only — both tools are
                // read-only, so stored content is untouched. This keeps a pure
                // CRLF/LF difference from rendering as a whole-file rewrite.
                $norm = static function ($text) {
                    return explode("\n", str_replace(["\r\n", "\r"], "\n", (string) $text));
                };
                $engine = new \Text_Diff('auto', [$norm($from_content), $norm($to_content)]);
                $out = '';
                $added = 0;
                $removed = 0;
                $emitted = 0;
                $truncated = false;
                foreach ($engine->getDiff() as $op) {
                    $orig  = is_array($op->orig)  ? $op->orig  : [];
                    $final = is_array($op->final) ? $op->final : [];
                    // Copy ops have identical sides — skip by value rather than
                    // relying on Text_Diff_Op_* subclass names.
                    if ($orig && $final && $orig === $final) continue;
                    foreach ($orig as $line) {
                        if ($emitted >= $max_lines) { $truncated = true; break 2; }
                        $out .= '-' . $line . "\n"; $removed++; $emitted++;
                    }
                    foreach ($final as $line) {
                        if ($emitted >= $max_lines) { $truncated = true; break 2; }
                        $out .= '+' . $line . "\n"; $added++; $emitted++;
                    }
                }
                return [
                    'from' => $from_label, 'to' => $to_label, 'identical' => false,
                    'diff' => $out, 'lines_added' => $added, 'lines_removed' => $removed,
                    'truncated' => $truncated,
                ];

            case 'wp_restore_revision':
                $revision_id = intval($args['revision_id'] ?? 0);
                if ($revision_id <= 0) throw new \Exception('revision_id is required.');
                $revision = wp_get_post_revision($revision_id);
                if (!$revision) throw new \Exception('Revision not found.');
                if (!current_user_can('edit_post', $revision->post_parent)) {
                    throw new \Exception('edit_post capability required for the parent post.');
                }
                $result = wp_restore_post_revision($revision_id);
                if (!$result) throw new \Exception('Failed to restore revision.');
                return [
                    'success'   => true,
                    'parent_id' => (int) $revision->post_parent,
                    'restored_revision_id' => $revision_id,
                ];

            default:
                // Route to integration handlers
                if ( strpos( $name, 'wc_' ) === 0 ) {
                    return WooIntegration::execute_tool( $name, $args );
                }
                if ( strpos( $name, 'gp_' ) === 0 ) {
                    return GPIntegration::execute_tool( $name, $args );
                }
                if ( strpos( $name, 'sv_' ) === 0 ) {
                    return SVIntegration::execute_tool( $name, $args );
                }
                if ( strpos( $name, 'rl_' ) === 0 ) {
                    return RLIntegration::execute_tool( $name, $args );
                }
                if ( strpos( $name, 'fc_' ) === 0 ) {
                    return FCIntegration::execute_tool( $name, $args );
                }
                if ( strpos( $name, 'rlinks_' ) === 0 ) {
                    return RLinksIntegration::execute_tool( $name, $args );
                }
                if ( strpos( $name, 'elementor_' ) === 0 ) {
                    return ElementorIntegration::execute_tool( $name, $args );
                }
                if ( strpos( $name, 'acf_' ) === 0 ) {
                    return ACFIntegration::execute_tool( $name, $args );
                }
                if ( strpos( $name, 'raif_' ) === 0 ) {
                    return RAIFIntegration::execute_tool( $name, $args );
                }
                if ( strpos( $name, 'redirection_' ) === 0 ) {
                    return RedirectionIntegration::execute_tool( $name, $args );
                }
                if ( strpos( $name, 'divi_' ) === 0 ) {
                    return DiviIntegration::execute_tool( $name, $args );
                }
                if ( 'wp_publish_and_promote' === $name ) {
                    return ComposersIntegration::execute_tool( $name, $args );
                }
                throw new \Exception('Unknown tool: ' . esc_html($name));
        }
    }

    /**
     * Build menu-item update args that preserve existing values for any field
     * not in $overrides. Without this read-merge-write pattern, callers passing
     * partial args to wp_update_nav_menu_item() silently zero title/url/parent
     * on the existing item — a destructive class of bug where a well-intentioned
     * partial update erases fields the caller never touched.
     *
     * Returns an array suitable for wp_update_nav_menu_item(), or WP_Error if
     * the item doesn't exist, the merge would still destroy a non-empty
     * existing field, or the caller explicitly passed empty for a destructive
     * field that's currently non-empty.
     *
     * @param int   $item_id   Menu item post ID.
     * @param array $overrides Caller-supplied fields keyed by wp_update_nav_menu_item arg name (e.g. 'menu-item-title').
     * @return array|\WP_Error
     */
    private function build_safe_menu_item_args($item_id, $overrides) {
        $post = get_post($item_id);
        if (!$post || $post->post_type !== 'nav_menu_item') {
            return new \WP_Error('item_not_found', "Menu item {$item_id} not found.");
        }
        $existing = wp_setup_nav_menu_item($post);
        if (!$existing || is_wp_error($existing)) {
            return new \WP_Error('item_setup_failed', "Could not read menu item {$item_id} for safe merge.");
        }
        $classes = $existing->classes ?? '';
        if (is_array($classes)) {
            $classes = implode(' ', $classes);
        }
        $base = [
            'menu-item-db-id'       => (int) $item_id,
            'menu-item-object-id'   => (int) ($existing->object_id ?? 0),
            'menu-item-object'      => (string) ($existing->object ?? ''),
            'menu-item-parent-id'   => (int) ($existing->menu_item_parent ?? 0),
            'menu-item-position'    => (int) ($existing->menu_order ?? 0),
            'menu-item-type'        => (string) ($existing->type ?? 'custom'),
            'menu-item-title'       => (string) ($existing->title ?? ''),
            'menu-item-url'         => (string) ($existing->url ?? ''),
            'menu-item-description' => (string) ($existing->description ?? ''),
            'menu-item-attr-title'  => (string) ($existing->attr_title ?? ''),
            'menu-item-target'      => (string) ($existing->target ?? ''),
            'menu-item-classes'     => (string) $classes,
            'menu-item-xfn'         => (string) ($existing->xfn ?? ''),
            'menu-item-status'      => 'publish',
        ];
        // Destructive-operation guardrail: refuse if the caller explicitly
        // passed an empty value for a destructive-relevant field that's
        // currently non-empty. Catches the AI-agent failure mode where
        // partial args spell out empty strings for fields they didn't mean
        // to change. To clear a field intentionally, delete + recreate the
        // item instead.
        $destructive_fields = ['menu-item-title', 'menu-item-url'];
        foreach ($destructive_fields as $arg_key) {
            if (!array_key_exists($arg_key, $overrides)) {
                continue;
            }
            $existing_value = (string) ($base[$arg_key] ?? '');
            $new_value = (string) $overrides[$arg_key];
            if ($existing_value !== '' && $new_value === '') {
                return new \WP_Error(
                    'destructive_operation_blocked',
                    "Refused: passing empty '{$arg_key}' would zero a non-empty value on menu item {$item_id}. To clear intentionally, use wp_delete_menu_item + wp_create_menu_item."
                );
            }
        }
        return array_merge($base, $overrides);
    }

    /**
     * Set or remove the featured image on a post. media_id=0 removes it.
     */
    /**
     * Detect which SEO plugin is active.
     *
     * @return string 'yoast', 'rankmath', or 'none'.
     */
    private function detect_seo_plugin() {
        if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
            return 'yoast';
        }
        if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
            return 'rankmath';
        }
        if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
            return 'aioseo';
        }
        if ( defined( 'SEOBOLT_VERSION' ) ) {
            return 'seobolt';
        }
        return 'none';
    }

    private function apply_featured_media($post_id, $media_id) {
        if ($media_id <= 0) {
            delete_post_thumbnail($post_id);
            return;
        }
        $attachment = get_post($media_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            throw new \Exception('Media attachment not found.');
        }
        $result = set_post_thumbnail($post_id, $media_id);
        if (!$result) throw new \Exception('Failed to set featured image.');
    }

    /**
     * Download an image from a public URL and create a media attachment.
     * SSRF-hardened: blocks private/reserved IP ranges, requires https,
     * caps size and time, validates mime type against extension.
     */
    private function sideload_image_from_url($url, $filename, $title, $caption, $alt_text) {
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            throw new \Exception('URL must include scheme and host.');
        }
        $scheme = strtolower($parts['scheme']);
        $host   = strtolower($parts['host']);
        $is_local_host = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $is_local_host)) {
            throw new \Exception('Only https:// URLs are allowed.');
        }
        // Resolve and block private/reserved IPs.
        if (!$is_local_host) {
            $ips = @gethostbynamel($host);
            if (empty($ips)) throw new \Exception('Could not resolve host.');
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new \Exception('URL resolves to a blocked address range.');
                }
            }
        }
        // Fetch.
        $response = wp_safe_remote_get($url, [
            'timeout'             => 10,
            'redirection'         => 3,
            'limit_response_size' => 20 * 1024 * 1024, // 20 MB
        ]);
        if (is_wp_error($response)) throw new \Exception(esc_html($response->get_error_message()));
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) throw new \Exception('Download failed with HTTP ' . intval($code) . '.');
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) throw new \Exception('Downloaded file is empty.');
        if (strlen($body) > 20 * 1024 * 1024) throw new \Exception('File exceeds 20 MB limit.');
        // Derive filename if not given.
        if (empty($filename)) {
            $path = isset($parts['path']) ? basename($parts['path']) : '';
            $filename = sanitize_file_name($path ?: 'download');
        }
        // Many image CDNs (Unsplash, Pexels, etc) serve extensionless URLs. If the filename has no
        // extension, derive one from the response Content-Type so wp_check_filetype_and_ext can validate.
        if (empty(pathinfo($filename, PATHINFO_EXTENSION))) {
            $content_type = wp_remote_retrieve_header($response, 'content-type');
            if ($content_type) {
                $content_type = strtolower(trim(explode(';', $content_type)[0]));
            }
            $mime_to_ext = [
                'image/jpeg' => 'jpg',
                'image/jpg'  => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
                'image/avif' => 'avif',
                'image/bmp'  => 'bmp',
            ];
            if (isset($mime_to_ext[$content_type])) {
                $filename .= '.' . $mime_to_ext[$content_type];
            } else {
                throw new \Exception('Could not determine image type (Content-Type: ' . esc_html($content_type ?: 'unknown') . ').');
            }
        }
        return $this->sideload_image_from_bytes($body, $filename, $title, $caption, $alt_text);
    }

    /**
     * Persist raw image bytes to the uploads dir and create an attachment.
     * Validates mime against extension and rejects scriptable formats.
     */
    private function sideload_image_from_bytes($bytes, $filename, $title, $caption, $alt_text) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        if (empty($bytes))    throw new \Exception('No file contents provided.');
        if (empty($filename)) throw new \Exception('Filename is required.');
        if (strlen($bytes) > 20 * 1024 * 1024) throw new \Exception('File exceeds 20 MB limit.');

        // Reject scriptable formats outright — SVG/XML can contain script payloads.
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $blocked_ext = ['svg', 'svgz', 'html', 'htm', 'xml', 'js', 'php', 'phtml', 'phar', 'exe'];
        if (in_array($ext, $blocked_ext, true)) {
            throw new \Exception('File type .' . esc_html($ext) . ' is not allowed.');
        }

        // Write to a tmp file so we can run WP's own type/ext check.
        $tmp = wp_tempnam($filename);
        if (!$tmp) throw new \Exception('Could not create temp file.');
        if (file_put_contents($tmp, $bytes) === false) {
            wp_delete_file($tmp);
            throw new \Exception('Could not write temp file.');
        }
        $check = wp_check_filetype_and_ext($tmp, $filename);
        if (empty($check['type']) || empty($check['ext'])) {
            wp_delete_file($tmp);
            throw new \Exception('File type could not be verified or is not permitted by WordPress.');
        }
        if (strpos($check['type'], 'image/') !== 0) {
            wp_delete_file($tmp);
            throw new \Exception('Only image uploads are supported here (got ' . esc_html($check['type']) . ').');
        }

        $file_array = [
            'name'     => $check['proper_filename'] ?: $filename,
            'tmp_name' => $tmp,
        ];
        // Let WP move it to uploads and generate the attachment.
        $attachment_id = media_handle_sideload($file_array, 0, $title ?: null);
        if (is_wp_error($attachment_id)) {
            wp_delete_file($tmp);
            throw new \Exception(esc_html($attachment_id->get_error_message()));
        }
        if (!empty($caption)) {
            wp_update_post(['ID' => $attachment_id, 'post_excerpt' => $caption]);
        }
        if (!empty($alt_text)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
        }
        return (int) $attachment_id;
    }

    /**
     * Look up all wp_options entries that appear to belong to a given plugin slug.
     * Uses a LIKE match on slug + slug_with_underscores. Sensitive keys inside
     * the returned values are redacted via redact_sensitive_keys().
     */
    private function find_plugin_options($slug) {
        global $wpdb;
        $slug = sanitize_key($slug);
        if (empty($slug)) return [];

        $variants = array_unique([
            $slug,
            str_replace('-', '_', $slug),
        ]);

        $clauses = [];
        $values  = [];
        foreach ($variants as $variant) {
            $clauses[] = '(option_name = %s OR option_name LIKE %s)';
            $values[]  = $variant;
            $values[]  = $wpdb->esc_like($variant) . '_%';
        }
        $where = implode(' OR ', $clauses);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE {$where} ORDER BY option_name ASC",
                $values
            )
        );
        if (empty($rows)) return [];

        $result = [];
        foreach ($rows as $row) {
            $value = maybe_unserialize($row->option_value);
            $result[$row->option_name] = $this->redact_sensitive_keys($value);
        }
        return $result;
    }

    /**
     * Extract a snippet of $len chars around the first occurrence of $query in $content.
     * Strips shortcodes + HTML first. Falls back to leading $len chars if no match
     * (e.g. when the term only hit the title). Multibyte-safe — WP runs utf8mb4.
     */
    private function extract_snippet($content, $query, $len) {
        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(strip_shortcodes((string) $content))));
        if ($text === '') return '';
        $total = mb_strlen($text);
        if ($total <= $len) return $text;

        $needle = $query;
        $pos = ($needle !== '') ? mb_stripos($text, $needle) : false;
        if ($pos === false) {
            $first_word = strtok($query, ' ');
            if ($first_word !== false && $first_word !== '') {
                $pos = mb_stripos($text, $first_word);
            }
        }
        if ($pos === false) {
            return rtrim(mb_substr($text, 0, $len)) . '…';
        }

        $start = max(0, $pos - intval($len / 2));
        $start = min($start, max(0, $total - $len));
        $excerpt = mb_substr($text, $start, $len);
        if ($start > 0) $excerpt = '…' . ltrim($excerpt);
        if ($start + $len < $total) $excerpt = rtrim($excerpt) . '…';
        return $excerpt;
    }

    /**
     * Walk a value (array/object/scalar) and replace any value whose KEY matches
     * a credential-shaped pattern with the literal string [REDACTED]. Non-array
     * scalars at the top level pass through unchanged.
     */
    private function redact_sensitive_keys($value) {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if ($this->is_sensitive_key($k)) {
                $out[$k] = '[REDACTED]';
                continue;
            }
            $out[$k] = $this->redact_sensitive_keys($v);
        }
        return $out;
    }

    /**
     * Returns true if the given key name looks like a credential.
     * Pattern is intentionally aggressive — false positives are recoverable
     * (user can fetch the underlying option directly), false negatives leak.
     */
    private function is_sensitive_key($key) {
        if (!is_string($key) || $key === '') return false;
        $needles = [
            'password', 'passwd', 'secret', 'salt', 'token', 'nonce',
            'apikey', 'api_key', 'accesskey', 'access_key',
            'private_key', 'public_key',
            'client_secret', 'client_id', 'auth_key', 'auth_token',
            'bearer', 'license_key', 'consumer_secret', 'consumer_key',
            'webhook_secret', 'session_key', 'credentials',
        ];
        $key_lc = strtolower($key);
        foreach ($needles as $needle) {
            if (strpos($key_lc, $needle) !== false) return true;
        }
        return false;
    }

    /**
     * Hard denylist for option writes. These can never be written via MCP,
     * regardless of allowlist or admin toggle.
     */
    /**
     * Return the effective readable-options allowlist for wp_get_option +
     * the write⊆readable invariant check in wp_update_option. Single source
     * of truth so both handlers stay in sync.
     *
     * Site Kit GA4 config (property ID, measurement ID, web data stream ID)
     * — no secrets; redact_sensitive_keys() runs on returns in case Site Kit
     * ever adds one. Site Kit has ~4M installs; common read-tier lookup.
     */
    private function get_readable_options_allowlist() {
        $default = ['blogname', 'blogdescription', 'siteurl', 'home', 'admin_email', 'posts_per_page', 'date_format', 'time_format', 'timezone_string', 'googlesitekit_analytics-4_settings', 'show_on_front', 'page_on_front'];
        $allowed = apply_filters('royal_mcp_readable_options', $default);
        return is_array($allowed) ? $allowed : $default;
    }

    private function is_denylisted_option($name) {
        $name_lc = strtolower($name);

        // Hard exact-match denylist (compared case-insensitively).
        $exact = [
            'siteurl', 'home', 'db_version', 'wp_user_roles', 'cron', 'rewrite_rules',
            'wplang', 'template', 'stylesheet', 'active_plugins',
            'royal_mcp_settings', // Self-protection: prevent AI from disabling its own gates.
            // Takeover / privilege-escalation vectors — each is a direct
            // site-compromise path if inadvertently opted into the writable
            // allowlist via a third-party integration filter.
            'admin_email',        // change → password-reset email hijack → account takeover
            'default_role',       // set to administrator → next self-reg becomes admin
            'users_can_register', // enable → combined with default_role, remote admin creation
            'upload_path',        // redirect uploads to attacker-controlled directory
            'upload_url_path',    // same class — URL rewriting for uploads
            'mailserver_login',   // SMTP credential disclosure if paired with reads
            'mailserver_pass',    // SMTP password — doesn't match _key/_secret patterns
            'mailserver_url',     // redirect outbound mail to attacker relay
            'mailserver_port',    // same class
            // Role/cap-shaped global option names — mostly inert (real per-user
            // cap data lives in wp_usermeta) but any auditor seeing
            // wp_capabilities in the writable set will file a bug.
            'wp_user_level', 'wp_capabilities',
        ];
        if (in_array($name_lc, $exact, true)) return true;

        // Royal MCP namespace is reserved.
        if (strpos($name_lc, 'royal_mcp_') === 0) return true;

        // Pattern denylist on the option name itself.
        $patterns = [
            'secret', 'salt', 'auth_key', 'logged_in_key', 'nonce_key',
            'license_key', 'api_key', 'auth_token', 'private_key',
            'session_token', 'recovery_key',
        ];
        foreach ($patterns as $p) {
            if (strpos($name_lc, $p) !== false) return true;
        }
        return false;
    }

    // =========================================================================
    // LEGACY SSE SUPPORT (deprecated, kept for backwards compatibility)
    // =========================================================================

    /**
     * Legacy SSE endpoint handler - redirects to new streamable HTTP
     * @deprecated Use handle_mcp() instead
     */
    public function handle_sse($request) {
        // Return instructions to use the new endpoint
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode([
            'error' => 'SSE transport deprecated',
            'message' => 'Please use the Streamable HTTP transport at /wp-json/royal-mcp/v1/mcp',
            'endpoint' => rest_url('royal-mcp/v1/mcp'),
            'spec' => '2025-11-25'
        ]);
        exit;
    }

    /**
     * Legacy message handler - redirects to new endpoint
     * @deprecated Use handle_mcp() instead
     */
    public function handle_message($request) {
        // Forward to new handler
        return $this->handle_mcp($request);
    }
}
