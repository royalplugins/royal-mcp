<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Tool_Refusal;
use Royal_MCP\MCP\Undo_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirection MCP Integration.
 *
 * Registers MCP tools for the Redirection plugin (John Godley, ~2M+ installs).
 * Only loaded when Redirection is active.
 *
 * Redirection's PHP API is documented as "subject to change" upstream, so every
 * class_exists / method_exists is defensive by design — a missing method returns
 * a clean tool error rather than a fatal.
 */
class Redirection {

	/**
	 * Check if Redirection is available.
	 */
	public static function is_available() {
		return class_exists( '\Red_Item' ) && class_exists( '\Red_Group' );
	}

	/**
	 * Get tool definitions for MCP tools/list response.
	 */
	public static function get_tools() {
		// Always register so tools appear in MCP tools/list regardless of the
		// underlying plugin activation state. execute_tool gates at call time
		// with a clean 'not active' throw. Prevents ghost-tools UX where
		// activating a plugin post-MCP-connection requires the client to
		// reconnect before the tools become discoverable.
		return array(
			array(
				'name'        => 'redirection_list_redirects',
				'description' => 'List redirects from the Redirection plugin. Direct read from the wp_redirection_items table. Filter by group_id or a URL substring; default limit 50, max 200.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit'    => array( 'type' => 'integer', 'description' => 'Max redirects to return (default 50, max 200)' ),
						'group_id' => array( 'type' => 'integer', 'description' => 'Only return redirects in this group (use redirection_list_groups to discover IDs)' ),
						'search'   => array( 'type' => 'string', 'description' => 'Case-insensitive substring match against source URL' ),
					),
				),
			),
			array(
				'name'        => 'redirection_create_redirect',
				'description' => 'Create a redirect using the Redirection plugin. Wraps Red_Item::create. status_code defaults to 301 (permanent). Returns an undo token (undo deletes the redirect). Pass dry_run: true to check the redirect without creating it.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'source_url'  => array( 'type' => 'string', 'description' => 'Source path (e.g. /old-page/) or full URL' ),
						'target_url'  => array( 'type' => 'string', 'description' => 'Destination path or full URL' ),
						'status_code' => array( 'type' => 'integer', 'enum' => array( 301, 302, 307 ), 'description' => 'HTTP status code (301 permanent, 302 temp, 307 preserved-method)' ),
						'group_id'    => array( 'type' => 'integer', 'description' => 'Group to file this redirect under. Defaults to the first enabled Redirection group; the reply says which.' ),
						'regex'       => array( 'type' => 'boolean', 'description' => 'Treat source_url as a regex pattern. Default false. Regex sources are limited to 250 characters, may not nest repeats such as (a+)+, and may not name wp-admin, wp-login, xmlrpc, wp-json or wp-cron.' ),
						'title'       => array( 'type' => 'string', 'description' => 'Optional human-readable title for admin display.' ),
						'allow_protected_source' => array( 'type' => 'boolean', 'description' => 'Allow a source that matches the home page, wp-login.php, wp-admin, the REST API, xmlrpc.php or wp-cron.php. Redirecting those can lock everyone out of the site. Default false.' ),
						'dry_run'     => array( 'type' => 'boolean', 'description' => 'Check the redirect and report what would be created, without creating it. Default false.' ),
					),
					'required'   => array( 'source_url', 'target_url' ),
				),
			),
			array(
				'name'        => 'redirection_update_redirect',
				'description' => 'Update an existing Redirection entry by ID. Pass only the fields you want to change. Returns an undo token (undo restores the redirect as it was). Pass dry_run: true to see the change without making it.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer', 'description' => 'Redirect ID (use redirection_list_redirects to discover)' ),
						'target_url'  => array( 'type' => 'string', 'description' => 'New destination path or full URL' ),
						'status_code' => array( 'type' => 'integer', 'enum' => array( 301, 302, 307 ) ),
						'enabled'     => array( 'type' => 'boolean', 'description' => 'Enable (true) or disable (false) the redirect' ),
						'allow_protected_source' => array( 'type' => 'boolean', 'description' => 'Allow a source that matches the home page, wp-login.php, wp-admin, the REST API, xmlrpc.php or wp-cron.php. Redirecting those can lock everyone out of the site. Default false.' ),
						'dry_run'     => array( 'type' => 'boolean', 'description' => 'Report what would change, without changing it. Default false.' ),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'        => 'redirection_list_groups',
				'description' => 'List the Redirection group registry. Direct read from wp_redirection_groups. Use group IDs to file new redirects into the correct category.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
			),
		);
	}

	/**
	 * Execute a Redirection MCP tool.
	 */
	public static function execute_tool( $name, $args ) {
		// umbrella cap check BEFORE the active-check. Without this order a Subscriber
		// receives "Redirection is not active" and learns whether the plugin is installed.
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use Redirection tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Redirection plugin is not active' );
		}

		switch ( $name ) {
			case 'redirection_list_redirects':
				return self::list_redirects( $args );
			case 'redirection_create_redirect':
				return self::create_redirect( $args );
			case 'redirection_update_redirect':
				return self::update_redirect( $args );
			case 'redirection_list_groups':
				return self::list_groups();
			default:
				throw new \Exception( 'Unknown Redirection tool: ' . esc_html( $name ) );
		}
	}

	private static function list_redirects( $args ) {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_items';
		$limit = isset( $args['limit'] ) ? max( 1, min( intval( $args['limit'] ), 200 ) ) : 50;

		$where  = array( '1=1' );
		$params = array();
		if ( isset( $args['group_id'] ) ) {
			$where[]  = 'group_id = %d';
			$params[] = intval( $args['group_id'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
		}

		$sql = "SELECT id, url, match_url, action_data, action_type, action_code, regex, position, status, group_id FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$params[] = $limit;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_map( function ( $r ) {
			return array(
				'id'          => (int) $r['id'],
				'source_url'  => (string) $r['url'],
				'target_url'  => (string) $r['action_data'],
				'status_code' => (int) $r['action_code'],
				'regex'       => ! empty( $r['regex'] ),
				'enabled'     => 'enabled' === $r['status'],
				'group_id'    => (int) $r['group_id'],
				'position'    => (int) $r['position'],
			);
		}, $rows );
	}

	private static function create_redirect( $args ) {
		if ( empty( $args['source_url'] ) || empty( $args['target_url'] ) || ! is_string( $args['source_url'] ) || ! is_string( $args['target_url'] ) ) {
			throw new \Exception( 'source_url and target_url are required strings' );
		}
		if ( ! method_exists( '\Red_Item', 'create' ) ) {
			throw new \Exception( 'Red_Item::create not available in this Redirection version.' );
		}

		$source   = sanitize_text_field( $args['source_url'] );
		$is_regex = self::flag( $args, 'regex' );
		if ( $is_regex ) {
			self::validate_regex_source( $source );
		}
		self::refuse_protected_source( $source, $is_regex, $args );

		$status_code = isset( $args['status_code'] ) ? self::status_code_arg( $args['status_code'] ) : 301;

		$group_note = '';
		if ( isset( $args['group_id'] ) && 0 !== $args['group_id'] && '0' !== $args['group_id'] ) {
			$group_id = self::positive_int_arg( $args['group_id'], 'group_id' );
		} else {
			$group = self::default_group();
			if ( ! $group ) {
				throw new \Exception( 'No enabled Redirection group exists. Create or enable a group in Redirection, or pass group_id.' );
			}
			$group_id   = (int) $group['id'];
			$group_note = sprintf( ' Filed under group %d (%s), the first enabled group, because no group_id was given.', $group_id, (string) $group['name'] );
		}

		$data = array(
			'url'         => $source,
			'action_data' => array( 'url' => esc_url_raw( $args['target_url'] ) ),
			'action_type' => 'url',
			'action_code' => $status_code,
			'match_type'  => 'url',
			'group_id'    => $group_id,
			'regex'       => $is_regex,
			'title'       => isset( $args['title'] ) && is_scalar( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : '',
		);

		$struct = array(
			'source_url'  => $data['url'],
			'target_url'  => $data['action_data']['url'],
			'status_code' => $data['action_code'],
			'regex'       => $is_regex,
			'group_id'    => $group_id,
		);

		if ( self::flag( $args, 'dry_run' ) ) {
			self::check_with_sanitizer( $data );
			return Envelope::success(
				'Dry run: nothing was created. This redirect would be created.' . $group_note,
				array( 'dry_run' => true, 'would_create' => $struct, 'message' => 'Dry run: nothing was created.' . $group_note )
			);
		}

		$result = \Red_Item::create( $data );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( 'Failed to create redirect: ' . esc_html( $result->get_error_message() ) );
		}
		$id = is_object( $result ) && method_exists( $result, 'get_id' ) ? (int) $result->get_id() : 0;

		$undo = null;
		if ( $id > 0 ) {
			$undo = Undo_Store::store( array(
				'op'           => 'redirection_create_redirect',
				'summary'      => sprintf( 'Delete redirect %d (%s)', $id, $data['url'] ),
				'target'       => array( 'redirect_id' => $id ),
				'pre_op_state' => array( 'created_fingerprint' => self::fingerprint( $id ) ),
			) );
		}
		return Envelope::success(
			sprintf( 'Redirect %d created.', $id ) . $group_note,
			array_merge( array( 'id' => $id ), $struct, array( 'message' => 'Redirect created.' . $group_note ) ),
			$undo
		);
	}

	private static function update_redirect( $args ) {
		if ( empty( $args['id'] ) ) {
			throw new \Exception( 'id is required' );
		}
		if ( ! method_exists( '\Red_Item', 'get_by_id' ) ) {
			throw new \Exception( 'Red_Item::get_by_id not available in this Redirection version.' );
		}

		$id   = self::positive_int_arg( $args['id'], 'id' );
		$item = \Red_Item::get_by_id( $id );
		if ( ! $item ) {
			throw new \Exception( 'Redirect not found: ' . intval( $id ) );
		}
		if ( ! method_exists( $item, 'update' ) || ! method_exists( $item, 'to_json' ) ) {
			throw new \Exception( 'Red_Item::update not available in this Redirection version.' );
		}

		$changes = array();
		if ( isset( $args['target_url'] ) ) {
			if ( ! is_string( $args['target_url'] ) || '' === trim( $args['target_url'] ) ) {
				throw new \Exception( 'target_url must be a non-empty string.' );
			}
			$changes['target_url'] = esc_url_raw( $args['target_url'] );
		}
		if ( isset( $args['status_code'] ) ) {
			$changes['status_code'] = self::status_code_arg( $args['status_code'] );
		}
		$enable = null;
		if ( array_key_exists( 'enabled', $args ) ) {
			$raw_enabled = $args['enabled'];
			if ( is_bool( $raw_enabled ) ) {
				$enable = $raw_enabled;
			} elseif ( ( is_int( $raw_enabled ) || is_string( $raw_enabled ) ) && '' !== trim( (string) $raw_enabled ) ) {
				$enable = filter_var( $raw_enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			}
			if ( null === $enable ) {
				throw new \Exception( 'enabled must be true or false.' );
			}
		}
		if ( empty( $changes ) && null === $enable ) {
			throw new \Exception( 'No updatable fields provided (target_url, status_code, or enabled)' );
		}

		$before = self::read_row( $id );
		if ( ! $before ) {
			throw new \Exception( 'Redirect not found: ' . intval( $id ) );
		}
		$json = $item->to_json();

		// The full current redirect with the requested changes applied:
		// Redirection validates every field on update, not only the changed ones.
		$details = array(
			'url'         => (string) $json['url'],
			'regex'       => ! empty( $json['regex'] ),
			'match_type'  => (string) $json['match_type'],
			'action_type' => (string) $json['action_type'],
			'action_code' => (int) $json['action_code'],
			'action_data' => is_array( $json['action_data'] ) ? $json['action_data'] : array(),
			'group_id'    => (int) $json['group_id'],
			'title'       => (string) $json['title'],
			'match_data'  => $json['match_data'],
			'position'    => (int) $json['position'],
		);
		if ( ( isset( $changes['target_url'] ) || isset( $changes['status_code'] ) )
			&& ( 'url' !== $details['match_type'] || 'url' !== $details['action_type'] ) ) {
			throw new \Exception( sprintf( 'Redirect %d uses the %s match and the %s action, so its target and status code are set in Redirection itself. Only enabled can be changed here.', $id, esc_html( $details['match_type'] ), esc_html( $details['action_type'] ) ) );
		}
		if ( isset( $changes['target_url'] ) ) {
			$details['action_data']['url'] = $changes['target_url'];
		}
		if ( isset( $changes['status_code'] ) ) {
			$details['action_code'] = $changes['status_code'];
		}

		$is_enabled = 'enabled' === (string) $before['status'];
		$will_be_on = null === $enable ? $is_enabled : $enable;
		// A redirect that will be live after this call is checked like a new one.
		if ( $will_be_on && ( ! empty( $changes ) || ( true === $enable && ! $is_enabled ) ) ) {
			self::refuse_protected_source( $details['url'], ! empty( $details['regex'] ), $args );
		}

		$would = array();
		if ( isset( $changes['target_url'] ) ) {
			$would['target_url'] = array( 'from' => isset( $json['action_data']['url'] ) ? (string) $json['action_data']['url'] : '', 'to' => $changes['target_url'] );
		}
		if ( isset( $changes['status_code'] ) ) {
			$would['status_code'] = array( 'from' => (int) $json['action_code'], 'to' => $changes['status_code'] );
		}
		if ( null !== $enable ) {
			$would['enabled'] = array( 'from' => $is_enabled, 'to' => $enable );
		}

		if ( self::flag( $args, 'dry_run' ) ) {
			if ( ! empty( $changes ) ) {
				self::check_with_sanitizer( $details );
			}
			return Envelope::success(
				sprintf( 'Dry run: redirect %d was not changed.', $id ),
				array( 'id' => $id, 'dry_run' => true, 'would_change' => $would, 'message' => 'Dry run: nothing was changed.' )
			);
		}

		if ( ! empty( $changes ) ) {
			$result = $item->update( $details );
			if ( is_wp_error( $result ) ) {
				throw new \Exception( 'Failed to update redirect: ' . esc_html( $result->get_error_message() ) );
			}
		}
		if ( null !== $enable && $enable !== $is_enabled ) {
			if ( $enable ) {
				$item->enable();
			} else {
				$item->disable();
			}
		}

		$undo = Undo_Store::store( array(
			'op'           => 'redirection_update_redirect',
			'summary'      => sprintf( 'Restore redirect %d as it was', $id ),
			'target'       => array( 'redirect_id' => $id ),
			'pre_op_state' => array(
				'prior_row'           => $before,
				'written_fingerprint' => self::fingerprint( $id ),
			),
		) );
		return Envelope::success(
			sprintf( 'Redirect %d updated.', $id ),
			array( 'id' => $id, 'updated' => array_keys( $would ), 'changes' => $would, 'message' => 'Redirect updated.' ),
			$undo
		);
	}

	/** Columns that define a redirect (hit counters are left out). */
	const ROW_FIELDS = array( 'url', 'match_url', 'match_data', 'regex', 'position', 'group_id', 'status', 'action_type', 'action_code', 'action_data', 'match_type', 'title' );

	/** A redirect's stored definition, or null when it does not exist. */
	private static function read_row( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . implode( ', ', self::ROW_FIELDS ) . " FROM {$wpdb->prefix}redirection_items WHERE id = %d", (int) $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Fingerprint of a redirect's definition; '' when it does not exist. */
	public static function fingerprint( $id ) {
		$row = self::read_row( $id );
		if ( ! $row ) {
			return '';
		}
		foreach ( $row as $k => $v ) {
			$row[ $k ] = null === $v ? '' : (string) $v;
		}
		return md5( (string) wp_json_encode( $row ) );
	}

	/**
	 * Undo of a create or update. Refuses with edited_since_write when the
	 * redirect changed after the tracked write, unless $force.
	 */
	public static function undo( array $snapshot, $force = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'manage_options capability required to undo this operation.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Redirection is no longer active, so this change cannot be undone.' );
		}
		$op     = (string) ( $snapshot['op'] ?? '' );
		$id     = (int) ( $snapshot['target']['redirect_id'] ?? 0 );
		$pre    = (array) ( $snapshot['pre_op_state'] ?? array() );
		$target = array( 'redirect_id' => $id );
		if ( $id <= 0 ) {
			throw new \Exception( 'Undo snapshot missing redirect_id.' );
		}
		$now = self::fingerprint( $id );

		if ( 'redirection_create_redirect' === $op ) {
			if ( '' === $now ) {
				return Envelope::success(
					sprintf( 'No-op: redirect %d was already removed.', $id ),
					array( 'undone' => true, 'op' => $op, 'target' => $target, 'restored' => false, 'reason' => 'row_already_gone' )
				);
			}
			$written = (string) ( $pre['created_fingerprint'] ?? '' );
			if ( ! $force && '' !== $written && $now !== $written ) {
				throw new Tool_Refusal( 'edited_since_write', sprintf( 'Cannot undo: redirect %d was edited after it was created, and undo would delete it with those edits. The undo token stays valid: undo again with force: true to delete it anyway.', $id ) );
			}
			$item = \Red_Item::get_by_id( $id );
			if ( $item ) {
				$item->delete();
			}
			return Envelope::success(
				sprintf( 'Deleted redirect %d.', $id ),
				array( 'undone' => true, 'op' => $op, 'target' => $target, 'restored' => true )
			);
		}

		if ( 'redirection_update_redirect' === $op ) {
			$prior = isset( $pre['prior_row'] ) && is_array( $pre['prior_row'] ) ? array_intersect_key( $pre['prior_row'], array_flip( self::ROW_FIELDS ) ) : array();
			if ( count( $prior ) !== count( self::ROW_FIELDS ) ) {
				throw new \Exception( 'Undo snapshot has no prior redirect to restore.' );
			}
			if ( '' === $now ) {
				throw new \Exception( sprintf( 'Redirect %d no longer exists, so there is nothing to restore.', $id ) );
			}
			$written = (string) ( $pre['written_fingerprint'] ?? '' );
			if ( ! $force && '' !== $written && $now !== $written ) {
				throw new Tool_Refusal( 'edited_since_write', sprintf( 'Cannot undo: redirect %d was edited after this change, and undo would remove those edits. The undo token stays valid: undo again with force: true to restore the earlier version anyway.', $id ) );
			}
			global $wpdb;
			$current   = self::read_row( $id );
			$old_group = (int) ( $current['group_id'] ?? 0 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->update( $wpdb->prefix . 'redirection_items', $prior, array( 'id' => $id ) );
			if ( false === $ok ) {
				throw new \Exception( sprintf( 'Redirect %d could not be restored.', $id ) );
			}
			if ( class_exists( '\Red_Module' ) && method_exists( '\Red_Module', 'flush' ) ) {
				\Red_Module::flush( (int) $prior['group_id'] );
				if ( $old_group !== (int) $prior['group_id'] ) {
					\Red_Module::flush( $old_group );
				}
			}
			do_action( 'redirection_redirect_updated', $id, \Red_Item::get_by_id( $id ) );
			return Envelope::success(
				sprintf( 'Restored redirect %d.', $id ),
				array( 'undone' => true, 'op' => $op, 'target' => $target, 'restored' => true )
			);
		}

		throw new \Exception( 'Unsupported Redirection undo operation.' );
	}

	/** Status codes a redirect may use. */
	const STATUS_CODES = array( 301, 302, 307 );

	/** A positive whole number given as an integer or a string of digits; anything else is refused. */
	private static function positive_int_arg( $value, $name ) {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && preg_match( '/^\s*[1-9][0-9]{0,9}\s*$/', $value ) ) {
			return (int) $value;
		}
		throw new \Exception( sprintf( '%s must be a positive whole number.', esc_html( $name ) ) );
	}

	/** A status code from STATUS_CODES; anything else is refused. */
	private static function status_code_arg( $value ) {
		$code = ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^\s*\d{3}\s*$/', $value ) ) ) ? (int) $value : 0;
		if ( ! in_array( $code, self::STATUS_CODES, true ) ) {
			throw new \Exception( 'status_code must be 301, 302 or 307.' );
		}
		return $code;
	}

	/** A boolean argument, read the way FILTER_VALIDATE_BOOLEAN reads it. */
	private static function flag( array $args, $key ) {
		return isset( $args[ $key ] ) && filter_var( $args[ $key ], FILTER_VALIDATE_BOOLEAN );
	}

	/** Run Redirection's own validation on a redirect without saving it. */
	private static function check_with_sanitizer( array $data ) {
		if ( ! class_exists( '\Red_Item_Sanitize' ) ) {
			return;
		}
		$checked = ( new \Red_Item_Sanitize() )->get( $data );
		if ( is_wp_error( $checked ) ) {
			throw new \Exception( 'Redirection would refuse this redirect: ' . esc_html( $checked->get_error_message() ) );
		}
	}

	/** The first enabled group, preferring the WordPress module; null when there is none. */
	private static function default_group() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( "SELECT id, name FROM {$wpdb->prefix}redirection_groups WHERE status = 'enabled' ORDER BY ( module_id = 1 ) DESC, position, id LIMIT 1", ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Longest regex source accepted. */
	const MAX_REGEX_LENGTH = 250;

	/** Backtracking steps one test match may use in the slow-pattern check. */
	const REGEX_STEP_BUDGET = 100000;

	/** Seconds one test match may take in the slow-pattern check. */
	const REGEX_TIME_BUDGET = 0.005;

	/**
	 * Refuse a regex source that is too long, does not compile, or takes very
	 * long to match some addresses. Every page request is matched against it.
	 */
	public static function validate_regex_source( $source ) {
		$pattern = rawurldecode( (string) $source );
		if ( strlen( $pattern ) > self::MAX_REGEX_LENGTH ) {
			throw new \Exception( sprintf( 'Refused: regex sources are limited to %d characters.', self::MAX_REGEX_LENGTH ) );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid pattern is refused below
		if ( false === @preg_match( '@' . str_replace( '@', '\\@', $pattern ) . '@i', '' ) ) {
			throw new \Exception( 'source_url is not a valid regular expression.' );
		}
		if ( self::is_slow_regex( $pattern ) ) {
			throw new \Exception( 'Refused: this regex can take very long to match some addresses (for example a repeated group whose parts overlap, such as (a+)+ or (a|a)*), and every page request is matched against it. Rewrite it more specifically.' );
		}
	}

	/**
	 * Whether the pattern runs out of its step or time budget on any test
	 * subject. Subjects are long runs of the pattern's own literal text and of
	 * common characters, ending in a character that forces a failed match.
	 */
	private static function is_slow_regex( $pattern ) {
		$compiled = '@' . str_replace( '@', '\\@', $pattern ) . '@i';
		$old_bt   = ini_get( 'pcre.backtrack_limit' );
		$old_rc   = ini_get( 'pcre.recursion_limit' );
		ini_set( 'pcre.backtrack_limit', (string) self::REGEX_STEP_BUDGET ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		ini_set( 'pcre.recursion_limit', '20000' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$slow = false;
		try {
			foreach ( self::slow_regex_subjects( $pattern ) as $subject ) {
				for ( $try = 0; $try < 2; $try++ ) {
					$start = microtime( true );
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed match is the signal
					$result = @preg_match( $compiled, $subject );
					if ( false === $result ) {
						$slow = true;
						break 2;
					}
					if ( microtime( true ) - $start <= self::REGEX_TIME_BUDGET ) {
						continue 2;
					}
				}
				// Over the time budget twice in a row.
				$slow = true;
				break;
			}
		} finally {
			if ( false !== $old_bt ) {
				ini_set( 'pcre.backtrack_limit', $old_bt ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			}
			if ( false !== $old_rc ) {
				ini_set( 'pcre.recursion_limit', $old_rc ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			}
		}
		return $slow;
	}

	/** Test subjects for is_slow_regex(). */
	private static function slow_regex_subjects( $pattern ) {
		$prefix = '';
		if ( preg_match( '#^\^?((?:[A-Za-z0-9/_~-]|\\\\[./-])*)#', $pattern, $m ) ) {
			$prefix = str_replace( '\\', '', $m[1] );
		}
		$plain = (string) preg_replace( '/\\\\[dDwWsSbBhHvV]/', ' ', $pattern );
		$plain = (string) preg_replace( '/\\\\(.)/s', '$1', $plain );
		preg_match_all( '#[A-Za-z0-9_,./-]+#', $plain, $mm );
		$words = array();
		foreach ( $mm[0] as $word ) {
			$words[] = $word;
			foreach ( str_split( $word ) as $char ) {
				$words[] = $char;
			}
		}
		$words = array_merge( $words, array( 'a', '1', 'ab', 'a-', 'a/', 'a.', ',', 'foo', ' ', '-' ) );
		$words = array_slice( array_values( array_unique( $words ) ), 0, 40 );
		$subjects = array();
		foreach ( $words as $word ) {
			foreach ( array( 16, 30, 48 ) as $length ) {
				$run = str_repeat( $word, (int) max( 2, ceil( $length / strlen( $word ) ) ) );
				foreach ( array_unique( array( '', '/', $prefix ) ) as $lead ) {
					$subjects[] = $lead . $run . '!';
				}
				$subjects[] = '/' . $run;
			}
		}
		// The engine gives up at once when a character the pattern needs is missing, so
		// also end failing runs with the pattern's own literal characters.
		$tails = array();
		foreach ( $mm[0] as $word ) {
			$tails[] = $word;
			foreach ( str_split( $word ) as $char ) {
				$tails[] = $char;
			}
		}
		$tails = array_slice( array_values( array_unique( $tails ) ), 0, 12 );
		foreach ( array_slice( $words, 0, 20 ) as $word ) {
			$run = str_repeat( $word, (int) max( 2, ceil( 30 / strlen( $word ) ) ) );
			foreach ( $tails as $tail ) {
				foreach ( array_unique( array( '/', $prefix ) ) as $lead ) {
					$subjects[] = $lead . $run . '!' . $tail;
				}
			}
		}
		return array_values( array_unique( $subjects ) );
	}

	/** Paths below each WordPress base path whose redirect would lock people out or break WordPress itself. */
	const SYSTEM_PATHS = array( 'wp-login.php', 'wp-admin', 'wp-json', 'xmlrpc.php', 'wp-cron.php', 'index.php' );

	/** Requests (relative to each base path) a regex source is tested against. */
	const REGEX_PROBES = array(
		'', 'index.php', '?p=1',
		'wp-admin', 'wp-admin/', 'wp-admin/index.php', 'wp-admin/x.php', 'wp-admin/plugins.php', 'wp-admin/tools.php',
		'wp-admin/options-general.php', 'wp-admin/admin-ajax.php', 'wp-admin/post.php?post=1&action=edit', 'wp-admin/admin.php?page=x',
		'wp-login.php', 'wp-login.php?x=1', 'wp-login.php?action=lostpassword', 'wp-login.php?action=logout',
		'wp-login.php?action=rp&key=x&login=y', 'wp-login.php?action=resetpass', 'wp-login.php?action=register',
		'wp-login.php?interim-login=1', 'wp-login.php?loggedout=true', 'wp-login.php?checkemail=confirm',
		'wp-login.php?redirect_to=%2Fwp-admin%2F&reauth=1', 'wp-login.php?redirect_to=/wp-admin/&reauth=1',
		'xmlrpc.php', 'wp-json', 'wp-json/', 'wp-json/wp/v2/posts', '?rest_route=/', '?rest_route=/wp/v2/', '?rest_route=/wp/v2/posts',
		'wp-cron.php', 'wp-cron.php?doing_wp_cron=1',
	);

	/** The paths WordPress answers under: the root, the site address path and the WordPress address path. */
	private static function base_paths() {
		$bases = array( '/' );
		foreach ( array( home_url( '/' ), site_url( '/' ) ) as $url ) {
			$path    = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
			$bases[] = '' === $path ? '/' : '/' . strtolower( $path ) . '/';
		}
		return array_values( array_unique( $bases ) );
	}

	/** Every probe under every base path, plus trailing-slash, query-string, no-leading-slash and upper-case variants. */
	private static function regex_probes() {
		$relative = self::REGEX_PROBES;
		$host     = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		foreach ( array( admin_url( '/' ), 'http://' . $host . '/wp-admin/', 'https://' . $host . '/wp-admin/' ) as $back ) {
			$relative[] = 'wp-login.php?redirect_to=' . $back . '&reauth=1';
			$relative[] = 'wp-login.php?redirect_to=' . rawurlencode( $back ) . '&reauth=1';
		}
		$out = array();
		foreach ( self::base_paths() as $base ) {
			foreach ( $relative as $rel ) {
				$probe = $base . $rel;
				$out[] = $probe;
				$out[] = strtoupper( $probe );
				if ( false === strpos( $probe, '?' ) ) {
					$out[] = rtrim( $probe, '/' ) . '/';
					$out[] = $probe . '?a=1';
					$out[] = ltrim( $probe, '/' );
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Regex text as it reads once escapes, single-character classes ([w], [-],
	 * [Pp]) and case are taken out, so the name check sees the path it matches.
	 */
	private static function pattern_text( $decoded ) {
		$text = (string) preg_replace_callback(
			'/\\\\x\{?([0-9a-fA-F]{2})\}?/',
			function ( $m ) {
				return chr( hexdec( $m[1] ) );
			},
			(string) $decoded
		);
		$text = (string) preg_replace_callback(
			'/\[(?!\^)((?:\\\\.|[^\]\\\\])+)\]/',
			function ( $m ) {
				$chars = array_unique( str_split( strtolower( str_replace( '\\', '', $m[1] ) ) ) );
				return 1 === count( $chars ) ? reset( $chars ) : $m[0];
			},
			$text
		);
		return strtolower( str_replace( '\\', '', $text ) );
	}

	/**
	 * Refuse a redirect source that matches a protected path, unless the call
	 * passes allow_protected_source: true. Protected paths sit under the root
	 * and under the site and WordPress address paths. Plain sources are
	 * compared after decoding, lower-casing and dropping the host, query and
	 * duplicate slashes. Regex sources are refused when their text names a
	 * WordPress system path or when they match any probe request.
	 */
	public static function refuse_protected_source( $source, $is_regex, array $args ) {
		if ( ! empty( $args['allow_protected_source'] ) && true === $args['allow_protected_source'] ) {
			return;
		}
		$hit = '';
		if ( $is_regex ) {
			$decoded = rawurldecode( (string) $source );
			if ( preg_match( '/wp[^a-z0-9]{0,6}(admin|login|json|cron)|xmlrpc/', self::pattern_text( $decoded ), $m ) ) {
				$hit = 'a WordPress system path (' . $m[0] . ')';
			} else {
				$pattern = '@' . str_replace( '@', '\\@', $decoded ) . '@i';
				foreach ( self::regex_probes() as $path ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid pattern is refused below
					$matched = @preg_match( $pattern, $path );
					if ( false === $matched ) {
						throw new \Exception( 'source_url is not a valid regular expression.' );
					}
					if ( 1 === $matched ) {
						$hit = $path;
						break;
					}
				}
			}
		} else {
			$path = strtolower( rawurldecode( rawurldecode( (string) $source ) ) );
			// Only a source with a scheme is a full URL; anything else (even "//x") is a path.
			if ( preg_match( '#^[a-z][a-z0-9+.-]*://#', $path ) ) {
				$parsed = wp_parse_url( $path );
				$path   = isset( $parsed['path'] ) ? (string) $parsed['path'] : '/';
			} else {
				$path = (string) strtok( $path, '?#' );
			}
			$path = '/' . ltrim( (string) preg_replace( '#/+#', '/', $path ), '/' );
			$path = rtrim( $path, '/' );
			$path = '' === $path ? '/' : $path;
			foreach ( self::base_paths() as $base ) {
				$home = rtrim( $base, '/' );
				$home = '' === $home ? '/' : $home;
				if ( $path === $home ) {
					$hit = $home;
					break;
				}
				foreach ( self::SYSTEM_PATHS as $system ) {
					$protected = $base . $system;
					if ( $path === $protected || 0 === strpos( $path, $protected . '/' ) ) {
						$hit = $protected;
						break 2;
					}
				}
			}
		}
		if ( '' !== $hit ) {
			throw new \Exception( sprintf( 'Refused: this source matches %s, and redirecting it can lock everyone out of the site. Pass allow_protected_source: true only if the site owner asked for exactly this.', $hit ) );
		}
	}

	private static function list_groups() {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_groups';
		$rows  = $wpdb->get_results( "SELECT id, name, tracking, status, position, module_id FROM {$table} ORDER BY position, id", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_map( function ( $r ) {
			return array(
				'id'        => (int) $r['id'],
				'name'      => (string) $r['name'],
				'tracking'  => ! empty( $r['tracking'] ),
				'enabled'   => 'enabled' === $r['status'],
				'position'  => (int) $r['position'],
				'module_id' => (int) $r['module_id'],
			);
		}, $rows );
	}
}
