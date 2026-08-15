<?php
namespace Royal_MCP\MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Undo Store — persists compressed pre-op snapshots for destructive MCP tools
 * that support reversibility. Tokens returned in a tool's response envelope
 * (`undo.token`) are consumed by `mcp_undo_last_operation` to restore the
 * pre-op state.
 *
 * Storage: one wp_options row per snapshot, keyed `royal_mcp_undo_<token>`,
 * autoload = no (snapshots are read only when a specific undo is requested).
 *
 * TTL: 72 hours by default. Expired rows are pruned lazily on next read AND
 * bulk-swept on the shared `royal_mcp_token_cleanup` daily cron.
 */
class Undo_Store {

    const OPTION_PREFIX = 'royal_mcp_undo_';
    const DEFAULT_TTL   = 259200; // 72 * 3600 seconds (avoid HOUR_IN_SECONDS eval order in const context)

    /**
     * Persist a snapshot and return the undo envelope for the tool response.
     *
     * @param array $snapshot Free-form snapshot payload. Expected keys:
     *                        - `op`      (string) tool name that generated this snapshot
     *                        - `summary` (string) human-readable "what would be restored" text
     *                        - `target`  (array)  identifying info about the mutated object
     *                        - `pre_op_state` (mixed) whatever the consumer needs to restore
     * @return array `{token, expires_at, summary, ttl_hours}` — merge into the tool's response as `undo`.
     */
    public static function store( array $snapshot ): array {
        $token      = bin2hex( random_bytes( 16 ) );
        $expires_at = time() + self::DEFAULT_TTL;

        $envelope = array_merge( $snapshot, [
            'token'      => $token,
            'created_at' => time(),
            'expires_at' => $expires_at,
        ] );

        // Compress + base64 so the option value stays plain-text (some hosts
        // reject binary blobs in wp_options via WAF/backup layers) but is
        // still ~30-50% smaller than raw JSON for typical menu snapshots.
        $stored = base64_encode( gzcompress( wp_json_encode( $envelope ), 9 ) );
        add_option( self::OPTION_PREFIX . $token, $stored, '', 'no' );

        return [
            'token'      => $token,
            'expires_at' => $expires_at,
            'summary'    => isset( $snapshot['summary'] ) ? (string) $snapshot['summary'] : '',
            'ttl_hours'  => (int) ( self::DEFAULT_TTL / 3600 ),
        ];
    }

