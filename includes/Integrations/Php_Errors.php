<?php
/**
 * PHP fatal errors without the debug log.
 *
 * Records fatal errors as they happen (any request: front end, wp-admin,
 * REST, cron, AJAX), keeping the last 50 distinct ones with a count, and
 * reports them with the plugins and themes WordPress paused after a fatal
 * error. File paths are relative to the WordPress folder; no request data
 * (URLs, query strings, bodies, cookies, user data) is stored. Messages go
 * through the credential scrubber when they are stored and when they are read.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Secret_Scrubber;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Php_Errors {

	const TOOL        = 'wp_get_php_errors';
	const OPTION      = 'royal_mcp_php_errors';
	const SETTING     = 'royal_mcp_record_php_errors';
	const SINCE       = 'royal_mcp_php_errors_since';
	const MAX_ENTRIES = 50;
	const MAX_MESSAGE = 500;
	const FATAL_TYPES = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ];

	/** Whether this request's fatal error is already recorded. */
	private static $recorded = false;

	/** Whether the capture hooks are in place. */
	private static $registered = false;

	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		// WordPress's own fatal-error handler runs first and ends the request,
		// so the error is recorded from the message filter it applies before
		// that. The shutdown function covers sites that switch the handler off.
		add_filter( 'wp_php_error_message', [ __CLASS__, 'on_wp_fatal_error' ], 1, 2 );
		register_shutdown_function( [ __CLASS__, 'on_shutdown' ] );
	}

	/** Recording is on unless the setting or the filter turns it off. */
	public static function enabled() {
		return (bool) apply_filters( 'royal_mcp_record_php_errors', '0' !== (string) get_option( self::SETTING, '1' ) );
	}

	/**
	 * @param string $message Message WordPress shows.
	 * @param array  $error   The error, as error_get_last() gives it.
	 */
	public static function on_wp_fatal_error( $message, $error ) {
		self::capture( $error );
		return $message;
	}

	public static function on_shutdown() {
		self::capture( error_get_last() );
	}

	private static function capture( $e ) {
		if ( ! is_array( $e ) || ! in_array( (int) ( $e['type'] ?? 0 ), self::FATAL_TYPES, true ) ) {
			return;
		}
		if ( self::$recorded ) {
			return;
		}
		self::$recorded = true;
		try {
			if ( function_exists( 'get_option' ) && self::enabled() ) {
				self::record( $e );
			}
		} catch ( \Throwable $t ) {
			// Recording must never add a second failure to the one being recorded.
			return;
		}
	}

	/** A folder as given and as resolved (symlinks), normalised, with a trailing slash. */
	private static function folder_forms( $dir ) {
		$forms = [ trailingslashit( wp_normalize_path( (string) $dir ) ) ];
		$real  = realpath( (string) $dir );
		if ( false !== $real ) {
			$forms[] = trailingslashit( wp_normalize_path( $real ) );
		}
		return array_unique( $forms );
	}

	/** The rest of $path after $folder, or null. Paths on Windows ignore case. */
	private static function after( $path, $folder ) {
		$head = substr( $path, 0, strlen( $folder ) );
		$same = '\\' === DIRECTORY_SEPARATOR ? 0 === strcasecmp( $head, $folder ) : $head === $folder;
		return $same ? substr( $path, strlen( $folder ) ) : null;
	}

	/** Path relative to the WordPress folder (the server's own path is never kept). */
	private static function relative( $path ) {
		$path = wp_normalize_path( (string) $path );
		foreach ( self::folder_forms( ABSPATH ) as $root ) {
			$rest = self::after( $path, $root );
			if ( null !== $rest ) {
				return $rest;
			}
		}
		return '(outside WordPress)/' . wp_basename( $path );
	}

	/** A message with the WordPress folder taken out of every path in it. */
	private static function strip_paths( $message ) {
		$message = wp_normalize_path( (string) $message );
		foreach ( self::folder_forms( ABSPATH ) as $root ) {
			$message = '\\' === DIRECTORY_SEPARATOR ? str_ireplace( $root, '', $message ) : str_replace( $root, '', $message );
		}
		return $message;
	}

	/** [ plugin slug, theme slug ] for a file, either may be null. */
	private static function owner( $path ) {
		$path = wp_normalize_path( (string) $path );
		foreach ( [ WP_PLUGIN_DIR, WPMU_PLUGIN_DIR ] as $dir ) {
			foreach ( self::folder_forms( $dir ) as $form ) {
				$rest = self::after( $path, $form );
				if ( null !== $rest ) {
					return [ preg_replace( '/\.php$/', '', (string) strtok( $rest, '/' ) ), null ];
				}
			}
		}
		foreach ( self::folder_forms( get_theme_root() ) as $form ) {
			$rest = self::after( $path, $form );
			if ( null !== $rest ) {
				return [ null, (string) strtok( $rest, '/' ) ];
			}
		}
		return [ null, null ];
	}

	/** Cut to MAX_MESSAGE characters. */
	private static function cut( $text ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, self::MAX_MESSAGE ) : substr( (string) $text, 0, self::MAX_MESSAGE );
	}

	/** Add one fatal error to the record (deduplicated by file, line and message). */
	public static function record( array $e ) {
		$file = self::relative( $e['file'] ?? '' );
		// Fatal messages can quote arguments and config values, so credentials are hidden before storing.
		// The failing file's own path in the message is shown the same way as the file field.
		$raw     = (string) ( $e['message'] ?? '' );
		$abs     = wp_normalize_path( (string) ( $e['file'] ?? '' ) );
		$raw     = '' !== $abs ? str_replace( $abs, $file, wp_normalize_path( $raw ) ) : $raw;
		$message = self::cut( Secret_Scrubber::scrub_text( self::strip_paths( $raw ), 'strict' ) );
		$line    = (int) ( $e['line'] ?? 0 );
		list( $plugin, $theme ) = self::owner( $e['file'] ?? '' );
		$key  = md5( $file . '|' . $line . '|' . $message );
		$now  = time();
		$list = get_option( self::OPTION, [] );
		$list = is_array( $list ) ? $list : [];
		if ( isset( $list[ $key ] ) && is_array( $list[ $key ] ) ) {
			$list[ $key ]['count']     = (int) $list[ $key ]['count'] + 1;
			$list[ $key ]['last_seen'] = $now;
		} else {
			$list[ $key ] = [
				'type'        => 'fatal',
				'message'     => $message,
				'file'        => $file,
				'line'        => $line,
				'plugin_slug' => $plugin,
				'theme_slug'  => $theme,
				'count'       => 1,
				'first_seen'  => $now,
				'last_seen'   => $now,
			];
		}
		uasort( $list, static function ( $a, $b ) {
			return (int) ( $b['last_seen'] ?? 0 ) <=> (int) ( $a['last_seen'] ?? 0 );
		} );
		$list = array_slice( $list, 0, self::MAX_ENTRIES, true );
		update_option( self::OPTION, $list, false );
		if ( false === get_option( self::SINCE, false ) ) {
			update_option( self::SINCE, $now, false );
		}
	}

	/**
	 * The tool: recorded fatal errors (newest first) plus paused plugins and themes.
	 *
	 * @param array $args since (ISO time), limit (1-50).
	 * @return array
	 * @throws \Exception On a bad argument or a caller without manage_options.
	 */
	public static function report( array $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'wp_get_php_errors requires manage_options.' );
		}
		$limit = self::MAX_ENTRIES;
		if ( isset( $args['limit'] ) && '' !== $args['limit'] ) {
			if ( ! is_numeric( $args['limit'] ) ) {
				throw new \Exception( 'limit must be a number from 1 to 50.' );
			}
			$limit = max( 1, min( self::MAX_ENTRIES, (int) $args['limit'] ) );
		}
		$since = false;
		if ( isset( $args['since'] ) && '' !== $args['since'] ) {
			$since = is_string( $args['since'] ) && strlen( $args['since'] ) <= 64 ? strtotime( $args['since'] ) : false;
			if ( false === $since ) {
				throw new \Exception( 'since must be a date and time, e.g. 2026-10-26T08:00:00Z.' );
			}
		}
		$list = get_option( self::OPTION, [] );
		$list = is_array( $list ) ? array_filter( $list, 'is_array' ) : [];
		uasort( $list, static function ( $a, $b ) {
			return (int) ( $b['last_seen'] ?? 0 ) <=> (int) ( $a['last_seen'] ?? 0 );
		} );
		$out  = [];
		foreach ( is_array( $list ) ? $list : [] as $row ) {
			if ( ! is_array( $row ) || ( false !== $since && (int) ( $row['last_seen'] ?? 0 ) <= $since ) ) {
				continue;
			}
			$out[] = [
				'type'        => 'fatal',
				'message'     => Secret_Scrubber::scrub_text( (string) ( $row['message'] ?? '' ), 'strict' ),
				'file'        => (string) ( $row['file'] ?? '' ),
				'line'        => (int) ( $row['line'] ?? 0 ),
				'plugin_slug' => isset( $row['plugin_slug'] ) ? (string) $row['plugin_slug'] : null,
				'theme_slug'  => isset( $row['theme_slug'] ) ? (string) $row['theme_slug'] : null,
				'count'       => (int) ( $row['count'] ?? 1 ),
				'first_seen'  => gmdate( 'c', (int) ( $row['first_seen'] ?? 0 ) ),
				'last_seen'   => gmdate( 'c', (int) ( $row['last_seen'] ?? 0 ) ),
			];
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		$paused = static function ( $store ) {
			$rows = [];
			if ( is_object( $store ) && method_exists( $store, 'get_all' ) ) {
				foreach ( (array) $store->get_all() as $slug => $err ) {
					$msg    = is_array( $err ) ? (string) ( $err['message'] ?? '' ) : '';
					$rows[] = [ 'slug' => (string) $slug, 'message' => self::cut( Secret_Scrubber::scrub_text( self::strip_paths( $msg ), 'strict' ) ) ];
				}
			}
			return $rows;
		};
		$started = get_option( self::SINCE, false );
		return [
			'capture_enabled' => self::enabled(),
			'captured_since'  => false === $started ? null : gmdate( 'c', (int) $started ),
			'errors'          => $out,
			'paused_plugins'  => function_exists( 'wp_paused_plugins' ) ? $paused( wp_paused_plugins() ) : [],
			'paused_themes'   => function_exists( 'wp_paused_themes' ) ? $paused( wp_paused_themes() ) : [],
		];
	}
}
