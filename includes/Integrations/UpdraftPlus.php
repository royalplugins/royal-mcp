<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UpdraftPlus MCP Integration
 *
 * Registers MCP tools that expose UpdraftPlus's backup surface — enumerate
 * existing backups, read per-backup status/manifest, read the current
 * schedule, and trigger a new additive backup. Restore, retention purge,
 * and remote-storage configuration are intentionally out of scope for the
 * Free tier (destructive class → Pro).
 *
 * Detection uses class_exists('UpdraftPlus') — the plugin's main class,
 * which loads during its bootstrap on every request where the plugin is
 * active. UpdraftPlus does not define a version constant, so the class
 * check is the canonical availability probe.
 *
 * Identifier vocabulary for per-backup tools is `nonce` (string), matching
 * UpdraftPlus's native identifier — the SiteVault sibling uses integer id
 * because SiteVault stores backups in a custom table with an auto-increment
 * primary key; UpdraftPlus keys its backup set by a random string nonce
 * baked into every backup filename and job data row.
 */
class UpdraftPlus {

	/**
	 * UpdraftPlus is present when its main class is loaded. There is no
	 * version constant defined by the plugin, so class_exists is the
	 * canonical probe.
	 */
	public static function is_available() {
		return class_exists( '\\UpdraftPlus' );
	}

