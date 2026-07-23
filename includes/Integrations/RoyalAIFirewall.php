<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Royal AI Firewall MCP Integration
 *
 * Exposes RAIF's bot invocation log, per-bot policies, daily rollups, and
 * panic-block over MCP. All tools consume RAIF's existing public surface
 * (FingerprintDB catalog, PolicyWriter apply path, raif_* tables); no RAIF
 * internal state is touched directly.
 */
class RoyalAIFirewall {

	public static function is_available() {
		return defined( 'ROYAL_AI_FIREWALL_VERSION' );
	}

	public static function get_tools() {
		if ( ! self::is_available() ) {
			return [];
		}

		return [
			[
				'name'        => 'raif_get_dashboard_stats',
				'description' => 'Get Royal AI Firewall dashboard snapshot: total bot invocations in a window, per-bot breakdown (hits, blocked, unique IPs, last seen, current policy), plus MCP/abilities activity summary.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'window_hours' => [ 'type' => 'integer', 'description' => 'Rolling window in hours (default 24, max 720)', 'minimum' => 1, 'maximum' => 720 ],
					],
				],
			],
			[
				'name'        => 'raif_get_recent_hits',
				'description' => 'Get recent bot invocation log entries with optional filters (bot_id, source, since). Returns per-hit rows: timestamp, bot, category, source, request path, IP, policy decision, response status.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'  => [ 'type' => 'integer', 'description' => 'Max rows (default 50, max 500)', 'minimum' => 1, 'maximum' => 500 ],
						'bot_id' => [ 'type' => 'string', 'description' => 'Filter to a single bot (matches FingerprintDB bot id).' ],
						'source' => [ 'type' => 'string', 'description' => 'Filter by request source: http (regular HTTP bot hits), mcp-server (Royal MCP tool calls), abilities (WordPress Abilities API calls).', 'enum' => [ 'http', 'mcp-server', 'abilities' ] ],
						'since'  => [ 'type' => 'string', 'description' => 'ISO 8601 timestamp (UTC) — only rows at or after this instant.' ],
					],
				],
			],
			[
				'name'        => 'raif_get_bot_policies',
				'description' => 'Get per-bot allow/block/log-only policy state for every recognized bot. Returns catalog entry (name, category, owner, always_allow) plus effective policy and its source (per-bot override vs. site default).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'bot_id' => [ 'type' => 'string', 'description' => 'Optional — restrict to a single bot.' ],
					],
				],
			],
			[
				'name'        => 'raif_set_bot_policy',
				'description' => 'Set a per-bot policy override (allow, block, log-only) or clear the override so the bot reverts to the site default policy.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'bot_id' => [ 'type' => 'string', 'description' => 'Bot id (matches FingerprintDB).' ],
						'policy' => [ 'type' => 'string', 'description' => 'New policy. Use "default" to remove the override.', 'enum' => [ 'allow', 'block', 'log-only', 'default' ] ],
					],
					'required'   => [ 'bot_id', 'policy' ],
				],
			],
			[
				'name'        => 'raif_get_daily_rollup',
				'description' => 'Get historical daily rollup aggregates for reporting and trending: per date + per bot, hits, blocked, rate_limited, bytes_total, unique IPs, unique paths.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'days'   => [ 'type' => 'integer', 'description' => 'Number of days back from today (default 7, max 30)', 'minimum' => 1, 'maximum' => 30 ],
						'bot_id' => [ 'type' => 'string', 'description' => 'Optional — restrict to a single bot.' ],
					],
				],
			],
			[
				'name'        => 'raif_block_all_ai_bots',
				'description' => 'Panic button: set the per-bot override to "block" for every recognized bot except those marked always_allow. Requires confirm:true.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'confirm' => [ 'type' => 'boolean', 'description' => 'MUST be true — refused silently otherwise.' ],
					],
					'required'   => [ 'confirm' ],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		// 1.4.30 order: cap check BEFORE active-check so an unauthorized caller
		// can't probe whether RAIF is installed via the "not active" error path.
		// RAIF tools expose invocation logs, IP addresses, and policy write —
		// manage_options matches RAIF's own admin-screen gating.
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use Royal AI Firewall tools.' );
		}

		if ( ! self::is_available() ) {
			throw new \Exception( 'Royal AI Firewall is not active' );
		}

		switch ( $name ) {
			case 'raif_get_dashboard_stats':
				return self::get_dashboard_stats( $args );

			case 'raif_get_recent_hits':
				return self::get_recent_hits( $args );

			case 'raif_get_bot_policies':
				return self::get_bot_policies( $args );

			case 'raif_set_bot_policy':
				return self::set_bot_policy( $args );

			case 'raif_get_daily_rollup':
				return self::get_daily_rollup( $args );

			case 'raif_block_all_ai_bots':
				return self::block_all_ai_bots( $args );

			default:
				throw new \Exception( 'Unknown Royal AI Firewall tool: ' . esc_html( $name ) );
		}
	}

	private static function get_dashboard_stats( $args ) {
		global $wpdb;

		$hours = isset( $args['window_hours'] ) ? intval( $args['window_hours'] ) : 24;
		if ( $hours < 1 || $hours > 720 ) {
			$hours = 24;
		}
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );

		$log_table    = $wpdb->prefix . ROYAL_AI_FIREWALL_TABLE_LOG;
		$policy_table = $wpdb->prefix . ROYAL_AI_FIREWALL_TABLE_POLICY;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT bot_id, bot_category,
					COUNT(*) AS hits,
					SUM(CASE WHEN policy_action = \'block\' THEN 1 ELSE 0 END) AS blocked,
					SUM(response_bytes) AS bytes_total,
					COUNT(DISTINCT ip) AS unique_ips,
					MAX(occurred_at) AS last_seen
				FROM %i
				WHERE occurred_at >= %s
				GROUP BY bot_id, bot_category
				ORDER BY hits DESC',
				$log_table,
				$since
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$override_rows = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT bot_id, action FROM %i', $policy_table ),
			ARRAY_A
		);
		$overrides = [];
		foreach ( $override_rows as $r ) {
			$overrides[ $r['bot_id'] ] = $r['action'];
		}

		$default_mode = (string) get_option( 'raif_default_policy', 'log-only' );

		$bots          = [];
		$total_hits    = 0;
		$total_blocked = 0;

		foreach ( (array) $rows as $row ) {
			$hits    = (int) $row['hits'];
			$blocked = (int) $row['blocked'];

			$total_hits    += $hits;
			$total_blocked += $blocked;

			$bot_def = \Royal_AI_Firewall\FingerprintDB::get_bot_by_id( $row['bot_id'] );

			$policy_action = isset( $overrides[ $row['bot_id'] ] )
				? $overrides[ $row['bot_id'] ]
				: self::derive_default_action( $bot_def, $default_mode );
			$policy_source = isset( $overrides[ $row['bot_id'] ] ) ? 'per-bot' : 'default';

			$bots[] = [
				'bot_id'       => $row['bot_id'],
				'bot_name'     => $bot_def['name'] ?? $row['bot_id'],
				'category'     => $row['bot_category'],
				'owner'        => $bot_def['owner'] ?? null,
				'hits'         => $hits,
				'blocked'      => $blocked,
				'bytes_total'  => (int) $row['bytes_total'],
				'unique_ips'   => (int) $row['unique_ips'],
				'last_seen'    => $row['last_seen'],
				'always_allow' => ! empty( $bot_def['always_allow'] ),
				'policy'       => [ 'action' => $policy_action, 'source' => $policy_source ],
			];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$mcp_rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source, ability_id, COUNT(*) AS hits,
					SUM(CASE WHEN response_status >= 400 THEN 1 ELSE 0 END) AS errors
				FROM %i
				WHERE source IN (\'abilities\',\'mcp-server\') AND occurred_at >= %s
				GROUP BY source, ability_id
				ORDER BY hits DESC
				LIMIT 20',
				$log_table,
				$since
			),
			ARRAY_A
		);
		$mcp_total = 0;
		foreach ( $mcp_rows as $r ) {
			$mcp_total += (int) $r['hits'];
		}

		return [
			'window_hours' => $hours,
			'hero'         => [
				'total_hits'    => $total_hits,
				'total_blocked' => $total_blocked,
				'total_bots'    => count( $bots ),
				'default_mode'  => $default_mode,
			],
			'bots'         => $bots,
			'mcp_activity' => [
				'total_invocations' => $mcp_total,
				'top_tools'         => $mcp_rows,
			],
			'raif_version' => ROYAL_AI_FIREWALL_VERSION,
		];
	}

	private static function get_recent_hits( $args ) {
		global $wpdb;

		$limit = isset( $args['limit'] ) ? intval( $args['limit'] ) : 50;
		if ( $limit < 1 || $limit > 500 ) {
			$limit = 50;
		}

		$log_table = $wpdb->prefix . ROYAL_AI_FIREWALL_TABLE_LOG;

		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $args['bot_id'] ) ) {
			$where[]  = 'bot_id = %s';
			$params[] = sanitize_key( $args['bot_id'] );
		}
		if ( ! empty( $args['source'] ) ) {
			$source = sanitize_key( $args['source'] );
			if ( in_array( $source, [ 'http', 'mcp-server', 'abilities' ], true ) ) {
				$where[]  = 'source = %s';
				$params[] = $source;
			}
		}
		if ( ! empty( $args['since'] ) ) {
			$ts = strtotime( (string) $args['since'] );
			if ( false !== $ts ) {
				$where[]  = 'occurred_at >= %s';
				$params[] = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		$where_sql = implode( ' AND ', $where );

		$sql_parts   = array_merge( [ $log_table ], $params, [ $limit ] );
		$prepared    = call_user_func_array(
			[ $wpdb, 'prepare' ],
			array_merge(
				[
					'SELECT occurred_at, bot_id, bot_category, source, ability_id,
						request_method, request_uri, response_status, policy_action,
						INET6_NTOA(ip) AS ip_text
					FROM %i
					WHERE ' . $where_sql . '
					ORDER BY occurred_at DESC
					LIMIT %d',
				],
				$sql_parts
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $prepared, ARRAY_A );

		return [
			'hits'      => array_map(
				function ( $r ) {
					return [
						'timestamp'       => $r['occurred_at'],
						'bot_id'          => $r['bot_id'],
						'category'        => $r['bot_category'],
						'source'          => $r['source'],
						'ability_id'      => $r['ability_id'],
						'method'          => $r['request_method'],
						'path'            => $r['request_uri'],
						'response_status' => (int) $r['response_status'],
						'policy_action'   => $r['policy_action'],
						'ip'              => $r['ip_text'],
					];
				},
				$rows
			),
			'returned'  => count( $rows ),
			'truncated' => count( $rows ) === $limit,
		];
	}

	private static function get_bot_policies( $args ) {
		global $wpdb;

		$policy_table = $wpdb->prefix . ROYAL_AI_FIREWALL_TABLE_POLICY;
		$default_mode = (string) get_option( 'raif_default_policy', 'log-only' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$override_rows = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT bot_id, action, rate_limit_per_min, paywall_url, updated_at FROM %i', $policy_table ),
			ARRAY_A
		);
		$overrides = [];
		foreach ( $override_rows as $r ) {
			$overrides[ $r['bot_id'] ] = $r;
		}

		$catalog = (array) \Royal_AI_Firewall\FingerprintDB::get_all_bots();

		if ( ! empty( $args['bot_id'] ) ) {
			$only = sanitize_key( $args['bot_id'] );
			$catalog = array_values( array_filter(
				$catalog,
				function ( $b ) use ( $only ) {
					return ( $b['id'] ?? '' ) === $only;
				}
			) );
		}

		$policies = [];
		foreach ( $catalog as $bot ) {
			$bot_id = $bot['id'] ?? '';
			if ( '' === $bot_id ) {
				continue;
			}
			$override = $overrides[ $bot_id ] ?? null;

			$policies[] = [
				'bot_id'       => $bot_id,
				'name'         => $bot['name'] ?? $bot_id,
				'category'     => $bot['category'] ?? 'unknown-ai',
				'owner'        => $bot['owner'] ?? null,
				'always_allow' => ! empty( $bot['always_allow'] ),
				'policy'       => $override
					? [
						'action'             => $override['action'],
						'source'             => 'per-bot',
						'rate_limit_per_min' => isset( $override['rate_limit_per_min'] ) ? (int) $override['rate_limit_per_min'] : null,
						'paywall_url'        => $override['paywall_url'] ?? null,
						'updated_at'         => $override['updated_at'] ?? null,
					]
					: [
						'action' => self::derive_default_action( $bot, $default_mode ),
						'source' => 'default',
					],
			];
		}

		return [
			'policies'      => $policies,
			'global_default' => $default_mode,
			'total_count'   => count( $policies ),
		];
	}

	private static function set_bot_policy( $args ) {
		global $wpdb;

		$bot_id = isset( $args['bot_id'] ) ? sanitize_key( $args['bot_id'] ) : '';
		$policy = isset( $args['policy'] ) ? sanitize_key( $args['policy'] ) : '';

		if ( '' === $bot_id ) {
			throw new \Exception( 'bot_id is required.' );
		}
		if ( ! in_array( $policy, [ 'allow', 'block', 'log-only', 'default' ], true ) ) {
			throw new \Exception( 'policy must be one of: allow, block, log-only, default.' );
		}

		$bot_def = \Royal_AI_Firewall\FingerprintDB::get_bot_by_id( $bot_id );
		if ( null === $bot_def ) {
			throw new \Exception( 'Unknown bot_id: ' . esc_html( $bot_id ) );
		}

		$policy_table = $wpdb->prefix . ROYAL_AI_FIREWALL_TABLE_POLICY;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$previous_row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT action FROM %i WHERE bot_id = %s', $policy_table, $bot_id ),
			ARRAY_A
		);
		$previous_action = $previous_row['action'] ?? null;

		if ( 'default' === $policy ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $policy_table, [ 'bot_id' => $bot_id ], [ '%s' ] );
			do_action( 'raif_policy_override_cleared', $bot_id );

			return [
				'updated'         => true,
				'bot_id'          => $bot_id,
				'policy'          => 'default',
				'previous_policy' => $previous_action,
			];
		}

		$result = \Royal_AI_Firewall\PolicyWriter::apply( $bot_id, $policy );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( esc_html( $result->get_error_message() ) );
		}

		return [
			'updated'         => true,
			'bot_id'          => $bot_id,
			'policy'          => $policy,
			'previous_policy' => $previous_action,
		];
	}

	private static function get_daily_rollup( $args ) {
		global $wpdb;

		$days = isset( $args['days'] ) ? intval( $args['days'] ) : 7;
		if ( $days < 1 || $days > 30 ) {
			$days = 7;
		}

		$rollup_table = $wpdb->prefix . ROYAL_AI_FIREWALL_TABLE_ROLLUP;
		$since        = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );

		if ( ! empty( $args['bot_id'] ) ) {
			$only = sanitize_key( $args['bot_id'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT rollup_date, bot_id, bot_category, hits, blocked, rate_limited, bytes_total, unique_ips, unique_paths
					FROM %i
					WHERE rollup_date >= %s AND bot_id = %s
					ORDER BY rollup_date DESC, bot_id ASC',
					$rollup_table,
					$since,
					$only
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT rollup_date, bot_id, bot_category, hits, blocked, rate_limited, bytes_total, unique_ips, unique_paths
					FROM %i
					WHERE rollup_date >= %s
					ORDER BY rollup_date DESC, hits DESC',
					$rollup_table,
					$since
				),
				ARRAY_A
			);
		}

		return [
			'rollup'      => array_map(
				function ( $r ) {
					return [
						'rollup_date'  => $r['rollup_date'],
						'bot_id'       => $r['bot_id'],
						'bot_category' => $r['bot_category'],
						'hits'         => (int) $r['hits'],
						'blocked'      => (int) $r['blocked'],
						'rate_limited' => (int) $r['rate_limited'],
						'bytes_total'  => (int) $r['bytes_total'],
						'unique_ips'   => (int) $r['unique_ips'],
						'unique_paths' => (int) $r['unique_paths'],
					];
				},
				$rows
			),
			'window_days' => $days,
			'total_count' => count( $rows ),
		];
	}

	private static function block_all_ai_bots( $args ) {
		if ( empty( $args['confirm'] ) || true !== filter_var( $args['confirm'], FILTER_VALIDATE_BOOLEAN ) ) {
			throw new \Exception( 'confirm must be true to run this destructive operation.' );
		}

		$catalog = (array) \Royal_AI_Firewall\FingerprintDB::get_all_bots();

		$blocked_count = 0;
		$skipped       = [];
		$errors        = [];

		foreach ( $catalog as $bot ) {
			$bot_id = $bot['id'] ?? '';
			if ( '' === $bot_id ) {
				continue;
			}
			if ( ! empty( $bot['always_allow'] ) ) {
				$skipped[] = $bot_id;
				continue;
			}

			$result = \Royal_AI_Firewall\PolicyWriter::apply( $bot_id, 'block' );
			if ( is_wp_error( $result ) ) {
				$errors[ $bot_id ] = $result->get_error_message();
				continue;
			}
			$blocked_count++;
		}

		return [
			'blocked_count'         => $blocked_count,
			'skipped_always_allow'  => $skipped,
			'errors'                => (object) $errors,
			'previous_global_default' => (string) get_option( 'raif_default_policy', 'log-only' ),
		];
	}

	/**
	 * Derive the effective default action for a bot when no per-bot override
	 * exists. Mirrors DashboardRoute::derive_default_action_for() semantics so
	 * dashboard-widget vs. MCP-tool answers stay consistent.
	 */
	private static function derive_default_action( $bot_def, $mode ) {
		if ( is_array( $bot_def ) && ! empty( $bot_def['always_allow'] ) ) {
			return 'allow';
		}
		$category = is_array( $bot_def ) ? (string) ( $bot_def['category'] ?? '' ) : '';
		switch ( $mode ) {
			case 'block-all':
				return 'block';
			case 'block-training':
				return ( 'training-crawler' === $category || 'dataset-scraper' === $category ) ? 'block' : 'allow';
			case 'log-only':
			default:
				return 'log-only';
		}
	}
}
