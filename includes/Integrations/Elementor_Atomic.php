<?php
/**
 * Elementor 4 atomic (V4) elements: text and image replacement in their typed
 * settings, typed setting values for the bulk setting tool, and the per-page
 * cache clear after a direct _elementor_data write.
 *
 * Atomic settings are stored as {"$$type": <type>, "value": <payload>}. Text
 * lives only in the text props named in TEXT_PROPS (other string props hold
 * tags, sizes, ids and URLs and are never touched); dynamic tags are left
 * alone. Rich text may be stored in any of the shapes Elementor has used
 * (string, html, html-v2, html-v3, escaped-html), as Elementor migrates old
 * data only when a page is next opened.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Elementor_Atomic {

	/** Props that hold visible text, per atomic widget type. */
	const TEXT_PROPS = [
		'e-heading'        => [ 'title' ],
		'e-paragraph'      => [ 'paragraph' ],
		'e-button'         => [ 'text' ],
		'e-form-label'     => [ 'text' ],
		'e-form-input'     => [ 'placeholder' ],
		'e-form-textarea'  => [ 'placeholder' ],
	];

	/** Text value types (current and older stored shapes). */
	const TEXT_TYPES = [ 'string', 'html', 'html-v2', 'html-v3', 'escaped-html' ];

	/** Whether an element is an Elementor 4 atomic element (widget or container). */
	public static function is_atomic( array $el ) {
		$type = (string) ( $el['widgetType'] ?? '' );
		if ( '' === $type ) {
			$el_type = (string) ( $el['elType'] ?? '' );
			$type    = in_array( $el_type, [ 'widget', 'container', 'section', 'column' ], true ) ? '' : $el_type;
		}
		return '' !== $type && ( 0 === strpos( $type, 'e-' ) || 0 === strpos( $type, 'a-' ) || 0 === strpos( $type, 'atomic-' ) );
	}

	/** The element's type name: widgetType for widgets, elType for containers. */
	public static function type_of( array $el ) {
		$type = (string) ( $el['widgetType'] ?? '' );
		return '' !== $type ? $type : (string) ( $el['elType'] ?? '' );
	}

	private static function is_typed( $v ) {
		return is_array( $v ) && isset( $v['$$type'] ) && is_string( $v['$$type'] ) && array_key_exists( 'value', $v );
	}

	private static function replace_string( $find, $replace, $subject, $case_insensitive, array &$counter ) {
		if ( ! is_string( $subject ) || '' === $subject || '' === $find ) {
			return $subject;
		}
		$n = 0;
		if ( $case_insensitive ) {
			$out = preg_replace( '/' . preg_quote( $find, '/' ) . '/iu', str_replace( [ '\\', '$' ], [ '\\\\', '\\$' ], $replace ), $subject, -1, $n );
			if ( null === $out ) {
				$out = str_ireplace( $find, $replace, $subject, $n ); // mb-check-ignore
			}
		} else {
			$out = str_replace( $find, $replace, $subject, $n );
		}
		$counter['count'] += (int) $n;
		return $out;
	}

	/** Replace text inside one typed text value; anything else is returned as it is. */
	private static function replace_in_text_value( $v, $find, $replace, $case_insensitive, array &$counter ) {
		if ( ! self::is_typed( $v ) ) {
			return $v;
		}
		$type = $v['$$type'];
		if ( 'overridable' === $type && is_array( $v['value'] ) && isset( $v['value']['origin_value'] ) ) {
			$v['value']['origin_value'] = self::replace_in_text_value( $v['value']['origin_value'], $find, $replace, $case_insensitive, $counter );
			return $v;
		}
		if ( ! in_array( $type, self::TEXT_TYPES, true ) ) {
			return $v; // dynamic tags, links, numbers, anything not text
		}
		if ( is_string( $v['value'] ) ) {
			$v['value'] = self::replace_string( $find, $replace, $v['value'], $case_insensitive, $counter );
			return $v;
		}
		if ( is_array( $v['value'] ) ) {
			// html-v2: content is a string; html-v3: content is a typed string.
			if ( isset( $v['value']['content'] ) ) {
				$v['value']['content'] = is_string( $v['value']['content'] )
					? self::replace_string( $find, $replace, $v['value']['content'], $case_insensitive, $counter )
					: self::replace_in_text_value( $v['value']['content'], $find, $replace, $case_insensitive, $counter );
			}
			if ( isset( $v['value']['children'] ) && is_array( $v['value']['children'] ) ) {
				$v['value']['children'] = self::replace_in_children( $v['value']['children'], $find, $replace, $case_insensitive, $counter );
			}
		}
		return $v;
	}

	private static function replace_in_children( array $children, $find, $replace, $case_insensitive, array &$counter ) {
		foreach ( $children as $i => $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			if ( isset( $child['content'] ) ) {
				$children[ $i ]['content'] = is_string( $child['content'] )
					? self::replace_string( $find, $replace, $child['content'], $case_insensitive, $counter )
					: self::replace_in_text_value( $child['content'], $find, $replace, $case_insensitive, $counter );
			}
			if ( isset( $child['children'] ) && is_array( $child['children'] ) ) {
				$children[ $i ]['children'] = self::replace_in_children( $child['children'], $find, $replace, $case_insensitive, $counter );
			}
		}
		return $children;
	}

	/**
	 * Replace text in an atomic element's settings: its text props, and the
	 * alt text of a URL image. Adds the number of replacements to $counter['count'].
	 */
	public static function replace_text( array $settings, $find, $replace, $case_insensitive, array &$counter, $type = '' ) {
		$keys = '' !== $type && isset( self::TEXT_PROPS[ $type ] ) ? self::TEXT_PROPS[ $type ] : array_unique( array_merge( ...array_values( self::TEXT_PROPS ) ) );
		foreach ( $keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$settings[ $key ] = self::replace_in_text_value( $settings[ $key ], $find, $replace, $case_insensitive, $counter );
			}
		}
		// Alt text is stored on the image only for URL images.
		if ( isset( $settings['image'] ) && self::is_typed( $settings['image'] ) && 'image' === $settings['image']['$$type']
			&& isset( $settings['image']['value']['src']['value']['alt'] ) ) {
			$settings['image']['value']['src']['value']['alt'] = self::replace_in_text_value( $settings['image']['value']['src']['value']['alt'], $find, $replace, $case_insensitive, $counter );
		}
		return $settings;
	}

	/**
	 * Swap an image in typed values anywhere below $node (settings or styles):
	 * image-src and svg-src matching the old attachment id or URL. A URL match
	 * becomes the new attachment id when one is given (an image holds an id or
	 * a URL, never both). Dynamic values are left alone.
	 */
	public static function replace_image( $node, $old_url, $new_url, $old_id, $new_id, array &$counter ) {
		if ( ! is_array( $node ) ) {
			return $node;
		}
		if ( self::is_typed( $node ) ) {
			if ( 'dynamic' === $node['$$type'] ) {
				return $node;
			}
			if ( in_array( $node['$$type'], [ 'image-src', 'svg-src' ], true ) && is_array( $node['value'] ) ) {
				$src    = $node['value'];
				$id     = isset( $src['id']['value'] ) ? (int) $src['id']['value'] : 0;
				$url    = isset( $src['url']['value'] ) && is_string( $src['url']['value'] ) ? $src['url']['value'] : '';
				$by_id  = $old_id > 0 && $id === (int) $old_id;
				$by_url = '' !== $old_url && '' !== $url && $url === $old_url;
				if ( $by_id || $by_url ) {
					if ( $new_id > 0 ) {
						$src['id'] = [ '$$type' => 'image-attachment-id', 'value' => (int) $new_id ];
						if ( 'image-src' === $node['$$type'] ) {
							$src['url'] = null;
						} elseif ( isset( $src['url'] ) && '' !== $new_url ) {
							$src['url'] = [ '$$type' => 'url', 'value' => $new_url ];
						}
					} elseif ( '' !== $new_url ) {
						$src['url'] = [ '$$type' => 'url', 'value' => $new_url ];
						if ( 'image-src' === $node['$$type'] ) {
							$src['id'] = null;
						}
					}
					$node['value'] = $src;
					$counter['count']++;
				}
				return $node;
			}
		}
		foreach ( $node as $k => $v ) {
			if ( is_array( $v ) ) {
				$node[ $k ] = self::replace_image( $v, $old_url, $new_url, $old_id, $new_id, $counter );
			}
		}
		return $node;
	}

	/**
	 * A typed value for an atomic widget's prop from a caller's value: a value
	 * already carrying the prop's $$type is checked as it is; a plain string,
	 * number or boolean is wrapped in the type the widget stores for that prop.
	 * Elementor's own prop type validates the result.
	 *
	 * @return array|\WP_Error
	 */
	public static function typed_value( $widget_type, $key, $existing, $value ) {
		$prop = self::prop_type( $widget_type, $key );
		if ( null === $prop ) {
			return new \WP_Error( 'unknown_prop', sprintf( '%s has no setting %s.', $widget_type, $key ) );
		}
		if ( ! self::is_typed( $value ) ) {
			$stored = self::is_typed( $existing ) ? $existing['$$type'] : '';
			if ( '' === $stored && is_object( $prop ) && method_exists( $prop, 'get_prop_types' ) ) {
				// A union (text props are unions with dynamic tags): its first plain member.
				$members = array_diff( array_keys( (array) $prop->get_prop_types() ), [ 'dynamic', 'overridable' ] );
				$stored  = (string) reset( $members );
			} elseif ( '' === $stored && is_object( $prop ) && method_exists( $prop, 'get_key' ) ) {
				$stored = (string) $prop->get_key();
			}
			$scalar_ok = ( is_string( $value ) && in_array( $stored, array_merge( self::TEXT_TYPES, [ 'url' ] ), true ) )
				|| ( ( is_int( $value ) || is_float( $value ) ) && 'number' === $stored )
				|| ( is_bool( $value ) && 'boolean' === $stored );
			if ( ! $scalar_ok || in_array( $stored, [ 'html-v2', 'html-v3' ], true ) ) {
				return new \WP_Error( 'invalid_value_for_prop', sprintf( 'Setting %s of %s takes a {"$$type", "value"} object (it stores %s).', $key, $widget_type, '' !== $stored ? $stored : 'a typed value' ) );
			}
			$value = [ '$$type' => $stored, 'value' => $value ];
		}
		if ( is_object( $prop ) && method_exists( $prop, 'validate' ) && ! $prop->validate( $value ) ) {
			return new \WP_Error( 'invalid_value_for_prop', sprintf( 'Elementor does not accept this value for setting %s of %s.', $key, $widget_type ) );
		}
		if ( is_object( $prop ) && method_exists( $prop, 'sanitize' ) ) {
			$value = $prop->sanitize( $value );
		}
		return $value;
	}

	/** Elementor's prop type for one setting of an atomic widget or element, or null. */
	public static function prop_type( $type, $key ) {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return null;
		}
		$plugin   = \Elementor\Plugin::$instance;
		$instance = isset( $plugin->widgets_manager ) ? $plugin->widgets_manager->get_widget_types( $type ) : null;
		if ( ! $instance && isset( $plugin->elements_manager ) ) {
			$instance = $plugin->elements_manager->get_element_types( $type );
		}
		if ( ! $instance || ! method_exists( $instance, 'get_props_schema' ) ) {
			return null;
		}
		$schema = $instance::get_props_schema();
		return isset( $schema[ $key ] ) ? $schema[ $key ] : null;
	}

	/** Clear a page's rendered-element cache and CSS after a direct _elementor_data write. */
	public static function clear_post_cache( $post_id ) {
		$post_id = (int) $post_id;
		delete_post_meta( $post_id, '_elementor_element_cache' );
		if ( class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
			\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
		} else {
			delete_post_meta( $post_id, '_elementor_css' );
		}
		do_action( 'elementor/atomic-widgets/styles/clear', [ 'local', $post_id ] );
	}
}
