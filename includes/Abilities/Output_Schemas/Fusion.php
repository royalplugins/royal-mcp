<?php
/**
 * Output schemas for the Avada / Fusion Builder integration tools.
 */

namespace Royal_MCP\Abilities\Output_Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Fusion {

	public static function get( string $tool_name ): ?array {
		$map = array(
			'fusion_get_text_blocks' => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'index'          => array( 'type' => 'integer' ),
						'tag'            => array( 'type' => 'string' ),
						'admin_label'    => array( 'type' => 'string' ),
						'inner_content'  => array( 'type' => 'string' ),
						'content_length' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => true,
				),
			),
			'fusion_update_text_block' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'index'          => array( 'type' => 'integer' ),
					'tag'            => array( 'type' => 'string' ),
					'before'         => array( 'type' => 'string' ),
					'after'          => array( 'type' => 'string' ),
					'verified'       => array( 'type' => 'boolean' ),
					'modified_by_wp' => array( 'type' => 'string' ),
					'message'        => array( 'type' => 'string' ),
				),
			),
			'fusion_update_attribute' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'tag'       => array( 'type' => 'string' ),
					'index'     => array( 'type' => 'integer' ),
					'attribute' => array( 'type' => 'string' ),
					'before'    => array( 'type' => array( 'string', 'null' ) ),
					'after'     => array( 'type' => 'string' ),
					'added'     => array( 'type' => 'boolean' ),
					'verified'  => array( 'type' => 'boolean' ),
					'message'   => array( 'type' => 'string' ),
				),
			),
		);
		return $map[ $tool_name ] ?? null;
	}
}
