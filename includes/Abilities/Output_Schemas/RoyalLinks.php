<?php
/**
 * Royal Links output schemas.
 */

namespace Royal_MCP\Abilities\Output_Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RoyalLinks {

	public static function get( string $tool_name ): ?array {
		$map = self::map();
		return $map[ $tool_name ] ?? null;
	}

	private static function map(): array {
		return array(
			'rlinks_get_links' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => array(
						'id'          => array( 'type' => 'integer' ),
						'title'       => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'target_url'  => array( 'type' => 'string' ),
						'click_count' => array( 'type' => 'integer' ),
					),
				),
			),
			'rlinks_create_link' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'id'         => array( 'type' => 'integer' ),
					'slug'       => array( 'type' => 'string' ),
					'short_url'  => array( 'type' => 'string' ),
					'target_url' => array( 'type' => 'string' ),
				),
			),
			'rlinks_get_link_stats' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'link_id'     => array( 'type' => 'integer' ),
					'click_count' => array( 'type' => 'integer' ),
				),
			),
		);
	}
}
