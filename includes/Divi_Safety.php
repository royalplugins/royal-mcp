<?php
/**
 * Royal MCP — Divi_Safety
 *
 * Per-post Divi builder-version detection helper.
 *
 * The core insight: `_et_builder_version` postmeta records the builder
 * version at LAST SAVE for that specific post — decoupled from the
 * currently-active theme version. Common real-world state on aged sites:
 *   `_et_builder_version = "BB|Divi|4.5.1"` on a post inside a `4.27.7`
 *   theme install (theme updated many times without the page being re-saved).
 *
 * ANY tool that infers format from theme version instead of postmeta will
 * corrupt content on the majority of aged sites. This helper is the
 * canonical read.
 *
 * Detection order:
 *   1. `_et_builder_version` postmeta → format + version + source='postmeta'
 *      Falls back to legacy `_et_pb_builder_version` key so posts written
 *      under older Divi meta conventions, or by our own
 *      assert_divi_meta_flags, still resolve.
 *   2. Content-shape scan (has_block + shortcode string search) → source='content_scan'
 *   3. Never `ET_BUILDER_VERSION` constant — that's the *theme's* current
 *      version, not the post's authorship version.
 */

namespace Royal_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Divi_Safety {

    const META_KEY        = '_et_builder_version';        // modern (Divi 4.5+) — stores "BB|Divi|4.5.1"
    const META_KEY_LEGACY = '_et_pb_builder_version';     // older key still written by Divi + by our own assert_divi_meta_flags

    /**
     * @param int $post_id
     * @return array {
     *   @type string      $format          'divi_4_shortcodes' | 'divi_5_blocks' | 'mixed' | 'not_divi' | 'unknown'
     *   @type string|null $version         e.g. "4.5.1", "5.9.0"
     *   @type string|null $source          'postmeta' | 'content_scan' | null (post not found)
     *   @type int|null    $gap_from_theme  major-version gap (theme - post). null when theme version unknown.
     *   @type string|null $meta_raw        raw meta value ("BB|Divi|4.5.1") for observability
     *   @type string|null $meta_key_used   which postmeta key produced the version, or null when content-scan
     *   @type string|null $theme_version   currently-active theme version, or null when Divi not installed
     * }
     */
    public static function get_post_builder_version( $post_id ) {
        $post_id = (int) $post_id;
        $theme_version = defined( 'ET_BUILDER_VERSION' ) ? (string) constant( 'ET_BUILDER_VERSION' ) : null;
        $empty = [
            'format'         => 'not_divi',
            'version'        => null,
            'source'         => null,
            'gap_from_theme' => null,
            'meta_raw'       => null,
            'meta_key_used'  => null,
            'theme_version'  => $theme_version,
        ];
        if ( $post_id <= 0 ) return $empty;
        $post = get_post( $post_id );
        if ( ! $post ) return $empty;

        // The version comes from a stamp on the page: Divi's own first, then the
        // older one, which tools that only touched the page write as well.
        $stamp = null;
        foreach ( [ self::META_KEY, self::META_KEY_LEGACY ] as $key ) {
            $raw = get_post_meta( $post_id, $key, true );
            if ( ! is_string( $raw ) || $raw === '' ) continue;
            $version = self::parse_version_from_meta( $raw );
            if ( $version === null ) continue;
            $stamp = [ 'version' => $version, 'raw' => $raw, 'key' => $key ];
            break;
        }
        $with_stamp = static function ( $format, $source ) use ( $stamp, $theme_version ) {
            return [
                'format'         => $format,
                'version'        => $stamp ? $stamp['version'] : null,
                'source'         => $source,
                'gap_from_theme' => $stamp ? self::compute_gap( $stamp['version'], $theme_version ) : null,
                'meta_raw'       => $stamp ? $stamp['raw'] : null,
                'meta_key_used'  => $stamp ? $stamp['key'] : null,
                'theme_version'  => $theme_version,
            ];
        };

        // The format is what the page holds. A stamp can be out of date (a page
        // converted without being saved again) or written by another tool, and
        // the tools that change a page must walk the markup that is really there.
        $content = (string) $post->post_content;
        $has_d5  = strpos( $content, '<!-- wp:divi/' ) !== false;
        $has_d4  = strpos( $content, '[et_pb_section' ) !== false
            || strpos( $content, '[et_pb_row' ) !== false
            || preg_match( '/\[et_pb_[a-z0-9_]+[\s\]]/', $content ) === 1;
        if ( $has_d5 && $has_d4 ) return $with_stamp( 'mixed', 'content_scan' );
        if ( $has_d5 )            return $with_stamp( 'divi_5_blocks', 'content_scan' );
        if ( $has_d4 )            return $with_stamp( 'divi_4_shortcodes', 'content_scan' );

        // Content of its own with no Divi markup in it is not a Divi page, whatever a stamp says.
        if ( '' !== trim( $content ) ) {
            return $empty;
        }

        // An empty page: the stamp says which Divi it was made for; without one, the builder flag.
        if ( $stamp ) {
            return $with_stamp( self::classify_version( $stamp['version'] ), 'postmeta' );
        }
        if ( get_post_meta( $post_id, '_et_pb_use_builder', true ) === 'on' ) {
            return $with_stamp( 'divi_4_shortcodes', 'content_scan' );
        }
        return $with_stamp( 'not_divi', null );
    }

