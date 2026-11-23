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
 * TTL: 72 hours by default (filter royal_mcp_undo_ttl_seconds). Expired rows are pruned lazily on next read AND
 * bulk-swept on the shared `royal_mcp_token_cleanup` daily cron.
 */
class Undo_Store {

    const OPTION_PREFIX = 'royal_mcp_undo_';

    /** Rows holding the parts of a large snapshot's earlier state: royal_mcp_undopart_<token>_<n>. */
    const PART_PREFIX = 'royal_mcp_undopart_';

    /** Largest stored row; a bigger snapshot keeps its earlier state in parts of this size. */
    const PART_BYTES = 900000;

    /** Largest earlier state an undo keeps (compressed); writers above it say there is no undo. */
    const MAX_COMPRESSED_BYTES = 8388608;

    /** Whether a value of this size (compressed JSON) can still be kept for undo. */
    public static function fits( $value ): bool {
        return strlen( gzcompress( (string) wp_json_encode( $value ), 9 ) ) <= self::MAX_COMPRESSED_BYTES;
    }

    /**
     * The stored form of a snapshot. Up to PART_BYTES it is one row as before; above
     * that the earlier state goes into part rows and the main row keeps the rest
     * (owner, expiry, summary), so listings stay small.
     */
    private static function encode( array $data, string $token ): string {
        $whole = base64_encode( gzcompress( (string) wp_json_encode( $data ), 9 ) );
        self::delete_parts( $token );
        if ( strlen( $whole ) <= self::PART_BYTES || ! isset( $data['pre_op_state'] ) ) {
            return self::wrap( $data, $whole );
        }
        $blob  = base64_encode( gzcompress( (string) wp_json_encode( $data['pre_op_state'] ), 9 ) );
        $parts = str_split( $blob, self::PART_BYTES );
        foreach ( $parts as $i => $part ) {
            if ( ! add_option( self::PART_PREFIX . $token . '_' . $i, $part, '', 'no' ) ) {
                self::delete_parts( $token );
                return ''; // The database would not take a part: no undo rather than one that cannot run.
            }
        }
        $pre = (array) $data['pre_op_state'];
        $full = $data;
        $data['pre_op_state'] = [
            '__royal_mcp_parts' => count( $parts ),
            '_sha256'           => hash( 'sha256', $blob ),
            'original_op'       => $pre['original_op'] ?? null,
        ];
        return self::wrap( $full, base64_encode( gzcompress( (string) wp_json_encode( $data ), 9 ) ) );
    }

    /** Marks the stored form of a snapshot row: FORMAT|owner|state|head|body. */
    const FORMAT = 'rmcp2';

    /**
     * The stored row: a small head (everything a change list shows, without the
     * earlier state) ahead of the full body. The owner and the used/unused state
     * sit in plain text so limits and lists can read them in SQL without
     * decoding any snapshot.
     */
    private static function wrap( array $data, string $body ): string {
        $head = $data;
        $pre  = isset( $data['pre_op_state'] ) && is_array( $data['pre_op_state'] ) ? $data['pre_op_state'] : [];
        // Short scalar fields of the earlier state stay in the head (the original
        // tool name, a created item's id); everything else stays in the body.
        $head['pre_op_state'] = array_filter( $pre, static function ( $v ) {
            return is_scalar( $v ) && strlen( (string) $v ) <= 200;
        } );
        return implode( '|', [
            self::FORMAT,
            (int) ( $data['user_id'] ?? 0 ),
            empty( $data['undone_at'] ) ? 'a' : 'd',
            base64_encode( gzcompress( (string) wp_json_encode( $head ), 9 ) ),
            $body,
        ] );
    }

    /** Most snapshot rows kept per user and overall, and their largest total stored size. */
    const MAX_PER_USER    = 1000;
    const MAX_TOTAL       = 10000;
    const MAX_TOTAL_BYTES = 134217728; // 128 MB

