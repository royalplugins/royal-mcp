<?php
/**
 * Protocol_Insights — admin submenu rendering weekly rollups of inbound MCP
 * protocol-version + client-name + method signals collected by Protocol_Counter.
 *
 * Zero external calls: distribution charts are HTML tables with CSS width bars,
 * the 12-week history is inline SVG, no JS chart library required.
 *
 * JSON export delivers the current week's raw rollup as an attachment via
 * admin-post handler (nonce-gated).
 *
 * Cap: manage_options.
 *
 * @since 1.5.3
 */

namespace Royal_MCP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Protocol_Insights {

    const PAGE_SLUG      = 'royal-mcp-protocol-insights';
    const PARENT_SLUG    = 'royal-mcp';
    const CAPABILITY     = 'manage_options';
    const EXPORT_ACTION  = 'royal_mcp_protocol_insights_export';
    const EXPORT_NONCE   = 'royal_mcp_protocol_insights_export_nonce';
    const OPTION_PREFIX  = 'royal_mcp_protocol_counter_';
    const HISTORY_WEEKS  = 12;
    const TOP_CLIENTS    = 10;
    const TOP_METHODS    = 20;

    public static function register() {
        // Priority 12 slots the submenu between Pending Clients (11) and
        // Help (15) in the Royal MCP admin sidebar, so the data-viewing
        // pages (Activity Log, Pending Clients, Protocol Insights) group
        // together above the Help entry.
        add_action( 'admin_menu',                       array( __CLASS__, 'add_submenu' ), 12 );
        add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'handle_export' ) );
    }

    public static function add_submenu() {
        // Skip when Royal MCP Pro provides its own Protocol Insights page —
        // avoids a duplicate "Protocol Insights" entry in the absorbed Pro
        // menu. Pro's version supersedes ours under parallel activation.
        if ( class_exists( '\\Royal_MCP_Pro\\Admin\\Protocol_Insights', false ) ) {
            return;
        }
        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Protocol Insights', 'royal-mcp' ),
            __( 'Protocol Insights', 'royal-mcp' ),
            self::CAPABILITY,
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Discover all week-keyed rollup option rows by name, without loading the
     * value blobs. Returns keys sorted ascending (oldest week first). Public
     * for tests.
     */
    public static function discover_week_keys() {
        global $wpdb;
        $prefix = self::OPTION_PREFIX;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $names = $wpdb->get_col( $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC",
            $wpdb->esc_like( $prefix ) . '%'
        ) );
        return array_values( array_filter( (array) $names, static function ( $n ) use ( $prefix ) {
            return is_string( $n ) && strpos( $n, $prefix ) === 0;
        } ) );
    }

    /**
     * Extract ISO year-week suffix (YYYY-WW) from an option name so the
     * caller can sort or window without loading option values.
     */
    public static function week_id_from_key( $option_name ) {
        return substr( (string) $option_name, strlen( self::OPTION_PREFIX ) );
    }

    /**
     * Load the last N week rollups (most recent last), each with its week_id
     * attached. Non-existent rollups are skipped rather than backfilled — the
     * dashboard shows what actually happened, not what should have.
     */
    public static function load_recent_rollups( $limit = self::HISTORY_WEEKS ) {
        $keys = self::discover_week_keys();
        $keys = array_slice( $keys, -1 * max( 1, (int) $limit ) );
        $out = array();
        foreach ( $keys as $key ) {
            $rollup = get_option( $key );
            if ( ! is_array( $rollup ) ) {
                continue;
            }
            $rollup['week_id'] = self::week_id_from_key( $key );
            $out[] = $rollup;
        }
        return $out;
    }

    public static function current_week_key() {
        return self::OPTION_PREFIX . gmdate( 'o-W' );
    }

    /**
     * Build the render-ready view model. Pure — takes an array of rollups
     * and produces the shape the template needs. Testable without HTML.
     */
    public static function build_view_model( array $rollups ) {
        $current_key = self::current_week_key();
        $current     = null;
        foreach ( $rollups as $r ) {
            if ( self::OPTION_PREFIX . ( $r['week_id'] ?? '' ) === $current_key ) {
                $current = $r;
                break;
            }
        }
        if ( $current === null ) {
            $current = end( $rollups );
            if ( false === $current ) {
                $current = array();
            }
        }

        $current_pv = is_array( $current['protocol_version_counts'] ?? null ) ? $current['protocol_version_counts'] : array();
        $current_cn = is_array( $current['client_name_counts']       ?? null ) ? $current['client_name_counts']       : array();
        $current_mm = is_array( $current['method_counts']            ?? null ) ? $current['method_counts']            : array();
        $current_total = (int) ( $current['total_requests'] ?? 0 );
        $current_hdr_m = (int) ( $current['mcp_method_header_present'] ?? 0 );

        arsort( $current_pv );
        arsort( $current_cn );
        arsort( $current_mm );

        $header_pct = $current_total > 0 ? round( ( $current_hdr_m / $current_total ) * 100, 1 ) : 0.0;

        return array(
            'current_week_id'    => $current['week_id']    ?? '',
            'current_week_start' => $current['week_starting'] ?? '',
            'current_total'      => $current_total,
            'unique_clients'     => count( $current_cn ),
            'unique_versions'    => count( $current_pv ),
            'header_pct'         => $header_pct,
            'protocol_versions'  => $current_pv,
            'top_clients'        => array_slice( $current_cn, 0, self::TOP_CLIENTS, true ),
            'top_methods'        => array_slice( $current_mm, 0, self::TOP_METHODS, true ),
            'history'            => $rollups,
        );
    }

    public static function render_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'royal-mcp' ) );
        }

        $rollups = self::load_recent_rollups();
        $vm      = self::build_view_model( $rollups );
        $export_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=' . self::EXPORT_ACTION ),
            self::EXPORT_NONCE
        );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Protocol Insights', 'royal-mcp' ) . '</h1>';

        if ( empty( $rollups ) ) {
            echo '<p>' . esc_html__( 'No MCP traffic recorded yet. Rollups populate as MCP clients connect to this site.', 'royal-mcp' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<p style="color:#666;">' . sprintf(
            /* translators: %1$s = week id (YYYY-WW), %2$s = week starting YYYY-MM-DD */
            esc_html__( 'Showing week %1$s (starting %2$s).', 'royal-mcp' ),
            esc_html( $vm['current_week_id'] ),
            esc_html( $vm['current_week_start'] )
        ) . '</p>';

        self::render_cards( $vm );
        self::render_distribution( __( 'Protocol version distribution', 'royal-mcp' ), $vm['protocol_versions'] );
        self::render_distribution( __( 'MCP client distribution (top 10)', 'royal-mcp' ), $vm['top_clients'] );
        self::render_method_table( $vm['top_methods'], $vm['current_total'] );
        self::render_history_chart( $vm['history'] );

        echo '<p style="margin-top:24px;"><a href="' . esc_url( $export_url ) . '" class="button button-secondary">' . esc_html__( 'Download current week as JSON', 'royal-mcp' ) . '</a></p>';

        echo '</div>';
    }

    // -------------------------------------------------------------------
    // Rendering helpers — all pure (no external calls, no JS).
    // -------------------------------------------------------------------

    private static function render_cards( array $vm ) {
        $cards = array(
            array( 'label' => __( 'Requests this week', 'royal-mcp' ),       'value' => number_format_i18n( $vm['current_total'] ) ),
            array( 'label' => __( 'Unique clients', 'royal-mcp' ),           'value' => number_format_i18n( $vm['unique_clients'] ) ),
            array( 'label' => __( 'Unique protocol versions', 'royal-mcp' ), 'value' => number_format_i18n( $vm['unique_versions'] ) ),
            array( 'label' => __( 'Sending Mcp-* headers', 'royal-mcp' ),    'value' => $vm['header_pct'] . '%' ),
        );
        echo '<div style="display:flex;flex-wrap:wrap;gap:16px;margin:16px 0 24px;">';
        foreach ( $cards as $c ) {
            echo '<div style="flex:1 1 200px;min-width:180px;padding:16px 20px;background:#fff;border:1px solid #dcdcde;border-radius:4px;">';
            echo '<div style="font-size:28px;font-weight:600;line-height:1.1;">' . esc_html( $c['value'] ) . '</div>';
            echo '<div style="color:#646970;font-size:13px;margin-top:6px;">' . esc_html( $c['label'] ) . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    private static function render_distribution( $title, array $counts ) {
        echo '<h2>' . esc_html( $title ) . '</h2>';
        if ( empty( $counts ) ) {
            echo '<p style="color:#646970;">' . esc_html__( 'No data yet.', 'royal-mcp' ) . '</p>';
            return;
        }
        $max = max( $counts );
        echo '<table class="widefat striped" style="margin-bottom:24px;"><tbody>';
        foreach ( $counts as $label => $count ) {
            $pct = $max > 0 ? round( ( $count / $max ) * 100 ) : 0;
            echo '<tr>';
            echo '<td style="width:220px;font-family:monospace;">' . esc_html( (string) $label ) . '</td>';
            echo '<td><div style="background:#2271b1;height:16px;width:' . (int) $pct . '%;border-radius:2px;"></div></td>';
            echo '<td style="width:80px;text-align:right;font-variant-numeric:tabular-nums;">' . esc_html( number_format_i18n( $count ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_method_table( array $method_counts, $total ) {
        $total = (int) $total;
        echo '<h2>' . esc_html__( 'Method call frequency (top 20)', 'royal-mcp' ) . '</h2>';
        if ( empty( $method_counts ) ) {
            echo '<p style="color:#646970;">' . esc_html__( 'No data yet.', 'royal-mcp' ) . '</p>';
            return;
        }
        echo '<table class="widefat striped" style="margin-bottom:24px;"><thead><tr>';
        echo '<th>' . esc_html__( 'Method', 'royal-mcp' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( 'Count', 'royal-mcp' ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html__( '% of total', 'royal-mcp' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $method_counts as $method => $count ) {
            $pct = $total > 0 ? round( ( $count / $total ) * 100, 1 ) : 0;
            echo '<tr>';
            echo '<td style="font-family:monospace;">' . esc_html( (string) $method ) . '</td>';
            echo '<td style="text-align:right;font-variant-numeric:tabular-nums;">' . esc_html( number_format_i18n( $count ) ) . '</td>';
            echo '<td style="text-align:right;font-variant-numeric:tabular-nums;">' . esc_html( $pct . '%' ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Simple inline-SVG line chart of total_requests per week over the loaded
     * history window. No JS, no external font, one path per series.
     */
    private static function render_history_chart( array $rollups ) {
        echo '<h2>' . esc_html__( 'Weekly history', 'royal-mcp' ) . '</h2>';
        if ( count( $rollups ) < 2 ) {
            echo '<p style="color:#646970;">' . esc_html__( 'History becomes available after two or more weeks of data.', 'royal-mcp' ) . '</p>';
            return;
        }

        $totals = array_map( static function ( $r ) { return (int) ( $r['total_requests'] ?? 0 ); }, $rollups );
        $labels = array_map( static function ( $r ) { return (string) ( $r['week_id'] ?? '' ); }, $rollups );
        $max    = max( 1, max( $totals ) );

        $w = 720; $h = 200; $pad_l = 40; $pad_r = 12; $pad_t = 16; $pad_b = 32;
        $iw = $w - $pad_l - $pad_r; $ih = $h - $pad_t - $pad_b;
        $n  = count( $totals );
        $x_step = $n > 1 ? $iw / ( $n - 1 ) : 0;

        $points = array();
        foreach ( $totals as $i => $v ) {
            $x = $pad_l + $i * $x_step;
            $y = $pad_t + $ih - ( $v / $max ) * $ih;
            $points[] = sprintf( '%.1f,%.1f', $x, $y );
        }
        $path = 'M ' . implode( ' L ', $points );

        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:12px;overflow:auto;">';
        echo '<svg viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" style="width:100%;max-width:' . (int) $w . 'px;height:auto;font-family:sans-serif;font-size:11px;">';
        // Baseline + max-value horizontal rules
        echo '<line x1="' . (int) $pad_l . '" y1="' . (int) ( $pad_t + $ih ) . '" x2="' . (int) ( $pad_l + $iw ) . '" y2="' . (int) ( $pad_t + $ih ) . '" stroke="#dcdcde" stroke-width="1"/>';
        echo '<line x1="' . (int) $pad_l . '" y1="' . (int) $pad_t . '" x2="' . (int) ( $pad_l + $iw ) . '" y2="' . (int) $pad_t . '" stroke="#f0f0f1" stroke-width="1" stroke-dasharray="2,3"/>';
        // Y-axis labels
        echo '<text x="' . (int) ( $pad_l - 6 ) . '" y="' . (int) ( $pad_t + 4 ) . '" text-anchor="end" fill="#646970">' . esc_html( number_format_i18n( $max ) ) . '</text>';
        echo '<text x="' . (int) ( $pad_l - 6 ) . '" y="' . (int) ( $pad_t + $ih + 4 ) . '" text-anchor="end" fill="#646970">0</text>';
        // Series path
        echo '<path d="' . esc_attr( $path ) . '" fill="none" stroke="#2271b1" stroke-width="2"/>';
        // Data points
        foreach ( $points as $i => $pt ) {
            list( $px, $py ) = array_map( 'floatval', explode( ',', $pt ) );
            echo '<circle cx="' . (float) $px . '" cy="' . (float) $py . '" r="3" fill="#2271b1"/>';
        }
        // X-axis labels (every other label if crowded)
        $label_every = $n > 8 ? 2 : 1;
        foreach ( $labels as $i => $lbl ) {
            if ( $i % $label_every !== 0 && $i !== $n - 1 ) {
                continue;
            }
            $x = $pad_l + $i * $x_step;
            echo '<text x="' . (float) $x . '" y="' . (int) ( $pad_t + $ih + 16 ) . '" text-anchor="middle" fill="#646970">' . esc_html( $lbl ) . '</text>';
        }
        echo '</svg>';
        echo '</div>';
    }

    /**
     * Build the current week's export payload. Pure — no headers, no exit.
     * Public for unit testing. Callers that want the download-attachment
     * behavior use handle_export() which wraps this with headers + exit.
     */
    public static function build_export_payload() {
        $key    = self::current_week_key();
        $rollup = get_option( $key );
        if ( ! is_array( $rollup ) ) {
            $rollup = array();
        }
        $rollup['_export'] = array(
            'week_key'    => $key,
            'exported_at' => gmdate( 'c' ),
            'site_url'    => home_url(),
        );
        return $rollup;
    }

    /**
     * admin-post handler — emits current week's raw rollup as JSON attachment.
     * Cap + nonce gated.
     */
    public static function handle_export() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'royal-mcp' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( self::EXPORT_NONCE );

        $payload = self::build_export_payload();
        $key     = $payload['_export']['week_key'] ?? self::current_week_key();

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="royal-mcp-protocol-insights-' . self::week_id_from_key( $key ) . '.json"' );
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }
}
