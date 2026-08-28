<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\WriteVerifier;
use Royal_MCP\MCP\Undo_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Yoast SEO MCP Integration
 *
 * Registers MCP tools that expose Yoast SEO's per-post metadata surface —
 * SEO title / description / focus keyword / canonical / primary category /
 * breadcrumbs / robots flags / Open Graph overrides / content + keyword
 * scores — plus JSON-LD schema graph, indexed internal-link data, and (on
 * Yoast Premium) the Redirection module list.
 *
 * Detection uses the WPSEO_VERSION constant, which Yoast defines earliest
 * in its bootstrap and which both Free and Premium set.
 *
 * Complements the plugin-agnostic wp_get_seo_meta / wp_update_seo_meta
 * auto-detect tools: those cover the four core fields across every
 * supported SEO plugin; the yoast_* tools expose the full Yoast surface
 * for callers doing Yoast-specific work (canonical, breadcrumbs,
 * cornerstone, schema).
 */
class YoastSEO {

	/**
	 * Yoast SEO is present when its version constant is defined. The
	 * constant loads earlier than the class autoloader on some hosts, so
	 * relying on defined() rather than class_exists() avoids ordering
	 * edge cases during early plugin bootstrap.
	 */
	public static function is_available() {
		return defined( 'WPSEO_VERSION' );
	}

