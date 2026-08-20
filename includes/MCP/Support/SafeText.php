<?php
/**
 * SafeText — sanitize_text_field() minus the %XX percent-encoded strip.
 *
 * WordPress core's sanitize_text_field() runs a `/%[a-f0-9]{2}/i` strip loop
 * that removes any two-hex-char percent sequence from the input. That loop
 * corrupts inputs that legitimately contain `%<token>%` patterns or URL-
 * encoded content:
 *
 *   - Permalink structures: `%category%` → `tegory%` (%ca is a hex-hex pair)
 *   - User-authored titles: "My %category% archive" → "My tegory% archive"
 *   - Search terms with URL-encoding: "hello%20world" → "helloworld"
 *   - Media alt-text describing encoded values: "The %20 is a space" → "The  is a space"
 *
 * `SafeText::field()` reproduces sanitize_text_field()'s other behaviors
 * verbatim — UTF-8 check, tag stripping, whitespace collapse, trim — but
 * skips the percent-strip loop. Use in place of sanitize_text_field()
 * whenever the input can legitimately contain `%XX` sequences (user-authored
 * text: titles, alt text, captions, search queries, term names, some meta
 * keys, arbitrary option names). Do NOT use for pure identifiers
 * (post_type, taxonomy slug, status enum) — those never contain `%XX` and
 * the stock function is fine.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SafeText {

    /**
     * Sanitize a text field while preserving percent-encoded sequences.
     *
     * Mirrors the body of WordPress core's `_sanitize_text_fields()` minus
     * the `/%[a-f0-9]{2}/i` strip loop.
     *
     * @param mixed $str  Input value. Non-string, non-scalar → empty string.
     * @return string
     */
    public static function field( $str ) : string {
        if ( is_object( $str ) || is_array( $str ) ) {
            return '';
        }
        $str = (string) $str;
        $filtered = wp_check_invalid_utf8( $str );

        if ( strpos( $filtered, '<' ) !== false ) {
            $filtered = wp_pre_kses_less_than( $filtered );
            // wp_strip_all_tags collapses embedded script/style content — same
            // behavior sanitize_text_field relies on.
            $filtered = wp_strip_all_tags( $filtered, false );
            $filtered = str_replace( "<\n", "&lt;\n", $filtered );
        }

        // Collapse whitespace runs (spaces/tabs/newlines/CR) into a single space.
        $filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
        $filtered = trim( $filtered );

        return $filtered;
    }
}
