<?php
namespace Royal_MCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fixed-window request counters.
 *
 * Each bucket is named by what it counts (an address, a client, a user) so
 * anonymous traffic, failed sign-ins and signed-in clients never share one
 * budget. Counting is atomic: with a persistent object cache it uses
 * wp_cache_incr(), otherwise one options row per bucket updated by a single
 * INSERT ... ON DUPLICATE KEY UPDATE (value "window-start:count"). Rows of
 * buckets idle for an hour are swept. The Connection Doctor's own probe is
 * never counted or refused.
 */
class Rate_Limit {

    /** Options-row prefix of the counters. */
    const PREFIX = 'royal_mcp_ratectr_';

    /** Object-cache group of the counters. */
    const GROUP = 'royal_mcp_rate';

    /**
     * Default budgets per window, by bucket kind. Filter with royal_mcp_oauth_rate_limit.
     * discovery: metadata documents, per address.
     * authorize: sign-in pages, per address (anonymous) or per user (signed in).
     * token_ok: tokens issued, per client and user.
     * token_client: tokens issued, per client across all its users.
     * failure: refused token and revocation requests, per address.
     */
    const LIMITS = [
        'discovery'    => 120,
        'authorize'    => 30,
        'token_ok'     => 30,
        'token_client' => 600,
        'failure'      => 20,
    ];

    /** Window length in seconds. */
    const WINDOW = 60;

    /** Rows whose window ended this long ago are swept. */
    const STALE_AFTER = 3600;

    /**
     * The budget of a bucket kind.
     *
     * @param string $kind One of the LIMITS keys.
     * @return array{0:int,1:int} [ max requests, window seconds ].
     */
    public static function budget( $kind ) {
        $default = isset( self::LIMITS[ $kind ] ) ? self::LIMITS[ $kind ] : 60;
        /**
         * Filter how many requests one OAuth bucket may make per window.
         *
         * @param int    $max  Requests per window.
         * @param string $kind discovery | authorize | token_ok | token_client | failure.
         */
        $max = (int) apply_filters( 'royal_mcp_oauth_rate_limit', $default, $kind );
        /**
         * Filter the OAuth rate-limit window in seconds.
         *
         * @param int    $window Seconds.
         * @param string $kind   Bucket kind.
         */
        $window = (int) apply_filters( 'royal_mcp_oauth_rate_window', self::WINDOW, $kind );
        return [ $max > 0 ? $max : $default, $window > 0 ? $window : self::WINDOW ];
    }