	public static function get_tools() {
		// Always register so tools appear in MCP tools/list regardless of
		// UpdraftPlus activation state. Callers see the schemas immediately on
		// connect; execute_tool cleanly refuses with "UpdraftPlus is not active"
		// when the underlying plugin isn't loaded. Prevents the ghost-tools UX
		// where activating a plugin post-MCP-connection requires the client to
		// reconnect before the tools become discoverable.
		return [
			[
				'name'        => 'updraftplus_list_backups',
				'description' => 'List UpdraftPlus backups from local history. Returns nonce (string identifier), timestamp, service (destination), and per-entity presence flags (db, plugins, themes, uploads, others). Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'  => [ 'type' => 'integer', 'description' => 'Max backups to return. Default 50, max 100.' ],
						'offset' => [ 'type' => 'integer', 'description' => 'Pagination offset. Default 0.' ],
					],
				],
			],
			[
				'name'        => 'updraftplus_get_backup_status',
				'description' => 'Read the progress/state of a specific backup by nonce. For in-progress backups returns jobstatus (backup/uploading/clouduploading/encrypted/finished). For completed backups returns the manifest. Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'nonce' => [ 'type' => 'string', 'description' => 'Backup nonce identifier from updraftplus_list_backups.' ],
					],
					'required'   => [ 'nonce' ],
				],
			],
			[
				'name'        => 'updraftplus_trigger_backup',
				'description' => 'Trigger a new UpdraftPlus backup asynchronously via WP-Cron dispatch. Returns immediately (under 1 second) with the pre-generated nonce so callers can poll updraftplus_get_backup_status. Additive — creates a new backup, never deletes prior backups. entities filters which components are included via restrict_files_to_override: entities:["plugins"] produces plugins.zip only, entities:["db"] produces db.gz only, entities:["plugins","themes"] produces both zips without db. Default (empty entities) backs up everything (db + all file entities).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'entities' => [
							'type'        => 'array',
							'description' => 'Which entities to include. Default: all files + db.',
							'items'       => [
								'type' => 'string',
								'enum' => [ 'db', 'plugins', 'themes', 'uploads', 'others', 'mu-plugins' ],
							],
						],
						'label'    => [ 'type' => 'string', 'description' => 'Optional label for the backup.' ],
					],
				],
			],
			[
				'name'        => 'updraftplus_get_schedule',
				'description' => 'Read the current UpdraftPlus backup schedule (files interval, database interval, retention counts, next scheduled run). Read-only.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
			],
		];
	}

	/**
	 * Coarse permission cap check fires BEFORE the availability check so a
	 * Subscriber-tier OAuth Bearer receives an identical permission error
	 * whether UpdraftPlus is installed or not — prevents error-message
	 * probing for integration presence. Backups can contain the entire
	 * site (DB + uploads + plugins) so even read-only listings require
	 * admin-tier capability.
	 */
	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use UpdraftPlus tools.' );
		}

		if ( ! self::is_available() ) {
			throw new \Exception( 'UpdraftPlus is not active' );
		}

		switch ( $name ) {
			case 'updraftplus_list_backups':
				return self::handle_list_backups( $args );

			case 'updraftplus_get_backup_status':
				return self::handle_get_backup_status( $args );

			case 'updraftplus_trigger_backup':
				return self::handle_trigger_backup( $args );

			case 'updraftplus_get_schedule':
				return self::handle_get_schedule( $args );

			default:
				$suffix = strpos( $name, 'updraftplus_' ) === 0
					? substr( $name, strlen( 'updraftplus_' ) )
					: $name;
				throw new \Exception( 'Unknown UpdraftPlus tool: ' . esc_html( (string) $suffix ) );
		}
	}

	// ==================== Handlers ====================

	/**
	 * List backups from local UpdraftPlus history with pagination.
	 *
	 * Reads the timestamp-keyed history array (already reverse-chronological
	 * per get_history's krsort), converts to an indexed array, applies
	 * offset/limit slicing, and normalizes each entry to a flat shape that
	 * mirrors SiteVault's sv_get_backups for cross-plugin skill authoring.
	 *
	 * Entity fields (db/plugins/themes/uploads/others/mu-plugins) are cast
	 * to boolean presence flags — the native history stores them as arrays
	 * of filenames when present or omits the key when absent. The boolean
	 * coercion collapses "either shape" into a single skill-friendly
	 * contract without leaking archive filenames the caller cannot use.
	 */
	private static function handle_list_backups( $args ) {
		$limit  = isset( $args['limit'] ) ? intval( $args['limit'] ) : 50;
		if ( $limit < 1 ) {
			$limit = 50;
		}
		if ( $limit > 100 ) {
			$limit = 100;
		}
		$offset = isset( $args['offset'] ) ? intval( $args['offset'] ) : 0;
		if ( $offset < 0 ) {
			$offset = 0;
		}

		$history = [];
		try {
			if ( class_exists( '\\UpdraftPlus_Backup_History' ) ) {
				$history = \UpdraftPlus_Backup_History::get_history();
			}
		} catch ( \Throwable $e ) {
			// Defensive fallback: read the raw option directly if the
			// class-level accessor throws (e.g. corrupted incremental-set
			// metadata). Preserves reverse-chronological ordering.
			$history = (array) get_option( 'updraft_backup_history', [] );
			krsort( $history );
		}

		if ( ! is_array( $history ) ) {
			$history = [];
		}

		$entries = [];
		foreach ( $history as $timestamp => $backup ) {
			if ( ! is_array( $backup ) ) {
				continue;
			}
			$entries[] = self::format_backup_entry( (int) $timestamp, $backup );
		}

		$total  = count( $entries );
		$sliced = array_slice( $entries, $offset, $limit );

		return [
			'backups' => array_values( $sliced ),
			'count'   => count( $sliced ),
			'total'   => $total,
			'offset'  => $offset,
			'limit'   => $limit,
		];
	}

	/**
	 * Read the state of a specific backup by nonce.
	 *
	 * ensure_backup_exists throws if the nonce is not present in the
	 * history — real backups always create their history entry before the
	 * jobdata option starts writing, so the in-progress branch only fires
	 * when BOTH the history entry and a non-finished jobdata option exist.
	 * A jobdata option without a history entry means the backup either
	 * hasn't started registering yet or the history entry was manually
	 * removed; either way, "backup not found" is the right response.
	 */
	private static function handle_get_backup_status( $args ) {
		$nonce  = self::resolve_nonce( $args );
		$backup = self::ensure_backup_exists( $nonce );

		$jobdata = get_option( 'updraft_jobdata_' . $nonce );
		if ( is_array( $jobdata ) && isset( $jobdata['jobstatus'] ) && $jobdata['jobstatus'] !== 'finished' ) {
			$updated_at = 0;
			if ( isset( $jobdata['job_time_ms'] ) ) {
				// UpdraftPlus stores job_time_ms as microtime(true) — cast to
				// int seconds to match the return-shape invariant.
				$updated_at = (int) $jobdata['job_time_ms'];
			} elseif ( isset( $jobdata['backup_time'] ) ) {
				$updated_at = (int) $jobdata['backup_time'];
			}
			return [
				'nonce'      => $nonce,
				'state'      => 'in_progress',
				'jobstatus'  => (string) $jobdata['jobstatus'],
				'updated_at' => $updated_at,
			];
		}

		$formatted = self::format_backup_entry(
			isset( $backup['timestamp'] ) ? (int) $backup['timestamp'] : 0,
			$backup
		);

		return [
			'nonce'              => $formatted['nonce'],
			'state'              => 'completed',
			'timestamp'          => $formatted['timestamp'],
			'entities'           => $formatted['entities'],
			'service'            => $formatted['service'],
			'label'              => $formatted['label'],
			'created_by_version' => $formatted['created_by_version'],
		];
	}

	/**
	 * Trigger an async backup via UpdraftPlus's backupnow dispatch (mirrors admin.php request_backupnow).
	 *
	 * MUST use do_action with pre-generated nonce + restrict_files_to_override rather than boot_backup()
	 * (boot_backup is synchronous — inline zip + db dump + upload exceeds MCP transport timeouts).
	 *
	 * Event dispatch:
	 *   files + db    → updraft_backupnow_backup_all
	 *   files, no db  → updraft_backupnow_backup
	 *   db, no files  → updraft_backupnow_backup_database
	 */
	private static function handle_trigger_backup( $args ) {
		if ( ! isset( $GLOBALS['updraftplus'] ) || ! ( $GLOBALS['updraftplus'] instanceof \UpdraftPlus ) ) {
			throw new \Exception( 'UpdraftPlus global not available.' );
		}
		$updraftplus = $GLOBALS['updraftplus'];

		$label = sanitize_text_field( (string) ( $args['label'] ?? '' ) );

		$req_entities  = (array) ( $args['entities'] ?? [] );
		$allowed       = [ 'db', 'plugins', 'themes', 'uploads', 'others', 'mu-plugins' ];
		$file_entities = [ 'plugins', 'themes', 'uploads', 'others', 'mu-plugins' ];

		// Distinguish empty input (default = all entities) from invalid-only input (throw).
		if ( empty( $req_entities ) ) {
			$entities = $allowed;
		} else {
			$entities = array_values( array_intersect( $req_entities, $allowed ) );
		}

		$requested_file_entities = array_values( array_intersect( $entities, $file_entities ) );
		$backup_files            = ! empty( $requested_file_entities );
		$backup_database         = in_array( 'db', $entities, true );

		if ( ! $backup_files && ! $backup_database ) {
			throw new \Exception( 'entities must include at least one of db or a file entity.' );
		}

		// Defensive pre-flight — skip if the method isn't present on the
		// installed UpdraftPlus build so a version drift doesn't fatal us.
		if ( method_exists( $updraftplus, 'is_backup_running' ) && $updraftplus->is_backup_running() ) {
			throw new \Exception( 'A backup is already in progress.' );
		}

		if ( ! method_exists( $updraftplus, 'backup_time_nonce' ) ) {
			throw new \Exception( 'UpdraftPlus build too old — backup_time_nonce() missing (async dispatch not supported).' );
		}

		$nonce = (string) $updraftplus->backup_time_nonce();
		if ( $nonce === '' ) {
			throw new \Exception( 'Backup nonce could not be generated (internal error).' );
		}

		// Options array mirrors admin.php request_backupnow.
		$options = [
			'nocloud'   => false,
			'use_nonce' => $nonce,
		];
		if ( $label !== '' ) {
			$options['label'] = $label;
		}

		// restrict_files_to_override is what UpdraftPlus reads to filter
		// file entities. Populate ONLY when the caller requested a strict
		// subset of file entities — passing all 5 file entities is
		// functionally equivalent to not passing the override and Yoast
		// doesn't need the extra work.
		$all_file_entities_requested = count( array_intersect( $file_entities, $entities ) ) === count( $file_entities );
		if ( $backup_files && ! $all_file_entities_requested ) {
			$options['restrict_files_to_override'] = $requested_file_entities;
		}

		// Event dispatch pattern from admin.php:2689.
		if ( $backup_files && $backup_database ) {
			$event = 'updraft_backupnow_backup_all';
		} elseif ( $backup_files ) {
			$event = 'updraft_backupnow_backup';
		} else {
			$event = 'updraft_backupnow_backup_database';
		}

		// Filter mirrors the admin dispatch — hosts can inject extra
		// options (extradata, remote_storage_instances, etc.).
		$options = apply_filters( 'updraft_backupnow_options', $options, [] );

		do_action( $event, $options );

		$service = get_option( 'updraft_service', 'local' );
		if ( is_array( $service ) ) {
			$service = implode( ',', array_map( 'strval', $service ) );
		}
		$service = (string) $service;

		$summary = sprintf(
			'UpdraftPlus backup dispatched asynchronously. Nonce: %s. Use updraftplus_get_backup_status to monitor progress.',
			$nonce
		);

		return Envelope::success(
			$summary,
			[
				'nonce'      => $nonce,
				'service'    => $service,
				'entities'   => $entities,
				'event'      => $event,
				'started_at' => time(),
				'poll_hint'  => 'updraftplus_get_backup_status',
				'label'      => $label,
			],
			null
		);
	}

	/**
	 * Read the current backup schedule — files interval + database interval
	 * with their next scheduled cron run and retention counts.
	 *
	 * UpdraftPlus stores interval/retention as separate options for files
	 * vs database. When an option is unset get_option returns false, which
	 * we normalize to the plugin's own defaults: 'manual' interval, null
	 * next_run_at (no cron scheduled), retention_count 2.
	 */
	private static function handle_get_schedule( $args ) {
		$files_interval = get_option( 'updraft_interval' );
		$db_interval    = get_option( 'updraft_interval_database' );
		$files_retain   = get_option( 'updraft_retain' );
		$db_retain      = get_option( 'updraft_retain_db' );

		$files_next = wp_next_scheduled( 'updraft_backup' );
		$db_next    = wp_next_scheduled( 'updraft_backup_database' );

		return [
			'files'    => [
				'interval'        => ( is_string( $files_interval ) && $files_interval !== '' ) ? $files_interval : 'manual',
				'next_run_at'     => $files_next ? (int) $files_next : null,
				'retention_count' => ( false === $files_retain ) ? 2 : (int) $files_retain,
			],
			'database' => [
				'interval'        => ( is_string( $db_interval ) && $db_interval !== '' ) ? $db_interval : 'manual',
				'next_run_at'     => $db_next ? (int) $db_next : null,
				'retention_count' => ( false === $db_retain ) ? 2 : (int) $db_retain,
			],
		];
	}

	/**
	 * Normalize a single history entry to the shape documented in the tool
	 * schema. Entity fields are boolean presence flags — the underlying
	 * history stores arrays of filenames per entity when included or omits
	 * the key when absent, so a boolean coercion is the cleanest cross-tier
	 * contract. Service can be either a string or an array of storage
	 * destinations in the native format; we surface it as-is.
	 */
	private static function format_backup_entry( $timestamp, $backup ) {
		$entities = [];
		foreach ( [ 'db', 'plugins', 'themes', 'uploads', 'others', 'mu-plugins' ] as $entity ) {
			$entities[ $entity ] = ! empty( $backup[ $entity ] );
		}

		$service = isset( $backup['service'] ) ? $backup['service'] : '';
		if ( is_array( $service ) ) {
			$service = implode( ',', array_map( 'strval', $service ) );
		}

		return [
			'nonce'              => isset( $backup['nonce'] ) ? (string) $backup['nonce'] : '',
			'timestamp'          => (int) $timestamp,
			'service'            => (string) $service,
			'entities'           => $entities,
			'label'              => isset( $backup['label'] ) ? (string) $backup['label'] : '',
			'created_by_version' => isset( $backup['created_by_version'] ) ? (string) $backup['created_by_version'] : '',
			'is_multisite'       => ! empty( $backup['is_multisite'] ),
		];
	}

	// ==================== Helpers ====================

	/**
	 * Resolve the `nonce` arg to a non-empty string. Missing/empty raises
	 * so handlers can trust the returned string. Mirrors the YoastSEO
	 * resolve_post_id helper shape adapted for string identifier.
	 */
	private static function resolve_nonce( $args ) {
		$raw = isset( $args['nonce'] ) ? (string) $args['nonce'] : '';
		$nonce = sanitize_text_field( $raw );
		if ( $nonce === '' ) {
			throw new \Exception( 'nonce is required.' );
		}
		return $nonce;
	}

	/**
	 * Confirm a backup set exists for the given nonce and return it.
	 * Handlers reuse the returned array so a second lookup is not needed.
	 * Throws when the nonce does not resolve to a known backup set.
	 */
	private static function ensure_backup_exists( $nonce ) {
		if ( ! class_exists( '\\UpdraftPlus_Backup_History' ) ) {
			throw new \Exception( 'UpdraftPlus backup history is not available.' );
		}
		$backup = \UpdraftPlus_Backup_History::get_backup_set_by_nonce( $nonce );
		if ( ! is_array( $backup ) || empty( $backup ) ) {
			throw new \Exception( 'Backup not found: ' . esc_html( $nonce ) );
		}
		return $backup;
	}
}