	public static function get_tools() {
		// Always register so tools appear in MCP tools/list regardless of Yoast
		// activation state. Callers see the schemas immediately on connect;
		// execute_tool cleanly refuses with "Yoast SEO is not active" when the
		// underlying plugin isn't loaded. Prevents the ghost-tools UX where
		// activating a plugin post-MCP-connection requires the client to
		// reconnect before the tools become discoverable.
		return [
			[
				'name'        => 'yoast_get_meta',
				'description' => 'Read the full Yoast SEO meta surface for a post — SEO title, meta description, focus keyword, canonical URL, primary category, breadcrumbs title, robots flags (noindex/nofollow/advanced), Open Graph title/description/image, Twitter card fields, content score, keyword-analysis score. Multi-value fields (title, description, og, twitter, canonical, breadcrumbs_title) return BOTH raw stored templates AND resolved values Yoast actually renders — the resolved layer captures fallbacks (og.image falls back to featured image, canonical falls back to permalink, breadcrumb_title falls back to post_title) so an AI agent reading the meta does not misdiagnose an empty stored value as a missing rendered value. `analysis_pending: true` signals that Yoast has never run its content-score/linkdex analysis on this post (posts created via REST, MCP, WP-CLI, or imports skip the editor scoring path); when true, treat content_score=0 + linkdex=0 as "unknown," not "bad." Yoast-specific counterpart to wp_get_seo_meta.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer', 'description' => 'Post/page/CPT ID.' ],
					],
					'required'   => [ 'post_id' ],
				],
			],
			[
				'name'        => 'yoast_get_schema',
				'description' => 'Read the JSON-LD schema graph Yoast emits for a post — captured via the wpseo_schema_graph filter. Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer', 'description' => 'Post/page/CPT ID.' ],
					],
					'required'   => [ 'post_id' ],
				],
			],
			[
				'name'        => 'yoast_get_internal_links',
				'description' => 'Read internal-linking data Yoast has indexed for a post via WPSEO_Link_Storage. Response carries link_index_status (indexed / index_pending / no_links) so callers can distinguish "post has zero internal links" from "Yoast never scanned this post" (posts created via REST, MCP, WP-CLI, or migration imports never trigger the scanner). content_anchor_count reports the raw anchor count from post_content for cross-check. Full suggestion set requires Yoast Premium; Free returns indexed links plus a Premium note. Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer', 'description' => 'Post/page/CPT ID.' ],
					],
					'required'   => [ 'post_id' ],
				],
			],
			[
				'name'        => 'yoast_get_redirects',
				'description' => 'List redirects configured in Yoast SEO Premium\'s Redirection module. Yoast Premium only; returns `{state: \'unavailable\', reason: \'requires_premium\', tier: \'yoast_premium\', message: ...}` on Free. Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'         => [ 'type' => 'integer', 'description' => 'Max redirects to return. Default 100, max 500.' ],
						'offset'        => [ 'type' => 'integer', 'description' => 'Pagination offset. Default 0.' ],
						'source_filter' => [ 'type' => 'string', 'description' => 'Case-insensitive substring match on source URL.' ],
					],
				],
			],
			[
				'name'        => 'yoast_update_meta',
				'description' => 'Update Yoast SEO title, meta description, and focus keyword for a single post. Single-op additive write — only fields explicitly passed are mutated. Requires edit_post on the target post_id.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id'       => [ 'type' => 'integer', 'description' => 'Post/page/CPT ID.' ],
						'title'         => [ 'type' => 'string', 'description' => 'New SEO title. Omit to leave unchanged.' ],
						'description'   => [ 'type' => 'string', 'description' => 'New meta description. Omit to leave unchanged.' ],
						'focus_keyword' => [ 'type' => 'string', 'description' => 'New focus keyword. Omit to leave unchanged.' ],
					],
					'required'   => [ 'post_id' ],
				],
			],
		];
	}

	/**
	 * Coarse permission cap check fires BEFORE the availability check so a
	 * Subscriber-tier OAuth Bearer receives an identical permission error
	 * whether Yoast is installed or not — prevents error-message probing
	 * for integration presence. Per-post edit_post enforcement lives in
	 * the write handler.
	 */
	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new \Exception( 'You do not have permission to use Yoast SEO tools.' );
		}

		if ( ! self::is_available() ) {
			throw new \Exception( 'Yoast SEO is not active' );
		}

		switch ( $name ) {
			case 'yoast_get_meta':
				return self::handle_get_meta( $args );
			case 'yoast_get_schema':
				return self::handle_get_schema( $args );
			case 'yoast_get_internal_links':
				return self::handle_get_internal_links( $args );
			case 'yoast_get_redirects':
				return self::handle_get_redirects( $args );
			case 'yoast_update_meta':
				return self::handle_update_meta( $args );
			default:
				throw new \Exception( 'Unknown Yoast tool: ' . esc_html( (string) $name ) );
		}
	}

	// ==================== Helpers ====================

	/**
	 * Accepts both `post_id` and `id` (mirrors Server::resolve_post_id_arg).
	 * Missing/zero → thrown exception so handlers can trust the returned int.
	 */
	private static function resolve_post_id( $args ) {
		$raw = $args['post_id'] ?? $args['id'] ?? 0;
		$post_id = absint( intval( $raw ) );
		if ( $post_id <= 0 ) {
			throw new \Exception( 'post_id is required.' );
		}
		return $post_id;
	}

	private static function ensure_post_exists( $post_id ) {
		if ( ! get_post( $post_id ) ) {
			throw new \Exception( 'Post not found: ' . esc_html( (string) $post_id ) );
		}
	}

	private static function ensure_can_read( $post_id ) {
		if ( ! current_user_can( 'read_post', $post_id ) ) {
			throw new \Exception( 'You do not have permission to read Yoast SEO data on this post.' );
		}
	}

	private static function ensure_can_edit( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new \Exception( 'edit_post capability required for this post.' );
		}
	}

	// ==================== Handlers ====================

	/**
	 * Build the Meta shape documented in the tool description. Raw fields
	 * come from WPSEO_Meta::get_value(); rendered title/description come
	 * from the DI-container presentation surface when it is bootstrapped,
	 * falling back to raw values verbatim so the shape is deterministic
	 * regardless of DI state.
	 */
	private static function handle_get_meta( $args ) {
		$post_id = self::resolve_post_id( $args );
		self::ensure_post_exists( $post_id );
		self::ensure_can_read( $post_id );

		// Raw stored values (WPSEO_Meta::get_value takes the key WITHOUT
		// the _yoast_wpseo_ prefix and returns the stored string or a
		// declared default).
		$raw_title       = '';
		$raw_description = '';
		$focus_keyword   = '';
		$canonical       = '';
		$breadcrumbs     = '';
		$og_title        = '';
		$og_description  = '';
		$og_image        = '';
		$tw_title        = '';
		$tw_description  = '';
		$content_score   = 0;
		$linkdex         = 0;
		$is_cornerstone  = false;
		// Yoast's content_score + linkdex are computed by the editor's
		// yoast-seo-analysis JS bundle. Any post created or updated via
		// REST, MCP, WP-CLI, or migration imports never triggers the
		// compute path — the meta rows stay unwritten and Yoast's
		// WPSEO_Meta::get_value falls back to the declared default (0),
		// producing an int result indistinguishable from a real "bad
		// score" measurement. Distinguish via metadata_exists: row absent
		// → analysis was never run → surface analysis_pending=true so
		// downstream dashboards and audit tools can branch on that
		// signal rather than treating 0 as universally bad.
		$content_score_present = metadata_exists( 'post', $post_id, '_yoast_wpseo_content_score' );
		$linkdex_present       = metadata_exists( 'post', $post_id, '_yoast_wpseo_linkdex' );
		$analysis_pending      = ! $content_score_present && ! $linkdex_present;
		$robots_noindex_raw  = '0';
		$robots_nofollow_raw = '0';
		$robots_adv_raw      = '';

		if ( class_exists( '\WPSEO_Meta' ) ) {
			$raw_title           = (string) \WPSEO_Meta::get_value( 'title', $post_id );
			$raw_description     = (string) \WPSEO_Meta::get_value( 'metadesc', $post_id );
			$focus_keyword       = (string) \WPSEO_Meta::get_value( 'focuskw', $post_id );
			$canonical           = (string) \WPSEO_Meta::get_value( 'canonical', $post_id );
			$breadcrumbs         = (string) \WPSEO_Meta::get_value( 'bctitle', $post_id );
			$og_title            = (string) \WPSEO_Meta::get_value( 'opengraph-title', $post_id );
			$og_description      = (string) \WPSEO_Meta::get_value( 'opengraph-description', $post_id );
			$og_image            = (string) \WPSEO_Meta::get_value( 'opengraph-image', $post_id );
			$tw_title            = (string) \WPSEO_Meta::get_value( 'twitter-title', $post_id );
			$tw_description      = (string) \WPSEO_Meta::get_value( 'twitter-description', $post_id );
			$content_score       = (int) \WPSEO_Meta::get_value( 'content_score', $post_id );
			$linkdex             = (int) \WPSEO_Meta::get_value( 'linkdex', $post_id );
			$is_cornerstone      = ( (string) \WPSEO_Meta::get_value( 'is_cornerstone', $post_id ) === '1' );
			$robots_noindex_raw  = (string) \WPSEO_Meta::get_value( 'meta-robots-noindex', $post_id );
			$robots_nofollow_raw = (string) \WPSEO_Meta::get_value( 'meta-robots-nofollow', $post_id );
			$robots_adv_raw      = (string) \WPSEO_Meta::get_value( 'meta-robots-adv', $post_id );
		} else {
			// Raw postmeta fallback preserves the shape when WPSEO_Meta
			// is not loadable (very early bootstrap, partial init).
			$raw_title           = (string) get_post_meta( $post_id, '_yoast_wpseo_title', true );
			$raw_description     = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
			$focus_keyword       = (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
			$canonical           = (string) get_post_meta( $post_id, '_yoast_wpseo_canonical', true );
			$breadcrumbs         = (string) get_post_meta( $post_id, '_yoast_wpseo_bctitle', true );
			$og_title            = (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true );
			$og_description      = (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true );
			$og_image            = (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true );
			$tw_title            = (string) get_post_meta( $post_id, '_yoast_wpseo_twitter-title', true );
			$tw_description      = (string) get_post_meta( $post_id, '_yoast_wpseo_twitter-description', true );
			$content_score       = (int) get_post_meta( $post_id, '_yoast_wpseo_content_score', true );
			$linkdex             = (int) get_post_meta( $post_id, '_yoast_wpseo_linkdex', true );
			$is_cornerstone      = ( (string) get_post_meta( $post_id, '_yoast_wpseo_is_cornerstone', true ) === '1' );
			$robots_noindex_raw  = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
			$robots_nofollow_raw = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );
			$robots_adv_raw      = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', true );
		}

		// Rendered title/description via presentation surface. Fully
		// qualified at call site so a mid-request Yoast deactivation
		// only trips the try/catch rather than a fatal at compile time.
		$rendered_title       = $raw_title;
		$rendered_description = $raw_description;
		try {
			if ( function_exists( 'YoastSEO' ) ) {
				$container = \YoastSEO();
				if ( $container && isset( $container->meta ) ) {
					$meta = $container->meta->for_post( $post_id );
					if ( $meta ) {
						$rendered_title       = (string) ( $meta->title ?? $raw_title );
						$rendered_description = (string) ( $meta->meta_description ?? $raw_description );
					}
				}
			}
		} catch ( \Throwable $e ) {
			error_log( 'Royal MCP YoastSEO presentation fallback (meta): ' . $e->getMessage() );
			$rendered_title       = $raw_title;
			$rendered_description = $raw_description;
		}

		// Resolved og/twitter/canonical values from Yoast's Meta presenter
		// surface — the same read path Yoast itself uses to render the
		// <meta> tags on the front end, so it includes every fallback
		// (permalink for canonical, featured image for og.image on installs
		// that opt in, etc.). Reading this alongside the raw postmeta lets
		// an AI agent distinguish "no template set + Yoast is rendering a
		// fallback" from "no value emitted at all."
		//
		// Falls back defensively to the indexable ORM row when the
		// presenter is unavailable (Yoast not loaded, container missing,
		// deep bootstrap edge cases).
		$meta_surface = self::load_yoast_meta_surface( $post_id );
		$indexable    = $meta_surface ? null : self::load_yoast_indexable( $post_id );

		if ( $meta_surface ) {
			$resolved_og_title       = (string) ( $meta_surface->open_graph_title ?? '' );
			$resolved_og_description = (string) ( $meta_surface->open_graph_description ?? '' );
			// The meta surface returns open_graph_image as an ARRAY of
			// image data (url, width, height, alt) — extract the first
			// image's url. Empty array means no OG image is being rendered.
			$og_image_array          = $meta_surface->open_graph_image ?? [];
			$resolved_og_image       = '';
			$resolved_og_image_id    = 0;
			if ( is_array( $og_image_array ) && ! empty( $og_image_array ) ) {
				$first = reset( $og_image_array );
				if ( is_array( $first ) ) {
					$resolved_og_image    = (string) ( $first['url'] ?? '' );
					$resolved_og_image_id = (int) ( $first['id'] ?? 0 );
				}
			}
			$resolved_tw_title       = (string) ( $meta_surface->twitter_title ?? '' );
			$resolved_tw_description = (string) ( $meta_surface->twitter_description ?? '' );
			$resolved_tw_image       = (string) ( $meta_surface->twitter_image ?? '' );
			$resolved_tw_image_id    = 0; // meta surface doesn't expose twitter image ID
			$resolved_canonical      = (string) ( $meta_surface->canonical ?? '' );
		} else {
			$resolved_og_title       = is_object( $indexable ) ? (string) ( $indexable->open_graph_title ?? '' ) : '';
			$resolved_og_description = is_object( $indexable ) ? (string) ( $indexable->open_graph_description ?? '' ) : '';
			$resolved_og_image       = is_object( $indexable ) ? (string) ( $indexable->open_graph_image ?? '' ) : '';
			$resolved_og_image_id    = is_object( $indexable ) ? (int) ( $indexable->open_graph_image_id ?? 0 ) : 0;
			$resolved_tw_title       = is_object( $indexable ) ? (string) ( $indexable->twitter_title ?? '' ) : '';
			$resolved_tw_description = is_object( $indexable ) ? (string) ( $indexable->twitter_description ?? '' ) : '';
			$resolved_tw_image       = is_object( $indexable ) ? (string) ( $indexable->twitter_image ?? '' ) : '';
			$resolved_tw_image_id    = is_object( $indexable ) ? (int) ( $indexable->twitter_image_id ?? 0 ) : 0;
			$resolved_canonical      = is_object( $indexable ) ? (string) ( $indexable->canonical ?? '' ) : '';
		}

		// breadcrumb_title isn't exposed on the Meta presenter surface —
		// read from the indexable directly, fall back to post_title.
		$indexable_for_breadcrumb = $indexable ?: self::load_yoast_indexable( $post_id );
		$resolved_breadcrumbs = is_object( $indexable_for_breadcrumb )
			? (string) ( $indexable_for_breadcrumb->breadcrumb_title ?? '' )
			: '';

		// Ultimate fallbacks — canonical to permalink, breadcrumb to
		// post_title — for freshly-created posts where Yoast's async
		// indexable rebuild hasn't yet populated the resolved values.
		if ( $resolved_canonical === '' ) {
			$permalink = get_permalink( $post_id );
			$resolved_canonical = is_string( $permalink ) ? $permalink : '';
		}
		if ( $resolved_breadcrumbs === '' ) {
			$post_obj = get_post( $post_id );
			$resolved_breadcrumbs = ( $post_obj && isset( $post_obj->post_title ) ) ? (string) $post_obj->post_title : '';
		}

		// Primary category resolution. The source of truth Yoast writes on
		// save is postmeta `_yoast_wpseo_primary_category` (see
		// WPSEO_Primary_Term::set_primary_term). The wp_yoast_primary_term
		// table + Primary_Term_Repository ORM are downstream materializations
		// maintained by the Indexable Builder for query performance; the
		// postmeta value is the write-time source. Read primary → repository
		// → raw table in that order so we always resolve, even when the
		// indexable has not yet been built for a freshly saved post.
		$primary_category = null;
		$primary_term_id  = 0;
		$meta_primary = get_post_meta( $post_id, '_yoast_wpseo_primary_category', true );
		if ( $meta_primary !== '' && (int) $meta_primary > 0 ) {
			$primary_term_id = (int) $meta_primary;
		}
		if ( $primary_term_id === 0 ) {
			try {
				if ( function_exists( 'YoastSEO' ) ) {
					$container = \YoastSEO();
					if ( $container && isset( $container->classes ) ) {
						$repo = $container->classes->get( \Yoast\WP\SEO\Repositories\Primary_Term_Repository::class );
						if ( $repo ) {
							$row = $repo->find_by_post_id_and_taxonomy( $post_id, 'category', false );
							if ( $row && isset( $row->term_id ) ) {
								$primary_term_id = (int) $row->term_id;
							}
						}
					}
				}
			} catch ( \Throwable $e ) {
				error_log( 'Royal MCP YoastSEO primary-term repo fallback: ' . $e->getMessage() );
				$primary_term_id = 0;
			}
		}
		if ( $primary_term_id === 0 ) {
			global $wpdb;
			$table = $wpdb->prefix . 'yoast_primary_term';
			$fallback_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT term_id FROM {$table} WHERE post_id = %d AND taxonomy = %s LIMIT 1",
					$post_id,
					'category'
				)
			);
			if ( $fallback_id > 0 ) {
				$primary_term_id = $fallback_id;
			}
		}
		if ( $primary_term_id > 0 ) {
			$term = get_term( $primary_term_id, 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				$primary_category = [
					'term_id' => (int) $term->term_id,
					'name'    => (string) $term->name,
					'slug'    => (string) $term->slug,
				];
			}
		}

		// Robots enum decode.
		$noindex_enum = 'default';
		if ( $robots_noindex_raw === '1' ) {
			$noindex_enum = 'noindex';
		} elseif ( $robots_noindex_raw === '2' ) {
			$noindex_enum = 'index';
		}
		$nofollow_enum = ( $robots_nofollow_raw === '1' ) ? 'nofollow' : 'follow';

		$advanced_allowed = [ 'noimageindex', 'noarchive', 'nosnippet' ];
		$advanced_out     = [];
		if ( $robots_adv_raw !== '' ) {
			foreach ( array_map( 'trim', explode( ',', $robots_adv_raw ) ) as $flag ) {
				if ( $flag !== '' && in_array( $flag, $advanced_allowed, true ) ) {
					$advanced_out[] = $flag;
				}
			}
		}

		return [
			'post_id'              => $post_id,
			'raw_title'            => $raw_title,
			'rendered_title'       => $rendered_title,
			'raw_description'      => $raw_description,
			'rendered_description' => $rendered_description,
			'focus_keyword'        => $focus_keyword,
			'canonical'            => [
				'raw'      => $canonical,
				'resolved' => $resolved_canonical,
			],
			'breadcrumbs_title'    => [
				'raw'      => $breadcrumbs,
				'resolved' => $resolved_breadcrumbs,
			],
			'primary_category'     => $primary_category,
			'robots'               => [
				'noindex'  => $noindex_enum,
				'nofollow' => $nofollow_enum,
				'advanced' => $advanced_out,
			],
			'og'                   => [
				'raw' => [
					'title'       => $og_title,
					'description' => $og_description,
					'image'       => $og_image,
				],
				'resolved' => [
					'title'       => $resolved_og_title,
					'description' => $resolved_og_description,
					'image'       => $resolved_og_image,
					'image_id'    => $resolved_og_image_id,
				],
			],
			'twitter'              => [
				'raw' => [
					'title'       => $tw_title,
					'description' => $tw_description,
				],
				'resolved' => [
					'title'       => $resolved_tw_title,
					'description' => $resolved_tw_description,
					'image'       => $resolved_tw_image,
					'image_id'    => $resolved_tw_image_id,
				],
			],
			'content_score'        => $content_score,
			'linkdex'              => $linkdex,
			'analysis_pending'     => $analysis_pending,
			'is_cornerstone'       => $is_cornerstone,
		];
	}

	/**
	 * Load Yoast's Meta presenter surface for a post. Returns the Meta
	 * Values object whose property accessors surface the rendered values
	 * Yoast emits on the front end (open_graph_title, open_graph_image,
	 * canonical, etc.) with every fallback resolved.
	 *
	 * Prefer this over the raw Indexable ORM row when reading og/twitter/
	 * canonical values — the presenter surface is Yoast's official public
	 * read API and handles the presenter-layer transformations (e.g. the
	 * open_graph_image ARRAY of image data vs the indexable's bare column).
	 *
	 * Returns null when Yoast is not loaded, when the container/meta
	 * surface is missing, or when the surface throws — callers must
	 * handle null defensively.
	 */
	private static function load_yoast_meta_surface( $post_id ) {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return null;
		}
		try {
			$container = \YoastSEO();
			if ( ! $container || ! isset( $container->meta ) ) {
				return null;
			}
			$meta = $container->meta->for_post( (int) $post_id );
			return $meta ?: null;
		} catch ( \Throwable $e ) {
			error_log( 'Royal MCP YoastSEO meta surface load fallback: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Load the Yoast Indexable row for a post. Returns the Indexable ORM
	 * object (property accessors: title, description, open_graph_title,
	 * open_graph_description, open_graph_image, open_graph_image_id,
	 * twitter_title, twitter_description, twitter_image, twitter_image_id,
	 * canonical, breadcrumb_title, ...) or null when Yoast is not loaded,
	 * the container classes are missing, the repository doesn't exist, or
	 * the indexable row has not been built yet for this post.
	 *
	 * Callers must treat null defensively — a null result is normal on
	 * freshly-created posts where the async rebuild queue has not yet run,
	 * and should never fatal a read.
	 */
	private static function load_yoast_indexable( $post_id ) {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return null;
		}
		$repository_class = 'Yoast\\WP\\SEO\\Repositories\\Indexable_Repository';
		if ( ! class_exists( $repository_class ) ) {
			return null;
		}
		try {
			$container = \YoastSEO();
			if ( ! $container || ! isset( $container->classes ) ) {
				return null;
			}
			$repo = $container->classes->get( $repository_class );
			if ( ! $repo || ! method_exists( $repo, 'find_by_id_and_type' ) ) {
				return null;
			}
			$indexable = $repo->find_by_id_and_type( (int) $post_id, 'post', false );
			return $indexable ?: null;
		} catch ( \Throwable $e ) {
			error_log( 'Royal MCP YoastSEO indexable load fallback: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Yoast's schema graph via the presentation surface. Primary path
	 * uses YoastSEO()->meta->for_post()->schema; fallback attempts direct
	 * Schema_Generator with a memoized context. Both wrapped so callers
	 * always get a well-formed envelope even on internal failure.
	 */
	private static function handle_get_schema( $args ) {
		$post_id = self::resolve_post_id( $args );
		self::ensure_post_exists( $post_id );
		self::ensure_can_read( $post_id );

		$graph = null;

		// Primary: presentation surface exposes the fully-built graph.
		try {
			if ( function_exists( 'YoastSEO' ) ) {
				$container = \YoastSEO();
				if ( $container && isset( $container->meta ) ) {
					$meta = $container->meta->for_post( $post_id );
					if ( $meta && isset( $meta->schema ) && is_array( $meta->schema ) ) {
						$graph = $meta->schema;
					}
				}
			}
		} catch ( \Throwable $e ) {
			error_log( 'Royal MCP YoastSEO schema presentation fallback: ' . $e->getMessage() );
			$graph = null;
		}

		if ( is_array( $graph ) ) {
			// Presentation surface may return the graph nested under
			// @graph already, or as a bare list. Normalize to the
			// documented envelope shape.
			if ( isset( $graph['@graph'] ) ) {
				return self::finalize_schema_envelope(
					$graph['@context'] ?? 'https://schema.org',
					is_array( $graph['@graph'] ) ? $graph['@graph'] : []
				);
			}
			return self::finalize_schema_envelope( 'https://schema.org', array_values( $graph ) );
		}

		// Fallback: manual context + generator. Any failure collapses
		// to the documented empty-graph error shape.
		try {
			if ( function_exists( 'YoastSEO' ) ) {
				$container = \YoastSEO();
				if ( $container && isset( $container->classes ) ) {
					$memoizer  = $container->classes->get( \Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer::class );
					$generator = $container->classes->get( \Yoast\WP\SEO\Generators\Schema_Generator::class );
					if ( $memoizer && $generator && method_exists( $memoizer, 'for_post_id' ) ) {
						$context = $memoizer->for_post_id( $post_id );
						if ( $context ) {
							$generated = $generator->generate( $context );
							if ( is_array( $generated ) ) {
								if ( isset( $generated['@graph'] ) ) {
									return self::finalize_schema_envelope(
										$generated['@context'] ?? 'https://schema.org',
										is_array( $generated['@graph'] ) ? $generated['@graph'] : []
									);
								}
								return self::finalize_schema_envelope( 'https://schema.org', array_values( $generated ) );
							}
						}
					}
				}
			}
		} catch ( \Throwable $e ) {
			error_log( 'Royal MCP YoastSEO schema manual-generator fallback: ' . $e->getMessage() );
		}

		return [
			'@context' => 'https://schema.org',
			'@graph'   => [],
			'error'    => 'Schema graph generation failed',
		];
	}

	/**
	 * Compose the schema envelope, defensively cleaning any node whose
	 * `author` reference is a dangling stub (empty @id AND empty name).
	 * Google Structured Data Testing Tool treats Article with empty-string
	 * author as invalid and drops the post from rich-results eligibility;
	 * an empty-string @id is worse than an omitted key because it dangles
	 * against a non-existent Person node in the graph.
	 *
	 * Root cause is upstream — WordPress-level author reference on the post
	 * is broken, so Yoast has no Person entity to reference. Correct
	 * defensive behavior is to omit `author` entirely; Google reads a
	 * missing author as "author unknown" rather than "author invalid".
	 *
	 * When cleanup fires, adds a `warnings` field to the response so
	 * callers see that the source data was defective — otherwise silent
	 * repair could hide a bug the caller needs to fix at the post level.
	 */
	private static function finalize_schema_envelope( $context, array $nodes ) {
		$warnings = [];
		$cleaned  = [];

		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && isset( $node['author'] ) && is_array( $node['author'] ) ) {
				$author_id   = isset( $node['author']['@id'] ) ? (string) $node['author']['@id'] : null;
				$author_name = isset( $node['author']['name'] ) ? (string) $node['author']['name'] : null;

				// Trigger when the author node is a dangling stub — @id
				// AND/OR name empty when the property is present.
				$id_present_and_empty   = $author_id !== null && $author_id === '';
				$name_present_and_empty = $author_name !== null && $author_name === '';

				if ( $id_present_and_empty || $name_present_and_empty ) {
					$node_type = isset( $node['@type'] ) ? ( is_array( $node['@type'] ) ? implode( '/', $node['@type'] ) : (string) $node['@type'] ) : 'unknown';
					$node_id   = isset( $node['@id'] ) ? (string) $node['@id'] : '';
					$warnings[] = sprintf(
						'Dropped dangling author reference from %s node%s — source post has an unresolvable author. Fix by assigning a valid author to the post.',
						$node_type,
						$node_id !== '' ? " ({$node_id})" : ''
					);
					unset( $node['author'] );
				}
			}
			$cleaned[] = $node;
		}

		$out = [
			'@context' => $context,
			'@graph'   => $cleaned,
		];
		if ( ! empty( $warnings ) ) {
			$out['warnings'] = $warnings;
		}
		return $out;
	}

	/**
	 * Internal-link index for a post — both outbound (post links to X)
	 * and inbound (X links to post) directions. Primary path is the
	 * SEO_Links_Repository; fallback is a direct query against
	 * {$wpdb->prefix}yoast_seo_links.
	 */
	private static function handle_get_internal_links( $args ) {
		$post_id = self::resolve_post_id( $args );
		self::ensure_post_exists( $post_id );
		self::ensure_can_read( $post_id );

		$from_rows = null;
		$to_rows   = null;

		try {
			if ( function_exists( 'YoastSEO' ) ) {
				$container = \YoastSEO();
				if ( $container && isset( $container->classes ) ) {
					$repo = $container->classes->get( \Yoast\WP\SEO\Repositories\SEO_Links_Repository::class );
					if ( $repo ) {
						$from_rows = $repo->find_all_by_post_id( $post_id );
						$to_rows   = $repo->find_all_by_target_post_id( $post_id );
					}
				}
			}
		} catch ( \Throwable $e ) {
			error_log( 'Royal MCP YoastSEO internal-links repo fallback: ' . $e->getMessage() );
			$from_rows = null;
			$to_rows   = null;
		}

		$from_links = [];
		$to_links   = [];

		if ( is_array( $from_rows ) && is_array( $to_rows ) ) {
			foreach ( $from_rows as $row ) {
				$from_links[] = self::normalize_link_row( $row );
			}
			foreach ( $to_rows as $row ) {
				$to_links[] = self::normalize_link_row( $row );
			}
		} else {
			global $wpdb;
			$table = $wpdb->prefix . 'yoast_seo_links';
			$from_raw = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, url, post_id, target_post_id, type FROM {$table} WHERE post_id = %d",
					$post_id
				),
				ARRAY_A
			);
			$to_raw = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, url, post_id, target_post_id, type FROM {$table} WHERE target_post_id = %d",
					$post_id
				),
				ARRAY_A
			);
			if ( is_array( $from_raw ) ) {
				foreach ( $from_raw as $row ) {
					$from_links[] = self::normalize_link_row( $row );
				}
			}
			if ( is_array( $to_raw ) ) {
				foreach ( $to_raw as $row ) {
					$to_links[] = self::normalize_link_row( $row );
				}
			}
		}

		$count_from = count( $from_links );
		$count_to   = count( $to_links );

		// link_index_status disambiguates "indexed with zero links" from
		// "index not yet built for this post" — otherwise both cases return
		// count_from=0 + count_to=0 and callers cannot tell whether to add
		// links or trigger a rebuild. Yoast's WPSEO_Link_Storage only
		// populates when a post is saved through the standard editor flow;
		// posts created via REST, MCP, WP-CLI, or migration imports never
		// trigger the scanner, leaving the wp_yoast_seo_links table empty
		// even when post_content has real internal anchors.
		//
		//   `indexed`       — count > 0 (at least one link known to Yoast)
		//   `index_pending` — count = 0 AND post_content has any <a href=...>
		//                     (Yoast has not yet scanned; trigger by saving
		//                     the post through the standard editor or via
		//                     wp_publish_and_promote)
		//   `no_links`      — count = 0 AND post_content has zero anchors
		//                     (true empty; no ambiguity)
		$link_index_status    = 'indexed';
		$content_anchor_count = 0;
		if ( $count_from === 0 && $count_to === 0 ) {
			$post_obj = get_post( $post_id );
			$content  = ( $post_obj && isset( $post_obj->post_content ) ) ? (string) $post_obj->post_content : '';
			$matches  = [];
			$found    = preg_match_all( '/<a\s[^>]*href\s*=/i', $content, $matches );
			$content_anchor_count = is_int( $found ) ? $found : 0;
			$link_index_status    = ( $content_anchor_count > 0 ) ? 'index_pending' : 'no_links';
		}

		$note = 'Full internal-link suggestion set (with new-link recommendations) requires Yoast SEO Premium; indexed links are returned in both tiers.';
		if ( $link_index_status === 'index_pending' ) {
			$note .= ' Yoast has not yet indexed internal links for this post — post_content contains anchors but the link table is empty. Trigger by saving the post through the standard editor or via wp_publish_and_promote.';
		}

		return [
			'post_id'              => $post_id,
			'from_links'           => $from_links,
			'to_links'             => $to_links,
			'count_from'           => $count_from,
			'count_to'             => $count_to,
			'link_index_status'    => $link_index_status,
			'content_anchor_count' => $content_anchor_count,
			'note'                 => $note,
		];
	}

	/**
	 * Normalize an SEO_Links row — accepts either an ORM model with
	 * property access or a raw associative array — into a flat shape.
	 */
	private static function normalize_link_row( $row ) {
		$get = function ( $key ) use ( $row ) {
			if ( is_array( $row ) ) {
				return $row[ $key ] ?? null;
			}
			if ( is_object( $row ) ) {
				if ( isset( $row->$key ) ) {
					return $row->$key;
				}
				if ( method_exists( $row, 'get' ) ) {
					return $row->get( $key );
				}
			}
			return null;
		};

		return [
			'id'             => (int) $get( 'id' ),
			'url'            => (string) $get( 'url' ),
			'post_id'        => (int) $get( 'post_id' ),
			'target_post_id' => (int) $get( 'target_post_id' ),
			'type'           => (string) $get( 'type' ),
		];
	}

	/**
	 * Yoast Premium's Redirection module list. Free returns a documented
	 * no_match envelope; Premium branch is scaffolded but flagged as
	 * untested pending a Premium install for verification.
	 */
	private static function handle_get_redirects( $args ) {
		$limit = isset( $args['limit'] ) ? intval( $args['limit'] ) : 100;
		if ( $limit <= 0 ) {
			$limit = 100;
		}
		if ( $limit > 500 ) {
			$limit = 500;
		}
		$offset = isset( $args['offset'] ) ? intval( $args['offset'] ) : 0;
		if ( $offset < 0 ) {
			$offset = 0;
		}
		$source_filter = isset( $args['source_filter'] ) ? sanitize_text_field( (string) $args['source_filter'] ) : '';

		// Tier-gate contract: `no_match` semantically means "query ran,
		// matched nothing." That misled downstream AI agents into
		// interpreting a Free-tier gate as "site has no redirects."
		// `unavailable` + `reason: requires_premium` cannot be misread as
		// a query outcome.
		if ( ! defined( 'WPSEO_PREMIUM_FILE' ) ) {
			return [
				'state'   => 'unavailable',
				'reason'  => 'requires_premium',
				'tier'    => 'yoast_premium',
				'message' => 'This feature requires Yoast SEO Premium; the Redirection module is only available in the Premium tier.',
			];
		}

		// TODO: Premium redirect path is scaffolded — retest against an
		// activated Premium install and pin the returned row shape.
		try {
			if ( ! class_exists( '\WPSEO_Redirect_Manager' ) ) {
				return [
					'state'   => 'error',
					'reason'  => 'handler_unavailable',
					'tier'    => 'yoast_premium',
					'message' => 'WPSEO_Redirect_Manager class not available in this Premium build.',
				];
			}

			$manager = new \WPSEO_Redirect_Manager();
			$all = [];
			if ( method_exists( $manager, 'get_redirects' ) ) {
				$all = (array) $manager->get_redirects();
			} elseif ( method_exists( $manager, 'get_all_redirects' ) ) {
				$all = (array) $manager->get_all_redirects();
			}

			$normalized = [];
			foreach ( $all as $key => $row ) {
				$source = '';
				$target = '';
				$type   = '';
				$format = '';
				if ( is_array( $row ) ) {
					$source = (string) ( $row['origin'] ?? $row['source'] ?? ( is_string( $key ) ? $key : '' ) );
					$target = (string) ( $row['url'] ?? $row['target'] ?? '' );
					$type   = (string) ( $row['type'] ?? '' );
					$format = (string) ( $row['format'] ?? '' );
				} elseif ( is_object( $row ) && method_exists( $row, 'get_origin' ) ) {
					$source = (string) $row->get_origin();
					if ( method_exists( $row, 'get_target' ) ) {
						$target = (string) $row->get_target();
					}
					if ( method_exists( $row, 'get_type' ) ) {
						$type = (string) $row->get_type();
					}
					if ( method_exists( $row, 'get_format' ) ) {
						$format = (string) $row->get_format();
					}
				}
				if ( $source_filter !== '' && stripos( $source, $source_filter ) === false ) {
					continue;
				}
				$normalized[] = [
					'source' => $source,
					'target' => $target,
					'type'   => $type,
					'format' => $format,
				];
			}

			$total = count( $normalized );
			$paged = array_slice( $normalized, $offset, $limit );

			return [
				'redirects' => $paged,
				'total'     => $total,
				'limit'     => $limit,
				'offset'    => $offset,
			];
		} catch ( \Throwable $e ) {
			error_log( 'Royal MCP YoastSEO redirects Premium fallback: ' . $e->getMessage() );
			return [
				'state'   => 'error',
				'reason'  => 'enumeration_failed',
				'tier'    => 'yoast_premium',
				'message' => 'Failed to enumerate Premium redirects.',
			];
		}
	}

	/**
	 * Update Yoast SEO title / description / focus keyword for a single post.
	 *
	 * Single-op additive write — only fields present in $args are mutated
	 * (array_key_exists gate). Empty string is an intentional clear.
	 *
	 * Flow mirrors wp_update_seo_meta narrowed to the Yoast adapter:
	 *   resolve post_id → post-exists → per-post edit_post cap →
	 *   sanitize + before-snapshot → update_post_meta writes →
	 *   cache invalidate → after-snapshot → WriteVerifier diff +
	 *   throw_if_dropped → Undo_Store snapshot → Envelope::success.
	 *
	 * Undo envelope uses op=wp_update_seo_meta with adapter=yoast so the
	 * existing mcp_undo_last_operation dispatcher (which already routes
	 * that adapter-tagged snapshot back through the Yoast field map)
	 * reverses the write out-of-the-box.
	 */
	private static function handle_update_meta( $args ) {
		$post_id = self::resolve_post_id( $args );
		self::ensure_post_exists( $post_id );
		self::ensure_can_edit( $post_id );

		// Adapter-scoped field map. Keys are caller-facing arg names; values
		// are the postmeta keys Yoast reads/writes.
		$field_map = [
			'title'         => '_yoast_wpseo_title',
			'description'   => '_yoast_wpseo_metadesc',
			'focus_keyword' => '_yoast_wpseo_focuskw',
		];

		// Closure: read the current normalized value for a given arg key.
		// Used for both the pre-write snapshot and the post-write re-read
		// so the diff compares apples to apples.
		$read = function ( $arg_key ) use ( $post_id, $field_map ) {
			$meta_key = $field_map[ $arg_key ] ?? '';
			return $meta_key !== '' ? (string) get_post_meta( $post_id, $meta_key, true ) : '';
		};

		// Requested set: only fields the caller actually passed. Empty string
		// is an intentional clear (matches wp_update_seo_meta semantics).
		$requested = [];
		$raw_input = [];
		$before    = [];
		foreach ( array_keys( $field_map ) as $arg_key ) {
			if ( ! array_key_exists( $arg_key, $args ) ) {
				continue;
			}
			$raw_input[ $arg_key ] = (string) $args[ $arg_key ];
			$requested[ $arg_key ] = sanitize_text_field( $raw_input[ $arg_key ] );
			$before[ $arg_key ]    = $read( $arg_key );
		}

		// Noop path — no writeable fields passed. Return a valid envelope
		// with an empty saved_fields list; skip undo since there's nothing
		// to restore.
		if ( empty( $requested ) ) {
			return Envelope::success(
				sprintf( 'No Yoast SEO fields updated on post %d (no writeable fields passed).', $post_id ),
				[
					'plugin'       => 'yoast',
					'post_id'      => $post_id,
					'saved_fields' => [],
				]
			);
		}

		// Execute writes.
		foreach ( $requested as $arg_key => $value ) {
			$meta_key = $field_map[ $arg_key ] ?? '';
			if ( $meta_key !== '' ) {
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		// Cache invalidate before re-read so the after-snapshot reflects
		// what landed on disk, not a stale object-cache row.
		wp_cache_delete( $post_id, 'post_meta' );
		clean_post_cache( $post_id );

		// Bridge meta-only writes to save_post so downstream indexable
		// rebuilders (Yoast's own Indexable Builder + OG image column,
		// sitemap generators, cache invalidators) pick up the change.
		// update_post_meta only fires updated_post_meta, which those
		// consumers don't subscribe to.
		\Royal_MCP\MCP\Support\Post_Write_Hooks::trigger( $post_id );

		// Re-read AFTER-state for the same requested keys.
		$actual = [];
		foreach ( array_keys( $requested ) as $arg_key ) {
			$actual[ $arg_key ] = $read( $arg_key );
		}

		// Diff. throw_if_dropped surfaces silent-drop failures (filter
		// mutation, storage rejection) with a field-list detail so the
		// caller sees which fields didn't stick.
		$diff = WriteVerifier::diff( $requested, $before, $actual, $raw_input );
		WriteVerifier::throw_if_dropped( $diff, 'yoast_update_meta' );

		// Undo envelope — snapshot BEFORE + AFTER so the dispatcher can
		// run drift detection before restoring. Uses op=wp_update_seo_meta
		// so the existing dispatcher's adapter-aware restore branch handles
		// the reversal without needing a new op case.
		$undo_envelope = Undo_Store::store( [
			'op'      => 'wp_update_seo_meta',
			'summary' => sprintf(
				'Restore %d Yoast SEO field(s) on post %d to prior values',
				count( $before ),
				$post_id
			),
			'target'  => [
				'post_id' => $post_id,
				'adapter' => 'yoast',
			],
			'pre_op_state' => [
				'prior_values'   => $before,
				'applied_values' => $actual,
			],
		] );

		// Build structuredContent — WriteVerifier::response_partial surfaces
		// modified_by_wp / input_mangled when relevant. Then override its
		// assoc-map saved_fields with a flat list of field names actually
		// applied (union of applied + silent_modifies) — the spec-shape
		// callers get a simple list they can iterate for display.
		$saved_fields = array_values( array_unique( array_merge(
			array_keys( $diff['applied'] ?? [] ),
			array_keys( $diff['silent_modifies'] ?? [] )
		) ) );
		$struct = array_merge(
			[
				'plugin'  => 'yoast',
				'post_id' => $post_id,
			],
			WriteVerifier::response_partial( $diff ),
			[
				'saved_fields' => $saved_fields,
			]
		);

		$summary = sprintf(
			'Updated %d Yoast SEO field(s) on post %d%s',
			count( $saved_fields ),
			$post_id,
			! empty( $diff['silent_modifies'] ) ? ' (WP modified value)' : ''
		);

		return Envelope::success( $summary, $struct, $undo_envelope );
	}
}

/**
 * Manifest declaration for host discovery (Helm / Royally.io / any
 * consumer of the royal_mcp_manifests filter).
 *
 * Registered unconditionally; the WPSEO_VERSION guard runs inside the
 * callback so plugin load order does not affect the filter registration.
 */
add_filter( 'royal_mcp_manifests', function ( $manifests ) {
	if ( ! defined( 'WPSEO_VERSION' ) ) {
		return $manifests;
	}
	$manifests[] = [
		'royal_mcp_manifest_version' => '1.0',
		'plugin_slug'                => 'yoast-seo',
		'plugin_display_name'        => 'Yoast SEO',
		'plugin_version'             => WPSEO_VERSION,
		'vendor_name'                => 'Yoast BV',
		'mcp_endpoint'               => rest_url( 'royal-mcp/v1/mcp' ),
		'auth_methods'               => [ 'oauth2.1' ],
		'capabilities'               => [ 'read', 'reversible-write' ],
		'manifest_updated_at'        => gmdate( 'c' ),
		'trust_signals'              => [
			'supports_dry_run'                 => false,
			'supports_undo'                    => true,
			'supports_snapshots'               => false,
			'requires_review_for_destructive'  => true,
		],
	];
	return $manifests;
} );
