<?php
namespace Royal_MCP\MCP;

use Royal_MCP\Integrations\WooCommerce as WooIntegration;
use Royal_MCP\Integrations\GuardPress as GPIntegration;
use Royal_MCP\Integrations\SiteVault as SVIntegration;
use Royal_MCP\Integrations\RoyalLedger as RLIntegration;
use Royal_MCP\Integrations\ForgeCache as FCIntegration;
use Royal_MCP\Integrations\RoyalLinks as RLinksIntegration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCP Server - Streamable HTTP Transport (2025-11-25 spec)
 *
 * Single endpoint that accepts POST for all JSON-RPC messages
 * and returns either JSON or SSE stream based on Accept header.
 *
 * This replaces the deprecated HTTP+SSE transport (2024-11-05).
 */
class Server {

    /**
     * Store active session IDs (in production, use transients or database)
     */
    private $sessions = [];

    /**
     * Rate limit: max requests per window per IP
     */
    private $rate_limit_max = 60;
    private $rate_limit_window = 60; // seconds

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

        // Try OAuth 2.0 Bearer token first.
        $auth_header = $request->get_header('Authorization');
        if (!empty($auth_header) && stripos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);
            return $this->validate_bearer_token($token);
        }

        // Fall back to API key.
        $api_key = $request->get_header('X-Royal-MCP-API-Key');
        if (!empty($api_key)) {
            return $this->validate_api_key_value($api_key, $settings);
        }

        // Neither provided — return 401 with WWW-Authenticate for OAuth discovery.
        // Per MCP spec (2025-06-18 / RFC 9728), include resource_metadata URL.
        // Cache-Control: no-store is critical here. Pre-1.4.15 this 401 was
        // getting cached at edge (URL-keyed) and served to subsequent
        // authenticated requests, breaking every MCP client that hits GET /mcp
        // before sending its credentials (Claude.ai web, ChatGPT discovery).
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
            // permission". Pre-1.4.15 returned 403 here. Strict MCP clients
            // (per RFC 9728 OAuth discovery) start the OAuth flow on 401, not
            // 403, so the wrong status was suppressing legitimate retries.
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

        // Check transient for session validity.
        $session_data = get_transient('royal_mcp_session_' . $session_id);
        if ($session_data === false) {
            return false;
        }

        // Sliding window: refresh the TTL on every valid hit so an actively-used
        // session never times out. Pre-1.4.15 the transient had a fixed
        // HOUR_IN_SECONDS lifetime with no refresh, so Claude Desktop sessions
        // died exactly 60 minutes after creation regardless of activity, then
        // auto-reconnected and spawned competing mcp-remote npx processes.
        set_transient('royal_mcp_session_' . $session_id, $session_data, DAY_IN_SECONDS);

        return true;
    }

    /**
     * Store a new session
     *
     * @param string $session_id The session ID to store
     */
    private function store_session($session_id, $auth_fingerprint = '') {
        // Store session with 24-hour expiry, refreshed on every valid hit
        // (see is_valid_session). Bound to auth credentials.
        set_transient('royal_mcp_session_' . $session_id, [
            'created' => time(),
            'last_event_id' => 0,
            'auth_fingerprint' => $auth_fingerprint,
        ], DAY_IN_SECONDS);
    }

    /**
     * Delete a session
     *
     * @param string $session_id The session ID to delete
     */
    private function delete_session($session_id) {
        delete_transient('royal_mcp_session_' . $session_id);
    }

    private function get_tools() {
        $tools = [
            // Posts (supports custom post types)
            ['name' => 'wp_get_posts', 'description' => 'Get WordPress posts (supports custom post types)', 'inputSchema' => ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer', 'description' => 'Number of posts (max 100)'], 'search' => ['type' => 'string', 'description' => 'Search term'], 'status' => ['type' => 'string', 'description' => 'Post status (publish, draft, etc)'], 'post_type' => ['type' => 'string', 'description' => 'Post type slug (default: post). Use wp_get_post_types to discover available types']]]],
            ['name' => 'wp_get_post', 'description' => 'Get single post by ID (any post type)', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'Post ID']], 'required' => ['id']]],
            ['name' => 'wp_create_post', 'description' => 'Create new post (supports custom post types)', 'inputSchema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['publish', 'draft']], 'excerpt' => ['type' => 'string'], 'categories' => ['type' => 'array', 'items' => ['type' => 'integer']], 'post_type' => ['type' => 'string', 'description' => 'Post type slug (default: post)'], 'featured_media' => ['type' => 'integer', 'description' => 'Attachment ID to set as featured image'], 'post_author' => ['type' => 'integer', 'description' => 'User ID to assign as the post author. Defaults to the authenticated MCP user (admin). Use wp_get_users to discover available author IDs.']], 'required' => ['title', 'content']]],
            ['name' => 'wp_update_post', 'description' => 'Update existing post (any post type). Use featured_media to change the featured image by attachment ID, or use wp_set_featured_image for a URL-based workflow.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'status' => ['type' => 'string'], 'excerpt' => ['type' => 'string'], 'featured_media' => ['type' => 'integer', 'description' => 'Attachment ID to set as featured image (pass 0 to remove)'], 'post_author' => ['type' => 'integer', 'description' => 'User ID to reassign as the post author. Use wp_get_users to discover available author IDs.']], 'required' => ['id']]],
            ['name' => 'wp_get_post_types', 'description' => 'Get all registered public post types (including custom post types)', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_delete_post', 'description' => 'Delete post', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'force' => ['type' => 'boolean', 'description' => 'Skip trash and permanently delete']], 'required' => ['id']]],
            ['name' => 'wp_count_posts', 'description' => 'Get post counts by status', 'inputSchema' => ['type' => 'object', 'properties' => ['post_type' => ['type' => 'string', 'description' => 'Post type (post, page, etc)']]]],

            // Pages
            ['name' => 'wp_get_pages', 'description' => 'Get WordPress pages', 'inputSchema' => ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer'], 'parent' => ['type' => 'integer', 'description' => 'Parent page ID']]]],
            ['name' => 'wp_get_page', 'description' => 'Get single page by ID', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'Page ID']], 'required' => ['id']]],
            ['name' => 'wp_create_page', 'description' => 'Create new page', 'inputSchema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['publish', 'draft']], 'parent' => ['type' => 'integer', 'description' => 'Parent page ID']], 'required' => ['title', 'content']]],
            ['name' => 'wp_update_page', 'description' => 'Update existing page', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'status' => ['type' => 'string']], 'required' => ['id']]],
            ['name' => 'wp_delete_page', 'description' => 'Delete page', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'force' => ['type' => 'boolean']], 'required' => ['id']]],

            // Media
            ['name' => 'wp_get_media', 'description' => 'Get media library items', 'inputSchema' => ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer'], 'mime_type' => ['type' => 'string', 'description' => 'Filter by mime type (image, video, etc)']]]],
            ['name' => 'wp_get_media_item', 'description' => 'Get single media item by ID', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],
            ['name' => 'wp_upload_media_from_url', 'description' => 'Download an image from a public HTTPS URL and add it to the WordPress media library. Use this when you have an image URL (Unsplash, Pexels, client asset, etc) that needs to become a library attachment — for example before setting it as a featured image. Returns the new attachment ID.', 'inputSchema' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string', 'description' => 'Public HTTPS URL of the image to download'], 'filename' => ['type' => 'string', 'description' => 'Optional filename (with extension). Derived from URL if omitted.'], 'alt_text' => ['type' => 'string', 'description' => 'Alt text for accessibility and SEO'], 'caption' => ['type' => 'string'], 'title' => ['type' => 'string']], 'required' => ['url']]],
            ['name' => 'wp_upload_media', 'description' => 'Upload an image to the media library from base64-encoded bytes. Use this for AI-generated images or pasted screenshots where you have raw bytes rather than a URL. For images already hosted somewhere, prefer wp_upload_media_from_url.', 'inputSchema' => ['type' => 'object', 'properties' => ['filename' => ['type' => 'string', 'description' => 'Filename with extension (e.g. hero.jpg)'], 'content_base64' => ['type' => 'string', 'description' => 'Base64-encoded file bytes'], 'alt_text' => ['type' => 'string'], 'caption' => ['type' => 'string'], 'title' => ['type' => 'string']], 'required' => ['filename', 'content_base64']]],
            ['name' => 'wp_set_featured_image', 'description' => 'Set or replace the featured image on a post or page. Accepts EITHER an existing media_id from wp_get_media, OR an image_url that will be downloaded into the library first. Pass media_id=0 to remove the featured image.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer', 'description' => 'Post or page ID'], 'media_id' => ['type' => 'integer', 'description' => 'Existing attachment ID (use 0 to remove the featured image)'], 'image_url' => ['type' => 'string', 'description' => 'Public HTTPS image URL to download and use instead of media_id'], 'alt_text' => ['type' => 'string', 'description' => 'Alt text applied when image_url is provided']], 'required' => ['post_id']]],
            ['name' => 'wp_update_media', 'description' => 'Update metadata on an existing media attachment: alt text, caption, title, description. Great for adding SEO-friendly alt text to images already in the library.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'alt_text' => ['type' => 'string'], 'caption' => ['type' => 'string'], 'title' => ['type' => 'string'], 'description' => ['type' => 'string']], 'required' => ['id']]],
            ['name' => 'wp_delete_media', 'description' => 'Delete media item', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'force' => ['type' => 'boolean']], 'required' => ['id']]],
            ['name' => 'wp_count_media', 'description' => 'Get media counts by type', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],

            // Categories & Tags (Terms)
            ['name' => 'wp_get_categories', 'description' => 'Get all categories', 'inputSchema' => ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer']]]],
            ['name' => 'wp_get_tags', 'description' => 'Get all tags', 'inputSchema' => ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer']]]],
            ['name' => 'wp_create_term', 'description' => 'Create a term in any registered taxonomy (category, post_tag, or any custom taxonomy). Use wp_get_taxonomies to discover available taxonomy slugs.', 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug (e.g. category, post_tag, product_cat)'], 'description' => ['type' => 'string'], 'parent' => ['type' => 'integer', 'description' => 'Parent term ID (only applies to hierarchical taxonomies)'], 'slug' => ['type' => 'string', 'description' => 'Optional URL-friendly slug. Auto-generated from name if omitted.']], 'required' => ['name', 'taxonomy']]],
            ['name' => 'wp_update_term', 'description' => 'Update an existing term in any taxonomy. Use this to rename a tag/category, edit its description, or change its slug. Pair with wp_update_term_meta to edit SEO meta on tags (Yoast/Rank Math/AIOSEO store tag SEO data in wp_termmeta).', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug the term belongs to'], 'name' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'description' => ['type' => 'string'], 'parent' => ['type' => 'integer', 'description' => 'Parent term ID (hierarchical taxonomies only)']], 'required' => ['id', 'taxonomy']]],
            ['name' => 'wp_delete_term', 'description' => 'Delete a term from any registered taxonomy.', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug the term belongs to']], 'required' => ['id', 'taxonomy']]],
            ['name' => 'wp_add_post_terms', 'description' => 'Add or replace terms on a post in any taxonomy.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'terms' => ['type' => 'array', 'items' => ['type' => 'integer']], 'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug (e.g. category, post_tag, product_cat)']], 'required' => ['post_id', 'terms', 'taxonomy']]],
            ['name' => 'wp_count_terms', 'description' => 'Get term counts in a taxonomy', 'inputSchema' => ['type' => 'object', 'properties' => ['taxonomy' => ['type' => 'string']]]],
            ['name' => 'wp_get_taxonomies', 'description' => 'Get all registered public taxonomies (built-in plus custom taxonomies registered by themes/plugins like product_cat, brand, etc.). Returns the taxonomy slug, label, hierarchical flag, and which post types it applies to.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],

            // Term Meta (for SEO-plugin tag/category meta — Yoast, Rank Math, AIOSEO)
            ['name' => 'wp_get_term_meta', 'description' => 'Get term meta data. Useful for reading tag/category SEO meta stored by Yoast, Rank Math, or AIOSEO before editing it.', 'inputSchema' => ['type' => 'object', 'properties' => ['term_id' => ['type' => 'integer'], 'key' => ['type' => 'string', 'description' => 'Specific meta key. Omit to return all meta for the term.']], 'required' => ['term_id']]],
            ['name' => 'wp_update_term_meta', 'description' => 'Update term meta data. Common keys for SEO plugins: Yoast uses _yoast_wpseo_title / _yoast_wpseo_metadesc; Rank Math uses rank_math_title / rank_math_description; AIOSEO uses _aioseo_title / _aioseo_description.', 'inputSchema' => ['type' => 'object', 'properties' => ['term_id' => ['type' => 'integer'], 'key' => ['type' => 'string'], 'value' => ['type' => 'string']], 'required' => ['term_id', 'key', 'value']]],
            ['name' => 'wp_delete_term_meta', 'description' => 'Delete term meta data', 'inputSchema' => ['type' => 'object', 'properties' => ['term_id' => ['type' => 'integer'], 'key' => ['type' => 'string']], 'required' => ['term_id', 'key']]],

            // Comments
            ['name' => 'wp_get_comments', 'description' => 'Get comments', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'status' => ['type' => 'string']]]],
            ['name' => 'wp_create_comment', 'description' => 'Create a comment', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'content' => ['type' => 'string'], 'author' => ['type' => 'string'], 'author_email' => ['type' => 'string']], 'required' => ['post_id', 'content']]],
            ['name' => 'wp_delete_comment', 'description' => 'Delete a comment', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'force' => ['type' => 'boolean']], 'required' => ['id']]],
            ['name' => 'wp_get_pending_comments', 'description' => 'Get comments awaiting moderation (status=hold). Requires moderate_comments capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'limit' => ['type' => 'integer', 'description' => 'Max comments to return (default 20, max 100)']]]],
            ['name' => 'wp_approve_comment', 'description' => 'Approve a pending comment. Requires moderate_comments capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['comment_id' => ['type' => 'integer']], 'required' => ['comment_id']]],
            ['name' => 'wp_spam_comment', 'description' => 'Mark a comment as spam. Requires moderate_comments capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['comment_id' => ['type' => 'integer']], 'required' => ['comment_id']]],
            ['name' => 'wp_trash_comment', 'description' => 'Move a comment to trash. Requires moderate_comments capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['comment_id' => ['type' => 'integer']], 'required' => ['comment_id']]],

            // Users
            ['name' => 'wp_get_users', 'description' => 'Get users list', 'inputSchema' => ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer'], 'role' => ['type' => 'string']]]],
            ['name' => 'wp_get_user', 'description' => 'Get user by ID', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],

            // Post Meta
            ['name' => 'wp_get_post_meta', 'description' => 'Get post meta data', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'key' => ['type' => 'string']], 'required' => ['post_id']]],
            ['name' => 'wp_update_post_meta', 'description' => 'Update post meta data', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'key' => ['type' => 'string'], 'value' => ['type' => 'string']], 'required' => ['post_id', 'key', 'value']]],
            ['name' => 'wp_delete_post_meta', 'description' => 'Delete post meta data', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'key' => ['type' => 'string']], 'required' => ['post_id', 'key']]],

            // Site & Search
            ['name' => 'wp_get_site_info', 'description' => 'Get site information', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_search', 'description' => 'Search all content', 'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string'], 'post_type' => ['type' => 'string']], 'required' => ['query']]],

            // Options
            ['name' => 'wp_get_option', 'description' => 'Get a single WordPress option value (allowlisted, sensitive keys redacted)', 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']]],
            ['name' => 'wp_get_plugin_settings', 'description' => 'Get all options stored by a plugin, looked up by slug. Sensitive keys (api keys, secrets, tokens, passwords) are redacted before return.', 'inputSchema' => ['type' => 'object', 'properties' => ['plugin_slug' => ['type' => 'string', 'description' => 'Plugin slug, e.g. royalcomply or royal-affiliate-pro']], 'required' => ['plugin_slug']]],
            ['name' => 'wp_update_option', 'description' => 'Update a WordPress option. Requires the "Allow AI to write WordPress options" admin toggle to be enabled, and the option name must be in the allowlist (extend via the royal_mcp_writable_options filter). Sensitive option names are permanently denylisted.', 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'value' => ['description' => 'New value (any JSON type). Full overwrite — read first, merge in your client, then write back.']], 'required' => ['name', 'value']]],

            // Menus
            ['name' => 'wp_get_menus', 'description' => 'Get navigation menus', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_get_menu_items', 'description' => 'Get menu items', 'inputSchema' => ['type' => 'object', 'properties' => ['menu_id' => ['type' => 'integer']], 'required' => ['menu_id']]],
            ['name' => 'wp_create_menu_item', 'description' => 'Create a menu item in a navigation menu. Requires edit_theme_options capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['menu_id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'url' => ['type' => 'string', 'description' => 'External URL (leave empty if linking to a post/page via object_id)'], 'object_id' => ['type' => 'integer', 'description' => 'WordPress object ID (post, page, or term)'], 'object_type' => ['type' => 'string', 'enum' => ['post', 'page', 'category', 'custom'], 'description' => 'Type of object being linked (default: custom)'], 'parent_id' => ['type' => 'integer', 'description' => 'Parent menu item ID for nested items (0 = top level)'], 'position' => ['type' => 'integer', 'description' => 'Position in menu order (default: end)'], 'target' => ['type' => 'string', 'enum' => ['_self', '_blank'], 'description' => 'Link target']], 'required' => ['menu_id', 'title']]],
            ['name' => 'wp_update_menu_item', 'description' => 'Update an existing menu item. Requires edit_theme_options capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['menu_item_id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'url' => ['type' => 'string'], 'parent_id' => ['type' => 'integer'], 'position' => ['type' => 'integer'], 'target' => ['type' => 'string', 'enum' => ['_self', '_blank']]], 'required' => ['menu_item_id']]],
            ['name' => 'wp_delete_menu_item', 'description' => 'Delete a menu item. Requires edit_theme_options capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['menu_item_id' => ['type' => 'integer']], 'required' => ['menu_item_id']]],
            ['name' => 'wp_reorder_menu_items', 'description' => 'Reorder menu items by passing an array of menu_item_ids in the desired order. Requires edit_theme_options capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['menu_id' => ['type' => 'integer'], 'item_order' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Array of menu_item_ids in the desired order']], 'required' => ['menu_id', 'item_order']]],

            // Plugins & Themes
            ['name' => 'wp_get_plugins', 'description' => 'Get installed plugins', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_get_themes', 'description' => 'Get installed themes', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],

            // Theme & Appearance
            ['name' => 'wp_get_active_theme', 'description' => 'Get the active theme with name, version, parent (if child theme), and screenshot URL', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_get_theme_mods', 'description' => 'Get all customizer settings (theme_mods) for the active theme', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_update_theme_mod', 'description' => 'Update a single theme customizer setting. Requires the "Allow AI to modify theme appearance" admin toggle AND the mod name must be in the allowlist (extend via the royal_mcp_writable_theme_mods filter).', 'inputSchema' => ['type' => 'object', 'properties' => ['mod_name' => ['type' => 'string'], 'value' => ['description' => 'New value (any JSON type compatible with set_theme_mod)']], 'required' => ['mod_name', 'value']]],
            ['name' => 'wp_get_custom_css', 'description' => 'Get the active theme\'s custom CSS', 'inputSchema' => ['type' => 'object', 'properties' => ['theme_slug' => ['type' => 'string', 'description' => 'Theme slug (defaults to active theme)']]]],
            ['name' => 'wp_update_custom_css', 'description' => 'Update the active theme\'s custom CSS. CSS is filtered through wp_kses (script tags stripped). Requires the "Allow AI to modify theme appearance" admin toggle and unfiltered_html capability.', 'inputSchema' => ['type' => 'object', 'properties' => ['css' => ['type' => 'string'], 'theme_slug' => ['type' => 'string', 'description' => 'Theme slug (defaults to active theme)']], 'required' => ['css']]],

            // SEO Meta (auto-detects Yoast SEO or Rank Math)
            ['name' => 'wp_get_seo_meta', 'description' => 'Get the SEO meta fields for a post (title, description, focus keyword, robots, OG/Twitter overrides). Auto-detects Yoast SEO or Rank Math — returns the active plugin\'s fields. Returns empty values if neither is installed.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer']], 'required' => ['post_id']]],
            ['name' => 'wp_update_seo_meta', 'description' => 'Update SEO meta fields on a post. Auto-routes to Yoast or Rank Math based on which is active. Requires edit_post capability on the target post.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'title' => ['type' => 'string', 'description' => 'SEO title (replaces the meta title used in browser tabs and SERPs)'], 'description' => ['type' => 'string', 'description' => 'SEO meta description (used in SERPs)'], 'focus_keyword' => ['type' => 'string', 'description' => 'Primary focus keyword for SEO scoring'], 'noindex' => ['type' => 'boolean', 'description' => 'Tell search engines not to index this URL'], 'og_title' => ['type' => 'string', 'description' => 'Open Graph title (Facebook / Slack / LinkedIn previews)'], 'og_description' => ['type' => 'string', 'description' => 'Open Graph description']], 'required' => ['post_id']]],

            // Permalink Structure
            ['name' => 'wp_get_permalink_structure', 'description' => 'Get the WordPress permalink structure (e.g. /%postname%/, /%year%/%monthnum%/%postname%/). Read-only.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_update_permalink_structure', 'description' => 'Update the WordPress permalink structure. Requires the "Allow AI to write WordPress options" admin toggle. Common values: /%postname%/, /%year%/%monthnum%/%postname%/, /%category%/%postname%/. Changing this rewrites every URL on the site — flushes rewrite rules automatically.', 'inputSchema' => ['type' => 'object', 'properties' => ['structure' => ['type' => 'string', 'description' => 'New permalink structure (e.g. /%postname%/)']], 'required' => ['structure']]],

            // Post Revisions
            ['name' => 'wp_get_post_revisions', 'description' => 'Get the revision history for a post — list of all saved revisions with author, date, and revision ID. Useful for "what changed?" or "revert to yesterday\'s version" workflows.', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'limit' => ['type' => 'integer', 'description' => 'Max revisions to return (default 20)']], 'required' => ['post_id']]],
            ['name' => 'wp_restore_revision', 'description' => 'Restore a post to a specific revision. The current post content becomes the previous revision (so it can still be reverted again). Requires edit_post capability on the parent post.', 'inputSchema' => ['type' => 'object', 'properties' => ['revision_id' => ['type' => 'integer']], 'required' => ['revision_id']]],
        ];

        // Conditionally add integration tools
        $tools = array_merge( $tools, WooIntegration::get_tools() );
        $tools = array_merge( $tools, GPIntegration::get_tools() );
        $tools = array_merge( $tools, SVIntegration::get_tools() );
        $tools = array_merge( $tools, RLIntegration::get_tools() );
        $tools = array_merge( $tools, FCIntegration::get_tools() );
        $tools = array_merge( $tools, RLinksIntegration::get_tools() );

        return $tools;
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
     * Handle GET - SSE stream for server-initiated messages
     *
     * Auth check goes FIRST per RFC 9728 (Protected Resource Metadata) —
     * unauthenticated GET must return 401 + WWW-Authenticate so RFC 9728-aware
     * clients (Claude.ai web, ChatGPT) can discover OAuth and start the flow.
     *
     * Authenticated GET then returns 405 since this server does not host SSE,
     * preserving the 1.4.12 fix that stopped mcp-remote retry storms.
     *
     * See _dev/MCP_ENDPOINT_BEHAVIOR_MATRIX.md before changing.
     */
    private function handle_get_stream($request) {
        $auth_check = $this->validate_auth($request);
        if ($auth_check !== true) {
            return $auth_check;
        }

        // Authenticated client — SSE not hosted here, return 405 so mcp-remote
        // falls back to POST-only mode instead of retrying.
        $response = new \WP_REST_Response([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32600,
                'message' => 'Server-sent events (SSE) are not supported. Use HTTP POST for all MCP communication.',
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

            // Verify session is bound to the same credentials.
            $session_data = get_transient('royal_mcp_session_' . $session_id);
            if (!empty($session_data['auth_fingerprint']) && !hash_equals($session_data['auth_fingerprint'], $auth_fingerprint)) {
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
     * Build a fingerprint from the request's auth credentials.
     * Used to bind sessions to the original authenticator.
     *
     * @param \WP_REST_Request $request The request object.
     * @return string SHA-256 hash of the credential.
     */
    private function build_auth_fingerprint($request) {
        $auth_header = $request->get_header('Authorization');
        if (!empty($auth_header) && stripos($auth_header, 'Bearer ') === 0) {
            return hash('sha256', 'bearer:' . substr($auth_header, 7));
        }

        $api_key = $request->get_header('X-Royal-MCP-API-Key');
        if (!empty($api_key)) {
            return hash('sha256', 'apikey:' . $api_key);
        }

        return '';
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
     * Cache-Control: no-store is mandatory on every MCP response. Pre-1.4.15 the
     * 1.4.13 fix that added no-store to OAuth endpoints (OAuth/Server.php)
     * never propagated here, so MCP responses were getting cached at edge
     * (Cloudflare, host-level fastcgi cache, intermediaries) — the same root
     * cause as the OAuth cache poisoning bug, just on a different endpoint.
     * Customer reproduction: bare GET /mcp returned a stale auth-error response
     * regardless of whether the Authorization / X-Royal-MCP-API-Key header was
     * present, until a query string busted the URL-keyed cache.
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

        try {
            $result = $this->execute_tool($name, $args);
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

    private function execute_tool($name, $args) {
        switch ($name) {
            // ==================== POSTS ====================
            case 'wp_get_posts':
                $query_args = [
                    'numberposts' => min(intval($args['per_page'] ?? 10), 100),
                    's' => sanitize_text_field($args['search'] ?? ''),
                ];
                if (!empty($args['post_type'])) {
                    $pt = sanitize_text_field($args['post_type']);
                    $pto = get_post_type_object($pt);
                    if (!$pto || !$pto->public) throw new \Exception('Invalid or non-public post type: ' . esc_html($pt));
                    $query_args['post_type'] = $pt;
                }
                if (!empty($args['status'])) $query_args['post_status'] = sanitize_text_field($args['status']);
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
                    ];
                }, $posts);

            case 'wp_get_post':
                $post = get_post(intval($args['id']));
                if (!$post) throw new \Exception('Post not found');
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
                if (!$pto || !$pto->public) throw new \Exception('Invalid or non-public post type: ' . esc_html($post_type));
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
                $post_data = [
                    'post_title' => sanitize_text_field($args['title']),
                    'post_content' => wp_kses_post($args['content']),
                    'post_status' => in_array($args['status'] ?? 'draft', ['publish', 'draft']) ? $args['status'] : 'draft',
                    'post_type' => $post_type,
                ];
                if (!empty($args['excerpt'])) $post_data['post_excerpt'] = sanitize_text_field($args['excerpt']);
                if (!empty($args['categories'])) $post_data['post_category'] = array_map('intval', $args['categories']);
                if (isset($args['post_author']) && intval($args['post_author']) > 0) {
                    $post_data['post_author'] = intval($args['post_author']);
                }
                $post_id = wp_insert_post($post_data);
                if (is_wp_error($post_id)) throw new \Exception(esc_html($post_id->get_error_message()));
                if (isset($args['featured_media'])) {
                    $this->apply_featured_media($post_id, intval($args['featured_media']));
                }
                return ['id' => $post_id, 'message' => ucfirst($post_type) . ' created successfully', 'url' => get_permalink($post_id)];

            case 'wp_update_post':
                $post_id = intval($args['id']);
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
                $data = ['ID' => $post_id];
                if (isset($args['title'])) $data['post_title'] = sanitize_text_field($args['title']);
                if (isset($args['content'])) $data['post_content'] = wp_kses_post($args['content']);
                if (isset($args['status'])) $data['post_status'] = sanitize_text_field($args['status']);
                if (isset($args['excerpt'])) $data['post_excerpt'] = sanitize_text_field($args['excerpt']);
                if (isset($args['post_author']) && intval($args['post_author']) > 0) {
                    $data['post_author'] = intval($args['post_author']);
                }
                $result = wp_update_post($data);
                if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
                if (isset($args['featured_media'])) {
                    $this->apply_featured_media($post_id, intval($args['featured_media']));
                }
                return ['id' => $post_id, 'message' => 'Post updated successfully'];

            case 'wp_delete_post':
                $force = !empty($args['force']);
                $result = wp_delete_post(intval($args['id']), $force);
                if (!$result) throw new \Exception('Failed to delete post');
                return ['message' => $force ? 'Post permanently deleted' : 'Post moved to trash'];

            case 'wp_count_posts':
                $type = sanitize_text_field($args['post_type'] ?? 'post');
                $counts = wp_count_posts($type);
                return (array) $counts;

            case 'wp_get_post_types':
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
                $taxonomies = get_taxonomies(['public' => true], 'objects');
                return array_values(array_map(function($tax) {
                    // 1.4.12 — `slug` added as a clearer alias for the taxonomy
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
                    ];
                }, $pages);

            case 'wp_get_page':
                $page = get_post(intval($args['id']));
                if (!$page || $page->post_type !== 'page') throw new \Exception('Page not found');
                return [
                    'id' => $page->ID,
                    'title' => $page->post_title,
                    'content' => $page->post_content,
                    'status' => $page->post_status,
                    'url' => get_permalink($page),
                    'parent' => $page->post_parent,
                ];

            case 'wp_create_page':
                $page_data = [
                    'post_title' => sanitize_text_field($args['title']),
                    'post_content' => wp_kses_post($args['content']),
                    'post_status' => in_array($args['status'] ?? 'draft', ['publish', 'draft']) ? $args['status'] : 'draft',
                    'post_type' => 'page',
                ];
                if (!empty($args['parent'])) $page_data['post_parent'] = intval($args['parent']);
                $page_id = wp_insert_post($page_data);
                if (is_wp_error($page_id)) throw new \Exception(esc_html($page_id->get_error_message()));
                return ['id' => $page_id, 'message' => 'Page created successfully', 'url' => get_permalink($page_id)];

            case 'wp_update_page':
                $data = ['ID' => intval($args['id'])];
                if (isset($args['title'])) $data['post_title'] = sanitize_text_field($args['title']);
                if (isset($args['content'])) $data['post_content'] = wp_kses_post($args['content']);
                if (isset($args['status'])) $data['post_status'] = sanitize_text_field($args['status']);
                $result = wp_update_post($data);
                if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
                return ['id' => $args['id'], 'message' => 'Page updated successfully'];

            case 'wp_delete_page':
                $force = !empty($args['force']);
                $result = wp_delete_post(intval($args['id']), $force);
                if (!$result) throw new \Exception('Failed to delete page');
                return ['message' => $force ? 'Page permanently deleted' : 'Page moved to trash'];

            // ==================== MEDIA ====================
            case 'wp_get_media':
                $media_args = [
                    'post_type' => 'attachment',
                    'numberposts' => min(intval($args['per_page'] ?? 10), 100),
                    'post_status' => 'inherit',
                ];
                if (!empty($args['mime_type'])) $media_args['post_mime_type'] = sanitize_text_field($args['mime_type']);
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
                $media = get_post(intval($args['id']));
                if (!$media || $media->post_type !== 'attachment') throw new \Exception('Media not found');
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
                    isset($args['title']) ? sanitize_text_field($args['title']) : '',
                    isset($args['caption']) ? sanitize_text_field($args['caption']) : '',
                    isset($args['alt_text']) ? sanitize_text_field($args['alt_text']) : ''
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
                    isset($args['title']) ? sanitize_text_field($args['title']) : '',
                    isset($args['caption']) ? sanitize_text_field($args['caption']) : '',
                    isset($args['alt_text']) ? sanitize_text_field($args['alt_text']) : ''
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
                        isset($args['alt_text']) ? sanitize_text_field($args['alt_text']) : ''
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
                $media_id = intval($args['id'] ?? 0);
                $media = $media_id > 0 ? get_post($media_id) : null;
                if (!$media || $media->post_type !== 'attachment') throw new \Exception('Media not found.');
                if (!current_user_can('edit_post', $media_id)) {
                    throw new \Exception('You do not have permission to edit this media item.');
                }
                $update = ['ID' => $media_id];
                if (isset($args['title']))       $update['post_title']   = sanitize_text_field($args['title']);
                if (isset($args['caption']))     $update['post_excerpt'] = sanitize_text_field($args['caption']);
                if (isset($args['description'])) $update['post_content'] = wp_kses_post($args['description']);
                if (count($update) > 1) {
                    $res = wp_update_post($update, true);
                    if (is_wp_error($res)) throw new \Exception(esc_html($res->get_error_message()));
                }
                if (isset($args['alt_text'])) {
                    update_post_meta($media_id, '_wp_attachment_image_alt', sanitize_text_field($args['alt_text']));
                }
                return ['id' => $media_id, 'message' => 'Media updated successfully'];

            case 'wp_delete_media':
                $force = !empty($args['force']);
                $result = wp_delete_attachment(intval($args['id']), $force);
                if (!$result) throw new \Exception('Failed to delete media');
                return ['message' => 'Media deleted successfully'];

            case 'wp_count_media':
                $counts = wp_count_attachments();
                return (array) $counts;

            // ==================== CATEGORIES & TAGS ====================
            case 'wp_get_categories':
                $cats = get_categories(['number' => min(intval($args['per_page'] ?? 100), 100), 'hide_empty' => false]);
                return array_map(function($c) {
                    return ['id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug, 'count' => $c->count, 'parent' => $c->parent];
                }, $cats);

            case 'wp_get_tags':
                $tags = get_tags(['number' => min(intval($args['per_page'] ?? 100), 100), 'hide_empty' => false]);
                return array_map(function($t) {
                    return ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count];
                }, $tags ?: []);

            case 'wp_create_term':
                $taxonomy = sanitize_text_field($args['taxonomy']);
                if (!taxonomy_exists($taxonomy)) throw new \Exception('Unknown taxonomy: ' . esc_html($taxonomy) . '. Use wp_get_taxonomies to list available taxonomies.');
                $tax_obj = get_taxonomy($taxonomy);
                $term_args = [];
                if (!empty($args['description'])) $term_args['description'] = sanitize_text_field($args['description']);
                if (!empty($args['slug'])) $term_args['slug'] = sanitize_title($args['slug']);
                if (!empty($args['parent']) && $tax_obj && $tax_obj->hierarchical) $term_args['parent'] = intval($args['parent']);
                $result = wp_insert_term(sanitize_text_field($args['name']), $taxonomy, $term_args);
                if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
                return ['id' => $result['term_id'], 'taxonomy' => $taxonomy, 'message' => 'Term created successfully'];

            case 'wp_update_term':
                $taxonomy = sanitize_text_field($args['taxonomy']);
                if (!taxonomy_exists($taxonomy)) throw new \Exception('Unknown taxonomy: ' . esc_html($taxonomy) . '. Use wp_get_taxonomies to list available taxonomies.');
                $term_id = intval($args['id']);
                if (!get_term($term_id, $taxonomy)) throw new \Exception('Term not found in taxonomy ' . esc_html($taxonomy));
                $update_args = [];
                if (isset($args['name'])) $update_args['name'] = sanitize_text_field($args['name']);
                if (isset($args['slug'])) $update_args['slug'] = sanitize_title($args['slug']);
                if (isset($args['description'])) $update_args['description'] = sanitize_text_field($args['description']);
                if (isset($args['parent'])) {
                    $tax_obj = get_taxonomy($taxonomy);
                    if ($tax_obj && $tax_obj->hierarchical) $update_args['parent'] = intval($args['parent']);
                }
                if (empty($update_args)) throw new \Exception('No update fields provided. Pass at least one of: name, slug, description, parent.');
                $result = wp_update_term($term_id, $taxonomy, $update_args);
                if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
                return ['id' => $term_id, 'taxonomy' => $taxonomy, 'message' => 'Term updated successfully'];

            case 'wp_delete_term':
                $taxonomy = sanitize_text_field($args['taxonomy']);
                if (!taxonomy_exists($taxonomy)) throw new \Exception('Unknown taxonomy: ' . esc_html($taxonomy) . '. Use wp_get_taxonomies to list available taxonomies.');
                $result = wp_delete_term(intval($args['id']), $taxonomy);
                if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
                if (!$result) throw new \Exception('Failed to delete term');
                return ['message' => 'Term deleted successfully'];

            case 'wp_add_post_terms':
                $taxonomy = sanitize_text_field($args['taxonomy']);
                if (!taxonomy_exists($taxonomy)) throw new \Exception('Unknown taxonomy: ' . esc_html($taxonomy) . '. Use wp_get_taxonomies to list available taxonomies.');
                $result = wp_set_post_terms(intval($args['post_id']), array_map('intval', $args['terms']), $taxonomy, true);
                if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
                return ['message' => 'Terms added to post successfully'];

            case 'wp_count_terms':
                $taxonomy = sanitize_text_field($args['taxonomy'] ?? 'category');
                $count = wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
                return ['taxonomy' => $taxonomy, 'count' => $count];

            case 'wp_get_term_meta':
                $term_id = intval($args['term_id']);
                if (!get_term($term_id)) throw new \Exception('Term not found');
                // 1.4.12 — wrap return in a structured object for consistency
                // with wp_update_term_meta / wp_delete_term_meta which return
                // structured arrays. Single-key get returns {term_id, key,
                // value}; full-meta get returns {term_id, meta: {...}}.
                if (!empty($args['key'])) {
                    $key = sanitize_text_field($args['key']);
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
                $meta_value = is_string($args['value']) ? sanitize_textarea_field($args['value']) : $args['value'];
                $result = update_term_meta($term_id, sanitize_text_field($args['key']), $meta_value);
                if ($result === false) throw new \Exception('Failed to update term meta');
                return ['term_id' => $term_id, 'key' => sanitize_text_field($args['key']), 'message' => 'Term meta updated'];

            case 'wp_delete_term_meta':
                $term_id = intval($args['term_id']);
                if (!get_term($term_id)) throw new \Exception('Term not found');
                $result = delete_term_meta($term_id, sanitize_text_field($args['key']));
                if (!$result) throw new \Exception('Failed to delete term meta (key may not exist)');
                return ['term_id' => $term_id, 'message' => 'Term meta deleted'];

            // ==================== COMMENTS ====================
            case 'wp_get_comments':
                $comment_args = ['number' => min(intval($args['per_page'] ?? 10), 100)];
                if (!empty($args['post_id'])) $comment_args['post_id'] = intval($args['post_id']);
                if (!empty($args['status'])) $comment_args['status'] = sanitize_text_field($args['status']);
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
                $comment_data = [
                    'comment_post_ID' => intval($args['post_id']),
                    'comment_content' => sanitize_text_field($args['content']),
                    'comment_author' => sanitize_text_field($args['author'] ?? 'Anonymous'),
                    'comment_author_email' => sanitize_email($args['author_email'] ?? ''),
                ];
                // Respect WordPress comment moderation settings
                $comment_data['comment_approved'] = wp_allow_comment($comment_data);
                $comment_id = wp_insert_comment($comment_data);
                if (!$comment_id) throw new \Exception('Failed to create comment');
                $status = $comment_data['comment_approved'] === 1 ? 'approved' : 'pending moderation';
                return ['id' => $comment_id, 'message' => 'Comment created (' . $status . ')'];

            case 'wp_delete_comment':
                $force = !empty($args['force']);
                $result = wp_delete_comment(intval($args['id']), $force);
                if (!$result) throw new \Exception('Failed to delete comment');
                return ['message' => 'Comment deleted successfully'];

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
                if (!current_user_can('moderate_comments')) {
                    throw new \Exception('moderate_comments capability required.');
                }
                $comment_id = intval($args['comment_id']);
                $result = wp_set_comment_status($comment_id, 'approve');
                if (!$result) throw new \Exception('Failed to approve comment.');
                return ['comment_id' => $comment_id, 'new_status' => 'approved'];

            case 'wp_spam_comment':
                if (!current_user_can('moderate_comments')) {
                    throw new \Exception('moderate_comments capability required.');
                }
                $comment_id = intval($args['comment_id']);
                $result = wp_set_comment_status($comment_id, 'spam');
                if (!$result) throw new \Exception('Failed to mark comment as spam.');
                return ['comment_id' => $comment_id, 'new_status' => 'spam'];

            case 'wp_trash_comment':
                if (!current_user_can('moderate_comments')) {
                    throw new \Exception('moderate_comments capability required.');
                }
                $comment_id = intval($args['comment_id']);
                $result = wp_trash_comment($comment_id);
                if (!$result) throw new \Exception('Failed to trash comment.');
                return ['comment_id' => $comment_id, 'new_status' => 'trash'];

            // ==================== USERS ====================
            case 'wp_get_users':
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
                $post_id = intval($args['post_id']);
                if (!empty($args['key'])) {
                    $value = get_post_meta($post_id, sanitize_text_field($args['key']), true);
                    return ['key' => $args['key'], 'value' => $value];
                }
                return get_post_meta($post_id);

            case 'wp_update_post_meta':
                $meta_value = $args['value'];
                if (is_string($meta_value)) {
                    $meta_value = sanitize_text_field($meta_value);
                } elseif (is_array($meta_value)) {
                    $meta_value = array_map('sanitize_text_field', $meta_value);
                }
                $result = update_post_meta(intval($args['post_id']), sanitize_text_field($args['key']), $meta_value);
                return ['message' => 'Post meta updated successfully', 'result' => $result];

            case 'wp_delete_post_meta':
                $result = delete_post_meta(intval($args['post_id']), sanitize_text_field($args['key']));
                if (!$result) throw new \Exception('Failed to delete post meta');
                return ['message' => 'Post meta deleted successfully'];

            // ==================== SITE & SEARCH ====================
            case 'wp_get_site_info':
                return [
                    'name' => get_bloginfo('name'),
                    'description' => get_bloginfo('description'),
                    'url' => home_url(),
                    'language' => get_locale(),
                    'timezone' => wp_timezone_string(),
                    'wp_version' => get_bloginfo('version'),
                ];

            case 'wp_search':
                $search_args = [
                    's' => sanitize_text_field($args['query']),
                    'post_type' => !empty($args['post_type']) ? sanitize_text_field($args['post_type']) : 'any',
                    'numberposts' => 20,
                ];
                $posts = get_posts($search_args);
                return array_map(function($p) {
                    return ['id' => $p->ID, 'title' => $p->post_title, 'type' => $p->post_type, 'url' => get_permalink($p)];
                }, $posts);

            // ==================== OPTIONS ====================
            case 'wp_get_option':
                $allowed = ['blogname', 'blogdescription', 'siteurl', 'home', 'admin_email', 'posts_per_page', 'date_format', 'time_format', 'timezone_string'];
                $name = sanitize_text_field($args['name']);
                if (!in_array($name, $allowed)) throw new \Exception('Option not allowed: ' . esc_html($name));
                return ['name' => $name, 'value' => $this->redact_sensitive_keys(get_option($name))];

            case 'wp_get_plugin_settings':
                $slug = sanitize_text_field($args['plugin_slug'] ?? '');
                if (empty($slug)) throw new \Exception('plugin_slug is required.');
                return [
                    'slug'    => $slug,
                    'options' => $this->find_plugin_options($slug),
                ];

            case 'wp_update_option':
                $name = sanitize_text_field($args['name'] ?? '');
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

                // Gate 3: allowlist
                $default_writable = ['blogname', 'blogdescription', 'posts_per_page', 'date_format', 'time_format'];
                $writable = apply_filters('royal_mcp_writable_options', $default_writable);
                if (!is_array($writable)) $writable = $default_writable;
                if (!in_array($name, $writable, true)) {
                    throw new \Exception('Option not in allowlist: ' . esc_html($name) . '. Plugin authors can opt their settings in via add_filter("royal_mcp_writable_options", ...).');
                }

                // Value is intentionally accepted as-is (any JSON type). update_option will serialize.
                $value = $args['value'] ?? null;
                $previous = get_option($name);
                $result = update_option($name, $value);
                return [
                    'name'     => $name,
                    'updated'  => (bool) $result,
                    'previous' => $this->redact_sensitive_keys($previous),
                ];

            // ==================== MENUS ====================
            case 'wp_get_menus':
                $menus = wp_get_nav_menus();
                return array_map(function($m) {
                    return ['id' => $m->term_id, 'name' => $m->name, 'slug' => $m->slug];
                }, $menus);

            case 'wp_get_menu_items':
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
                    'menu-item-title'     => sanitize_text_field($args['title']),
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
                $item_id = intval($args['menu_item_id']);
                $existing = get_post($item_id);
                if (!$existing || $existing->post_type !== 'nav_menu_item') {
                    throw new \Exception('Menu item not found.');
                }
                $menus = wp_get_post_terms($item_id, 'nav_menu', ['fields' => 'ids']);
                $menu_id = (!empty($menus) && !is_wp_error($menus)) ? (int) $menus[0] : 0;
                $update_args = ['menu-item-status' => 'publish'];
                if (isset($args['title']))     $update_args['menu-item-title']     = sanitize_text_field($args['title']);
                if (isset($args['url']))       $update_args['menu-item-url']       = esc_url_raw($args['url']);
                if (isset($args['parent_id'])) $update_args['menu-item-parent-id'] = intval($args['parent_id']);
                if (isset($args['position']))  $update_args['menu-item-position']  = intval($args['position']);
                if (isset($args['target']))    $update_args['menu-item-target']    = sanitize_text_field($args['target']);
                $result = wp_update_nav_menu_item($menu_id, $item_id, $update_args);
                if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
                return ['menu_item_id' => $item_id, 'menu_id' => $menu_id];

            case 'wp_delete_menu_item':
                if (!current_user_can('edit_theme_options')) {
                    throw new \Exception('edit_theme_options capability required.');
                }
                $item_id = intval($args['menu_item_id']);
                $existing = get_post($item_id);
                if (!$existing || $existing->post_type !== 'nav_menu_item') {
                    throw new \Exception('Menu item not found.');
                }
                $result = wp_delete_post($item_id, true);
                if (!$result) throw new \Exception('Failed to delete menu item.');
                return ['success' => true, 'menu_item_id' => $item_id];

            case 'wp_reorder_menu_items':
                if (!current_user_can('edit_theme_options')) {
                    throw new \Exception('edit_theme_options capability required.');
                }
                $menu_id = intval($args['menu_id']);
                if (!wp_get_nav_menu_object($menu_id)) {
                    throw new \Exception('Menu not found.');
                }
                $order = $args['item_order'] ?? [];
                if (!is_array($order)) throw new \Exception('item_order must be an array of menu_item_ids.');
                $position = 1;
                foreach ($order as $iid) {
                    $iid = intval($iid);
                    if ($iid <= 0) continue;
                    wp_update_nav_menu_item($menu_id, $iid, [
                        'menu-item-position' => $position,
                        'menu-item-status'   => 'publish',
                    ]);
                    $position++;
                }
                return ['success' => true, 'menu_id' => $menu_id, 'count' => $position - 1];

            // ==================== PLUGINS & THEMES ====================
            case 'wp_get_plugins':
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
                $mods = get_theme_mods();
                return is_array($mods) ? $mods : [];

            case 'wp_update_theme_mod':
                $mod_name = sanitize_text_field($args['mod_name'] ?? '');
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

                $previous = get_theme_mod($mod_name);
                $value = $args['value'] ?? null;
                set_theme_mod($mod_name, $value);
                return [
                    'mod_name'       => $mod_name,
                    'previous_value' => $previous,
                    'new_value'      => get_theme_mod($mod_name),
                ];

            case 'wp_get_custom_css':
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
                $css = $args['css'] ?? '';
                if (!is_string($css)) throw new \Exception('css must be a string.');
                $theme_slug = isset($args['theme_slug']) ? sanitize_key($args['theme_slug']) : get_stylesheet();
                $result = wp_update_custom_css_post($css, ['stylesheet' => $theme_slug]);
                if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
                return [
                    'success'    => true,
                    'post_id'    => (int) $result->ID,
                    'theme_slug' => $theme_slug,
                    'byte_count' => strlen($css),
                ];

            // ==================== SEO META (Yoast / Rank Math auto-detect) ====================
            case 'wp_get_seo_meta':
                $post_id = intval($args['post_id'] ?? 0);
                if ($post_id <= 0) throw new \Exception('post_id is required.');
                if (!get_post($post_id)) throw new \Exception('Post not found: ' . esc_html((string) $post_id));
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
                    ];
                }
                if ($detected === 'rankmath') {
                    return [
                        'plugin'         => 'rankmath',
                        'post_id'        => $post_id,
                        'title'          => (string) get_post_meta($post_id, 'rank_math_title', true),
                        'description'    => (string) get_post_meta($post_id, 'rank_math_description', true),
                        'focus_keyword'  => (string) get_post_meta($post_id, 'rank_math_focus_keyword', true),
                        'noindex'        => strpos((string) get_post_meta($post_id, 'rank_math_robots', true), 'noindex') !== false,
                        'og_title'       => (string) get_post_meta($post_id, 'rank_math_facebook_title', true),
                        'og_description' => (string) get_post_meta($post_id, 'rank_math_facebook_description', true),
                    ];
                }
                return [
                    'plugin'  => 'none',
                    'post_id' => $post_id,
                    'note'    => 'No SEO plugin (Yoast SEO or Rank Math) detected on this site.',
                ];

            case 'wp_update_seo_meta':
                $post_id = intval($args['post_id'] ?? 0);
                if ($post_id <= 0) throw new \Exception('post_id is required.');
                if (!get_post($post_id)) throw new \Exception('Post not found: ' . esc_html((string) $post_id));
                if (!current_user_can('edit_post', $post_id)) {
                    throw new \Exception('edit_post capability required for this post.');
                }
                $detected = $this->detect_seo_plugin();
                if ($detected === 'none') {
                    throw new \Exception('No SEO plugin (Yoast SEO or Rank Math) is active. Install one first.');
                }
                $field_map = $detected === 'yoast'
                    ? [
                        'title'          => '_yoast_wpseo_title',
                        'description'    => '_yoast_wpseo_metadesc',
                        'focus_keyword'  => '_yoast_wpseo_focuskw',
                        'og_title'       => '_yoast_wpseo_opengraph-title',
                        'og_description' => '_yoast_wpseo_opengraph-description',
                    ]
                    : [
                        'title'          => 'rank_math_title',
                        'description'    => 'rank_math_description',
                        'focus_keyword'  => 'rank_math_focus_keyword',
                        'og_title'       => 'rank_math_facebook_title',
                        'og_description' => 'rank_math_facebook_description',
                    ];
                $updated = [];
                foreach ($field_map as $arg_key => $meta_key) {
                    if (array_key_exists($arg_key, $args)) {
                        $value = sanitize_text_field((string) $args[$arg_key]);
                        update_post_meta($post_id, $meta_key, $value);
                        $updated[$arg_key] = $value;
                    }
                }
                if (array_key_exists('noindex', $args)) {
                    $noindex = (bool) $args['noindex'];
                    if ($detected === 'yoast') {
                        update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', $noindex ? '1' : '0');
                    } else {
                        $robots = (array) get_post_meta($post_id, 'rank_math_robots', true);
                        if (!is_array($robots)) $robots = [];
                        $robots = array_filter($robots, fn($r) => $r !== 'noindex' && $r !== 'index');
                        $robots[] = $noindex ? 'noindex' : 'index';
                        update_post_meta($post_id, 'rank_math_robots', array_values(array_unique($robots)));
                    }
                    $updated['noindex'] = $noindex;
                }
                return [
                    'plugin'  => $detected,
                    'post_id' => $post_id,
                    'updated' => $updated,
                ];

            // ==================== PERMALINK STRUCTURE ====================
            case 'wp_get_permalink_structure':
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
                $structure = isset($args['structure']) ? sanitize_text_field((string) $args['structure']) : '';
                if (empty($structure)) {
                    throw new \Exception('structure is required (e.g. /%postname%/)');
                }
                $previous = (string) get_option('permalink_structure', '');
                global $wp_rewrite;
                if ($wp_rewrite) {
                    $wp_rewrite->set_permalink_structure($structure);
                    $wp_rewrite->flush_rules();
                } else {
                    update_option('permalink_structure', $structure);
                }
                return [
                    'success'  => true,
                    'previous' => $previous,
                    'current'  => (string) get_option('permalink_structure', ''),
                ];

            // ==================== POST REVISIONS ====================
            case 'wp_get_post_revisions':
                $post_id = intval($args['post_id'] ?? 0);
                if ($post_id <= 0) throw new \Exception('post_id is required.');
                if (!get_post($post_id)) throw new \Exception('Post not found.');
                $limit = min(intval($args['limit'] ?? 20), 100);
                $revisions = wp_get_post_revisions($post_id, ['number' => $limit]);
                return array_map(function($r) {
                    return [
                        'revision_id'  => (int) $r->ID,
                        'parent_id'    => (int) $r->post_parent,
                        'author_id'    => (int) $r->post_author,
                        'author_name'  => get_the_author_meta('display_name', $r->post_author),
                        'date'         => $r->post_date,
                        'title'        => $r->post_title,
                        'word_count'   => str_word_count(wp_strip_all_tags((string) $r->post_content)),
                    ];
                }, array_values($revisions));

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
                throw new \Exception('Unknown tool: ' . esc_html($name));
        }
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
    private function is_denylisted_option($name) {
        $name_lc = strtolower($name);

        // Hard exact-match denylist (compared case-insensitively).
        $exact = [
            'siteurl', 'home', 'db_version', 'wp_user_roles', 'cron', 'rewrite_rules',
            'wplang', 'template', 'stylesheet', 'active_plugins',
            'royal_mcp_settings', // Self-protection: prevent AI from disabling its own gates.
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
