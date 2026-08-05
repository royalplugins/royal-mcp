<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Avada / Fusion Builder MCP Integration
 *
 * Avada stores page-builder state as nested shortcodes in post_content, at
 * roughly 100:1 markup-to-copy overhead — a 551-word post can carry a 55 KB
 * body, a pricing page over 1 MB. These tools separate copy from layout so an
 * agent can read and edit the prose without transferring or re-transmitting
 * the scaffolding.
 *
 * Parsing strategy: a minimal self-contained shortcode scan, NOT Avada's own
 * parser. Deliberate: the tools must work on REST/MCP requests where the
 * theme's builder classes may not be bootstrapped, must never depend on
 * Avada internals that change between releases, and only need two guarantees
 * Avada's parser can't improve on — [fusion_text] / [fusion_title] do not
 * nest within themselves, and attribute strings never contain ']'.
 * Everything outside the targeted block is preserved byte-for-byte.
 *
 * Tools (registered only when Avada / Fusion Builder is active):
 *  - fusion_get_text_blocks     — extract [fusion_text] / [fusion_title] copy
 *  - fusion_update_text_block   — write back ONE block's inner content
 *  - fusion_update_attribute    — set ONE attribute on the nth [tag ...] opener
 */
class Fusion {

	/**
	 * Tags whose inner content is human copy. Order-independent; blocks are
	 * indexed by document position across both tags combined.
	 */
	const TEXT_TAGS = array( 'fusion_text', 'fusion_title' );

