<?php
/**
 * Builder_Safety — portfolio-wide guard for write tools touching a post
 * that a human editor is actively holding open.
 *
 * The signal is WordPress core's _edit_lock postmeta: written by
 * wp_set_post_lock() when an editor opens a post, refreshed every
 * AUTOSAVE_INTERVAL seconds while the editor stays open, read back by
 * wp_check_post_lock(). Every WordPress editing UI participates — the
 * block editor, the classic editor, Divi's Visual Builder, Elementor's
 * editor, Beaver Builder, Bricks, Oxygen, and any future editor that
 * respects the core lock.
 *
 * This helper deliberately lives in shared includes rather than under
 * any single integration file. A write-collision guard that only fires
 * on Divi (or only on Elementor) would leave the same class of failure
 * uncovered on every other builder — the mechanism is core-WordPress,
 * not vendor.
 *
 * Two intended use paths for callers:
 *
 *   1. Passive probe (read tools). Include the return value in the
 *      response envelope so an agent planning multi-step writes can
 *      see whether a human is currently editing the target.
 *
 *   2. Active guard (write tools). Check active === true and refuse
 *      the write unless force === true. Refuse-response should include
 *      the full return value so the caller can decide whether to
 *      retry after the lock times out.
 *
 * Usage:
 *
 *     use Royal_MCP\MCP\Support\Builder_Safety;
 *
 *     $session = Builder_Safety::detect_active_editor_session($post_id);
 *     if ($session['active'] && empty($args['force'])) {
 *         return Envelope::error(
 *             'builder_session_active',
 *             'Editor session is open on this post. Close the editor or pass force:true.',
 *             $session
 *         );
 *     }
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Builder_Safety {

	/**
	 * Report whether an editor session is currently open on the given post.
	 *
	 * "Active" is defined by WordPress core's own semantic: the lock
	 * timestamp is within the wp_check_post_lock_window (defaults to
	 * AUTOSAVE_INTERVAL * 2, typically 150 seconds). Beyond that window
	 * the editor is either closed or has crashed without cleanup, and
	 * the post is safe to write.
	 *
	 * The _edit_lock meta stores the format "<unix_time>:<user_id>". We
	 * parse it directly (rather than only calling wp_check_post_lock)
	 * so we can surface the timestamp and user id to the caller — the
	 * WP core function only returns the locking user id.
	 *
	 * @param int $post_id
	 * @return array{
	 *   active: bool,
	 *   since: int|null,
	 *   source: string|null,
	 *   editor_user_id: int|null,
	 *   window_seconds: int
	 * }
	 */
	public static function detect_active_editor_session( $post_id ) {
		$post_id = (int) $post_id;
		$window  = self::lock_window_seconds();

		$result = [
			'active'         => false,
			'since'          => null,
			'source'         => null,
			'editor_user_id' => null,
			'window_seconds' => $window,
		];

		if ( $post_id <= 0 ) {
			return $result;
		}

		$lock = get_post_meta( $post_id, '_edit_lock', true );
		if ( ! is_string( $lock ) || '' === $lock ) {
			return $result;
		}

		$parts = explode( ':', $lock );
		if ( count( $parts ) < 2 ) {
			return $result;
		}

		$since  = (int) $parts[0];
		$editor = (int) $parts[1];

		if ( $since <= 0 || $editor <= 0 ) {
			return $result;
		}

		// A stale lock (timestamp older than the check-window) is treated
		// as not-active. This mirrors wp_check_post_lock's own logic and
		// prevents a crashed editor session from indefinitely blocking
		// writes on a post the user no longer has open.
		if ( ( time() - $since ) > $window ) {
			return $result;
		}

		$result['active']         = true;
		$result['since']          = $since;
		$result['source']         = 'edit_lock';
		$result['editor_user_id'] = $editor;

		return $result;
	}

	/**
	 * The number of seconds after which a lock is considered stale.
	 *
	 * Mirrors WordPress core: defaults to AUTOSAVE_INTERVAL * 2 and can
	 * be adjusted via the wp_check_post_lock_window filter. Callers get
	 * the effective window in the detection response so they can decide
	 * how long to wait before retrying.
	 */
	public static function lock_window_seconds() {
		$default = defined( 'AUTOSAVE_INTERVAL' ) ? ( 2 * AUTOSAVE_INTERVAL ) : 150;
		/**
		 * WordPress core filter — respected here so third-party sites
		 * that have customized the lock window get consistent behavior
		 * across the WP editor and every builder's write guard.
		 */
		return (int) apply_filters( 'wp_check_post_lock_window', $default );
	}
}
