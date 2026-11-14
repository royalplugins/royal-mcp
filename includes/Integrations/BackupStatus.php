<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backup status reads for All-in-One WP Migration, WPvivid, Jetpack VaultPress
 * Backup and BackWPup.
 *
 * Every tool answers in the same shape so a site's backup coverage can be
 * read the same way whichever plugin makes the backups:
 *   { plugin, active, version, backups { count, latest_at, latest_size },
 *     items [ { id, created_at, size, label } ], schedule { enabled, ... },
 *     last_run { status, title } }
 * Read-only. Never returns credentials, download links, file names that
 * would let someone fetch an archive, or server paths.
 */
class BackupStatus {

	const MAX_ITEMS = 50;

	/** The tools this class answers, by name. */
	const TOOLS = [
		'ai1wm_get_backup_status'    => 'handle_ai1wm',
		'wpvivid_get_backup_status'  => 'handle_wpvivid',
		'jetpack_get_backup_status'  => 'handle_jetpack',
		'backwpup_get_backup_status' => 'handle_backwpup',
	];

	/** Whether a tool name belongs to this class. */
	public static function handles( $name ) {
		return is_string( $name ) && isset( self::TOOLS[ $name ] );
	}

	/** True when at least one of the four backup plugins is running. */
	public static function is_available() {
		return self::ai1wm_active() || self::wpvivid_active() || self::jetpack_active() || self::backwpup_active();
	}

	public static function get_tools() {
		return array_values( self::schemas() );
	}

	/**
	 * The permission check runs before anything is read, so the reply is the
	 * same for a caller without it whichever backup plugins the site runs.
	 * A plugin that is not active answers { active: false }, not an error.
	 */
	public static function execute_tool( $name, $args ) {
		self::require_cap();
		if ( ! self::handles( $name ) ) {
			throw new \Exception( 'Unknown backup status tool: ' . esc_html( is_string( $name ) ? $name : '' ) );
		}
		return call_user_func( [ __CLASS__, self::TOOLS[ $name ] ], is_array( $args ) ? $args : [] );
	}

