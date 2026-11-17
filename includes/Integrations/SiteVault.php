<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SiteVault MCP Integration
 *
 * Registers MCP tools for SiteVault backup management. Detects both
 * SiteVault Free (SiteVault_ prefix) and SiteVault Pro (RB_ prefix) —
 * the public API surface is identical across tiers, so tool behavior is
 * consistent whichever plugin is installed.
 */
class SiteVault {

	/**
	 * Resolve the loaded Backup_Manager class name. Prefers Pro's RB_ prefix
	 * when both are present (Pro overrides Free on the same install).
	 */
	private static function manager_class() {
		if ( class_exists( '\\RB_Backup_Manager' ) ) return '\\RB_Backup_Manager';
		if ( class_exists( '\\SiteVault_Backup_Manager' ) ) return '\\SiteVault_Backup_Manager';
		return null;
	}

	private static function async_backup_class() {
		if ( class_exists( '\\RB_Async_Backup' ) ) return '\\RB_Async_Backup';
		if ( class_exists( '\\SiteVault_Async_Backup' ) ) return '\\SiteVault_Async_Backup';
		return null;
	}

	/** Whether the installed SiteVault can tell a backup that is still being made from one whose job stopped. */
	private static function can_tell_stopped( $async_class ) {
		return null !== $async_class && ( method_exists( $async_class, 'is_backup_running' ) || method_exists( $async_class, 'is_job_alive' ) );
	}

