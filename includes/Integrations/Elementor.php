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

			case 'elementor_list_local_templates':
				return self::list_local_templates( $args );

			case 'elementor_import_template':
				return self::import_template( $args );

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
}
