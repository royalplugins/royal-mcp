<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Location_Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SiteVault backup health: a one-call summary, an archive check, cancelling a
 * backup in progress, and the state of a running restore.
 *
 * Works with SiteVault Pro (RB_ classes) and SiteVault (SiteVault_ classes);
 * a part the installed plugin does not have is reported as empty, not as an error.
 * The summary answers { active: false } when neither plugin is running.
 */
class SiteVaultHealth {

	/** The tools this class answers, by name. */
	const TOOLS = [
		'sv_get_dashboard_summary' => 'handle_get_dashboard_summary',
		'sv_verify_backup'         => 'handle_verify_backup',
		'sv_verify_backup_health'  => 'handle_verify_backup',
		'sv_cancel_backup'         => 'handle_cancel_backup',
		'sv_get_restore_status'    => 'handle_get_restore_status',
	];

	/** Whether a tool name belongs to this class. */
	public static function handles( $name ) {
		return is_string( $name ) && isset( self::TOOLS[ $name ] );
	}

	public static function execute_tool( $name, $args ) {
		self::require_cap();
		if ( ! self::handles( $name ) ) {
			throw new \Exception( 'Unknown SiteVault tool: ' . esc_html( is_string( $name ) ? $name : '' ) );
		}
		return call_user_func( [ __CLASS__, self::TOOLS[ $name ] ], is_array( $args ) ? $args : [] );
	}

