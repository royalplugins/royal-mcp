<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Duplicator MCP Integration
 *
 * Read-only in the Free tier: list packages, read package status, get
 * installer URL for completed packages. Programmatic package creation
 * belongs in Pro — Duplicator's build pipeline is a multi-stage async
 * flow (scan → validate → db build → archive build → installer build →
 * complete) that needs orchestration + cleanup guarantees this tier does
 * not ship.
 */
class Duplicator {

	public static function is_available() {
		return defined( 'DUPLICATOR_VERSION' ) && class_exists( '\\DUP_Package' );
	}

	public static function get_tools() {
		return [
			[
				'name'        => 'duplicator_list_packages',
				'description' => 'List Duplicator packages (migration bundles). Returns package id, name, type, status, version, and creation timestamp. Supports pagination via limit + offset.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'  => [ 'type' => 'integer', 'description' => 'Max packages to return (default 50, max 200).' ],
						'offset' => [ 'type' => 'integer', 'description' => 'Row offset for pagination (default 0).' ],
					],
				],
			],
			[
				'name'        => 'duplicator_get_package_status',
				'description' => 'Get status detail for a single Duplicator package by ID — includes progress state (created, running, complete, error), name, type, size, and archive/installer availability.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'package_id' => [ 'type' => 'integer', 'description' => 'Duplicator package ID (from duplicator_list_packages).' ],
					],
					'required'   => [ 'package_id' ],
				],
			],
			[
				'name'        => 'duplicator_get_installer',
				'description' => 'Get the installer filename + download URL for a completed Duplicator package. Returns {state: unavailable, reason: not_complete} when the package has not finished building.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'package_id' => [ 'type' => 'integer', 'description' => 'Duplicator package ID.' ],
					],
					'required'   => [ 'package_id' ],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use Duplicator tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Duplicator is not active' );
		}

		switch ( $name ) {
			case 'duplicator_list_packages':
				return self::handle_list_packages( $args );
			case 'duplicator_get_package_status':
				return self::handle_get_package_status( $args );
			case 'duplicator_get_installer':
				return self::handle_get_installer( $args );
			default:
				throw new \Exception( 'Unknown Duplicator tool: ' . esc_html( $name ) );
		}
	}

	private static function handle_list_packages( $args ) {
		$limit  = min( max( intval( $args['limit']  ?? 50 ), 1 ), 200 );
		$offset = max( intval( $args['offset'] ?? 0 ), 0 );

		// Use 'row' resultType — returns column-level rows without attempting to
		// unserialize the `package` blob (the default 'objs' mode silently drops
		// rows with an empty blob, which we don't need for a summary listing).
		$rows = \DUP_Package::get_packages_by_status(
			[],
			$limit,
			$offset,
			'`id` DESC',
			'row'
		);

		$total = (int) \DUP_Package::count_by_status( [] );

		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[] = self::format_package_summary( $row );
		}

		return [
			'limit'   => $limit,
			'offset'  => $offset,
			'count'   => count( $out ),
			'total'   => $total,
			'entries' => $out,
		];
	}

	private static function handle_get_package_status( $args ) {
		$id = intval( $args['package_id'] ?? 0 );
		if ( $id <= 0 ) {
			throw new \Exception( 'package_id is required' );
		}
		$package = self::load_package( $id );
		if ( ! $package ) {
			throw new \Exception( 'Duplicator package not found: ' . $id );
		}
		return self::format_package_detail( $package );
	}

	private static function handle_get_installer( $args ) {
		$id = intval( $args['package_id'] ?? 0 );
		if ( $id <= 0 ) {
			throw new \Exception( 'package_id is required' );
		}
		$package = self::load_package( $id );
		if ( ! $package ) {
			throw new \Exception( 'Duplicator package not found: ' . $id );
		}
		$status = intval( $package->status ?? $package->Status ?? 0 );
		if ( $status < 100 ) {
			return [
				'state'      => 'unavailable',
				'reason'     => 'not_complete',
				'message'    => 'Package build has not completed yet. Poll duplicator_get_package_status until status is 100.',
				'package_id' => $id,
				'status'     => self::status_label( $status ),
			];
		}
		// Hydrate the serialized package object if available to get the exact
		// installer filename. Fall back to hash-based convention when the blob
		// is empty (typical for test seeds; real Duplicator packages always
		// carry the blob).
		$installer_filename = '';
		if ( ! empty( $package->package ) && is_string( $package->package ) ) {
			$decoded = @unserialize( $package->package );
			if ( is_object( $decoded ) && method_exists( $decoded, 'getInstallerFilename' ) ) {
				$installer_filename = (string) $decoded->getInstallerFilename();
			}
		}
		return [
			'package_id'         => $id,
			'installer_filename' => $installer_filename,
			'installer_url'      => self::build_installer_url( $installer_filename ),
			'notice'             => 'Installer downloads require authenticated access via Duplicator admin; direct download URLs are gated by the plugin.',
		];
	}

	private static function load_package( $id ) {
		// Read the package row directly — DUP_Package::get_row_by_status()
		// doesn't accept an ID filter in its condition syntax without going
		// through the packageStatus condition builder, and this is a simple
		// primary-key lookup.
		global $wpdb;
		$table = $wpdb->base_prefix . 'duplicator_packages';
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT `id` AS ID, `name`, `hash`, `status`, `created`, `owner`, `package` FROM `$table` WHERE `id` = %d",
			$id
		) );
	}

	private static function format_package_summary( $row ) {
		$created_at = isset( $row->created ) ? $row->created : null;
		$status_int = isset( $row->status ) ? (int) $row->status : ( isset( $row->Status ) ? (int) $row->Status : 0 );
		return [
			'id'             => isset( $row->ID ) ? (int) $row->ID : ( isset( $row->id ) ? (int) $row->id : null ),
			'name'           => $row->name ?? ( isset( $row->Name ) ? $row->Name : '' ),
			'hash'           => $row->hash ?? '',
			'owner'          => $row->owner ?? '',
			'status'         => $status_int,
			'status_label'   => self::status_label( $status_int ),
			'created_at'     => $created_at,
			'created_at_iso' => self::to_iso( $created_at ),
		];
	}

	private static function format_package_detail( $row ) {
		$out = self::format_package_summary( $row );

		// Try to hydrate the full DUP_Package object from the serialized blob
		// for extra detail (archive size, filenames). Row.package is populated
		// by Duplicator's own save flow; direct DB inserts leave it empty.
		$package_obj = null;
		if ( ! empty( $row->package ) && is_string( $row->package ) ) {
			$decoded = @unserialize( $row->package );
			if ( is_object( $decoded ) ) {
				$package_obj = $decoded;
			}
		}

		if ( $package_obj ) {
			if ( method_exists( $package_obj, 'getArchiveSize' ) ) {
				$out['archive_size_bytes'] = (int) $package_obj->getArchiveSize();
			}
			if ( method_exists( $package_obj, 'getArchiveFilename' ) ) {
				$out['archive_filename'] = (string) $package_obj->getArchiveFilename();
			}
			if ( method_exists( $package_obj, 'getInstallerFilename' ) ) {
				$out['installer_filename'] = (string) $package_obj->getInstallerFilename();
			}
			if ( isset( $package_obj->Notes ) ) {
				$out['notes'] = (string) $package_obj->Notes;
			}
		}
		return $out;
	}

	private static function status_label( $status ) {
		$map = [
			-1  => 'error',
			0   => 'created',
			10  => 'started',
			20  => 'db_started',
			30  => 'db_done',
			40  => 'archive_started',
			60  => 'archive_validating',
			65  => 'archive_done',
			100 => 'complete',
		];
		return $map[ $status ] ?? 'unknown';
	}

	private static function build_installer_url( $filename ) {
		if ( '' === $filename ) {
			return '';
		}
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['baseurl'] ) . 'duplicator/';
		return $base . $filename;
	}

	private static function to_iso( $mysql_datetime ) {
		if ( empty( $mysql_datetime ) || '0000-00-00 00:00:00' === $mysql_datetime ) {
			return null;
		}
		$ts = strtotime( $mysql_datetime . ' UTC' );
		return $ts ? gmdate( 'c', $ts ) : null;
	}
}

/**
 * Manifest declaration.
 * capabilities: read only in the Free tier — create is deferred to Pro.
 */
add_filter( 'royal_mcp_manifests', function ( $manifests ) {
	if ( ! defined( 'DUPLICATOR_VERSION' ) ) {
		return $manifests;
	}
	$manifests[] = [
		'royal_mcp_manifest_version' => '1.0',
		'plugin_slug'                => 'duplicator',
		'plugin_display_name'        => 'Duplicator',
		'plugin_version'             => DUPLICATOR_VERSION,
		'vendor_name'                => 'Snap Creek',
		'mcp_endpoint'               => rest_url( 'royal-mcp/v1/mcp' ),
		'auth_methods'               => [ 'oauth2.1' ],
		'capabilities'               => [ 'read' ],
		'manifest_updated_at'        => gmdate( 'c' ),
		'trust_signals'              => [
			'supports_dry_run'                => false,
			'supports_undo'                   => false,
			'supports_snapshots'              => false,
			'requires_review_for_destructive' => false,
		],
	];
	return $manifests;
} );