	/** Backups can hold the whole site, so even their status is for administrators only. */
	private static function require_cap() {
		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to read backup status.' );
		}
	}

	/** Bounded item count. */
	private static function limit( array $args ) {
		return isset( $args['limit'] ) && is_numeric( $args['limit'] ) ? max( 1, min( self::MAX_ITEMS, (int) $args['limit'] ) ) : 10;
	}

	/** A stable id for a backup that does not reveal its file name. */
	private static function opaque_id( $plugin, $name ) {
		return substr( hash_hmac( 'sha256', $plugin . '|' . (string) $name, wp_salt( 'auth' ) ), 0, 16 );
	}

	/** Seconds since the epoch as ISO 8601 UTC, or null. */
	private static function iso( $ts ) {
		return is_numeric( $ts ) && (int) $ts > 0 ? gmdate( 'c', (int) $ts ) : null;
	}

	/** The reply for a plugin that is not running. */
	private static function inactive( $plugin, $label ) {
		return Envelope::success( sprintf( '%s is not active on this site.', $label ), [
			'plugin'  => $plugin,
			'active'  => false,
			'version' => null,
			'backups'  => [ 'count' => 0, 'latest_at' => null, 'latest_size' => null ],
			'items'    => [],
			'schedule' => null,
			'last_run' => null,
		] );
	}

	/** A name or label someone typed, without words that could be a host, an address, a path, a file or a secret. */
	private static function label_text( $text ) {
		return \Royal_MCP\MCP\Support\Location_Filter::clean( $text, 120 );
	}

	/** A plugin-stored value as a key-like string, or null when it is not a string. */
	private static function key_of( $value ) {
		return is_string( $value ) || is_int( $value ) ? substr( sanitize_key( (string) $value ), 0, 64 ) : null;
	}

	/** One-line summary shared by every monitor. */
	private static function summary( $label, array $backups ) {
		if ( 0 === (int) $backups['count'] ) {
			return sprintf( '%s: no backups found on this site.', $label );
		}
		return sprintf( '%s: %d backup(s); the latest was made %s.', $label, (int) $backups['count'], $backups['latest_at'] ? $backups['latest_at'] : 'at an unknown time' );
	}

	// ============================================================
	// All-in-One WP Migration
	// ============================================================

	public static function ai1wm_active() {
		return defined( 'AI1WM_VERSION' ) && class_exists( '\\Ai1wm_Backups' );
	}

	public static function handle_ai1wm( array $args ) {
		self::require_cap();
		if ( ! self::ai1wm_active() ) {
			return self::inactive( 'all-in-one-wp-migration', 'All-in-One WP Migration' );
		}
		$files  = (array) \Ai1wm_Backups::get_files();
		$labels = (array) \Ai1wm_Backups::get_labels();
		$items  = [];
		foreach ( array_slice( $files, 0, self::limit( $args ) ) as $f ) {
			$name    = isset( $f['filename'] ) ? (string) $f['filename'] : '';
			$items[] = [
				'id'         => self::opaque_id( 'ai1wm', $name ),
				'created_at' => self::iso( $f['mtime'] ?? null ),
				'size'       => isset( $f['size'] ) && is_numeric( $f['size'] ) ? (int) $f['size'] : null,
				'label'      => isset( $labels[ $name ] ) ? self::label_text( $labels[ $name ] ) : null,
			];
		}
		$latest  = $files ? reset( $files ) : null;
		$backups = [
			'count'       => count( $files ),
			'latest_at'   => is_array( $latest ) ? self::iso( $latest['mtime'] ?? null ) : null,
			'latest_size' => is_array( $latest ) && isset( $latest['size'] ) && is_numeric( $latest['size'] ) ? (int) $latest['size'] : null,
		];
		// The status option is the latest step of an export or an import; its message can carry links, so only the type and title are read.
		$status   = get_option( 'ai1wm_status', [] );
		$last_run = is_array( $status ) && isset( $status['type'] ) ? [
			'status' => self::key_of( $status['type'] ),
			'title'  => isset( $status['title'] ) ? self::label_text( wp_strip_all_tags( (string) ( is_scalar( $status['title'] ) ? $status['title'] : '' ) ) ) : null,
		] : null;
		return Envelope::success( self::summary( 'All-in-One WP Migration', $backups ), [
			'plugin'       => 'all-in-one-wp-migration',
			'active'       => true,
			'version'      => (string) AI1WM_VERSION,
			'backups'      => $backups,
			'items'        => $items,
			'schedule'     => [ 'enabled' => false, 'note' => 'The free plugin makes backups only when someone runs an export; scheduling needs a paid extension.' ],
			'last_run'     => $last_run,
			'downloadable' => (bool) \Ai1wm_Backups::are_downloadable(),
		] );
	}

	// ============================================================
	// WPvivid Backup & Migration
	// ============================================================

	public static function wpvivid_active() {
		return defined( 'WPVIVID_PLUGIN_VERSION' );
	}

	/**
	 * WPvivid's own getters write defaults and create tables, so its options
	 * are read directly. Each backup entry can hold full remote-storage
	 * settings; only the storage type is taken from it.
	 */
	public static function handle_wpvivid( array $args ) {
		self::require_cap();
		if ( ! self::wpvivid_active() ) {
			return self::inactive( 'wpvivid-backuprestore', 'WPvivid Backup & Migration' );
		}
		$list = get_option( 'wpvivid_backup_list', [] );
		$list = is_array( $list ) ? array_filter( $list, 'is_array' ) : [];
		uasort( $list, static function ( $a, $b ) {
			return (int) ( $b['create_time'] ?? 0 ) <=> (int) ( $a['create_time'] ?? 0 );
		} );
		$size_of = static function ( array $entry ) {
			$total = 0;
			foreach ( (array) ( $entry['backup']['files'] ?? [] ) as $f ) {
				$total += is_array( $f ) && isset( $f['size'] ) && is_numeric( $f['size'] ) ? (int) $f['size'] : 0;
			}
			return $total;
		};
		$items = [];
		foreach ( array_slice( $list, 0, self::limit( $args ), true ) as $id => $entry ) {
			$remote = [];
			foreach ( (array) ( $entry['remote'] ?? [] ) as $r ) {
				if ( is_array( $r ) && isset( $r['type'] ) && is_string( $r['type'] ) ) {
					$remote[] = sanitize_key( $r['type'] );
				}
			}
			$items[] = [
				'id'         => self::opaque_id( 'wpvivid', $id ),
				'created_at' => self::iso( $entry['create_time'] ?? null ),
				'size'       => $size_of( $entry ),
				'label'      => null,
				'trigger'    => isset( $entry['type'] ) ? self::key_of( $entry['type'] ) : null,
				'local'      => ! empty( $entry['save_local'] ),
				'remote'     => array_values( array_unique( $remote ) ),
				'locked'     => ! empty( $entry['lock'] ),
			];
		}
		$latest  = $list ? reset( $list ) : null;
		$backups = [
			'count'       => count( $list ),
			'latest_at'   => is_array( $latest ) ? self::iso( $latest['create_time'] ?? null ) : null,
			'latest_size' => is_array( $latest ) ? $size_of( $latest ) : null,
		];
		$recurrence = wp_get_schedule( 'wpvivid_main_schedule_event' );
		$common     = get_option( 'wpvivid_common_setting', [] );
		$schedule   = [
			'enabled'     => false !== $recurrence,
			'recurrence'  => false !== $recurrence ? (string) $recurrence : null,
			'next_run_at' => self::iso( wp_next_scheduled( 'wpvivid_main_schedule_event' ) ),
			'keep'        => is_array( $common ) && isset( $common['max_backup_count'] ) && is_numeric( $common['max_backup_count'] ) ? (int) $common['max_backup_count'] : null,
		];
		$storage = [];
		$upload  = get_option( 'wpvivid_upload_setting', [] );
		foreach ( is_array( $upload ) ? $upload : [] as $remote ) {
			if ( is_array( $remote ) && isset( $remote['type'] ) && is_string( $remote['type'] ) ) {
				$storage[] = sanitize_key( $remote['type'] );
			}
		}
		// The last result lives in WPvivid's own options table.
		global $wpdb;
		$last_run = null;
		$table    = $wpdb->base_prefix . 'wpvivid_options';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is prefix + literal
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", 'wpvivid_last_msg' ) );
			$msg = is_string( $raw ) && is_serialized( $raw ) ? @unserialize( $raw, [ 'allowed_classes' => false ] ) : null; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- plugin's own stored array, no objects
			if ( is_array( $msg ) && isset( $msg['status'] ) && is_array( $msg['status'] ) ) {
				$st       = $msg['status'];
				// The error text can name hosts, accounts and files, so only whether there was one is reported.
				$last_run = [
					'status'     => isset( $st['str'] ) ? self::key_of( $st['str'] ) : null,
					'started_at' => self::iso( $st['start_time'] ?? null ),
					'has_error'  => ! empty( $st['error'] ),
				];
			}
		}
		return Envelope::success( self::summary( 'WPvivid Backup & Migration', $backups ), [
			'plugin'   => 'wpvivid-backuprestore',
			'active'   => true,
			'version'  => (string) WPVIVID_PLUGIN_VERSION,
			'backups'  => $backups,
			'items'    => $items,
			'schedule' => $schedule,
			'storage'  => array_values( array_unique( $storage ) ),
			'last_run' => $last_run,
		] );
	}

	// ============================================================
	// Jetpack VaultPress Backup
	// ============================================================

	public static function jetpack_active() {
		return defined( 'JETPACK_BACKUP_PLUGIN_DIR' );
	}

	/**
	 * Jetpack keeps backups on WordPress.com and stores no history on the site.
	 * The connection state is read locally; recent backups come from the
	 * plugin's own REST route, which asks WordPress.com, and only when the
	 * site is connected.
	 */
	public static function handle_jetpack( array $args ) {
		self::require_cap();
		if ( ! self::jetpack_active() ) {
			return self::inactive( 'jetpack-backup', 'Jetpack VaultPress Backup' );
		}
		$main    = trailingslashit( (string) JETPACK_BACKUP_PLUGIN_DIR ) . 'jetpack-backup.php';
		$header  = is_readable( $main ) ? get_file_data( $main, [ 'Version' => 'Version' ] ) : [];
		$version = isset( $header['Version'] ) && '' !== $header['Version'] ? (string) $header['Version'] : null;
		$manager = '\\Automattic\\Jetpack\\Connection\\Manager';
		$connected = class_exists( $manager ) && ( new $manager() )->is_connected();
		$state     = get_transient( 'my-jetpack-backup-status' );
		$last_run  = null;
		if ( 'no_errors' === $state ) {
			$last_run = [ 'status' => 'ok', 'title' => null ];
		} elseif ( is_array( $state ) && isset( $state['data'] ) && is_array( $state['data'] ) ) {
			$last_run = [
				'status' => isset( $state['data']['status'] ) ? ( self::key_of( $state['data']['status'] ) ?? 'error' ) : 'error',
				'title'  => isset( $state['data']['source'] ) ? self::key_of( $state['data']['source'] ) : null,
			];
		}
		$items   = [];
		$backups = [ 'count' => 0, 'latest_at' => null, 'latest_size' => null ];
		$note    = null;
		if ( ! $connected ) {
			$note = 'The site is not connected to WordPress.com, so Jetpack is not backing it up.';
		} else {
			// Asking WordPress.com takes a request each time, so the answer is kept for a few minutes.
			$cache_key = 'royal_mcp_jp_backups';
			$data      = get_transient( $cache_key );
			if ( ! is_array( $data ) ) {
				$response = rest_do_request( new \WP_REST_Request( 'GET', '/jetpack/v4/backups' ) );
				$data     = $response instanceof \WP_REST_Response && $response->get_status() < 400 ? $response->get_data() : null;
				if ( is_array( $data ) ) {
					set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS ); // audit:transient-ttl-only -- short cache of a remote read
				} else {
					set_transient( $cache_key, [ '__failed' => true ], MINUTE_IN_SECONDS ); // audit:transient-ttl-only -- do not repeat a failing remote read on every call
				}
			}
			if ( is_array( $data ) && ! empty( $data['__failed'] ) ) {
				$data = null;
			}
			if ( ! is_array( $data ) ) {
				$note = 'WordPress.com did not return the backup list just now.';
			} else {
				$rows = array_values( array_filter( $data, static function ( $row ) {
					return is_array( $row ) && ( ! array_key_exists( 'is_backup', $row ) || ! empty( $row['is_backup'] ) );
				} ) );
				$all = [];
				foreach ( $rows as $index => $row ) {
					$when  = $row['last_updated'] ?? ( $row['started'] ?? null );
					$ref   = $row['id'] ?? ( $row['rewind_id'] ?? null );
					$all[] = [
						'id'         => self::opaque_id( 'jetpack', is_scalar( $ref ) ? $ref : 'row' . $index ),
						'created_at' => is_string( $when ) && strtotime( $when ) ? gmdate( 'c', strtotime( $when ) ) : null,
						'size'       => null,
						'label'      => null,
						'status'     => isset( $row['status'] ) ? self::key_of( $row['status'] ) : null,
					];
				}
				$items = array_slice( $all, 0, self::limit( $args ) );
				$done  = array_values( array_filter( $all, static function ( $i ) {
					return 'finished' === $i['status'] && null !== $i['created_at'];
				} ) );
				usort( $done, static function ( $a, $b ) {
					return strcmp( (string) $b['created_at'], (string) $a['created_at'] );
				} );
				$backups = [ 'count' => count( array_filter( $all, static function ( $i ) {
					return 'finished' === $i['status'];
				} ) ), 'latest_at' => $done ? $done[0]['created_at'] : null, 'latest_size' => null ];
			}
		}
		return Envelope::success( $note ? 'Jetpack VaultPress Backup: ' . $note : self::summary( 'Jetpack VaultPress Backup (recent backups)', $backups ), [
			'plugin'    => 'jetpack-backup',
			'active'    => true,
			'version'   => $version,
			'connected' => $connected,
			'backups'   => $backups,
			'items'     => $items,
			'schedule'  => [ 'enabled' => $connected ? null : false, 'note' => 'WordPress.com runs the backups on its own schedule.' ],
			'last_run'  => $last_run,
			'note'      => $note,
		] );
	}

	// ============================================================
	// BackWPup
	// ============================================================

	public static function backwpup_active() {
		return class_exists( '\\BackWPup', false ) && defined( 'BACKWPUP_PLUGIN_FILE' );
	}

	/** A stored UTC datetime as ISO 8601, or null. */
	private static function utc_iso( $value ) {
		if ( ! is_string( $value ) || '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return null;
		}
		$ts = strtotime( $value . ' UTC' );
		return $ts ? gmdate( 'c', $ts ) : null;
	}

	/**
	 * BackWPup jobs hold storage credentials and mail addresses, so each job is
	 * read through a list of allowed fields. History comes from BackWPup's
	 * backups table; the last result of each job from its log header.
	 */
	public static function handle_backwpup( array $args ) {
		self::require_cap();
		if ( ! self::backwpup_active() ) {
			return self::inactive( 'backwpup', 'BackWPup' );
		}
		$header  = is_readable( BACKWPUP_PLUGIN_FILE ) ? get_file_data( BACKWPUP_PLUGIN_FILE, [ 'Version' => 'Version' ] ) : [];
		$version = isset( $header['Version'] ) && '' !== $header['Version'] ? (string) $header['Version'] : null;
		$offset  = (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS;
		$jobs    = [];
		$stored  = get_site_option( 'backwpup_jobs', [] );
		foreach ( is_array( $stored ) ? $stored : [] as $job ) {
			if ( ! is_array( $job ) || empty( $job['jobid'] ) || ! empty( $job['tempjob'] ) ) {
				continue;
			}
			$id      = (int) $job['jobid'];
			$next    = wp_next_scheduled( 'backwpup_cron', [ 'arg' => $id ] );
			$result  = null;
			$logfile = isset( $job['logfile'] ) && is_string( $job['logfile'] ) ? $job['logfile'] : '';
			if ( '' !== $logfile && ! wp_is_stream( $logfile ) && is_readable( $logfile ) && method_exists( '\\BackWPup_Job', 'read_logheader' ) ) {
				$head = \BackWPup_Job::read_logheader( $logfile );
				if ( is_array( $head ) ) {
					$errors   = (int) ( $head['errors'] ?? 0 );
					$warnings = (int) ( $head['warnings'] ?? 0 );
					$result   = [
						'status'   => $errors > 0 ? 'errors' : ( $warnings > 0 ? 'warnings' : 'done' ),
						'errors'   => $errors,
						'warnings' => $warnings,
						'runtime'  => isset( $head['runtime'] ) && is_numeric( $head['runtime'] ) ? (int) $head['runtime'] : null,
						'size'     => isset( $head['backupfilesize'] ) && is_numeric( $head['backupfilesize'] ) ? (int) $head['backupfilesize'] : null,
					];
				}
			}
			$jobs[] = [
				'id'           => $id,
				'name'         => isset( $job['name'] ) ? self::label_text( $job['name'] ) : null,
				'contents'     => array_values( array_map( 'sanitize_key', array_filter( (array) ( $job['type'] ?? [] ), 'is_string' ) ) ),
				'destinations' => array_values( array_map( 'sanitize_key', array_filter( (array) ( $job['destinations'] ?? [] ), 'is_string' ) ) ),
				'scheduled'    => 'wpcron' === ( $job['activetype'] ?? '' ),
				'next_run_at'  => self::iso( $next ),
				// lastrun is stored in site time.
				'last_run_at'  => isset( $job['lastrun'] ) && is_numeric( $job['lastrun'] ) && (int) $job['lastrun'] > 0 ? gmdate( 'c', (int) ( $job['lastrun'] - $offset ) ) : null,
				'last_result'  => $result,
			];
		}
		global $wpdb;
		$table   = $wpdb->prefix . 'bwpup_backups';
		$items   = [];
		$backups = [ 'count' => 0, 'latest_at' => null, 'latest_size' => null ];
		$failed  = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			// BackWPup writes one row per destination of a backup; rows of one archive are one backup.
			$group = "COALESCE(filename, CONCAT('row', id))";
			// Older tables lack some columns; read those as empty.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is prefix + literal
			$have = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ) );
			$col  = static function ( $name, $agg ) use ( $have ) {
				return in_array( $name, $have, true ) ? sprintf( $agg, $name ) : 'NULL';
			};
			$job_col     = in_array( 'job_id', $have, true ) ? 'job_id' : 'NULL';
			$when_col    = in_array( 'submitted_at', $have, true ) ? 'COALESCE(submitted_at, modified)' : 'modified';
			$error_col   = $col( 'error_code', 'MAX(%s)' );
			$trigger_col = $col( 'backup_trigger', 'MAX(%s)' );
			$shown       = $wpdb->hide_errors();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is prefix + literal
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT MIN(id) AS id, {$job_col} AS job_id, GROUP_CONCAT(DISTINCT destination) AS destinations, GROUP_CONCAT(DISTINCT status) AS statuses, {$error_col} AS error_code, {$trigger_col} AS backup_trigger, MAX({$when_col}) AS at FROM {$table} GROUP BY {$group}, {$job_col} ORDER BY MAX(id) DESC LIMIT %d", self::limit( $args ) ), ARRAY_A );
			foreach ( $rows as $r ) {
				$statuses = array_map( 'sanitize_key', explode( ',', (string) $r['statuses'] ) );
				$items[]  = [
					'id'           => self::opaque_id( 'backwpup', $r['id'] ),
					'created_at'   => self::utc_iso( $r['at'] ),
					'size'         => null,
					'label'        => null,
					'status'       => array_intersect( $statuses, [ 'failed', 'aborted' ] ) ? 'failed' : ( in_array( 'completed', $statuses, true ) ? 'completed' : ( $statuses[0] ?? null ) ),
					'job_id'       => null === $r['job_id'] ? null : (int) $r['job_id'],
					'destinations' => array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $r['destinations'] ) ) ) ),
					'trigger'      => self::key_of( $r['backup_trigger'] ),
					'error_code'   => null === $r['error_code'] ? null : self::key_of( $r['error_code'] ),
				];
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is prefix + literal
			$agg = $wpdb->get_row( "SELECT COUNT(DISTINCT CASE WHEN status = 'completed' THEN {$group} END) AS done, COUNT(DISTINCT CASE WHEN status IN ('failed','aborted') THEN {$group} END) AS failed, MAX(CASE WHEN status = 'completed' THEN {$when_col} END) AS latest FROM {$table}", ARRAY_A );
			if ( $shown ) {
				$wpdb->show_errors();
			}
			$backups = [ 'count' => (int) ( $agg['done'] ?? 0 ), 'latest_at' => self::utc_iso( $agg['latest'] ?? null ), 'latest_size' => null ];
			$failed  = (int) ( $agg['failed'] ?? 0 );
		}
		$nexts      = array_values( array_filter( array_map( static function ( $j ) {
			return $j['next_run_at'] ? strtotime( $j['next_run_at'] ) : 0;
		}, $jobs ) ) );
		$latest_job = null;
		foreach ( $jobs as $j ) {
			if ( $j['last_run_at'] && ( null === $latest_job || $j['last_run_at'] > $latest_job['last_run_at'] ) ) {
				$latest_job = $j;
			}
		}
		if ( null === $backups['latest_at'] && $latest_job && $latest_job['last_result'] && 'errors' !== $latest_job['last_result']['status'] ) {
			$backups['latest_at']   = $latest_job['last_run_at'];
			$backups['latest_size'] = $latest_job['last_result']['size'];
		}
		return Envelope::success( self::summary( 'BackWPup', $backups ), [
			'plugin'   => 'backwpup',
			'active'   => true,
			'version'  => $version,
			'backups'  => array_merge( $backups, [ 'failed' => $failed ] ),
			'items'    => $items,
			'jobs'     => $jobs,
			'schedule' => [ 'enabled' => (bool) array_filter( array_column( $jobs, 'scheduled' ) ), 'next_run_at' => $nexts ? self::iso( min( $nexts ) ) : null ],
			'last_run' => $latest_job ? [ 'status' => $latest_job['last_result']['status'] ?? null, 'started_at' => $latest_job['last_run_at'], 'job_id' => $latest_job['id'] ] : null,
		] );
	}

	// ============================================================
	// Schemas
	// ============================================================

	private static function item_props() {
		return [
			'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_ITEMS, 'description' => 'How many recent backups to list (default 10).' ],
		];
	}

	private static function schemas() {
		return [
			'ai1wm_get_backup_status' => [
				'name'        => 'ai1wm_get_backup_status',
				'description' => 'Backup health from All-in-One WP Migration: backups {count, latest_at, latest_size}, the newest items [{id, created_at, size, label}], schedule (the free plugin has none), last_run {status, title}: the latest step of an export or an import, whichever ran last (a finished export reads status "download"), and whether archives are web-downloadable. File names, links and paths are never returned. Read-only. Requires manage_options. { active: false } when the plugin is not active.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => self::item_props(),
				],
			],
			'wpvivid_get_backup_status' => [
				'name'        => 'wpvivid_get_backup_status',
				'description' => 'Backup health from WPvivid Backup & Migration: backups {count, latest_at, latest_size}, the newest items [{id, created_at, size, trigger (manual, cron, upload), local, remote (storage types), locked}], schedule {enabled, recurrence, next_run_at, keep}, storage (configured remote types only, never their settings), last_run {status, started_at, has_error} (the error text is not returned: it can name hosts and accounts). Read-only. Requires manage_options. { active: false } when the plugin is not active.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => self::item_props(),
				],
			],
			'backwpup_get_backup_status' => [
				'name'        => 'backwpup_get_backup_status',
				'description' => 'Backup health from BackWPup: backups {count (completed), latest_at, failed}, the newest backups [{id, created_at, status, job_id, destinations, trigger, error_code}] (one entry per archive, whatever the number of destinations), jobs [{id, name, contents, destinations, scheduled, next_run_at, last_run_at, last_result {status, errors, warnings, runtime, size}}], schedule {enabled, next_run_at}, last_run. Storage credentials, mail addresses, file names, paths and links are never returned. Read-only. Requires manage_options. { active: false } when the plugin is not active.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => self::item_props(),
				],
			],
			'jetpack_get_backup_status' => [
				'name'        => 'jetpack_get_backup_status',
				'description' => 'Backup health from the Jetpack VaultPress Backup plugin (the standalone plugin; backups made through the full Jetpack plugin are not read): connected (to WordPress.com), backups {count, latest_at} over the most recent backups WordPress.com lists (about 10, so count is not a lifetime total) and the newest items [{id, created_at, status}] fetched from WordPress.com through the plugin when connected, last_run from the plugin\'s cached status check. Jetpack stores no backup history on the site, so a site that is not connected reports none. Read-only. Requires manage_options. { active: false } when the plugin is not active.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => self::item_props(),
				],
			],
		];
	}
}
