<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MonsterInsights (Google Analytics for WordPress) MCP Integration
 *
 * MonsterInsights fetches every report through its own relay to Google
 * Analytics. All tools here require the site to be authenticated with
 * MonsterInsights first (setup wizard → link a GA property). When
 * unauthenticated, tools return {state: unavailable, reason:
 * requires_authentication}. Pro-tier reports return {state: unavailable,
 * reason: requires_pro} on Lite installs.
 */
class MonsterInsights {

	public static function is_available() {
		return defined( 'MONSTERINSIGHTS_VERSION' ) && function_exists( 'MonsterInsights' );
	}

	public static function get_tools() {
		return [
			[
				'name'        => 'monsterinsights_get_summary',
				'description' => 'Get the Google Analytics overview summary — sessions, users, pageviews, bounce rate, and average session duration. Defaults to the last 30 days. Returns {state: unavailable, reason: requires_authentication} when MonsterInsights is not yet connected to a GA property.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'start' => [ 'type' => 'string', 'description' => 'Optional start date in YYYY-MM-DD. Defaults to 30 days ago.' ],
						'end'   => [ 'type' => 'string', 'description' => 'Optional end date in YYYY-MM-DD. Defaults to yesterday.' ],
					],
				],
			],
			[
				'name'        => 'monsterinsights_get_top_pages',
				'description' => 'Get the top pages by traffic. Requires MonsterInsights Pro (this report is gated on the Lite tier). Returns {state: unavailable, reason: requires_pro} when the site is on Lite.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'start' => [ 'type' => 'string', 'description' => 'Optional start date in YYYY-MM-DD.' ],
						'end'   => [ 'type' => 'string', 'description' => 'Optional end date in YYYY-MM-DD.' ],
					],
				],
			],
			[
				'name'        => 'monsterinsights_get_traffic_sources',
				'description' => 'Get the traffic source breakdown (referrer, direct, organic, social). Requires MonsterInsights Pro.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'start' => [ 'type' => 'string', 'description' => 'Optional start date in YYYY-MM-DD.' ],
						'end'   => [ 'type' => 'string', 'description' => 'Optional end date in YYYY-MM-DD.' ],
					],
				],
			],
			[
				'name'        => 'monsterinsights_get_search_queries',
				'description' => 'Get the top Google Search queries driving traffic. Requires MonsterInsights Pro AND a Google Search Console connection.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'start' => [ 'type' => 'string', 'description' => 'Optional start date in YYYY-MM-DD.' ],
						'end'   => [ 'type' => 'string', 'description' => 'Optional end date in YYYY-MM-DD.' ],
					],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use MonsterInsights tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'MonsterInsights is not active' );
		}

		$map = [
			'monsterinsights_get_summary'         => 'overview',
			'monsterinsights_get_top_pages'       => 'dimensions',
			'monsterinsights_get_traffic_sources' => 'dimensions',
			'monsterinsights_get_search_queries'  => 'queries',
		];

		if ( ! isset( $map[ $name ] ) ) {
			throw new \Exception( 'Unknown MonsterInsights tool: ' . esc_html( $name ) );
		}

		return self::fetch_report( $map[ $name ], self::normalize_date_args( $args ) );
	}

	private static function normalize_date_args( $args ) {
		$out = [];
		foreach ( [ 'start', 'end' ] as $k ) {
			if ( ! empty( $args[ $k ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args[ $k ] ) ) {
				$out[ $k ] = $args[ $k ];
			}
		}
		if ( empty( $out ) ) {
			$out['default'] = true;
		}
		return $out;
	}

	private static function get_auth() {
		if ( ! function_exists( 'MonsterInsights' ) ) {
			return null;
		}
		$mi = \MonsterInsights();
		return ( $mi && isset( $mi->auth ) ) ? $mi->auth : null;
	}

	private static function is_authed() {
		$auth = self::get_auth();
		return $auth && ( $auth->is_authed() || $auth->is_network_authed() );
	}

	private static function fetch_report( $report_name, $args ) {
		if ( ! self::is_authed() ) {
			return [
				'state'   => 'unavailable',
				'reason'  => 'requires_authentication',
				'message' => 'MonsterInsights is not connected to a Google Analytics property. Complete the setup wizard in wp-admin → Insights to enable this report.',
			];
		}

		$mi = \MonsterInsights();
		if ( ! isset( $mi->reporting ) || ! method_exists( $mi->reporting, 'get_report' ) ) {
			return [
				'state'   => 'unavailable',
				'reason'  => 'reporting_unavailable',
				'message' => 'MonsterInsights reporting subsystem is not initialized.',
			];
		}

		$report = $mi->reporting->get_report( $report_name );
		if ( ! $report ) {
			return [
				'state'   => 'unavailable',
				'reason'  => 'unknown_report',
				'message' => 'Report not registered: ' . $report_name,
			];
		}

		// Lite tier renders an upsell for Pro-only reports. Detect and short-circuit.
		if ( ! empty( $report->level ) && 'pro' === $report->level ) {
			return [
				'state'   => 'unavailable',
				'reason'  => 'requires_pro',
				'tier'    => 'monsterinsights_pro',
				'message' => 'This report requires MonsterInsights Pro.',
			];
		}

		$data = $report->get_data( $args );

		if ( empty( $data['success'] ) ) {
			return [
				'state'   => 'unavailable',
				'reason'  => 'report_error',
				'message' => $data['error'] ?? 'Report fetch failed.',
			];
		}

		return [
			'report'  => $report_name,
			'range'   => [
				'start' => $args['start'] ?? null,
				'end'   => $args['end']   ?? null,
			],
			'data'    => $data['data'] ?? [],
		];
	}
}

/**
 * Manifest declaration. All MonsterInsights tools are read-only.
 */
add_filter( 'royal_mcp_manifests', function ( $manifests ) {
	if ( ! defined( 'MONSTERINSIGHTS_VERSION' ) ) {
		return $manifests;
	}
	$manifests[] = [
		'royal_mcp_manifest_version' => '1.0',
		'plugin_slug'                => 'monsterinsights',
		'plugin_display_name'        => 'MonsterInsights (Google Analytics)',
		'plugin_version'             => MONSTERINSIGHTS_VERSION,
		'vendor_name'                => 'Awesome Motive',
		'mcp_endpoint'               => rest_url( 'royal-mcp/v1/mcp' ),
		'auth_methods'               => [ 'oauth2.1' ],
		'capabilities'               => [ 'read' ],
		'manifest_updated_at'        => gmdate( 'c' ),
		'trust_signals'              => [
			'supports_dry_run'                => false,
			'supports_undo'                   => false,
			'supports_snapshots'              => false,
			'requires_review_for_destructive' => false,
		],
	];
	return $manifests;
} );