    /**
     * Read a snapshot by token. Returns null when the token is invalid, missing,
     * or expired. Expired rows are deleted as a side effect of a failed read.
     *
     * Design invariant: all four failure modes (malformed token, unknown token,
     * decode failure, expired) return the same null value on purpose — every
     * failed read is indistinguishable to the caller. Do not add distinct error
     * paths here; a single opaque failure is required by the token model.
     */
    public static function read( string $token ): ?array {
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
            return null;
        }
        $stored = get_option( self::OPTION_PREFIX . $token );
        if ( ! $stored ) {
            return null;
        }
        $raw = @gzuncompress( base64_decode( $stored ) );
        if ( $raw === false ) {
            return null;
        }
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            return null;
        }
        if ( isset( $data['expires_at'] ) && (int) $data['expires_at'] < time() ) {
            self::delete( $token );
            return null;
        }
        return $data;
    }

    /**
     * Delete a snapshot by token.
     */
    public static function delete( string $token ): bool {
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
            return false;
        }
        return delete_option( self::OPTION_PREFIX . $token );
    }

    /**
     * Count active (non-expired) undo tokens. Used by the AI Safety
     * Overview dashboard widget as the "Undo Window" metric. Iterates
     * all rows with the OPTION_PREFIX — cheap for typical token counts
     * (widget dashboards render infrequently; token counts are small).
     */
    public static function count_active(): int {
        $stats = self::collect_active_stats();
        return $stats['count'];
    }

    /**
     * Minimum expires_at across active (non-expired) tokens, or null when
     * no active tokens exist. Powers the "Oldest reversible op" status
     * line in the AI Safety Overview widget.
     */
    public static function oldest_expiry(): ?int {
        $stats = self::collect_active_stats();
        return $stats['oldest'];
    }

    /**
     * List every active (non-expired) undo token with its full metadata.
     * Powers the "Recent Operations" admin page that lets admins inspect
     * and one-click-undo pending operations.
     *
     * Returns tokens sorted by created_at DESC (newest first) — matches
     * "what did I just do?" workflow. Each entry:
     *   - token (string)
     *   - op (string) — tool name
     *   - summary (string) — human-readable description
     *   - created_at (int) — unix
     *   - expires_at (int) — unix
     *   - expires_in_seconds (int)
     *
     * @return array<int, array{token: string, op: string, summary: string, created_at: int, expires_at: int, expires_in_seconds: int}>
     */
    public static function list_active(): array {
        global $wpdb;
        $prefix = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $prefix
            )
        );
        $out = [];
        $now = time();
        foreach ( (array) $rows as $row ) {
            $stored = isset( $row->option_value ) ? (string) $row->option_value : '';
            if ( '' === $stored ) continue;
            $raw = @gzuncompress( base64_decode( $stored ) );
            if ( false === $raw ) continue;
            $data = json_decode( $raw, true );
            if ( ! is_array( $data ) ) continue;
            $expires_at = isset( $data['expires_at'] ) ? (int) $data['expires_at'] : 0;
            if ( $expires_at <= $now ) continue;
            $out[] = [
                'token'              => isset( $data['token'] ) ? (string) $data['token'] : '',
                'op'                 => isset( $data['op'] ) ? (string) $data['op'] : '',
                'summary'            => isset( $data['summary'] ) ? (string) $data['summary'] : '',
                'created_at'         => isset( $data['created_at'] ) ? (int) $data['created_at'] : 0,
                'expires_at'         => $expires_at,
                'expires_in_seconds' => max( 0, $expires_at - $now ),
            ];
        }
        // Newest first. Token used as deterministic tiebreaker when two
        // rows share created_at (bin2hex token → lexicographic order is
        // stable, unlike DB scan order).
        usort( $out, static function ( $a, $b ) {
            if ( $a['created_at'] === $b['created_at'] ) {
                return strcmp( $b['token'], $a['token'] );
            }
            return $b['created_at'] <=> $a['created_at'];
        } );
        return $out;
    }

    /**
     * One-pass iteration over royal_mcp_undo_* options producing both the
     * active-token count and the min-expires_at. Separated so multiple
     * dashboard-widget metric calls in one request don't re-scan the
     * options table.
     *
     * @return array{count: int, oldest: int|null}
     */
    private static function collect_active_stats(): array {
        global $wpdb;
        $prefix = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $prefix
            )
        );
        $count  = 0;
        $oldest = null;
        $now    = time();
        foreach ( (array) $rows as $row ) {
            $stored = isset( $row->option_value ) ? (string) $row->option_value : '';
            if ( '' === $stored ) {
                continue;
            }
            $raw = @gzuncompress( base64_decode( $stored ) );
            if ( false === $raw ) {
                continue;
            }
            $data = json_decode( $raw, true );
            if ( ! is_array( $data ) ) {
                continue;
            }
            $expires_at = isset( $data['expires_at'] ) ? (int) $data['expires_at'] : 0;
            if ( $expires_at <= $now ) {
                // Expired but not yet swept. Skip for the widget metric —
                // cron cleanup will remove eventually.
                continue;
            }
            $count++;
            if ( null === $oldest || $expires_at < $oldest ) {
                $oldest = $expires_at;
            }
        }
        return [ 'count' => $count, 'oldest' => $oldest ];
    }

    /**
     * Sweep every expired snapshot from wp_options. Hooked to the shared
     * `royal_mcp_token_cleanup` daily cron alongside `Token_Store::cleanup_expired`
     * and `Session_Store::cleanup_expired`.
     *
     * @return int Number of expired rows removed.
     */
    public static function cleanup_expired(): int {
        global $wpdb;
        $prefix = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $option_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $prefix
            )
        );
        $deleted = 0;
        foreach ( (array) $option_names as $option_name ) {
            $token = substr( $option_name, strlen( self::OPTION_PREFIX ) );
            // read() auto-deletes on expiry; count deletes by checking whether the row is gone after.
            $existed_before = (bool) get_option( $option_name );
            self::read( $token );
            $existed_after = (bool) get_option( $option_name );
            if ( $existed_before && ! $existed_after ) {
                $deleted++;
            }
        }
        return $deleted;
    }
}