	/**
	 * Check if Avada / Fusion Builder is available.
	 */
	public static function is_available() {
		if ( defined( 'FUSION_BUILDER_VERSION' ) || class_exists( 'FusionBuilder' ) ) {
			return true;
		}
		if ( defined( 'AVADA_VERSION' ) ) {
			return true;
		}
		$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		if ( $theme && ( 'Avada' === $theme->get( 'Name' ) || 'Avada' === $theme->get_template() ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Get tool definitions for MCP tools/list response.
	 */
	public static function get_tools() {
		if ( ! self::is_available() ) {
			return array();
		}
		return array(
			array(
				'name'        => 'fusion_get_text_blocks',
				'description' => 'Extract the human-readable copy from an Avada/Fusion Builder post: inner content of every [fusion_text] and [fusion_title] element, in document order, without the layout scaffolding. On builder-heavy pages this returns kilobytes instead of the megabyte-scale full body. Use the returned index with fusion_update_text_block to edit a block. Read-only.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'post_id' ),
				),
			),
			array(
				'name'        => 'fusion_update_text_block',
				'description' => 'Replace the inner content of ONE [fusion_text] or [fusion_title] block, addressed by the index from fusion_get_text_blocks. All shortcode attributes and everything outside the block are preserved byte-for-byte. Pass expected_current (the block\'s current inner content) to abort if the block changed since it was read — recommended for every write. Response verifies stored content read-after-write.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'          => array( 'type' => 'integer' ),
						'index'            => array( 'type' => 'integer', 'description' => 'Block index from fusion_get_text_blocks (0-based, document order).' ),
						'content'          => array( 'type' => 'string', 'description' => 'New inner content for the block (replaces the old inner content verbatim).' ),
						'expected_current' => array( 'type' => 'string', 'description' => 'Optional stale-read guard: abort without writing unless the block\'s current inner content equals this exactly.' ),
					),
					'required'   => array( 'post_id', 'index', 'content' ),
				),
			),
			array(
				'name'        => 'fusion_update_attribute',
				'description' => 'Set a single attribute on the nth opening tag of a given Fusion shortcode (e.g. link= on the 3rd [fusion_builder_column]) without touching anything else — avoids re-transmitting the full body and the silent-layout-break risk of hand-reassembling flags like first="true". Adds the attribute if absent, replaces its value if present. Counting is per-tag in document order (0-based).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'tag'       => array( 'type' => 'string', 'description' => 'Fusion shortcode tag, e.g. fusion_builder_column, fusion_button.' ),
						'index'     => array( 'type' => 'integer', 'description' => 'Which occurrence of the tag to modify (0-based, document order).' ),
						'attribute' => array( 'type' => 'string', 'description' => 'Attribute name, e.g. link, first, background_color.' ),
						'value'     => array( 'type' => 'string', 'description' => 'New attribute value. Must not contain double quotes or ].' ),
					),
					'required'   => array( 'post_id', 'tag', 'index', 'attribute', 'value' ),
				),
			),
		);
	}

	/**
	 * Execute a Fusion tool.
	 */
	public static function execute_tool( $name, $args ) {
		if ( ! self::is_available() ) {
			throw new \Exception( 'Avada / Fusion Builder is not active' );
		}
		switch ( $name ) {
			case 'fusion_get_text_blocks':
				return self::get_text_blocks( $args );
			case 'fusion_update_text_block':
				return self::update_text_block( $args );
			case 'fusion_update_attribute':
				return self::update_attribute( $args );
			default:
				throw new \Exception( 'Unknown Fusion tool: ' . esc_html( $name ) );
		}
	}

	// ============================================================
	// Tool implementations
	// ============================================================

	/**
	 * Extract text blocks from a post's content.
	 */
	private static function get_text_blocks( $args ) {
		$post = self::require_post( $args );
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			throw new \Exception( 'You do not have permission to read this post.' );
		}
		$blocks = self::parse_text_blocks( (string) $post->post_content );
		$out = array();
		foreach ( $blocks as $i => $b ) {
			$out[] = array(
				'index'          => $i,
				'tag'            => $b['tag'],
				'admin_label'    => $b['admin_label'],
				'inner_content'  => $b['inner'],
				'content_length' => strlen( $b['inner'] ),
			);
		}
		return $out;
	}

	/**
	 * Replace the inner content of one text block.
	 */
	private static function update_text_block( $args ) {
		$post = self::require_post( $args );
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			throw new \Exception( 'You do not have permission to edit this post.' );
		}
		if ( ! array_key_exists( 'index', $args ) ) {
			throw new \Exception( 'index is required.' );
		}
		$index = intval( $args['index'] );
		if ( ! array_key_exists( 'content', $args ) || ! is_string( $args['content'] ) ) {
			throw new \Exception( 'content must be a string.' );
		}
		$new_inner = $args['content'];

		$content = (string) $post->post_content;
		$blocks  = self::parse_text_blocks( $content );
		if ( $index < 0 || $index >= count( $blocks ) ) {
			throw new \Exception( sprintf( 'index %d out of range - post has %d text block(s). Re-run fusion_get_text_blocks.', $index, count( $blocks ) ) );
		}
		$block = $blocks[ $index ];
		if ( array_key_exists( 'expected_current', $args ) && (string) $args['expected_current'] !== $block['inner'] ) {
			throw new \Exception( 'expected_current does not match the block\'s current content - the post changed since it was read. Re-run fusion_get_text_blocks; nothing written.' );
		}
		if ( $new_inner === $block['inner'] ) {
			throw new \Exception( 'content is identical to the block\'s current content; nothing to do.' );
		}

		// Splice by byte offsets from the parse — everything outside the
		// block's inner span is preserved byte-for-byte.
		$new_content = substr( $content, 0, $block['inner_start'] ) . $new_inner . substr( $content, $block['inner_end'] );
		$result = wp_update_post( array( 'ID' => $post->ID, 'post_content' => wp_slash( $new_content ) ), true );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( esc_html( $result->get_error_message() ) );
		}
		$stored   = (string) get_post( $post->ID )->post_content;
		$verified = ( $stored === $new_content );
		$response = array(
			'index'    => $index,
			'tag'      => $block['tag'],
			'before'   => $block['inner'],
			'after'    => $new_inner,
			'verified' => $verified,
			'message'  => 'Text block updated.',
		);
		if ( ! $verified ) {
			$response['modified_by_wp'] = 'Stored content differs from the computed result (sanitization or a content filter modified it on save). Re-run fusion_get_text_blocks to inspect.';
		}
		return $response;
	}

	/**
	 * Set one attribute on the nth opening tag of a Fusion shortcode.
	 */
	private static function update_attribute( $args ) {
		$post = self::require_post( $args );
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			throw new \Exception( 'You do not have permission to edit this post.' );
		}
		$tag = sanitize_key( $args['tag'] ?? '' );
		if ( ! preg_match( '/^fusion_[a-z0-9_]+$/', $tag ) ) {
			throw new \Exception( 'tag must be a fusion_* shortcode tag.' );
		}
		$attribute = strtolower( (string) ( $args['attribute'] ?? '' ) );
		if ( ! preg_match( '/^[a-z0-9_\-]+$/', $attribute ) ) {
			throw new \Exception( 'attribute must contain only letters, numbers, underscores, and hyphens.' );
		}
		if ( ! array_key_exists( 'index', $args ) ) {
			throw new \Exception( 'index is required.' );
		}
		$index = intval( $args['index'] );
		if ( ! isset( $args['value'] ) || ! is_string( $args['value'] ) ) {
			throw new \Exception( 'value must be a string.' );
		}
		$value = $args['value'];
		// Shortcode attribute values cannot contain these without breaking
		// the surrounding markup — reject rather than mangle.
		if ( strpos( $value, '"' ) !== false || strpos( $value, ']' ) !== false ) {
			throw new \Exception( 'value must not contain double quotes or "]".' );
		}

		$content = (string) $post->post_content;
		// Opening tags only: "[tag" followed by whitespace+attrs or an
		// immediate "]". A closing tag "[/tag]" cannot match.
		if ( ! preg_match_all( '/\[' . preg_quote( $tag, '/' ) . '(\s[^\]]*)?\]/', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			throw new \Exception( 'No [' . esc_html( $tag ) . '] elements found in this post.' );
		}
		if ( $index < 0 || $index >= count( $m[0] ) ) {
			throw new \Exception( sprintf( 'index %d out of range - post has %d [%s] element(s).', $index, count( $m[0] ), esc_html( $tag ) ) );
		}
		$full   = $m[0][ $index ][0];
		$offset = $m[0][ $index ][1];
		$attrs  = isset( $m[1][ $index ][0] ) && is_string( $m[1][ $index ][0] ) ? $m[1][ $index ][0] : '';

		$before = null;
		$pattern = '/(\s' . preg_quote( $attribute, '/' ) . '=")([^"]*)(")/';
		if ( preg_match( $pattern, $attrs, $am ) ) {
			$before    = $am[2];
			$new_attrs = preg_replace( $pattern, '${1}' . self::preg_replacement_literal( $value ) . '${3}', $attrs, 1 );
		} else {
			$new_attrs = rtrim( $attrs ) . ' ' . $attribute . '="' . $value . '"';
		}
		if ( $before === $value ) {
			throw new \Exception( 'Attribute already has this exact value; nothing to do.' );
		}
		$new_opener  = '[' . $tag . $new_attrs . ']';
		$new_content = substr( $content, 0, $offset ) . $new_opener . substr( $content, $offset + strlen( $full ) );

		$result = wp_update_post( array( 'ID' => $post->ID, 'post_content' => wp_slash( $new_content ) ), true );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( esc_html( $result->get_error_message() ) );
		}
		$stored   = (string) get_post( $post->ID )->post_content;
		$verified = ( $stored === $new_content );
		return array(
			'tag'       => $tag,
			'index'     => $index,
			'attribute' => $attribute,
			'before'    => $before,
			'after'     => $value,
			'added'     => ( null === $before ),
			'verified'  => $verified,
			'message'   => 'Attribute updated.',
		);
	}

	// ============================================================
	// Helpers
	// ============================================================

	/**
	 * Resolve and validate the target post.
	 */
	private static function require_post( $args ) {
		$post_id = intval( $args['post_id'] ?? $args['id'] ?? 0 );
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post ) {
			throw new \Exception( 'Post not found.' );
		}
		return $post;
	}

	/**
	 * Scan post_content for text-bearing Fusion blocks.
	 *
	 * Returns, per block in document order: tag, admin_label (own attribute
	 * if present, else ''), inner content, and the byte offsets of the inner
	 * span so writers can splice without touching anything else.
	 * Non-greedy inner match is safe because neither tag nests within itself.
	 */
	private static function parse_text_blocks( $content ) {
		$tags = implode( '|', array_map( 'preg_quote', self::TEXT_TAGS ) );
		if ( ! preg_match_all(
			'/\[(' . $tags . ')(\s[^\]]*)?\](.*?)\[\/\1\]/s',
			$content,
			$m,
			PREG_OFFSET_CAPTURE | PREG_SET_ORDER
		) ) {
			return array();
		}
		$blocks = array();
		foreach ( $m as $match ) {
			$tag         = $match[1][0];
			$attrs       = isset( $match[2][0] ) && is_string( $match[2][0] ) ? $match[2][0] : '';
			$inner       = $match[3][0];
			$inner_start = $match[3][1];
			$admin_label = '';
			if ( $attrs && preg_match( '/\sadmin_label="([^"]*)"/', $attrs, $lm ) ) {
				$admin_label = $lm[1];
			}
			$blocks[] = array(
				'tag'         => $tag,
				'admin_label' => $admin_label,
				'inner'       => $inner,
				'inner_start' => $inner_start,
				'inner_end'   => $inner_start + strlen( $inner ),
			);
		}
		return $blocks;
	}

	/**
	 * Escape a literal string for use as a preg_replace replacement.
	 */
	private static function preg_replacement_literal( $value ) {
		return strtr( $value, array( '\\' => '\\\\', '$' => '\\$' ) );
	}
}
