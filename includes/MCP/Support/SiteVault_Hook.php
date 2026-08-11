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

    /**
     * Fire the pre-op hook for a destructive tool call.
     *
     * Wraps do_action in try/catch so any listener bug can't break the
     * write path. Failed listeners get logged when WP_DEBUG is on.
     *
     * @param string $tool_name The MCP tool name about to execute.
     * @param array  $args      The tool's raw args (post_id, term_id, etc).
     * @return void
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
