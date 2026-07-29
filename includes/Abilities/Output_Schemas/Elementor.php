<?php
/**
 * Elementor output schemas. Data + settings shapes vary heavily by widget kind
 * so most tools use loose object schemas with a small anchor of well-known fields.
 */

namespace Royal_MCP\Abilities\Output_Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Elementor {

	public static function get( string $tool_name ): ?array {
		$map = self::map();
		return $map[ $tool_name ] ?? null;
	}

	private static function map(): array {
		return array(
			'elementor_clone_page' => array(
				'type'       => 'object',
				'properties' => array(
					'source_id' => array( 'type' => 'integer' ),
					'new_id'    => array( 'type' => 'integer' ),
					'title'     => array( 'type' => 'string' ),
					'edit_url'  => array( 'type' => 'string' ),
					'view_url'  => array( 'type' => 'string' ),
				),
				'additionalProperties' => true,
			),
			'elementor_replace_text' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'      => array( 'type' => 'integer' ),
					'replacements' => array( 'type' => 'integer' ),
				),
				'additionalProperties' => true,
			),
			'elementor_replace_image' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'      => array( 'type' => 'integer' ),
					'replacements' => array( 'type' => 'integer' ),
				),
				'additionalProperties' => true,
			),
			'elementor_get_page_outline' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
			),
			'elementor_get_widget_settings' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
			),
			'elementor_list_local_templates' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => array(
						'id'    => array( 'type' => 'integer' ),
						'title' => array( 'type' => 'string' ),
						'type'  => array( 'type' => 'string' ),
					),
				),
			),
			'elementor_import_template' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
			),
			'elementor_add_widget' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					'post_id'    => array( 'type' => 'integer' ),
					'element_id' => array( 'type' => 'string' ),
					'widget'     => array( 'type' => 'string' ),
				),
			),
		);
	}
}
