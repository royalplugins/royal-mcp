<?php
/**
 * MCP tool-response envelope helpers — canonical `tools/call` shape per
 * the MCP 2025-11-25 spec.
 *
 * Every tool that returns via this envelope produces:
 *
 *   [
 *     'isError'           => false | true,          // bool — machine-friendly success flag
 *     'content'           => [ [ 'type' => 'text', 'text' => 'human summary' ] ],
 *     'structuredContent' => [ ...machine-parseable fields... ],
 *     'undo'              => [ ... optional undo envelope ... ],  // omitted when absent
 *   ]
 *
 * Free's Server::handle_tools_call detects the envelope shape (presence of
 * the `isError` key at the top of the tool return) and passes it through
 * unwrapped. Legacy tools that return flat arrays continue to be JSON-encoded
 * into a single text block via the fallback path — no back-compat break.
 *
 * Pro's Tool_Registry intercepts Pro tools BEFORE Free's dispatcher and
 * already understands the envelope. Both plugins converge on the same shape
 * with this helper.
 *
 * Usage:
 *
 *     use Royal_MCP\MCP\Support\Envelope;
 *
 *     // Success (with structured data + optional undo)
 *     return Envelope::success(
 *         sprintf('Updated %s on post %d.', $meta_key, $post_id),
 *         [ 'post_id' => $post_id, 'meta_key' => $meta_key, 'saved_fields' => [...] ],
 *         $undo_envelope  // omit or pass null for tools without undo
 *     );
 *
 *     // Error (code + human message + optional extra machine fields)
 *     return Envelope::error(
 *         'invalid_args',
 *         'post_id must be a positive integer.',
 *         [ 'received' => $args['post_id'] ?? null ]
 *     );
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Envelope {

    /**
     * Build a success envelope.
     *
     * @param string     $summary  Human-readable summary. Rendered by MCP clients
     *                             as the chat-visible text output of the tool call.
     *                             Keep concise + specific (include IDs, counts,
     *                             file paths, etc. so agents can quote it directly).
     * @param array      $struct   Machine-parseable fields. Sits in structuredContent
     *                             for downstream tool chaining.
     * @param array|null $undo     Optional undo envelope from Undo_Store::store().
     *                             Passed through at top level. Omitted from result
     *                             when null or empty.
     * @return array MCP-canonical tool-response envelope.
     */
    public static function success( string $summary, array $struct = [], ?array $undo = null ) : array {
        $has_undo = is_array( $undo ) && ! empty( $undo['token'] );
        if ( $has_undo ) {
            // Mirror undo into structuredContent so spec-forward MCP clients
            // that read the full envelope can consume the token programmatically.
            $struct = array_merge(
                $struct,
                [
                    'undo_available'  => true,
                    'undo_token'      => (string) $undo['token'],
                    'undo_expires_at' => isset( $undo['expires_at'] ) ? (int) $undo['expires_at'] : null,
                    'undo_ttl_hours'  => isset( $undo['ttl_hours'] ) ? (int) $undo['ttl_hours'] : null,
                    'undo_summary'    => isset( $undo['summary'] ) ? (string) $undo['summary'] : '',
                ]
            );
            // Also surface the token INTO the human-readable summary text.
            // Most MCP clients (Claude Desktop, ChatGPT connectors, Cursor)
            // inject content[0].text into the model context but do NOT inject
            // structuredContent — so any LLM operator that needs to invoke
            // mcp_undo_last_operation must have the token value visible in
            // the tool response text block, not just on the wire envelope.
            $summary = rtrim( $summary, ". \t\r\n" )
                . '. Undo token: ' . (string) $undo['token']
                . ' (72h, pass to mcp_undo_last_operation to reverse).';
        }
        $out = [
            'isError'           => false,
            'content'           => [ [ 'type' => 'text', 'text' => $summary ] ],
            'structuredContent' => $struct,
        ];
        if ( $has_undo ) {
            $out['undo'] = $undo;
        }
        return $out;
    }

    /**
     * Build an error envelope.
     *
     * @param string $code    Machine-friendly error code. Snake_case; grouped so
     *                        clients can retry-classify. Common codes:
     *                        insufficient_caps, invalid_args, not_found,
     *                        silent_drop, conflict, host_layer_blocked.
     * @param string $message Human-readable message. Rendered as the tool's
     *                        chat-visible error text. Prefix with code for
     *                        symmetry with structured field.
     * @param array  $extra   Optional additional machine fields merged into
     *                        structuredContent alongside error + message.
     * @return array MCP-canonical tool-response error envelope.
     */
    public static function error( string $code, string $message, array $extra = [] ) : array {
        return [
            'isError'           => true,
            'content'           => [ [ 'type' => 'text', 'text' => sprintf( '%s: %s', $code, $message ) ] ],
            'structuredContent' => array_merge( [ 'error' => $code, 'message' => $message ], $extra ),
        ];
    }

    /**
     * Detect whether a value is already a pre-formed envelope. Used by Free's
     * dispatcher to decide passthrough vs legacy JSON-encoding.
     *
     * @param mixed $value
     * @return bool True when $value has the required envelope keys.
     */
    public static function is_envelope( $value ) : bool {
        return is_array( $value )
            && array_key_exists( 'isError', $value )
            && isset( $value['content'] )
            && is_array( $value['content'] );
    }

    /**
     * Append a full JSON code fence containing structuredContent (merged with
     * envelope-level undo) to content[0].text. Idempotent — skipped if the
     * marker is already present.
     *
     * Opt-in. Free tool responses default to prose-only summaries; callers that
     * want the JSON-fence dump call this method after building the envelope.
     * Pro's Tool_Handlers::mirror_structured_to_text() middleware wraps every
     * Pro tool response via this method so Pro-native tools uniformly emit
     * the JSON fence.
     *
     * Also promotes envelope-level `undo` into `structuredContent.undo` so
     * clients that parse structuredContent receive the token in the same
     * shape as the text mirror.
     *
     * Contract:
     *   - Only mutates success envelopes (isError !== true).
     *   - No-op when structuredContent is empty AND envelope has no undo.
     *   - Idempotent: skipped if content[0].text already contains the marker.
     *   - Uses '--- royal-mcp payload ---' as the marker (unified with Pro's
     *     historical '--- royal-mcp-pro payload ---' — both variants are
     *     recognized on read so pre-existing Pro responses don't double-inject).
     *
     * @param array  $result The MCP tool-response envelope to mirror.
     * @param string $marker Marker line prefixing the JSON fence. Callers can
     *                       override to preserve legacy marker text (Pro uses
     *                       '--- royal-mcp-pro payload ---' historically).
     * @return array The (possibly-mutated) envelope.
     */
    public static function apply_full_json_mirror( $result, string $marker = '--- royal-mcp payload ---' ) : array {
        if ( ! is_array( $result ) ) {
            return [];
        }
        if ( isset( $result['isError'] ) && $result['isError'] === true ) {
            return $result;
        }
        $struct = isset( $result['structuredContent'] ) && is_array( $result['structuredContent'] )
            ? $result['structuredContent']
            : [];
        $undo = isset( $result['undo'] ) && is_array( $result['undo'] )
            ? $result['undo']
            : null;
        if ( empty( $struct ) && $undo === null ) {
            return $result;
        }

        // Promote envelope-level undo into structuredContent for structural parity.
        if ( $undo !== null ) {
            if ( ! isset( $result['structuredContent'] ) || ! is_array( $result['structuredContent'] ) ) {
                $result['structuredContent'] = [];
            }
            if ( ! isset( $result['structuredContent']['undo'] ) ) {
                $result['structuredContent']['undo'] = $undo;
            }
            $struct = $result['structuredContent'];
        }

        $payload = $struct;
        if ( $undo !== null && ! isset( $payload['undo'] ) ) {
            $payload['undo'] = $undo;
        }

        $existing_text = '';
        if ( isset( $result['content'][0]['text'] ) ) {
            $existing_text = (string) $result['content'][0]['text'];
        }

        // Idempotent — recognize both current unified marker and historical Pro-only marker.
        if ( strpos( $existing_text, $marker ) !== false
            || strpos( $existing_text, '--- royal-mcp payload ---' ) !== false
            || strpos( $existing_text, '--- royal-mcp-pro payload ---' ) !== false ) {
            return $result;
        }

        $json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( $json === false ) {
            return $result;
        }

        $appended = $existing_text
            . ( $existing_text === '' ? '' : "\n\n" )
            . $marker . "\n"
            . "```json\n" . $json . "\n```";

        if ( ! isset( $result['content'] ) || ! is_array( $result['content'] ) || empty( $result['content'] ) ) {
            $result['content'] = [ [ 'type' => 'text', 'text' => $appended ] ];
        } else {
            $result['content'][0]['type'] = 'text';
            $result['content'][0]['text'] = $appended;
        }

        return $result;
    }
}
