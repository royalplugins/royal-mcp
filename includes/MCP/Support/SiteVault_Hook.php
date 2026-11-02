<?php
/**
 * SiteVault pre-op backup hook — canonical fire-point for the
 * `royal_mcp_before_destructive_write` action documented in INVARIANTS §3.
 *
 * Fired once per destructive tool call, BEFORE the mutation runs. Listeners
 * (SiteVault Pro, third-party backup plugins, audit loggers) can queue a
 * snapshot / log the intent. The fire is best-effort insurance:
 *
 *   - Non-blocking. Any listener exception is caught and logged (via
 *     error_log at WP_DEBUG) but never bubbles up to abort the write.
 *   - Fire-and-forget. Return value is ignored.
 *   - No snapshot data passed — listeners inspect current state themselves
 *     via WP APIs. Tool-name + args are enough context for a listener to
 *     decide whether to snapshot.
 *
 * NOT a substitute for the per-tool undo tokens. Undo tokens are
 * operation-level reversibility; this hook is a file/DB-level safety net
 * for catastrophic or non-tool-reversible ops (uploads, cache flushes,
 * cross-plugin composites).
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SiteVault_Hook {

    /** @var array|null [tool name, args] while a write tool is running and has not written yet. */
    private static $armed = null;

    /** @var callable|null The query filter that fires the hook. */
    private static $watch = null;

    /** Tools that change files, not the database: the hook fires for them straight away. */
    const FIRE_AT_START = [ 'fc_clear_cache', 'fc_purge_url' ];

    /**
     * Fire the hook just before the running tool's first database write, so a call the
     * tool refuses (permission, not found, invalid input) never starts backup work.
     * Writes to Royal MCP's own tables and to transients do not count. The outermost
     * tool owns the watch: a tool it calls does not re-arm it under its own name.
     */
    public static function arm( string $tool_name, array $args ): void {
        if ( null !== self::$armed || ! has_action( 'royal_mcp_before_destructive_write' ) ) {
            return;
        }
        if ( in_array( $tool_name, self::FIRE_AT_START, true ) ) {
            self::maybe_fire( $tool_name, $args );
            return;
        }
        self::$armed = [ $tool_name, $args ];
        self::$watch = static function ( $query ) {
            if ( null !== self::$armed && is_string( $query ) && self::is_counted_write( $query ) ) {
                list( $tool, $tool_args ) = self::$armed;
                self::disarm();
                self::maybe_fire( $tool, $tool_args );
            }
            return $query;
        };
        add_filter( 'query', self::$watch, 1 );
    }

    /**
     * A write the hook waits for: judged by the table the statement writes to (and, for the
     * options table, the option name), never by the values it carries.
     */
    private static function is_counted_write( string $query ): bool {
        if ( ! preg_match( '/^\s*(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM|TRUNCATE(?:\s+TABLE)?|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|ALTER\s+TABLE)\s+`?([A-Za-z0-9_$]+)`?/i', $query, $m ) ) {
            return false;
        }
        $table = strtolower( $m[1] );
        if ( false !== strpos( $table, 'royal_mcp_' ) ) {
            return false;
        }
        global $wpdb;
        $transient_tables = array_filter( [ $wpdb->options ?? '', is_multisite() ? ( $wpdb->sitemeta ?? '' ) : '' ] );
        if ( in_array( $table, array_map( 'strtolower', $transient_tables ), true )
            && preg_match( "/(?:option_name|meta_key)`?\s*=\s*'_(?:site_)?transient_|VALUES\s*\(\s*(?:\d+\s*,\s*)?'_(?:site_)?transient_/i", $query ) ) {
            return false;
        }
        return true;
    }

    /**
     * Stop watching. With a tool name, only when that tool armed the watch (the call that
     * armed it has finished); without one, unconditionally.
     */
    public static function disarm( ?string $tool_name = null ): void {
        if ( null !== $tool_name && ( null === self::$armed || self::$armed[0] !== $tool_name ) ) {
            return;
        }
        if ( null !== self::$watch ) {
            remove_filter( 'query', self::$watch, 1 );
        }
        self::$watch = null;
        self::$armed = null;
    }

    /**
     * Fire royal_mcp_before_destructive_write now, if anything listens. Non-blocking: a
     * listener that throws is logged (with WP_DEBUG) and the write goes on.
     */
    public static function maybe_fire( string $tool_name, array $args ): void {
        // Only fire if at least one listener is registered — skips the
        // do_action + try/catch overhead when SiteVault isn't active.
        if ( ! has_action( 'royal_mcp_before_destructive_write' ) ) {
            return;
        }
        try {
            /**
             * Fires immediately before a destructive Royal MCP tool executes.
             *
             * Non-blocking. Failed listeners are caught + logged. Do NOT rely
             * on this hook to prevent writes — use per-tool capability gates
             * instead. Do NOT use return values or throw exceptions from
             * listeners; both are ignored by the caller.
             *
             * @param string $tool_name  Name of the tool about to execute
             *                            (e.g., 'wp_delete_post', 'wc_update_order_status').
             * @param array  $args       Raw arguments passed to the tool. Contains
             *                            enough context (post_id / term_id / order_id)
             *                            for a listener to snapshot the target.
             */
            do_action( 'royal_mcp_before_destructive_write', $tool_name, $args );
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[Royal MCP] SiteVault_Hook listener failed for tool "%s": %s',
                    $tool_name,
                    $e->getMessage()
                ) );
            }
            // swallow — write path must continue regardless
        }
    }
}
