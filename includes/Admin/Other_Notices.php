<?php
/**
 * On Royal MCP's own screens, notices from other plugins (and core) are printed
 * in one collapsed row instead of above the page, so they stay one click away
 * without pushing the screen down. Royal MCP's own notices stay where they are.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Other_Notices {

	/** Other plugins' notice callbacks taken off this screen, to print in one collapsed row. */
	private static $held = [];

	public static function register() {
		add_action( 'current_screen', [ __CLASS__, 'on_screen' ] );
	}

	/** Whether another Royal MCP component already does this on these screens. */
	private static function handled_elsewhere() {
		return class_exists( '\\Royal_MCP_Pro\\Admin\\Dashboard_Page', false );
	}

	public static function on_screen( $screen ) {
		$id   = is_object( $screen ) ? (string) $screen->id : '';
		$ours = 'toplevel_page_' . Menu_Layout::PARENT === $id || 0 === strpos( $id, Menu_Layout::sub_page_prefix() ) || 'admin_page_' . Help_Page::SLUG === $id;
		if ( ! $ours || self::handled_elsewhere() ) {
			return;
		}
		// Runs before the notice hooks fire, after every notice has hooked in.
		add_action( 'in_admin_header', [ __CLASS__, 'hold' ], PHP_INT_MAX );
		// The Dashboard prints the row itself, below its header; other screens get it as one notice under the heading.
		if ( 'toplevel_page_' . Dashboard_Page::PAGE_SLUG !== (string) $screen->id ) {
			add_action( 'admin_notices', [ __CLASS__, 'print_tray' ], 1 );
		}
	}

	public static function hold() {
		global $wp_filter;
		// Only the hooks WordPress fires on this screen; a callback also hooked
		// to the network or user variant would otherwise print twice.
		if ( is_network_admin() ) {
			$hooks = [ 'network_admin_notices', 'all_admin_notices' ];
		} elseif ( is_user_admin() ) {
			$hooks = [ 'user_admin_notices', 'all_admin_notices' ];
		} else {
			$hooks = [ 'admin_notices', 'all_admin_notices' ];
		}
		foreach ( $hooks as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					$fn = $cb['function'];
					if ( self::is_own( $fn ) ) {
						continue;
					}
					self::$held[] = [ $fn, (int) ( $cb['accepted_args'] ?? 1 ) ];
					remove_action( $hook, $fn, $priority );
				}
			}
		}
	}

	private static function is_own( $fn ) {
		if ( is_array( $fn ) ) {
			$name = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
		} elseif ( is_string( $fn ) ) {
			$name = $fn;
		} else {
			return false;
		}
		return 0 === strpos( ltrim( $name, '\\' ), 'Royal_MCP\\' );
	}

	/** The collapsed row as a single notice, so WordPress places it under the page heading. */
	public static function print_tray() {
		ob_start();
		self::render();
		$tray = (string) ob_get_clean();
		if ( '' !== $tray ) {
			echo '<div class="notice royal-mcp-notice-tray" style="padding:0;border:0;box-shadow:none;background:transparent;">' . $tray . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by render()
		}
	}

	/**
	 * Run the held callbacks and print what they output inside one closed
	 * details row. Each notice gets the `inline` class so WordPress's admin
	 * script leaves it in the row instead of moving it under the heading.
	 */
	public static function render() {
		$parts  = [];
		$extras = '';
		foreach ( self::$held as $held ) {
			list( $fn, $accepted ) = $held;
			if ( ! is_callable( $fn ) ) {
				continue;
			}
			$level = ob_get_level();
			ob_start();
			try {
				// The same argument do_action( 'admin_notices' ) hands each callback.
				call_user_func_array( $fn, array_slice( [ '' ], 0, max( 0, $accepted ) ) );
			} catch ( \Throwable $e ) {
				// Another plugin's notice failing must not take the screen down.
				unset( $e );
			}
			$out = '';
			while ( ob_get_level() > $level ) {
				$out = (string) ob_get_clean() . $out;
			}
			$out = trim( $out );
			if ( '' === $out ) {
				continue;
			}
			// Only output that holds a notice is folded; scripts, styles and pop-ups print as they would.
			if ( preg_match( '/<div\b[^>]*?\sclass=(["\'])[^"\']*(?<![\w-])(notice|updated|error)(?![\w-])/i', $out ) ) {
				$parts[] = $out;
			} else {
				$extras .= $out . "\n";
			}
		}
		self::$held = [];
		if ( '' !== $extras ) {
			echo $extras; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- other plugins' own output, printed as they would print it
		}
		if ( ! $parts ) {
			return;
		}
		$html = preg_replace_callback(
			'/(<div\b[^>]*?\sclass=)(["\'])([^"\']*)\2/i',
			static function ( $m ) {
				return preg_match( '/(^|\s)(notice|updated|error)(\s|$)/', $m[3] ) && ! preg_match( '/(^|\s)inline(\s|$)/', $m[3] )
					? $m[1] . $m[2] . $m[3] . ' inline' . $m[2]
					: $m[0];
			},
			implode( "\n", $parts )
		);
		$has_error = (bool) preg_match( '/<div\b[^>]*?\sclass=(["\'])(?=[^"\']*(?<![\w-])(notice|updated)(?![\w-]))[^"\']*(?<![\w-])(notice-error|error)(?![\w-])/i', $html );
		$label     = sprintf(
			/* translators: %d: number of notices */
			_n( '%d notice from other plugins', '%d notices from other plugins', count( $parts ), 'royal-mcp' ),
			count( $parts )
		);
		// Inline styles: the row shows on every Royal MCP screen, with or without its own stylesheet.
		echo '<details class="royal-mcp-other-notices' . ( $has_error ? ' has-error' : '' ) . '" style="margin:8px 0 16px;background:#fff;border:1px solid rgba(44,44,44,0.1);border-radius:4px;">'
			. '<summary style="padding:8px 16px;cursor:pointer;color:#4A4A4A;">'
			. ( $has_error ? '<span aria-hidden="true" style="display:inline-block;width:8px;height:8px;margin-right:8px;border-radius:50%;background:#ef4444;vertical-align:middle;"></span>' : '' )
			. esc_html( $label ) . '</summary><div class="royal-mcp-other-notices-body" style="padding:0 16px 8px;">';
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- other plugins' own notice markup, printed as they would print it above the page
		echo '</div></details>';
	}
}