/**
 * Manifest declaration for host discovery (any consumer of the
 * royal_mcp_manifests filter).
 *
 * Registered unconditionally; the UpdraftPlus class guard runs inside the
 * callback so plugin load order does not affect the filter registration.
 * The plugin does not define a version constant, so we fall back to
 * 'unknown' when UPDRAFTPLUS_VERSION is absent — the class presence is
 * the canonical availability probe.
 *
 * supports_undo is false because the only write tool in this integration
 * (updraftplus_trigger_backup) is purely additive — a new backup set is
 * created and prior backup sets are never mutated, so there is nothing
 * to reverse.
 */
add_filter( 'royal_mcp_manifests', function ( $manifests ) {
	if ( ! class_exists( '\\UpdraftPlus' ) ) {
		return $manifests;
	}
	$manifests[] = [
		'royal_mcp_manifest_version' => '1.0',
		'plugin_slug'                => 'updraftplus',
		'plugin_display_name'        => 'UpdraftPlus',
		'plugin_version'             => defined( 'UPDRAFTPLUS_VERSION' ) ? UPDRAFTPLUS_VERSION : 'unknown',
		'vendor_name'                => 'UpdraftPlus.Com',
		'mcp_endpoint'               => rest_url( 'royal-mcp/v1/mcp' ),
		'auth_methods'               => [ 'oauth2.1' ],
		'capabilities'               => [ 'read', 'reversible-write' ],
		'manifest_updated_at'        => gmdate( 'c' ),
		'trust_signals'              => [
			'supports_dry_run'                 => false,
			'supports_undo'                    => false,
			'supports_snapshots'               => false,
			'requires_review_for_destructive'  => true,
		],
	];
	return $manifests;
} );
