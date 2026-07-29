<?php
/**
 * Royal AI Firewall output schemas.
 */

namespace Royal_MCP\Abilities\Output_Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RAIF {

	public static function get( string $tool_name ): ?array {
		$map = self::map();
		return $map[ $tool_name ] ?? null;
	}

	private static function map(): array {
		return array(
			'raif_get_dashboard_stats' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'total_hits'  => array( 'type' => 'integer' ),
					'unique_bots' => array( 'type' => 'integer' ),
					'top_bots'    => array( 'type' => 'array' ),
					'top_paths'   => array( 'type' => 'array' ),
				),
			),
			'raif_get_recent_hits' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => array(
						'timestamp' => array( 'type' => 'string' ),
						'bot_name'  => array( 'type' => 'string' ),
						'path'      => array( 'type' => 'string' ),
						'action'    => array( 'type' => 'string' ),
					),
				),
			),
			'raif_get_bot_policies' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => array(
						'bot_id' => array( 'type' => 'string' ),
						'name'   => array( 'type' => 'string' ),
						'policy' => array( 'type' => 'string' ),
					),
				),
			),
			'raif_set_bot_policy' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'bot_id'  => array( 'type' => 'string' ),
					'policy'  => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'raif_get_daily_rollup' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
			),
			'raif_block_all_ai_bots' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'blocked_count' => array( 'type' => 'integer' ),
					'message'       => array( 'type' => 'string' ),
				),
			),
		);
	}
}
