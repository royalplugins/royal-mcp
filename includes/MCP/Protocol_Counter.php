<?php
/**
 * Protocol_Counter — per-site, per-week rollup of inbound MCP request signals.
 *
 * Hooked onto rest_pre_dispatch for the /royal-mcp/v1/mcp namespace. Every
 * incoming JSON-RPC request contributes to a single wp_options row keyed by
 * ISO year-week (royal_mcp_protocol_counter_YYYY-WW). No custom table; the
 * row grows in place, autoload OFF so option table scans stay cheap.
 *
 * Signals captured per request (all string-safe, capped, sanitized):
 *   - protocol version: MCP-Protocol-Version HTTP header (2026-07-28 modern-era
 *                       transport-level declaration — present on every stateless
 *                       request per spec)
 *                       → falls back to params._meta['io.modelcontextprotocol/protocolVersion']
 *                       → falls back to params.protocolVersion (legacy handshake)
 *                       → 'unknown' when none present
 *   - client name:     params._meta['io.modelcontextprotocol/clientInfo']['name']
 *                       → falls back to params.clientInfo.name
 *                       → 'unknown' when neither is present
 *   - JSON-RPC method: body.method, or 'unknown' on parse failure
 *   - Mcp-Method / Mcp-Name observability-hint header presence (boolean count)
 *
 * Rollup shape (matches Royal_MCP_Pro\Admin\Protocol_Insights reader exactly):
 *   [
 *     'week_starting'             => 'YYYY-MM-DD',  // Monday of the ISO week
 *     'protocol_version_counts'   => [ '<version>' => int, ... ],
 *     'client_name_counts'        => [ '<name>'    => int, ... ],
 *     'method_counts'             => [ '<method>'  => int, ... ],
 *     'mcp_method_header_present' => int,
 *     'mcp_name_header_present'   => int,
 *     'total_requests'            => int,
 *   ]
 *
 * Failure modes are non-fatal — a malformed body, missing option, or filter-
 * disabled site still returns $result unchanged so the request completes.
 *
 * @since 1.5.3
 */

namespace Royal_MCP\MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Protocol_Counter {

    const OPTION_PREFIX = 'royal_mcp_protocol_counter_';
    const NAMESPACE_MATCH = '/royal-mcp/v1/mcp';

    // Cap sizes to bound wp_options row growth on high-traffic installs and
    // prevent a rogue client from filling the rollup with megabyte identifiers.
    const MAX_PROTOCOL_VERSION_LEN = 64;
    const MAX_CLIENT_NAME_LEN      = 128;
    const MAX_METHOD_LEN           = 128;

    // Per-week cap on unique entries in each counter bucket. Excess entries
    // funnel into an 'other' aggregate rather than growing the row unboundedly
    // when a rogue client rotates its clientInfo.name per-request. Empirical
    // upper bound observed on our own public-facing install
    // (demo.royalplugins.com week 2026-38): ~130 unique client names across
    // ~5,000 requests. 200 gives a real-agent + real-scanner ceiling with
    // headroom.
    const MAX_UNIQUE_PER_BUCKET = 200;

    // Cleanup horizon — rollups older than this many ISO weeks are reaped
    // by cleanup_expired(). 26 weeks = 6 months; the admin dashboard shows
    // the last 12 weeks so this leaves 14 weeks of margin for on-demand
    // export before old data disappears.
    const RETENTION_WEEKS = 26;

    /**
     * Register the rest_pre_dispatch hook. Called once during plugin bootstrap.
     * Idempotent — repeated calls collapse via WP's filter dedupe.
     */
    public static function register() {
        // Priority 5 (before default 10) so the counter records the request
        // even when a later filter short-circuits dispatch with an error.
        add_filter( 'rest_pre_dispatch', array( __CLASS__, 'record' ), 5, 3 );
    }

    /**
     * The rest_pre_dispatch filter callback. Always returns $result unchanged
     * — this is a passive observer, never mutates the response path.
     *
     * @param mixed            $result  Filter passthrough value (null or WP_REST_Response).
     * @param \WP_REST_Server  $server  REST server (unused).
     * @param \WP_REST_Request $request The incoming request.
     * @return mixed The unchanged $result value.
     */
    public static function record( $result, $server, $request ) {
        unset( $server );

        if ( ! ( $request instanceof \WP_REST_Request ) ) {
            return $result;
        }

        // Namespace gate — only count MCP endpoint traffic. Other REST routes
        // on the same install stay out of the rollup.
        if ( strpos( (string) $request->get_route(), self::NAMESPACE_MATCH ) === false ) {
            return $result;
        }

        // Site-owner escape hatch — filter to disable counter on privacy-
        // sensitive installs (default: enabled).
        if ( ! (bool) apply_filters( 'royal_mcp_protocol_counter_enabled', true ) ) {
            return $result;
        }

        $body = (string) $request->get_body();
        if ( '' === $body ) {
            return $result;
        }

        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            // Malformed body still counts as a request, but we can't extract
            // protocol/client hints from it — bucket as 'unknown' across the
            // board. Blocks silent data loss on garbage-post traffic while
            // avoiding a JSON-error-only rollup that would mask visibility
            // into the malformed-traffic rate itself.
            $data = array();
        }