	/** Whether any backup listed as in progress is still being made, by SiteVault's own check of each one. */
	private static function any_backup_alive( $async_class ) {
		global $wpdb;
		$paid  = '\\RB_Async_Backup' === $async_class;
		$table = $wpdb->prefix . ( $paid ? 'rb_backups' : 'sitevault_backups' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is one of two fixed names
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'in_progress'" );
		try {
			$async = $async_class::instance();
		} catch ( \Throwable $e ) {
			// Not knowing counts as still being made.
			return true;
		}
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || empty( $row->id ) ) {
				continue;
			}
			try {
				if ( method_exists( $async, 'is_backup_running' ) ) {
					$alive = (bool) $async->is_backup_running( $row );
				} else {
					$engine = self::engine_class();
					$alive  = (bool) $async->is_job_alive( (int) $row->id ) || ( null !== $engine && is_callable( [ $engine, 'is_running' ] ) && true === $engine::is_running( (int) $row->id ) );
				}
			} catch ( \Throwable $e ) {
				// Not knowing counts as still being made.
				$alive = true;
			}
			if ( $alive ) {
				return true;
			}
		}
		return false;
	}

	private static function scheduler_class() {
		if ( class_exists( '\\RB_Backup_Scheduler' ) ) return '\\RB_Backup_Scheduler';
		if ( class_exists( '\\SiteVault_Backup_Scheduler' ) ) return '\\SiteVault_Backup_Scheduler';
		return null;
	}

	private static function engine_class() {
		if ( class_exists( '\\RB_Backup_Engine' ) ) return '\\RB_Backup_Engine';
		if ( class_exists( '\\SiteVault_Backup_Engine' ) ) return '\\SiteVault_Backup_Engine';
		return null;
	}

	public static function is_available() {
		return self::manager_class() !== null;
	}

	public static function get_tools() {
		// Always register so tools appear in MCP tools/list regardless of the
		// underlying plugin activation state. execute_tool gates at call time
		// with a clean 'not active' throw. Prevents ghost-tools UX where
		// activating a plugin post-MCP-connection requires the client to
		// reconnect before the tools become discoverable.

		return array_merge( [
			[
				'name'        => 'sv_get_backups',
				'description' => 'List SiteVault backups with ID, name, type, status, size, cloud sync state and created and completed times. Filter by status or type; limit defaults to 20 (max 50). Read-only. Requires manage_options (manage_network_options on multisite).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'  => [ 'type' => 'integer', 'description' => 'Number of backups to return (max 50)' ],
						'status' => [ 'type' => 'string', 'description' => 'Filter by status', 'enum' => [ 'completed', 'failed', 'in_progress' ] ],
						'type'   => [ 'type' => 'string', 'description' => 'Filter by backup type', 'enum' => [ 'full', 'database', 'files', 'plugins', 'themes', 'uploads' ] ],
					],
				],
			],
			[
				'name'        => 'sv_get_backup',
				'description' => 'Get details of a specific backup by ID',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'id' => [ 'type' => 'integer', 'description' => 'Backup ID' ],
					],
					'required'   => [ 'id' ],
				],
			],
			[
				'name'        => 'sv_create_backup',
				'description' => 'Trigger a new backup (runs asynchronously). With type plugins or themes, plugins or themes can name the folders to back up instead of all of them.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'type'    => [ 'type' => 'string', 'description' => 'Backup type', 'enum' => [ 'full', 'database', 'files', 'plugins', 'themes', 'uploads' ] ],
						'name'    => [ 'type' => 'string', 'description' => 'Backup name (optional)' ],
						'plugins' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'With type plugins: only these folders in the plugins directory, for example ["woocommerce"].' ],
						'themes'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'With type themes: only these folders in the themes directory.' ],
					],
				],
			],
			[
				'name'        => 'sv_get_backup_status',
				'description' => 'Check the progress of an in-progress backup',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'id' => [ 'type' => 'integer', 'description' => 'Backup ID' ],
					],
					'required'   => [ 'id' ],
				],
			],
			[
				'name'        => 'sv_get_backup_stats',
				'description' => 'Get overall backup statistics (total count, size, last backup)',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
			[
				'name'        => 'sv_get_schedules',
				'description' => 'List SiteVault backup schedules: ID, name, backup type, frequency, whether it is active, and the last and next run times. Takes no inputs. Read-only. Requires manage_options (manage_network_options on multisite).',
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			],
		], SiteVaultHealth::get_tools() );
	}

	public static function execute_tool( $name, $args ) {
		// The health tools check the permission themselves and answer for a site without SiteVault.
		if ( SiteVaultHealth::handles( $name ) ) {
			return SiteVaultHealth::execute_tool( $name, $args );
		}

		// cap check fires BEFORE the active-check, so a Subscriber-tier
		// OAuth Bearer gets a permission error rather than "SiteVault is not
		// active" (which leaks integration presence). All SiteVault tools are
		// admin-tier — backups can contain the entire site (DB + uploads +
		// plugins) and even read-only listings expose backup contents,
		// schedules, and storage destinations.
		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use SiteVault tools.' );
		}

		if ( ! self::is_available() ) {
			throw new \Exception( 'SiteVault is not active' );
		}

		$manager_class = self::manager_class();
		$manager       = $manager_class::instance();

		switch ( $name ) {
			case 'sv_get_backups':
				$query_args = [
					'limit' => max(1, min( intval( $args['limit'] ?? 20 ), 50 )),
				];
				if ( ! empty( $args['status'] ) ) {
					$query_args['status'] = sanitize_text_field( $args['status'] );
				}
				if ( ! empty( $args['type'] ) ) {
					$query_args['type'] = sanitize_text_field( $args['type'] );
				}
				$backups = $manager->get_backups( $query_args );
				return array_map( [ __CLASS__, 'format_backup' ], $backups );

			case 'sv_get_backup':
				$backup = $manager->get_backup( intval( $args['id'] ) );
				if ( ! $backup ) {
					throw new \Exception( 'Backup not found' );
				}
				return self::format_backup( $backup );

			case 'sv_create_backup':
				$backup_args = [
					'type' => in_array( $args['type'] ?? 'full', [ 'full', 'database', 'files', 'plugins', 'themes', 'uploads' ] ) ? $args['type'] : 'full',
				];
				if ( ! empty( $args['name'] ) ) {
					$backup_args['name'] = sanitize_text_field( $args['name'] );
				}

				$async_class = self::async_backup_class();
				foreach ( [ 'plugins', 'themes' ] as $folder_key ) {
					if ( ! array_key_exists( $folder_key, $args ) || [] === $args[ $folder_key ] ) {
						continue;
					}
					if ( ! is_array( $args[ $folder_key ] ) || array_filter( $args[ $folder_key ], static function ( $folder ) { return ! is_string( $folder ); } ) ) {
						throw new \Royal_MCP\MCP\Support\Tool_Refusal( 'invalid_args', esc_html( $folder_key ) . ' must be a list of folder names.' );
					}
					// A version without named folders would back up the whole component and say nothing.
					if ( null === $async_class || ! method_exists( $async_class, 'named_folders' ) ) {
						throw new \Royal_MCP\MCP\Support\Tool_Refusal( 'not_supported', 'This version of SiteVault cannot back up named folders. Update SiteVault Pro, or back up the whole component.' );
					}
					$backup_args[ $folder_key ] = array_values( $args[ $folder_key ] );
				}

				// A backup that is still being made is never started over. One whose job
				// stopped is cleared by SiteVault's own start. A SiteVault that cannot tell
				// the two apart keeps every backup in progress as a reason to wait.
				if ( $manager->has_active_backup() && ( ! self::can_tell_stopped( $async_class ) || self::any_backup_alive( $async_class ) ) ) {
					throw new \Royal_MCP\MCP\Support\Tool_Refusal( 'backup_running', 'A backup is already in progress. Please wait for it to complete.' );
				}

				// Use async backup to avoid timeout
				if ( $async_class !== null ) {
					$backup_id = $async_class::instance()->start_backup( $backup_args );
				} else {
					$engine_class = self::engine_class();
					if ( $engine_class === null ) {
						throw new \Exception( 'SiteVault backup engine not available' );
					}
					$backup_id = $engine_class::instance()->create_backup( $backup_args );
				}

				if ( is_wp_error( $backup_id ) ) {
					throw new \Royal_MCP\MCP\Support\Tool_Refusal( sanitize_key( (string) $backup_id->get_error_code() ) ?: 'backup_not_started', esc_html( $backup_id->get_error_message() ) );
				}

				return [
					'id'      => $backup_id,
					'message' => 'Backup started successfully. Use sv_get_backup_status to check progress.',
					'type'    => $backup_args['type'],
				];

			case 'sv_get_backup_status':
				$backup_id = intval( $args['id'] );
				$backup    = $manager->get_backup( $backup_id );
				if ( ! $backup ) {
					throw new \Exception( 'Backup not found' );
				}

				$result = [
					'id'     => $backup_id,
					'status' => $backup->status,
					'type'   => $backup->backup_type,
				];

				// If in progress, get detailed progress
				$async_class = self::async_backup_class();
				if ( $backup->status === 'in_progress' && $async_class !== null ) {
					$progress = $async_class::instance()->get_status( $backup_id );
					if ( is_array( $progress ) ) {
						$result['percent'] = $progress['percent'] ?? 0;
						$result['step']    = $progress['step'] ?? '';
						$result['message'] = $progress['message'] ?? '';
					}
				} elseif ( $backup->status === 'completed' ) {
					$result['size']         = $backup->backup_size;
					$result['size_human']   = size_format( $backup->backup_size );
					$result['completed_at'] = $backup->completed_at;
				} elseif ( $backup->status === 'failed' ) {
					$meta = json_decode( $backup->metadata ?? '{}', true );
					$result['error'] = $meta['error'] ?? 'Unknown error';
				}

				return $result;

			case 'sv_get_backup_stats':
				if ( ! method_exists( $manager, 'get_stats' ) ) {
					throw new \Exception( 'Backup stats not available in this version' );
				}
				$stats = $manager->get_stats();
				if ( is_array( $stats ) && ! empty( $stats['last_backup'] ) && is_object( $stats['last_backup'] ) ) {
					$stats['last_backup'] = self::format_backup( $stats['last_backup'] );
				}
				return $stats;

			case 'sv_get_schedules':
				$scheduler_class = self::scheduler_class();
				if ( $scheduler_class === null ) {
					throw new \Exception( 'Backup scheduler not available' );
				}
				$schedules = $scheduler_class::instance()->get_schedules();
				return array_map( function( $s ) {
					return [
						'id'         => $s->id,
						'name'       => $s->schedule_name,
						'type'       => $s->backup_type,
						'frequency'  => $s->frequency,
						'is_active'  => (bool) $s->is_active,
						'last_run'   => $s->last_run,
						'next_run'   => $s->next_run,
					];
				}, $schedules );

			default:
				throw new \Exception( 'Unknown SiteVault tool: ' . esc_html( $name ) );
		}
	}

	/**
	 * Cloud copy state of a backup: synced | pending | local_missing (the local
	 * file was gone when the upload was attempted).
	 */
	public static function cloud_sync_state( $backup ) {
		if ( class_exists( 'RB_Backup_Manager' ) && method_exists( 'RB_Backup_Manager', 'get_cloud_sync_state' ) ) {
			return (string) \RB_Backup_Manager::get_cloud_sync_state( $backup );
		}
		$value = (int) ( is_object( $backup ) ? ( $backup->cloud_synced ?? 0 ) : $backup );
		return 1 === $value ? 'synced' : ( -1 === $value ? 'local_missing' : 'pending' );
	}

	private static function format_backup( $backup ) {
		return [
			'id'           => (int) $backup->id,
			// The random suffix is what keeps the archive's address from being guessed.
			'name'         => preg_replace( '/-[a-f0-9]{32}$/', '', (string) $backup->backup_name ),
			'type'         => $backup->backup_type,
			'status'       => $backup->status,
			'size'         => (int) $backup->backup_size,
			'size_human'   => size_format( $backup->backup_size ),
			'cloud_synced' => 'synced' === self::cloud_sync_state( $backup ),
			'cloud_sync_state' => self::cloud_sync_state( $backup ),
			'created_at'   => $backup->created_at,
			'completed_at' => $backup->completed_at,
		];
	}
}
