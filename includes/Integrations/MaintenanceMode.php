<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Tool_Refusal;
use Royal_MCP\MCP\Undo_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp_set_maintenance_mode: visitors get a 503 page with a short message while
 * signed-in administrators, wp-admin, the sign-in page, the REST API (and so
 * Royal MCP itself), AJAX and cron keep working.
 *
 * The state is one option. It only has an effect while Royal MCP is active.
 * While it is on, administrators see a notice in wp-admin with a link that
 * turns it off, since they themselves keep seeing the normal site.
 */
class MaintenanceMode {

	const TOOL   = 'wp_set_maintenance_mode';
	const OPTION = 'royal_mcp_maintenance';

	const OFF_ACTION = 'royal_mcp_maintenance_off';

	public static function register() {
		add_action( 'template_redirect', [ __CLASS__, 'maybe_show' ], 0 );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );
		add_action( 'network_admin_notices', [ __CLASS__, 'admin_notice' ] );
		add_action( 'admin_post_' . self::OFF_ACTION, [ __CLASS__, 'handle_off_link' ] );
	}

	private static function cap() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/** Tells administrators that visitors are getting the maintenance page, with a link that ends it. */
	public static function admin_notice() {
		$s = self::state();
		if ( ! $s['enabled'] || ! current_user_can( self::cap() ) ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::OFF_ACTION ), self::OFF_ACTION );
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Maintenance mode is on.', 'royal-mcp' ) . '</strong> '
			. esc_html__( 'Visitors get a 503 maintenance page; you see the normal site because you are signed in as an administrator.', 'royal-mcp' )
			. ( '' !== $s['message'] ? ' ' . esc_html( sprintf( /* translators: %s: the message visitors read. */ __( 'Message: "%s"', 'royal-mcp' ), $s['message'] ) ) : '' )
			. ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Turn maintenance mode off', 'royal-mcp' ) . '</a></p></div>';
	}

	/** The notice's link: turns maintenance mode off and returns to where the administrator was. */
	public static function handle_off_link() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to change maintenance mode.', 'royal-mcp' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::OFF_ACTION );
		self::save( false, '' );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	public static function handles( $name ) {
		return self::TOOL === $name;
	}

	public static function get_tools() {
		return [ self::schema() ];
	}

	public static function state() {
		$s = get_option( self::OPTION, [] );
		$s = is_array( $s ) ? $s : [];
		return [
			'enabled' => ! empty( $s['enabled'] ),
			'message' => isset( $s['message'] ) && is_string( $s['message'] ) ? $s['message'] : '',
			'since'   => ! empty( $s['enabled'] ) && ! empty( $s['since'] ) ? gmdate( 'c', (int) $s['since'] ) : null,
		];
	}

	/** The 503 page for visitors. */
	public static function maybe_show() {
		$s = self::state();
		if ( ! $s['enabled'] || current_user_can( 'manage_options' ) || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		nocache_headers();
		header( 'Retry-After: 600' );
		wp_die(
			'<h1>' . esc_html( get_bloginfo( 'name' ) ) . '</h1><p>' . esc_html( '' !== $s['message'] ? $s['message'] : __( 'This site is down for a short maintenance and will be back soon.', 'royal-mcp' ) ) . '</p>',
			esc_html__( 'Maintenance', 'royal-mcp' ),
			[ 'response' => 503 ]
		);
	}

	/** Clears the page caches of the cache plugins that are active; returns the ones cleared. */
	private static function purge_page_caches() {
		$done = [];
		$try  = static function ( $name, callable $fn ) use ( &$done ) {
			$level = ob_get_level();
			ob_start();
			try {
				$fn();
				$done[] = $name;
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		};
		if ( is_callable( [ '\\ForgeCache_Cache', 'clear_all_cache_static' ] ) ) {
			$try( 'forgecache', static function () { \ForgeCache_Cache::clear_all_cache_static(); } );
		}
		if ( defined( 'LSCWP_V' ) || class_exists( '\\LiteSpeed\\Core' ) ) {
			$try( 'litespeed', static function () { do_action( 'litespeed_purge_all' ); } );
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			$try( 'w3tc', static function () { w3tc_flush_all(); } );
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			$try( 'wp_rocket', static function () { rocket_clean_domain(); } );
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			$try( 'wp_super_cache', static function () { wp_cache_clear_cache(); } );
		}
		return $done;
	}

	private static function save( $on, $message ) {
		update_option( self::OPTION, [ 'enabled' => (bool) $on, 'message' => (string) $message, 'since' => $on ? time() : null, 'by' => get_current_user_id() ], true );
		return self::purge_page_caches();
	}

	/**
	 * true, 1, "1", "true" are yes; false, 0, "0", "false" are no; null when absent or anything else.
	 */
	private static function yes_no( array $args, $key ) {
		if ( ! array_key_exists( $key, $args ) ) {
			return null;
		}
		$v = is_string( $args[ $key ] ) ? strtolower( trim( $args[ $key ] ) ) : $args[ $key ];
		if ( in_array( $v, [ true, 1, '1', 'true' ], true ) ) {
			return true;
		}
		return in_array( $v, [ false, 0, '0', 'false' ], true ) ? false : null;
	}

	public static function execute_tool( $name, $args ) {
		if ( ! self::handles( $name ) ) {
			throw new \Exception( 'Unknown tool.' );
		}
		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to change maintenance mode.' );
		}
		$args    = is_array( $args ) ? $args : [];
		$unknown = array_diff( array_map( 'strval', array_keys( $args ) ), [ 'enabled', 'message', 'dry_run' ] );
		$on      = self::yes_no( $args, 'enabled' );
		$dry     = array_key_exists( 'dry_run', $args ) ? self::yes_no( $args, 'dry_run' ) : true;
		if ( $unknown || null === $on || null === $dry || ( isset( $args['message'] ) && ( ! is_string( $args['message'] ) || mb_strlen( $args['message'] ) > 300 ) ) ) {
			return Envelope::error( 'invalid_args', 'enabled must be true or false; message a text of at most 300 characters; dry_run true or false (true when left out).' );
		}
		$message = isset( $args['message'] ) ? trim( sanitize_text_field( $args['message'] ) ) : '';
		$before  = self::state();
		// Turning it off keeps no message.
		$message = $on ? $message : '';
		$out     = [ 'enabled' => $on, 'message' => $message, 'before' => $before ];
		if ( $before['enabled'] === $on && $before['message'] === $message ) {
			return Envelope::success( sprintf( 'Maintenance mode is already %s; nothing changed.', $on ? 'on with that message' : 'off' ), [ 'dry_run' => $dry, 'changed' => false ] + $out );
		}
		$what = $on ? ( $before['enabled'] ? 'kept on with a new message' : 'turned on' ) : 'turned off';
		if ( $dry ) {
			return Envelope::success( sprintf( 'Preview: maintenance mode would be %s. While it is on the front end answers 503 for everyone but signed-in administrators; wp-admin, the sign-in page and Royal MCP keep working, and administrators get a notice in wp-admin with a link that turns it off. Nothing was changed; call again with dry_run false to apply.', $what ), [ 'dry_run' => true, 'changed' => false ] + $out );
		}
		$purged = self::save( $on, $message );
		$undo   = Undo_Store::store( [
			'op'           => self::TOOL,
			'summary'      => sprintf( 'Put maintenance mode back %s', $before['enabled'] ? 'on' : 'off' ),
			'target'       => [ 'option' => self::OPTION ],
			'pre_op_state' => [ 'before' => [ 'enabled' => $before['enabled'], 'message' => $before['message'] ], 'after' => [ 'enabled' => $on, 'message' => $message ] ],
		] );
		return Envelope::success(
			sprintf( 'Maintenance mode %s%s. A cache at the host or a CDN can go on serving stored pages until they expire.', $what, $on ? ': visitors get a 503 page' : '' ),
			[ 'dry_run' => false, 'changed' => true ] + $out + [ 'page_caches_cleared' => $purged ],
			$undo ?: null
		);
	}

	public static function undo( array $snapshot, $force = false ) {
		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to change maintenance mode.' );
		}
		$before = (array) ( $snapshot['pre_op_state']['before'] ?? [] );
		$after  = (array) ( $snapshot['pre_op_state']['after'] ?? [] );
		if ( ! array_key_exists( 'enabled', $before ) || ! array_key_exists( 'enabled', $after ) ) {
			throw new \Exception( 'Undo snapshot missing the earlier state.' );
		}
		$now  = self::state();
		$same = static function ( array $a, array $b ) { return ! empty( $a['enabled'] ) === ! empty( $b['enabled'] ) && (string) ( $a['message'] ?? '' ) === (string) ( $b['message'] ?? '' ); };
		if ( $same( $now, $before ) ) {
			return Envelope::success( 'Maintenance mode is already as it was; nothing to undo.', [ 'undone' => false, 'op' => self::TOOL, 'reason' => 'already_restored', 'enabled' => $now['enabled'] ] );
		}
		if ( ! $same( $now, $after ) && ! $force ) {
			throw new Tool_Refusal( 'edited_since_write', 'Maintenance mode was changed again after this write, so the undo left it as it is. Pass force: true to put the earlier state back anyway.' );
		}
		$purged = self::save( ! empty( $before['enabled'] ), ! empty( $before['enabled'] ) ? (string) ( $before['message'] ?? '' ) : '' );
		return Envelope::success( sprintf( 'Maintenance mode is %s again.', ! empty( $before['enabled'] ) ? 'on' : 'off' ), [ 'undone' => true, 'op' => self::TOOL, 'enabled' => ! empty( $before['enabled'] ), 'page_caches_cleared' => $purged ] );
	}

	private static function schema() {
		return [
			'name'        => self::TOOL,
			'description' => 'Turn maintenance mode on or off. While it is on, everything served on the front end answers with a 503 page carrying your message (up to 300 characters; a default sentence when left out): pages, feeds, the sitemap and robots.txt, and also for signed-in users who are not administrators, and for services that call front-end addresses. Signed-in administrators, wp-admin, the sign-in page, the REST API and Royal MCP keep working. It can be turned off from here, or by any administrator from the notice wp-admin shows while it is on; in read-only or drafts-only mode this tool cannot change it, so use that notice. It only has an effect while Royal MCP is active. dry_run is true unless you pass false: the first call previews. Applying clears the page caches of ForgeCache, LiteSpeed Cache, W3 Total Cache, WP Rocket and WP Super Cache when they are active; a cache at the host or a CDN can go on serving stored pages until they expire. Returns an undo token; undo restores the earlier state and is refused with edited_since_write when the mode was changed again since, unless forced. Not available in read-only mode. Requires manage_options.',
			'inputSchema' => [
				'type'                 => 'object',
				'required'             => [ 'enabled' ],
				'properties'           => [
					'enabled' => [ 'type' => 'boolean', 'description' => 'true turns it on, false turns it off.' ],
					'message' => [ 'type' => 'string', 'maxLength' => 300, 'description' => 'What visitors read while it is on.' ],
					'dry_run' => [ 'type' => 'boolean', 'description' => 'Default true (preview). Pass false to apply.' ],
				],
				'additionalProperties' => false,
			],
		];
	}
}