    /**
     * The part of an address a budget is kept for: an IPv4 address as is, an
     * IPv6 address by its /64 network (one subscriber usually holds a whole /64).
     *
     * @param string $ip Address.
     * @return string
     */
    public static function address( $ip ) {
        $ip  = (string) $ip;
        $bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unparsable value is used as given
        if ( false === $bin || 16 !== strlen( $bin ) ) {
            return $ip;
        }
        if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $bin, 0, 12 ) ) {
            return (string) inet_ntop( substr( $bin, 12 ) );
        }
        return inet_ntop( substr( $bin, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
    }

    /** Whether this request is the Connection Doctor's own probe. */
    public static function is_probe() {
        return class_exists( '\Royal_MCP\Admin\Connection_Doctor' ) && \Royal_MCP\Admin\Connection_Doctor::is_probe_request();
    }

    /** Start of the current window. */
    private static function window_start( $window ) {
        return (int) ( floor( time() / $window ) * $window );
    }

    /**
     * Count one request against a bucket, atomically.
     *
     * @param string $bucket Bucket name.
     * @param int    $window Window seconds.
     * @return array{0:int,1:int} [ count in this window, seconds until the window ends ].
     */
    public static function increment( $bucket, $window ) {
        global $wpdb;
        $window = max( 1, (int) $window );
        $start  = self::window_start( $window );
        $retry  = max( 1, $start + $window - time() );
        if ( wp_using_ext_object_cache() ) {
            $key = md5( (string) $bucket ) . ':' . $start;
            wp_cache_add( $key, 0, self::GROUP, $window + 5 );
            $n = wp_cache_incr( $key, 1, self::GROUP );
            if ( false === $n ) {
                wp_cache_set( $key, 1, self::GROUP, $window + 5 );
                $n = 1;
            }
            return [ (int) $n, $retry ];
        }
        // A row is stamped with the end of its window, so the sweep can tell a finished window
        // from a long one that is still running.
        $mark  = (string) ( $start + $window );
        $name  = self::PREFIX . md5( (string) $bucket );
        $fresh = $mark . ':1';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic counter row
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )
             ON DUPLICATE KEY UPDATE option_value = IF( SUBSTRING_INDEX( option_value, ':', 1 ) = %s,
                 CONCAT( %s, ':', CAST( SUBSTRING_INDEX( option_value, ':', -1 ) AS UNSIGNED ) + 1 ), %s )",
            $name,
            $fresh,
            $mark,
            $mark,
            $fresh
        ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic counter row
        $value = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
        if ( 1 === wp_rand( 1, 50 ) ) {
            self::sweep( 200 );
        }
        $parts = explode( ':', $value );
        return [ ( isset( $parts[1] ) && $mark === $parts[0] ) ? (int) $parts[1] : 1, $retry ];
    }

    /**
     * Requests counted in the current window of a bucket, without counting one.
     *
     * @param string $bucket Bucket name.
     * @param int    $window Window seconds.
     * @return array{0:int,1:int} [ count, seconds until the window ends ].
     */
    public static function peek( $bucket, $window ) {
        global $wpdb;
        $window = max( 1, (int) $window );
        $start  = self::window_start( $window );
        $retry  = max( 1, $start + $window - time() );
        if ( wp_using_ext_object_cache() ) {
            return [ (int) wp_cache_get( md5( (string) $bucket ) . ':' . $start, self::GROUP ), $retry ];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic counter row
        $value = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::PREFIX . md5( (string) $bucket ) ) );
        $parts = explode( ':', $value );
        return [ ( isset( $parts[1] ) && (string) ( $start + $window ) === $parts[0] ) ? (int) $parts[1] : 0, $retry ];
    }

    /**
     * Forget a bucket's count.
     *
     * @param string $bucket Bucket name.
     * @param int    $window Window seconds (for the object-cache key).
     */
    public static function reset( $bucket, $window = self::WINDOW ) {
        global $wpdb;
        if ( wp_using_ext_object_cache() ) {
            wp_cache_delete( md5( (string) $bucket ) . ':' . self::window_start( max( 1, (int) $window ) ), self::GROUP );
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic counter row
        $wpdb->delete( $wpdb->options, [ 'option_name' => self::PREFIX . md5( (string) $bucket ) ] );
    }

    /**
     * Delete counter rows whose window ended over an hour ago.
     *
     * @param int $limit Most rows deleted in one call.
     * @return int Rows deleted.
     */
    public static function sweep( $limit = 5000 ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counter rows
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST( SUBSTRING_INDEX( option_value, ':', 1 ) AS UNSIGNED ) < %d LIMIT %d",
            $wpdb->esc_like( self::PREFIX ) . '%',
            time() - self::STALE_AFTER,
            max( 1, (int) $limit )
        ) );
    }

    /**
     * Count one request against a bucket of a kind.
     *
     * @param string $bucket Bucket name, e.g. 'oauth-discovery:203.0.113.5'.
     * @param string $kind   Budget kind (see LIMITS).
     * @return array{allowed:bool,retry_after:int,count:int,max:int}
     */
    public static function hit( $bucket, $kind ) {
        list( $max, $window ) = self::budget( $kind );
        // Only the sign-in page probe is exempt; the Doctor sends its header nowhere else on this path.
        if ( 'authorize' === $kind && self::is_probe() ) {
            return [ 'allowed' => true, 'retry_after' => 0, 'count' => 0, 'max' => $max ];
        }
        list( $count, $retry ) = self::increment( $bucket, $window );
        return [ 'allowed' => $count <= $max, 'retry_after' => $retry, 'count' => $count, 'max' => $max ];
    }

    /**
     * Whether a bucket is already at its budget, without counting this request.
     *
     * @param string $bucket Bucket name.
     * @param string $kind   Budget kind.
     * @return int Seconds until the bucket opens again, or 0 when it is open.
     */
    public static function blocked_for( $bucket, $kind ) {
        if ( 'authorize' === $kind && self::is_probe() ) {
            return 0;
        }
        list( $max, $window ) = self::budget( $kind );
        list( $count, $retry ) = self::peek( $bucket, $window );
        return $count >= $max ? $retry : 0;
    }
}
