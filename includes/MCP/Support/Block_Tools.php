<?php
/**
 * Block editor tools: read a post's block tree, check block markup, and change
 * blocks by path without breaking them in the editor.
 *
 * The editor checks a static block by running its JavaScript save() and
 * comparing the result with the stored markup. The server cannot run save(), so
 * every write here keeps each block's markup and attributes consistent: blocks
 * are inserted, replaced, moved or deleted whole; text is replaced in a block's
 * markup and attributes together; and attributes are changed only on blocks that
 * store no markup. Each write checks the edit lock, refuses changes WordPress
 * would strip for the current user, saves a revision and returns an undo token.
 *
 * Paths count named blocks from 0, one number per level: "2" is the third
 * top-level block, "2.0" its first inner block.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Block_Tools {

	const OUTLINE_LIMIT  = 500;
	const MAX_MARKUP_BYTES = 2097152;
	const MAX_DEPTH      = 100;
	const PREVIEW_CHARS  = 80;
	const LIST_LIMIT     = 100;

	/** Tool names handled here. */
	const TOOLS = [
		'wp_get_block_outline', 'wp_list_block_types', 'wp_validate_blocks',
		'wp_insert_blocks', 'wp_replace_block', 'wp_move_block', 'wp_delete_block',
		'wp_replace_text_in_blocks', 'wp_update_block_attributes',
		'wp_list_synced_patterns', 'wp_get_synced_pattern', 'wp_create_synced_pattern', 'wp_update_synced_pattern',
		'wp_get_global_styles', 'wp_list_block_templates', 'wp_get_block_template',
	];

	/**
	 * Run one of the block tools.
	 *
	 * @param string $name Tool name.
	 * @param array  $args Tool arguments.
	 * @return array
	 * @throws \Exception With a message for the caller.
	 */
	public static function run( $name, array $args ) {
		switch ( $name ) {
			case 'wp_get_block_outline':
				return self::outline_tool( $args );
			case 'wp_list_block_types':
				return self::list_types( $args );
			case 'wp_validate_blocks':
				return self::validate_tool( $args );
			case 'wp_insert_blocks':
				return self::insert_tool( $args );
			case 'wp_replace_block':
				return self::replace_tool( $args );
			case 'wp_move_block':
				return self::move_tool( $args );
			case 'wp_delete_block':
				return self::delete_tool( $args );
			case 'wp_replace_text_in_blocks':
				return self::replace_text_tool( $args );
			case 'wp_update_block_attributes':
				return self::attributes_tool( $args );
			case 'wp_list_synced_patterns':
				return self::list_patterns( $args );
			case 'wp_get_synced_pattern':
				return self::get_pattern( $args );
			case 'wp_create_synced_pattern':
				return self::create_pattern( $args );
			case 'wp_update_synced_pattern':
				return self::update_pattern( $args );
			case 'wp_get_global_styles':
				return self::global_styles();
			case 'wp_list_block_templates':
				return self::list_templates( $args );
			case 'wp_get_block_template':
				return self::get_template( $args );
		}
		throw new \Exception( 'Unknown block tool.' );
	}

	/* ------------------------------------------------------------------
	 *  Reads
	 * ------------------------------------------------------------------ */

	private static function outline_tool( array $args ) {
		$post = self::readable_post( $args );
		$tree = parse_blocks( (string) $post->post_content );
		$from = [];
		if ( isset( $args['path'] ) && '' !== (string) $args['path'] ) {
			$from  = self::parse_path( $args['path'] );
			$block = self::block_at( $tree, $from );
			if ( null === $block ) {
				throw new \Exception( 'No block at path ' . implode( '.', $from ) . '.' );
			}
			$tree = [ $block ];
		}
		$depth   = isset( $args['max_depth'] ) ? max( 1, (int) $args['max_depth'] ) : 0;
		$entries = [];
		$prefix  = $from ? array_slice( $from, 0, -1 ) : [];
		$start   = $from ? (int) end( $from ) : 0;
		self::outline_walk( $tree, $prefix, $depth, $entries, $from ? $start : null );
		$stats = self::stats( parse_blocks( (string) $post->post_content ) );
		$out   = [
			'id'        => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'blocks'    => array_slice( $entries, 0, self::OUTLINE_LIMIT ),
			'stats'     => $stats,
		];
		if ( count( $entries ) > self::OUTLINE_LIMIT ) {
			$out['truncated'] = true;
		}
		if ( self::is_elementor_page( $post ) ) {
			$out['note'] = 'This page is built with Elementor; its content is managed by Elementor, so use the elementor_* tools to change it.';
		}
		return $out;
	}

	private static function outline_walk( array $list, array $parent, $max_depth, array &$out, $first_index = null ) {
		$n = null === $first_index ? 0 : $first_index;
		foreach ( $list as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$path  = array_merge( $parent, [ $n ] );
			$entry = [
				'path'       => implode( '.', $path ),
				'name'       => (string) $block['blockName'],
				'attributes' => self::key_attributes( (array) $block['attrs'] ),
				'text'       => self::preview( $block ),
				'inner'      => count( (array) $block['innerBlocks'] ),
				'edit'       => self::stores_markup( $block ) ? 'text, replace, move, delete' : 'attributes, replace, move, delete',
			];
			$out[] = $entry;
			if ( ( 0 === $max_depth || count( $path ) < $max_depth ) && ! empty( $block['innerBlocks'] ) ) {
				self::outline_walk( $block['innerBlocks'], $path, $max_depth, $out );
			}
			$n++;
		}
	}

	private static function list_types( array $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new \Exception( 'You do not have permission to list block types.' );
		}
		$ns    = isset( $args['namespace'] ) ? trim( sanitize_text_field( (string) $args['namespace'] ), '/' ) : '';
		$attrs = ! empty( $args['include_attributes'] );
		$out   = [];
		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			if ( '' !== $ns && 0 !== strpos( $name, $ns . '/' ) ) {
				continue;
			}
			$row = [
				'name'     => (string) $name,
				'title'    => (string) ( $type->title ?? '' ),
				'category' => (string) ( $type->category ?? '' ),
				'parent'   => is_array( $type->parent ) ? array_values( $type->parent ) : [],
				'server_rendered' => $type->is_dynamic(),
			];
			if ( $attrs ) {
				$schema = [];
				foreach ( (array) $type->get_attributes() as $key => $def ) {
					$schema[ $key ] = array_intersect_key( (array) $def, array_flip( [ 'type', 'enum', 'default', 'source' ] ) );
				}
				$row['attributes'] = $schema;
			}
			$out[] = $row;
		}
		usort( $out, static function ( $a, $b ) {
			return strcmp( $a['name'], $b['name'] );
		} );
		return [
			'count'       => count( $out ),
			'block_types' => $out,
			'note'        => 'Block types registered on the server. Some plugins register further blocks only in the editor; markup using those passes wp_validate_blocks as editor_only when other blocks of the same plugin are registered.',
		];
	}

	private static function validate_tool( array $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new \Exception( 'You do not have permission to check block markup.' );
		}
		$check = self::check_markup( self::markup_arg( $args ) );
		unset( $check['parsed'] );
		$check['note'] = 'This is a structural check: block names, nesting and a stable parse. Only the block editor can run each block\'s own save() to confirm its markup.';
		return $check;
	}

	private static function list_patterns( array $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new \Exception( 'You do not have permission to list patterns.' );
		}
		$query = [
			'post_type'   => 'wp_block',
			'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'numberposts' => isset( $args['limit'] ) ? max( 1, min( self::LIST_LIMIT, (int) $args['limit'] ) ) : 50,
			'orderby'     => 'title',
			'order'       => 'ASC',
		];
		if ( ! empty( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( (string) $args['search'] );
		}
		$out = [];
		foreach ( get_posts( $query ) as $p ) {
			if ( ! current_user_can( 'edit_post', $p->ID ) ) {
				continue;
			}
			$out[] = self::pattern_row( $p );
		}
		return [ 'count' => count( $out ), 'patterns' => $out ];
	}

	private static function get_pattern( array $args ) {
		$post = self::pattern_post( $args );
		$row  = self::pattern_row( $post );
		$row['content'] = (string) $post->post_content;
		$row['stats']   = self::stats( parse_blocks( (string) $post->post_content ) );
		return $row;
	}

	private static function global_styles() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new \Exception( 'You do not have permission to read global styles.' );
		}
		$settings = function_exists( 'wp_get_global_settings' ) ? (array) wp_get_global_settings() : [];
		$pick     = [];
		foreach ( [ [ 'color', 'palette' ], [ 'color', 'gradients' ], [ 'typography', 'fontSizes' ], [ 'typography', 'fontFamilies' ], [ 'spacing', 'spacingSizes' ], [ 'layout' ] ] as $keys ) {
			$value = $settings;
			foreach ( $keys as $k ) {
				$value = is_array( $value ) && array_key_exists( $k, $value ) ? $value[ $k ] : null;
			}
			if ( null !== $value ) {
				$pick[ implode( '.', $keys ) ] = $value;
			}
		}
		return [
			'block_theme' => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			'theme'       => (string) get_stylesheet(),
			'settings'    => $pick,
			'styles'      => function_exists( 'wp_get_global_styles' ) ? (array) wp_get_global_styles() : [],
		];
	}

	private static function list_templates( array $args ) {
		self::require_block_theme();
		$types = isset( $args['type'] ) && in_array( $args['type'], [ 'wp_template', 'wp_template_part' ], true ) ? [ (string) $args['type'] ] : [ 'wp_template', 'wp_template_part' ];
		$out   = [];
		foreach ( $types as $type ) {
			foreach ( (array) get_block_templates( [], $type ) as $tpl ) {
				$out[] = [
					'id'             => (string) $tpl->id,
					'type'           => (string) $tpl->type,
					'slug'           => (string) $tpl->slug,
					'title'          => (string) ( is_array( $tpl->title ) ? ( $tpl->title['rendered'] ?? '' ) : $tpl->title ),
					'area'           => (string) ( $tpl->area ?? '' ),
					'source'         => (string) $tpl->source,
					'customized'     => 'custom' === $tpl->source,
					'has_theme_file' => (bool) $tpl->has_theme_file,
				];
			}
		}
		return [ 'count' => count( $out ), 'templates' => $out ];
	}

	private static function get_template( array $args ) {
		self::require_block_theme();
		$id   = isset( $args['id'] ) ? sanitize_text_field( (string) $args['id'] ) : '';
		$type = isset( $args['type'] ) && 'wp_template_part' === $args['type'] ? 'wp_template_part' : 'wp_template';
		$tpl  = '' !== $id ? get_block_template( $id, $type ) : null;
		if ( ! $tpl ) {
			throw new \Exception( 'Template not found. Use wp_list_block_templates for ids such as "twentytwentyfive//single", and pass type "wp_template_part" for template parts.' );
		}
		return [
			'id'      => (string) $tpl->id,
			'type'    => (string) $tpl->type,
			'slug'    => (string) $tpl->slug,
			'source'  => (string) $tpl->source,
			'content' => (string) $tpl->content,
			'stats'   => self::stats( parse_blocks( (string) $tpl->content ) ),
		];
	}

	/* ------------------------------------------------------------------
	 *  Writes
	 * ------------------------------------------------------------------ */

	private static function insert_tool( array $args ) {
		$post   = self::editable_post( $args );
		$check  = self::require_good_markup( self::markup_arg( $args ) );
		$new    = self::named( $check['parsed'] );
		$tree   = parse_blocks( (string) $post->post_content );
		$at     = isset( $args['at'] ) ? (string) $args['at'] : 'end';
		$target = isset( $args['path'] ) && '' !== (string) $args['path'] ? self::parse_path( $args['path'] ) : [];
		$tree   = self::insert_at( $tree, $at, $target, $new );
		return self::write_tree( $post, $tree, 'wp_insert_blocks', sprintf( 'Inserted %d block(s)', count( $new ) ), [ 'inserted' => count( $new ) ] );
	}

	private static function replace_tool( array $args ) {
		$post  = self::editable_post( $args );
		$path  = self::parse_path( $args['path'] ?? '' );
		$tree  = parse_blocks( (string) $post->post_content );
		$block = self::expect_block( $tree, $path, $args );
		$check = self::require_good_markup( self::markup_arg( $args ) );
		$new   = self::named( $check['parsed'] );
		$tree  = self::replace_at( $tree, $path, $new );
		return self::write_tree( $post, $tree, 'wp_replace_block', sprintf( 'Replaced %s at %s with %d block(s)', $block['blockName'], implode( '.', $path ), count( $new ) ), [ 'replaced' => (string) $block['blockName'] ] );
	}

	private static function move_tool( array $args ) {
		$post   = self::editable_post( $args );
		$path   = self::parse_path( $args['path'] ?? '' );
		$tree   = parse_blocks( (string) $post->post_content );
		$block  = self::expect_block( $tree, $path, $args );
		$at     = isset( $args['at'] ) ? (string) $args['at'] : 'end';
		$target = isset( $args['target_path'] ) && '' !== (string) $args['target_path'] ? self::parse_path( $args['target_path'] ) : [];
		if ( $target && array_slice( $target, 0, count( $path ) ) === $path ) {
			throw new \Exception( 'A block cannot be moved into or next to itself.' );
		}
		if ( $target && in_array( $at, [ 'before', 'after', 'inside_start', 'inside_end' ], true ) && null === self::block_at( $tree, $target ) ) {
			throw new \Exception( 'No block at target_path ' . implode( '.', $target ) . '.' );
		}
		$tree   = self::remove_at( $tree, $path );
		$target = self::shift_after_removal( $target, $path );
		$tree   = self::insert_at( $tree, $at, $target, [ $block ] );
		return self::write_tree( $post, $tree, 'wp_move_block', sprintf( 'Moved %s from %s', $block['blockName'], implode( '.', $path ) ), [ 'moved' => (string) $block['blockName'] ] );
	}

	private static function delete_tool( array $args ) {
		$post  = self::editable_post( $args );
		$path  = self::parse_path( $args['path'] ?? '' );
		$tree  = parse_blocks( (string) $post->post_content );
		$block = self::expect_block( $tree, $path, $args );
		$tree  = self::remove_at( $tree, $path );
		return self::write_tree( $post, $tree, 'wp_delete_block', sprintf( 'Deleted %s at %s', $block['blockName'], implode( '.', $path ) ), [ 'deleted' => (string) $block['blockName'] ] );
	}

	private static function replace_text_tool( array $args ) {
		$post = self::editable_post( $args );
		if ( ! isset( $args['find'] ) || ! is_string( $args['find'] ) || '' === $args['find'] ) {
			throw new \Exception( 'find must be a non-empty string.' );
		}
		if ( ! array_key_exists( 'replace', $args ) || ! is_string( $args['replace'] ) ) {
			throw new \Exception( 'replace must be a string (empty string deletes the matched text).' );
		}
		$find    = $args['find'];
		$replace = $args['replace'];
		if ( $find === $replace ) {
			throw new \Exception( 'find and replace are identical; nothing to do.' );
		}
		$tree  = parse_blocks( (string) $post->post_content );
		$scope = isset( $args['path'] ) && '' !== (string) $args['path'] ? self::parse_path( $args['path'] ) : [];
		if ( $scope && null === self::block_at( $tree, $scope ) ) {
			throw new \Exception( 'No block at path ' . implode( '.', $scope ) . '.' );
		}
		// Markup stores text HTML-encoded (&amp; &lt; &quot;), attributes may store
		// it raw or encoded: replace every form together so the two stay in step.
		$forms  = self::text_forms( $find, $replace );
		$markup = self::markup_forms( $find, $replace, $forms );
		$counts = [ 'markup' => 0, 'attributes' => 0, 'blocks' => [], 'attribute_only' => [] ];
		$tree   = self::map_blocks( $tree, $scope, static function ( array $block, array $path ) use ( $forms, $markup, &$counts ) {
			$in_markup = 0;
			foreach ( $block['innerContent'] as $k => $chunk ) {
				if ( is_string( $chunk ) ) {
					$in_markup                  += self::count_forms( $chunk, $markup );
					$block['innerContent'][ $k ] = strtr( $chunk, $markup );
				}
			}
			$block['innerHTML'] = strtr( (string) $block['innerHTML'], $markup );
			$in_attrs           = 0;
			$block['attrs']     = self::replace_deep( (array) $block['attrs'], $forms, $in_attrs );
			if ( $in_attrs > 0 && 0 === $in_markup && self::stores_markup( $block ) ) {
				$counts['attribute_only'][] = (string) $block['blockName'] . ' at ' . implode( '.', $path );
			}
			if ( $in_markup + $in_attrs > 0 ) {
				$counts['markup']     += $in_markup;
				$counts['attributes'] += $in_attrs;
				$counts['blocks'][]    = [ 'path' => implode( '.', $path ), 'name' => (string) $block['blockName'], 'occurrences' => $in_markup + $in_attrs ];
			}
			return $block;
		} );
		$total = $counts['markup'] + $counts['attributes'];
		if ( array_key_exists( 'expected_count', $args ) && (int) $args['expected_count'] !== $total ) {
			throw new \Exception( sprintf( 'expected_count is %d but %d occurrence(s) found inside blocks; nothing changed.', (int) $args['expected_count'], $total ) );
		}
		$report = [ 'occurrences' => $total, 'in_markup' => $counts['markup'], 'in_attributes' => $counts['attributes'], 'blocks' => array_slice( $counts['blocks'], 0, 100 ) ];
		$attr_only = sprintf( 'The text is in the attributes of %s but not in their markup, so replacing it would put the two out of step and break the block. Replace the block with wp_replace_block instead.', implode( ', ', array_slice( $counts['attribute_only'], 0, 10 ) ) );
		if ( ! empty( $args['dry_run'] ) ) {
			$out = [ 'state' => 'dry_run', 'would_execute' => $total > 0 && ! $counts['attribute_only'], 'id' => (int) $post->ID ] + $report;
			if ( $counts['attribute_only'] ) {
				$out['refused'] = $attr_only;
			}
			return $out;
		}
		if ( $counts['attribute_only'] ) {
			throw new \Exception( $attr_only );
		}
		if ( 0 === $total ) {
			throw new \Exception( 'find text not found inside any block; nothing changed. Use dry_run=true to probe safely.' );
		}
		return self::write_tree( $post, $tree, 'wp_replace_text_in_blocks', sprintf( 'Replaced %d occurrence(s) in %d block(s)', $total, count( $counts['blocks'] ) ), $report );
	}

	private static function attributes_tool( array $args ) {
		$post  = self::editable_post( $args );
		$path  = self::parse_path( $args['path'] ?? '' );
		$tree  = parse_blocks( (string) $post->post_content );
		$block = self::expect_block( $tree, $path, $args );
		if ( empty( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
			throw new \Exception( 'attributes must be an object of attribute names and new values (null removes one).' );
		}
		if ( self::stores_markup( $block ) ) {
			throw new \Exception( sprintf(
				'%s stores its own markup, so changing only its attributes would leave the markup out of step and the editor would mark the block invalid. Replace the whole block with wp_replace_block (new markup with the new attributes), or change its text with wp_replace_text_in_blocks.',
				$block['blockName']
			) );
		}
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( (string) $block['blockName'] );
		if ( ! $type ) {
			throw new \Exception( sprintf( '%s is not registered on this site, so its attributes cannot be checked.', $block['blockName'] ) );
		}
		$schema = (array) $type->get_attributes();
		$attrs  = (array) $block['attrs'];
		foreach ( $args['attributes'] as $key => $value ) {
			$key = (string) $key;
			if ( ! array_key_exists( $key, $schema ) ) {
				throw new \Exception( sprintf( '%1$s has no attribute "%2$s". Its attributes: %3$s.', $block['blockName'], $key, implode( ', ', array_keys( $schema ) ) ) );
			}
			if ( null === $value ) {
				unset( $attrs[ $key ] );
				continue;
			}
			if ( is_object( $value ) ) {
				$value = json_decode( (string) wp_json_encode( $value ), true );
			}
			if ( ! empty( $schema[ $key ]['type'] ) ) {
				$valid = rest_validate_value_from_schema( $value, $schema[ $key ], $key );
				if ( is_wp_error( $valid ) ) {
					throw new \Exception( esc_html( $valid->get_error_message() ) );
				}
			}
			$attrs[ $key ] = $value;
		}
		$tree = self::map_blocks( $tree, $path, static function ( array $b, array $p ) use ( $path, $attrs ) {
			if ( $p === $path ) {
				$b['attrs'] = $attrs;
			}
			return $b;
		} );
		return self::write_tree( $post, $tree, 'wp_update_block_attributes', sprintf( 'Updated attributes of %s at %s', $block['blockName'], implode( '.', $path ) ), [ 'attributes' => $attrs ] );
	}

	private static function create_pattern( array $args ) {
		$type = get_post_type_object( 'wp_block' );
		if ( ! $type || ! current_user_can( $type->cap->create_posts ) ) {
			throw new \Exception( 'You do not have permission to create patterns.' );
		}
		$title = isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : '';
		if ( '' === $title ) {
			throw new \Exception( 'title is required.' );
		}
		$markup = self::markup_arg( $args );
		self::require_good_markup( $markup );
		self::assert_survives_save( $markup, 0 );
		$id = wp_insert_post( [ 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => wp_slash( $markup ) ], true );
		if ( is_wp_error( $id ) ) {
			throw new \Exception( esc_html( $id->get_error_message() ) );
		}
		$synced = ! array_key_exists( 'synced', $args ) || ! empty( $args['synced'] );
		if ( ! $synced ) {
			update_post_meta( $id, 'wp_pattern_sync_status', 'unsynced' );
		}
		return Envelope::success(
			sprintf( 'Created %s pattern %d "%s". Remove it with wp_delete_post.', $synced ? 'synced' : 'unsynced', $id, $title ),
			self::pattern_row( get_post( $id ) )
		);
	}

	private static function update_pattern( array $args ) {
		$post    = self::pattern_post( $args, true );
		$changes = [];
		if ( isset( $args['title'] ) && '' !== (string) $args['title'] ) {
			$changes['post_title'] = sanitize_text_field( (string) $args['title'] );
		}
		if ( isset( $args['markup'] ) ) {
			$markup = self::markup_arg( $args );
			self::require_good_markup( $markup );
			$changes['post_content'] = $markup;
		}
		if ( ! $changes ) {
			throw new \Exception( 'Pass markup, title or both.' );
		}
		$synced = '' === (string) get_post_meta( $post->ID, 'wp_pattern_sync_status', true );
		$what   = $synced ? 'Updated synced pattern (every post that uses it shows the change)' : 'Updated pattern';
		return self::write( $post, $changes, 'wp_update_synced_pattern', $what, [ 'synced' => $synced ] );
	}

	/* ------------------------------------------------------------------
	 *  Write pipeline
	 * ------------------------------------------------------------------ */

	/**
	 * Serialize a changed block tree and save it, refusing when the result would
	 * not read back as the same blocks (markup inside a block that contains block
	 * comments would re-nest the tree).
	 */
	private static function write_tree( \WP_Post $post, array $tree, $tool, $what, array $extra = [] ) {
		$content = serialize_blocks( $tree );
		if ( self::shape( parse_blocks( $content ) ) !== self::shape( $tree ) ) {
			throw new \Exception( 'The result would not read back as the same blocks (some markup contains block comments), so nothing was written. Check the markup with wp_validate_blocks.' );
		}
		return self::write( $post, [ 'post_content' => $content ], $tool, $what, $extra );
	}

	/**
	 * Save the changed fields of one post with the edit-lock and filter checks,
	 * a revision, read-back verification and an undo token.
	 */
	private static function write( \WP_Post $post, array $changes, $tool, $what, array $extra = [] ) {
		$prior = [];
		foreach ( $changes as $field => $value ) {
			$prior[ $field ] = (string) $post->$field;
		}
		if ( $changes === $prior ) {
			throw new \Exception( 'Nothing changed; the post already has that content.' );
		}
		self::assert_not_locked( (int) $post->ID );
		if ( isset( $changes['post_content'] ) ) {
			self::assert_survives_save( (string) $changes['post_content'], (int) $post->ID );
		}
		$data = [ 'ID' => (int) $post->ID ];
		foreach ( $changes as $field => $value ) {
			$data[ $field ] = wp_slash( $value );
		}
		$result = wp_update_post( $data, true );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( esc_html( $result->get_error_message() ) );
		}
		clean_post_cache( (int) $post->ID );
		$stored   = get_post( (int) $post->ID );
		$verified = true;
		foreach ( $changes as $field => $value ) {
			$verified = $verified && (string) $stored->$field === (string) $value;
		}
		$undo = \Royal_MCP\MCP\Undo_Store::store( [
			'op'           => 'wp_update_post',
			'summary'      => sprintf( 'Restore post %d as it was before %s.', $post->ID, $tool ),
			'target'       => [ 'post_id' => (int) $post->ID, 'post_type' => (string) $post->post_type ],
			'pre_op_state' => [ 'prior_values' => $prior, 'is_product' => false, 'original_op' => $tool ],
		] );
		$revisions = wp_get_post_revisions( (int) $post->ID, [ 'numberposts' => 1, 'fields' => 'ids' ] );
		$struct    = array_merge( [
			'id'                    => (int) $post->ID,
			'verified'              => $verified,
			'content_length_before' => strlen( (string) $post->post_content ),
			'content_length_after'  => strlen( (string) $stored->post_content ),
			'revision_id'           => $revisions ? (int) reset( $revisions ) : null,
		], $extra );
		if ( ! $verified ) {
			$struct['modified_by_wp'] = 'The stored content differs from what was written (a content filter changed it on save). Read the post again to see the stored result.';
		}
		return Envelope::success( sprintf( '%s in post %d, undo available', $what, $post->ID ), $struct, $undo );
	}

	/** Refuse while another user has the post open in the editor. */
	private static function assert_not_locked( $post_id ) {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$locker = wp_check_post_lock( $post_id );
		if ( $locker ) {
			$user = get_userdata( (int) $locker );
			throw new \Exception( sprintf( '%s has this post open in the editor right now. Try again when they have closed it, so neither change overwrites the other.', $user ? $user->display_name : 'Another user' ) );
		}
	}

	/**
	 * Content is filtered on save: kses for users without unfiltered_html (which
	 * strips markup some blocks need, such as inline SVG and data attributes) and
	 * any content_save_pre filter a plugin adds. Run the same chain first and
	 * refuse, naming the blocks, when it would change them.
	 */
	private static function assert_survives_save( $content, $post_id ) {
		$filtered = sanitize_post_field( 'post_content', wp_slash( $content ), (int) $post_id, 'db' );
		if ( ! current_user_can( 'unfiltered_html' ) && false === has_filter( 'content_save_pre', 'wp_filter_post_kses' ) ) {
			$filtered = wp_filter_post_kses( $filtered );
		}
		$filtered = wp_unslash( $filtered );
		if ( self::same_markup( $filtered, $content ) ) {
			return;
		}
		$broken = [];
		self::diff_blocks( parse_blocks( $content ), parse_blocks( $filtered ), [], $broken );
		$list = $broken ? implode( ', ', array_slice( $broken, 0, 10 ) ) : 'content outside blocks';
		if ( current_user_can( 'unfiltered_html' ) ) {
			throw new \Exception( sprintf( 'A content filter on this site changes this markup when it is saved, so saving would break: %s. Nothing was written.', $list ) );
		}
		throw new \Exception( sprintf(
			'Your account cannot save the markup some blocks on this post use (WordPress removes it on save for users without the unfiltered_html capability), so saving would break them: %s. Ask an administrator to make this change.',
			$list
		) );
	}

	/** Collect the deepest blocks whose markup differs between two trees. */
	private static function diff_blocks( array $a, array $b, array $parent, array &$out ) {
		$a = self::named( $a );
		$b = self::named( $b );
		foreach ( $a as $i => $block ) {
			$path  = array_merge( $parent, [ $i ] );
			$other = $b[ $i ] ?? null;
			if ( null !== $other && self::same_markup( serialize_block( $block ), serialize_block( $other ) ) ) {
				continue;
			}
			$before = count( $out );
			if ( null !== $other && ! empty( $block['innerBlocks'] ) ) {
				self::diff_blocks( $block['innerBlocks'], (array) $other['innerBlocks'], $path, $out );
			}
			if ( count( $out ) === $before ) {
				$out[] = $block['blockName'] . ' at ' . implode( '.', $path );
			}
		}
	}

	private static function same_markup( $a, $b ) {
		$norm = static function ( $s ) {
			return preg_replace( '/\s+/', ' ', trim( html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		};
		return $norm( $a ) === $norm( $b );
	}

	/* ------------------------------------------------------------------
	 *  Block tree helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Structural check of block markup.
	 *
	 * @return array{ok: bool, blocks: int, block_names: string[], unknown: string[], core_html_blocks: int, freeform_chars: int, broken_delimiters: bool, stable: bool, problems: string[], parsed: array}
	 */
	public static function check_markup( $markup ) {
		$markup = (string) $markup;
		$limit  = self::markup_limit( $markup );
		if ( null !== $limit ) {
			return [ 'ok' => false, 'blocks' => 0, 'block_names' => [], 'unknown' => [], 'editor_only' => [], 'core_html_blocks' => 0, 'freeform_chars' => 0, 'broken_delimiters' => false, 'stable' => false, 'problems' => [ $limit ], 'parsed' => [] ];
		}
		$parsed   = parse_blocks( $markup );
		$registry = \WP_Block_Type_Registry::get_instance();
		$names    = [];
		$unknown  = [];
		$html     = 0;
		$broken   = false;
		$walk     = static function ( array $list ) use ( &$walk, &$names, &$unknown, &$html, &$broken, $registry ) {
			foreach ( $list as $b ) {
				if ( empty( $b['blockName'] ) ) {
					continue;
				}
				$names[] = $b['blockName'];
				if ( ! $registry->is_registered( $b['blockName'] ) ) {
					$unknown[] = $b['blockName'];
				}
				if ( 'core/html' === $b['blockName'] ) {
					$html++;
				}
				foreach ( (array) $b['innerContent'] as $chunk ) {
					if ( is_string( $chunk ) && preg_match( '#<!--\s*/?wp:#', $chunk ) ) {
						$broken = true;
					}
				}
				$walk( (array) $b['innerBlocks'] );
			}
		};
		$walk( $parsed );
		$freeform = 0;
		foreach ( $parsed as $b ) {
			if ( empty( $b['blockName'] ) ) {
				$text = trim( (string) $b['innerHTML'] );
				if ( '' !== $text ) {
					$freeform += strlen( $text );
					if ( preg_match( '#<!--\s*/?wp:#', $text ) ) {
						$broken = true;
					}
				}
			}
		}
		// The parser closes an unclosed block silently (swallowing the parent's
		// closing tags), so count openers and closers per block name as well.
		$found = preg_match_all( '#<!--\s+(?<close>/)?wp:(?<name>[a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)(?:\s+\{.*?\})?\s+(?<void>/)?-->#s', $markup, $delims, PREG_SET_ORDER );
		if ( false === $found ) {
			$broken = true;
		} elseif ( $found ) {
			$depth = [];
			foreach ( $delims as $d ) {
				if ( ! empty( $d['void'] ) ) {
					continue;
				}
				$depth[ $d['name'] ] = ( $depth[ $d['name'] ] ?? 0 ) + ( empty( $d['close'] ) ? 1 : -1 );
			}
			foreach ( $depth as $open ) {
				if ( 0 !== $open ) {
					$broken = true;
				}
			}
		}
		$stable   = self::shape( parse_blocks( serialize_blocks( $parsed ) ) ) === self::shape( $parsed );
		// Many plugins register some blocks only in the editor's JavaScript, so a
		// name missing from the server registry is accepted when other blocks of
		// its namespace are registered; an unknown namespace means a typo or a
		// plugin that is not active.
		$known_ns = [];
		foreach ( array_keys( $registry->get_all_registered() ) as $registered ) {
			$known_ns[ (string) strstr( $registered, '/', true ) ] = true;
		}
		$editor_only = [];
		$missing     = [];
		foreach ( array_unique( $unknown ) as $name ) {
			if ( isset( $known_ns[ (string) strstr( $name, '/', true ) ] ) ) {
				$editor_only[] = $name;
			} else {
				$missing[] = $name;
			}
		}
		$problems = [];
		if ( ! $names ) {
			$problems[] = 'No blocks found. Block markup looks like <!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->.';
		}
		if ( $missing ) {
			$problems[] = 'No plugin on this site provides these block types: ' . implode( ', ', $missing ) . '. Use wp_list_block_types to see what is available.';
		}
		if ( $broken ) {
			$problems[] = 'Block comment delimiters are unbalanced or misplaced, so part of the markup would not parse as blocks.';
		}
		if ( ! $stable ) {
			$problems[] = 'The markup does not survive a parse and re-save unchanged.';
		}
		if ( $freeform > 0 ) {
			$problems[] = sprintf( '%d characters of HTML sit outside any block and would become a Classic block.', $freeform );
		}
		return [
			'ok'                => ! $problems,
			'blocks'            => count( $names ),
			'block_names'       => array_values( array_unique( $names ) ),
			'unknown'           => $missing,
			'editor_only'       => $editor_only,
			'core_html_blocks'  => $html,
			'freeform_chars'    => $freeform,
			'broken_delimiters' => $broken,
			'stable'            => $stable,
			'problems'          => $problems,
			'parsed'            => $parsed,
		];
	}

	/**
	 * Size and nesting limits, checked from the block comments before the markup
	 * is parsed or serialized (serializing deep nesting is quadratic).
	 */
	private static function markup_limit( $markup ) {
		if ( strlen( $markup ) > self::MAX_MARKUP_BYTES ) {
			return sprintf( 'The markup is %1$s; the limit is %2$s per call. Send it in smaller parts.', size_format( strlen( $markup ) ), size_format( self::MAX_MARKUP_BYTES ) );
		}
		$found = preg_match_all( '#<!--\s+(/)?wp:[a-z][a-z0-9_/-]*(?:\s+\{.*?\})?\s+(/)?-->#s', $markup, $delims, PREG_SET_ORDER );
		if ( false === $found ) {
			return 'The markup is too complex to check. Send it in smaller parts.';
		}
		$depth = 0;
		$max   = 0;
		foreach ( $delims as $d ) {
			if ( ! empty( $d[2] ) ) {
				continue;
			}
			$depth += empty( $d[1] ) ? 1 : -1;
			$max    = max( $max, $depth );
		}
		if ( $max > self::MAX_DEPTH ) {
			return sprintf( 'Blocks are nested %1$d deep; the limit is %2$d.', $max, self::MAX_DEPTH );
		}
		return null;
	}

	private static function require_good_markup( $markup ) {
		$check = self::check_markup( $markup );
		if ( ! $check['ok'] ) {
			throw new \Exception( 'The markup was not written: ' . implode( ' ', $check['problems'] ) );
		}
		return $check;
	}

	private static function shape( array $list ) {
		$out = [];
		foreach ( $list as $b ) {
			if ( empty( $b['blockName'] ) ) {
				if ( '' !== trim( (string) $b['innerHTML'] ) ) {
					$out[] = [ null, trim( (string) $b['innerHTML'] ) ];
				}
				continue;
			}
			$own = '';
			foreach ( (array) $b['innerContent'] as $chunk ) {
				if ( is_string( $chunk ) ) {
					$own .= $chunk;
				}
			}
			$out[] = [ $b['blockName'], (array) $b['attrs'], preg_replace( '/\s+/', ' ', trim( $own ) ), self::shape( (array) $b['innerBlocks'] ) ];
		}
		return $out;
	}

	/** Named blocks only, reindexed. */
	private static function named( array $list ) {
		return array_values( array_filter( $list, static function ( $b ) {
			return ! empty( $b['blockName'] );
		} ) );
	}

	/** Raw indexes of the named blocks in a list. */
	private static function named_indexes( array $list ) {
		$out = [];
		foreach ( $list as $i => $b ) {
			if ( ! empty( $b['blockName'] ) ) {
				$out[] = $i;
			}
		}
		return $out;
	}

	private static function parse_path( $path ) {
		$path = is_int( $path ) ? (string) $path : $path;
		if ( ! is_string( $path ) || ! preg_match( '/^\d+(\.\d+)*$/', $path ) ) {
			throw new \Exception( 'path must look like "2" or "2.0.1": block positions from wp_get_block_outline, counting from 0.' );
		}
		return array_map( 'intval', explode( '.', $path ) );
	}

	private static function block_at( array $tree, array $path ) {
		$list  = $tree;
		$block = null;
		foreach ( $path as $n ) {
			$named = self::named_indexes( $list );
			if ( ! isset( $named[ $n ] ) ) {
				return null;
			}
			$block = $list[ $named[ $n ] ];
			$list  = (array) $block['innerBlocks'];
		}
		return $block;
	}

	/** The block at a path, checked against expected_name when given. */
	private static function expect_block( array $tree, array $path, array $args ) {
		$block = self::block_at( $tree, $path );
		if ( null === $block ) {
			throw new \Exception( 'No block at path ' . implode( '.', $path ) . '. Read the current paths with wp_get_block_outline.' );
		}
		if ( ! empty( $args['expected_name'] ) && (string) $args['expected_name'] !== (string) $block['blockName'] ) {
			throw new \Exception( sprintf( 'The block at %1$s is %2$s, not %3$s; nothing changed. The post may have changed since you read it.', implode( '.', $path ), $block['blockName'], $args['expected_name'] ) );
		}
		return $block;
	}

	/**
	 * Apply $fn to the children of the block at $parent (or the top level),
	 * keeping the parent's inner content in step. $fn gets and returns
	 * [ list, innerContent|null ].
	 */
	private static function with_children( array $tree, array $parent, callable $fn ) {
		if ( ! $parent ) {
			return $fn( $tree, null )[0];
		}
		$head  = array_shift( $parent );
		$named = self::named_indexes( $tree );
		if ( ! isset( $named[ $head ] ) ) {
			throw new \Exception( 'No block at that path.' );
		}
		$i     = $named[ $head ];
		$block = $tree[ $i ];
		if ( $parent ) {
			$block['innerBlocks'] = self::with_children( (array) $block['innerBlocks'], $parent, $fn );
		} else {
			list( $block['innerBlocks'], $block['innerContent'] ) = $fn( (array) $block['innerBlocks'], (array) $block['innerContent'] );
		}
		$tree[ $i ] = $block;
		return $tree;
	}

	private static function separator() {
		return [ 'blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "\n\n", 'innerContent' => [ "\n\n" ] ];
	}

	/** Positions of the inner-block placeholders in a block's inner content. */
	private static function placeholders( array $content ) {
		$out = [];
		foreach ( $content as $k => $chunk ) {
			if ( null === $chunk ) {
				$out[] = $k;
			}
		}
		return $out;
	}

	/** Insert $blocks among the children of $parent at child index $index. */
	private static function insert_children( array $tree, array $parent, $index, array $blocks ) {
		return self::with_children( $tree, $parent, static function ( array $list, $content ) use ( $index, $blocks ) {
			if ( null === $content ) {
				$named = self::named_indexes( $list );
				$seq   = [];
				if ( $index < count( $named ) ) {
					foreach ( $blocks as $b ) {
						$seq[] = $b;
						$seq[] = self::separator();
					}
					array_splice( $list, $named[ $index ], 0, $seq );
				} else {
					foreach ( $blocks as $b ) {
						$seq[] = self::separator();
						$seq[] = $b;
					}
					array_splice( $list, count( $list ), 0, $seq );
				}
				return [ $list, null ];
			}
			$holders = self::placeholders( $content );
			if ( ! $holders ) {
				throw new \Exception( 'That block has no inner blocks to place new ones next to. Replace the whole block with wp_replace_block instead.' );
			}
			if ( $index < count( $holders ) ) {
				$seq = [];
				foreach ( $blocks as $b ) {
					$seq[] = null;
					$seq[] = "\n\n";
				}
				array_splice( $content, $holders[ $index ], 0, $seq );
			} else {
				$seq = [];
				foreach ( $blocks as $b ) {
					$seq[] = "\n\n";
					$seq[] = null;
				}
				array_splice( $content, end( $holders ) + 1, 0, $seq );
			}
			array_splice( $list, min( $index, count( $list ) ), 0, $blocks );
			return [ $list, $content ];
		} );
	}

	private static function insert_at( array $tree, $at, array $target, array $blocks ) {
		switch ( $at ) {
			case 'start':
				return self::insert_children( $tree, [], 0, $blocks );
			case 'end':
				return self::insert_children( $tree, [], count( self::named( $tree ) ), $blocks );
			case 'before':
			case 'after':
			case 'inside_start':
			case 'inside_end':
				if ( ! $target ) {
					throw new \Exception( sprintf( 'at "%s" needs path (or target_path when moving): the block to insert next to or inside.', $at ) );
				}
				$block = self::block_at( $tree, $target );
				if ( null === $block ) {
					throw new \Exception( 'No block at path ' . implode( '.', $target ) . '.' );
				}
				if ( 'inside_start' === $at || 'inside_end' === $at ) {
					$index = 'inside_start' === $at ? 0 : count( (array) $block['innerBlocks'] );
					return self::insert_children( $tree, $target, $index, $blocks );
				}
				$index = (int) end( $target ) + ( 'after' === $at ? 1 : 0 );
				return self::insert_children( $tree, array_slice( $target, 0, -1 ), $index, $blocks );
		}
		throw new \Exception( 'at must be one of: start, end, before, after, inside_start, inside_end.' );
	}

	private static function remove_at( array $tree, array $path ) {
		$index = (int) end( $path );
		return self::with_children( $tree, array_slice( $path, 0, -1 ), static function ( array $list, $content ) use ( $index ) {
			if ( null === $content ) {
				$named = self::named_indexes( $list );
				$i     = $named[ $index ];
				$drop  = [ $i ];
				$next  = $list[ $i + 1 ] ?? null;
				$prev  = $list[ $i - 1 ] ?? null;
				if ( $next && empty( $next['blockName'] ) && '' === trim( (string) $next['innerHTML'] ) ) {
					$drop[] = $i + 1;
				} elseif ( $prev && empty( $prev['blockName'] ) && '' === trim( (string) $prev['innerHTML'] ) ) {
					$drop[] = $i - 1;
				}
				foreach ( $drop as $d ) {
					unset( $list[ $d ] );
				}
				return [ array_values( $list ), null ];
			}
			$holders = self::placeholders( $content );
			$k       = $holders[ $index ];
			$drop    = [ $k ];
			if ( isset( $content[ $k + 1 ] ) && is_string( $content[ $k + 1 ] ) && '' === trim( $content[ $k + 1 ] ) && isset( $content[ $k + 2 ] ) ) {
				$drop[] = $k + 1;
			} elseif ( isset( $content[ $k - 1 ] ) && is_string( $content[ $k - 1 ] ) && '' === trim( $content[ $k - 1 ] ) && $k - 1 > 0 ) {
				$drop[] = $k - 1;
			}
			foreach ( $drop as $d ) {
				unset( $content[ $d ] );
			}
			array_splice( $list, $index, 1 );
			return [ $list, array_values( $content ) ];
		} );
	}

	/** Replace the block at $path with $blocks, in place. */
	private static function replace_at( array $tree, array $path, array $blocks ) {
		$index = (int) end( $path );
		return self::with_children( $tree, array_slice( $path, 0, -1 ), static function ( array $list, $content ) use ( $index, $blocks ) {
			if ( null === $content ) {
				$seq = [];
				foreach ( $blocks as $n => $b ) {
					if ( $n > 0 ) {
						$seq[] = self::separator();
					}
					$seq[] = $b;
				}
				array_splice( $list, self::named_indexes( $list )[ $index ], 1, $seq );
				return [ $list, null ];
			}
			$seq = [];
			foreach ( $blocks as $n => $b ) {
				if ( $n > 0 ) {
					$seq[] = "\n\n";
				}
				$seq[] = null;
			}
			array_splice( $content, self::placeholders( $content )[ $index ], 1, $seq );
			array_splice( $list, $index, 1, $blocks );
			return [ $list, $content ];
		} );
	}

	/** Adjust a path for the removal of the block at $removed. */
	private static function shift_after_removal( array $target, array $removed ) {
		if ( ! $target ) {
			return $target;
		}
		$depth = count( $removed ) - 1;
		if ( count( $target ) > $depth && array_slice( $target, 0, $depth ) === array_slice( $removed, 0, $depth ) && $target[ $depth ] > $removed[ $depth ] ) {
			$target[ $depth ]--;
		}
		return $target;
	}

	/** Apply $fn to every named block at or under $scope (the whole tree when empty). */
	private static function map_blocks( array $list, array $scope, callable $fn, array $parent = [] ) {
		$n = 0;
		foreach ( $list as $i => $b ) {
			if ( empty( $b['blockName'] ) ) {
				continue;
			}
			$path   = array_merge( $parent, [ $n ] );
			$common = min( count( $path ), count( $scope ) );
			if ( array_slice( $path, 0, $common ) === array_slice( $scope, 0, $common ) ) {
				if ( count( $path ) >= count( $scope ) ) {
					$b = $fn( $b, $path );
				}
				if ( ! empty( $b['innerBlocks'] ) ) {
					$b['innerBlocks'] = self::map_blocks( $b['innerBlocks'], $scope, $fn, $path );
				}
				$list[ $i ] = $b;
			}
			$n++;
		}
		return $list;
	}

	private static function replace_deep( $value, array $forms, &$count ) {
		if ( is_string( $value ) ) {
			$count += self::count_forms( $value, $forms );
			return strtr( $value, $forms );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::replace_deep( $v, $forms, $count );
			}
		}
		return $value;
	}

	/**
	 * The text and its HTML-encoded forms, each mapped to the replacement encoded
	 * the same way, for strtr().
	 *
	 * @return array<string, string>
	 */
	private static function text_forms( $find, $replace ) {
		$maps  = [
			[ '&' => '&amp;', '<' => '&lt;', '>' => '&gt;' ],
			[ '&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;' ],
			[ '&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#039;' ],
			[ '&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;' ],
		];
		$forms = [ (string) $find => (string) $replace ];
		foreach ( $maps as $map ) {
			$encoded = strtr( (string) $find, $map );
			if ( ! isset( $forms[ $encoded ] ) ) {
				$forms[ $encoded ] = strtr( (string) $replace, $map );
			}
		}
		return $forms;
	}

	/**
	 * Forms for markup. Plain search text is text in markup (in a text node or an
	 * attribute value), so its replacement goes in HTML-encoded; search text that
	 * contains HTML characters is matched as markup and replaced literally.
	 *
	 * @return array<string, string>
	 */
	private static function markup_forms( $find, $replace, array $forms ) {
		if ( strpbrk( (string) $find, '&<>"\'' ) !== false ) {
			return $forms;
		}
		return [ (string) $find => htmlspecialchars( (string) $replace, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ];
	}

	private static function count_forms( $text, array $forms ) {
		$n = 0;
		foreach ( array_keys( $forms ) as $form ) {
			$n += substr_count( (string) $text, (string) $form );
		}
		return $n;
	}

	/** Whether a block stores markup of its own (the editor validates it). */
	private static function stores_markup( array $block ) {
		foreach ( (array) $block['innerContent'] as $chunk ) {
			if ( is_string( $chunk ) && '' !== trim( $chunk ) ) {
				return true;
			}
		}
		return false;
	}

	private static function preview( array $block ) {
		$own = '';
		foreach ( (array) $block['innerContent'] as $chunk ) {
			if ( is_string( $chunk ) ) {
				$own .= ' ' . $chunk;
			}
		}
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $own ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::PREVIEW_CHARS ) : substr( $text, 0, self::PREVIEW_CHARS );
	}

	private static function key_attributes( array $attrs ) {
		$out = [];
		foreach ( $attrs as $k => $v ) {
			if ( count( $out ) >= 8 ) {
				break;
			}
			if ( is_bool( $v ) || is_int( $v ) || is_float( $v ) ) {
				$out[ $k ] = $v;
			} elseif ( is_string( $v ) && '' !== $v ) {
				$out[ $k ] = strlen( $v ) > 80 ? substr( $v, 0, 80 ) . '…' : $v;
			}
		}
		return $out;
	}

	private static function stats( array $tree ) {
		$total = 0;
		$html  = 0;
		$ns    = [];
		$walk  = static function ( array $list ) use ( &$walk, &$total, &$html, &$ns ) {
			foreach ( $list as $b ) {
				if ( empty( $b['blockName'] ) ) {
					continue;
				}
				$total++;
				$prefix        = strstr( (string) $b['blockName'], '/', true ) ?: 'core';
				$ns[ $prefix ] = ( $ns[ $prefix ] ?? 0 ) + 1;
				if ( 'core/html' === $b['blockName'] ) {
					$html++;
				}
				$walk( (array) $b['innerBlocks'] );
			}
		};
		$walk( $tree );
		$freeform = 0;
		foreach ( $tree as $b ) {
			if ( empty( $b['blockName'] ) ) {
				$freeform += strlen( trim( (string) $b['innerHTML'] ) );
			}
		}
		ksort( $ns );
		return [ 'blocks' => $total, 'by_namespace' => $ns, 'core_html_blocks' => $html, 'freeform_chars' => $freeform ];
	}

	/* ------------------------------------------------------------------
	 *  Arguments and access
	 * ------------------------------------------------------------------ */

	private static function markup_arg( array $args ) {
		if ( ! isset( $args['markup'] ) || ! is_string( $args['markup'] ) || '' === trim( $args['markup'] ) ) {
			throw new \Exception( 'markup is required: serialized block markup, for example <!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->.' );
		}
		return (string) $args['markup'];
	}

	private static function post_arg( array $args ) {
		$id   = intval( $args['post_id'] ?? $args['id'] ?? 0 );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || in_array( $post->post_type, [ 'revision', 'attachment', 'nav_menu_item' ], true ) ) {
			throw new \Exception( 'Post not found.' );
		}
		return $post;
	}

	private static function readable_post( array $args ) {
		$post = self::post_arg( $args );
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			throw new \Exception( 'You do not have permission to read this post.' );
		}
		if ( '' !== (string) $post->post_password && ! current_user_can( 'edit_post', $post->ID ) ) {
			throw new \Exception( 'This post is password protected; only users who can edit it can read its blocks.' );
		}
		return $post;
	}

	private static function editable_post( array $args ) {
		$post = self::post_arg( $args );
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			throw new \Exception( 'You do not have permission to edit this post.' );
		}
		if ( self::is_elementor_page( $post ) ) {
			throw new \Exception( 'This page is built with Elementor, which rebuilds its content from its own data, so block changes would be lost. Use the elementor_* tools.' );
		}
		return $post;
	}

	private static function is_elementor_page( \WP_Post $post ) {
		return 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true );
	}

	private static function pattern_post( array $args, $for_edit = false ) {
		$post = self::post_arg( $args );
		if ( 'wp_block' !== $post->post_type ) {
			throw new \Exception( 'That ID is not a pattern. Use wp_list_synced_patterns for pattern IDs.' );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			throw new \Exception( $for_edit ? 'You do not have permission to edit this pattern.' : 'You do not have permission to read this pattern.' );
		}
		return $post;
	}

	private static function pattern_row( \WP_Post $p ) {
		$terms = wp_get_object_terms( $p->ID, 'wp_pattern_category', [ 'fields' => 'names' ] );
		return [
			'id'             => (int) $p->ID,
			'title'          => (string) $p->post_title,
			'status'         => (string) $p->post_status,
			'synced'         => 'unsynced' !== (string) get_post_meta( $p->ID, 'wp_pattern_sync_status', true ),
			'categories'     => is_wp_error( $terms ) ? [] : array_values( $terms ),
			'content_length' => strlen( (string) $p->post_content ),
		];
	}

	private static function require_block_theme() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new \Exception( 'You do not have permission to read templates.' );
		}
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			throw new \Exception( 'The active theme is not a block theme, so it has no block templates.' );
		}
	}
}