	/** Backups can hold the whole site, so even their status is for administrators only. */
	private static function require_cap() {
		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use SiteVault tools.' );
		}
	}

	/** The loaded SiteVault class for a name such as Backup_Manager, or null. */
	private static function cls( $suffix ) {
		foreach ( [ 'RB_', 'SiteVault_' ] as $prefix ) {
			if ( class_exists( '\\' . $prefix . $suffix ) ) {
				return '\\' . $prefix . $suffix;
			}
		}
		return null;
	}

	/** The object behind a SiteVault class with an instance() method, or null. */
	private static function obj( $suffix ) {
		$class = self::cls( $suffix );
		return ( null !== $class && is_callable( [ $class, 'instance' ] ) ) ? call_user_func( [ $class, 'instance' ] ) : null;
	}

	/** Whether the active SiteVault can report on background restores. */
	public static function has_background_restores() {
		$restorer = self::active() ? self::obj( 'Async_Restore' ) : null;
		return (bool) $restorer && is_callable( [ $restorer, 'get_restore_status' ] );
	}

	private static function active() {
		return null !== self::cls( 'Backup_Manager' );
	}

	/** True when the Pro plugin's classes are the ones loaded. */
	private static function is_pro() {
		return class_exists( '\\RB_Backup_Manager' );
	}

	/** A positive whole number from an argument, or 0. */
	private static function id_arg( $raw ) {
		return ( ( is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ) && (int) $raw > 0 ) ? (int) $raw : 0;
	}

	// ==================== sv_get_dashboard_summary ====================

	public static function handle_get_dashboard_summary( array $args ) {
		self::require_cap();
		if ( ! self::active() ) {
			return Envelope::success( 'sv_get_dashboard_summary: SiteVault is not active on this site.', [
				'active'            => false,
				'backup_age_days'   => null,
				'latest_backup'     => null,
				'backup_count'      => null,
				'schedule'          => null,
				'storage_providers' => [],
			] );
		}

		$manager = self::obj( 'Backup_Manager' );
		$stats   = ( $manager && is_callable( [ $manager, 'get_stats' ] ) ) ? $manager->get_stats() : [];
		$stats   = is_array( $stats ) ? $stats : [];
		$last    = ( isset( $stats['last_backup'] ) && is_object( $stats['last_backup'] ) ) ? $stats['last_backup'] : null;
		$latest  = self::latest_backup( $last );

		$schedule              = self::schedule();
		$schedule['schedules'] = self::schedule_health();
		$latest_failed         = self::latest_failed_backup();
		if ( ! empty( $schedule['schedules'] ) ) {
			// A run WP-Cron fires a little late is not a missed backup; follow
			// SiteVault's own verdict (stale = no run started within twice the
			// schedule interval, orphan = no cron event).
			$schedule['on_track'] = true;
			foreach ( $schedule['schedules'] as $s ) {
				if ( 'stale' === $s['last_run_status'] || $s['is_orphan'] ) {
					$schedule['on_track'] = false;
				}
			}
		}
		// SiteVault stamps last_run when a run starts, so a schedule whose
		// backups keep failing still reads as fresh: also off track when
		// the newest failure started after the newest completed backup.
		if ( is_array( $latest_failed ) && true === ( $schedule['on_track'] ?? null ) ) {
			$failed_ts    = strtotime( (string) $latest_failed['started_at'] );
			$completed_ts = ( $last && ! empty( $last->created_at ) ) ? self::site_local_to_ts( $last->created_at ) : false;
			if ( false === $completed_ts || ( false !== $failed_ts && $failed_ts > $completed_ts ) ) {
				$schedule['on_track'] = false;
			}
		}

		$summary = [
			'active'          => true,
			'backup_age_days' => $latest['age_days'],
			'latest_backup'   => $latest['metadata'],
			'backup_count'    => [
				'total'            => (int) ( $stats['total'] ?? 0 ),
				'completed'        => (int) ( $stats['completed'] ?? 0 ),
				'failed'           => (int) ( $stats['failed'] ?? 0 ),
				'total_size_bytes' => (int) ( $stats['total_size'] ?? 0 ),
				'retention_target' => (int) get_option( self::is_pro() ? 'rb_backup_retention' : 'sitevault_backup_retention', 30 ),
			],
			'latest_failed_backup' => $latest_failed,
			'schedule'             => $schedule,
			'storage_providers'    => self::storage_providers(),
		];

		$age  = $summary['backup_age_days'];
		$text = sprintf(
			'sv_get_dashboard_summary: %d backups (%d completed, %d failed), latest %s, %d active schedule(s)%s, %d storage provider(s).',
			$summary['backup_count']['total'],
			$summary['backup_count']['completed'],
			$summary['backup_count']['failed'],
			null === $age ? 'never' : $age . 'd ago',
			(int) ( $schedule['active_count'] ?? 0 ),
			! empty( $schedule['on_track'] ) ? ', on track' : ( ( isset( $schedule['overdue_by_hours'] ) && $schedule['overdue_by_hours'] > 0 ) ? sprintf( ', overdue %dh', $schedule['overdue_by_hours'] ) : '' ),
			count( $summary['storage_providers'] )
		);
		return Envelope::success( $text, $summary );
	}

	private static function latest_backup( $row ) {
		$none = [ 'age_days' => null, 'metadata' => null ];
		if ( ! is_object( $row ) || empty( $row->created_at ) ) {
			return $none;
		}
		$created_ts = self::site_local_to_ts( (string) $row->created_at );
		if ( false === $created_ts ) {
			return $none;
		}
		$has_cloud = isset( $row->cloud_synced );
		return [
			'age_days' => (int) floor( max( 0, time() - $created_ts ) / DAY_IN_SECONDS ),
			'metadata' => [
				'created_at'       => gmdate( 'c', $created_ts ),
				'backup_type'      => isset( $row->backup_type ) ? (string) $row->backup_type : null,
				'size_bytes'       => isset( $row->backup_size ) ? (int) $row->backup_size : null,
				'cloud_synced'     => $has_cloud ? 'synced' === SiteVault::cloud_sync_state( $row ) : null,
				'cloud_sync_state' => $has_cloud ? SiteVault::cloud_sync_state( $row ) : null,
				'encrypted'        => self::is_encrypted( $row ),
			],
		];
	}

	/** Most recent failed backup: when and why. */
	private static function latest_failed_backup() {
		global $wpdb;
		$table = $wpdb->prefix . ( self::is_pro() ? 'rb_backups' : 'sitevault_backups' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is prefix + literal
		$row = $wpdb->get_row( "SELECT id, backup_type, created_at, completed_at, metadata FROM {$table} WHERE status = 'failed' ORDER BY created_at DESC, id DESC LIMIT 1" );
		if ( ! $row ) {
			return null;
		}
		$meta = json_decode( (string) $row->metadata, true );
		return [
			'id'          => (int) $row->id,
			'backup_type' => (string) $row->backup_type,
			'started_at'  => self::site_local_to_iso( $row->created_at ),
			// completed_at is when the backup ended; empty when SiteVault did not record it.
			'failed_at'   => ! empty( $row->completed_at ) ? self::site_local_to_iso( $row->completed_at ) : null,
			// SiteVault's error text can name server paths, hosts and accounts.
			'error'       => ( is_array( $meta ) && isset( $meta['error'] ) ) ? Location_Filter::clean( $meta['error'], 300 ) : null,
		];
	}

	/** Per-schedule health from SiteVault's own scheduler check, where the plugin has one. */
	private static function schedule_health() {
		$scheduler = self::obj( 'Backup_Scheduler' );
		if ( ! $scheduler || ! is_callable( [ $scheduler, 'get_schedule_health' ] ) ) {
			return [];
		}
		$out = [];
		foreach ( (array) $scheduler->get_schedule_health() as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$out[] = [
				'id'               => (int) ( $s['id'] ?? 0 ),
				'name'             => (string) Location_Filter::clean( $s['name'] ?? '', 120 ),
				'backup_type'      => (string) ( $s['type'] ?? '' ),
				'frequency'        => (string) ( $s['frequency'] ?? '' ),
				'last_run_at'      => self::site_local_to_iso( $s['last_run'] ?? '' ),
				'last_run_status'  => (string) ( $s['last_run_status'] ?? '' ),
				'next_run_at'      => self::site_local_to_iso( $s['next_run'] ?? '' ),
				'next_run_is_past' => (bool) ( $s['next_run_is_past'] ?? false ),
				'cron_armed'       => (bool) ( $s['cron_armed'] ?? false ),
				'is_orphan'        => (bool) ( $s['is_orphan'] ?? false ),
			];
		}
		return $out;
	}

	private static function schedule() {
		$out       = [ 'active_count' => 0, 'next_run_at' => null, 'on_track' => null, 'overdue_by_hours' => null ];
		$scheduler = self::obj( 'Backup_Scheduler' );
		if ( ! $scheduler || ! is_callable( [ $scheduler, 'get_schedules' ] ) ) {
			return $out;
		}
		$schedules = $scheduler->get_schedules( true );
		if ( ! is_array( $schedules ) || ! $schedules ) {
			return $out;
		}
		$out['active_count'] = count( $schedules );
		$next                = null;
		foreach ( $schedules as $row ) {
			if ( ! is_object( $row ) || empty( $row->next_run ) ) {
				continue;
			}
			$ts = self::site_local_to_ts( (string) $row->next_run );
			if ( false !== $ts && ( null === $next || $ts < $next ) ) {
				$next = $ts;
			}
		}
		if ( null === $next ) {
			return $out;
		}
		$overdue                 = (int) floor( max( 0, time() - $next ) / HOUR_IN_SECONDS );
		$out['next_run_at']      = gmdate( 'c', $next );
		$out['on_track']         = 0 === $overdue;
		$out['overdue_by_hours'] = $overdue;
		return $out;
	}

	/** Cloud destinations and their health; empty when the plugin has none. */
	private static function storage_providers() {
		$tracker = self::cls( 'Sync_Failure_Tracker' );
		if ( null !== $tracker && method_exists( $tracker, 'get_provider_health' ) ) {
			$out = [];
			foreach ( (array) call_user_func( [ $tracker, 'get_provider_health' ] ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$failures = (int) ( $row['consecutive_failures'] ?? 0 );
				$active   = ! empty( $row['is_active'] );
				$out[]    = [
					'type'                 => isset( $row['type'] ) ? (string) $row['type'] : null,
					'connected'            => $active && $failures < 3,
					'name'                 => isset( $row['name'] ) ? Location_Filter::clean( $row['name'], 120 ) : null,
					'is_active'            => $active,
					'is_default'           => ! empty( $row['is_default'] ),
					'consecutive_failures' => $failures,
					'last_failure_at'      => self::site_local_to_iso( (string) ( $row['last_failure_at'] ?? '' ) ),
					'last_failure_message' => isset( $row['last_failure_message'] ) ? Location_Filter::clean( $row['last_failure_message'], 300 ) : null,
					'last_sync'            => self::site_local_to_iso( (string) ( $row['last_sync'] ?? '' ) ),
				];
			}
			return $out;
		}
		$storage = self::obj( 'Storage_Manager' );
		if ( ! $storage || ! is_callable( [ $storage, 'get_providers' ] ) ) {
			return [];
		}
		$out = [];
		foreach ( (array) $storage->get_providers( true ) as $row ) {
			if ( ! is_object( $row ) ) {
				continue;
			}
			$out[] = [
				'type'      => isset( $row->provider_type ) ? (string) $row->provider_type : ( isset( $row->type ) ? (string) $row->type : null ),
				'connected' => isset( $row->is_active ) ? (bool) $row->is_active : ( isset( $row->connected ) ? (bool) $row->connected : null ),
			];
		}
		return $out;
	}

	// ==================== sv_verify_backup ====================

	/** Encrypted archives are verified with a full decrypt, so large ones take a while. */
	public static function handle_verify_backup( array $args ) {
		self::require_cap();
		$backup_id = self::id_arg( $args['backup_id'] ?? null );
		$out       = [ 'backup_id' => $backup_id, 'verified' => false, 'encrypted' => false ];
		if ( ! self::active() ) {
			return self::verify_reply( $out, 'sitevault_inactive', 'SiteVault is not active on this site.' );
		}
		if ( $backup_id < 1 ) {
			return Envelope::error( 'invalid_args', 'backup_id must be a backup id from sv_get_backups.' );
		}
		$manager = self::obj( 'Backup_Manager' );
		$backup  = ( $backup_id > 0 && $manager && is_callable( [ $manager, 'get_backup' ] ) ) ? $manager->get_backup( $backup_id ) : null;
		if ( ! $backup ) {
			return self::verify_reply( $out, 'not_found', 'Backup not found.' );
		}
		$out['encrypted'] = self::is_encrypted( $backup );
		$engine_class     = self::cls( 'Restore_Engine' );
		$engine           = self::obj( 'Restore_Engine' );
		if ( ! $engine || ! is_callable( [ $engine, 'validate_backup' ] ) ) {
			return self::verify_reply( $out, 'unsupported_version', 'This SiteVault version cannot verify backups. Update SiteVault.' );
		}
		// Older SiteVault versions cannot decrypt an archive to check it.
		if ( $out['encrypted'] && ! method_exists( $engine_class, 'validate_archive_file' ) ) {
			return self::verify_reply( $out, 'unsupported_version', 'This SiteVault version cannot verify encrypted backups. Update SiteVault.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not allowed on some hosts
		}
		$result = $engine->validate_backup( $backup_id );
		if ( is_wp_error( $result ) ) {
			return self::verify_reply( $out, (string) $result->get_error_code(), (string) $result->get_error_message() );
		}
		$out['verified'] = true;
		return Envelope::success( sprintf( 'Backup %d is intact%s.', $backup_id, $out['encrypted'] ? ' (decrypted and checked)' : '' ), $out );
	}

	/** A failed check is a result, not a tool error. */
	private static function verify_reply( array $out, $code, $message ) {
		$out['error_code'] = (string) $code;
		$out['message']    = (string) Location_Filter::clean( $message, 300 );
		return Envelope::success( sprintf( 'Backup %d could not be verified: %s', $out['backup_id'], $out['message'] ), $out );
	}

	// ==================== sv_cancel_backup ====================

	public static function handle_cancel_backup( array $args ) {
		self::require_cap();
		if ( ! self::active() ) {
			return Envelope::error( 'sitevault_inactive', 'SiteVault is not active on this site.' );
		}
		$id = self::id_arg( $args['backup_id'] ?? null );
		if ( $id < 1 ) {
			return Envelope::error( 'invalid_args', 'backup_id must be a backup id from sv_get_backups.' );
		}
		// The free SiteVault plugin cannot tell a backup running in the background from a stopped one, so a cancel there is not reliable.
		if ( ! self::is_pro() ) {
			return Envelope::error( 'not_supported', 'Cancelling a backup in progress needs SiteVault Pro. Nothing was changed.' );
		}
		$manager = self::obj( 'Backup_Manager' );
		$engine  = self::cls( 'Backup_Engine' );
		if ( ! $manager || ! method_exists( $manager, 'cancel_backup' ) || null === $engine || ! is_callable( [ $engine, 'is_running' ] ) ) {
			return Envelope::error( 'sitevault_update_required', 'This SiteVault version cannot cancel a backup from here. Update SiteVault. Nothing was changed.' );
		}
		$backup = $manager->get_backup( $id );
		if ( ! $backup ) {
			return Envelope::error( 'not_found', 'Backup not found.', [ 'backup_id' => $id ] );
		}
		if ( 'in_progress' !== (string) $backup->status ) {
			return Envelope::error( 'not_in_progress', 'This backup is not in progress, so there is nothing to cancel.', [ 'backup_id' => $id, 'status' => (string) $backup->status ] );
		}
		if ( call_user_func( [ $engine, 'is_running' ], $id ) ) {
			return Envelope::error( 'backup_running_elsewhere', 'This backup is being made in another process (WP-CLI, the REST API or a staging push) and cannot be cancelled from here. Wait for it to finish.', [ 'backup_id' => $id ] );
		}
		if ( false === $manager->cancel_backup( $id, 'Backup cancelled through Royal MCP.' ) ) {
			return Envelope::error( 'cancel_failed', 'The backup could not be cancelled.', [ 'backup_id' => $id ] );
		}
		$after  = $manager->get_backup( $id );
		$status = $after ? (string) $after->status : 'unknown';
		if ( 'in_progress' === $status ) {
			return Envelope::error( 'cancel_failed', 'The backup could not be cancelled.', [ 'backup_id' => $id ] );
		}
		if ( 'completed' === $status ) {
			return Envelope::error( 'not_in_progress', 'The backup finished before it could be cancelled.', [ 'backup_id' => $id, 'status' => $status ] );
		}
		return Envelope::success(
			sprintf( 'Cancelled backup %d. SiteVault lists a cancelled backup as %s.', $id, $status ),
			[ 'backup_id' => $id, 'cancelled' => true, 'status' => $status, 'undo' => null, 'undo_reason' => 'a_cancelled_backup_cannot_resume' ]
		);
	}

	// ==================== sv_get_restore_status ====================

	public static function handle_get_restore_status( array $args ) {
		self::require_cap();
		$restorer = self::active() ? self::obj( 'Async_Restore' ) : null;
		if ( ! $restorer || ! is_callable( [ $restorer, 'get_restore_status' ] ) ) {
			return Envelope::error( self::active() ? 'not_supported' : 'sitevault_inactive', self::active() ? 'This SiteVault version has no background restores to report on.' : 'SiteVault is not active on this site.' );
		}
		$id = self::id_arg( $args['restore_id'] ?? null );
		if ( $id < 1 ) {
			return Envelope::error( 'invalid_args', 'restore_id is required: the id of the restore, which is the id of the backup being restored.' );
		}
		$s   = (array) $restorer->get_restore_status( $id );
		$iso = static function ( $ts ) {
			return ( is_numeric( $ts ) && (int) $ts > 0 ) ? gmdate( 'c', (int) $ts ) : null;
		};
		$out = [
			'restore_id'  => (string) (int) ( $s['restore_id'] ?? $id ),
			'backup_id'   => (int) ( $s['backup_id'] ?? $id ),
			'status'      => is_scalar( $s['status'] ?? null ) ? substr( sanitize_key( (string) $s['status'] ), 0, 40 ) : 'unknown',
			'stage'       => is_scalar( $s['stage'] ?? null ) ? substr( sanitize_key( (string) $s['stage'] ), 0, 40 ) : '',
			'percent'     => (int) ( $s['percent'] ?? 0 ),
			'message'     => (string) Location_Filter::clean( $s['message'] ?? '', 300 ),
			'started_at'  => $iso( $s['started_at'] ?? 0 ),
			'updated_at'  => $iso( $s['updated_at'] ?? 0 ),
			'finished_at' => $iso( $s['finished_at'] ?? 0 ),
		];
		return Envelope::success( sprintf( 'Restore %s: %s%s.', $out['restore_id'], $out['status'], '' !== $out['stage'] ? ' (' . $out['stage'] . ', ' . $out['percent'] . '%)' : '' ), $out );
	}

	// ==================== Helpers ====================

	/** Unix timestamp for a site-local MySQL datetime, or false. */
	private static function site_local_to_ts( $mysql_datetime ) {
		if ( ! is_scalar( $mysql_datetime ) || '' === (string) $mysql_datetime || 0 === strpos( (string) $mysql_datetime, '0000-00-00' ) ) {
			return false;
		}
		try {
			return ( new \DateTimeImmutable( (string) $mysql_datetime, wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/** ISO 8601 in UTC for a site-local MySQL datetime, or null. Every time in the summary is in UTC. */
	private static function site_local_to_iso( $mysql_datetime ) {
		$ts = self::site_local_to_ts( $mysql_datetime );
		return false === $ts ? null : gmdate( 'c', $ts );
	}

	/** Encrypted when SiteVault flagged it or the archive is an .enc file. */
	private static function is_encrypted( $row ) {
		if ( ! empty( $row->encrypted ) ) {
			return true;
		}
		return '.enc' === strtolower( substr( (string) ( $row->backup_path ?? '' ), -4 ) );
	}

	// ==================== Schemas ====================

	public static function get_tools() {
		$verify_description = 'Check that one SiteVault backup archive is intact before relying on it. Plain ZIP backups are opened and checked; encrypted backups are fully decrypted with the site key and then checked, so large encrypted backups take a while. Nothing is restored and no backup is changed; to check an encrypted backup SiteVault writes a decrypted copy to a temporary file in the backup folder and removes it when the check ends. Returns verified true or false with an error_code: not_found, file_missing, no_zip, no_encryption, insufficient_space, invalid_zip, a decryption error code, unsupported_version on SiteVault versions that cannot check the backup, or cloud_only when the archive is no longer on this server but a copy on a connected cloud destination can still be restored (verified is false, the backup is not broken). Requires manage_options.';
		$verify_schema      = [
			'inputSchema'  => [
				'type'                 => 'object',
				'properties'           => [ 'backup_id' => [ 'type' => 'integer', 'description' => 'Backup ID from sv_get_backups.' ] ],
				'required'             => [ 'backup_id' ],
				'additionalProperties' => false,
			],
			'outputSchema' => [
				'type'       => 'object',
				'properties' => [
					'backup_id'  => [ 'type' => 'integer' ],
					'verified'   => [ 'type' => 'boolean' ],
					'encrypted'  => [ 'type' => 'boolean' ],
					'error_code' => [ 'type' => 'string' ],
					'message'    => [ 'type' => 'string' ],
				],
				'required'   => [ 'backup_id', 'verified', 'encrypted' ],
			],
		];
		return [
			[
				'name'         => 'sv_get_dashboard_summary',
				'description'  => 'SiteVault backup health in one call: latest backup age and details, backup counts (total, completed, failed) and the retention target, the most recent failed backup, scheduler state (next run, on track or overdue, per-schedule health where the plugin reports it) and cloud destination status. Error text and names from SiteVault are shown with paths, hosts, addresses and accounts replaced by [hidden]. Answers { active: false } when SiteVault is not installed or active. Read-only. Requires manage_options.',
				'inputSchema'  => [
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				],
				'outputSchema' => [
					'type'       => 'object',
					'properties' => [
						'active'               => [ 'type' => 'boolean' ],
						'backup_age_days'      => [ 'type' => [ 'integer', 'null' ] ],
						'latest_backup'        => [ 'type' => [ 'object', 'null' ] ],
						'backup_count'         => [ 'type' => [ 'object', 'null' ] ],
						'latest_failed_backup' => [ 'type' => [ 'object', 'null' ] ],
						'schedule'             => [ 'type' => [ 'object', 'null' ] ],
						'storage_providers'    => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
					],
					'required'   => [ 'active' ],
				],
			],
			array_merge( [ 'name' => 'sv_verify_backup', 'description' => $verify_description ], $verify_schema ),
			array_merge( [ 'name' => 'sv_verify_backup_health', 'description' => 'Same check as sv_verify_backup, under the name backup-health monitors use. ' . $verify_description ], $verify_schema ),
			[
				'name'        => 'sv_cancel_backup',
				'description' => 'Stop a SiteVault Pro backup that is in progress (the free SiteVault plugin cannot: not_supported). SiteVault lists a cancelled backup as failed, and the reply gives the status it recorded. Refused: an unknown id (not_found), a backup not in progress (not_in_progress), one being made in another process such as WP-CLI, the REST API or a staging push (backup_running_elsewhere), a SiteVault version that cannot cancel from here (sitevault_update_required). No undo: start a new backup with sv_create_backup. Requires manage_options.',
				'inputSchema' => [
					'type'       => 'object',
					'required'   => [ 'backup_id' ],
					'properties' => [
						'backup_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'SiteVault backup id (sv_get_backups).' ],
					],
				],
			],
			[
				'name'        => 'sv_get_restore_status',
				'description' => 'Where a SiteVault restore running in the background stands: status (running, completed, failed or unknown), stage, percent, message, started_at, updated_at, finished_at. Restores are started in SiteVault itself; this tool only reads their progress. Read-only. Requires manage_options.',
				'inputSchema' => [
					'type'       => 'object',
					'required'   => [ 'restore_id' ],
					'properties' => [
						'restore_id' => [ 'type' => [ 'string', 'integer' ], 'description' => 'The id of the restore: the id of the backup being restored (a string of digits).' ],
					],
				],
			],
		];
	}
}