        $signals = array(
            'protocol_version' => self::extract_protocol_version( $data, $request ),
            'client_name'      => self::extract_client_name( $data ),
            'method'           => self::extract_method( $data ),
            'mcp_method_hint'  => (bool) $request->get_header( 'Mcp-Method' ),
            'mcp_name_hint'    => (bool) $request->get_header( 'Mcp-Name' ),
        );

        self::increment( self::current_week_key(), $signals );

        return $result;
    }

    /**
     * Increment the weekly rollup for a single request's signals. Public so
     * tests can drive it directly without going through the REST dispatcher.
     *
     * @param string $week_key wp_options key (self::current_week_key()).
     * @param array  $signals  Extracted per-request signals from record().
     * @return void
     */
    public static function increment( $week_key, array $signals ) {
        $rollup = get_option( $week_key, self::empty_rollup() );
        if ( ! is_array( $rollup ) ) {
            $rollup = self::empty_rollup();
        }
        // Backfill missing keys — a stale rollup shape from a prior release
        // gets normalized on next write instead of throwing typed-index
        // errors mid-increment.
        $rollup = array_merge( self::empty_rollup(), $rollup );

        $pv   = isset( $signals['protocol_version'] ) ? (string) $signals['protocol_version'] : 'unknown';
        $cn   = isset( $signals['client_name'] )      ? (string) $signals['client_name']      : 'unknown';
        $mt   = isset( $signals['method'] )           ? (string) $signals['method']           : 'unknown';
        $mmh  = ! empty( $signals['mcp_method_hint'] );
        $mnh  = ! empty( $signals['mcp_name_hint'] );

        $rollup['protocol_version_counts'] = self::increment_bucket_with_cap( $rollup['protocol_version_counts'], $pv );
        $rollup['client_name_counts']      = self::increment_bucket_with_cap( $rollup['client_name_counts'], $cn );
        $rollup['method_counts']           = self::increment_bucket_with_cap( $rollup['method_counts'], $mt );
        $rollup['mcp_method_header_present'] += $mmh ? 1 : 0;
        $rollup['mcp_name_header_present']   += $mnh ? 1 : 0;
        $rollup['total_requests']            += 1;

        // autoload=false so the option table scan on every request stays
        // small; the rollup is only read by the admin dashboard.
        update_option( $week_key, $rollup, false );
    }

    /**
     * Increment a counter bucket with a per-week unique-key cap. Once the
     * bucket has MAX_UNIQUE_PER_BUCKET entries, new keys are funnelled into
     * an 'other' aggregate rather than growing the row. Existing keys still
     * increment normally regardless of the cap.
     *
     * Protects against a rogue client rotating its clientInfo.name (or any
     * other signal) per-request to balloon one week's wp_options row.
     *
     * @param array  $bucket Current bucket state ('key' => count).
     * @param string $key    Key to increment.
     * @return array Updated bucket.
     */
    private static function increment_bucket_with_cap( array $bucket, $key ) {
        if ( array_key_exists( $key, $bucket ) ) {
            $bucket[ $key ] += 1;
            return $bucket;
        }
        if ( count( $bucket ) >= self::MAX_UNIQUE_PER_BUCKET ) {
            $bucket['other'] = ( $bucket['other'] ?? 0 ) + 1;
            return $bucket;
        }
        $bucket[ $key ] = 1;
        return $bucket;
    }

    /**
     * Reap rollups older than RETENTION_WEEKS. Hooked to the shared
     * royal_mcp_token_cleanup daily cron alongside Token_Store,
     * Session_Store, and Undo_Store cleanup handlers.
     *
     * @return int Number of expired rollup rows removed.
     */
    public static function cleanup_expired() {
        global $wpdb;
        $prefix = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Options-table scan for ISO-week-keyed rollup rows.
        $option_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $prefix
            )
        );
        if ( empty( $option_names ) ) {
            return 0;
        }
        $cutoff_ts = strtotime( sprintf( '-%d weeks', self::RETENTION_WEEKS ) );
        $cutoff_week_id = gmdate( 'o-W', (int) $cutoff_ts );
        $deleted = 0;
        foreach ( (array) $option_names as $option_name ) {
            $week_id = substr( (string) $option_name, strlen( self::OPTION_PREFIX ) );
            // ISO week ids are lexicographically comparable in YYYY-WW format
            // (zero-padded week number, so 2025-02 < 2025-13 < 2026-01).
            if ( strcmp( $week_id, $cutoff_week_id ) < 0 ) {
                delete_option( $option_name );
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Empty rollup shape — canonical shape a fresh week starts with.
     *
     * @return array The empty-week rollup.
     */
    public static function empty_rollup() {
        return array(
            'week_starting'             => gmdate( 'Y-m-d', (int) strtotime( 'monday this week' ) ),
            'protocol_version_counts'   => array(),
            'client_name_counts'        => array(),
            'method_counts'             => array(),
            'mcp_method_header_present' => 0,
            'mcp_name_header_present'   => 0,
            'total_requests'            => 0,
        );
    }

    /**
     * Compute the wp_options key for the current ISO year-week. Public so
     * the admin dashboard + tests use the same source of truth.
     *
     * @return string wp_options row name for the current week's rollup.
     */
    public static function current_week_key() {
        return self::OPTION_PREFIX . gmdate( 'o-W' );
    }

    /**
     * Extract the MCP protocol version from a request. Preference order per
     * MCP 2026-07-28 spec:
     *   1. MCP-Protocol-Version HTTP header (modern-era stateless transport
     *      declares the negotiated version per request; strongest signal)
     *   2. body.params._meta['io.modelcontextprotocol/protocolVersion']
     *   3. body.params.protocolVersion (legacy handshake path)
     *   4. 'unknown'
     *
     * The $request argument is optional so tests + admin/dashboard code can
     * still hydrate a version off a raw body when no request object is at
     * hand — in that mode the HTTP-header source is skipped and preference
     * starts at #2.
     *
     * @param array                 $data    Decoded JSON-RPC request body.
     * @param \WP_REST_Request|null $request Optional request for header lookup.
     * @return string Sanitized + capped version string, or 'unknown'.
     */
    public static function extract_protocol_version( array $data, $request = null ) {
        $raw = null;
        if ( $request instanceof \WP_REST_Request ) {
            $header = $request->get_header( 'MCP-Protocol-Version' );
            if ( is_string( $header ) && '' !== $header ) {
                $raw = $header;
            }
        }
        if ( null === $raw && isset( $data['params']['_meta']['io.modelcontextprotocol/protocolVersion'] ) ) {
            $raw = $data['params']['_meta']['io.modelcontextprotocol/protocolVersion'];
        }
        if ( null === $raw && isset( $data['params']['protocolVersion'] ) ) {
            $raw = $data['params']['protocolVersion'];
        }
        if ( ! is_string( $raw ) || '' === $raw ) {
            return 'unknown';
        }
        return sanitize_text_field( substr( $raw, 0, self::MAX_PROTOCOL_VERSION_LEN ) );
    }

    /**
     * Extract the client name from a decoded request body. Preference:
     *   1. params._meta['io.modelcontextprotocol/clientInfo']['name']
     *   2. params.clientInfo.name (legacy handshake path)
     *   3. 'unknown'
     *
     * @param array $data Decoded JSON-RPC request body.
     * @return string Sanitized + capped client name, or 'unknown'.
     */
    public static function extract_client_name( array $data ) {
        $raw = null;
        if ( isset( $data['params']['_meta']['io.modelcontextprotocol/clientInfo']['name'] ) ) {
            $raw = $data['params']['_meta']['io.modelcontextprotocol/clientInfo']['name'];
        } elseif ( isset( $data['params']['clientInfo']['name'] ) ) {
            $raw = $data['params']['clientInfo']['name'];
        }
        if ( ! is_string( $raw ) || '' === $raw ) {
            return 'unknown';
        }
        return sanitize_text_field( substr( $raw, 0, self::MAX_CLIENT_NAME_LEN ) );
    }

    /**
     * Extract the JSON-RPC method name from a decoded request body. Returns
     * 'unknown' on missing / non-string method.
     *
     * @param array $data Decoded JSON-RPC request body.
     * @return string Sanitized + capped method name, or 'unknown'.
     */
    public static function extract_method( array $data ) {
        $raw = $data['method'] ?? null;
        if ( ! is_string( $raw ) || '' === $raw ) {
            return 'unknown';
        }
        return sanitize_text_field( substr( $raw, 0, self::MAX_METHOD_LEN ) );
    }
}
