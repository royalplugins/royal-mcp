<?php
/**
 * WriteVerifier — canonical read-after-write helper.
 *
 * Encapsulates the diff-and-report pattern that every write tool must
 * follow post-write to catch silent-drop / silent-modify / silent-fail
 * failure classes.
 *
 * Usage pattern (in a write handler):
 *
 *     use Royal_MCP\MCP\Support\WriteVerifier;
 *
 *     // Phase 1: extract intended field values from $args
 *     $requested = [ 'title' => sanitize_text_field($args['title']),
 *                    'menu_order' => (int) $args['menu_order'] ];
 *
 *     // Phase 2: snapshot BEFORE-state so we can distinguish drop vs modify
 *     $before = [ 'title' => $post->post_title, 'menu_order' => (int) $post->menu_order ];
 *
 *     // Phase 3: execute the WP write (per-tool logic)
 *     wp_update_post([ 'ID' => $id, 'post_title' => $requested['title'], ... ]);
 *     clean_post_cache($id);
 *
 *     // Phase 4: re-read AFTER-state fresh (cache invalidated above)
 *     $post_fresh = get_post($id);
 *     $actual = [ 'title' => $post_fresh->post_title, 'menu_order' => (int) $post_fresh->menu_order ];
 *
 *     // Phase 5: diff → throw on silent-drop, otherwise merge modified_by_wp
 *     //         info into the response envelope
 *     $diff = WriteVerifier::diff($requested, $before, $actual);
 *     WriteVerifier::throw_if_dropped($diff, 'wp_update_post');
 *     return [
 *         'id' => $id,
 *         ...WriteVerifier::response_partial($diff),
 *     ];
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WriteVerifier {

    /**
     * Categorize the outcome of a write by comparing requested / before / actual.
     *
     * @param array $requested Intended values keyed by field. Sanitized + type-coerced.
     * @param array $before    Prior values keyed by same field names (pre-write snapshot).
     *                         Types must match $requested for comparison to be meaningful
     *                         (e.g. cast both to int for numeric fields).
     * @param array $actual    Post-write re-read values keyed by same field names.
     * @return array {
     *     silent_drops:    field => ['requested' => $r, 'actual' => $a]
     *                      Write did not stick — actual === before, requested !== before.
     *     silent_modifies: field => ['requested' => $r, 'actual' => $a]
     *                      Write partially applied — actual !== before AND actual !== requested.
     *                      Common causes: slug uniqueness suffixing, WP sanitization, filter
     *                      transforms. Informational — caller may still want to know.
     *     applied:         field => actual
     *                      Write took effect as requested — actual === requested.
     * }
     */
    public static function diff( array $requested, array $before, array $actual ) : array {
        $silent_drops    = [];
        $silent_modifies = [];
        $applied         = [];

        foreach ( $requested as $field => $intended ) {
            $before_val = $before[ $field ] ?? null;
            $actual_val = $actual[ $field ] ?? null;

            if ( $actual_val === $intended ) {
                // Write applied as requested.
                $applied[ $field ] = $actual_val;
            } elseif ( $actual_val === $before_val && $intended !== $before_val ) {
                // Value didn't move — write silently dropped.
                $silent_drops[ $field ] = [
                    'requested' => $intended,
                    'actual'    => $actual_val,
                ];
            } else {
                // Value moved but not to what we asked for — WP modified.
                $silent_modifies[ $field ] = [
                    'requested' => $intended,
                    'actual'    => $actual_val,
                ];
            }
        }

        return [
            'silent_drops'    => $silent_drops,
            'silent_modifies' => $silent_modifies,
            'applied'         => $applied,
        ];
    }

    /**
     * Throw an Exception if the diff reports any silent-drops.
     *
     * Error message includes the tool name (if provided) plus the list of
     * dropped fields with their requested-vs-actual values so LLM clients
     * see enough context to decide whether to retry, escalate, or abandon.
     *
     * @param array  $diff      Output from diff() above.
     * @param string $tool_name Optional tool identifier for the error message.
     * @throws \Exception When silent_drops is non-empty.
     */
    public static function throw_if_dropped( array $diff, string $tool_name = '' ) : void {
        if ( empty( $diff['silent_drops'] ) ) {
            return;
        }
        $dropped = [];
        foreach ( $diff['silent_drops'] as $field => $info ) {
            $dropped[] = sprintf(
                '%s (requested %s, actual %s)',
                $field,
                self::stringify( $info['requested'] ),
                self::stringify( $info['actual'] )
            );
        }
        $prefix = $tool_name !== '' ? "$tool_name: " : '';
        throw new \Exception( esc_html( sprintf(
            '%sWrite reported success but %d field(s) were silently dropped: %s. Confirm the field is accepted by this tool\'s schema and re-issue the write, or file a bug if the schema claims support.',
            $prefix,
            count( $diff['silent_drops'] ),
            implode( '; ', $dropped )
        ) ) );
    }

    /**
     * Return a partial response envelope carrying the saved-fields and
     * modified_by_wp diff. Merge into the tool's own response array.
     *
     * If silent_drops is non-empty this method still returns partial data —
     * callers should invoke throw_if_dropped() first if silent-drop should
     * abort the response.
     *
     * @param array $diff Output from diff() above.
     * @return array {
     *     saved_fields:   field => actual (both applied + modified rows)
     *     modified_by_wp: field => [requested, actual]  (only present when non-empty)
     * }
     */
    public static function response_partial( array $diff ) : array {
        $saved = $diff['applied'];
        foreach ( $diff['silent_modifies'] as $field => $info ) {
            $saved[ $field ] = $info['actual'];
        }
        $out = [ 'saved_fields' => $saved ];
        if ( ! empty( $diff['silent_modifies'] ) ) {
            $out['modified_by_wp'] = $diff['silent_modifies'];
        }
        return $out;
    }

    /**
     * Stringify a value for inclusion in a human-readable error message.
     * Handles scalars, null, arrays; truncates long strings.
     */
    private static function stringify( $value ) : string {
        if ( $value === null ) {
            return 'null';
        }
        if ( is_bool( $value ) ) {
            return $value ? 'true' : 'false';
        }
        if ( is_scalar( $value ) ) {
            $s = (string) $value;
            return strlen( $s ) > 80 ? substr( $s, 0, 77 ) . '...' : $s;
        }
        return wp_json_encode( $value );
    }
}
