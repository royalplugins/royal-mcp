<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor MCP Integration
 *
 * Registers MCP tools for the Elementor page builder. Only loaded when Elementor
 * is active. Strategy: never generate Elementor JSON from scratch — always work
 * from an existing-known-good source. Atomic widgets (Editor V4, Elementor 4.0+)
 * pass through as opaque blobs since their JSON schema is not publicly documented.
 *
 * Tools:
 *  - elementor_clone_page         — duplicate an existing page with fresh element IDs
 *  - elementor_replace_text       — bulk text substitution across widget settings
 *  - elementor_replace_image      — image URL swap across image-bearing widgets
 *  - elementor_get_page_outline   — extract simplified structure for AI reasoning (<2KB)
 *  - elementor_list_local_templates — enumerate saved templates from the library
 *  - elementor_import_template    — create a new template from a JSON payload
 */
class Elementor {

	/**
	 * Check if Elementor is available.
	 */
	public static function is_available() {
		return class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Get tool definitions for MCP tools/list response.
	 */
	public static function get_tools() {
		if ( ! self::is_available() ) {
			return [];
		}

		return [
			[
				'name'        => 'elementor_clone_page',
				'description' => 'Duplicate an existing Elementor page or post as a new draft. Copies the full _elementor_data tree and regenerates every element ID to avoid duplicates. Preserves Container model, legacy section/column, and atomic widgets as-is. Returns the new post ID. The Elementor editor on the new page opens cleanly because IDs are unique within the document.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'source_post_id' => [ 'type' => 'integer', 'description' => 'Post or page ID to clone from. Must have Elementor data.' ],
						'new_title'      => [ 'type' => 'string', 'description' => 'Title for the new post' ],
						'new_status'     => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'private', 'pending' ], 'description' => 'Defaults to draft' ],
					],
					'required'   => [ 'source_post_id', 'new_title' ],
				],
			],
			[
				'name'        => 'elementor_replace_text',
				'description' => 'Replace text in all text-bearing widget settings of an Elementor page. Walks the _elementor_data tree and substitutes matching strings in known text fields (heading title, text-editor content, button text, image caption/alt, etc.). Case-sensitive by default. Atomic widgets are skipped (opaque passthrough). Returns count of replacements made.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id'          => [ 'type' => 'integer' ],
						'find'             => [ 'type' => 'string', 'description' => 'Text to find' ],
						'replace'          => [ 'type' => 'string', 'description' => 'Text to substitute' ],
						'case_insensitive' => [ 'type' => 'boolean', 'description' => 'Default false' ],
					],
					'required'   => [ 'post_id', 'find', 'replace' ],
				],
			],
			[
				'name'        => 'elementor_replace_image',
				'description' => 'Swap image URLs in an Elementor page across all image-bearing widgets (image widget, background image, gallery items, etc.). Optionally also remap WP attachment IDs. Returns count of replacements made.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer' ],
						'old_url' => [ 'type' => 'string', 'description' => 'URL to find' ],
						'new_url' => [ 'type' => 'string', 'description' => 'URL to replace with' ],
						'old_id'  => [ 'type' => 'integer', 'description' => 'Optional: old WP attachment ID' ],
						'new_id'  => [ 'type' => 'integer', 'description' => 'Optional: new WP attachment ID' ],
					],
					'required'   => [ 'post_id', 'old_url', 'new_url' ],
				],
			],
			[
				'name'        => 'elementor_get_page_outline',
				'description' => 'Extract a simplified outline of an Elementor page: section/container hierarchy, widget types per slot, and short text snippets from text-bearing widgets. Returns JSON small enough for an AI to reason over without consuming the full _elementor_data budget (~2KB for typical pages). Useful before calling clone or replace_text to understand the structure first.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer' ],
					],
					'required'   => [ 'post_id' ],
				],
			],
			[
				'name'        => 'elementor_get_widget_settings',
				'description' => 'Read the full settings object for a single Elementor element (widget, container, section, or column) by its ID. Use after elementor_get_page_outline to inspect a specific element before proposing a modification. Returns element_type, widget_type (widgets only), depth in the tree, has_children flag, child_count, and the raw settings object. If the element is not found, returns found=false with the count of elements searched (helps diagnose wrong IDs). Requires read_post on the parent post_id.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id'    => [ 'type' => 'integer', 'description' => 'The Elementor page/post to search within.' ],
						'element_id' => [ 'type' => 'string',  'description' => 'The Elementor element ID (short hex string, e.g. "a1b2c3d"). Obtained from elementor_get_page_outline or via editing the element.' ],
					],
					'required'   => [ 'post_id', 'element_id' ],
				],
			],
			[
				'name'        => 'elementor_list_local_templates',
				'description' => 'Enumerate saved templates from the Elementor Library (the elementor_library custom post type). Returns id, name, type (page/section/widget/popup/etc.), and date_modified for each. Filter by type if needed. Use this before elementor_import_template to discover available templates.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'type'  => [ 'type' => 'string', 'description' => 'Optional filter by template type (page, section, widget, popup, header, footer, single, archive)' ],
						'limit' => [ 'type' => 'integer', 'description' => 'Max templates to return (default 50)' ],
					],
				],
			],
			[
				'name'        => 'elementor_import_template',
				'description' => 'Create a new Elementor template (in the elementor_library CPT) from a JSON payload. Accepts the structure exported by the Elementor editor (an array of section/container elements). Validates top-level shape and stores the data as _elementor_data on a new template post. Returns the new template post ID.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'title'         => [ 'type' => 'string', 'description' => 'Template name' ],
						'template_type' => [ 'type' => 'string', 'description' => 'page, section, widget, popup, header, footer, single, archive. Defaults to page.' ],
						'template_json' => [ 'type' => 'string', 'description' => 'JSON-encoded array of Elementor elements (the export shape)' ],
					],
					'required'   => [ 'title', 'template_json' ],
				],
			],
			[
				'name'        => 'elementor_add_widget',
				'description' => 'Add a new widget or container to an existing Elementor page. Dual-surface: RAW (any widget_type + full settings object) or CURATED (high-frequency widget types with flat parameters the tool expands into the canonical settings object internally, saving tokens). Curated widget_types: container, heading, text-editor, button, image, image-box, icon-box, icon-list, video, divider, spacer. For any other widget_type, supply settings directly. Container widgets can include children inline (one call drops parent + N children, recursive). Atomic widgets (Editor V4, widget_type prefixed a- or e-) pass through opaquely via the raw path. Returns the new element ID + parent context + edit URL. Cap-checked via edit_post on the target post.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id'          => [ 'type' => 'integer', 'description' => 'Target post or page ID. Must be Elementor-edited.' ],
						'widget_type'      => [ 'type' => 'string', 'description' => 'Elementor widget slug (e.g. heading, button, html, wp-widget-text), or "container" for a Flexbox container.' ],
						'settings'         => [ 'type' => 'object', 'description' => 'RAW path: full Elementor settings object for this widget. When supplied, raw wins (curated params ignored). Required for non-curated widget_types.' ],
						'parent_id'        => [ 'type' => 'string', 'description' => 'Optional. Element ID to insert under. Must be a container, section, or column. If omitted, appended at document top level.' ],
						'position'         => [ 'type' => 'integer', 'description' => 'Optional. Zero-indexed position within parent. If omitted, appended at end.' ],
						'flex_direction'   => [ 'type' => 'string', 'enum' => [ 'row', 'column' ], 'description' => 'Curated container: row or column. Default column.' ],
						'content_width'    => [ 'type' => 'string', 'enum' => [ 'boxed', 'full' ], 'description' => 'Curated container: boxed or full. Default boxed.' ],
						'children'         => [ 'type' => 'array', 'description' => 'Curated container: inline child widget definitions. Each item is an object with widget_type + curated params or settings.' ],
						'title'            => [ 'type' => 'string', 'description' => 'Curated heading: title text.' ],
						'header_size'      => [ 'type' => 'string', 'description' => 'Curated heading: HTML tag (h1-h6, div, span, p). Default h2.' ],
						'editor'           => [ 'type' => 'string', 'description' => 'Curated text-editor: HTML content.' ],
						'text'             => [ 'type' => 'string', 'description' => 'Curated button: button label text.' ],
						'link_url'         => [ 'type' => 'string', 'description' => 'Curated button/image/image-box/icon-box: destination URL.' ],
						'link_target'      => [ 'type' => 'string', 'enum' => [ '_blank', '_self' ], 'description' => 'Curated button/image: link target. Default _self.' ],
						'image_url'        => [ 'type' => 'string', 'description' => 'Curated image/image-box: image URL.' ],
						'image_alt'        => [ 'type' => 'string', 'description' => 'Curated image/image-box: image alt text.' ],
						'title_text'       => [ 'type' => 'string', 'description' => 'Curated image-box/icon-box: title text.' ],
						'description_text' => [ 'type' => 'string', 'description' => 'Curated image-box/icon-box: description text.' ],
						'title_size'       => [ 'type' => 'string', 'description' => 'Curated image-box/icon-box: title HTML tag. Default h3.' ],
						'icon'             => [ 'type' => 'string', 'description' => 'Curated icon-box: FontAwesome icon class (e.g. fas fa-check). Library auto-derived from prefix.' ],
						'items'            => [ 'type' => 'array', 'description' => 'Curated icon-list: array of { text (required), icon?, link_url? } items.' ],
						'video_url'        => [ 'type' => 'string', 'description' => 'Curated video: YouTube, Vimeo, or Dailymotion URL. Self-hosted / VideoPress require raw mode.' ],
						'aspect_ratio'     => [ 'type' => 'string', 'enum' => [ '169', '219', '43', '32', '11', '916' ], 'description' => 'Curated video: aspect ratio (169 = 16:9). Default 169.' ],
						'autoplay'         => [ 'type' => 'boolean', 'description' => 'Curated video: autoplay. Default false.' ],
						'weight'           => [ 'type' => 'integer', 'description' => 'Curated divider: border thickness in pixels. Default 1.' ],
						'color'            => [ 'type' => 'string', 'description' => 'Curated divider: border color hex (e.g. #000000).' ],
						'space'            => [ 'type' => 'integer', 'description' => 'Curated spacer: height in pixels. Default 50.' ],
					],
					'required'   => [ 'post_id', 'widget_type' ],
				],
			],
		];
	}

	/**
	 * Execute an Elementor MCP tool.
	 *
	 * @param string $name Tool name.
	 * @param array  $args Tool arguments.
	 * @return mixed Result data.
	 * @throws \Exception If tool fails.
	 */
	public static function execute_tool( $name, $args ) {
		if ( ! self::is_available() ) {
			throw new \Exception( 'Elementor is not active' );
		}

		switch ( $name ) {
			case 'elementor_clone_page':
				return self::clone_page( $args );

			case 'elementor_replace_text':
				return self::replace_text( $args );

			case 'elementor_replace_image':
				return self::replace_image( $args );

			case 'elementor_get_page_outline':
				return self::get_page_outline( $args );

			case 'elementor_get_widget_settings':
				return self::get_widget_settings( $args );

			case 'elementor_list_local_templates':
				return self::list_local_templates( $args );

			case 'elementor_import_template':
				return self::import_template( $args );

			case 'elementor_add_widget':
				return self::add_widget( $args );

			default:
				throw new \Exception( 'Unknown Elementor tool: ' . esc_html( $name ) );
		}
	}

	// ============================================================
	// Tool implementations
	// ============================================================

	/**
	 * Clone an Elementor page or post as a new draft.
	 */
	private static function clone_page( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new \Exception( 'edit_posts capability required.' );
		}
		$source_id = (int) ( $args['source_post_id'] ?? 0 );
		$new_title = sanitize_text_field( $args['new_title'] ?? '' );
		$new_status = isset( $args['new_status'] ) ? sanitize_key( $args['new_status'] ) : 'draft';
		if ( ! in_array( $new_status, [ 'draft', 'publish', 'private', 'pending' ], true ) ) {
			$new_status = 'draft';
		}
		if ( $source_id <= 0 || $new_title === '' ) {
			throw new \Exception( 'source_post_id and new_title are required.' );
		}
		$source = get_post( $source_id );
		if ( ! $source ) {
			throw new \Exception( 'Source post not found.' );
		}
		$elementor_data = get_post_meta( $source_id, '_elementor_data', true );
		if ( empty( $elementor_data ) ) {
			throw new \Exception( 'Source post does not have Elementor data — was it edited with Elementor?' );
		}

		// Parse, regenerate IDs, re-serialize.
		// Elementor stores _elementor_data as a JSON-encoded string (sometimes
		// already-decoded array depending on filter timing — handle both).
		$tree = is_string( $elementor_data ) ? json_decode( $elementor_data, true ) : $elementor_data;
		if ( ! is_array( $tree ) ) {
			throw new \Exception( 'Could not parse source _elementor_data as a JSON array.' );
		}
		$regenerated = self::regenerate_element_ids( $tree );

		// Create the new post.
		$new_post_data = [
			'post_title'  => $new_title,
			'post_status' => $new_status,
			'post_type'   => $source->post_type,
			'post_author' => get_current_user_id() ?: $source->post_author,
		];
		$new_id = wp_insert_post( $new_post_data, true );
		if ( is_wp_error( $new_id ) ) {
			throw new \Exception( $new_id->get_error_message() );
		}

		// Copy Elementor meta. _elementor_data is stored as a slashed JSON string;
		// re-encoding from our parsed array gives the same shape.
		// Important: wp_slash before update_post_meta because WP unslashes on read.
		update_post_meta( $new_id, '_elementor_data', wp_slash( wp_json_encode( $regenerated ) ) );

		// Copy structural Elementor meta to make the editor open cleanly.
		$meta_keys_to_copy = [
			'_elementor_edit_mode',
			'_elementor_template_type',
			'_elementor_version',
			'_elementor_pro_version',
			'_elementor_page_settings',
			'_elementor_page_assets',
		];
		foreach ( $meta_keys_to_copy as $key ) {
			$value = get_post_meta( $source_id, $key, true );
			if ( $value !== '' && $value !== null && $value !== false ) {
				update_post_meta( $new_id, $key, $value );
			}
		}

		// Set edit mode = 'builder' if it wasn't on source (rare).
		if ( get_post_meta( $new_id, '_elementor_edit_mode', true ) === '' ) {
			update_post_meta( $new_id, '_elementor_edit_mode', 'builder' );
		}

		return [
			'success'        => true,
			'new_post_id'    => (int) $new_id,
			'new_title'      => $new_title,
			'new_status'     => $new_status,
			'source_post_id' => $source_id,
			'edit_url'       => admin_url( 'post.php?post=' . $new_id . '&action=elementor' ),
			'view_url'       => $new_status === 'publish' ? get_permalink( $new_id ) : get_preview_post_link( $new_id ),
		];
	}

	/**
	 * Walk an Elementor element tree and replace every element's id with a fresh random 8-char hex.
	 * Preserves all other fields. Recurses into nested elements.
	 *
	 * @param array $elements
	 * @return array
	 */
	private static function regenerate_element_ids( $elements ) {
		if ( ! is_array( $elements ) ) {
			return $elements;
		}
		$out = [];
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				$out[] = $el;
				continue;
			}
			if ( isset( $el['id'] ) ) {
				$el['id'] = self::generate_element_id();
			}
			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::regenerate_element_ids( $el['elements'] );
			}
			$out[] = $el;
		}
		return $out;
	}

	/**
	 * Generate an 8-character hex element ID matching Elementor's format.
	 */
	private static function generate_element_id() {
		// random_bytes(4) → 4 bytes → bin2hex → 8 chars. Matches Elementor's
		// internal format (its editor generates IDs via random hex too).
		return bin2hex( random_bytes( 4 ) );
	}

	/**
	 * Replace text in known text-bearing widget settings.
	 */
	private static function replace_text( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new \Exception( 'edit_posts capability required.' );
		}
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$find = (string) ( $args['find'] ?? '' );
		$replace = (string) ( $args['replace'] ?? '' );
		$case_insensitive = ! empty( $args['case_insensitive'] );
		if ( $post_id <= 0 || $find === '' ) {
			throw new \Exception( 'post_id and find are required.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new \Exception( 'edit_post capability required on target post.' );
		}
		$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $elementor_data ) ) {
			throw new \Exception( 'Target post does not have Elementor data.' );
		}
		$tree = is_string( $elementor_data ) ? json_decode( $elementor_data, true ) : $elementor_data;
		if ( ! is_array( $tree ) ) {
			throw new \Exception( 'Could not parse _elementor_data as a JSON array.' );
		}

		$counter = [ 'count' => 0 ];
		$updated = self::walk_widgets_text( $tree, $find, $replace, $case_insensitive, $counter );

		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $updated ) ) );

		return [
			'success'       => true,
			'post_id'       => $post_id,
			'replacements'  => $counter['count'],
			'find'          => $find,
			'replace'       => $replace,
		];
	}

	/**
	 * Recursively walk widgets and substitute text in known text-bearing fields.
	 * Atomic widgets (elType=widget with widgetType matching atomic patterns) are
	 * skipped — their schema is not publicly documented and we don't want to corrupt them.
	 */
	private static function walk_widgets_text( $elements, $find, $replace, $case_insensitive, &$counter ) {
		if ( ! is_array( $elements ) ) {
			return $elements;
		}
		// Known text-bearing setting keys per widget type. Conservative list —
		// widgets not in this map have their settings left alone. Covers Container
		// model + legacy section/column model.
		$text_fields = [
			'heading'        => [ 'title' ],
			'text-editor'    => [ 'editor' ],
			'button'         => [ 'text' ],
			'image'          => [ 'caption', 'alt' ],
			'image-box'      => [ 'title_text', 'description_text' ],
			'icon-box'       => [ 'title_text', 'description_text' ],
			'icon-list'      => [ 'icon_list' ], // array — handled per-item below
			'video'          => [ 'caption' ],
			'testimonial'    => [ 'testimonial_content', 'testimonial_name', 'testimonial_job' ],
			'tabs'           => [ 'tabs' ], // array
			'accordion'      => [ 'tabs' ], // array (Elementor calls them tabs internally)
			'toggle'         => [ 'tabs' ], // array
			'star-rating'    => [ 'title' ],
			'call-to-action' => [ 'title', 'description', 'button' ],
			'flip-box'       => [ 'title_text_a', 'description_text_a', 'title_text_b', 'description_text_b', 'button_text' ],
		];

		$out = [];
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				$out[] = $el;
				continue;
			}

			if ( ( $el['elType'] ?? '' ) === 'widget' ) {
				$widget_type = (string) ( $el['widgetType'] ?? '' );

				// Skip atomic widgets — they live under a different schema in Editor V4
				// and we shouldn't blindly mutate their settings.
				$is_atomic = strpos( $widget_type, 'a-' ) === 0 || strpos( $widget_type, 'e-' ) === 0;

				if ( ! $is_atomic && isset( $text_fields[ $widget_type ] ) && isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
					foreach ( $text_fields[ $widget_type ] as $key ) {
						if ( ! isset( $el['settings'][ $key ] ) ) {
							continue;
						}
						$value = $el['settings'][ $key ];
						if ( is_string( $value ) ) {
							$new_value = self::str_replace_count( $find, $replace, $value, $case_insensitive, $counter );
							$el['settings'][ $key ] = $new_value;
						} elseif ( is_array( $value ) ) {
							// Repeater fields (icon-list, tabs, etc.) — walk one level into each item's text fields.
							foreach ( $value as $i => $item ) {
								if ( ! is_array( $item ) ) {
									continue;
								}
								foreach ( $item as $item_key => $item_value ) {
									if ( is_string( $item_value ) ) {
										$value[ $i ][ $item_key ] = self::str_replace_count( $find, $replace, $item_value, $case_insensitive, $counter );
									}
								}
							}
							$el['settings'][ $key ] = $value;
						}
					}
				}
			}

			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::walk_widgets_text( $el['elements'], $find, $replace, $case_insensitive, $counter );
			}

			$out[] = $el;
		}
		return $out;
	}

	/**
	 * Count-aware string replace. Increments $counter['count'] by the number of replacements.
	 */
	private static function str_replace_count( $find, $replace, $subject, $case_insensitive, &$counter ) {
		$c = 0;
		if ( $case_insensitive ) {
			$out = str_ireplace( $find, $replace, $subject, $c );
		} else {
			$out = str_replace( $find, $replace, $subject, $c );
		}
		$counter['count'] += $c;
		return $out;
	}

	/**
	 * Swap image URLs across image-bearing widget settings.
	 */
	private static function replace_image( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new \Exception( 'edit_posts capability required.' );
		}
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$old_url = esc_url_raw( $args['old_url'] ?? '' );
		$new_url = esc_url_raw( $args['new_url'] ?? '' );
		$old_id = isset( $args['old_id'] ) ? (int) $args['old_id'] : 0;
		$new_id = isset( $args['new_id'] ) ? (int) $args['new_id'] : 0;
		if ( $post_id <= 0 || $old_url === '' || $new_url === '' ) {
			throw new \Exception( 'post_id, old_url, and new_url are required.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new \Exception( 'edit_post capability required on target post.' );
		}
		$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $elementor_data ) ) {
			throw new \Exception( 'Target post does not have Elementor data.' );
		}
		$tree = is_string( $elementor_data ) ? json_decode( $elementor_data, true ) : $elementor_data;
		if ( ! is_array( $tree ) ) {
			throw new \Exception( 'Could not parse _elementor_data as a JSON array.' );
		}

		$counter = [ 'count' => 0 ];
		$updated = self::walk_widgets_image( $tree, $old_url, $new_url, $old_id, $new_id, $counter );

		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $updated ) ) );

		return [
			'success'      => true,
			'post_id'      => $post_id,
			'replacements' => $counter['count'],
			'old_url'      => $old_url,
			'new_url'      => $new_url,
		];
	}

	/**
	 * Recursively walk widgets and swap image URLs in known image-bearing keys.
	 */
	private static function walk_widgets_image( $elements, $old_url, $new_url, $old_id, $new_id, &$counter ) {
		if ( ! is_array( $elements ) ) {
			return $elements;
		}
		$out = [];
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				$out[] = $el;
				continue;
			}

			if ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
				// Walk every settings key — if it's a dict with 'url' that matches, swap.
				// Covers image widget (settings.image.url), background images
				// (settings.background_image.url), and similar.
				$el['settings'] = self::swap_image_in_settings( $el['settings'], $old_url, $new_url, $old_id, $new_id, $counter );
			}

			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::walk_widgets_image( $el['elements'], $old_url, $new_url, $old_id, $new_id, $counter );
			}

			$out[] = $el;
		}
		return $out;
	}

	/**
	 * Walk a settings dict and replace image URL refs.
	 */
	private static function swap_image_in_settings( $settings, $old_url, $new_url, $old_id, $new_id, &$counter ) {
		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) ) {
				// Elementor image-shape: { 'url': '...', 'id': N, 'size': '...', ... }
				if ( isset( $value['url'] ) && is_string( $value['url'] ) && $value['url'] === $old_url ) {
					$settings[ $key ]['url'] = $new_url;
					$counter['count']++;
					if ( $old_id > 0 && $new_id > 0 && isset( $value['id'] ) && (int) $value['id'] === $old_id ) {
						$settings[ $key ]['id'] = $new_id;
					}
				} else {
					// Recurse — gallery items, repeaters, etc.
					$settings[ $key ] = self::swap_image_in_settings( $value, $old_url, $new_url, $old_id, $new_id, $counter );
				}
			}
		}
		return $settings;
	}

	/**
	 * Build a simplified outline of an Elementor page.
	 */
	private static function get_page_outline( $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			throw new \Exception( 'post_id is required.' );
		}
		if ( ! current_user_can( 'read_post', $post_id ) ) {
			throw new \Exception( 'read_post capability required.' );
		}
		$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $elementor_data ) ) {
			throw new \Exception( 'Target post does not have Elementor data.' );
		}
		$tree = is_string( $elementor_data ) ? json_decode( $elementor_data, true ) : $elementor_data;
		if ( ! is_array( $tree ) ) {
			throw new \Exception( 'Could not parse _elementor_data as a JSON array.' );
		}

		$outline = self::build_outline( $tree, 0 );
		$post = get_post( $post_id );

		return [
			'post_id'        => $post_id,
			'post_title'     => $post ? $post->post_title : '',
			'post_type'      => $post ? $post->post_type : '',
			'edit_mode'      => get_post_meta( $post_id, '_elementor_edit_mode', true ) ?: null,
			'template_type'  => get_post_meta( $post_id, '_elementor_template_type', true ) ?: null,
			'outline'        => $outline,
		];
	}

	/**
	 * 1.4.37 Candidate 2 — read the full settings object for a single Elementor element.
	 *
	 * Read-only half of the widget CRUD trio; the write halves (update_widget /
	 * delete_widget) remain deferred to post-WCUS pending assessment of what
	 * Elementor's own MCP module ends up covering.
	 */
	private static function get_widget_settings( $args ) {
		$post_id    = (int) ( $args['post_id'] ?? 0 );
		$element_id = isset( $args['element_id'] ) ? (string) $args['element_id'] : '';

		if ( $post_id <= 0 ) {
			throw new \Exception( 'post_id is required.' );
		}
		if ( '' === $element_id ) {
			throw new \Exception( 'element_id is required.' );
		}
		if ( ! current_user_can( 'read_post', $post_id ) ) {
			throw new \Exception( 'read_post capability required.' );
		}

		$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $elementor_data ) ) {
			throw new \Exception( 'Target post does not have Elementor data.' );
		}
		$tree = is_string( $elementor_data ) ? json_decode( $elementor_data, true ) : $elementor_data;
		if ( ! is_array( $tree ) ) {
			throw new \Exception( 'Could not parse _elementor_data as a JSON array.' );
		}

		$searched_count = 0;
		$found = self::find_element_with_depth( $tree, $element_id, 0, $searched_count );

		if ( null === $found ) {
			return [
				'post_id'        => $post_id,
				'element_id'     => $element_id,
				'found'          => false,
				'searched_count' => $searched_count,
			];
		}

		$el       = $found['element'];
		$depth    = $found['depth'];
		$el_type  = (string) ( $el['elType'] ?? 'unknown' );
		$children = ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) ? $el['elements'] : [];

		return [
			'post_id'      => $post_id,
			'element_id'   => $element_id,
			'found'        => true,
			'element_type' => $el_type,
			'widget_type'  => ( 'widget' === $el_type ) ? (string) ( $el['widgetType'] ?? '' ) : null,
			'depth'        => $depth,
			'has_children' => count( $children ) > 0,
			'child_count'  => count( $children ),
			'settings'     => isset( $el['settings'] ) ? $el['settings'] : new \stdClass(),
		];
	}

	/**
	 * DFS-search an Elementor element tree for a matching element ID.
	 * Returns [ 'element' => array, 'depth' => int ] on hit, null on miss.
	 * Counts every element inspected into &$searched_count for diagnostic
	 * "wrong-ID" reporting on miss.
	 *
	 * Distinct from find_element_by_id (2-arg simple lookup used by
	 * add_widget's parent-resolution path) — this one carries the extra
	 * bookkeeping needed for the widget-settings diagnostic.
	 */
	private static function find_element_with_depth( $elements, $target_id, $depth = 0, &$searched_count = 0 ) {
		foreach ( (array) $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$searched_count++;
			if ( isset( $el['id'] ) && (string) $el['id'] === $target_id ) {
				return [ 'element' => $el, 'depth' => $depth ];
			}
			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) && count( $el['elements'] ) > 0 ) {
				$hit = self::find_element_with_depth( $el['elements'], $target_id, $depth + 1, $searched_count );
				if ( null !== $hit ) {
					return $hit;
				}
			}
		}
		return null;
	}

	/**
	 * Recursively build an outline summary.
	 */
	private static function build_outline( $elements, $depth ) {
		$out = [];
		if ( $depth > 6 ) {
			return [ '...deep nesting truncated...' ];
		}
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$el_type = (string) ( $el['elType'] ?? 'unknown' );
			$entry = [
				'elType' => $el_type,
			];
			if ( $el_type === 'widget' ) {
				$entry['widgetType'] = (string) ( $el['widgetType'] ?? 'unknown' );
				// Surface a short text snippet if the widget has one.
				$snippet = self::widget_text_snippet( $el );
				if ( $snippet !== '' ) {
					$entry['snippet'] = $snippet;
				}
			} elseif ( $el_type === 'container' && isset( $el['settings']['flex_direction'] ) ) {
				$entry['flex_direction'] = (string) $el['settings']['flex_direction'];
			}
			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) && count( $el['elements'] ) > 0 ) {
				$entry['children'] = self::build_outline( $el['elements'], $depth + 1 );
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Extract a short text snippet from a widget for the outline (max 80 chars).
	 */
	private static function widget_text_snippet( $widget ) {
		$widget_type = (string) ( $widget['widgetType'] ?? '' );
		$s = $widget['settings'] ?? [];
		if ( ! is_array( $s ) ) {
			return '';
		}
		$snippet_candidates = [
			'heading'        => [ 'title' ],
			'text-editor'    => [ 'editor' ],
			'button'         => [ 'text' ],
			'image-box'      => [ 'title_text' ],
			'icon-box'       => [ 'title_text' ],
			'call-to-action' => [ 'title' ],
		];
		if ( ! isset( $snippet_candidates[ $widget_type ] ) ) {
			return '';
		}
		foreach ( $snippet_candidates[ $widget_type ] as $key ) {
			if ( isset( $s[ $key ] ) && is_string( $s[ $key ] ) && $s[ $key ] !== '' ) {
				$plain = wp_strip_all_tags( $s[ $key ] );
				return mb_strimwidth( $plain, 0, 80, '...' );
			}
		}
		return '';
	}

	/**
	 * Enumerate saved templates from the Elementor Library CPT.
	 */
	private static function list_local_templates( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new \Exception( 'edit_posts capability required.' );
		}
		$type_filter = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : '';
		$limit = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 50;

		$query_args = [
			'post_type'      => 'elementor_library',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		];
		if ( $type_filter !== '' ) {
			$query_args['tax_query'] = [
				[
					'taxonomy' => 'elementor_library_type',
					'field'    => 'slug',
					'terms'    => $type_filter,
				],
			];
		}
		$posts = get_posts( $query_args );

		$templates = [];
		foreach ( $posts as $tpl ) {
			$terms = wp_get_post_terms( $tpl->ID, 'elementor_library_type', [ 'fields' => 'slugs' ] );
			$templates[] = [
				'id'            => (int) $tpl->ID,
				'name'          => $tpl->post_title,
				'type'          => is_array( $terms ) && ! is_wp_error( $terms ) && ! empty( $terms ) ? (string) $terms[0] : 'page',
				'date_modified' => $tpl->post_modified_gmt,
			];
		}

		return [
			'count'     => count( $templates ),
			'templates' => $templates,
		];
	}

	/**
	 * Create a new Elementor template from a JSON payload.
	 */
	private static function import_template( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new \Exception( 'edit_posts capability required.' );
		}
		$title = sanitize_text_field( $args['title'] ?? '' );
		$template_type = isset( $args['template_type'] ) ? sanitize_key( $args['template_type'] ) : 'page';
		$template_json = (string) ( $args['template_json'] ?? '' );
		if ( $title === '' || $template_json === '' ) {
			throw new \Exception( 'title and template_json are required.' );
		}

		$decoded = json_decode( $template_json, true );
		if ( ! is_array( $decoded ) ) {
			throw new \Exception( 'template_json must be a JSON-encoded array of Elementor elements.' );
		}
		// If the payload is the full Elementor export shape ({ 'content': [...], 'page_settings': {...}, ... })
		// extract content. Otherwise assume it's the bare elements array.
		if ( isset( $decoded['content'] ) && is_array( $decoded['content'] ) ) {
			$elements = $decoded['content'];
		} else {
			$elements = $decoded;
		}

		// Regenerate IDs to avoid collisions if the template was exported from the same site.
		$elements = self::regenerate_element_ids( $elements );

		// Create the template post in the elementor_library CPT.
		$new_id = wp_insert_post( [
			'post_title'  => $title,
			'post_status' => 'publish',
			'post_type'   => 'elementor_library',
			'post_author' => get_current_user_id(),
		], true );
		if ( is_wp_error( $new_id ) ) {
			throw new \Exception( $new_id->get_error_message() );
		}

		// Set the template type taxonomy.
		wp_set_object_terms( $new_id, $template_type, 'elementor_library_type' );

		// Set Elementor meta.
		update_post_meta( $new_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
		update_post_meta( $new_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $new_id, '_elementor_template_type', $template_type );

		return [
			'success'       => true,
			'template_id'   => (int) $new_id,
			'title'         => $title,
			'template_type' => $template_type,
			'edit_url'      => admin_url( 'post.php?post=' . $new_id . '&action=elementor' ),
		];
	}

	// ============================================================
	// add_widget — dual-surface widget insertion (1.4.29)
	// ============================================================

	/**
	 * Curated widget types — when widget_type is one of these and `settings` is
	 * not supplied, the tool builds the canonical settings object from flat
	 * curated params. When `settings` IS supplied, raw wins (curated params ignored).
	 */
	private static $curated_widget_types = [
		'container', 'heading', 'text-editor', 'button', 'image',
		'image-box', 'icon-box', 'icon-list', 'video', 'divider', 'spacer',
	];

	/**
	 * Main entry point — add a widget or container to an Elementor page.
	 */
	private static function add_widget( $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$widget_type = isset( $args['widget_type'] ) ? sanitize_key( $args['widget_type'] ) : '';
		if ( $post_id <= 0 || $widget_type === '' ) {
			throw new \Exception( 'post_id and widget_type are required.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new \Exception( 'edit_post capability required on target post.' );
		}
		$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $elementor_data ) ) {
			throw new \Exception( 'Target post does not have Elementor data — was it edited with Elementor?' );
		}
		$tree = is_string( $elementor_data ) ? json_decode( $elementor_data, true ) : $elementor_data;
		if ( ! is_array( $tree ) ) {
			throw new \Exception( 'Could not parse _elementor_data as a JSON array.' );
		}

		// Build the new element (raw vs curated, recursive for container children).
		$new_element = self::build_element_from_args( $args );

		// Targeting.
		$parent_id = isset( $args['parent_id'] ) ? (string) $args['parent_id'] : null;
		$position = isset( $args['position'] ) ? (int) $args['position'] : null;
		if ( $parent_id !== null ) {
			$parent = self::find_element_by_id( $tree, $parent_id );
			if ( $parent === null ) {
				throw new \Exception( 'parent_id not found in this page: ' . esc_html( $parent_id ) );
			}
			if ( ! isset( $parent['elType'] ) || ! in_array( $parent['elType'], [ 'container', 'section', 'column' ], true ) ) {
				throw new \Exception( 'parent_id must reference a container, section, or column. Found: ' . esc_html( $parent['elType'] ?? 'unknown' ) );
			}
			// If we're inserting a container under another container, mark as inner.
			if ( $new_element['elType'] === 'container' && $parent['elType'] === 'container' ) {
				$new_element['isInner'] = true;
			}
		}

		// Insert.
		$tree = self::insert_into_tree( $tree, $parent_id, $position, $new_element );

		// Save.
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );

		$notice = ! empty( $args['settings'] ) && in_array( $widget_type, self::$curated_widget_types, true )
			? 'Raw settings supplied for a curated widget_type — curated params were ignored. To use curated, omit the settings parameter.'
			: null;

		$response = [
			'success'      => true,
			'post_id'      => $post_id,
			'new_id'       => (string) $new_element['id'],
			'widget_type'  => $widget_type,
			'parent_id'    => $parent_id,
			'position'     => $position,
			'edit_url'     => admin_url( 'post.php?post=' . $post_id . '&action=elementor' ),
		];
		if ( $notice !== null ) {
			$response['notice'] = $notice;
		}
		return $response;
	}

	/**
	 * Build the element shape (recursive for container children) from args.
	 * Routes to raw or curated path based on whether `settings` was supplied.
	 */
	private static function build_element_from_args( $args ) {
		$widget_type = isset( $args['widget_type'] ) ? sanitize_key( $args['widget_type'] ) : '';
		if ( $widget_type === '' ) {
			throw new \Exception( 'widget_type is required for every element (including children).' );
		}

		// Raw path: explicit settings supplied → use them verbatim.
		// Curated path: settings absent + widget_type in curated list → build canonical settings.
		// Pure raw for unknown widget_types: settings required.
		$is_curated = in_array( $widget_type, self::$curated_widget_types, true );
		$has_settings = isset( $args['settings'] ) && is_array( $args['settings'] );

		if ( ! $is_curated && ! $has_settings ) {
			throw new \Exception( 'widget_type "' . esc_html( $widget_type ) . '" is not curated — supply a `settings` object directly.' );
		}

		// Raw path: a typo'd widget_type with any settings object would otherwise
		// serialize into _elementor_data and render as a silent empty placeholder.
		// Reject unknown slugs at the boundary so the caller sees the failure.
		if ( ! $is_curated && ! self::is_known_widget_type( $widget_type ) ) {
			throw new \Exception(
				'widget_type "' . esc_html( $widget_type ) . '" is not registered with Elementor on this site. '
				. 'Use a curated type (' . implode( ', ', self::$curated_widget_types ) . '), '
				. 'an Elementor V4 atomic widget (a-* / e-*), '
				. 'or any widget slug returned by Elementor\'s widget registry.'
			);
		}

		if ( $has_settings ) {
			// Raw path.
			$settings = $args['settings'];
			$el_type = $widget_type === 'container' ? 'container' : 'widget';
		} else {
			// Curated path.
			$settings = self::build_curated_settings( $widget_type, $args );
			$el_type = $widget_type === 'container' ? 'container' : 'widget';
		}

		$element = [
			'id'       => self::generate_element_id(),
			'elType'   => $el_type,
			'settings' => is_array( $settings ) ? $settings : (object) [],
			'elements' => [],
			'isInner'  => false,
		];
		if ( $el_type === 'widget' ) {
			$element['widgetType'] = $widget_type;
		}

		// Container children — both raw and curated paths support inline children.
		// Raw path: $args['children'] OR $settings does NOT carry children (children live at envelope level).
		// Curated path: $args['children'] populates the elements array recursively.
		if ( $widget_type === 'container' && isset( $args['children'] ) && is_array( $args['children'] ) ) {
			foreach ( $args['children'] as $child_args ) {
				if ( ! is_array( $child_args ) ) {
					continue;
				}
				$child = self::build_element_from_args( $child_args );
				if ( $child['elType'] === 'container' ) {
					$child['isInner'] = true;
				}
				$element['elements'][] = $child;
			}
		}

		return $element;
	}

	/**
	 * Whether the widget_type is one we'll let through the raw path: an Editor V4
	 * atomic widget (opaque passthrough by design — schema not publicly documented),
	 * or a slug currently registered with Elementor's widget manager (covers standard
	 * widgets, third-party widget plugins, and the wp-widget-* legacy bridge).
	 * Fail-open if the Elementor class or registry method is unreachable, so a
	 * transient autoloader miss can't block writes that would have succeeded before.
	 */
	private static function is_known_widget_type( $widget_type ) {
		if ( strpos( $widget_type, 'a-' ) === 0 || strpos( $widget_type, 'e-' ) === 0 ) {
			return true;
		}
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return true;
		}
		$manager = isset( \Elementor\Plugin::$instance ) ? \Elementor\Plugin::$instance->widgets_manager : null;
		if ( ! $manager || ! method_exists( $manager, 'get_widget_types' ) ) {
			return true;
		}
		$registered = $manager->get_widget_types();
		return is_array( $registered ) && isset( $registered[ $widget_type ] );
	}

	/**
	 * Curated → settings dispatcher. Each curated widget has its own builder.
	 */
	private static function build_curated_settings( $widget_type, $args ) {
		switch ( $widget_type ) {
			case 'container':   return self::curated_container( $args );
			case 'heading':     return self::curated_heading( $args );
			case 'text-editor': return self::curated_text_editor( $args );
			case 'button':      return self::curated_button( $args );
			case 'image':       return self::curated_image( $args );
			case 'image-box':   return self::curated_image_box( $args );
			case 'icon-box':    return self::curated_icon_box( $args );
			case 'icon-list':   return self::curated_icon_list( $args );
			case 'video':       return self::curated_video( $args );
			case 'divider':     return self::curated_divider( $args );
			case 'spacer':      return self::curated_spacer( $args );
		}
		// Should be unreachable — caller pre-checks against the curated list.
		throw new \Exception( 'No curated builder for widget_type: ' . esc_html( $widget_type ) );
	}

	// ----- Curated builders -----

	private static function curated_container( $args ) {
		$flex_direction = isset( $args['flex_direction'] ) && in_array( $args['flex_direction'], [ 'row', 'column' ], true )
			? $args['flex_direction'] : 'column';
		$content_width = isset( $args['content_width'] ) && in_array( $args['content_width'], [ 'boxed', 'full' ], true )
			? $args['content_width'] : 'boxed';
		return [
			'content_width'  => $content_width,
			'flex_direction' => $flex_direction,
		];
	}

	private static function curated_heading( $args ) {
		$title = isset( $args['title'] ) ? (string) $args['title'] : '';
		if ( $title === '' ) {
			throw new \Exception( 'Curated heading requires `title`.' );
		}
		$header_size = isset( $args['header_size'] ) && in_array( $args['header_size'], [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ], true )
			? $args['header_size'] : 'h2';
		return [
			'title'       => $title,
			'header_size' => $header_size,
		];
	}

	private static function curated_text_editor( $args ) {
		$editor = isset( $args['editor'] ) ? (string) $args['editor'] : '';
		if ( $editor === '' ) {
			throw new \Exception( 'Curated text-editor requires `editor` (HTML content).' );
		}
		return [ 'editor' => $editor ];
	}

	private static function curated_button( $args ) {
		$text = isset( $args['text'] ) ? (string) $args['text'] : '';
		$link_url = isset( $args['link_url'] ) ? esc_url_raw( $args['link_url'] ) : '';
		if ( $text === '' || $link_url === '' ) {
			throw new \Exception( 'Curated button requires `text` and `link_url`.' );
		}
		$target = isset( $args['link_target'] ) ? (string) $args['link_target'] : '_self';
		return [
			'text' => $text,
			'link' => self::wrap_link( $link_url, $target ),
		];
	}

	private static function curated_image( $args ) {
		$image_url = isset( $args['image_url'] ) ? esc_url_raw( $args['image_url'] ) : '';
		if ( $image_url === '' ) {
			throw new \Exception( 'Curated image requires `image_url`.' );
		}
		$image_alt = isset( $args['image_alt'] ) ? sanitize_text_field( $args['image_alt'] ) : '';
		$settings = [
			'image' => self::wrap_image( $image_url, $image_alt ),
		];
		if ( ! empty( $args['link_url'] ) ) {
			$target = isset( $args['link_target'] ) ? (string) $args['link_target'] : '_self';
			$settings['link_to'] = 'custom';
			$settings['link'] = self::wrap_link( esc_url_raw( $args['link_url'] ), $target );
		}
		return $settings;
	}

	private static function curated_image_box( $args ) {
		$image_url = isset( $args['image_url'] ) ? esc_url_raw( $args['image_url'] ) : '';
		$title_text = isset( $args['title_text'] ) ? (string) $args['title_text'] : '';
		if ( $image_url === '' || $title_text === '' ) {
			throw new \Exception( 'Curated image-box requires `image_url` and `title_text`.' );
		}
		$image_alt = isset( $args['image_alt'] ) ? sanitize_text_field( $args['image_alt'] ) : '';
		$description_text = isset( $args['description_text'] ) ? (string) $args['description_text'] : '';
		$title_size = isset( $args['title_size'] ) && in_array( $args['title_size'], [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ], true )
			? $args['title_size'] : 'h3';
		$settings = [
			'image'            => self::wrap_image( $image_url, $image_alt ),
			'title_text'       => $title_text,
			'description_text' => $description_text,
			'title_size'       => $title_size,
		];
		if ( ! empty( $args['link_url'] ) ) {
			$target = isset( $args['link_target'] ) ? (string) $args['link_target'] : '_self';
			$settings['link'] = self::wrap_link( esc_url_raw( $args['link_url'] ), $target );
		}
		return $settings;
	}

	private static function curated_icon_box( $args ) {
		$icon = isset( $args['icon'] ) ? (string) $args['icon'] : '';
		$title_text = isset( $args['title_text'] ) ? (string) $args['title_text'] : '';
		if ( $icon === '' || $title_text === '' ) {
			throw new \Exception( 'Curated icon-box requires `icon` and `title_text`.' );
		}
		$description_text = isset( $args['description_text'] ) ? (string) $args['description_text'] : '';
		$title_size = isset( $args['title_size'] ) && in_array( $args['title_size'], [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ], true )
			? $args['title_size'] : 'h3';
		$settings = [
			'selected_icon'    => [
				'value'   => $icon,
				'library' => self::derive_icon_library( $icon ),
			],
			'title_text'       => $title_text,
			'description_text' => $description_text,
			'title_size'       => $title_size,
		];
		if ( ! empty( $args['link_url'] ) ) {
			$target = isset( $args['link_target'] ) ? (string) $args['link_target'] : '_self';
			$settings['link'] = self::wrap_link( esc_url_raw( $args['link_url'] ), $target );
		}
		return $settings;
	}

	private static function curated_icon_list( $args ) {
		if ( empty( $args['items'] ) || ! is_array( $args['items'] ) ) {
			throw new \Exception( 'Curated icon-list requires `items` (array of {text, icon?, link_url?}).' );
		}
		$icon_list = [];
		foreach ( $args['items'] as $i => $item ) {
			if ( ! is_array( $item ) || empty( $item['text'] ) ) {
				throw new \Exception( 'icon-list item at index ' . (int) $i . ' missing required `text`.' );
			}
			$icon = isset( $item['icon'] ) && $item['icon'] !== '' ? (string) $item['icon'] : 'fas fa-check';
			$row = [
				'_id'           => self::generate_repeater_id(),
				'text'          => (string) $item['text'],
				'selected_icon' => [
					'value'   => $icon,
					'library' => self::derive_icon_library( $icon ),
				],
			];
			if ( ! empty( $item['link_url'] ) ) {
				$row['link'] = self::wrap_link( esc_url_raw( $item['link_url'] ), '_self' );
			}
			$icon_list[] = $row;
		}
		return [
			'icon_list' => $icon_list,
			'view'      => 'traditional',
		];
	}

	private static function curated_video( $args ) {
		$video_url = isset( $args['video_url'] ) ? (string) $args['video_url'] : '';
		if ( $video_url === '' ) {
			throw new \Exception( 'Curated video requires `video_url`.' );
		}
		$routed = self::route_video_url( $video_url );
		$aspect_ratio = isset( $args['aspect_ratio'] ) && in_array( $args['aspect_ratio'], [ '169', '219', '43', '32', '11', '916' ], true )
			? $args['aspect_ratio'] : '169';
		$settings = [
			'video_type'   => $routed['video_type'],
			$routed['url_field'] => $routed['url_value'],
			'aspect_ratio' => $aspect_ratio,
		];
		if ( ! empty( $args['autoplay'] ) ) {
			$settings['autoplay'] = 'yes';
		}
		return $settings;
	}

	private static function curated_divider( $args ) {
		$settings = [ 'style' => 'solid' ];
		if ( isset( $args['weight'] ) ) {
			$settings['weight'] = self::wrap_slider_px( (int) $args['weight'] );
		}
		if ( ! empty( $args['color'] ) ) {
			$settings['color'] = sanitize_hex_color( $args['color'] ) ?: (string) $args['color'];
		}
		return $settings;
	}

	private static function curated_spacer( $args ) {
		$space = isset( $args['space'] ) ? (int) $args['space'] : 50;
		return [ 'space' => self::wrap_slider_px( $space ) ];
	}

	// ----- Shape helpers -----

	/**
	 * Build an Elementor URL control object: { url, is_external, nofollow }.
	 */
	private static function wrap_link( $url, $target = '_self', $nofollow = false ) {
		return [
			'url'         => (string) $url,
			'is_external' => ( $target === '_blank' ) ? 'on' : '',
			'nofollow'    => $nofollow ? 'on' : '',
		];
	}

	/**
	 * Build an Elementor MEDIA control object: { url, id, alt, source, size }.
	 * External URLs use id='' (string) since there's no WP attachment.
	 */
	private static function wrap_image( $url, $alt = '' ) {
		return [
			'url'    => (string) $url,
			'id'     => '',
			'alt'    => (string) $alt,
			'source' => 'library',
			'size'   => '',
		];
	}

	/**
	 * Wrap an int as Elementor's SLIDER value shape: { size: N, unit: 'px' }.
	 */
	private static function wrap_slider_px( $size ) {
		return [ 'size' => (int) $size, 'unit' => 'px' ];
	}

	/**
	 * Derive the FontAwesome library identifier from an icon class.
	 * fas → fa-solid, far → fa-regular, fab → fa-brands. Unknown → fa-solid.
	 */
	private static function derive_icon_library( $icon_value ) {
		$icon_value = trim( (string) $icon_value );
		if ( strpos( $icon_value, 'fas ' ) === 0 ) {
			return 'fa-solid';
		}
		if ( strpos( $icon_value, 'far ' ) === 0 ) {
			return 'fa-regular';
		}
		if ( strpos( $icon_value, 'fab ' ) === 0 ) {
			return 'fa-brands';
		}
		return 'fa-solid';
	}

	/**
	 * Detect video source from URL and return matching Elementor field name + value.
	 * Supports youtube, vimeo, dailymotion. Self-hosted / VideoPress raise.
	 */
	private static function route_video_url( $url ) {
		if ( preg_match( '#(?:youtube\.com|youtu\.be)#i', $url ) ) {
			return [ 'video_type' => 'youtube', 'url_field' => 'youtube_url', 'url_value' => (string) $url ];
		}
		if ( preg_match( '#vimeo\.com#i', $url ) ) {
			return [ 'video_type' => 'vimeo', 'url_field' => 'vimeo_url', 'url_value' => (string) $url ];
		}
		if ( preg_match( '#dailymotion\.com#i', $url ) ) {
			return [ 'video_type' => 'dailymotion', 'url_field' => 'dailymotion_url', 'url_value' => (string) $url ];
		}
		throw new \Exception( 'Curated video supports YouTube, Vimeo, and Dailymotion URLs. For self-hosted or VideoPress, use the raw path with explicit settings (video_type + matching url field).' );
	}

	/**
	 * Generate a 7-char hex ID for repeater items. Elementor's editor uses
	 * 7-char hex IDs for icon-list repeater items (smaller than the 8-char
	 * element IDs used for elType=widget/container).
	 */
	private static function generate_repeater_id() {
		// 4 bytes = 8 hex chars; truncate to 7 to match Elementor's repeater convention.
		return substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
	}

	// ----- Tree manipulation -----

	/**
	 * Recursively search the element tree for an element with the given ID.
	 * Returns the matching element by reference? No — returns a copy for
	 * inspection. The insert path uses insert_into_tree which walks again
	 * and modifies in-place.
	 */
	private static function find_element_by_id( $tree, $id ) {
		if ( ! is_array( $tree ) ) {
			return null;
		}
		foreach ( $tree as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['id'] ) && (string) $el['id'] === (string) $id ) {
				return $el;
			}
			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$found = self::find_element_by_id( $el['elements'], $id );
				if ( $found !== null ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Insert a new element into the tree.
	 * If parent_id is null → append (or insert at $position) at top level.
	 * If parent_id is provided → recurse to that element and insert into its `elements`.
	 *
	 * Returns the mutated tree (not modified in place; rebuilt to preserve clean copy).
	 */
	private static function insert_into_tree( $tree, $parent_id, $position, $new_element ) {
		if ( $parent_id === null ) {
			return self::insert_at_position( $tree, $position, $new_element );
		}
		// Walk the tree, find parent, insert into its elements.
		return self::walk_and_insert( $tree, $parent_id, $position, $new_element );
	}

	private static function walk_and_insert( $tree, $parent_id, $position, $new_element ) {
		if ( ! is_array( $tree ) ) {
			return $tree;
		}
		$out = [];
		foreach ( $tree as $el ) {
			if ( ! is_array( $el ) ) {
				$out[] = $el;
				continue;
			}
			if ( isset( $el['id'] ) && (string) $el['id'] === (string) $parent_id ) {
				$children = isset( $el['elements'] ) && is_array( $el['elements'] ) ? $el['elements'] : [];
				$el['elements'] = self::insert_at_position( $children, $position, $new_element );
				$out[] = $el;
				continue;
			}
			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::walk_and_insert( $el['elements'], $parent_id, $position, $new_element );
			}
			$out[] = $el;
		}
		return $out;
	}

	private static function insert_at_position( $list, $position, $new_element ) {
		$count = count( $list );
		if ( $position === null || $position >= $count ) {
			$list[] = $new_element;
			return $list;
		}
		if ( $position <= 0 ) {
			array_unshift( $list, $new_element );
			return $list;
		}
		array_splice( $list, $position, 0, [ $new_element ] );
		return $list;
	}
}
