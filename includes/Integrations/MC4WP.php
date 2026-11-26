<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Undo_Store;
use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Tool_Refusal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mailchimp for WP (MC4WP): connection status, the Mailchimp audiences it has
 * loaded, its sign-up forms and which audiences each form subscribes people to,
 * plus changing that audience choice on one form, with undo.
 *
 * Audience data comes from MC4WP's own cache of the Mailchimp account; only a
 * refresh asks Mailchimp. The API key is never returned (only whether one is
 * set) and no subscriber's details are read: audiences carry member counts only.
 */
class MC4WP {

	/** Largest page size for lists. */
	const MAX_PER_PAGE = 100;

	/** Highest page number accepted. */
	const MAX_PAGE = 100000;

	/** Audiences one form can be set to. */
	const MAX_FORM_LISTS = 20;

	/** Seconds between two refreshes from Mailchimp (a refresh can make many slow requests). */
	const REFRESH_EVERY = 300;

	/** Option holding the time of the last refresh. */
	const REFRESH_OPTION = 'royal_mcp_mc4wp_refresh_at';

	/** Option used as a short lock while a refresh takes its slot. */
	const REFRESH_LOCK_OPTION = 'royal_mcp_mc4wp_refresh_lock';

	/** Form settings returned besides the audiences. */
	const FORM_FLAGS = [ 'double_optin', 'update_existing', 'replace_interests' ];

	/** Arguments each tool accepts. */
	const ALLOWED_ARGS = [
		'mc4wp_get_status'        => [],
		'mc4wp_list_audiences'    => [ 'refresh', 'page', 'per_page' ],
		'mc4wp_list_forms'        => [ 'page', 'per_page' ],
		'mc4wp_update_form_lists' => [ 'form_id', 'list_ids' ],
	];

	public static function is_available() {
		return defined( 'MC4WP_VERSION' ) && function_exists( 'mc4wp_get_options' );
	}

