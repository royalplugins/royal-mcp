<?php
namespace Royal_MCP\MCP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCP Server - Streamable HTTP Transport (2025-03-26 spec)
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

        // Check transient for session validity
        $session_data = get_transient('royal_mcp_session_' . $session_id);
        return $session_data !== false;
    }

    /**
     * Store a new session
     *
     * @param string $session_id The session ID to store
     */
    private function store_session($session_id) {
        // Store session with 1 hour expiry
        set_transient('royal_mcp_session_' . $session_id, [
            'created' => time(),
            'last_event_id' => 0,
        ], HOUR_IN_SECONDS);
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
        return [
            // Posts
            ['name' => 'wp_get_posts', 'description' => 'Get WordPress posts of any post type', 'inputSchema' => ['type' => 'object', 'properties' => ['post_type' => ['type' => 'string', 'description' => 'Post type slug (default: post). Use wp_get_post_types to discover available types.'], 'per_page' => ['type' => 'integer', 'description' => 'Number of posts (max 100)'], 'search' => ['type' => 'string', 'description' => 'Search term'], 'status' => ['type' => 'string', 'description' => 'Post status (publish, draft, etc)']]]],
            ['name' => 'wp_get_post', 'description' => 'Get single post by ID', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'Post ID'], 'include_fields' => ['type' => 'boolean', 'description' => 'Include all custom fields (ACF + post meta) in the response (default false)']], 'required' => ['id']]],
            ['name' => 'wp_create_post', 'description' => 'Create a new post of any post type with optional custom fields and taxonomy terms', 'inputSchema' => ['type' => 'object', 'properties' => ['post_type' => ['type' => 'string', 'description' => 'Post type slug (default: post). Use wp_get_post_types to discover available types.'], 'title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['publish', 'draft']], 'excerpt' => ['type' => 'string'], 'categories' => ['type' => 'array', 'items' => ['type' => 'integer']], 'meta_fields' => ['type' => 'object', 'description' => 'Key-value map of custom fields / post meta to set (e.g. {"project_status": "active", "budget": 5000}). Use wp_get_cpt_fields to discover available fields.'], 'taxonomy_terms' => ['type' => 'object', 'description' => 'Map of taxonomy slug to array of term IDs to assign (e.g. {"project_category": [12, 34]}). Use wp_get_taxonomies to discover available taxonomies.']], 'required' => ['title', 'content']]],
            ['name' => 'wp_update_post', 'description' => 'Update existing post with optional custom fields and taxonomy terms', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'status' => ['type' => 'string'], 'excerpt' => ['type' => 'string'], 'meta_fields' => ['type' => 'object', 'description' => 'Key-value map of custom fields / post meta to update (e.g. {"project_status": "complete"})'], 'taxonomy_terms' => ['type' => 'object', 'description' => 'Map of taxonomy slug to array of term IDs to assign (replaces existing terms for that taxonomy)']], 'required' => ['id']]],
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
            ['name' => 'wp_delete_media', 'description' => 'Delete media item', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'force' => ['type' => 'boolean']], 'required' => ['id']]],
            ['name' => 'wp_count_media', 'description' => 'Get media counts by type', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],

            // Categories & Tags (Terms)
            ['name' => 'wp_get_categories', 'description' => 'Get all categories', 'inputSchema' => ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer']]]],
            ['name' => 'wp_get_tags', 'description' => 'Get all tags', 'inputSchema' => ['type' => 'object', 'properties' => ['per_page' => ['type' => 'integer']]]],
            ['name' => 'wp_get_terms', 'description' => 'Get terms for any taxonomy (use wp_get_taxonomies to discover available taxonomies)', 'inputSchema' => ['type' => 'object', 'properties' => ['taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug (e.g. category, post_tag, or any custom taxonomy)'], 'number' => ['type' => 'integer', 'description' => 'Number of terms to return (max 200, default 100)'], 'hide_empty' => ['type' => 'boolean', 'description' => 'Hide terms with no posts (default false)']], 'required' => ['taxonomy']]],
            ['name' => 'wp_create_term', 'description' => 'Create a term in any taxonomy (category, tag, or custom)', 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug (e.g. category, post_tag, or any custom taxonomy)'], 'description' => ['type' => 'string'], 'parent' => ['type' => 'integer']], 'required' => ['name', 'taxonomy']]],
            ['name' => 'wp_delete_term', 'description' => 'Delete a term from any taxonomy', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug']], 'required' => ['id', 'taxonomy']]],
            ['name' => 'wp_add_post_terms', 'description' => 'Add/set terms on a post for any taxonomy', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'terms' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Array of term IDs'], 'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug (e.g. category, post_tag, or any custom taxonomy)']], 'required' => ['post_id', 'terms', 'taxonomy']]],
            ['name' => 'wp_count_terms', 'description' => 'Get term counts', 'inputSchema' => ['type' => 'object', 'properties' => ['taxonomy' => ['type' => 'string']]]],

            // Custom Post Types & Taxonomies Discovery
            ['name' => 'wp_get_post_types', 'description' => 'List all registered custom post types', 'inputSchema' => ['type' => 'object', 'properties' => ['show_public_only' => ['type' => 'boolean', 'description' => 'Only return public post types (default true)'], 'include_built_in' => ['type' => 'boolean', 'description' => 'Include built-in types post/page/attachment (default false)']]]],
            ['name' => 'wp_get_taxonomies', 'description' => 'List all registered taxonomies, optionally filtered by post type', 'inputSchema' => ['type' => 'object', 'properties' => ['post_type' => ['type' => 'string', 'description' => 'Filter taxonomies by post type slug'], 'public_only' => ['type' => 'boolean', 'description' => 'Only return public taxonomies (default true)']]]],
            ['name' => 'wp_get_cpt_fields', 'description' => 'Get custom fields schema for a post type — returns ACF field groups and registered meta keys', 'inputSchema' => ['type' => 'object', 'properties' => ['post_type' => ['type' => 'string', 'description' => 'Post type slug']], 'required' => ['post_type']]],

            // Comments
            ['name' => 'wp_get_comments', 'description' => 'Get comments', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'status' => ['type' => 'string']]]],
            ['name' => 'wp_create_comment', 'description' => 'Create a comment', 'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => ['type' => 'integer'], 'content' => ['type' => 'string'], 'author' => ['type' => 'string'], 'author_email' => ['type' => 'string']], 'required' => ['post_id', 'content']]],
            ['name' => 'wp_delete_comment', 'description' => 'Delete a comment', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'force' => ['type' => 'boolean']], 'required' => ['id']]],

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
            ['name' => 'wp_get_option', 'description' => 'Get WordPress option value', 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']]],

            // Menus
            ['name' => 'wp_get_menus', 'description' => 'Get navigation menus', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_get_menu_items', 'description' => 'Get menu items', 'inputSchema' => ['type' => 'object', 'properties' => ['menu_id' => ['type' => 'integer']], 'required' => ['menu_id']]],

            // Plugins & Themes
            ['name' => 'wp_get_plugins', 'description' => 'Get installed plugins', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'wp_get_themes', 'description' => 'Get installed themes', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
        ];
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
        $response->header('Access-Control-Allow-Headers', 'Content-Type, Accept, Mcp-Session-Id');
        $response->header('Access-Control-Max-Age', '86400');
        return $response;
    }

    /**
     * Handle GET - SSE stream for server-initiated messages
     * Per MCP spec: Opens SSE stream for server notifications
     */
    private function handle_get_stream($request) {
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
        }

        // Process the method
        $result = $this->process_method($method, $params, $id);

        // For initialize, generate and return session ID
        if ($method === 'initialize' && $result && isset($result['result'])) {
            $new_session_id = $this->generate_session_id();
            // Store the session
            $this->store_session($new_session_id);
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
     * Generate cryptographically secure session ID
     */
    private function generate_session_id() {
        return bin2hex(random_bytes(16));
    }

    /**
     * Create JSON response with proper headers
     */
    private function json_response($data, $status = 200) {
        $response = new \WP_REST_Response($data, $status);
        $response->header('Content-Type', 'application/json');
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
                        'protocolVersion' => '2025-03-26',
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
                $post_type = !empty($args['post_type']) ? sanitize_key($args['post_type']) : 'post';
                if (!post_type_exists($post_type)) throw new \Exception('Post type does not exist: ' . esc_html($post_type));
                $query_args = [
                    'post_type' => $post_type,
                    'numberposts' => min(intval($args['per_page'] ?? 10), 100),
                    's' => sanitize_text_field($args['search'] ?? ''),
                ];
                if (!empty($args['status'])) $query_args['post_status'] = sanitize_text_field($args['status']);
                $posts = get_posts($query_args);
                return array_map(function($p) {
                    return [
                        'id' => $p->ID,
                        'title' => $p->post_title,
                        'post_type' => $p->post_type,
                        'excerpt' => wp_trim_words($p->post_content, 50),
                        'status' => $p->post_status,
                        'url' => get_permalink($p),
                        'date' => $p->post_date,
                    ];
                }, $posts);

            case 'wp_get_post':
                $post = get_post(intval($args['id']));
                if (!$post) throw new \Exception('Post not found');
                $result = [
                    'id' => $post->ID,
                    'post_type' => $post->post_type,
                    'title' => $post->post_title,
                    'content' => $post->post_content,
                    'excerpt' => $post->post_excerpt,
                    'status' => $post->post_status,
                    'url' => get_permalink($post),
                    'date' => $post->post_date,
                    'modified' => $post->post_modified,
                    'author' => get_the_author_meta('display_name', $post->post_author),
                ];
                if (!empty($args['include_fields'])) {
                    $result['meta'] = get_post_meta($post->ID);
                    $result['acf_fields'] = function_exists('get_fields') ? (\get_fields($post->ID) ?: []) : [];
                }
                return $result;

            case 'wp_create_post':
                $post_type = !empty($args['post_type']) ? sanitize_key($args['post_type']) : 'post';
                if (!post_type_exists($post_type)) throw new \Exception('Post type does not exist: ' . esc_html($post_type));
                $post_data = [
                    'post_title'   => sanitize_text_field($args['title']),
                    'post_content' => wp_kses_post($args['content'] ?? ''),
                    'post_status'  => in_array($args['status'] ?? 'draft', ['publish', 'draft']) ? $args['status'] : 'draft',
                    'post_type'    => $post_type,
                ];
                if (!empty($args['excerpt'])) $post_data['post_excerpt'] = sanitize_text_field($args['excerpt']);
                if (!empty($args['categories'])) $post_data['post_category'] = array_map('intval', $args['categories']);
                $post_id = wp_insert_post($post_data);
                if (is_wp_error($post_id)) throw new \Exception(esc_html($post_id->get_error_message()));
                if (!empty($args['meta_fields']) && is_array($args['meta_fields'])) {
                    foreach ($args['meta_fields'] as $meta_key => $meta_value) {
                        update_post_meta($post_id, sanitize_key($meta_key), $meta_value);
                    }
                }
                if (!empty($args['taxonomy_terms']) && is_array($args['taxonomy_terms'])) {
                    foreach ($args['taxonomy_terms'] as $tax => $terms) {
                        $tax = sanitize_key($tax);
                        if (taxonomy_exists($tax)) {
                            wp_set_object_terms($post_id, array_map('intval', (array) $terms), $tax);
                        }
                    }
                }
                return ['id' => $post_id, 'post_type' => $post_type, 'message' => 'Post created successfully', 'url' => get_permalink($post_id)];

            case 'wp_update_post':
                $post_id = intval($args['id']);
                if (!get_post($post_id)) throw new \Exception('Post not found');
                $data = ['ID' => $post_id];
                if (isset($args['title'])) $data['post_title'] = sanitize_text_field($args['title']);
                if (isset($args['content'])) $data['post_content'] = wp_kses_post($args['content']);
                if (isset($args['status'])) $data['post_status'] = sanitize_text_field($args['status']);
                if (isset($args['excerpt'])) $data['post_excerpt'] = sanitize_text_field($args['excerpt']);
                $result = wp_update_post($data);
                if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
                if (!empty($args['meta_fields']) && is_array($args['meta_fields'])) {
                    foreach ($args['meta_fields'] as $meta_key => $meta_value) {
                        update_post_meta($post_id, sanitize_key($meta_key), $meta_value);
                    }
                }
                if (!empty($args['taxonomy_terms']) && is_array($args['taxonomy_terms'])) {
                    foreach ($args['taxonomy_terms'] as $tax => $terms) {
                        $tax = sanitize_key($tax);
                        if (taxonomy_exists($tax)) {
                            wp_set_object_terms($post_id, array_map('intval', (array) $terms), $tax);
                        }
                    }
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

            case 'wp_get_terms':
                $taxonomy = sanitize_key($args['taxonomy']);
                if (!taxonomy_exists($taxonomy)) throw new \Exception('Taxonomy does not exist: ' . esc_html($taxonomy));
                $number = min(intval($args['number'] ?? 100), 200);
                $hide_empty = !empty($args['hide_empty']);
                $terms = get_terms(['taxonomy' => $taxonomy, 'number' => $number, 'hide_empty' => $hide_empty]);
                if (is_wp_error($terms)) throw new \Exception(esc_html($terms->get_error_message()));
                return array_map(function($t) {
                    return ['term_id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count, 'parent' => $t->parent];
                }, $terms);

            case 'wp_create_term':
                $taxonomy = sanitize_key($args['taxonomy']);
                if (!taxonomy_exists($taxonomy)) throw new \Exception('Taxonomy does not exist: ' . esc_html($taxonomy));
                $term_args = [];
                if (!empty($args['description'])) $term_args['description'] = sanitize_text_field($args['description']);
                if (!empty($args['parent'])) $term_args['parent'] = intval($args['parent']);
                $result = wp_insert_term(sanitize_text_field($args['name']), $taxonomy, $term_args);
                if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
                return ['id' => $result['term_id'], 'message' => 'Term created successfully in ' . esc_html($taxonomy)];

            case 'wp_delete_term':
                $taxonomy = sanitize_key($args['taxonomy']);
                if (!taxonomy_exists($taxonomy)) throw new \Exception('Taxonomy does not exist: ' . esc_html($taxonomy));
                $result = wp_delete_term(intval($args['id']), $taxonomy);
                if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
                if (!$result) throw new \Exception('Failed to delete term');
                return ['message' => 'Term deleted successfully'];

            case 'wp_add_post_terms':
                $taxonomy = sanitize_key($args['taxonomy']);
                if (!taxonomy_exists($taxonomy)) throw new \Exception('Taxonomy does not exist: ' . esc_html($taxonomy));
                $result = wp_set_post_terms(intval($args['post_id']), array_map('intval', $args['terms']), $taxonomy, true);
                if (is_wp_error($result)) throw new \Exception(esc_html($result->get_error_message()));
                return ['message' => 'Terms added to post successfully'];

            case 'wp_count_terms':
                $taxonomy = sanitize_text_field($args['taxonomy'] ?? 'category');
                $count = wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
                return ['taxonomy' => $taxonomy, 'count' => $count];

            // ==================== CUSTOM POST TYPES & TAXONOMIES ====================
            case 'wp_get_post_types':
                $show_public_only = ($args['show_public_only'] ?? true) !== false;
                $include_built_in = !empty($args['include_built_in']);
                $query_args = $show_public_only ? ['public' => true] : [];
                $post_types = get_post_types($query_args, 'objects');
                $built_in = ['post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_face', 'wp_font_family'];
                $result = [];
                foreach ($post_types as $pt) {
                    if (!$include_built_in && in_array($pt->name, $built_in, true)) continue;
                    $result[] = [
                        'name' => $pt->name,
                        'label' => $pt->label,
                        'singular_label' => $pt->labels->singular_name ?? $pt->label,
                        'description' => $pt->description,
                        'supports' => get_all_post_type_supports($pt->name),
                        'taxonomies' => get_object_taxonomies($pt->name),
                    ];
                }
                return $result;

            case 'wp_get_taxonomies':
                $public_only = ($args['public_only'] ?? true) !== false;
                $query_args = $public_only ? ['public' => true] : [];
                $taxonomies = get_taxonomies($query_args, 'objects');
                $post_type_filter = !empty($args['post_type']) ? sanitize_key($args['post_type']) : null;
                $result = [];
                foreach ($taxonomies as $tax) {
                    if ($post_type_filter && !in_array($post_type_filter, $tax->object_type, true)) continue;
                    $result[] = [
                        'name' => $tax->name,
                        'label' => $tax->label,
                        'singular_label' => $tax->labels->singular_name ?? $tax->label,
                        'hierarchical' => $tax->hierarchical,
                        'post_types' => $tax->object_type,
                    ];
                }
                return $result;

            case 'wp_get_cpt_fields':
                $post_type = sanitize_key($args['post_type']);
                if (!post_type_exists($post_type)) throw new \Exception('Post type does not exist: ' . esc_html($post_type));
                $acf_fields = [];
                $meta_keys = [];
                // ACF fields
                if (function_exists('acf_get_field_groups')) {
                    $groups = \acf_get_field_groups(['post_type' => $post_type]);
                    foreach ($groups as $group) {
                        $fields = \acf_get_fields($group['key']);
                        if (!$fields) continue;
                        foreach ($fields as $field) {
                            $field_data = [
                                'name' => $field['name'],
                                'label' => $field['label'],
                                'type' => $field['type'],
                                'required' => !empty($field['required']),
                                'instructions' => $field['instructions'] ?? '',
                            ];
                            if (!empty($field['choices'])) $field_data['choices'] = $field['choices'];
                            $acf_fields[] = $field_data;
                        }
                    }
                }
                // Registered meta keys
                $registered = get_registered_meta_keys('post', $post_type);
                foreach ($registered as $key => $schema) {
                    $meta_keys[] = [
                        'key' => $key,
                        'type' => $schema['type'] ?? 'string',
                        'description' => $schema['description'] ?? '',
                    ];
                }
                return ['post_type' => $post_type, 'acf_fields' => $acf_fields, 'meta_keys' => $meta_keys];

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
                    'comment_approved' => 1,
                ];
                $comment_id = wp_insert_comment($comment_data);
                if (!$comment_id) throw new \Exception('Failed to create comment');
                return ['id' => $comment_id, 'message' => 'Comment created successfully'];

            case 'wp_delete_comment':
                $force = !empty($args['force']);
                $result = wp_delete_comment(intval($args['id']), $force);
                if (!$result) throw new \Exception('Failed to delete comment');
                return ['message' => 'Comment deleted successfully'];

            // ==================== USERS ====================
            case 'wp_get_users':
                $user_args = ['number' => min(intval($args['per_page'] ?? 10), 100)];
                if (!empty($args['role'])) $user_args['role'] = sanitize_text_field($args['role']);
                $users = get_users($user_args);
                return array_map(function($u) {
                    return [
                        'id' => $u->ID,
                        'username' => $u->user_login,
                        'email' => $u->user_email,
                        'display_name' => $u->display_name,
                        'roles' => $u->roles,
                    ];
                }, $users);

            case 'wp_get_user':
                $user = get_user_by('ID', intval($args['id']));
                if (!$user) throw new \Exception('User not found');
                return [
                    'id' => $user->ID,
                    'username' => $user->user_login,
                    'email' => $user->user_email,
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
                $result = update_post_meta(intval($args['post_id']), sanitize_text_field($args['key']), $args['value']);
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
                    'admin_email' => get_bloginfo('admin_email'),
                    'language' => get_locale(),
                    'timezone' => wp_timezone_string(),
                    'wp_version' => get_bloginfo('version'),
                    'php_version' => phpversion(),
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
                return ['name' => $name, 'value' => get_option($name)];

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

            default:
                throw new \Exception('Unknown tool: ' . esc_html($name));
        }
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
            'spec' => '2025-03-26'
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
