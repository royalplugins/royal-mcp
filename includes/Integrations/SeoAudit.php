<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Post_Access;
use Royal_MCP\MCP\Support\Seo_Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp_audit_seo_bulk: an on-page SEO audit across a post type. Reads titles
 * and meta descriptions from the active SEO plugin (or the WordPress fields
 * when there is none), then checks lengths, noindex, headings, word count,
 * images and alt text for each post.
 *
 * Read-only in the strict sense: the content is read as it is stored. It is
 * never passed through the_content, so no shortcode, block callback, embed
 * lookup or other plugin's content filter runs because of an audit. Text and
 * images that only exist once a shortcode or a dynamic block has run are
 * therefore not counted, and each page says when that applies.
 */
class SeoAudit {

	const TOOL = 'wp_audit_seo_bulk';

	const DEFAULT_PER_PAGE = 100;
	const MAX_PER_PAGE     = 500;
	const TITLE_MIN        = 30;
	const TITLE_MAX        = 60;
	const DESC_MIN         = 120;
	const DESC_MAX         = 160;

	/** Longest title or description text sent back for one page; its full length is still reported. */
	const VALUE_MAX = 300;

	/** Seconds one call may spend auditing before it stops and says where it stopped. */
	const TIME_BUDGET = 20;

	/** Most bytes of findings one call sends back. */
	const MAX_REPLY_BYTES = 1048576;

	/** Whether a tool name belongs to this class. */
	public static function handles( $name ) {
		return self::TOOL === $name;
	}

	public static function get_tools() {
		return [
			'wp_audit_seo_bulk' => [
				'name'        => 'wp_audit_seo_bulk',
				'description' => 'On-page SEO audit across a post type: title and description length, noindex, H1 count, word count, images and alt text, featured image. Titles and descriptions come from the active SEO plugin (SEObolt, Yoast, Rank Math, SEOPress or AIOSEO), else from the post title and excerpt; plugin scores are not reported. Returns findings per page and, unless rollup is false, a summary. Published content only unless include_drafts is true. Shortcodes, blocks and embeds are not run: pages whose content is built when served are marked content_made_when_served, and pages whose theme prints the title outside the content are marked h1_unverifiable_theme_title. Title and description text is cut to 300 characters; the full length is given. A call stops after about 20 seconds or 1 MB of findings (stopped_early, with remaining_post_ids to send back as post_ids, and next_page). post_ids takes up to 500 IDs; results come in ID order. Read-only. Requires manage_options.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_type' => [
							'type'        => 'string',
							'description' => 'Post type to audit (must be a registered CPT slug). Default: "post".',
						],
						'post_ids'  => [
							'type'        => 'array',
							'items'       => [ 'type' => 'integer' ],
							'description' => 'Optional. When provided, audit ONLY these post IDs (must be of post_type). Skips the pagination sweep of every post — useful for targeted re-audit after a fix, or for auditing a specific customer complaint.',
						],
						'paginate'  => [
							'type'        => 'object',
							'description' => 'Pagination controls. Defaults: page 1, per_page 100 (max 500).',
							'properties'  => [
								'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
								'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
							],
						],
						'rollup'    => [
							'type'        => 'boolean',
							'description' => 'Include the aggregated rollup summary alongside per-page results. Default: true.',
						],
						'include_drafts' => [
							'type'        => 'boolean',
							'description' => 'Include draft/pending/private/future posts alongside published ones. Default: false (publish-only). Agents auditing an SEO surface typically care about what customers actually see, so drafts are opt-in.',
						],
						'count_decorative_alts' => [
							'type'        => 'boolean',
							'description' => 'How to treat alt="" (HTML5 decorative-image marker). Default: true — counts decorative images in images_missing_alt + flags "N_images_missing_alt" issue (strict SEO parity, matches agency-audit convention). Set false to only count images with NO alt attribute at all — separates decoration from missing metadata. images_no_alt + images_decorative_alt are always returned separately regardless of this setting.',
						],
					],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		if ( ! self::handles( $name ) ) {
			throw new \Exception( 'Unknown SEO tool: ' . esc_html( is_string( $name ) ? $name : '' ) );
		}
		return self::handle_audit_seo_bulk( is_array( $args ) ? $args : [] );
	}

	/**
	 * A yes / no argument: true, 1, "1" and "true" are yes; false, 0, "0", "false", "" and
	 * null are no; a missing argument gives the default. Anything else is refused, so text
	 * such as "no" can never be read as yes.
	 *
	 * @return bool|null null when the value is neither.
	 */
	private static function flag( array $args, $key, $default ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			return (bool) $default;
		}
		$value = is_string( $args[ $key ] ) ? strtolower( trim( $args[ $key ] ) ) : $args[ $key ];
		if ( in_array( $value, [ true, 1, '1', 'true' ], true ) ) {
			return true;
		}
		if ( in_array( $value, [ false, 0, '0', 'false', '' ], true ) ) {
			return false;
		}
		return null;
	}