	public static function get_tools() {
		$paging = [
			'page'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE, 'description' => 'Page number, from 1. Default 1.' ],
			'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'description' => 'Rows per page, 1 to ' . self::MAX_PER_PAGE . '. Default 20.' ],
		];
		$admins = ' Administrators only.';
		return [
			[
				'name'        => 'mc4wp_get_status',
				'description' => 'Read Mailchimp for WP\'s connection status: whether a Mailchimp API key is set (yes or no only; the key is never returned) and whether it comes from wp-config.php, how many audiences MC4WP has loaded from Mailchimp, how many sign-up forms exist, and the plugin version. Makes no request to Mailchimp. Read-only.' . $admins,
				'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ],
			],
			[
				'name'        => 'mc4wp_list_audiences',
				'description' => 'List the Mailchimp audiences (lists) Mailchimp for WP has loaded: ID, name and member count. No subscriber details are returned. Reads MC4WP\'s saved copy; pass refresh: true to load them from Mailchimp again first (can take a while on large accounts). Returns results, total and has_more. Read-only.' . $admins,
				'inputSchema' => [ 'type' => 'object', 'properties' => array_merge( [ 'refresh' => [ 'type' => 'boolean', 'description' => 'Load the audiences from Mailchimp again before listing. Default false.' ] ], $paging ), 'additionalProperties' => false ],
			],
			[
				'name'        => 'mc4wp_list_forms',
				'description' => 'List Mailchimp for WP sign-up forms: ID, name, status, the audiences each form subscribes people to (ID and name), and whether it uses double opt-in, updates existing subscribers and replaces interests. Returns results, total and has_more. Read-only.' . $admins,
				'inputSchema' => [ 'type' => 'object', 'properties' => $paging, 'additionalProperties' => false ],
			],
			[
				'name'        => 'mc4wp_update_form_lists',
				'description' => 'Set which Mailchimp audiences one Mailchimp for WP form subscribes people to. list_ids replaces the form\'s current audiences; each ID must be an audience MC4WP has loaded (see mc4wp_list_audiences). Other form settings are kept. Refused in read-only mode. Returns an undo token that puts the earlier audiences back.' . $admins,
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => [
						'form_id'  => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Form ID from mc4wp_list_forms.' ],
						'list_ids' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_FORM_LISTS, 'items' => [ 'type' => 'string', 'maxLength' => 32 ], 'description' => 'Audience IDs the form subscribes people to.' ],
					],
					'required'             => [ 'form_id', 'list_ids' ],
					'additionalProperties' => false,
				],
			],
		];
	}

	/** MC4WP's own admin capability, which a site can change through its filter. */
	private static function plugin_cap() {
		return (string) apply_filters( 'mc4wp_admin_required_capability', 'manage_options' );
	}

	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use Mailchimp for WP tools.' );
		}
		if ( is_multisite() && ! is_super_admin() && ! is_user_member_of_blog( get_current_user_id(), get_current_blog_id() ) ) {
			throw new \Exception( 'You do not have permission to use Mailchimp for WP tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Mailchimp for WP is not active' );
		}
		$cap = self::plugin_cap();
		if ( '' === $cap || ! current_user_can( $cap ) ) {
			throw new \Exception( 'You do not have permission to use Mailchimp for WP tools.' );
		}
		if ( null === $args ) {
			$args = [];
		}
		if ( is_object( $args ) ) {
			$args = get_object_vars( $args );
		}
		if ( ! is_array( $args ) ) {
			throw new \Exception( 'Arguments must be an object.' );
		}
		if ( isset( self::ALLOWED_ARGS[ $name ] ) ) {
			$unknown = array_diff( array_map( 'strval', array_keys( $args ) ), self::ALLOWED_ARGS[ $name ] );
			if ( $unknown ) {
				throw new Tool_Refusal( 'unknown_argument', sprintf( 'Unknown argument: %s.', esc_html( implode( ', ', array_slice( $unknown, 0, 5 ) ) ) ) );
			}
		}
		switch ( $name ) {
			case 'mc4wp_get_status':
				return self::get_status();
			case 'mc4wp_list_audiences':
				return self::list_audiences( $args );
			case 'mc4wp_list_forms':
				return self::list_forms( $args );
			case 'mc4wp_update_form_lists':
				return self::update_form_lists( $args );
		}
		throw new \Exception( 'Unknown Mailchimp for WP tool: ' . esc_html( $name ) );
	}

	private static function int_arg( array $args, $key, $default, $min, $max ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			if ( null === $default ) {
				throw new Tool_Refusal( 'invalid_args', sprintf( '%s is required.', esc_html( $key ) ) );
			}
			return $default;
		}
		$v = $args[ $key ];
		if ( ! is_int( $v ) || $v < $min || $v > $max ) {
			throw new Tool_Refusal( 'invalid_args', sprintf( '%s must be a whole number from %d to %d.', esc_html( $key ), (int) $min, (int) $max ) );
		}
		return $v;
	}

	private static function page_of( array $rows, array $args, array $extra = [] ) {
		$page     = self::int_arg( $args, 'page', 1, 1, self::MAX_PAGE );
		$per_page = self::int_arg( $args, 'per_page', 20, 1, self::MAX_PER_PAGE );
		$total    = count( $rows );
		$more     = $total > $page * $per_page;
		$out      = [ 'results' => array_values( array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ) ), 'total' => $total, 'has_more' => $more, 'page' => $page, 'per_page' => $per_page ];
		if ( $more ) {
			$out['next_page'] = $page + 1;
		}
		return array_merge( $out, $extra );
	}

	private static function key_set() {
		$from_constant = defined( 'MC4WP_API_KEY' ) && '' !== (string) constant( 'MC4WP_API_KEY' );
		$opts          = mc4wp_get_options();
		return [ $from_constant || ( is_array( $opts ) && '' !== trim( (string) ( $opts['api_key'] ?? '' ) ) ), $from_constant ];
	}

	/** Audience ID => [ name, member_count ], from MC4WP's saved copy (or Mailchimp on refresh). */
	private static function audiences( $refresh = false ) {
		$lists = null;
		if ( $refresh && class_exists( '\MC4WP_MailChimp' ) ) {
			$lists = ( new \MC4WP_MailChimp() )->get_lists( true );
		} else {
			$lists = get_transient( 'mc4wp_mailchimp_lists' );
		}
		if ( ! is_array( $lists ) ) {
			return null;
		}
		$out = [];
		foreach ( $lists as $id => $list ) {
			if ( is_array( $list ) ) {
				$list = (object) $list;
			}
			if ( ! is_object( $list ) ) {
				continue;
			}
			$lid = isset( $list->id ) && is_scalar( $list->id ) ? (string) $list->id : (string) $id;
			if ( '' === $lid ) {
				continue;
			}
			$stats = isset( $list->stats ) ? (object) $list->stats : null;
			$out[ $lid ] = [
				'id'           => sanitize_text_field( $lid ),
				'name'         => isset( $list->name ) && is_scalar( $list->name ) ? wp_strip_all_tags( (string) $list->name ) : '',
				'member_count' => is_object( $stats ) && isset( $stats->member_count ) && is_numeric( $stats->member_count ) ? (int) $stats->member_count : null,
			];
		}
		return $out;
	}

	private static function form_posts() {
		return get_posts( [ 'post_type' => 'mc4wp-form', 'post_status' => [ 'publish', 'draft', 'private', 'pending' ], 'numberposts' => 1000, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ] );
	}

	private static function get_status() {
		list( $set, $constant ) = self::key_set();
		$lists                  = self::audiences( false );
		return [
			'plugin'           => [ 'name' => 'Mailchimp for WP', 'version' => (string) MC4WP_VERSION ],
			'api_key_set'      => $set,
			'api_key_from_constant' => $constant,
			'audiences_loaded' => is_array( $lists ) ? count( $lists ) : 0,
			'audiences_note'   => is_array( $lists ) ? 'Audience counts are MC4WP\'s saved copy; mc4wp_list_audiences with refresh: true loads them again.' : 'MC4WP has no saved copy of the audiences yet; mc4wp_list_audiences with refresh: true loads them.',
			'form_count'       => count( self::form_posts() ),
		];
	}

	private static function list_audiences( array $args ) {
		$refresh = false;
		if ( array_key_exists( 'refresh', $args ) && null !== $args['refresh'] ) {
			if ( ! is_bool( $args['refresh'] ) ) {
				throw new Tool_Refusal( 'invalid_args', 'refresh must be true or false.' );
			}
			$refresh = $args['refresh'];
		}
		list( $set ) = self::key_set();
		if ( $refresh && ! $set ) {
			throw new Tool_Refusal( 'not_connected', 'No Mailchimp API key is set in Mailchimp for WP, so the audiences cannot be loaded.' );
		}
		$extra = [ 'source' => $refresh ? 'mailchimp' : 'saved_copy' ];
		if ( $refresh ) {
			self::take_refresh_slot();
			$saved = self::audiences( false );
		}
		$lists = self::audiences( $refresh );
		if ( null === $lists ) {
			return self::page_of( [], $args, [ 'note' => 'MC4WP has no saved copy of the audiences. Call again with refresh: true to load them from Mailchimp.' ] );
		}
		if ( $refresh && is_array( $saved ) && count( $lists ) < count( $saved ) ) {
			$extra['warning'] = sprintf( 'Mailchimp returned %d audiences where MC4WP had %d saved. Mailchimp may have answered with an error; check the API key and the Mailchimp service status, then refresh again later.', count( $lists ), count( $saved ) );
		}
		return self::page_of( array_values( $lists ), $args, $extra );
	}

	/**
	 * Takes the one refresh allowed per window, or refuses. Every attempt counts. The read and
	 * write run under a lock row, so two calls at the same moment cannot both refresh.
	 */
	private static function take_refresh_slot() {
		global $wpdb;
		$now = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data
		$got = 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", self::REFRESH_LOCK_OPTION, (string) $now ) );
		if ( ! $got ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data
			$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::REFRESH_LOCK_OPTION ) );
			if ( null !== $held && ( ! ctype_digit( (string) $held ) || (int) $held < $now - 240 ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data
				$got = 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $now, self::REFRESH_LOCK_OPTION, (string) $held ) );
			}
		}
		self::forget_cached_option( self::REFRESH_LOCK_OPTION );
		if ( ! $got ) {
			throw new Tool_Refusal( 'busy', 'Another refresh from Mailchimp is running. Try again in a few minutes.', [ 'retry_after_seconds' => 60 ] );
		}
		try {
			self::forget_cached_option( self::REFRESH_OPTION );
			$last = (int) get_option( self::REFRESH_OPTION, 0 );
			if ( $last > $now - self::REFRESH_EVERY && $last <= $now + 60 ) {
				$retry = $last + self::REFRESH_EVERY - $now;
				throw new Tool_Refusal( 'rate_limited', sprintf( 'Royal MCP refreshes the audiences from Mailchimp at most once every %d minutes. Try again in about %d minutes, or list the saved copy without refresh.', (int) ( self::REFRESH_EVERY / 60 ), (int) max( 1, ceil( $retry / 60 ) ) ), [ 'retry_after_seconds' => $retry ] );
			}
			update_option( self::REFRESH_OPTION, $now, false );
		} finally {
			// The lock only covers taking the slot; the time stored above keeps others out for the window.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::REFRESH_LOCK_OPTION, (string) $now ) );
			self::forget_cached_option( self::REFRESH_LOCK_OPTION );
		}
	}

	/** Drops any cached copy of an option written directly, so get_option() reads the table. */
	private static function forget_cached_option( $name ) {
		wp_cache_delete( $name, 'options' );
		$not = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $not ) && isset( $not[ $name ] ) ) {
			unset( $not[ $name ] );
			wp_cache_set( 'notoptions', $not, 'options' );
		}
	}

	/** A form's saved settings with MC4WP's defaults filled in. */
	private static function form_settings( $form_id ) {
		$meta = get_post_meta( (int) $form_id, '_mc4wp_settings', true );
		$meta = is_array( $meta ) ? $meta : [];
		if ( ! isset( $meta['lists'] ) || ! is_array( $meta['lists'] ) ) {
			$meta['lists'] = [];
		}
		return $meta;
	}

	private static function list_forms( array $args ) {
		$lists = self::audiences( false );
		$rows  = [];
		foreach ( self::form_posts() as $post ) {
			$settings = self::form_settings( $post->ID );
			$mapped   = [];
			foreach ( array_values( array_filter( array_map( 'strval', $settings['lists'] ) ) ) as $lid ) {
				$mapped[] = [ 'id' => sanitize_text_field( $lid ), 'name' => is_array( $lists ) && isset( $lists[ $lid ] ) ? $lists[ $lid ]['name'] : null ];
			}
			$row = [
				'id'        => (int) $post->ID,
				'name'      => wp_strip_all_tags( get_the_title( $post ) ),
				'status'    => (string) $post->post_status,
				'audiences' => $mapped,
			];
			foreach ( self::FORM_FLAGS as $flag ) {
				$row[ $flag ] = ! empty( $settings[ $flag ] );
			}
			$rows[] = $row;
		}
		return self::page_of( $rows, $args );
	}

	private static function update_form_lists( array $args ) {
		if ( \Royal_MCP\MCP\Server::read_only_mode() ) {
			throw new Tool_Refusal( 'read_only_mode', 'This site is in read-only mode: tools that change the site are switched off.' );
		}
		$form_id = self::int_arg( $args, 'form_id', null, 1, PHP_INT_MAX );
		$post    = get_post( $form_id );
		if ( ! $post instanceof \WP_Post || 'mc4wp-form' !== $post->post_type || 'trash' === $post->post_status ) {
			throw new Tool_Refusal( 'not_found', sprintf( 'Mailchimp for WP form %d was not found.', (int) $form_id ) );
		}
		$ids = $args['list_ids'] ?? null;
		if ( ! is_array( $ids ) || [] === $ids || array_keys( $ids ) !== range( 0, count( $ids ) - 1 ) || count( $ids ) > self::MAX_FORM_LISTS ) {
			throw new Tool_Refusal( 'invalid_args', sprintf( 'list_ids must be a list of 1 to %d audience IDs.', self::MAX_FORM_LISTS ) );
		}
		$lists = self::audiences( false );
		if ( null === $lists ) {
			throw new Tool_Refusal( 'audiences_not_loaded', 'MC4WP has no saved copy of the audiences. Call mc4wp_list_audiences with refresh: true first. Nothing was changed.' );
		}
		$want = [];
		foreach ( $ids as $lid ) {
			if ( ! is_string( $lid ) || '' === $lid || strlen( $lid ) > 32 || ! preg_match( '/^[A-Za-z0-9]+$/', $lid ) ) {
				throw new Tool_Refusal( 'invalid_args', 'Each audience ID must be the letters and digits Mailchimp uses, as mc4wp_list_audiences lists them. Nothing was changed.' );
			}
			if ( ! isset( $lists[ $lid ] ) ) {
				throw new Tool_Refusal( 'unknown_audience', sprintf( 'Audience %s is not one MC4WP has loaded. Nothing was changed.', esc_html( $lid ) ) );
			}
			$want[ $lid ] = true;
		}
		$want     = array_keys( $want );
		$settings = self::form_settings( $form_id );
		$before   = array_values( array_map( 'strval', $settings['lists'] ) );
		if ( $before === $want ) {
			return Envelope::success( sprintf( 'Form %d already subscribes people to these audiences; nothing changed.', (int) $form_id ), [ 'form_id' => (int) $form_id, 'list_ids' => $want, 'changed' => false ] );
		}
		$had_meta          = metadata_exists( 'post', $form_id, '_mc4wp_settings' );
		$settings['lists'] = $want;
		update_post_meta( $form_id, '_mc4wp_settings', wp_slash( $settings ) );
		$after = array_values( array_map( 'strval', self::form_settings( $form_id )['lists'] ) );
		if ( $after !== $want ) {
			throw new Tool_Refusal( 'not_saved', sprintf( 'The audiences of form %d did not save. Nothing was changed.', (int) $form_id ) );
		}
		do_action( 'mc4wp_save_form', (int) $form_id );
		$undo = Undo_Store::store( [
			'op'           => 'mc4wp_update_form_lists',
			'summary'      => sprintf( 'Restore the audiences of Mailchimp for WP form %d', (int) $form_id ),
			'target'       => [ 'form_id' => (int) $form_id ],
			'pre_op_state' => [ 'prior_lists' => $before, 'had_meta' => $had_meta, 'written_lists' => $want ],
		] );
		return Envelope::success(
			sprintf( 'Form %d now subscribes people to: %s.', (int) $form_id, implode( ', ', array_map( static function ( $l ) use ( $lists ) { return $lists[ $l ]['name'] . ' (' . $l . ')'; }, $want ) ) ),
			[ 'form_id' => (int) $form_id, 'list_ids' => $want, 'previous_list_ids' => $before, 'changed' => true ],
			$undo
		);
	}

	public static function undo( array $snapshot, $force = false ) {
		if ( ! self::is_available() ) {
			throw new \Exception( 'Mailchimp for WP is no longer active, so this change cannot be undone.' );
		}
		if ( \Royal_MCP\MCP\Server::read_only_mode() ) {
			throw new Tool_Refusal( 'read_only_mode', 'This site is in read-only mode: tools that change the site are switched off.' );
		}
		$cap = self::plugin_cap();
		if ( ! current_user_can( 'manage_options' ) || '' === $cap || ! current_user_can( $cap ) ) {
			throw new Tool_Refusal( 'forbidden', 'You do not have permission to use Mailchimp for WP tools.' );
		}
		$form_id = (int) ( $snapshot['target']['form_id'] ?? 0 );
		$pre     = (array) ( $snapshot['pre_op_state'] ?? [] );
		$post    = $form_id > 0 ? get_post( $form_id ) : null;
		if ( ! $post instanceof \WP_Post || 'mc4wp-form' !== $post->post_type ) {
			throw new Tool_Refusal( 'already_removed', sprintf( 'Mailchimp for WP form %d no longer exists.', $form_id ) );
		}
		$settings = self::form_settings( $form_id );
		$now      = array_values( array_map( 'strval', $settings['lists'] ) );
		if ( ! $force && array_values( array_map( 'strval', (array) ( $pre['written_lists'] ?? [] ) ) ) !== $now ) {
			throw new Tool_Refusal( 'edited_since_write', sprintf( 'Cannot undo: the audiences of form %d changed again after this change. The undo token stays valid: undo again with force: true to restore anyway.', $form_id ) );
		}
		$prior = array_values( array_filter( array_map( 'strval', (array) ( $pre['prior_lists'] ?? [] ) ), static function ( $l ) {
			return '' !== $l && preg_match( '/^[A-Za-z0-9]+$/', $l );
		} ) );
		if ( empty( $pre['had_meta'] ) ) {
			// No saved settings when the change was made: back to MC4WP's defaults.
			delete_post_meta( $form_id, '_mc4wp_settings' );
		} else {
			$settings['lists'] = $prior;
			update_post_meta( $form_id, '_mc4wp_settings', wp_slash( $settings ) );
		}
		do_action( 'mc4wp_save_form', $form_id );
		return Envelope::success( sprintf( 'Restored the audiences of Mailchimp for WP form %d.', $form_id ), [ 'undone' => true, 'op' => 'mc4wp_update_form_lists', 'target' => [ 'form_id' => $form_id ], 'restored' => true, 'list_ids' => $prior ] );
	}
}
