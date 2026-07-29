<?php
/**
 * Royal Ledger output schemas.
 */

namespace Royal_MCP\Abilities\Output_Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RoyalLedger {

	public static function get( string $tool_name ): ?array {
		$map = self::map();
		return $map[ $tool_name ] ?? null;
	}

	private static function map(): array {
		return array(
			'rl_get_costs' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => array(
						'id'          => array( 'type' => array( 'integer', 'string' ) ),
						'name'        => array( 'type' => 'string' ),
						'amount'      => array( 'type' => array( 'number', 'string' ) ),
						'currency'    => array( 'type' => 'string' ),
						'recurrence'  => array( 'type' => 'string' ),
					),
				),
			),
			'rl_create_cost' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'id'      => array( 'type' => array( 'integer', 'string' ) ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'rl_get_renewals' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => array(
						'cost_id'    => array( 'type' => array( 'integer', 'string' ) ),
						'name'       => array( 'type' => 'string' ),
						'renews_at'  => array( 'type' => 'string' ),
						'amount'     => array( 'type' => array( 'number', 'string' ) ),
					),
				),
			),
			'rl_get_keys' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => array(
						'plugin_slug'  => array( 'type' => 'string' ),
						'license_key'  => array( 'type' => 'string' ),
						'expires_at'   => array( 'type' => 'string' ),
					),
				),
			),
		);
	}
}