	/** A site-wide audit reads every post of a type, drafts included on request: administrators only. */
	private static function require_admin() {
		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use this SEO tool.' );
		}
	}

	public static function handle_audit_seo_bulk( array $args ) {
		self::require_admin();

		$post_type = isset( $args['post_type'] ) ? ( is_string( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : '' ) : 'post';
		if ( $post_type === '' ) {
			return Envelope::error( 'invalid_args', 'post_type is required.' );
		}
		if ( ! post_type_exists( $post_type ) ) {
			return Envelope::error( 'unknown_post_type', sprintf( 'Post type "%s" is not registered.', $post_type ) );
		}
		// An SEO audit is for pages visitors can see; code and internal types stay out.
		if ( Post_Access::is_code_type( $post_type ) || ! is_post_type_viewable( $post_type ) ) {
			return Envelope::error( 'post_type_not_auditable', sprintf( 'Post type "%s" is not a public content type, so it has no SEO to audit.', $post_type ) );
		}

		$paginate = isset( $args['paginate'] ) && is_array( $args['paginate'] ) ? $args['paginate'] : [];
		$page     = max( 1, min( 100000, is_numeric( $paginate['page'] ?? null ) ? (int) $paginate['page'] : 1 ) );
		$per_page = is_numeric( $paginate['per_page'] ?? null ) ? (int) $paginate['per_page'] : self::DEFAULT_PER_PAGE;
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$rollup_on             = self::flag( $args, 'rollup', true );
		$count_decorative_alts = self::flag( $args, 'count_decorative_alts', true );
		$include_drafts        = self::flag( $args, 'include_drafts', false );
		foreach ( [ 'rollup' => $rollup_on, 'count_decorative_alts' => $count_decorative_alts, 'include_drafts' => $include_drafts ] as $flag_name => $flag_value ) {
			if ( null === $flag_value ) {
				return Envelope::error( 'invalid_args', $flag_name . ' must be true or false.' );
			}
		}
		$detected = self::detect_seo_plugin();

		// Optional post_ids filter — audit only the specified posts rather
		// than the full pagination sweep of every published post of the
		// type. Useful for targeted re-audit after a fix.
		$post_ids_filter = [];
		if ( array_key_exists( 'post_ids', $args ) && null !== $args['post_ids'] ) {
			// Anything but a list of ids is refused: reading it as "no filter" would audit every post.
			if ( ! is_array( $args['post_ids'] ) || count( $args['post_ids'] ) > self::MAX_PER_PAGE ) {
				return Envelope::error( 'invalid_args', sprintf( 'post_ids must be a list of at most %d post IDs.', self::MAX_PER_PAGE ) );
			}
			foreach ( $args['post_ids'] as $id ) {
				if ( ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) && (int) $id > 0 ) {
					$post_ids_filter[] = (int) $id;
				}
			}
			if ( ! $post_ids_filter ) {
				return Envelope::error( 'invalid_args', 'post_ids must be a list of post IDs.' );
			}
		}

		// Query strategy: WP_Query with paged/posts_per_page so we hit the
		// ORM cache paths (get_post_meta warms an in-memory row cache the
		// per-page loop reads from). Restricted to statuses agents actually
		// care about — trashed posts don't appear in SEO surfaces.
		$q_args = [
			'post_type'              => $post_type,
			'post_status'            => $include_drafts
				? [ 'publish', 'draft', 'pending', 'private', 'future' ]
				: [ 'publish' ],
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => false, // need total_pages for the response envelope
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		];
		if ( ! empty( $post_ids_filter ) ) {
			$q_args['post__in'] = $post_ids_filter;
			// Results come in ID order, a page at a time, whatever order the ids were sent in.
		}
		$q = new \WP_Query( $q_args );

		$pages = [];
		$issue_counter = [];
		$worst_offenders = [];

		// One call works for at most TIME_BUDGET seconds and sends back at most MAX_REPLY_BYTES of
		// findings; the posts left are listed so they can be asked for next.
		$started    = microtime( true );
		$stopped    = false;
		$remaining  = [];
		$reply_size = 0;
		$site_off   = '0' === (string) get_option( 'blog_public', '1' );
		if ( $q->have_posts() ) {
			foreach ( $q->posts as $post ) {
				if ( $stopped ) {
					$remaining[] = (int) $post->ID;
					continue;
				}
				if ( ! current_user_can( 'read_post', $post->ID ) ) {
					continue;
				}
				if ( microtime( true ) - $started > self::TIME_BUDGET ) {
					$stopped     = true;
					$remaining[] = (int) $post->ID;
					continue;
				}
				$findings = self::audit_single_post( $post, $detected, $count_decorative_alts );
				$size     = strlen( (string) wp_json_encode( $findings ) );
				if ( $pages && $reply_size + $size > self::MAX_REPLY_BYTES ) {
					$stopped     = true;
					$remaining[] = (int) $post->ID;
					continue;
				}
				$reply_size += $size;
				$pages[]     = $findings;

				foreach ( $findings['issues'] as $issue_key ) {
					// Normalize compound issues ("3_images_missing_alt")
					// into a bucket key ("images_missing_alt") so the
					// rollup counts occurrences not per-post magnitude.
					$bucket = self::issue_bucket_for( $issue_key );
					if ( ! isset( $issue_counter[ $bucket ] ) ) {
						$issue_counter[ $bucket ] = 0;
					}
					$issue_counter[ $bucket ]++;
				}

				if ( ! empty( $findings['issues'] ) ) {
					$worst_offenders[] = [
						'post_id'     => $findings['post_id'],
						'issue_count' => count( $findings['issues'] ),
					];
				}
			}
		}

		// Sort worst-offenders desc by issue_count, cap at 10 for envelope size.
		usort( $worst_offenders, static function ( $a, $b ) {
			return $b['issue_count'] <=> $a['issue_count'];
		} );
		$worst_offenders = array_slice( $worst_offenders, 0, 10 );

		arsort( $issue_counter );

		$rollup = null;
		if ( $rollup_on ) {
			$rollup = [
				'total_pages_audited' => count( $pages ),
				'issues_by_type'      => (object) $issue_counter,
				'worst_offenders'     => $worst_offenders,
			];
		}

		$structured = [
			'post_type' => $post_type,
			'pagination' => [
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) $q->max_num_pages,
				'total_posts' => (int) $q->found_posts,
			],
			// True when the time or size budget ran out before the page was finished. remaining_post_ids
			// can be sent back as post_ids to finish it; next_page is the page after this one.
			'stopped_early'        => $stopped,
			'remaining_post_ids'   => $remaining,
			'next_page'            => $page < (int) $q->max_num_pages ? $page + 1 : null,
			// WordPress's own "discourage search engines" switch covers every page, whatever each page says.
			'site_discourages_search_engines' => $site_off,
			'seo_plugin_detected'  => $detected,
			'pages'                => $pages,
			'thresholds'            => [
				'title_min'       => self::TITLE_MIN,
				'title_max'       => self::TITLE_MAX,
				'description_min' => self::DESC_MIN,
				'description_max' => self::DESC_MAX,
			],
		];
		if ( $rollup !== null ) {
			$structured['rollup'] = $rollup;
		}

		$summary_text = self::format_summary_text( $post_type, count( $pages ), $issue_counter, $detected );

		// Read-only: no undo block, so a no-argument undo can only reverse a real write.
		return Envelope::success(
			$summary_text,
			$structured
		);
	}

	/**
	 * The SEO plugin whose titles and descriptions the audit reads, returned
	 * to callers as `seo_plugin_detected`. The `royal_mcp_seo_plugin_detected`
	 * filter can force one of the listed values (a fork of an SEO plugin, a
	 * custom stack); anything else falls back to what was detected.
	 *
	 * @return string 'seobolt' | 'yoast' | 'rankmath' | 'seopress' | 'aioseo' | 'none'
	 */
	public static function detect_seo_plugin() {
		// Same precedence as the free tools (SEObolt, Yoast, Rank Math, SEOPress, AIOSEO).
		$detected = Seo_Plugin::detect();
		$filtered = apply_filters( 'royal_mcp_seo_plugin_detected', $detected );
		return in_array( $filtered, [ 'seobolt', 'yoast', 'rankmath', 'seopress', 'aioseo', 'none' ], true )
			? $filtered
			: $detected;
	}

	/**
	 * Audit a single post. Returns the per-page findings shape used
	 * by wp_audit_seo_bulk.
	 */
	private static function audit_single_post( \WP_Post $post, $detected, $count_decorative_alts = true ) {
		$post_id = (int) $post->ID;
		$meta    = self::read_seo_meta_for_post( $post_id, $detected, $post );

		// Title: the post's own SEO title, else the SEO plugin's template for the post type, else the post title.
		$title_value = (string) ( $meta['title'] ?? '' );
		if ( '' === $title_value && 'none' !== $detected ) {
			$title_value = self::get_site_default_template( $detected, 'title', $post );
		}
		if ( '' === $title_value ) {
			$title_value = (string) $post->post_title;
		}
		// Description: the post's own, else the template; Rank Math puts a hand-written excerpt before its template.
		$desc_value     = (string) ( $meta['description'] ?? '' );
		$desc_from_tpl  = false;
		if ( '' === $desc_value && 'rankmath' === $detected && '' !== trim( (string) $post->post_excerpt ) ) {
			$desc_value = (string) $post->post_excerpt;
		}
		if ( '' === $desc_value && 'none' !== $detected ) {
			$desc_value    = self::get_site_default_template( $detected, 'description', $post );
			$desc_from_tpl = '' !== $desc_value;
		}
		if ( '' === $desc_value ) {
			$desc_value = (string) $post->post_excerpt;
		}

		// Templates hold variables that never reach the page, so they are filled in before anything is
		// measured. When the SEO plugin cannot fill one in, the WordPress field is used instead.
		if ( 'none' !== $detected ) {
			$title_value = self::render_seo_template( $title_value, $detected, 'title', $post );
			$desc_value  = self::render_seo_template( $desc_value, $detected, 'description', $post );
			if ( self::has_unresolved_template_markers( $title_value, $detected ) ) {
				$title_value = (string) $post->post_title;
			}
			if ( self::has_unresolved_template_markers( $desc_value, $detected ) ) {
				$desc_value    = (string) $post->post_excerpt;
				$desc_from_tpl = false;
			}
		}
		$title_value = self::visible_text( $title_value );
		$desc_value  = self::visible_text( $desc_value );
		// Rank Math cuts a description that comes from its template at 160 characters.
		if ( $desc_from_tpl && 'rankmath' === $detected && self::str_visible_length( $desc_value ) > self::DESC_MAX ) {
			$desc_value = function_exists( 'mb_substr' ) ? mb_substr( $desc_value, 0, self::DESC_MAX ) : substr( $desc_value, 0, self::DESC_MAX );
		}

		$title_len = self::str_visible_length( $title_value );
		$desc_len  = self::str_visible_length( $desc_value );

		$title_issue = self::classify_length( $title_len, self::TITLE_MIN, self::TITLE_MAX );
		$desc_issue  = self::classify_length( $desc_len,  self::DESC_MIN,  self::DESC_MAX );

		// The content as stored, with comments, scripts, styles and shortcode tags taken out. Nothing is run.
		$stored   = (string) $post->post_content;
		$rendered = self::stored_markup( $stored );
		// Shortcodes and blocks that are filled in when the page is served: their text and images are not in the stored content.
		$made_at_render = 1 === preg_match( '/\[[A-Za-z][\w\-]*(?:\s[^\]]*)?\]|<!--\s*wp:[a-z0-9\/\-]+(?:\s+\{.*?\})?\s*\/-->/s', $stored );

		$word_count = self::count_words( wp_strip_all_tags( $rendered ) );

		// The theme usually prints the post title as the page's H1 outside the content, which cannot be seen here.
		$h1_count = (int) preg_match_all( '/<h1[\s>]/i', $rendered, $unused_h1 );
		$h1_theme_title_probable = post_type_supports( $post->post_type, 'title' );

		[ $image_count, $images_no_alt, $images_decorative_alt ] = self::count_images_and_missing_alt( $rendered );

		// A gallery shortcode shows its images only when the page is served, so their alt text cannot be checked here.
		$gallery_shortcodes  = self::detect_gallery_shortcodes( $stored );
		$images_unverifiable = ( 0 === $image_count && ! empty( $gallery_shortcodes ) );
		// By default an empty alt (the mark for a decorative image) counts as missing; with count_decorative_alts false only images with no alt at all do.
		$images_missing_alt = $count_decorative_alts
			? ( $images_no_alt + $images_decorative_alt )
			: $images_no_alt;

		$featured_image = has_post_thumbnail( $post_id );

		$issues = [];
		if ( $title_issue !== null ) {
			$issues[] = 'title_' . $title_issue;
		}
		if ( $desc_issue !== null ) {
			$issues[] = 'description_' . $desc_issue;
		}
		if ( ! empty( $meta['noindex'] ) ) {
			$issues[] = 'noindex_true';
		}
		if ( $h1_count === 0 ) {
			$issues[] = 'h1_missing';
		} elseif ( $h1_count > 1 ) {
			$issues[] = 'h1_multiple';
		}
		if ( ! $featured_image ) {
			$issues[] = 'no_featured_image';
		}
		if ( $images_missing_alt > 0 && ! $images_unverifiable ) {
			$issues[] = sprintf( '%d_images_missing_alt', $images_missing_alt );
		}
		if ( $images_unverifiable ) {
			$issues[] = 'images_unverifiable_shortcode';
		}

		// No H1 in the content is only a finding when the theme is unlikely to print the title as one.
		$issues = array_values( array_diff( $issues, [ 'h1_missing' ] ) );
		if ( 0 === $h1_count ) {
			$issues[] = $h1_theme_title_probable ? 'h1_unverifiable_theme_title' : 'h1_missing';
		}
		if ( $made_at_render ) {
			$issues[] = 'content_made_when_served';
		}

		return [
			'post_id'  => $post_id,
			'url'      => (string) get_permalink( $post_id ),
			'title'    => [
				'value'  => self::clip( $title_value ),
				'length' => $title_len,
				'issue'  => $title_issue,
			],
			'description' => [
				'value'  => self::clip( $desc_value ),
				'length' => $desc_len,
				'issue'  => $desc_issue,
			],
			'noindex'            => (bool) ( $meta['noindex'] ?? false ),
			'h1_count'           => $h1_count,
			'h1_theme_title_probable' => (bool) $h1_theme_title_probable,
			'word_count'         => $word_count,
			'image_count'          => $image_count,
			'images_missing_alt'   => $images_missing_alt,
			'images_no_alt'        => $images_no_alt,
			'images_decorative_alt' => $images_decorative_alt,
			'images_unverifiable'  => (bool) $images_unverifiable,
			'gallery_shortcodes'   => $gallery_shortcodes,
			'featured_image'       => (bool) $featured_image,
			// True when shortcodes or dynamic blocks add content as the page is served: word, heading and image counts cover the stored content only.
			'content_made_when_served' => (bool) $made_at_render,
			'issues'             => $issues,
		];
	}

	/** Roll a compound issue key like "3_images_missing_alt" into "images_missing_alt". */
	private static function issue_bucket_for( $issue_key ) {
		if ( preg_match( '/^\d+_(.+)$/', $issue_key, $m ) ) {
			return $m[1];
		}
		return $issue_key;
	}

	private static function format_summary_text( $post_type, $audited, array $counter, $detected ) {
		$plugin_label = ( $detected === 'none' )
			? 'no SEO plugin detected (WP native fields only)'
			: sprintf( 'SEO plugin: %s', $detected );

		if ( $audited === 0 ) {
			return sprintf( 'Audited 0 %s — nothing to report. %s.', $post_type, $plugin_label );
		}

		$top = [];
		$i = 0;
		foreach ( $counter as $issue => $n ) {
			if ( $i++ >= 3 ) {
				break;
			}
			$top[] = sprintf( '%d× %s', $n, $issue );
		}
		$top_str = empty( $top ) ? 'no issues found' : implode( ', ', $top );
		// Pluralization: post-type singular for 1, add 's' for 0/2+. English-only
		// pluralization is intentional here — this is a diagnostic summary for
		// agents/operators, not user-facing UI content that needs full i18n.
		$pt_label = $audited === 1 ? $post_type : $post_type . 's';
		return sprintf(
			'SEO audit — %d %s audited. Top issues: %s. %s.',
			$audited,
			$pt_label,
			$top_str,
			$plugin_label
		);
	}

	/**
	 * Read title/description/noindex from the detected SEO plugin's meta.
	 * Returns an assoc array with keys title/description/noindex. Missing
	 * values come back as '' / false so callers can safely default to
	 * post_title / post_excerpt without null checks.
	 */
	private static function read_seo_meta_for_post( $post_id, $detected, \WP_Post $post ) {
		switch ( $detected ) {
			case 'yoast':
				return [
					'title'       => (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ),
					'description' => (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
					'noindex'     => get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) === '1',
				];
			case 'rankmath':
				$robots = get_post_meta( $post_id, 'rank_math_robots', true );
				if ( ! is_array( $robots ) || ! $robots ) {
					// No setting on the post: Rank Math uses the post type's robots when they are switched on, else its global ones.
					$titles = get_option( 'rank-math-options-titles' );
					$titles = is_array( $titles ) ? $titles : [];
					$type   = (string) $post->post_type;
					$robots = ( isset( $titles[ 'pt_' . $type . '_custom_robots' ] ) && in_array( $titles[ 'pt_' . $type . '_custom_robots' ], [ 'on', true, 1, '1' ], true ) )
						? ( $titles[ 'pt_' . $type . '_robots' ] ?? [] )
						: ( $titles['robots_global'] ?? [] );
				}
				return [
					'title'       => (string) get_post_meta( $post_id, 'rank_math_title', true ),
					'description' => (string) get_post_meta( $post_id, 'rank_math_description', true ),
					// Rank Math never lets a private post be indexed.
					'noindex'     => ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) || 'private' === $post->post_status,
				];
			case 'seopress':
				// SEOPress robots key '_seopress_robots_index' stores 'yes'
				// (== noindex) when the box is checked in the editor. Empty
				// or missing == follow the site default (indexable).
				return [
					'title'       => (string) get_post_meta( $post_id, '_seopress_titles_title', true ),
					'description' => (string) get_post_meta( $post_id, '_seopress_titles_desc', true ),
					'noindex'     => get_post_meta( $post_id, '_seopress_robots_index', true ) === 'yes',
				];
			case 'seobolt':
				return [
					'title'       => (string) get_post_meta( $post_id, '_seobolt_meta_title', true ),
					'description' => (string) get_post_meta( $post_id, '_seobolt_meta_description', true ),
					'noindex'     => get_post_meta( $post_id, '_seobolt_robots_noindex', true ) === '1',
				];
			case 'aioseo':
				// AIOSEO writes to a custom table on modern versions AND
				// (historically) legacy post meta. Try meta first — same
				// pattern SEObolt uses when it composes an AIOSEO write —
				// and fall back to the aioseo_posts table lookup so audits
				// still surface values on modern-only installs.
				// noindex lives only in AIOSEO's table.
				$meta_title = (string) get_post_meta( $post_id, '_aioseo_title', true );
				$meta_desc  = (string) get_post_meta( $post_id, '_aioseo_description', true );
				$row        = self::aioseo_posts_row( $post_id );
				return [
					'title'       => $meta_title !== '' ? $meta_title : (string) ( is_array( $row ) ? ( $row['title'] ?? '' ) : '' ),
					'description' => $meta_desc !== '' ? $meta_desc : (string) ( is_array( $row ) ? ( $row['description'] ?? '' ) : '' ),
					'noindex'     => is_array( $row ) && self::aioseo_row_is_noindex( $row ),
				];
			case 'none':
			default:
				return [
					'title'       => '',
					'description' => '',
					'noindex'     => false,
				];
		}
	}

	/**
	 * Fetch the SEO engine's site-wide default template for a post type.
	 * Called when per-post meta is empty — the common case on any customer
	 * site that doesn't set custom SEO on every post. Return value is either
	 * a raw template (with %title%, %%sitename%%, etc. — will be resolved
	 * downstream by render_seo_template) OR an already-resolved final string
	 * (for AIOSEO, whose getPostTitle/getDescription API handles the whole
	 * chain including fallback + resolution internally).
	 *
	 * Returns empty string when the engine has no site-default OR the
	 * option store isn't shaped as expected. Caller falls through to
	 * post_title / post_excerpt.
	 *
	 * Per-engine option keys:
	 *   Yoast:    get_option('wpseo_titles')['title-<pt>' | 'metadesc-<pt>']
	 *   RankMath: get_option('rank-math-options-titles')['pt_<pt>_title' | 'pt_<pt>_description']
	 *   AIOSEO:   aioseo()->meta->title->getPostTitle($post_id) / description->getDescription($post)
	 *   SEOPress: get_option('seopress_titles_option_name')['seopress_titles_single_titles'][<pt>][title|description]
	 *   SEObolt:  no template system for post meta — returns '' (caller falls to post_title)
	 */
	private static function get_site_default_template( $engine, $field, \WP_Post $post ) {
		return self::without_site_code( static function () use ( $engine, $field, $post ) {
			return self::site_default_template( $engine, $field, $post );
		} );
	}

	/**
	 * Runs an SEO plugin's title or description resolver without running the site's shortcodes or
	 * excerpt filters: every shortcode renders as nothing (its tag is still known, so stripping
	 * shortcodes keeps working), the plugins' own "run shortcodes" switches are turned off where
	 * they have a filter for it, and get_the_excerpt returns the stored excerpt. Everything is put
	 * back afterwards.
	 */
	private static function without_site_code( callable $fn ) {
		global $shortcode_tags, $wp_filter;
		$saved_tags     = $shortcode_tags;
		$shortcode_tags = is_array( $saved_tags ) ? array_fill_keys( array_keys( $saved_tags ), '__return_empty_string' ) : []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored in finally
		$had_excerpt    = is_array( $wp_filter ) && isset( $wp_filter['get_the_excerpt'] );
		$saved_excerpt  = $had_excerpt ? $wp_filter['get_the_excerpt'] : null;
		if ( $had_excerpt ) {
			unset( $wp_filter['get_the_excerpt'] );
		}
		$yes = static function () {
			return true;
		};
		$no  = static function () {
			return false;
		};
		add_filter( 'aioseo_disable_shortcode_parsing', $yes, PHP_INT_MAX );
		add_filter( 'rank_math/paper/auto_generated_description/apply_shortcode', $no, PHP_INT_MAX );
		try {
			return $fn();
		} finally {
			remove_filter( 'aioseo_disable_shortcode_parsing', $yes, PHP_INT_MAX );
			remove_filter( 'rank_math/paper/auto_generated_description/apply_shortcode', $no, PHP_INT_MAX );
			if ( $had_excerpt ) {
				$wp_filter['get_the_excerpt'] = $saved_excerpt; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- put back as it was
			} elseif ( is_array( $wp_filter ) ) {
				unset( $wp_filter['get_the_excerpt'] );
			}
			$shortcode_tags = $saved_tags; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- put back as it was
		}
	}

	private static function site_default_template( $engine, $field, \WP_Post $post ) {
		$post_type = (string) $post->post_type;
		try {
			switch ( $engine ) {
				case 'yoast':
					$opts = get_option( 'wpseo_titles' );
					if ( is_array( $opts ) ) {
						$key = ( $field === 'title' ? 'title-' : 'metadesc-' ) . $post_type;
						if ( isset( $opts[ $key ] ) && is_string( $opts[ $key ] ) && $opts[ $key ] !== '' ) {
							return (string) $opts[ $key ];
						}
					}
					break;

				case 'rankmath':
					$opts = get_option( 'rank-math-options-titles' );
					if ( is_array( $opts ) ) {
						$key = 'pt_' . $post_type . '_' . ( $field === 'title' ? 'title' : 'description' );
						if ( isset( $opts[ $key ] ) && is_string( $opts[ $key ] ) && $opts[ $key ] !== '' ) {
							return (string) $opts[ $key ];
						}
					}
					// Rank Math's own default for a post type with no stored title template.
					if ( 'title' === $field ) {
						return '%title% %sep% %sitename%';
					}
					break;

				case 'aioseo':
					// Use AIOSEO's own resolver — it handles per-post →
					// site-default → auto-generation internally + returns a
					// fully-resolved string. Idempotent through downstream
					// replaceTags (no tags left to replace).
					if ( function_exists( 'aioseo' ) && isset( aioseo()->meta ) ) {
						$aioseo = aioseo();
						if ( $field === 'title'
							&& isset( $aioseo->meta->title )
							&& method_exists( $aioseo->meta->title, 'getPostTitle' ) ) {
							$out = $aioseo->meta->title->getPostTitle( $post->ID );
							if ( is_string( $out ) && $out !== '' ) {
								return (string) $out;
							}
						}
						if ( $field === 'description'
							&& isset( $aioseo->meta->description )
							&& method_exists( $aioseo->meta->description, 'getDescription' ) ) {
							$out = $aioseo->meta->description->getDescription( $post );
							if ( is_string( $out ) && $out !== '' ) {
								return (string) $out;
							}
						}
					}
					break;

				case 'seopress':
					$opts = get_option( 'seopress_titles_option_name' );
					if ( is_array( $opts ) && isset( $opts['seopress_titles_single_titles'][ $post_type ] ) ) {
						$conf = $opts['seopress_titles_single_titles'][ $post_type ];
						$key  = ( $field === 'title' ? 'title' : 'description' );
						if ( isset( $conf[ $key ] ) && is_string( $conf[ $key ] ) && $conf[ $key ] !== '' ) {
							return (string) $conf[ $key ];
						}
					}
					break;

				case 'seobolt':
					// SEObolt has title templates for posts and pages only.
					if ( 'title' === $field && in_array( $post_type, [ 'post', 'page' ], true ) ) {
						$settings = self::seobolt_settings();
						$template = $settings['titles'][ $post_type ]['title'] ?? '%title% %sep% %sitename%';
						return is_string( $template ) ? $template : '';
					}
					break;
			}
		} catch ( \Throwable $e ) {
			// Defensive: never let an option-store shape surprise crash the audit.
		}
		return '';
	}

	/**
	 * Resolve a stored SEO title/description template into its rendered form
	 * via the detected engine's own template resolver. Templates like Yoast's
	 * `%%page%% %%sep%% %%sitename%%` are stored literally in post meta and
	 * only expanded at render time. Measuring the raw stored template as if
	 * it were the final output produces false-positive length flags.
	 *
	 * Every branch defends with class_exists / function_exists / try-catch and
	 * falls back to the raw template on failure — a resolver bug must not
	 * crash the audit. When the resolver is unavailable OR returns empty
	 * unexpectedly, the raw string is returned (same length behavior as
	 * before this helper was added for that specific engine).
	 *
	 * The $field arg only matters for AIOSEO — AIOSEO's resolver API is
	 * field-specific (separate title/description generators that read from
	 * their own stored meta), so we need to route to the correct generator.
	 * Yoast / Rank Math / SEOPress all use field-agnostic template resolvers
	 * (replace_vars-style) and ignore $field.
	 *
	 * AIOSEO note: `getPostTitle($post_id)` and `getDescription($post)` read
	 * from `_aioseo_title` / `_aioseo_description` internally and return the
	 * fully-resolved string — the $template arg passed here is effectively
	 * ignored for AIOSEO. For our audit use case that's fine because we're
	 * always passing the same value the meta holds. Callers should not rely
	 * on being able to render an arbitrary template through the AIOSEO branch.
	 *
	 * @param string   $template Stored template (may contain engine-specific variables)
	 * @param string   $engine   Detected SEO engine slug (yoast|rankmath|aioseo|seopress|seobolt)
	 * @param string   $field    'title' or 'description' — routes AIOSEO to the right generator
	 * @param \WP_Post $post     Post context — resolvers need it for %%page%% / %title% / etc.
	 * @return string Rendered string (or raw template if resolver unavailable/failed)
	 */
	private static function render_seo_template( $template, $engine, $field, \WP_Post $post ) {
		if ( $template === '' ) {
			return '';
		}
		return self::without_site_code( static function () use ( $template, $engine, $field, $post ) {
			return self::render_template_now( $template, $engine, $field, $post );
		} );
	}

	private static function render_template_now( $template, $engine, $field, \WP_Post $post ) {
		try {
			switch ( $engine ) {
				case 'yoast':
					// Yoast's public API since v3.x. Signature: replace($template, $args).
					// $args accepts a WP_Post object (Yoast casts internally). Same
					// resolver handles both title and description templates uniformly.
					if ( class_exists( '\WPSEO_Replace_Vars' ) ) {
						$replacer = new \WPSEO_Replace_Vars();
						$out = $replacer->replace( $template, $post );
						if ( is_string( $out ) && $out !== '' ) {
							return $out;
						}
					}
					break;

				case 'aioseo':
					// AIOSEO exposes a field-agnostic template resolver via
					//   aioseo()->tags->replaceTags( $template, $post_id )
					// that handles all #post_title / #site_title / #separator_sa
					// style tags on any template string. Works for both title
					// and description templates uniformly (unlike getPostTitle /
					// getDescription which read from AIOSEO's internal store
					// and ignore the input template).
					//
					// The $field arg is currently ignored for AIOSEO since
					// replaceTags is universal — kept in the signature for
					// future engines that may need per-field dispatch.
					if ( function_exists( 'aioseo' ) ) {
						$aioseo = aioseo();
						if ( isset( $aioseo->tags ) && method_exists( $aioseo->tags, 'replaceTags' ) ) {
							$out = $aioseo->tags->replaceTags( $template, $post->ID );
							if ( is_string( $out ) && $out !== '' ) {
								return $out;
							}
						}
					}
					break;

				case 'rankmath':
					// Rank Math's Manager wires setup() to 'wp' (frontend) or
					// 'admin_enqueue_scripts' (admin) — neither fires during a
					// REST-dispatched audit call, so the built-in variables
					// (%title%, %sitename%, etc.) are never registered.
					// Force-invoking Manager::setup() on the singleton via the
					// rank_math() container registers them, then
					// Helper::replace_vars resolves the template. Idempotent —
					// Manager checks $is_setup internally so repeat calls are
					// no-ops.
					if ( function_exists( 'rank_math' )
						&& class_exists( '\RankMath\Helper' )
						&& method_exists( '\RankMath\Helper', 'replace_vars' ) ) {
						$rm = rank_math();
						if ( is_object( $rm ) && isset( $rm->variables )
							&& $rm->variables instanceof \RankMath\Replace_Variables\Manager ) {
							$rm->variables->setup();
						}
						$out = \RankMath\Helper::replace_vars( $template, $post );
						if ( is_string( $out ) && $out !== '' ) {
							return $out;
						}
					}
					break;

				case 'seopress':
					// SEOPress resolver via its own filter:
					//   apply_filters('seopress_dyn_variables_fn', null, $post, true)
					// Returns an array with two parallel arrays for str_replace:
					//   'seopress_titles_template_variables_array' — %%…%% markers
					//   'seopress_titles_template_replace_array'   — resolved values
					// Same mechanism SEOPress uses internally for oembed titles,
					// og:title, twitter:title, etc. Stable across versions.
					$variables = apply_filters( 'seopress_dyn_variables_fn', null, $post, true );
					if ( is_array( $variables )
						&& isset( $variables['seopress_titles_template_variables_array'], $variables['seopress_titles_template_replace_array'] )
						&& is_array( $variables['seopress_titles_template_variables_array'] )
						&& is_array( $variables['seopress_titles_template_replace_array'] ) ) {
						$out = (string) str_replace(
							$variables['seopress_titles_template_variables_array'],
							$variables['seopress_titles_template_replace_array'],
							$template
						);
						if ( $out !== '' ) {
							return $out;
						}
					}
					break;

				case 'seobolt':
					// SEObolt fills its variables in when the page is served. The common ones are filled in here;
					// any other is left in place, which makes the caller fall back to the WordPress field.
					if ( false === strpos( $template, '%' ) ) {
						return $template;
					}
					$settings = self::seobolt_settings();
					$sep      = isset( $settings['general']['separator'] ) && is_string( $settings['general']['separator'] ) ? $settings['general']['separator'] : '-';
					$excerpt  = '' !== (string) $post->post_password ? '' : ( '' !== trim( (string) $post->post_excerpt ) ? (string) $post->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ), 25 ) );
					return strtr( $template, [
						'%title%'    => (string) $post->post_title,
						'%sep%'      => $sep,
						'%sitename%' => (string) get_bloginfo( 'name' ),
						'%excerpt%'  => $excerpt,
					] );
			}
		} catch ( \Throwable $e ) {
			// Resolver blew up — fall through to raw fallback. Never let a
			// template-resolution bug in a third-party plugin crash the audit.
		}
		return $template;
	}

	/**
	 * Detect unresolved engine template markers in a string. Called after
	 * render_seo_template — a return value containing markers matching the
	 * engine's variable syntax indicates the resolver couldn't finish (class
	 * unavailable, variable not registered, etc.). Caller falls back to
	 * WP-native post_title / post_excerpt in that case.
	 *
	 * Engine-specific patterns intentionally narrow to reduce false positives
	 * on customer content that happens to contain % / # characters. Only
	 * matches lowercase-letters + underscores between the delimiters — real
	 * template variables never contain digits or uppercase.
	 */
	private static function has_unresolved_template_markers( $s, $engine ) {
		if ( $s === '' ) {
			return false;
		}
		switch ( $engine ) {
			case 'yoast':
			case 'seopress':
				// Both use %%…%% syntax
				return (bool) preg_match( '/%%[a-z_]+%%/', $s );
			case 'rankmath':
				// Single %…% — narrow to lowercase+underscore to skip URL-encoded
				// %20, "%K" literal text, etc. Real Rank Math variables are all
				// lowercase words.
				return (bool) preg_match( '/%[a-z_]+%/', $s );
			case 'aioseo':
				// AIOSEO uses #tag. Only its known tags are matched, so a hashtag or "#1 Best" in a real title is left alone.
				return (bool) preg_match(
					'/#(post_title|post_excerpt|post_content|post_date|post_author|permalink|'
					. 'site_title|site_description|separator_sa|categories|tags|author_name|'
					. 'author_first_name|author_last_name|current_year|current_month|current_day|'
					. 'archive_title|search_term|page_title|parent_title|taxonomy_title|'
					. 'taxonomy_description|term_title|term_description)\b/i',
					$s
				);
			case 'seobolt':
				return (bool) preg_match( '/%[a-z_]+%/', $s );
		}
		return false;
	}

	/** SEObolt's settings, or an empty list when the plugin is not loaded. */
	private static function seobolt_settings() {
		if ( class_exists( 'SEObolt_Settings' ) && is_callable( [ 'SEObolt_Settings', 'instance' ] ) ) {
			$object   = call_user_func( [ 'SEObolt_Settings', 'instance' ] );
			$settings = ( is_object( $object ) && is_callable( [ $object, 'get_settings' ] ) ) ? $object->get_settings() : [];
			return is_array( $settings ) ? $settings : [];
		}
		return [];
	}

	/** A title or description as a visitor's search result shows it: no tags, entities as characters, single spaces. */
	private static function visible_text( $s ) {
		$s = html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = preg_replace( '/[\s\x{00A0}]+/u', ' ', $s );
		return trim( is_string( $s ) ? $s : '' );
	}

	/** A value cut to what a reply should carry. */
	private static function clip( $s ) {
		$s = (string) $s;
		if ( self::str_visible_length( $s ) <= self::VALUE_MAX ) {
			return $s;
		}
		return ( function_exists( 'mb_substr' ) ? mb_substr( $s, 0, self::VALUE_MAX ) : substr( $s, 0, self::VALUE_MAX ) ) . '…';
	}

	/** Length in characters. */
	private static function str_visible_length( $s ) {
		if ( $s === '' ) {
			return 0;
		}
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $s ) : (int) strlen( $s );
	}

	/** Length classifier — returns 'missing' / 'too_short' / 'too_long' or null when OK. */
	private static function classify_length( $len, $min, $max ) {
		if ( $len === 0 ) {
			return 'missing';
		}
		if ( $len < $min ) {
			return 'too_short';
		}
		if ( $len > $max ) {
			return 'too_long';
		}
		return null;
	}

	/**
	 * Stored content ready for counting: HTML comments (block markers included), scripts, styles,
	 * templates and noscript copies are removed, and shortcode tags are taken out without being run.
	 */
	private static function stored_markup( $content ) {
		$content = (string) wp_check_invalid_utf8( (string) $content, true );
		$content = preg_replace( '/<!--.*?-->/s', ' ', $content );
		$content = preg_replace( '#<(script|style|template|noscript)\b[^>]*>.*?</\1\s*>#is', ' ', (string) $content );
		$content = preg_replace( '/\[\/?[A-Za-z][\w\-]*(?:\s[^\]]*)?\]/s', ' ', (string) $content );
		return is_string( $content ) ? $content : '';
	}

	/**
	 * Words in plain text, for any script: a run of letters or digits is a word, and in scripts
	 * written without spaces (Chinese, Japanese, Thai) each character counts.
	 */
	private static function count_words( $stripped ) {
		$text = html_entity_decode( (string) wp_check_invalid_utf8( (string) $stripped, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( '' === trim( $text ) ) {
			return 0;
		}
		$solo = (int) preg_match_all( '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}]/u', $text );
		$rest = preg_replace( '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}]/u', ' ', $text );
		$runs = (int) preg_match_all( '/[\p{L}\p{N}]+(?:[\x27\x{2019}\-][\p{L}\p{N}]+)*/u', is_string( $rest ) ? $rest : '' );
		return $solo + $runs;
	}

	/**
	 * Count <img> tags and how their alt text stands.
	 * Returns [ images, images with no alt attribute, images with an empty alt ]. An empty alt marks a
	 * decorative image; the caller decides whether that counts as missing.
	 */
	private static function count_images_and_missing_alt( $content ) {
		// A tag ends at the first > that is not inside a quoted attribute value.
		$count = preg_match_all( '/<img(?=[\s\/>])(?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*>/i', (string) $content, $imgs );
		if ( ! $count ) {
			return [ 0, 0, 0 ];
		}
		$no_alt     = 0;
		$decorative = 0;
		foreach ( $imgs[0] as $img_tag ) {
			// Other attributes' quoted values are emptied first, so text such as title="alt='x'" cannot pass for an alt.
			$bare = preg_replace_callback( '/([\w:\-]+)\s*=\s*("[^"]*"|\x27[^\x27]*\x27)/', static function ( $m ) {
				return 0 === strcasecmp( $m[1], 'alt' ) ? $m[0] : $m[1] . '=""';
			}, $img_tag );
			if ( ! is_string( $bare ) || ! preg_match( '/(?:\s|"|\x27)alt\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))/i', $bare, $m ) ) {
				$no_alt++;
				continue;
			}
			$alt = html_entity_decode( (string) ( $m[1] ?? '' ) . (string) ( $m[2] ?? '' ) . (string) ( $m[3] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( '' === trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $alt ) ) ) {
				$decorative++;
			}
		}
		return [ (int) $count, $no_alt, $decorative ];
	}

	/**
	 * Detect gallery-family shortcodes and builder modules that commonly
	 * render images through a lightbox/carousel rather than inline <img>.
	 * Returned as a list of shortcode names so the caller can surface
	 * "images_unverifiable — envira-gallery detected" instead of a
	 * confident false zero.
	 *
	 * @return array<string> Distinct shortcode names found, or empty array.
	 */
	private static function detect_gallery_shortcodes( $content ) {
		$known = [
			'gallery',
			'envira-gallery',
			'envira-album',
			'et_pb_gallery',
			'et_pb_image',
			'et_pb_fullwidth_image',
			'foogallery',
			'ngg_images',
			'nggallery',
			'modula',
			'photo_gallery',
			'wonderplugin_gridgallery',
			'sp_masterslider',
			'wpsm_gallery',
		];
		$found = [];
		foreach ( $known as $tag ) {
			$pattern = '/\[' . preg_quote( $tag, '/' ) . '\b[^\]]*\]/';
			if ( preg_match( $pattern, $content ) ) {
				$found[] = $tag;
			}
		}
		return $found;
	}

	/** aioseo_posts row lookup used by the audit tool. Delegates the table-
	 *  existence memoization to aioseo_posts_table_exists() so audit +
	 *  bulk-writer paths share one cached check per request. */
	private static function aioseo_posts_row( $post_id ) {
		if ( ! self::aioseo_posts_table_exists() ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT title, description, robots_default, robots_noindex FROM {$wpdb->prefix}aioseo_posts WHERE post_id = %d LIMIT 1",
			(int) $post_id
		), ARRAY_A );
	}

	/** AIOSEO stores robots_default=1 when the row inherits site defaults.
	 *  A stored 0 with robots_noindex=1 = explicit noindex. */
	private static function aioseo_row_is_noindex( array $row ) {
		$default_on = isset( $row['robots_default'] ) && (int) $row['robots_default'] === 1;
		if ( $default_on ) {
			return false;
		}
		return isset( $row['robots_noindex'] ) && (int) $row['robots_noindex'] === 1;
	}

	/** Table existence check, memoized per request. */
	private static function aioseo_posts_table_exists() {
		static $exists = null;
		if ( $exists !== null ) return $exists;
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $table ) . "'" );
		$exists = ( $found === $table );
		return $exists;
	}
}