    /**
     * Parse a `_et_builder_version` meta value into a bare version string.
     * Divi stores as "BB|Divi|4.5.1" or "VB|Divi|5.9.0" (BB=Backend Builder,
     * VB=Visual Builder). Historical / third-party formats sometimes ship
     * just the version. We defensively look for the last dotted-number
     * segment.
     */
    public static function parse_version_from_meta( $raw ) {
        $raw = trim( (string) $raw );
        if ( $raw === '' ) return null;
        // Pipe-separated → take last segment
        if ( strpos( $raw, '|' ) !== false ) {
            $parts = explode( '|', $raw );
            $raw = trim( end( $parts ) );
        }
        // Reject if it doesn't look like a version (accept N or N.N or N.N.N etc.)
        if ( ! preg_match( '/^\d+(\.\d+){0,3}$/', $raw ) ) return null;
        return $raw;
    }

    /**
     * Map a version string to a format label.
     */
    public static function classify_version( $version ) {
        if ( $version === null || $version === '' ) return 'unknown';
        if ( version_compare( $version, '5.0.0', '>=' ) ) return 'divi_5_blocks';
        if ( version_compare( $version, '4.0.0', '>=' ) ) return 'divi_4_shortcodes';
        return 'unknown';
    }

    /**
     * Compute major-version gap between the post's authored version and
     * the currently-active theme. Positive when the theme is newer than
     * the post's saved version (the "aged content on updated theme" case
     * that motivated this helper). null when either input is unknown.
     */
    public static function compute_gap( $post_version, $theme_version ) {
        if ( ! is_string( $post_version ) || ! is_string( $theme_version ) ) return null;
        if ( $post_version === '' || $theme_version === '' ) return null;
        $post_major  = (int) explode( '.', $post_version )[0];
        $theme_major = (int) explode( '.', $theme_version )[0];
        return $theme_major - $post_major;
    }

    /**
     * Full storage picture for a Divi post across the three places Divi
     * keeps state: post_content, postmeta, and the filesystem cache.
     *
     * Standard REST probes only look at post_content — a post can have
     * missing postmeta flags (renders as raw shortcode) or leftover cache
     * files (serves stale CSS) while post_content looks correct. This
     * helper returns the full state so callers can catch state drift that
     * a content-only probe would miss.
     */
    public static function get_post_state( $post_id ) {
        $post_id = (int) $post_id;
        $state   = [
            'post_id'            => $post_id,
            'postmeta_keys'      => [],
            'cache_files'        => [],
            'builder_version'    => [ 'format' => 'not_divi', 'version' => null, 'source' => null, 'gap_from_theme' => null, 'meta_raw' => null, 'meta_key_used' => null, 'theme_version' => null ],
            'shortcodes_present' => false,
            'blocks_present'     => false,
        ];

        if ( $post_id <= 0 ) return $state;

        $all_meta = get_post_meta( $post_id );
        if ( is_array( $all_meta ) ) {
            foreach ( array_keys( $all_meta ) as $key ) {
                if ( is_string( $key ) && 0 === strpos( $key, '_et' ) ) {
                    $state['postmeta_keys'][] = $key;
                }
            }
            sort( $state['postmeta_keys'] );
        }

        if ( defined( 'WP_CONTENT_DIR' ) ) {
            $cache_dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/et/' . $post_id;
            if ( is_dir( $cache_dir ) ) {
                $files = glob( trailingslashit( $cache_dir ) . '*' );
                if ( is_array( $files ) ) {
                    foreach ( $files as $file ) {
                        $state['cache_files'][] = basename( $file );
                    }
                    sort( $state['cache_files'] );
                }
            }
        }

        $state['builder_version'] = self::get_post_builder_version( $post_id );

        $post = get_post( $post_id );
        if ( $post instanceof \WP_Post ) {
            $content = (string) $post->post_content;
            $state['shortcodes_present'] = ( false !== strpos( $content, '[et_pb_section' ) )
                || ( false !== strpos( $content, '[et_pb_row' ) )
                || ( preg_match( '/\[et_pb_[a-z0-9_]+[\s\]]/', $content ) === 1 );
            $state['blocks_present']     = ( false !== strpos( $content, '<!-- wp:divi/' ) );
        }

        return $state;
    }

    /**
     * Attach builder version metadata to a read/write response envelope.
     * Adds:
     *   builder_version_at_save  (string|null)
     *   current_theme_version    (string|null)
     *   gap_signal               (int|null — post_major → theme_major diff)
     *   version_source           ('postmeta'|'content_scan'|null)
     */
    public static function annotate_response( array $envelope, $post_id ) {
        $info = self::get_post_builder_version( (int) $post_id );
        if ( ! isset( $envelope['structuredContent'] ) || ! is_array( $envelope['structuredContent'] ) ) {
            $envelope['structuredContent'] = [];
        }
        $envelope['structuredContent']['builder_version_at_save'] = $info['version'];
        $envelope['structuredContent']['current_theme_version']   = $info['theme_version'];
        $envelope['structuredContent']['gap_signal']              = $info['gap_from_theme'];
        $envelope['structuredContent']['version_source']          = $info['source'];
        return $envelope;
    }
}
