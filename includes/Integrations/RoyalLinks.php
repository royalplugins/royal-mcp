<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Royal Links MCP Integration
 *
 * Registers MCP tools for the Royal Links affiliate-link / URL-shortener / cloaker plugin.
 * Only loaded when Royal Links is active.
 */
class RoyalLinks {

	/**
	 * Check if Royal Links is available.
	 */
	public static function is_available() {
		return class_exists( 'Royal_Links_Post_Type' ) || post_type_exists( 'royal_link' );
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
				'name'        => 'rlinks_get_links',
				'description' => 'List Royal Links short URLs with destination, slug, click count, and category.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'    => [ 'type' => 'integer', 'description' => 'Max links to return (default 25, max 100)' ],
						'category' => [ 'type' => 'string', 'description' => 'Optional category slug to filter by' ],
						'search'   => [ 'type' => 'string', 'description' => 'Search term to match against title or destination URL' ],
					],
				],
			],
			[
				'name'        => 'rlinks_create_link',
				'description' => 'Create a new Royal Links short URL. Returns the public short URL on this site that redirects to the destination.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'title'           => [ 'type' => 'string', 'description' => 'Internal label for the link (e.g. "Affiliate – WPForms Pro")' ],
						'destination_url' => [ 'type' => 'string', 'description' => 'The URL the short link should redirect to' ],
						'slug'            => [ 'type' => 'string', 'description' => 'Custom slug (optional — auto-generated from title if omitted)' ],
						'redirect_type'   => [ 'type' => 'string', 'enum' => [ '301', '302', '307' ], 'description' => 'HTTP redirect type (default: 301 permanent)' ],
						'nofollow'        => [ 'type' => 'boolean', 'description' => 'Add rel="nofollow" (default: true)' ],
						'sponsored'       => [ 'type' => 'boolean', 'description' => 'Add rel="sponsored" for affiliate links (default: false)' ],
						'new_tab'         => [ 'type' => 'boolean', 'description' => 'Open in new tab (default: true)' ],
					],
					'required'   => [ 'title', 'destination_url' ],
				],
			],
			[
				'name'        => 'rlinks_get_link_stats',
				'description' => 'Get click analytics for a single Royal Link: total clicks, unique clicks, top countries, top referrers, browser/device breakdown over a period.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'link_id' => [ 'type' => 'integer', 'description' => 'Royal Link post ID' ],
						'period'  => [ 'type' => 'string', 'enum' => [ '7days', '30days', '90days', '12months', 'all' ], 'description' => 'Time period (default: 30days)' ],
					],
					'required'   => [ 'link_id' ],
				],
			],
		];
	}

	/**
	 * Execute a Royal Links MCP tool.
	 */
	public static function execute_tool( $name, $args ) {
		if ( ! self::is_available() ) {
			throw new \Exception( 'Royal Links is not active' );
		}

		switch ( $name ) {
			case 'rlinks_get_links':
				$query_args = [
					'post_type'      => 'royal_link',
					'post_status'    => 'publish',
					'posts_per_page' => min( intval( $args['limit'] ?? 25 ), 100 ),
					'orderby'        => 'date',
					'order'          => 'DESC',
				];
				if ( ! empty( $args['search'] ) ) {
					$query_args['s'] = sanitize_text_field( $args['search'] );
				}
				if ( ! empty( $args['category'] ) ) {
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- limited by posts_per_page (max 100); standard WP taxonomy filter pattern.
					$query_args['tax_query'] = [
						[
							'taxonomy' => 'royal_link_category',
							'field'    => 'slug',
							'terms'    => sanitize_text_field( $args['category'] ),
						],
					];
				}
				$posts = get_posts( $query_args );
				return array_map( [ __CLASS__, 'format_link' ], $posts );

			case 'rlinks_create_link':
				if ( ! class_exists( 'Royal_Links_Post_Type' ) ) {
					throw new \Exception( 'Royal_Links_Post_Type class not loaded' );
				}
				$create_args = [
					'title'           => sanitize_text_field( $args['title'] ?? '' ),
					'destination_url' => esc_url_raw( $args['destination_url'] ?? '' ),
				];
				if ( ! empty( $args['slug'] ) ) {
					$create_args['slug'] = sanitize_title( $args['slug'] );
				}
				if ( ! empty( $args['redirect_type'] ) ) {
					$create_args['redirect_type'] = sanitize_text_field( $args['redirect_type'] );
				}
				if ( isset( $args['nofollow'] ) ) {
					$create_args['nofollow'] = (bool) $args['nofollow'];
				}
				if ( isset( $args['sponsored'] ) ) {
					$create_args['sponsored'] = (bool) $args['sponsored'];
				}
				if ( isset( $args['new_tab'] ) ) {
					$create_args['new_tab'] = (bool) $args['new_tab'];
				}
				$result = \Royal_Links_Post_Type::create_link( $create_args );
				if ( is_wp_error( $result ) ) {
					throw new \Exception( esc_html( $result->get_error_message() ) );
				}
				$post = get_post( (int) $result );
				return self::format_link( $post );

			case 'rlinks_get_link_stats':
				if ( ! class_exists( 'Royal_Links_Tracker' ) ) {
					throw new \Exception( 'Royal_Links_Tracker class not loaded' );
				}
				$link_id = intval( $args['link_id'] ?? 0 );
				if ( $link_id <= 0 ) {
					throw new \Exception( 'link_id is required' );
				}
				$post = get_post( $link_id );
				if ( ! $post || $post->post_type !== 'royal_link' ) {
					throw new \Exception( 'Royal Link not found for ID ' . esc_html( (string) $link_id ) );
				}
				$period = sanitize_text_field( $args['period'] ?? '30days' );
				$stats  = \Royal_Links_Tracker::get_link_stats( $link_id, $period );
				return [
					'link_id' => $link_id,
					'title'   => $post->post_title,
					'period'  => $period,
					'stats'   => $stats,
				];

			default:
				throw new \Exception( 'Unknown Royal Links tool: ' . esc_html( $name ) );
		}
	}

	/**
	 * Format a royal_link post for response.
	 */
	private static function format_link( $post ) {
		if ( ! $post ) {
			return null;
		}
		$slug        = get_post_meta( $post->ID, '_royal_links_slug', true );
		$destination = get_post_meta( $post->ID, '_royal_links_destination_url', true );
		$total_hits  = (int) get_post_meta( $post->ID, '_royal_links_total_hits', true );
		$base        = trailingslashit( home_url() );
		$prefix      = get_option( 'royal_links_url_prefix', 'go' );
		$short_url   = $base . trim( $prefix, '/' ) . '/' . $slug;

		return [
			'id'              => (int) $post->ID,
			'title'           => $post->post_title,
			'slug'            => $slug,
			'short_url'       => $short_url,
			'destination_url' => $destination,
			'redirect_type'   => get_post_meta( $post->ID, '_royal_links_redirect_type', true ),
			'total_clicks'    => $total_hits,
			'date_created'    => $post->post_date,
		];
	}
}
