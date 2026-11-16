<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Tool_Refusal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image optimisation status for Smush, EWWW Image Optimizer, Imagify and
 * ShortPixel.
 *
 * One read-only tool answers in the same shape for whichever of the four is
 * active. Every number comes from the plugin's own stored statistics or its
 * own counting functions; nothing is re-scanned here and no outside service
 * is contacted. API keys are reported only as saved or not saved.
 */
class ImageOptimisation {

	const TOOL = 'image_optimisation_get_status';

	/** Providers in the order they are reported, with their display names. */
	const PROVIDERS = [
		'smush'      => 'Smush',
		'ewww'       => 'EWWW Image Optimizer',
		'imagify'    => 'Imagify',
		'shortpixel' => 'ShortPixel',
	];

	/** Arguments the tool accepts. */
	const ARGS = [ 'provider' ];

	/** True when at least one of the four plugins is running. */
	public static function is_available() {
		return [] !== self::active_providers();
	}

	/** Slugs of the providers that are loaded and active, in report order. */
	public static function active_providers() {
		$out = [];
		foreach ( array_keys( self::PROVIDERS ) as $slug ) {
			if ( self::provider_active( $slug ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/** Whether one provider's code is loaded and the plugin is active. */
	public static function provider_active( $slug ) {
		switch ( $slug ) {
			case 'smush':
				return defined( 'WP_SMUSH_VERSION' ) && defined( 'WP_SMUSH_BASENAME' ) && class_exists( 'WP_Smush', false )
					&& self::plugin_file_active( (string) WP_SMUSH_BASENAME );
			case 'ewww':
				return defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' ) && defined( 'EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE_REL' ) && function_exists( 'ewwwio' )
					&& self::plugin_file_active( (string) EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE_REL );
			case 'imagify':
				return defined( 'IMAGIFY_VERSION' ) && defined( 'IMAGIFY_FILE' ) && class_exists( 'Imagify_Options' )
					&& self::plugin_file_active( plugin_basename( (string) IMAGIFY_FILE ) );
			case 'shortpixel':
				return defined( 'SHORTPIXEL_IMAGE_OPTIMISER_VERSION' ) && defined( 'SHORTPIXEL_PLUGIN_FILE' ) && function_exists( 'wpSPIO' )
					&& class_exists( '\ShortPixel\Controller\StatsController' )
					&& self::plugin_file_active( plugin_basename( (string) SHORTPIXEL_PLUGIN_FILE ) );
		}
		return false;
	}

	/**
	 * A plugin whose code is loaded counts as active when WordPress lists it as
	 * active, or when it was loaded from outside the plugins folder (for
	 * example as a must-use plugin). Code that stays loaded after a
	 * deactivation earlier in the same request does not count.
	 */
	private static function plugin_file_active( $basename ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( $basename ) ) {
			return true;
		}
		return ! file_exists( WP_PLUGIN_DIR . '/' . $basename );
	}

	public static function get_tools() {
		return [
			[
				'name'        => self::TOOL,
				'description' => 'Read the image optimisation status from Smush, EWWW Image Optimizer, Imagify or ShortPixel, for each of them that is active (usually one): plugin version; whether it is set up, including whether an API key is saved (the key itself is never returned); media library images in total, optimised and not yet optimised, as the plugin itself counts them; space saved so far; estimated space still to save where the plugin keeps that figure (otherwise null); whether new uploads are optimised automatically; and whether a bulk run is in progress. Numbers come from each plugin\'s own stored statistics, which some plugins refresh only when their own scan runs, so they can lag behind the media library; the reply says so where the plugin records it. Makes no request to any outside service. Read-only; starting a bulk run is not offered. Administrators only.',
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => [
						'provider' => [
							'type'        => 'string',
							'enum'        => array_keys( self::PROVIDERS ),
							'description' => 'Read only this plugin: smush, ewww, imagify or shortpixel. It must be active. Default: every active one.',
						],
					],
					'additionalProperties' => false,
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use image optimisation tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Smush, EWWW Image Optimizer, Imagify or ShortPixel is not active' );
		}

		switch ( $name ) {
			case self::TOOL:
				return self::get_status( self::validate_args( $args ) );
		}
		throw new \Exception( 'Unknown image optimisation tool: ' . esc_html( is_string( $name ) ? $name : '' ) );
	}

	/** The provider asked for, or null for every active one. Anything else is refused. */
	private static function validate_args( $args ) {
		if ( null === $args ) {
			$args = [];
		}
		if ( is_object( $args ) ) {
			$args = get_object_vars( $args );
		}
		if ( ! is_array( $args ) ) {
			throw new Tool_Refusal( 'invalid_args', 'Arguments must be an object.' );
		}
		$unknown = array_diff( array_map( 'strval', array_keys( $args ) ), self::ARGS );
		if ( $unknown ) {
			throw new Tool_Refusal( 'unknown_argument', 'Unknown argument: ' . esc_html( implode( ', ', array_slice( $unknown, 0, 5 ) ) ) . '. The only argument is provider.' );
		}
		if ( ! array_key_exists( 'provider', $args ) ) {
			return null;
		}
		$p = $args['provider'];
		if ( ! is_string( $p ) || ! isset( self::PROVIDERS[ $p ] ) ) {
			throw new Tool_Refusal( 'invalid_args', 'provider must be one of: ' . implode( ', ', array_keys( self::PROVIDERS ) ) . '.' );
		}
		return $p;
	}

	private static function get_status( $only ) {
		$active = self::active_providers();
		if ( null !== $only && ! in_array( $only, $active, true ) ) {
			throw new Tool_Refusal(
				'provider_not_active',
				sprintf( '%s is not active on this site.', self::PROVIDERS[ $only ] ),
				[ 'active_providers' => $active ]
			);
		}
		$slugs   = null === $only ? $active : [ $only ];
		$entries = [];
		$lines   = [];
		foreach ( $slugs as $slug ) {
			$entry     = self::read_provider( $slug );
			$entries[] = $entry;
			$lines[]   = self::summary_line( $entry );
		}
		return Envelope::success( implode( ' ', $lines ), [
			'active_providers' => $active,
			'providers'        => $entries,
		] );
	}

	/** One provider's status. A read that fails reports stats_available: false instead of an error. */
	private static function read_provider( $slug ) {
		$entry = self::blank( $slug );
		try {
			switch ( $slug ) {
				case 'smush':
					return self::read_smush( $entry );
				case 'ewww':
					return self::read_ewww( $entry );
				case 'imagify':
					return self::read_imagify( $entry );
				case 'shortpixel':
					return self::read_shortpixel( $entry );
			}
		} catch ( \Throwable $e ) {
			$entry['stats_available'] = false;
			$entry['notes'][]         = sprintf( 'Could not read %s\'s statistics.', self::PROVIDERS[ $slug ] );
		}
		return $entry;
	}

	/** The common reply shape, filled in by each provider. */
	private static function blank( $slug ) {
		return [
			'provider'                => $slug,
			'name'                    => self::PROVIDERS[ $slug ],
			'version'                 => null,
			'set_up'                  => null,
			'key_required'        => null,
			'key_saved'           => null,
			'stats_available'         => true,
			'images'                  => [
				'total'       => null,
				'optimised'   => null,
				'unoptimised' => null,
			],
			'space_saved'             => [
				'bytes'   => null,
				'human'   => null,
				'percent' => null,
			],
			'estimated_space_to_save' => null,
			'auto_optimise_on_upload' => null,
			'bulk_run_in_progress'    => null,
			'notes'                   => [],
		];
	}

	private static function summary_line( array $e ) {
		if ( ! $e['stats_available'] ) {
			return sprintf( '%s: statistics could not be read.', $e['name'] );
		}
		$img  = $e['images'];
		$line = sprintf( '%s: %s of %s images optimised, %s not yet optimised', $e['name'], self::num( $img['optimised'] ), self::num( $img['total'] ), self::num( $img['unoptimised'] ) );
		if ( null !== $e['space_saved']['human'] ) {
			$line .= '; ' . $e['space_saved']['human'] . ' saved';
		}
		if ( true === $e['bulk_run_in_progress'] ) {
			$line .= '; a bulk run is in progress';
		}
		return $line . '.';
	}

	private static function num( $n ) {
		return null === $n ? 'unknown' : (string) (int) $n;
	}

	private static function bytes( $n ) {
		return is_numeric( $n ) ? max( 0, (int) $n ) : null;
	}

	private static function set_saved( array &$e, $bytes, $percent ) {
		$bytes = self::bytes( $bytes );
		$e['space_saved'] = [
			'bytes'   => $bytes,
			'human'   => null === $bytes ? null : size_format( $bytes, 1 ),
			'percent' => is_numeric( $percent ) ? round( (float) $percent, 1 ) : null,
		];
	}

	// ============================================================
	// Smush
	// ============================================================

	private static function read_smush( array $e ) {
		$e['version']          = (string) WP_SMUSH_VERSION;
		$e['set_up']           = true;
		$e['key_required'] = false;

		$gs_class = '\Smush\Core\Stats\Global_Stats';
		if ( ! class_exists( $gs_class ) ) {
			$e['stats_available'] = false;
			$e['notes'][]         = 'Smush has not finished loading, so its statistics are not available yet.';
			return $e;
		}
		$gs        = $gs_class::get();
		$remaining = (int) $gs->get_remaining_count();
		$total     = (int) $gs->get_image_attachment_count();
		$e['images'] = [
			'total'           => $total,
			'optimised'       => max( 0, (int) $gs->get_total_optimizable_items_count() - $remaining ),
			'unoptimised'     => $remaining,
			'skipped'         => (int) $gs->get_skipped_count(),
			'optimised_files' => (int) $gs->get_optimized_images_count(),
		];
		$sum = $gs->get_sum_of_optimization_global_stats();
		self::set_saved( $e, $sum->get_bytes(), $sum->get_percent() );

		$outdated            = (bool) $gs->is_outdated();
		$e['stats_outdated'] = $outdated;
		$updated             = (int) $gs->get_stats_updated_timestamp();
		$e['stats_updated_at'] = $updated > 0 ? gmdate( 'c', $updated ) : null;
		if ( $outdated ) {
			$e['notes'][] = 'Smush marks these numbers as out of date: they are from its last media library scan, which can be re-run from the Smush dashboard.';
		}

		$settings_class = '\Smush\Core\Settings';
		if ( class_exists( $settings_class ) ) {
			$settings = $settings_class::get_instance();
			if ( method_exists( $settings, 'is_auto_smush_enabled' ) ) {
				$e['auto_optimise_on_upload'] = (bool) $settings->is_auto_smush_enabled();
			}
		}

		$status_class = '\Smush\Core\Background\Background_Process_Status';
		if ( class_exists( $status_class ) ) {
			// Same process name Smush's bulk process manager uses.
			$identifier = 'wp_smush_bulk_smush_background_process' . ( is_multisite() ? '_' . get_current_blog_id() : '' );
			$status     = new $status_class( $identifier );
			$e['bulk_run_in_progress'] = (bool) $status->is_in_processing() && ! $status->is_cancelled() && ! $status->is_dead() && ! $status->is_completed();
		}
		return $e;
	}

	// ============================================================
	// EWWW Image Optimizer
	// ============================================================

	private static function read_ewww( array $e ) {
		global $wpdb;
		$data         = get_file_data( WP_PLUGIN_DIR . '/' . EWWW_IMAGE_OPTIMIZER_PLUGIN_FILE_REL, [ 'Version' => 'Version' ] );
		$e['version'] = ! empty( $data['Version'] ) ? (string) $data['Version'] : (string) EWWW_IMAGE_OPTIMIZER_VERSION;

		$key = function_exists( 'ewww_image_optimizer_get_option' ) ? ewww_image_optimizer_get_option( 'ewww_image_optimizer_cloud_key' ) : get_option( 'ewww_image_optimizer_cloud_key' );
		$e['key_required'] = false;
		$e['key_saved']    = is_string( $key ) && '' !== trim( $key );
		$e['set_up']           = true;
		$e['mode']             = $e['key_saved'] ? 'cloud' : 'local';
		if ( ! $e['key_saved'] ) {
			$e['notes'][] = 'EWWW Image Optimizer works without an API key, using the server\'s own optimisation tools.';
		}

		$noauto = function_exists( 'ewww_image_optimizer_get_option' ) ? ewww_image_optimizer_get_option( 'ewww_image_optimizer_noauto' ) : get_option( 'ewww_image_optimizer_noauto' );
		$e['auto_optimise_on_upload'] = ! $noauto;

		$total  = function_exists( 'ewww_image_optimizer_count_attachments' ) ? (int) ewww_image_optimizer_count_attachments() : null;
		$images = ! empty( $wpdb->ewwwio_images ) ? (string) $wpdb->ewwwio_images : $wpdb->prefix . 'ewwwio_images';
		$queue  = ! empty( $wpdb->ewwwio_queue ) ? (string) $wpdb->ewwwio_queue : $wpdb->prefix . 'ewwwio_queue';

		if ( self::table_exists( $images ) ) {
			// Same "optimised" rule as EWWW's own optimised-images count (not pending, has a size, processed at least once).
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb.
			$files     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$images}` WHERE pending = 0 AND image_size > 0 AND updates > 0" );
			$optimised = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT attachment_id) FROM `{$images}` WHERE gallery = 'media' AND attachment_id > 0 AND pending = 0 AND image_size > 0 AND updates > 0" );
			// phpcs:enable
			$pending = function_exists( 'ewww_image_optimizer_aux_images_table_count_pending' ) ? (int) ewww_image_optimizer_aux_images_table_count_pending() : null;
			$e['images'] = [
				'total'           => $total,
				'optimised'       => null === $total ? $optimised : min( $optimised, $total ),
				'unoptimised'     => null === $total ? null : max( 0, $total - $optimised ),
				'optimised_files' => $files,
				'pending_files'   => $pending,
			];
			if ( function_exists( 'ewww_image_optimizer_savings' ) ) {
				$sv = ewww_image_optimizer_savings();
				if ( is_array( $sv ) && 2 === count( $sv ) ) {
					$opt  = (int) $sv[0];
					$orig = (int) $sv[1];
					self::set_saved( $e, $orig - $opt, $orig > 0 ? ( $orig - $opt ) / $orig * 100 : 0 );
				}
			}
		} else {
			$e['images'] = [
				'total'           => $total,
				'optimised'       => 0,
				'unoptimised'     => $total,
				'optimised_files' => 0,
				'pending_files'   => 0,
			];
			self::set_saved( $e, 0, 0 );
			$e['notes'][] = 'EWWW Image Optimizer has not optimised any images yet.';
		}

		$running = false;
		$plugin  = ewwwio();
		foreach ( [ 'background_media', 'background_image' ] as $prop ) {
			if ( isset( $plugin->$prop ) && is_object( $plugin->$prop ) ) {
				if ( method_exists( $plugin->$prop, 'is_process_running' ) && $plugin->$prop->is_process_running() ) {
					$running = true;
				}
			}
		}
		if ( ! $running && self::table_exists( $queue ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb.
			$running = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$queue}`" ) > 0;
		}
		if ( ! $running && ( get_option( 'ewww_image_optimizer_bulk_resume' ) || get_option( 'ewww_image_optimizer_aux_resume' ) ) ) {
			$running = true;
		}
		$e['bulk_run_in_progress'] = $running;
		return $e;
	}

	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	// ============================================================
	// Imagify
	// ============================================================

	private static function read_imagify( array $e ) {
		$e['version']          = (string) IMAGIFY_VERSION;
		$e['key_required'] = true;

		$key                     = function_exists( 'get_imagify_option' ) ? get_imagify_option( 'api_key' ) : \Imagify_Options::get_instance()->get( 'api_key' );
		$e['key_saved']      = is_string( $key ) && '' !== $key;
		$e['set_up']             = $e['key_saved'];
		$e['key_checked']    = false;
		if ( ! $e['key_saved'] ) {
			$e['notes'][] = 'Imagify needs an API key before it can optimise images.';
		}
		$e['auto_optimise_on_upload'] = (bool) ( function_exists( 'get_imagify_option' ) ? get_imagify_option( 'auto_optimize' ) : \Imagify_Options::get_instance()->get( 'auto_optimize' ) );

		if ( ! function_exists( 'imagify_count_attachments' ) || ! function_exists( 'imagify_count_saving_data' ) ) {
			$e['stats_available'] = false;
			$e['notes'][]         = 'Imagify has not finished loading, so its statistics are not available yet.';
		} else {
			$e['images'] = [
				'total'       => (int) imagify_count_attachments(),
				'optimised'   => (int) imagify_count_optimized_attachments(),
				'unoptimised' => max( 0, (int) imagify_count_unoptimized_attachments() ),
				'errors'      => (int) imagify_count_error_attachments(),
			];
			$saving = imagify_count_saving_data();
			if ( is_array( $saving ) ) {
				$orig = (int) ( $saving['original_size'] ?? 0 );
				$opt  = (int) ( $saving['optimized_size'] ?? 0 );
				self::set_saved( $e, $orig > 0 ? $orig - $opt : 0, $saving['percent'] ?? null );
			}
		}

		$e['bulk_run_in_progress'] = (bool) get_transient( 'imagify_wp_optimize_running' ) || (bool) get_transient( 'imagify_custom-folders_optimize_running' );
		return $e;
	}

	// ============================================================
	// ShortPixel
	// ============================================================

	private static function read_shortpixel( array $e ) {
		$e['version']          = (string) SHORTPIXEL_IMAGE_OPTIMISER_VERSION;
		$e['key_required'] = true;

		// Read from the stored key record so that no key check against ShortPixel's service is started.
		$stored = get_option( 'spio_key', [] );
		$stored = is_array( $stored ) ? $stored : [];
		$key    = defined( 'SHORTPIXEL_API_KEY' ) ? (string) SHORTPIXEL_API_KEY : (string) ( $stored['apiKey'] ?? '' );
		$e['key_saved']    = '' !== trim( $key );
		$e['key_verified'] = $e['key_saved'] && ! empty( $stored['verifiedKey'] );
		$e['set_up']           = $e['key_verified'];
		if ( ! $e['key_saved'] ) {
			$e['notes'][] = 'ShortPixel needs an API key before it can optimise images.';
		} elseif ( ! $e['key_verified'] ) {
			$e['notes'][] = 'ShortPixel has an API key saved but has not confirmed it yet.';
		}

		$settings = \wpSPIO()->settings();
		$e['auto_optimise_on_upload'] = (bool) $settings->autoMediaLibrary;

		$stats     = \ShortPixel\Controller\StatsController::getInstance();
		$total     = (int) $stats->find( 'media', 'itemsTotal' );
		$optimised = (int) $stats->find( 'media', 'items' );
		$e['images'] = [
			'total'           => $total,
			'optimised'       => $optimised,
			'unoptimised'     => max( 0, $total - $optimised ),
			'optimised_files' => (int) $stats->find( 'media', 'images' ),
		];
		$updated               = (int) $stats->find( 'lastUpdate' );
		$e['stats_updated_at'] = $updated > 0 ? gmdate( 'c', $updated ) : null;

		$avg = method_exists( $stats, 'getAverageCompression' ) ? $stats->getAverageCompression() : null;
		$e['space_saved']['percent'] = is_numeric( $avg ) ? round( (float) $avg, 1 ) : null;
		$e['notes'][] = 'ShortPixel does not keep a total of bytes saved; space_saved.percent is its average compression over recently optimised images.';

		try {
			$e['bulk_run_in_progress'] = (bool) \ShortPixel\Controller\BulkController::getInstance()->isAnyBulkRunning();
		} catch ( \Throwable $ex ) {
			$e['bulk_run_in_progress'] = null;
		}
		return $e;
	}
}
