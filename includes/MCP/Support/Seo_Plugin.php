<?php
/**
 * Which SEO plugin owns SEO meta on this site — one answer for every tool.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Seo_Plugin {

	/** Display names, in the order detect() checks the plugins. */
	const NAMES = [
		'seobolt'  => 'SEObolt',
		'yoast'    => 'Yoast SEO',
		'rankmath' => 'Rank Math',
		'seopress' => 'SEOPress',
		'aioseo'   => 'All in One SEO',
	];

	/** Prefix of each SEO plugin's own post meta keys. */
	const KEY_PREFIXES = [
		'seobolt'  => '_seobolt_',
		'yoast'    => '_yoast_wpseo_',
		'rankmath' => 'rank_math_',
		'seopress' => '_seopress_',
		'aioseo'   => '_aioseo_',
	];

	/** Main plugin files, free and premium editions. */
	const PLUGIN_FILES = [
		'yoast'    => [ 'wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php' ],
		'rankmath' => [ 'seo-by-rank-math/rank-math.php', 'seo-by-rank-math-pro/rank-math-pro.php' ],
		'seopress' => [ 'wp-seopress/seopress.php', 'wp-seopress-pro/seopress-pro.php' ],
		'aioseo'   => [ 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' ],
	];

	/** Post meta key that holds each plugin's noindex flag. */
	const NOINDEX_KEYS = [
		'yoast'    => '_yoast_wpseo_meta-robots-noindex',
		'rankmath' => 'rank_math_robots',
		'aioseo'   => '_aioseo_noindex',
		'seopress' => '_seopress_robots_index',
		'seobolt'  => '_seobolt_robots_noindex',
	];

	/**
	 * When several SEO plugins are active, the first match wins:
	 * SEObolt, Yoast, Rank Math, SEOPress, AIOSEO.
	 *
	 * @return string 'seobolt' | 'yoast' | 'rankmath' | 'seopress' | 'aioseo' | 'none'
	 */
	public static function detect() {
		$detected = 'none';
		if ( self::seobolt_active() ) {
			$detected = 'seobolt';
		} elseif ( self::plugin_active( 'yoast', defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) ) {
			$detected = 'yoast';
		} elseif ( self::plugin_active( 'rankmath', defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) ) {
			$detected = 'rankmath';
		} elseif ( self::plugin_active( 'seopress', defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_get_service' ) ) ) {
			$detected = 'seopress';
		} elseif ( self::plugin_active( 'aioseo', defined( 'AIOSEO_VERSION' ) || defined( 'AIOSEO_FILE' ) || function_exists( 'aioseo' ) ) ) {
			$detected = 'aioseo';
		}

		/**
		 * Filters the detected SEO plugin.
		 *
		 * @param string $detected Plugin key.
		 */
		$filtered = apply_filters( 'royal_mcp_seo_plugin_detected', $detected );
		return in_array( $filtered, [ 'seobolt', 'yoast', 'rankmath', 'seopress', 'aioseo', 'none' ], true ) ? $filtered : $detected;
	}

	/**
	 * Post meta key for each field wp_update_seo_meta routes, per plugin.
	 * noindex and slug are handled separately (NOINDEX_KEYS, post_name).
	 *
	 * AIOSEO and SEObolt omit og_*: they store Open Graph data in different
	 * shapes (AIOSEO in its own table, SEObolt reusing the core fields).
	 * AIOSEO's authoritative storage is the wp_aioseo_posts table; the post
	 * meta rows below are its import bridge and stay for callers that rely on
	 * them, while its twitter_* fields are left out so they report as
	 * unsupported_fields.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function field_maps() {
		return [
			'yoast'    => [
				'title'               => '_yoast_wpseo_title',
				'description'         => '_yoast_wpseo_metadesc',
				'focus_keyword'       => '_yoast_wpseo_focuskw',
				'og_title'            => '_yoast_wpseo_opengraph-title',
				'og_description'      => '_yoast_wpseo_opengraph-description',
				'twitter_title'       => '_yoast_wpseo_twitter-title',
				'twitter_description' => '_yoast_wpseo_twitter-description',
				'twitter_image'       => '_yoast_wpseo_twitter-image',
			],
			'rankmath' => [
				'title'               => 'rank_math_title',
				'description'         => 'rank_math_description',
				'focus_keyword'       => 'rank_math_focus_keyword',
				'og_title'            => 'rank_math_facebook_title',
				'og_description'      => 'rank_math_facebook_description',
				'twitter_title'       => 'rank_math_twitter_title',
				'twitter_description' => 'rank_math_twitter_description',
				'twitter_image'       => 'rank_math_twitter_image',
			],
			'aioseo'   => [
				'title'               => '_aioseo_title',
				'description'         => '_aioseo_description',
				'focus_keyword'       => '_aioseo_focus_keyphrase',
			],
			'seopress' => [
				'title'               => '_seopress_titles_title',
				'description'         => '_seopress_titles_desc',
				'focus_keyword'       => '_seopress_analysis_target_kw',
				'og_title'            => '_seopress_social_fb_title',
				'og_description'      => '_seopress_social_fb_desc',
				'twitter_title'       => '_seopress_social_twitter_title',
				'twitter_description' => '_seopress_social_twitter_desc',
				'twitter_image'       => '_seopress_social_twitter_img',
			],
			'seobolt'  => [
				'title'               => '_seobolt_meta_title',
				'description'         => '_seobolt_meta_description',
				'focus_keyword'       => '_seobolt_focus_keyword',
				'twitter_title'       => '_seobolt_twitter_title',
				'twitter_description' => '_seobolt_twitter_description',
				'twitter_image'       => '_seobolt_twitter_image',
			],
		];
	}

	/**
	 * The SEO plugin a post meta key belongs to, and the wp_update_seo_meta
	 * field that writes it (null when no field covers that key).
	 *
	 * @param string $key Post meta key.
	 * @return array{plugin:string, name:string, field:?string}|null
	 */
	public static function owner_of_meta_key( $key ) {
		$key  = (string) $key;
		$maps = self::field_maps();
		foreach ( self::KEY_PREFIXES as $plugin => $prefix ) {
			if ( 0 !== strpos( $key, $prefix ) ) {
				continue;
			}
			$field = array_search( $key, $maps[ $plugin ] ?? [], true );
			if ( false === $field && ( self::NOINDEX_KEYS[ $plugin ] ?? '' ) === $key ) {
				$field = 'noindex';
			}
			return [
				'plugin' => $plugin,
				'name'   => self::NAMES[ $plugin ],
				'field'  => false === $field ? null : (string) $field,
			];
		}
		return null;
	}

	/**
	 * Message for a refused write to a protected SEO plugin key: names the
	 * plugin and the tool and field that write it. Null when the key does not
	 * belong to an SEO plugin, so the caller keeps its permission message.
	 *
	 * @param string $key    Post meta key.
	 * @param string $action 'edit' | 'add' | 'delete'.
	 * @return string|null
	 */
	public static function protected_meta_message( $key, $action ) {
		$owner = self::owner_of_meta_key( $key );
		if ( null === $owner ) {
			return null;
		}
		$name   = $owner['name'];
		$field  = $owner['field'];
		$active = self::detect();

		if ( $active !== $owner['plugin'] ) {
			if ( 'none' === $active ) {
				return sprintf( '%1$s belongs to %2$s, which is not active on this site, so Royal MCP does not write it.', $key, $name );
			}
			$message = sprintf( '%1$s belongs to %2$s, but Royal MCP writes SEO fields to %3$s on this site.', $key, $name, self::NAMES[ $active ] );
			$active_fields = array_merge( array_keys( self::field_maps()[ $active ] ?? [] ), [ 'noindex' ] );
			if ( null !== $field && in_array( $field, $active_fields, true ) ) {
				$message .= sprintf( ' Use wp_update_seo_meta (field: %1$s) to set it in %2$s.', $field, self::NAMES[ $active ] );
			}
			return $message;
		}

		if ( null === $field ) {
			$fields = array_merge( array_keys( self::field_maps()[ $owner['plugin'] ] ?? [] ), [ 'noindex' ] );
			return sprintf(
				'%1$s is managed by %2$s and is protected. wp_update_seo_meta covers these %2$s fields: %3$s. Other %2$s fields cannot be changed through Royal MCP.',
				$key,
				$name,
				implode( ', ', $fields )
			);
		}

		if ( 'delete' === $action ) {
			$how = 'noindex' === $field
				? 'Use wp_update_seo_meta (field: noindex) with false to clear it.'
				: sprintf( 'Use wp_update_seo_meta (field: %s) with an empty value to clear it.', $field );
		} else {
			$how = sprintf( 'Use wp_update_seo_meta (field: %s).', $field );
		}
		return sprintf( '%1$s is managed by %2$s. %3$s', $key, $name, $how );
	}

	/**
	 * Whether any active SEO plugin marks the post noindex.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_noindex( $post_id ) {
		$post_id = (int) $post_id;
		foreach ( self::NOINDEX_KEYS as $plugin => $key ) {
			if ( ! self::is_active( $plugin ) ) {
				continue;
			}
			$value = get_post_meta( $post_id, $key, true );
			if ( 'rankmath' === $plugin ? in_array( 'noindex', (array) $value, true ) : ( 'seopress' === $plugin ? 'yes' === $value : ( 'yoast' === $plugin ? '1' === (string) $value : (bool) $value ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether one SEO plugin is active, whichever plugin detect() picks as the
	 * primary one when several are active.
	 *
	 * @param string $key seobolt | yoast | rankmath | seopress | aioseo.
	 * @return bool
	 */
	public static function is_active( $key ) {
		switch ( (string) $key ) {
			case 'seobolt':
				return self::seobolt_active();
			case 'yoast':
				return self::plugin_active( 'yoast', defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) );
			case 'rankmath':
				return self::plugin_active( 'rankmath', defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) );
			case 'seopress':
				return self::plugin_active( 'seopress', defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_get_service' ) );
			case 'aioseo':
				return self::plugin_active( 'aioseo', defined( 'AIOSEO_VERSION' ) || defined( 'AIOSEO_FILE' ) || function_exists( 'aioseo' ) );
		}
		return false;
	}

	/**
	 * Active by the plugin list first. A constant or class alone counts only
	 * when the plugin is not installed under its usual folder (must-use or
	 * renamed install): a constant can linger from a plugin deactivated
	 * earlier in the same request.
	 *
	 * @param string $key    Key in PLUGIN_FILES.
	 * @param bool   $loaded Whether the plugin's constant or class is present.
	 * @return bool
	 */
	private static function plugin_active( $key, $loaded ) {
		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			return (bool) $loaded;
		}
		$installed = false;
		foreach ( self::PLUGIN_FILES[ $key ] ?? [] as $file ) {
			if ( is_plugin_active( $file ) ) {
				return true;
			}
			if ( defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				$installed = true;
			}
		}
		return $installed ? false : (bool) $loaded;
	}

	/**
	 * SEObolt Pro or Lite active (plugin list first; a constant alone can
	 * linger from a plugin deactivated earlier in the same request).
	 *
	 * @return bool
	 */
	private static function seobolt_active() {
		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'is_plugin_active' ) ) {
			foreach ( [ 'seobolt-pro/seobolt-pro.php', 'seobolt/seobolt.php' ] as $slug ) {
				if ( is_plugin_active( $slug ) ) {
					return true;
				}
			}
			return false;
		}
		return defined( 'SEOBOLT_VERSION' );
	}
}
