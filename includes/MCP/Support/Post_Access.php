<?php
/**
 * Object-level checks shared by every tool that reads a post or creates one
 * with a chosen status. Tools reach their handlers through more than one
 * route (this plugin's MCP endpoint, the Abilities API, other MCP servers),
 * so these checks run in the handler itself.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Access {

	/** Post types that set how the whole site looks. */
	const THEME_POST_TYPES = [ 'custom_css', 'customize_changeset', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' ];

	/** Theme post types whose stored text can reach an inline style element. */
	const STYLE_TEXT_TYPES = [ 'custom_css', 'customize_changeset', 'wp_global_styles' ];

	/** Taxonomies that tie a template to its theme and area. */
	const THEME_TAXONOMIES = [ 'wp_theme', 'wp_template_part_area' ];

	/** Tools that put back stored text. */
	const UNDO_TOOLS = [ 'mcp_undo_last_operation', 'royal_mcp_undo_last_operation' ];

	/** One entry per running tool call. */
	private static $guards = [];

	/** Whether the write guard's hooks are attached. */
	private static $guard_hooked = false;

	/** Statuses that make a post visible to the public or to other users with read access. */
	const PUBLISHING_STATUSES = [ 'publish', 'future', 'private' ];

	/**
	 * A whole-number id argument as an integer, or null when the value is not one: a
	 * non-negative integer, a whole float (JSON Schema counts 4.0 as an integer), or digits
	 * with optional surrounding spaces. Other shapes are never cast, because a cast turns
	 * a list, a boolean, a fraction or "1abc" into a real id.
	 *
	 * @param mixed $value Raw argument.
	 * @return int|null
	 */
	public static function id_value( $value ) {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		if ( is_float( $value ) ) {
			return $value >= 0 && $value < 9007199254740992.0 && floor( $value ) === $value ? (int) $value : null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( ! ctype_digit( $value ) ) {
			return null;
		}
		$value = ltrim( $value, '0' );
		return strlen( $value ) < strlen( (string) PHP_INT_MAX ) ? (int) $value : null;
	}

	/**
	 * The post a tool call names through post_id or its alias id, or 0 when it names none.
	 * A blank alias (0 or "") beside the key that names the post is ignored.
	 *
	 * @param array $args Tool arguments.
	 * @return int
	 * @throws Tool_Refusal invalid_args when a key is not a positive whole number, or the two keys name different posts.
	 */
	public static function post_id_arg( array $args ) {
		$ids   = [];
		$blank = null;
		foreach ( [ 'post_id', 'id' ] as $key ) {
			if ( ! isset( $args[ $key ] ) ) {
				continue;
			}
			$id = '' === $args[ $key ] ? 0 : self::id_value( $args[ $key ] );
			if ( null === $id ) {
				throw new Tool_Refusal( 'invalid_args', sprintf( '%s must be a positive whole number.', $key ) );
			}
			if ( 0 === $id ) {
				$blank = $blank ?? $key;
				continue;
			}
			$ids[] = $id;
		}
		if ( ! $ids && null !== $blank ) {
			throw new Tool_Refusal( 'invalid_args', sprintf( '%s must be a positive whole number.', $blank ) );
		}
		if ( count( array_unique( $ids ) ) > 1 ) {
			throw new Tool_Refusal( 'invalid_args', 'id and post_id name different posts; send only one of them.' );
		}
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Why theme writes are refused right now, or null when the site owner's setting allows them.
	 *
	 * @return Tool_Refusal|null
	 */
	public static function theme_writes_refusal() {
		$settings = get_option( 'royal_mcp_settings', [] );
		if ( empty( $settings['allow_theme_writes'] ) ) {
			return new Tool_Refusal( 'theme_writes_off', 'Theme writes are disabled. Enable "Allow AI to modify theme appearance" under Royal MCP > Settings.' );
		}
		return null;
	}

	/**
	 * Why the current user may not change the theme's Custom CSS through a tool, or null when
	 * they may. Every tool that can reach the stylesheet applies these same rules: core's
	 * edit_theme_options, unrestricted HTML, and the site owner's theme-writes setting.
	 *
	 * @return Tool_Refusal|null
	 */
	public static function custom_css_refusal() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new Tool_Refusal( 'insufficient_caps', 'edit_theme_options capability required to update custom CSS.' );
		}
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return new Tool_Refusal( 'unfiltered_html_required', 'unfiltered_html capability required to update custom CSS.' );
		}
		return self::theme_writes_refusal();
	}

	/**
	 * Why a post of this type may not be written now, or null. Types that set how the whole
	 * site looks answer to the theme-writes setting; every other type returns null.
	 *
	 * @param string $post_type Post type.
	 * @return Tool_Refusal|null
	 */
	public static function theme_post_refusal( $post_type ) {
		if ( 'custom_css' === $post_type ) {
			return self::custom_css_refusal();
		}
		return in_array( $post_type, self::THEME_POST_TYPES, true ) ? self::theme_writes_refusal() : null;
	}

	/**
	 * Whether text would end the style element it is printed in, in any case, with whitespace
	 * or invisible characters inside the tag, or written with HTML, URL, CSS or JSON escapes.
	 *
	 * @param mixed $text Stylesheet or style JSON.
	 * @return bool
	 */
	public static function closes_style( $text ) {
		$text = is_scalar( $text ) ? (string) $text : '';
		$json = json_decode( $text, true );
		$css  = is_array( $json ) && is_string( $json['styles']['css'] ?? null ) ? $json['styles']['css'] : $text;
		if ( 1 === preg_match( '#<\s*(?:/\s*(?:s(?:t(?:y(?:le?)?)?)?)?)?\s*$#i', SafeText::plain_spelling( $css ) ) ) {
			return true; // Core joins stylesheets with nothing between, so the next one could finish the tag.
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$plain = SafeText::plain_spelling( $text );
			$plain = (string) preg_replace( [ '/&(?:lt|#0*60|#x0*3c)(?![a-z0-9;])/i', '/&(?:sol|#0*47|#x0*2f)(?![a-z0-9;])/i' ], [ '<', '/' ], $plain );
			$ascii = static function ( $m ) {
				$code = hexdec( $m[1] );
				return $code > 0 && $code < 128 ? chr( (int) $code ) : $m[0];
			};
			$plain = (string) preg_replace_callback( '/\\\\u([0-9a-f]{4})/i', $ascii, $plain );
			$plain = (string) preg_replace_callback( '/\\\\(?:x|u\{)([0-9a-f]{1,6})\}?/i', $ascii, $plain );
			$plain = (string) preg_replace_callback( '/\\\\([0-9a-f]{1,6})\s?/i', $ascii, $plain );
			$plain = (string) preg_replace( '#\\\\+/#', '/', $plain );
			if ( 1 === preg_match( '#<\s*/\s*style#i', $plain ) ) {
				return true;
			}
			if ( $plain === $text ) {
				break;
			}
			$text = $plain;
		}
		return false;
	}

	/**
	 * Why this stylesheet text may not be saved, or null. Text that ended the style element
	 * would put what follows it on every page as markup.
	 *
	 * @param mixed $text Stylesheet or style JSON.
	 * @return Tool_Refusal|null
	 */
	public static function style_close_refusal( $text ) {
		if ( ! self::closes_style( $text ) ) {
			return null;
		}
		return new Tool_Refusal( 'css_closes_style', 'CSS may not contain a closing style tag (</style), however it is spelled. Nothing was written. Remove it and send the CSS again.' );
	}

	/**
	 * Throw when a write to a post of this type is not allowed now.
	 *
	 * @param string     $post_type Post type.
	 * @param mixed|null $content   Text about to be stored, when the call carries any.
	 * @throws Tool_Refusal When refused.
	 */
	public static function assert_theme_write( $post_type, $content = null ) {
		$refusal = self::theme_post_refusal( $post_type );
		if ( null === $refusal && null !== $content && in_array( $post_type, self::STYLE_TEXT_TYPES, true ) ) {
			$refusal = self::style_close_refusal( $content );
		}
		if ( null !== $refusal ) {
			throw $refusal;
		}
	}

	/**
	 * A theme's Custom CSS and its post, read without core saving its lookup in the theme's mods.
	 *
	 * @param string $theme Theme slug.
	 * @return array [ css, WP_Post|null ]
	 */
	public static function read_custom_css( $theme ) {
		$hook = 'pre_update_option_theme_mods_' . get_option( 'stylesheet' );
		$hold = static function ( $value, $old ) {
			return $old;
		};
		add_filter( $hook, $hold, PHP_INT_MAX, 2 );
		try {
			return [ (string) wp_get_custom_css( $theme ), wp_get_custom_css_post( $theme ) ];
		} finally {
			remove_filter( $hook, $hold, PHP_INT_MAX );
		}
	}

	/**
	 * Start the write guard for one tool call: whatever path a call takes, a row of a theme post
	 * type is written only under the rules above. Calls that only read run unguarded. An undo
	 * puts back stored text, so it answers to the theme-writes setting but not to the check on
	 * what the text says.
	 *
	 * @param string $tool    Tool name.
	 * @param bool   $enforce False for a call that only reads.
	 */
	public static function open_write_guard( $tool, $enforce ) {
		if ( ! self::$guard_hooked ) {
			self::$guard_hooked = true;
			add_filter( 'wp_insert_post_data', [ __CLASS__, 'guard_post_row' ], PHP_INT_MAX, 2 );
			add_filter( 'pre_delete_post', [ __CLASS__, 'guard_post_removal' ], 1, 2 );
			add_filter( 'pre_trash_post', [ __CLASS__, 'guard_post_removal' ], 1, 2 );
			add_filter( 'pre_untrash_post', [ __CLASS__, 'guard_post_removal' ], 1, 2 );
			foreach ( [ 'add', 'update', 'delete' ] as $verb ) {
				add_filter( $verb . '_post_metadata', [ __CLASS__, 'guard_post_meta' ], 1, 2 );
			}
			add_action( 'add_term_relationship', [ __CLASS__, 'guard_term_link' ], 1 );
			add_action( 'delete_term_relationships', [ __CLASS__, 'guard_term_link' ], 1 );
			add_action( 'edit_terms', [ __CLASS__, 'guard_term_write' ], 1, 2 );
			add_action( 'pre_delete_term', [ __CLASS__, 'guard_term_write' ], 1, 2 );
			add_filter( 'pre_insert_term', [ __CLASS__, 'guard_new_term' ], 1, 2 );
		}
		self::$guards[] = [ 'enforce' => (bool) $enforce, 'undo' => in_array( $tool, self::UNDO_TOOLS, true ) ];
	}

	/** End the write guard of the tool call that just finished. */
	public static function close_write_guard() {
		array_pop( self::$guards );
	}

	/** The guard of the running call, when it enforces anything. */
	private static function active_guard() {
		$guard = end( self::$guards );
		return is_array( $guard ) && $guard['enforce'] ? $guard : null;
	}

	/**
	 * Refuses a post row that is not allowed, before it is saved.
	 *
	 * @param array $data    Row about to be saved, slashed.
	 * @param array $postarr Arguments of the save.
	 * @return array
	 * @throws Tool_Refusal When refused.
	 */
	public static function guard_post_row( $data, $postarr ) {
		$guard = self::active_guard();
		if ( null === $guard ) {
			return $data;
		}
		$type = (string) ( $data['post_type'] ?? '' );
		$id   = is_array( $postarr ) ? (int) ( $postarr['ID'] ?? 0 ) : 0;
		$was  = $id > 0 ? (string) get_post_type( $id ) : '';
		if ( '' !== $was && $was !== $type ) {
			self::assert_theme_write( $was );
		}
		$text = wp_unslash( (string) ( $data['post_content'] ?? '' ) );
		$row  = $id > 0 ? get_post( $id ) : null;
		$same = $row instanceof \WP_Post && (string) $row->post_content === $text;
		self::assert_theme_write( $type, $guard['undo'] || $same ? null : $text );
		return $data;
	}

	/**
	 * Leaves a theme post in place when a call would delete, trash or restore it. Tools aimed at
	 * one post refuse before this point; a site-wide cleanup carries on past the post.
	 *
	 * @param mixed    $check Earlier answer of the filter.
	 * @param \WP_Post $post  Post.
	 * @return mixed
	 */
	public static function guard_post_removal( $check, $post ) {
		if ( null !== self::active_guard() && $post instanceof \WP_Post && null !== self::theme_post_refusal( (string) $post->post_type ) ) {
			return false;
		}
		return $check;
	}

	/**
	 * Refuses a meta write on a theme post that is not allowed.
	 *
	 * @param mixed $check   Earlier answer of the filter.
	 * @param int   $post_id Post.
	 * @return mixed
	 * @throws Tool_Refusal When refused.
	 */
	public static function guard_post_meta( $check, $post_id ) {
		if ( null !== self::active_guard() && (int) $post_id > 0 ) {
			self::assert_theme_write( (string) get_post_type( (int) $post_id ) );
		}
		return $check;
	}

	/**
	 * Refuses adding or removing a term on a theme post that is not allowed.
	 *
	 * @param int $object_id Post.
	 * @throws Tool_Refusal When refused.
	 */
	public static function guard_term_link( $object_id ) {
		if ( null !== self::active_guard() && (int) $object_id > 0 ) {
			self::assert_theme_write( (string) get_post_type( (int) $object_id ) );
		}
	}

	/**
	 * Refuses changing a term that says which theme or area a template belongs to.
	 *
	 * @param int    $term_id  Term.
	 * @param string $taxonomy Taxonomy.
	 * @throws Tool_Refusal When refused.
	 */
	public static function guard_term_write( $term_id, $taxonomy ) {
		if ( null !== self::active_guard() && in_array( $taxonomy, self::THEME_TAXONOMIES, true ) ) {
			$refusal = self::theme_writes_refusal();
			if ( null !== $refusal ) {
				throw $refusal;
			}
		}
	}

	/**
	 * The same rule for a new term.
	 *
	 * @param mixed  $term     Term name.
	 * @param string $taxonomy Taxonomy.
	 * @return mixed
	 * @throws Tool_Refusal When refused.
	 */
	public static function guard_new_term( $term, $taxonomy ) {
		self::guard_term_write( 0, $taxonomy );
		return $term;
	}

	/**
	 * Whether the current user may read this post through a tool.
	 *
	 * Mirrors what the REST API exposes: a post type that is not publicly
	 * viewable is readable only by users who can edit posts of that type;
	 * revisions and autosaves only by users who can edit the parent; then
	 * the per-post read_post check, which covers private, draft and other
	 * users' posts. Password protection is separate: see content_withheld().
	 *
	 * @param \WP_Post|int|null $post Post or ID.
	 * @return bool
	 */
	public static function can_read( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( 'revision' === $post->post_type ) {
			$parent = (int) $post->post_parent;
			return $parent > 0 && current_user_can( 'edit_post', $parent );
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type ) {
			return false;
		}
		if ( ! self::type_allowed( $type ) ) {
			return false;
		}
		return current_user_can( 'read_post', $post->ID );
	}

	/**
	 * Whether the current user may read this post's content: can_read() plus
	 * the password rule. For tools that return a page's body, builder data,
	 * field values or anything else the post's password protects.
	 *
	 * @param \WP_Post|int|null $post Post or ID.
	 * @return bool
	 */
	public static function can_read_content( $post ) {
		$post = get_post( $post );
		return $post instanceof \WP_Post && self::can_read( $post ) && ! self::content_withheld( $post );
	}

	/**
	 * Whether a post type may be queried by the current user at all: viewable
	 * types for anyone, other types only for users who can edit them.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public static function can_query_type( $post_type ) {
		$type = get_post_type_object( (string) $post_type );
		if ( ! $type ) {
			return false;
		}
		return self::type_allowed( $type );
	}

	/**
	 * Post type rule shared by can_read() and can_query_type(): viewable types
	 * for anyone; other types for users who can edit them; internal types with
	 * neither an admin screen nor a REST endpoint (embed cache, privacy
	 * requests) for administrators only.
	 *
	 * @param \WP_Post_Type $type Post type object.
	 * @return bool
	 */
	private static function type_allowed( \WP_Post_Type $type ) {
		// Code snippets keep keys in their code; they are read through the snippet
		// tools, which redact credentials in code.
		if ( self::is_code_type( $type->name ) ) {
			return false;
		}
		if ( is_post_type_viewable( $type ) ) {
			return true;
		}
		if ( ! current_user_can( $type->cap->edit_posts ) ) {
			return false;
		}
		return ! empty( $type->show_ui ) || ! empty( $type->show_in_rest ) || current_user_can( 'manage_options' );
	}

	/**
	 * Post types that store code (snippet plugins) or pending settings
	 * (Customizer changesets). The generic post tools neither return nor change
	 * them; the snippet tools read them with credentials redacted.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public static function is_code_type( $post_type ) {
		/**
		 * Filter the post types that hold code and stay out of the generic post tools.
		 *
		 * @param string[] $types Post type names.
		 */
		$types = apply_filters( 'royal_mcp_code_post_types', [ 'wpcode', 'wbcr-snippets', 'custom-css-js', 'elementor_snippet', 'customize_changeset' ] );
		return in_array( (string) $post_type, (array) $types, true );
	}

	/**
	 * Post types a search over "any" type may return for the current user.
	 *
	 * @return string[]
	 */
	public static function queryable_types() {
		$types = [];
		foreach ( get_post_types( [], 'names' ) as $name ) {
			if ( 'revision' === $name ) {
				continue;
			}
			if ( self::can_query_type( $name ) ) {
				$types[] = $name;
			}
		}
		return $types;
	}

	/**
	 * Whether a password-protected post's body must be withheld from the
	 * current user (only users who can edit the post get it).
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function content_withheld( \WP_Post $post ) {
		return '' !== (string) $post->post_password && ! current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Why the current user may not create a post of this type with this
	 * status, or '' when they may. Same rules as the REST API: the type's
	 * create_posts capability, and its publish_posts capability for publish,
	 * future and private.
	 *
	 * @param string $post_type Post type name.
	 * @param string $status    Requested status.
	 * @return string Refusal message, or ''.
	 */
	public static function create_refusal( $post_type, $status ) {
		$status = sanitize_key( (string) $status );
		$type   = get_post_type_object( (string) $post_type );
		if ( ! $type ) {
			return 'Unknown post type: ' . sanitize_key( (string) $post_type ) . '.';
		}
		if ( ! current_user_can( $type->cap->create_posts ) ) {
			return 'You do not have permission to create ' . $type->name . ' posts.';
		}
		if ( in_array( (string) $status, self::PUBLISHING_STATUSES, true ) && ! current_user_can( $type->cap->publish_posts ) ) {
			return 'You do not have permission to create ' . $type->name . ' posts with status ' . $status . '.';
		}
		return '';
	}

	/**
	 * Why the current user may not move an existing post to this status, or
	 * '' when they may. Publishing statuses need the post type's own
	 * publish_posts capability (publish_pages for pages, and so on).
	 *
	 * @param \WP_Post $post   Post being changed.
	 * @param string   $status Requested status.
	 * @return string Refusal message, or ''.
	 */
	public static function status_change_refusal( \WP_Post $post, $status ) {
		// WordPress lowercases a status when it saves it, so compare the same way.
		$status = sanitize_key( (string) $status );
		if ( $status === (string) $post->post_status || ! in_array( $status, self::PUBLISHING_STATUSES, true ) ) {
			return '';
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! current_user_can( $type->cap->publish_posts ) ) {
			return 'You do not have permission to change this ' . $post->post_type . ' to status ' . $status . '.';
		}
		return '';
	}

	/**
	 * Why the current user may not make $author_id the author of a post of
	 * this type, or '' when they may. Crediting another user needs the type's
	 * edit_others_posts capability.
	 *
	 * @param string $post_type Post type name.
	 * @param int    $author_id Requested author.
	 * @param int    $current   The post's current author (0 for a new post).
	 * @return string Refusal message, or ''.
	 */
	public static function author_refusal( $post_type, $author_id, $current = 0 ) {
		$author_id = (int) $author_id;
		if ( $author_id <= 0 || $author_id === (int) $current || $author_id === get_current_user_id() ) {
			return '';
		}
		$type = get_post_type_object( (string) $post_type );
		if ( ! $type || ! current_user_can( $type->cap->edit_others_posts ) ) {
			return 'You do not have permission to set another user as the author.';
		}
		return '';
	}
}