    /** @return array{per_user: int, total: int, bytes: int} */
    public static function limits(): array {
        $limits = (array) apply_filters( 'royal_mcp_undo_limits', [
            'per_user' => self::MAX_PER_USER,
            'total'    => self::MAX_TOTAL,
            'bytes'    => self::MAX_TOTAL_BYTES,
        ] );
        return [
            'per_user' => max( 1, (int) ( $limits['per_user'] ?? self::MAX_PER_USER ) ),
            'total'    => max( 1, (int) ( $limits['total'] ?? self::MAX_TOTAL ) ),
            'bytes'    => max( 1048576, (int) ( $limits['bytes'] ?? self::MAX_TOTAL_BYTES ) ),
        ];
    }

    /**
     * Keep the store inside its limits after a new snapshot was added. Used
     * snapshots go first, then the oldest; the snapshot just written is kept.
     *
     * @return int Snapshots removed.
     */
    public static function enforce_limits( string $keep_token = '' ): int {
        global $wpdb;
        $limits  = self::limits();
        $removed = 0;
        $totals  = self::totals( $keep_token );
        $count   = $totals['count'];
        $uid     = (int) get_current_user_id();
        $like    = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
        $namelen = strlen( self::OPTION_PREFIX ) + 32;
        if ( $uid > 0 && $count > $limits['per_user'] ) {
            $owner = self::FORMAT . '|' . $uid . '|%';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $mine = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND CHAR_LENGTH(option_name) = %d AND option_value LIKE %s", $like, $namelen, $owner ) );
            if ( $mine > $limits['per_user'] ) {
                $n        = self::prune( $mine - $limits['per_user'], $uid, $keep_token );
                $removed += $n;
                $count   -= $n;
            }
        }
        if ( $count > $limits['total'] ) {
            $removed += self::prune( $count - $limits['total'], null, $keep_token );
        }
        if ( $removed > 0 || $totals['bytes'] > $limits['bytes'] ) {
            // Over the size ceiling the writing user's own snapshots go first, then everyone's.
            for ( $round = 0; $round < 50; $round++ ) {
                $totals = self::totals( $keep_token, true );
                if ( $totals['bytes'] <= $limits['bytes'] ) {
                    break;
                }
                $n = $uid > 0 ? self::prune( 10, $uid, $keep_token ) : 0;
                if ( $n < 1 ) {
                    $n = self::prune( 10, null, $keep_token );
                }
                if ( $n < 1 ) {
                    break;
                }
                $removed += $n;
            }
        }
        return $removed;
    }

    /** Transient holding the snapshot count and stored size, so a store does not re-measure every row. */
    const TOTALS_TRANSIENT = 'royal_mcp_undo_totals';

