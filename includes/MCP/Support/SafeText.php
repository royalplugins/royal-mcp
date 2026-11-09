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
     * Text as a reader would take it: percent and entity encodings undone (layered ones too),
     * invisible and control characters dropped, full-width letters folded to ASCII. For comparing
     * a value against a known word; the result is never stored.
     *
     * @param mixed $text Input value.
     * @return string
     */
    public static function plain_spelling( $text ) : string {
        $text = is_scalar( $text ) ? (string) $text : '';
        for ( $i = 0; $i < 4; $i++ ) {
            $next = html_entity_decode( rawurldecode( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            if ( $next === $text ) {
                break;
            }
            $text = $next;
        }
        $text  = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
        $clean = preg_replace( '/[\x{00AD}\x{034F}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $text );
        if ( ! is_string( $clean ) ) {
            return $text; // Not valid UTF-8: only the byte-level steps apply.
        }
        return (string) preg_replace_callback(
            '/[\x{FF01}-\x{FF5E}]/u',
            static function ( $m ) {
                $b = $m[0];
                return chr( ( ( ( ord( $b[0] ) & 0x0F ) << 12 ) | ( ( ord( $b[1] ) & 0x3F ) << 6 ) | ( ord( $b[2] ) & 0x3F ) ) - 0xFEE0 );
            },
            $clean
        );
    }

    /**
     * HTML that WordPress itself would store for the current user: unchanged for users who may post
     * unfiltered HTML, through wp_kses_post() for everyone else. Filtering an administrator's text
     * up front turns block markup and JSON attributes into broken text.
     *
     * @param mixed $value Input value. Non-scalar → empty string.
     * @return string
     */
    public static function rich_html( $value ) : string {
        if ( is_object( $value ) || is_array( $value ) ) {
            return '';
        }
        $value = (string) $value;
        return current_user_can( 'unfiltered_html' ) ? $value : wp_kses_post( $value );
    }

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
