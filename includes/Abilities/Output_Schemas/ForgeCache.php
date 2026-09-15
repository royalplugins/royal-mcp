<?php
/**
 * ForgeCache output schemas.
 */

namespace Royal_MCP\Abilities\Output_Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ForgeCache {

	public static function get( string $tool_name ): ?array {
		$map = self::map();
		return $map[ $tool_name ] ?? null;
	}

	private static function map(): array {
		return array(
			'fc_clear_cache' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'fc_get_cache_stats' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
			),
			'fc_purge_url' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'success' => array( 'type' => 'boolean' ),
					'url'     => array( 'type' => 'string' ),
					'post_id' => array( 'type' => array( 'integer', 'null' ) ),
				),
			),
			'fc_get_rum_stats' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'rum_enabled'   => array( 'type' => 'boolean' ),
					'window_days'   => array( 'type' => 'integer' ),
					'total_urls'    => array( 'type' => 'integer' ),
					'total_samples' => array( 'type' => 'integer' ),
					'site_score'    => array( 'type' => array( 'integer', 'null' ) ),
					'sort_by'       => array( 'type' => 'string' ),
					'urls'          => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'properties'           => array(
								'url'      => array( 'type' => 'string' ),
								'score'    => array( 'type' => 'integer' ),
								'p75_lcp'  => array( 'type' => 'integer' ),
								'p75_inp'  => array( 'type' => 'integer' ),
								'p75_cls'  => array( 'type' => 'number' ),
								'p75_ttfb' => array( 'type' => 'integer' ),
								'samples'  => array( 'type' => 'integer' ),
							),
						),
					),
					'message' => array( 'type' => 'string' ),
				),
			),
		);
	}
}