    /**
     * Number of snapshot rows and their stored size (part rows included). Kept in a
     * short-lived transient: a new snapshot adds its own size, removals clear it.
     *
     * @param string $new_token Snapshot just written, added to cached totals once.
     * @param bool   $fresh     Measure again instead of using the cached totals.
     * @return array{count: int, bytes: int}
     */
    public static function totals( string $new_token = '', bool $fresh = false ): array {
        global $wpdb;
        $cached = $fresh ? false : get_transient( self::TOTALS_TRANSIENT );
        if ( is_array( $cached ) && isset( $cached['count'], $cached['bytes'] ) ) {
            if ( '' !== $new_token && preg_match( '/^[a-f0-9]{32}$/', $new_token ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $add = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE option_name = %s OR option_name LIKE %s", self::OPTION_PREFIX . $new_token, $wpdb->esc_like( self::PART_PREFIX . $new_token . '_' ) . '%' ) );
                $cached = [ 'count' => (int) $cached['count'] + 1, 'bytes' => (int) $cached['bytes'] + $add ];
                set_transient( self::TOTALS_TRANSIENT, $cached, 10 * MINUTE_IN_SECONDS );
            }
            return [ 'count' => (int) $cached['count'], 'bytes' => (int) $cached['bytes'] ];
        }
        $like    = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
        $parts   = $wpdb->esc_like( self::PART_PREFIX ) . '%';
        $namelen = strlen( self::OPTION_PREFIX ) + 32;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(option_value)), 0) AS b FROM {$wpdb->options} WHERE option_name LIKE %s AND CHAR_LENGTH(option_name) = %d", $like, $namelen ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $part_bytes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE option_name LIKE %s", $parts ) );
        $out = [ 'count' => (int) ( $row->n ?? 0 ), 'bytes' => (int) ( $row->b ?? 0 ) + $part_bytes ];
        set_transient( self::TOTALS_TRANSIENT, $out, 10 * MINUTE_IN_SECONDS );
        return $out;
    }

    /** Remove up to $n snapshots, used ones first, then oldest first. */
    private static function prune( int $n, ?int $user_id, string $keep_token ): int {
        global $wpdb;
        if ( $n < 1 ) {
            return 0;
        }
        $like = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
        $done = self::FORMAT . '|%|d|%';
        $keep = self::OPTION_PREFIX . $keep_token;
        $len  = strlen( self::OPTION_PREFIX ) + 32;
        if ( null !== $user_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CHAR_LENGTH(option_name) = %d AND option_name <> %s AND option_value LIKE %s ORDER BY ( option_value LIKE %s ) DESC, option_id ASC LIMIT %d", $like, $len, $keep, self::FORMAT . '|' . $user_id . '|%', $done, $n ) );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CHAR_LENGTH(option_name) = %d AND option_name <> %s ORDER BY ( option_value LIKE %s ) DESC, option_id ASC LIMIT %d", $like, $len, $keep, $done, $n ) );
        }
        $removed = 0;
        foreach ( (array) $names as $name ) {
            $token = substr( (string) $name, strlen( self::OPTION_PREFIX ) );
            if ( preg_match( '/^[a-f0-9]{32}$/', $token ) && self::delete( $token ) ) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Snapshot rows with only their heads: option_id, option_name and the head
     * part of the stored value (rows in the older single-part form come whole).
     */
    private static function head_rows( string $where_sql, array $params ): array {
        global $wpdb;
        $sql = "SELECT option_id, option_name, CASE WHEN option_value LIKE %s THEN SUBSTRING_INDEX(option_value, '|', 4) ELSE option_value END AS head FROM {$wpdb->options} WHERE option_name LIKE %s " . $where_sql;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $where_sql holds placeholders only; values are in $params
        return (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( [ self::FORMAT . '|%', $wpdb->esc_like( self::OPTION_PREFIX ) . '%' ], $params ) ) );
    }

    /** Decode a head from head_rows(), or a whole row in the older form. */
    private static function decode_head( string $head ): ?array {
        if ( 0 === strpos( $head, self::FORMAT . '|' ) ) {
            $bits = explode( '|', $head, 5 );
            $head = (string) ( $bits[3] ?? '' );
        }
        return self::decode_body( $head );
    }

    /** Put a large snapshot's earlier state back together; null when a part is missing or changed. */
    private static function hydrate( array $data, string $token ): ?array {
        $parts = (int) ( $data['pre_op_state']['__royal_mcp_parts'] ?? 0 );
        if ( $parts < 1 ) {
            return $data;
        }
        $blob = '';
        for ( $i = 0; $i < $parts; $i++ ) {
            wp_cache_delete( self::PART_PREFIX . $token . '_' . $i, 'options' );
            $part = get_option( self::PART_PREFIX . $token . '_' . $i, '' );
            if ( ! is_string( $part ) || '' === $part ) {
                return null;
            }
            $blob .= $part;
        }
        if ( ! hash_equals( (string) ( $data['pre_op_state']['_sha256'] ?? '' ), hash( 'sha256', $blob ) ) ) {
            return null;
        }
        $raw = @gzuncompress( base64_decode( $blob ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a damaged row reads as missing
        $pre = false === $raw ? null : json_decode( $raw, true );
        if ( ! is_array( $pre ) ) {
            return null;
        }
        $data['pre_op_state'] = $pre;
        return $data;
    }

    private static function delete_parts( string $token ): void {
        global $wpdb;
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PART_PREFIX . $token . '_' ) . '%' ) );
        foreach ( (array) $names as $name ) {
            delete_option( (string) $name );
        }
    }
    const UNREADABLE_OPTION       = 'royal_mcp_undo_unreadable_seen';
    const UNREADABLE_KEEP_SECONDS = 2592000; // 30 days
    const DEFAULT_TTL   = 259200; // 72 * 3600 seconds (avoid HOUR_IN_SECONDS eval order in const context)
    /** Most changes a change list returns. */
    const CHANGES_LIMIT = 500;
    /** Snapshot rows decoded per database read while building a change list. */
    const CHANGES_BATCH = 200;

    /**
     * The connection making the current tool call, recorded on every
     * snapshot so a change can say which AI client made it.
     *
     * @var array{auth: string, route: string, client_id: string}
     */
    private static $context = [ 'auth' => '', 'route' => 'native', 'client_id' => '' ];

    /** Set by the MCP server before each tool call. */
    public static function set_context( string $auth, string $route, string $client_id ): void {
        self::$context = [ 'auth' => $auth, 'route' => $route, 'client_id' => $client_id ];
    }

    /**
     * Persist a snapshot and return the undo envelope for the tool response.
     *
     * @param array $snapshot Free-form snapshot payload. Expected keys:
     *                        - `op`      (string) tool name that generated this snapshot
     *                        - `summary` (string) human-readable "what would be restored" text
     *                        - `target`  (array)  identifying info about the mutated object
     *                        - `pre_op_state` (mixed) whatever the consumer needs to restore
     * @return array `{token, expires_at, summary, ttl_hours}` — merge into the tool's response as `undo`.
     *               `expires_at` is emitted as an ISO 8601 UTC string on the wire (matches the fleet
     *               Bug 17 pattern); storage keeps epoch int for internal TTL arithmetic.
     */
    public static function store( array $snapshot ): array {
        $token          = bin2hex( random_bytes( 16 ) );
        $created_at_int = time();
        $expires_at_int = $created_at_int + self::ttl_seconds();

        // Bind the snapshot to the user who created it so replay is scoped
        // to the same caller. read() enforces the match on retrieval; the
        // 49 store() callsites across the plugin inherit owner-binding by
        // construction and need no per-callsite change.
        $envelope = array_merge( $snapshot, [
            'token'      => $token,
            'created_at' => $created_at_int,
            'expires_at' => $expires_at_int,
            'user_id'    => (int) get_current_user_id(),
            'via'        => self::$context,
        ] );

        // Compress + base64 so the option value stays plain-text (some hosts
        // reject binary blobs in wp_options via WAF/backup layers) but is
        // still ~30-50% smaller than raw JSON for typical menu snapshots.
        $stored = self::encode( $envelope, $token );
        if ( '' === $stored || ! add_option( self::OPTION_PREFIX . $token, $stored, '', 'no' ) ) {
            self::delete_parts( $token );
            return []; // No token: the caller's reply then offers no undo.
        }
        self::enforce_limits( $token );

        return [
            'token'      => $token,
            'expires_at' => gmdate( 'c', $expires_at_int ),
            'summary'    => isset( $snapshot['summary'] ) ? (string) $snapshot['summary'] : '',
            'ttl_hours'  => self::ttl_hours(),
        ];
    }

    /** How long an undo token lasts, in seconds (at least one hour). */
    public static function ttl_seconds(): int {
        return max( 3600, (int) apply_filters( 'royal_mcp_undo_ttl_seconds', self::DEFAULT_TTL ) );
    }

    public static function ttl_hours(): int {
        return (int) floor( self::ttl_seconds() / 3600 );
    }

    /** The undo window for people: "7 days" or "72 hours". */
    public static function window_label(): string {
        $hours = self::ttl_hours();
        if ( $hours > 72 && 0 === $hours % 24 ) {
            /* translators: %d: number of days */
            return sprintf( _n( '%d day', '%d days', $hours / 24, 'royal-mcp' ), $hours / 24 );
        }
        /* translators: %d: number of hours */
        return sprintf( _n( '%d hour', '%d hours', $hours, 'royal-mcp' ), $hours );
    }

    /** The undo window as a modifier: "7-day" or "72-hour". */
    public static function window_adjective(): string {
        $hours = self::ttl_hours();
        return ( $hours > 72 && 0 === $hours % 24 ) ? ( $hours / 24 ) . '-day' : $hours . '-hour';
    }

    /**
     * The undo token in a tool call's arguments. Write replies name it undo_token and
     * the undo tool's argument is token, so either name is read; token is used when
     * both hold a value.
     *
     * @param array $args Tool arguments.
     * @return string '' when neither holds a usable value.
     */
    public static function token_from_args( array $args ) {
        foreach ( [ 'token', 'undo_token' ] as $name ) {
            if ( isset( $args[ $name ] ) && is_scalar( $args[ $name ] ) && ! is_bool( $args[ $name ] ) ) {
                $token = sanitize_text_field( (string) $args[ $name ] );
                if ( '' !== $token ) {
                    return $token;
                }
            }
        }
        return '';
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
        if ( ! $stored || ! is_string( $stored ) ) {
            return null;
        }
        $data = self::decode( $stored );
        if ( null === $data ) {
            return null;
        }
        if ( isset( $data['expires_at'] ) && (int) $data['expires_at'] < time() ) {
            self::delete( $token );
            return null;
        }
        if ( ! empty( $data['undone_at'] ) ) {
            return null;
        }
        // Owner gate: return the same opaque null as the other failure modes
        // when the caller is not the user who created the snapshot. Snapshots
        // stored before the owner-binding change lack the user_id field and
        // fall through the strict compare — treated as not-owned and denied,
        // which closes the pre-binding window within the 72-hour TTL.
        $owner_id  = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
        $caller_id = (int) get_current_user_id();
        if ( $owner_id < 1 || $owner_id !== $caller_id ) {
            return null;
        }
        return self::hydrate( $data, $token );
    }

    /**
     * Delete a snapshot by token.
     */
    public static function delete( string $token ): bool {
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
            return false;
        }
        self::delete_parts( $token );
        delete_transient( self::TOTALS_TRANSIENT );
        return delete_option( self::OPTION_PREFIX . $token );
    }

    /**
     * Mark a snapshot as used by a successful undo. It can't be used
     * again, but stays readable as an undone change until it expires.
     */
    public static function consume( string $token ): bool {
        $data = self::decode_row( $token );
        if ( null === $data ) {
            return self::delete( $token );
        }
        $data['undone_at'] = time();
        $data['undone_by'] = (int) get_current_user_id();
        return update_option( self::OPTION_PREFIX . $token, self::encode( $data, $token ), false );
    }

    /**
     * Keep a snapshot open after a partial undo, changed so that it covers
     * only what is still left to restore.
     *
     * Written in one conditional update: it succeeds only while the snapshot
     * is unused and still restores exactly $expected_pre_op_state, so a
     * concurrent undo or narrow is never overwritten.
     *
     * @param string $token                 Undo token.
     * @param array  $expected_pre_op_state The pre_op_state the caller read.
     * @param array  $updates               Snapshot keys to replace (pre_op_state, summary, target, ...).
     * @return bool True when the snapshot was changed.
     */
    public static function narrow( string $token, array $expected_pre_op_state, array $updates ): bool {
        global $wpdb;
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
            return false;
        }
        $name = self::OPTION_PREFIX . $token;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-set needs the stored row, not a cached copy
        $stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
        $data   = is_string( $stored ) ? self::decode( $stored ) : null;
        if ( null === $data || ! empty( $data['undone_at'] ) || ! empty( $data['pre_op_state']['__royal_mcp_parts'] ) ) {
            return false; // A snapshot kept in parts is not narrowed; its undo still runs whole.
        }
        if ( wp_json_encode( $data['pre_op_state'] ?? null ) !== wp_json_encode( $expected_pre_op_state ) ) {
            return false;
        }
        unset( $updates['token'], $updates['user_id'], $updates['created_at'], $updates['expires_at'], $updates['undone_at'], $updates['undone_by'] );
        $merged = array_merge( $data, $updates );
        $next   = self::wrap( $merged, base64_encode( gzcompress( (string) wp_json_encode( $merged ), 9 ) ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- conditional update, cache cleared below
        $changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $next, $name, $stored ) );
        wp_cache_delete( $name, 'options' );
        return 1 === $changed;
    }

    /**
     * Public reference for a change: a hash of its token, so a log row or
     * an admin link can point at the change without holding the token.
     */
    public static function change_id( string $token ): string {
        return substr( hash( 'sha256', 'royal-mcp-change|' . $token ), 0, 20 );
    }

    /**
     * Snapshots still inside their window, undone ones included, newest
     * first, at most CHANGES_LIMIT of them. Rows are read in batches so a
     * busy site never holds every snapshot in memory at once. Owner and
     * capability checks are the caller's job.
     *
     * @param int|null $user_id         Only this user's snapshots, or every user's when null.
     * @param bool     $include_expired Also return expired rows the daily sweep hasn't removed yet.
     * @return array<int, array>
     */
    public static function all_changes( ?int $user_id = null, bool $include_expired = false, int $limit = self::CHANGES_LIMIT ): array {
        $limit = max( 1, min( self::CHANGES_LIMIT, $limit ) );
        $batch = min( self::CHANGES_BATCH, $limit );
        $out    = [];
        $before = PHP_INT_MAX;
        while ( count( $out ) < $limit ) {
            // Only heads are read: a list never needs a snapshot's earlier state.
            if ( null !== $user_id ) {
                $rows = self::head_rows( 'AND option_id < %d AND ( option_value LIKE %s OR option_value NOT LIKE %s ) ORDER BY option_id DESC LIMIT %d', [ $before, self::FORMAT . '|' . $user_id . '|%', self::FORMAT . '|%', $batch ] );
            } else {
                $rows = self::head_rows( 'AND option_id < %d ORDER BY option_id DESC LIMIT %d', [ $before, $batch ] );
            }
            if ( empty( $rows ) ) {
                break;
            }
            foreach ( $rows as $row ) {
                $before = (int) $row->option_id;
                $data   = self::visible( self::decode_head( isset( $row->head ) ? (string) $row->head : '' ), $user_id, $include_expired );
                if ( null !== $data ) {
                    $out[] = $data;
                    if ( count( $out ) >= $limit ) {
                        break;
                    }
                }
            }
            if ( count( $rows ) < $batch ) {
                break;
            }
        }
        usort( $out, static function ( $a, $b ) {
            if ( (int) $a['created_at'] === (int) $b['created_at'] ) {
                return strcmp( (string) $b['token'], (string) $a['token'] );
            }
            return (int) $b['created_at'] <=> (int) $a['created_at'];
        } );
        return $out;
    }

    /**
     * One change by its change_id, or null. Same visibility rules as
     * all_changes(). The token is part of the option name, so only the
     * matching row is decoded.
     */
    public static function find_change( string $change_id, ?int $user_id = null, bool $include_expired = false ): ?array {
        if ( ! preg_match( '/^[a-f0-9]{20}$/', $change_id ) ) {
            return null;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( self::OPTION_PREFIX ) . '%'
            )
        );
        foreach ( (array) $names as $name ) {
            $token = substr( (string) $name, strlen( self::OPTION_PREFIX ) );
            if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) || ! hash_equals( self::change_id( $token ), $change_id ) ) {
                continue;
            }
            $data = self::visible( self::decode_row( $token ), $user_id, $include_expired );
            return ( null !== $data && hash_equals( (string) $data['token'], $token ) ) ? $data : null;
        }
        return null;
    }

    /** A decoded snapshot with its change_id, or null when it is not shown. */
    private static function visible( ?array $data, ?int $user_id, bool $include_expired ): ?array {
        if ( null === $data || empty( $data['token'] ) || ( ! $include_expired && (int) ( $data['expires_at'] ?? 0 ) <= time() ) ) {
            return null;
        }
        if ( null !== $user_id && (int) ( $data['user_id'] ?? 0 ) !== $user_id ) {
            return null;
        }
        $data['change_id'] = self::change_id( (string) $data['token'] );
        return $data;
    }

    /** Decode one stored row by token, with no expiry or owner checks. */
    private static function decode_row( string $token ): ?array {
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
            return null;
        }
        $stored = get_option( self::OPTION_PREFIX . $token, '' );
        $data   = is_string( $stored ) ? self::decode( $stored ) : null;
        return null === $data ? null : self::hydrate( $data, $token );
    }

    private static function decode( string $stored ): ?array {
        if ( 0 === strpos( $stored, self::FORMAT . '|' ) ) {
            $bits   = explode( '|', $stored, 5 );
            $stored = (string) ( $bits[4] ?? '' );
        }
        return self::decode_body( $stored );
    }

    private static function decode_body( string $stored ): ?array {
        if ( '' === $stored ) {
            return null;
        }
        $raw = @gzuncompress( base64_decode( $stored ) );
        if ( false === $raw ) {
            return null;
        }
        $data = json_decode( $raw, true );
        return is_array( $data ) ? $data : null;
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
        $rows = self::head_rows( '', [] );
        $out = [];
        $now = time();
        foreach ( (array) $rows as $row ) {
            $data = self::decode_head( isset( $row->head ) ? (string) $row->head : '' );
            if ( null === $data ) continue;
            $expires_at = isset( $data['expires_at'] ) ? (int) $data['expires_at'] : 0;
            if ( $expires_at <= $now || ! empty( $data['undone_at'] ) ) continue;
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
        $rows   = self::head_rows( '', [] );
        $count  = 0;
        $oldest = null;
        $now    = time();
        foreach ( (array) $rows as $row ) {
            $data = self::decode_head( isset( $row->head ) ? (string) $row->head : '' );
            if ( null === $data ) {
                continue;
            }
            $expires_at = isset( $data['expires_at'] ) ? (int) $data['expires_at'] : 0;
            if ( $expires_at <= $now ) {
                // Expired but not yet swept. Skip for the widget metric —
                // cron cleanup will remove eventually.
                continue;
            }
            if ( ! empty( $data['undone_at'] ) ) {
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
        $deleted    = 0;
        // Rows this version cannot read (a newer storage format after a downgrade, or damaged data)
        // are kept until they have been unreadable for longer than any undo window.
        $unreadable = get_option( self::UNREADABLE_OPTION, [] );
        $unreadable = is_array( $unreadable ) ? $unreadable : [];
        $seen       = [];
        $now        = time();
        foreach ( (array) $option_names as $option_name ) {
            $token = substr( (string) $option_name, strlen( self::OPTION_PREFIX ) );
            if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
                continue; // not a snapshot row
            }
            $data = self::decode_row( $token );
            if ( null === $data ) {
                // Text that is not this version's format may be a newer format: kept 30 days.
                // Anything else (not text, or empty) is damaged: kept for the normal undo window.
                $raw   = get_option( (string) $option_name, '' );
                $keep  = is_string( $raw ) && '' !== $raw ? max( self::UNREADABLE_KEEP_SECONDS, self::ttl_seconds() ) : self::ttl_seconds();
                $first = isset( $unreadable[ $token ] ) ? (int) $unreadable[ $token ] : $now;
                if ( $now - $first < $keep ) {
                    $seen[ $token ] = $first;
                    continue;
                }
            } elseif ( ! isset( $data['expires_at'] ) || (int) $data['expires_at'] >= $now ) {
                continue;
            }
            self::delete( $token );
            if ( false === get_option( (string) $option_name, false ) ) {
                $deleted++;
            }
        }
        // Part rows whose snapshot row is gone (a write that failed half way) are removed too.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $part_names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PART_PREFIX ) . '%' ) );
        foreach ( (array) $part_names as $part_name ) {
            if ( preg_match( '/^' . preg_quote( self::PART_PREFIX, '/' ) . '([a-f0-9]{32})_\d+$/', (string) $part_name, $m ) && false === get_option( self::OPTION_PREFIX . $m[1], false ) ) {
                delete_option( (string) $part_name );
            }
        }
        if ( $seen !== $unreadable ) {
            if ( [] === $seen ) {
                delete_option( self::UNREADABLE_OPTION );
            } else {
                update_option( self::UNREADABLE_OPTION, $seen, false );
            }
        }
        return $deleted;
    }
}
