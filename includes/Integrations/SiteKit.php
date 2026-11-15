<?php
/**
 * Google Site Kit reads: Search Console and Google Analytics 4 reports through
 * Site Kit's own REST endpoints, called as the signed-in user, so Site Kit's
 * own access rules apply and Royal MCP never holds a Google credential.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SiteKit {

	const REST_ROOT = '/google-site-kit/v1';

	const SC_DIMENSIONS = [ 'query', 'page', 'date', 'country', 'device' ];

	const GA_METRICS = [ 'sessions', 'totalUsers', 'newUsers', 'activeUsers', 'screenPageViews', 'engagedSessions', 'engagementRate', 'averageSessionDuration', 'bounceRate', 'eventCount', 'conversions', 'keyEvents' ];

	const GA_DIMENSIONS = [ 'date', 'pagePath', 'pageTitle', 'sessionDefaultChannelGroup', 'sessionSource', 'sessionMedium', 'country', 'city', 'deviceCategory', 'landingPage' ];

	public static function is_available() {
		return defined( 'GOOGLESITEKIT_VERSION' ) || class_exists( '\\Google\\Site_Kit\\Plugin' );
	}

	public static function get_tools() {
		$dates = [
			'start_date' => [ 'type' => 'string', 'description' => 'YYYY-MM-DD. Default 28 days before end_date.' ],
			'end_date'   => [ 'type' => 'string', 'description' => 'YYYY-MM-DD. Default yesterday.' ],
		];
		return [
			[
				'name'        => 'sitekit_get_status',
				'description' => 'Google Site Kit status: version, which modules are active and connected (Search Console, Analytics), and whether your account can read their data. Read-only. Use before the report tools.',
				'inputSchema' => [ 'type' => 'object', 'properties' => (object) [] ],
			],
			[
				'name'        => 'sitekit_search_console_report',
				'description' => 'Google Search Console performance through Site Kit: clicks, impressions, CTR and average position, overall and by query, page, date, country or device. Read-only; Site Kit\'s own access rules apply. Returns {state: unavailable, reason} when Site Kit or its Search Console module is not set up or your account cannot read it. Search Console data lags 2-3 days.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => $dates + [
						'dimensions' => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => self::SC_DIMENSIONS ], 'description' => 'Break the numbers down by these (max 2). None: totals only.' ],
						'url'        => [ 'type' => 'string', 'description' => 'Only this page of the site (full URL).' ],
						'limit'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'description' => 'Rows (default 25).' ],
					],
				],
			],
			[
				'name'        => 'sitekit_analytics_report',
				'description' => 'Google Analytics 4 report through Site Kit: metrics such as sessions, totalUsers, screenPageViews, engagementRate, optionally broken down by date, pagePath, sessionDefaultChannelGroup, country, deviceCategory and more. Read-only; Site Kit\'s own access rules apply. Returns {state: unavailable, reason} when Site Kit or its Analytics module is not set up or your account cannot read it.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => $dates + [
						'metrics'    => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => self::GA_METRICS ], 'description' => 'Default sessions, totalUsers, screenPageViews (max 8).' ],
						'dimensions' => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => self::GA_DIMENSIONS ], 'description' => 'Break down by these (max 3). None: totals only.' ],
						'limit'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 250, 'description' => 'Rows (default 25).' ],
					],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use the Site Kit tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Google Site Kit is not active.' );
		}
		$args = is_array( $args ) ? $args : [];
		switch ( $name ) {
			case 'sitekit_get_status':
				return self::status();
			case 'sitekit_search_console_report':
				return self::search_console( $args );
			case 'sitekit_analytics_report':
				return self::analytics( $args );
		}
		throw new \Exception( 'Unknown Site Kit tool.' );
	}

	/** GET a Site Kit route as the current user. */
	private static function get( $route, array $params = [] ) {
		$req = new \WP_REST_Request( 'GET', self::REST_ROOT . $route );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return rest_do_request( $req );
	}

	/** Why a Site Kit answer is not data, as {state: unavailable, reason, message}. */
	private static function unavailable( \WP_REST_Response $res ) {
		$err    = $res->as_error();
		$code   = (string) $err->get_error_code();
		$status = (int) $res->get_status();
		$map    = [
			'module_not_active'           => 'module_not_active',
			'invalid_module_slug'         => 'module_not_active',
			'rest_forbidden'              => 'no_access',
			'missing_required_scopes'     => 'not_connected',
			'missing_required_param'      => 'invalid_args',
			'invalid_param'               => 'invalid_args',
			'rest_invalid_param'          => 'invalid_args',
		];
		$reason = $map[ $code ] ?? ( 401 === $status || 403 === $status ? 'no_access' : 'site_kit_error' );
		return [
			'state'   => 'unavailable',
			'reason'  => $reason,
			'message' => wp_strip_all_tags( (string) $err->get_error_message() ),
			'hint'    => 'no_access' === $reason || 'not_connected' === $reason
				? 'Your WordPress account needs Site Kit access: sign in to Site Kit (Site Kit > Dashboard) or ask the Site Kit owner to share the module with your role.'
				: ( 'module_not_active' === $reason ? 'Set up the module in Site Kit > Settings.' : '' ),
		];
	}

	private static function date_range( array $args ) {
		$valid = static function ( $d ) {
			return is_string( $d ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && false !== strtotime( $d . ' 00:00:00 UTC' );
		};
		$end   = isset( $args['end_date'] ) && '' !== $args['end_date'] ? $args['end_date'] : gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		if ( ! $valid( $end ) ) {
			throw new \Exception( 'end_date must be YYYY-MM-DD.' );
		}
		$start = isset( $args['start_date'] ) && '' !== $args['start_date'] ? $args['start_date'] : gmdate( 'Y-m-d', strtotime( $end . ' 00:00:00 UTC' ) - 27 * DAY_IN_SECONDS );
		if ( ! $valid( $start ) ) {
			throw new \Exception( 'start_date must be YYYY-MM-DD.' );
		}
		if ( $start > $end ) {
			throw new \Exception( 'start_date must be on or before end_date.' );
		}
		return [ $start, $end ];
	}

	/** Values of $list that are in $allowed, unique, at most $max; refuses unknown ones. */
	private static function pick( $list, array $allowed, $max, $what ) {
		if ( null === $list || '' === $list ) {
			return [];
		}
		$list = is_string( $list ) ? explode( ',', $list ) : (array) $list;
		$out  = [];
		foreach ( $list as $v ) {
			$v = trim( (string) $v );
			if ( ! in_array( $v, $allowed, true ) ) {
				/* translators: not shown in the admin; tool error text */
				throw new \Exception( sprintf( 'Unknown %s "%s". Allowed: %s.', $what, $v, implode( ', ', $allowed ) ) );
			}
			$out[ $v ] = true;
		}
		if ( count( $out ) > $max ) {
			throw new \Exception( sprintf( 'At most %d %s.', $max, $what ) );
		}
		return array_keys( $out );
	}

	private static function status() {
		$res     = self::get( '/core/modules/data/list' );
		$modules = [];
		if ( ! $res->is_error() ) {
			foreach ( (array) $res->get_data() as $m ) {
				if ( is_array( $m ) && in_array( $m['slug'] ?? '', [ 'search-console', 'analytics-4', 'pagespeed-insights', 'adsense', 'tagmanager' ], true ) ) {
					$modules[ (string) $m['slug'] ] = [ 'name' => (string) ( $m['name'] ?? $m['slug'] ), 'active' => ! empty( $m['active'] ), 'connected' => ! empty( $m['connected'] ) ];
				}
			}
		}
		$auth  = self::get( '/core/user/data/authentication' );
		$ad    = $auth->is_error() ? [] : (array) $auth->get_data();
		$conn  = self::get( '/core/site/data/connection' );
		$cd    = $conn->is_error() ? [] : (array) $conn->get_data();
		// Site Kit calls a module "connected" once it is configured; data flows only when the
		// site is connected to Google and setup is complete.
		$ready = ! empty( $cd['connected'] ) && ! empty( $cd['setupCompleted'] );
		return [
			'version'             => defined( 'GOOGLESITEKIT_VERSION' ) ? (string) GOOGLESITEKIT_VERSION : '',
			'site_connected'      => ! empty( $cd['connected'] ),
			'setup_completed'     => ! empty( $cd['setupCompleted'] ),
			'modules'             => $modules,
			'modules_readable'    => ! $res->is_error(),
			'user_authenticated'  => ! empty( $ad['authenticated'] ),
			'search_console'      => $ready && ! empty( $modules['search-console']['connected'] ),
			'analytics'           => $ready && ! empty( $modules['analytics-4']['connected'] ),
			'note'                => 'Reports are read through Site Kit as your account. A user who has not signed in to Site Kit can still read modules the Site Kit owner shared with their role.',
		];
	}

	private static function search_console( array $args ) {
		list( $start, $end ) = self::date_range( $args );
		$dims  = self::pick( $args['dimensions'] ?? null, self::SC_DIMENSIONS, 2, 'dimensions' );
		$limit = isset( $args['limit'] ) ? max( 1, min( 1000, (int) $args['limit'] ) ) : 25;
		$params = [ 'startDate' => $start, 'endDate' => $end, 'dimensions' => implode( ',', $dims ), 'limit' => $limit ];
		if ( ! empty( $args['url'] ) ) {
			$url = esc_url_raw( (string) $args['url'] );
			if ( '' === $url || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
				throw new \Exception( 'url must be a page of this site.' );
			}
			$params['url'] = $url;
		}
		$res = self::get( '/modules/search-console/data/searchanalytics', $params );
		if ( $res->is_error() ) {
			return self::unavailable( $res );
		}
		$rows = [];
		foreach ( (array) $res->get_data() as $r ) {
			$r = (array) json_decode( (string) wp_json_encode( $r ), true );
			$row = [];
			foreach ( $dims as $i => $d ) {
				$row[ $d ] = (string) ( $r['keys'][ $i ] ?? '' );
			}
			$rows[] = $row + [
				'clicks'      => (int) ( $r['clicks'] ?? 0 ),
				'impressions' => (int) ( $r['impressions'] ?? 0 ),
				'ctr'         => round( (float) ( $r['ctr'] ?? 0 ), 4 ),
				'position'    => round( (float) ( $r['position'] ?? 0 ), 1 ),
			];
		}
		return [ 'start_date' => $start, 'end_date' => $end, 'dimensions' => $dims, 'count' => count( $rows ), 'rows' => $rows, 'source' => 'Google Search Console via Site Kit' ];
	}

	private static function analytics( array $args ) {
		list( $start, $end ) = self::date_range( $args );
		$metrics = self::pick( $args['metrics'] ?? null, self::GA_METRICS, 8, 'metrics' );
		$metrics = $metrics ? $metrics : [ 'sessions', 'totalUsers', 'screenPageViews' ];
		$dims    = self::pick( $args['dimensions'] ?? null, self::GA_DIMENSIONS, 3, 'dimensions' );
		$limit   = isset( $args['limit'] ) ? max( 1, min( 250, (int) $args['limit'] ) ) : 25;
		$params  = [
			'startDate' => $start,
			'endDate'   => $end,
			'metrics'   => array_map( static function ( $m ) { return [ 'name' => $m ]; }, $metrics ),
			'limit'     => $limit,
		];
		if ( $dims ) {
			$params['dimensions'] = array_map( static function ( $d ) { return [ 'name' => $d ]; }, $dims );
		}
		$res = self::get( '/modules/analytics-4/data/report', $params );
		if ( $res->is_error() ) {
			return self::unavailable( $res );
		}
		$data = (array) json_decode( (string) wp_json_encode( $res->get_data() ), true );
		$rows = [];
		foreach ( (array) ( $data['rows'] ?? [] ) as $r ) {
			$row = [];
			foreach ( $dims as $i => $d ) {
				$row[ $d ] = (string) ( $r['dimensionValues'][ $i ]['value'] ?? '' );
			}
			foreach ( $metrics as $i => $m ) {
				$v         = $r['metricValues'][ $i ]['value'] ?? null;
				$row[ $m ] = is_numeric( $v ) ? $v + 0 : null;
			}
			$rows[] = $row;
		}
		$totals = [];
		foreach ( $metrics as $i => $m ) {
			$v = $data['totals'][0]['metricValues'][ $i ]['value'] ?? null;
			if ( is_numeric( $v ) ) {
				$totals[ $m ] = $v + 0;
			}
		}
		return [ 'start_date' => $start, 'end_date' => $end, 'metrics' => $metrics, 'dimensions' => $dims, 'count' => count( $rows ), 'rows' => $rows, 'totals' => $totals, 'row_count' => (int) ( $data['rowCount'] ?? count( $rows ) ), 'source' => 'Google Analytics 4 via Site Kit' ];
	}
}
