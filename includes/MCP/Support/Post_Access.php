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

	/** Statuses that make a post visible to the public or to other users with read access. */
	const PUBLISHING_STATUSES = [ 'publish', 'future', 'private' ];

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
