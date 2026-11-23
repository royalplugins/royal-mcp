<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Tool_Refusal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugins and themes: update, install from WordPress.org, activate,
 * deactivate, delete, switch theme, and the auto-update switches.
 *
 * Every tool previews unless dry_run is false. Activating, deactivating and
 * switching themes load the home page afterwards and put things back when it
 * stops loading. An update is WordPress's own update: going back to the
 * earlier version means restoring a backup.
 */
class PluginsThemes {

	/** Tool => [ handler, undo handler or null, the WordPress capability it needs ]. */
	const TOOLS = [
		'wp_update_plugin'           => [ 'handle_update_plugin', null, 'update_plugins' ],
		'wp_update_theme'            => [ 'handle_update_theme', null, 'update_themes' ],
		'wp_install_plugin'          => [ 'handle_install_plugin', 'undo_install_plugin', 'install_plugins' ],
		'wp_install_theme'           => [ 'handle_install_theme', 'undo_install_theme', 'install_themes' ],
		'wp_activate_plugin'         => [ 'handle_activate_plugin', 'undo_activate_plugin', 'activate_plugins' ],
		'wp_deactivate_plugin'       => [ 'handle_deactivate_plugin', 'undo_deactivate_plugin', 'activate_plugins' ],
		'wp_delete_plugin'           => [ 'handle_delete_plugin', null, 'delete_plugins' ],
		'wp_switch_theme'            => [ 'handle_switch_theme', 'undo_switch_theme', 'switch_themes' ],
		'wp_delete_theme'            => [ 'handle_delete_theme', null, 'delete_themes' ],
		'wp_set_plugin_auto_update'  => [ 'handle_plugin_auto_update', 'undo_auto_update', 'update_plugins' ],
		'wp_set_theme_auto_update'   => [ 'handle_theme_auto_update', 'undo_auto_update', 'update_themes' ],
	];

	/** Royal MCP's own plugin folder: it does not update, deactivate or delete itself while answering. */
	const SELF_SLUGS = [ 'royal-mcp' ];

	/** Plugin folders treated as security plugins: touching the active one needs force. */
	const SECURITY_FOLDERS = [ 'guardpress', 'guardpress-lite', 'wordfence', 'better-wp-security', 'ithemes-security-pro', 'sucuri-scanner', 'all-in-one-wp-security-and-firewall', 'wp-cerber', 'ninjafirewall', 'defender-security', 'wp-defender', 'wp-simple-firewall', 'malcare-security', 'bulletproof-security', 'jetpack-protect', 'security-ninja', 'wp-security-audit-log', 'royal-ai-firewall' ];

	public static function handles( $name ) {
		return is_string( $name ) && isset( self::TOOLS[ $name ] );
	}

	public static function capability( $name ) {
		return self::handles( $name ) ? self::TOOLS[ $name ][2] : 'manage_options';
	}

	public static function get_tools() {
		return array_values( self::schemas() );
	}

	public static function execute_tool( $name, $args ) {
		if ( ! self::handles( $name ) ) {
			throw new \Exception( 'Unknown plugin or theme tool: ' . esc_html( is_string( $name ) ? $name : '' ) );
		}
		$args    = is_array( $args ) ? $args : [];
		$known   = (array) ( self::schemas()[ $name ]['inputSchema']['properties'] ?? [] );
		$unknown = array_diff( array_map( 'strval', array_keys( $args ) ), array_keys( $known ) );
		if ( $unknown ) {
			return Envelope::error( 'invalid_args', 'This tool does not take: ' . implode( ', ', array_slice( array_map( 'sanitize_key', $unknown ), 0, 10 ) ) . '.' );
		}
		// Each handler checks its own WordPress capability first and says why when file changes are switched off.
		return call_user_func( [ __CLASS__, self::TOOLS[ $name ][0] ], $args );
	}

	/** Undo of the tools that return a token. */
	public static function undo( array $snapshot, $force = false ) {
		$op = (string) ( $snapshot['op'] ?? '' );
		if ( ! self::handles( $op ) || null === self::TOOLS[ $op ][1] ) {
			throw new \Exception( 'Unknown plugin or theme undo.' );
		}
		if ( ! current_user_can( self::TOOLS[ $op ][2] ) ) {
			throw new \Exception( 'You do not have permission to undo this.' );
		}
		$payload = (array) ( $snapshot['pre_op_state']['payload'] ?? [] );
		$result  = (array) call_user_func( [ __CLASS__, self::TOOLS[ $op ][1] ], $payload, [ 'force' => (bool) $force ] );
		$summary = (string) ( $result['restored_state_summary'] ?? 'Done.' );
		$reasons = array_column( (array) ( $result['skipped'] ?? [] ), 'reason' );
		unset( $result['restored_state_summary'], $result['remaining_payload'] );
		// Not put back: the token stays usable, unless there was nothing left to put back.
		if ( isset( $result['restored'] ) && false === $result['restored'] && ! array_intersect( $reasons, [ 'already_restored', 'already_removed' ] ) ) {
			throw new Tool_Refusal( $reasons ? sanitize_key( (string) $reasons[0] ) : 'undo_failed', sanitize_text_field( $summary ), array_diff_key( $result, [ 'restored' => 1 ] ) );
		}
		return Envelope::success( $summary, [ 'undone' => ! isset( $result['restored'] ) || false !== $result['restored'], 'op' => $op ] + $result );
	}

	/** The wp-admin functions these tools use, loaded only when a tool runs. */
	private static function admin_includes() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	private static function is_dry_run( array $args ) {
		return ! ( array_key_exists( 'dry_run', $args ) && in_array( $args['dry_run'], [ false, 0, '0', 'false' ], true ) );
	}

	/** Refusal when the caller lacks $cap (with the file-change reason when that is why), or null. */
	private static function cap_refusal( $cap, $tool ) {
		if ( current_user_can( $cap ) ) {
			return null;
		}
		$off = self::file_mods_off_for_admin();
		return $off ? $off : Envelope::error( 'insufficient_caps', sprintf( '%s requires the %s capability.', $tool, $cap ) );
	}

	/** An installed plugin's file from the arguments (exactly as WordPress lists it), or ''. */
	private static function plugin_arg( array $args ) {
		self::admin_includes();
		$file = isset( $args['plugin'] ) && is_string( $args['plugin'] ) ? trim( $args['plugin'] ) : '';
		return ( '' !== $file && array_key_exists( $file, get_plugins() ) ) ? $file : '';
	}

	/** An installed theme's folder name from the arguments, or ''. */
	private static function theme_arg( array $args ) {
		self::admin_includes();
		$slug = isset( $args['theme'] ) && is_string( $args['theme'] ) ? trim( $args['theme'] ) : '';
		return ( '' !== $slug && array_key_exists( $slug, wp_get_themes( [ 'errors' => null ] ) ) ) ? $slug : '';
	}

	/** A WordPress.org slug from the arguments, or ''. */
	private static function slug_arg( array $args ) {
		$slug = isset( $args['slug'] ) && is_string( $args['slug'] ) ? trim( $args['slug'] ) : '';
		return ( strlen( $slug ) <= 100 && preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ) ? $slug : '';
	}

	const NETWORK_ONLY_MESSAGE      = 'This plugin can only be activated for the whole network; activate it from Network Admin. Nothing was changed.';
	const THEME_NOT_ALLOWED_MESSAGE = 'This theme is not enabled for this site; a network administrator enables themes under Network Admin, Themes. Nothing was changed.';

	/** Whether this is a network-only plugin on a multisite subsite, where WordPress only activates it network-wide. */
	private static function network_only_on_site( $file ) {
		return is_multisite() && ! is_network_admin() && function_exists( 'is_network_only_plugin' ) && is_network_only_plugin( $file );
	}

	/** Whether other active plugins list this plugin under "Requires Plugins". */
	private static function has_active_dependents( $file ) {
		if ( ! class_exists( 'WP_Plugin_Dependencies' ) || ! method_exists( 'WP_Plugin_Dependencies', 'has_active_dependents' ) ) {
			return false;
		}
		// WordPress reads the dependency headers only on its Plugins screens; read them here too.
		\WP_Plugin_Dependencies::initialize();
		return \WP_Plugin_Dependencies::has_active_dependents( $file );
	}

	/** Whether a plugin file belongs to Royal MCP itself. */
	private static function is_self( $file ) {
		return in_array( strtok( (string) $file, '/' ), self::SELF_SLUGS, true );
	}

	private static function plugin_facts( $file ) {
		self::admin_includes();
		$all  = get_plugins();
		$data = $all[ $file ] ?? [];
		return [
			'plugin'  => $file,
			'name'    => (string) ( $data['Name'] ?? '' ),
			'version' => (string) ( $data['Version'] ?? '' ),
			'active'  => is_plugin_active( $file ),
		];
	}

	/** Files and bytes under a folder (for delete previews). */
	private static function folder_size( $dir ) {
		$files = 0;
		$bytes = 0;
		if ( is_dir( $dir ) ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( $f->isFile() ) {
					$files++;
					$bytes += (int) $f->getSize();
				}
			}
		}
		return [ 'files' => $files, 'bytes' => $bytes ];
	}

	/** Stores what an undo needs and returns the token details for the reply, or null. */
	private static function token( $tool, array $payload ) {
		$stored = \Royal_MCP\MCP\Undo_Store::store( [
			'op'           => (string) $tool,
			'summary'      => (string) $tool,
			'target'       => array_intersect_key( $payload, [ 'plugin' => 1, 'theme' => 1 ] ),
			'pre_op_state' => [ 'payload' => $payload ],
		] );
		return is_array( $stored ) && ! empty( $stored['token'] ) ? $stored : null;
	}

	/** WordPress.org facts for a plugin or theme slug, or a WP_Error. */
	private static function wporg_info( $kind, $slug ) {
		$fields = [ 'sections' => false, 'versions' => false, 'screenshots' => false, 'banners' => false, 'icons' => false, 'reviews' => false, 'requires' => true, 'requires_php' => true, 'tested' => true, 'downloadlink' => true ];
		$info   = 'plugin' === $kind
			? plugins_api( 'plugin_information', [ 'slug' => $slug, 'fields' => $fields ] )
			: themes_api( 'theme_information', [ 'slug' => $slug, 'fields' => $fields ] );
		if ( is_wp_error( $info ) || ! is_object( $info ) ) {
			return new \WP_Error( 'not_on_wordpress_org', sprintf( 'WordPress.org has no %s with the slug %s.', $kind, $slug ) );
		}
		$link = (string) ( $info->download_link ?? '' );
		if ( 'downloads.wordpress.org' !== wp_parse_url( $link, PHP_URL_HOST ) || 'https' !== wp_parse_url( $link, PHP_URL_SCHEME ) ) {
			return new \WP_Error( 'not_on_wordpress_org', sprintf( 'The %s %s does not download from WordPress.org.', $kind, $slug ) );
		}
		return $info;
	}

	private static function handle_install_plugin( array $args ) {
		self::admin_includes();
		$refused = self::cap_refusal( 'install_plugins', 'wp_install_plugin' );
		if ( $refused ) {
			return $refused;
		}
		$slug = self::slug_arg( $args );
		if ( '' === $slug ) {
			return Envelope::error( 'invalid_args', 'slug must be a WordPress.org plugin slug (lowercase letters, digits and hyphens).' );
		}
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( strtok( $file, '/' ) === $slug ) {
				return Envelope::error( 'already_installed', sprintf( '%s is already installed (%s).', $slug, $file ), [ 'plugin' => $file ] );
			}
		}
		$info = self::wporg_info( 'plugin', $slug );
		if ( is_wp_error( $info ) ) {
			return Envelope::error( $info->get_error_code(), $info->get_error_message() );
		}
		$activate = isset( $args['activate'] ) && in_array( $args['activate'], [ true, 1, '1', 'true' ], true );
		$facts    = array_merge( [ 'slug' => $slug, 'name' => (string) ( $info->name ?? $slug ), 'version' => (string) ( $info->version ?? '' ), 'activate' => $activate ], self::requirements( $info->requires_php ?? null, $info->requires ?? null ) );
		if ( ! $facts['php_ok'] || ! $facts['wp_ok'] ) {
			return Envelope::error( 'requirements_not_met', sprintf( '%s needs PHP %s and WordPress %s.', $slug, $facts['requires_php'] ?? 'any', $facts['requires_wp'] ?? 'any' ), $facts );
		}
		if ( self::is_dry_run( $args ) ) {
			return Envelope::success( sprintf( '[dry-run] %s %s would be installed from WordPress.org%s.', $facts['name'], $facts['version'], $activate ? ' and activated' : '' ), array_merge( [ 'dry_run' => true ], $facts ) );
		}
		$refused = self::file_mods_refusal( 'capability_update_core' );
		if ( $refused ) {
			return $refused;
		}
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( (string) $info->download_link, [ 'clear_update_cache' => false ] );
		wp_clean_plugins_cache( false );
		$file = (string) $upgrader->plugin_info();
		if ( true !== $result || '' === $file || ! array_key_exists( $file, get_plugins() ) ) {
			$why = is_wp_error( $result ) ? $result->get_error_message() : self::skin_errors( $skin );
			return Envelope::error( 'install_failed', 'WordPress could not install the plugin' . ( '' !== $why ? ': ' . $why : '.' ), [ 'slug' => $slug ] );
		}
		$out = array_merge( [ 'dry_run' => false, 'installed' => true, 'plugin' => $file, 'activated' => false ], $facts );
		if ( $activate ) {
			$act = self::activate_checked( $file );
			$out = array_merge( $out, $act );
		}
		return Envelope::success( sprintf( 'Installed %s %s%s.', $facts['name'], $facts['version'], $out['activated'] ? ' and activated it' : '' ), $out, self::token( 'wp_install_plugin', [ 'plugin' => $file, 'activated' => $out['activated'] ] ) );
	}

	private static function undo_install_plugin( array $payload, array $token_row ) {
		self::admin_includes();
		$file = (string) ( $payload['plugin'] ?? '' );
		if ( ! array_key_exists( $file, get_plugins() ) ) {
			return [ 'restored_state_summary' => 'The plugin was already removed.', 'restored' => false, 'skipped' => [ [ 'plugin' => $file, 'reason' => 'already_removed' ] ] ];
		}
		if ( is_plugin_active( $file ) && empty( $payload['activated'] ) && empty( $token_row['force'] ) ) {
			return [
				'restored_state_summary' => 'The plugin was activated after it was installed; left in place (pass force: true to remove it anyway).',
				'restored'               => false,
				'skipped'                => [ [ 'plugin' => $file, 'reason' => 'edited_since_write' ] ],
			];
		}
		$security = self::undo_refusal( $file, $payload, $token_row );
		if ( null !== $security ) {
			return $security;
		}
		if ( self::file_mods_refusal( 'capability_update_core' ) ) {
			return [ 'restored_state_summary' => 'Files cannot be changed on this site; the plugin was left installed.', 'restored' => false ];
		}
		$in_use = self::folder_in_use( $file );
		if ( null !== $in_use ) {
			return [ 'restored_state_summary' => $in_use[1] . ' The plugin was left installed.', 'restored' => false, 'skipped' => [ [ 'plugin' => $file, 'reason' => $in_use[0], 'active_plugins' => $in_use[2] ] ] ];
		}
		if ( is_plugin_active( $file ) ) {
			if ( self::has_active_dependents( $file ) ) {
				return [ 'restored_state_summary' => 'Other active plugins need this plugin now; deactivate them first. Nothing was changed.', 'restored' => false, 'skipped' => [ [ 'plugin' => $file, 'reason' => 'has_active_dependents' ] ] ];
			}
			$off = self::deactivate_checked( $file );
			if ( isset( $off['error'] ) ) {
				return array_merge( $off, [ 'restored_state_summary' => (string) $off['error'] . ' The plugin was left installed.', 'restored' => false, 'skipped' => [ [ 'plugin' => $file, 'reason' => ! empty( $off['revert_failed'] ) ? 'revert_failed' : ( $off['rolled_back'] ? 'deactivation_broke_site' : 'deactivation_failed' ) ] ] ] );
			}
		}
		$deleted = delete_plugins( [ $file ] );
		return [ 'restored_state_summary' => true === $deleted ? sprintf( 'Removed %s.', $file ) : sprintf( 'Could not remove %s.', $file ), 'restored' => true === $deleted ];
	}

	private static function handle_install_theme( array $args ) {
		self::admin_includes();
		$refused = self::cap_refusal( 'install_themes', 'wp_install_theme' );
		if ( $refused ) {
			return $refused;
		}
		$slug = self::slug_arg( $args );
		if ( '' === $slug ) {
			return Envelope::error( 'invalid_args', 'slug must be a WordPress.org theme slug (lowercase letters, digits and hyphens).' );
		}
		if ( wp_get_theme( $slug )->exists() ) {
			return Envelope::error( 'already_installed', sprintf( 'The theme %s is already installed.', $slug ), [ 'theme' => $slug ] );
		}
		$info = self::wporg_info( 'theme', $slug );
		if ( is_wp_error( $info ) ) {
			return Envelope::error( $info->get_error_code(), $info->get_error_message() );
		}
		$facts = array_merge( [ 'slug' => $slug, 'name' => (string) ( $info->name ?? $slug ), 'version' => (string) ( $info->version ?? '' ) ], self::requirements( $info->requires_php ?? null, $info->requires ?? null ) );
		if ( ! $facts['php_ok'] || ! $facts['wp_ok'] ) {
			return Envelope::error( 'requirements_not_met', sprintf( 'This theme needs PHP %s and WordPress %s; this site does not meet that.', $facts['requires_php'] ?? 'any', $facts['requires_wp'] ?? 'any' ), $facts );
		}
		if ( self::is_dry_run( $args ) ) {
			return Envelope::success( sprintf( '[dry-run] The theme %s %s would be installed from WordPress.org (not activated).', $facts['name'], $facts['version'] ), array_merge( [ 'dry_run' => true ], $facts ) );
		}
		$refused = self::file_mods_refusal( 'capability_update_core' );
		if ( $refused ) {
			return $refused;
		}
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$result   = $upgrader->install( (string) $info->download_link, [ 'clear_update_cache' => false ] );
		wp_clean_themes_cache( false );
		if ( true !== $result || ! wp_get_theme( $slug )->exists() ) {
			$why = is_wp_error( $result ) ? $result->get_error_message() : self::skin_errors( $skin );
			return Envelope::error( 'install_failed', 'WordPress could not install the theme' . ( '' !== $why ? ': ' . $why : '.' ), [ 'slug' => $slug ] );
		}
		return Envelope::success( sprintf( 'Installed the theme %s %s (not activated).', $facts['name'], $facts['version'] ), array_merge( [ 'dry_run' => false, 'installed' => true, 'theme' => $slug ], $facts ), self::token( 'wp_install_theme', [ 'theme' => $slug, 'written_state_fp' => self::theme_state_fingerprint( $slug ) ] ) );
	}

	private static function undo_install_theme( array $payload, array $token_row ) {
		self::admin_includes();
		$slug = (string) ( $payload['theme'] ?? '' );
		if ( ! wp_get_theme( $slug )->exists() ) {
			return [ 'restored_state_summary' => 'The theme was already removed.', 'restored' => false, 'skipped' => [ [ 'theme' => $slug, 'reason' => 'already_removed' ] ] ];
		}
		if ( in_array( $slug, [ get_stylesheet(), get_template() ], true ) ) {
			return [ 'restored_state_summary' => 'The theme is now in use; it was left installed.', 'restored' => false, 'skipped' => [ [ 'theme' => $slug, 'reason' => 'theme_in_use' ] ] ];
		}
		// Another site of the network, or an installed child theme, may use it: refused even with force.
		$elsewhere = self::theme_used_elsewhere( $slug );
		if ( null !== $elsewhere ) {
			return [ 'restored_state_summary' => $elsewhere, 'restored' => false, 'skipped' => [ [ 'theme' => $slug, 'reason' => 'theme_in_use' ] ] ];
		}
		if ( empty( $token_row['force'] ) ) {
			$state = (string) ( $payload['written_state_fp'] ?? '' );
			if ( 'too_large' === $state ) {
				return [ 'restored_state_summary' => sprintf( 'The theme %s has too many files to check for changes since it was installed. It was left installed; undo again with force: true to remove it anyway.', $slug ), 'restored' => false, 'skipped' => [ [ 'theme' => $slug, 'reason' => 'edited_since_write' ] ] ];
			}
			if ( '' !== $state ) {
				$now = self::theme_state_fingerprint( $slug );
				if ( '' !== $now && ! hash_equals( $state, $now ) ) {
					return [ 'restored_state_summary' => sprintf( 'The theme %s changed after it was installed (its files were updated or edited, or its Customizer or site-editor settings were saved). It was left installed; undo again with force: true to remove it anyway.', $slug ), 'restored' => false, 'skipped' => [ [ 'theme' => $slug, 'reason' => 'edited_since_write' ] ] ];
				}
			}
		}
		if ( self::file_mods_refusal( 'capability_update_core' ) ) {
			return [ 'restored_state_summary' => 'Files cannot be changed on this site; the theme was left installed.', 'restored' => false ];
		}
		// Links in the folder refuse the removal even with force.
		$links = self::folder_link_refusal( wp_get_theme( $slug )->get_stylesheet_directory(), 'theme' );
		if ( null !== $links ) {
			return [ 'restored_state_summary' => $links[1], 'restored' => false, 'skipped' => [ [ 'theme' => $slug, 'reason' => $links[0] ] ] ];
		}
		$deleted = delete_theme( $slug );
		return [ 'restored_state_summary' => true === $deleted ? sprintf( 'Removed the theme %s.', $slug ) : sprintf( 'Could not remove the theme %s.', $slug ), 'restored' => true === $deleted ];
	}

	/**
	 * Activate, then load the home page; deactivate again when it stops loading.
	 *
	 * @return array activated, home_status, home_ok, rolled_back, activation_error?
	 */
	private static function activate_checked( $file ) {
		$network = is_multisite() && is_network_admin();
		if ( self::network_only_on_site( $file ) ) {
			return [ 'activated' => false, 'activation_error' => self::NETWORK_ONLY_MESSAGE, 'rolled_back' => false ];
		}
		$before  = self::home_check();
		$level   = ob_get_level();
		try {
			$result = activate_plugin( $file, '', $network );
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			return [ 'activated' => false, 'activation_error' => 'The plugin stopped with an error while it was loading, so it was not activated: ' . sanitize_text_field( $e->getMessage() ), 'rolled_back' => false ];
		}
		if ( is_wp_error( $result ) ) {
			return [ 'activated' => false, 'activation_error' => $result->get_error_message(), 'rolled_back' => false ];
		}
		if ( ! $before['home_ok'] ) {
			return array_merge( [ 'activated' => true, 'rolled_back' => false ], self::unchecked( $before ) );
		}
		$home = self::home_check();
		if ( ! $home['home_ok'] ) {
			// Deactivated the usual way, so the plugin's own deactivation routine runs.
			deactivate_plugins( $file, false, $network );
			$still = is_plugin_active( $file );
			return array_merge( [
				'activated'        => false,
				'rolled_back'      => ! $still,
				'revert_failed'    => $still,
				'activation_error' => $still ? 'The home page stopped loading after activation, and the plugin could not be deactivated again; it is still active.' : 'The home page stopped loading after activation, so the plugin was deactivated again.',
			], $home );
		}
		return array_merge( [ 'activated' => true, 'rolled_back' => false ], $home );
	}

	/** Deactivate, then load the home page; activate again when it stops loading. error is set when the plugin was not left deactivated cleanly. */
	private static function deactivate_checked( $file ) {
		$before = self::home_check();
		deactivate_plugins( $file );
		if ( is_plugin_active( $file ) ) {
			return [ 'deactivated' => false, 'rolled_back' => false, 'error' => 'WordPress did not deactivate the plugin.' ];
		}
		if ( ! $before['home_ok'] ) {
			return array_merge( [ 'deactivated' => true, 'rolled_back' => false ], self::unchecked( $before ) );
		}
		$home = self::home_check();
		if ( ! $home['home_ok'] ) {
			$level = ob_get_level();
			try {
				// Activated the usual way, so the plugin's own activation routine runs.
				activate_plugin( $file );
			} catch ( \Throwable $e ) {
				while ( ob_get_level() > $level ) {
					ob_end_clean();
				}
			}
			$back = is_plugin_active( $file );
			return array_merge( [
				'deactivated'   => ! $back,
				'rolled_back'   => $back,
				'revert_failed' => ! $back,
				'error'         => $back ? 'The home page stopped loading without this plugin, so it was activated again.' : 'The home page stopped loading without this plugin, and it could not be activated again; it is now inactive.',
			], $home );
		}
		return array_merge( [ 'deactivated' => true, 'rolled_back' => false ], $home );
	}

	/** Switch theme, then load the home page; switch back when it stops loading. error is set when the switch did not hold. */
	private static function switch_checked( $slug ) {
		if ( ! wp_get_theme( $slug )->is_allowed() ) {
			return [ 'switched' => false, 'rolled_back' => false, 'error' => self::THEME_NOT_ALLOWED_MESSAGE ];
		}
		$from   = get_stylesheet();
		$before = self::home_check();
		switch_theme( $slug );
		if ( get_stylesheet() !== $slug ) {
			return [ 'switched' => false, 'rolled_back' => false, 'error' => 'WordPress did not switch the theme.' ];
		}
		if ( ! $before['home_ok'] ) {
			return array_merge( [ 'switched' => true, 'rolled_back' => false ], self::unchecked( $before ) );
		}
		$home = self::home_check();
		if ( ! $home['home_ok'] ) {
			switch_theme( $from );
			$back = get_stylesheet() === $from;
			return array_merge( [
				'switched'      => ! $back,
				'rolled_back'   => $back,
				'revert_failed' => ! $back,
				'error'         => $back ? sprintf( 'The home page stopped loading with %s, so %s was switched back.', $slug, $from ) : sprintf( 'The home page stopped loading with %s, and switching back to %s did not work; %s is active.', $slug, $from, get_stylesheet() ),
			], $home );
		}
		return array_merge( [ 'switched' => true, 'rolled_back' => false ], $home );
	}

	/** Marks a result as unchecked: a home page that fails on the first load cannot show what a plugin or theme change did. */
	private static function unchecked( array $before ) {
		return array_merge( $before, [ 'home_ok' => false, 'home_check_skipped' => 'already_failing' ] );
	}

	/** How the home page check went, for a reply text. */
	private static function home_words( array $r ) {
		if ( isset( $r['home_check_skipped'] ) ) {
			return sprintf( 'the home page was already not loading before the change (%s), so the change could not be checked against it', isset( $r['home_status'] ) ? 'status ' . (int) $r['home_status'] : 'no answer' );
		}
		return 'the home page loads';
	}

	/** Most sites of a network whose active plugins are read before a folder is deleted. */
	const MAX_NETWORK_SITES_CHECKED = 1000;

	/**
	 * Active plugin files other than $file on this site, active for the network,
	 * and (multisite) active on any other site of the network, where $file
	 * itself counts too. Null when the network has too many sites to check.
	 */
	private static function active_plugin_files_besides( $file ) {
		$others = array_diff( array_map( 'strval', (array) get_option( 'active_plugins', [] ) ), [ $file ] );
		if ( is_multisite() ) {
			$others = array_merge( $others, array_diff( array_map( 'strval', array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) ), [ $file ] ) );
			$site_ids = get_sites( [ 'fields' => 'ids', 'number' => self::MAX_NETWORK_SITES_CHECKED + 1 ] );
			if ( count( $site_ids ) > self::MAX_NETWORK_SITES_CHECKED ) {
				return null;
			}
			foreach ( $site_ids as $site_id ) {
				if ( (int) $site_id === get_current_blog_id() ) {
					continue;
				}
				$others = array_merge( $others, array_map( 'strval', (array) get_blog_option( (int) $site_id, 'active_plugins', [] ) ) );
			}
		}
		return array_values( array_unique( array_filter( $others, static function ( $v ) { return is_scalar( $v ) && '' !== (string) $v; } ) ) );
	}

	/** A resolved path with forward slashes and no trailing slash, or '' when it does not exist. */
	private static function real_path( $path ) {
		$real = realpath( $path );
		return false === $real ? '' : untrailingslashit( wp_normalize_path( $real ) );
	}

	/** Whether $inner is $outer or sits somewhere below it. */
	private static function path_within( $inner, $outer ) {
		return '' !== $inner && '' !== $outer && ( $inner === $outer || 0 === strpos( $inner . '/', $outer . '/' ) );
	}

	/** Most files and folders folder_link_refusal() walks before it refuses the folder as too large to check. */
	const LINK_WALK_MAX_ENTRIES = 20000;

	/**
	 * Why a plugin or theme folder cannot be deleted because of links in it, as [ code, message ],
	 * or null. Deleting a folder removes what its links point at too, which can sit outside the
	 * folder, so any link refuses it, as does a folder too large or unreadable to walk in full.
	 *
	 * @param string $dir  Folder to walk (links are not followed).
	 * @param string $what 'plugin' or 'theme', for the code and message.
	 * @return array|null
	 */
	private static function folder_link_refusal( $dir, $what ) {
		$dir = untrailingslashit( wp_normalize_path( (string) $dir ) );
		if ( '' === $dir ) {
			return null;
		}
		if ( is_link( $dir ) ) {
			return [ $what . '_folder_symlinked', sprintf( 'This %s\'s folder is a link to a folder elsewhere on the server; it was not removed. Remove it on the server instead.', $what ) ];
		}
		$real_root = realpath( $dir );
		if ( false === $real_root ) {
			return null;
		}
		$real_root = untrailingslashit( wp_normalize_path( $real_root ) );
		$fold_case = '\\' === DIRECTORY_SEPARATOR;
		$same_path = static function ( $a, $b ) use ( $fold_case ) {
			return $fold_case ? 0 === strcasecmp( $a, $b ) : $a === $b;
		};
		// The folder itself resolving outside its parent is a link the platform may not report as one (a Windows junction).
		$real_parent = realpath( dirname( $dir ) );
		if ( false !== $real_parent && ! $same_path( $real_root, untrailingslashit( wp_normalize_path( $real_parent ) ) . '/' . basename( $dir ) ) ) {
			return [ $what . '_folder_symlinked', sprintf( 'This %s\'s folder is a link to a folder elsewhere on the server; it was not removed. Remove it on the server instead.', $what ) ];
		}
		if ( ! is_dir( $dir ) ) {
			return null;
		}
		$count = 0;
		try {
			$iter = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $iter as $info ) {
				if ( ++$count > self::LINK_WALK_MAX_ENTRIES ) {
					return [ $what . '_folder_too_large', sprintf( 'This %s\'s folder holds more than %d files and folders, too many to check for links before deleting it; it was not removed. Remove it on the server instead.', $what, self::LINK_WALK_MAX_ENTRIES ) ];
				}
				$rel    = ltrim( substr( wp_normalize_path( $info->getPathname() ), strlen( $dir ) ), '/' );
				$linked = $info->isLink();
				if ( ! $linked && ! $info->isFile() ) {
					// An entry that resolves to somewhere else is a link the platform does not report as one (a Windows junction).
					$found  = realpath( $info->getPathname() );
					$found  = false === $found ? '' : untrailingslashit( wp_normalize_path( $found ) );
					$linked = ! $same_path( $found, $real_root . '/' . $rel );
				}
				if ( $linked ) {
					return [ $what . '_folder_has_links', sprintf( 'This %s\'s folder holds a link (%s) to files elsewhere on the server, which deleting the folder could remove too; it was not removed. Remove it on the server instead.', $what, $rel ) ];
				}
			}
		} catch ( \Throwable $e ) {
			return [ $what . '_folder_unreadable', sprintf( 'Part of this %s\'s folder could not be read, so it could not be checked for links before deleting it; it was not removed. Remove it on the server instead.', $what ) ];
		}
		return null;
	}

	/**
	 * Why deleting this plugin's files would take files an active plugin uses,
	 * as [ code, message, active plugin files ], or null. Deleting a plugin in a
	 * folder deletes the whole folder; the folder is refused when it is a
	 * symlink, or when it is, holds, or sits inside the folder of a plugin
	 * active on this site, the network, or any site of the network.
	 */
	private static function folder_in_use( $file ) {
		$active = self::active_plugin_files_besides( $file );
		if ( null === $active ) {
			return [ 'too_many_sites', sprintf( 'This network has more than %d sites, too many to check which of them use this plugin\'s files. Delete it from Network Admin.', self::MAX_NETWORK_SITES_CHECKED ), [] ];
		}
		$in_dir = false !== strpos( (string) $file, '/' );
		$path   = WP_PLUGIN_DIR . '/' . ( $in_dir ? strtok( (string) $file, '/' ) : (string) $file );
		if ( is_link( $path ) ) {
			return [ 'plugin_folder_symlinked', 'This plugin\'s folder is a link to a folder elsewhere on the server; it was not removed. Remove it on the server instead.', [] ];
		}
		if ( $in_dir ) {
			$links = self::folder_link_refusal( $path, 'plugin' );
			if ( null !== $links ) {
				return [ $links[0], $links[1], [] ];
			}
		}
		$real  = self::real_path( $path );
		$using = [];
		foreach ( $active as $other ) {
			if ( $other === $file && ! $in_dir ) {
				$using[] = $other;
				continue;
			}
			if ( false === strpos( $other, '/' ) ) {
				// A single-file plugin: only its own file matters.
				if ( self::path_within( self::real_path( WP_PLUGIN_DIR . '/' . $other ), $real ) ) {
					$using[] = $other;
				}
				continue;
			}
			$other_real = self::real_path( WP_PLUGIN_DIR . '/' . strtok( $other, '/' ) );
			if ( $in_dir && ( self::path_within( $other_real, $real ) || self::path_within( $real, $other_real ) ) ) {
				$using[] = $other;
			}
		}
		if ( $using ) {
			return [ 'plugin_folder_in_use', sprintf( 'Removing this plugin deletes %s, and active plugins use those files (%s).', $in_dir ? 'its whole folder' : 'its file', implode( ', ', $using ) ), $using ];
		}
		return null;
	}

	/** Most files and bytes theme_state_fingerprint() reads before it gives up. */
	const THEME_FP_MAX_FILES = 2000;
	const THEME_FP_MAX_BYTES = 20971520;

	/**
	 * Fingerprint of everything that changes when someone works on an installed
	 * theme: the path, size and modified time of each of its files, the contents
	 * of style.css, functions.php, theme.json and the block templates and parts,
	 * whether its saved Customizer settings exist, and how many site-editor
	 * templates, parts and global styles are saved for it. 'too_large' when the
	 * theme has more files or bytes than are read; '' when it is not installed.
	 */
	private static function theme_state_fingerprint( $slug ) {
		global $wpdb;
		wp_clean_themes_cache( false );
		$slug  = (string) $slug;
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return '';
		}
		$dir = untrailingslashit( wp_normalize_path( $theme->get_stylesheet_directory() ) );
		if ( ! is_dir( $dir ) ) {
			return '';
		}
		clearstatcache();
		$content_dirs = [ 'templates/', 'parts/', 'block-templates/', 'block-template-parts/' ];
		$content_root = [ 'style.css', 'functions.php', 'theme.json' ];
		$files        = [];
		$count        = 0;
		$bytes        = 0;
		try {
			$iter = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $iter as $info ) {
				$path = wp_normalize_path( $info->getPathname() );
				$rel  = ltrim( substr( $path, strlen( $dir ) ), '/' );
				if ( $info->isLink() ) {
					$files[ $rel ] = 'link:' . (string) @readlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable link still counts as a change.
					continue;
				}
				if ( ! $info->isFile() ) {
					continue;
				}
				$count++;
				$size   = (int) $info->getSize();
				$bytes += $size;
				if ( $count > self::THEME_FP_MAX_FILES || $bytes > self::THEME_FP_MAX_BYTES ) {
					return 'too_large';
				}
				$entry = $size . ':' . (int) $info->getMTime();
				$hash  = in_array( $rel, $content_root, true );
				foreach ( $content_dirs as $cd ) {
					$hash = $hash || 0 === strpos( $rel, $cd );
				}
				if ( $hash ) {
					$entry .= ':' . ( is_readable( $path ) ? (string) md5_file( $path ) : '' );
				}
				$files[ $rel ] = $entry;
			}
		} catch ( \Exception $e ) {
			return 'too_large';
		}
		ksort( $files, SORT_STRING );
		$mods_exist = null !== get_option( 'theme_mods_' . $slug, null );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read at undo time, must not come from a cache.
		$site_posts = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'wp_theme' AND t.slug = %s AND p.post_type IN ('wp_template', 'wp_template_part', 'wp_global_styles')",
			$slug
		) );
		return md5( (string) wp_json_encode( [ (string) $theme->get( 'Version' ), $files, $mods_exist, $site_posts ] ) );
	}

	/**
	 * Why removing this theme would break a site, or null: a site of the
	 * network uses it as its theme or parent theme, or an installed theme is
	 * built on it. A network with more sites than are checked is refused.
	 */
	private static function theme_used_elsewhere( $slug ) {
		$slug = (string) $slug;
		if ( is_multisite() ) {
			$site_ids = get_sites( [ 'fields' => 'ids', 'number' => self::MAX_NETWORK_SITES_CHECKED + 1 ] );
			if ( count( $site_ids ) > self::MAX_NETWORK_SITES_CHECKED ) {
				return sprintf( 'This network has more than %d sites, too many to check which of them use the theme %s. Delete it from Network Admin.', self::MAX_NETWORK_SITES_CHECKED, $slug );
			}
			foreach ( $site_ids as $site_id ) {
				$site_id = (int) $site_id;
				if ( $slug === (string) get_blog_option( $site_id, 'stylesheet' ) || $slug === (string) get_blog_option( $site_id, 'template' ) ) {
					return sprintf( 'Site %d of this network uses the theme %s. Switch that site to another theme first, then run this undo again.', $site_id, $slug );
				}
			}
		}
		$children = [];
		foreach ( wp_get_themes( [ 'errors' => null ] ) as $stylesheet => $theme ) {
			if ( (string) $stylesheet !== $slug && $slug === (string) $theme->get_template() ) {
				$children[] = (string) $stylesheet;
			}
		}
		if ( $children ) {
			return sprintf( 'The installed theme(s) %s use %s as their parent theme. Delete those first, then run this undo again.', implode( ', ', $children ), $slug );
		}
		return null;
	}

	private static function handle_activate_plugin( array $args ) {
		self::admin_includes();
		$refused = self::cap_refusal( 'activate_plugins', 'wp_activate_plugin' );
		if ( $refused ) {
			return $refused;
		}
		$file = self::plugin_arg( $args );
		if ( '' === $file ) {
			return Envelope::error( 'plugin_not_found', 'plugin must be an installed plugin file as wp_get_plugins lists it, e.g. "akismet/akismet.php".' );
		}
		$facts = self::plugin_facts( $file );
		if ( $facts['active'] ) {
			return Envelope::success( sprintf( '%s is already active; nothing changed.', $facts['name'] ), array_merge( $facts, [ 'undo' => null, 'undo_reason' => 'nothing_changed' ] ) );
		}
		if ( self::network_only_on_site( $file ) ) {
			return Envelope::error( 'network_only_plugin', self::NETWORK_ONLY_MESSAGE, $facts );
		}
		$valid = validate_plugin_requirements( $file );
		if ( is_wp_error( $valid ) ) {
			return Envelope::error( 'requirements_not_met', wp_strip_all_tags( $valid->get_error_message() ), $facts );
		}
		if ( self::is_dry_run( $args ) ) {
			return Envelope::success( sprintf( '[dry-run] %s %s would be activated, then the home page checked.', $facts['name'], $facts['version'] ), array_merge( [ 'dry_run' => true ], $facts ) );
		}
		$act = self::activate_checked( $file );
		$out = array_merge( [ 'dry_run' => false ], $facts, $act, [ 'active' => is_plugin_active( $file ) ] );
		if ( ! $act['activated'] ) {
			return Envelope::error( ! empty( $act['revert_failed'] ) ? 'revert_failed' : ( $act['rolled_back'] ? 'activation_broke_site' : 'activation_failed' ), (string) $act['activation_error'], $out );
		}
		// The tool list a client holds was made before this plugin was active.
		$out['tools_note'] = 'If Royal MCP has tools for this plugin they are available now: request the tool list again, or call them through execute_tool.';
		return Envelope::success( sprintf( 'Activated %s; %s.', $facts['name'], self::home_words( $act ) ), $out, self::token( 'wp_activate_plugin', [ 'plugin' => $file ] ) );
	}

	private static function undo_activate_plugin( array $payload, array $token_row ) {
		self::admin_includes();
		$file = (string) ( $payload['plugin'] ?? '' );
		if ( ! array_key_exists( $file, get_plugins() ) || ! is_plugin_active( $file ) ) {
			return [ 'restored_state_summary' => 'The plugin is already inactive.', 'restored' => false, 'skipped' => [ [ 'plugin' => $file, 'reason' => 'already_restored' ] ] ];
		}
		$security = self::undo_refusal( $file, $payload, $token_row );
		if ( null !== $security ) {
			return $security;
		}
		if ( is_multisite() && is_plugin_active_for_network( $file ) ) {
			return [ 'restored_state_summary' => 'The plugin is now active for the whole network; deactivate it from Network Admin.', 'restored' => false, 'skipped' => [ [ 'plugin' => $file, 'reason' => 'network_active' ] ] ];
		}
		if ( self::has_active_dependents( $file ) ) {
			return [ 'restored_state_summary' => 'Other active plugins need this plugin now; deactivate them first. Nothing was changed.', 'restored' => false, 'skipped' => [ [ 'plugin' => $file, 'reason' => 'has_active_dependents' ] ] ];
		}
		$off = self::deactivate_checked( $file );
		if ( isset( $off['error'] ) ) {
			return array_merge( $off, [ 'restored_state_summary' => (string) $off['error'], 'restored' => false, 'skipped' => [ [ 'plugin' => $file, 'reason' => ! empty( $off['revert_failed'] ) ? 'revert_failed' : ( $off['rolled_back'] ? 'deactivation_broke_site' : 'deactivation_failed' ) ] ] ] );
		}
		return array_merge( $off, [ 'restored_state_summary' => sprintf( 'Deactivated %s; %s.', $file, self::home_words( $off ) ), 'restored' => true ] );
	}

	private static function handle_deactivate_plugin( array $args ) {
		self::admin_includes();
		$refused = self::cap_refusal( 'activate_plugins', 'wp_deactivate_plugin' );
		if ( $refused ) {
			return $refused;
		}
		$file = self::plugin_arg( $args );
		if ( '' === $file ) {
			return Envelope::error( 'plugin_not_found', 'plugin must be an installed plugin file as wp_get_plugins lists it.' );
		}
		if ( self::is_self( $file ) ) {
			return Envelope::error( 'would_disconnect', 'Deactivating Royal MCP would end this connection; do it from wp-admin.', [ 'plugin' => $file ] );
		}
		$facts = self::plugin_facts( $file );
		if ( ! $facts['active'] ) {
			return Envelope::success( sprintf( '%s is already inactive; nothing changed.', $facts['name'] ), array_merge( $facts, [ 'undo' => null, 'undo_reason' => 'nothing_changed' ] ) );
		}
		if ( is_multisite() && is_plugin_active_for_network( $file ) ) {
			return Envelope::error( 'network_active', 'This plugin is active for the whole network; deactivate it from Network Admin.', $facts );
		}
		if ( self::has_active_dependents( $file ) ) {
			return Envelope::error( 'has_active_dependents', 'Other active plugins need this plugin; deactivate them first.', $facts );
		}
		if ( self::is_security_plugin( $file ) && ! filter_var( $args['force'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
			return Envelope::error( 'security_plugin', sprintf( '%s is this site\'s security plugin: deactivating it switches off the site\'s protection. Nothing was changed. Pass force: true if the site owner asked for it.', $facts['name'] ), $facts );
		}
		if ( self::is_dry_run( $args ) ) {
			return Envelope::success( sprintf( '[dry-run] %s would be deactivated, then the home page checked.', $facts['name'] ), array_merge( [ 'dry_run' => true ], $facts ) );
		}
		$off = self::deactivate_checked( $file );
		$out = array_merge( [ 'dry_run' => false ], $facts, $off, [ 'active' => is_plugin_active( $file ) ] );
		if ( isset( $off['error'] ) ) {
			return Envelope::error( ! empty( $off['revert_failed'] ) ? 'revert_failed' : ( $off['rolled_back'] ? 'deactivation_broke_site' : 'deactivation_failed' ), (string) $off['error'], $out );
		}
		return Envelope::success( sprintf( 'Deactivated %s; %s.', $facts['name'], self::home_words( $off ) ), $out, self::token( 'wp_deactivate_plugin', [ 'plugin' => $file ] ) );
	}

	private static function undo_deactivate_plugin( array $payload, array $token_row ) {
		self::admin_includes();
		$file = (string) ( $payload['plugin'] ?? '' );
		if ( ! array_key_exists( $file, get_plugins() ) ) {
			return [ 'restored_state_summary' => 'The plugin is no longer installed; nothing restored.', 'restored' => false, 'skipped' => [ [ 'plugin' => $file, 'reason' => 'plugin_removed_since_write' ] ] ];
		}
		if ( is_plugin_active( $file ) ) {
			return [ 'restored_state_summary' => 'The plugin is already active.', 'restored' => false, 'skipped' => [ [ 'plugin' => $file, 'reason' => 'already_restored' ] ] ];
		}
		$act = self::activate_checked( $file );
		return [ 'restored_state_summary' => $act['activated'] ? sprintf( 'Activated %s again.', $file ) : (string) ( $act['activation_error'] ?? 'Could not activate it again.' ), 'restored' => $act['activated'] ];
	}

	private static function handle_delete_plugin( array $args ) {
		self::admin_includes();
		$refused = self::cap_refusal( 'delete_plugins', 'wp_delete_plugin' );
		if ( $refused ) {
			return $refused;
		}
		$file = self::plugin_arg( $args );
		if ( '' === $file ) {
			return Envelope::error( 'plugin_not_found', 'plugin must be an installed plugin file as wp_get_plugins lists it.' );
		}
		if ( self::is_self( $file ) ) {
			return Envelope::error( 'would_disconnect', 'Royal MCP cannot delete itself; do it from wp-admin.', [ 'plugin' => $file ] );
		}
		$facts = self::plugin_facts( $file );
		if ( $facts['active'] || is_plugin_active_for_network( $file ) ) {
			return Envelope::error( 'plugin_active', 'Deactivate the plugin before deleting it (wp_deactivate_plugin).', $facts );
		}
		$in_use = self::folder_in_use( $file );
		if ( null !== $in_use ) {
			return Envelope::error( $in_use[0], $in_use[1], array_merge( $facts, [ 'active_in_folder' => $in_use[2] ] ) );
		}
		$in_dir  = false !== strpos( $file, '/' );
		$path    = WP_PLUGIN_DIR . '/' . $file;
		$size    = $in_dir ? self::folder_size( WP_PLUGIN_DIR . '/' . dirname( $file ) ) : [ 'files' => 1, 'bytes' => is_file( $path ) ? (int) filesize( $path ) : 0 ];
		$shared  = $in_dir ? array_values( array_filter( array_keys( get_plugins() ), static function ( $other ) use ( $file ) {
			return $other !== $file && dirname( $other ) === dirname( $file );
		} ) ) : [];
		$preview = array_merge( $facts, $size, [ 'runs_uninstall' => is_uninstallable_plugin( $file ), 'also_deleted' => $shared ] );
		if ( self::is_dry_run( $args ) ) {
			return Envelope::success( sprintf( '[dry-run] %s would be deleted (%d files)%s.', $facts['name'], $preview['files'], $preview['runs_uninstall'] ? '; its uninstall routine runs and may remove its data' : '' ) . ( $shared ? ' The folder also holds ' . implode( ', ', $shared ) . ', deleted with it.' : '' ), array_merge( [ 'dry_run' => true ], $preview ) );
		}
		$refused = self::file_mods_refusal( 'capability_update_core' );
		if ( $refused ) {
			return $refused;
		}
		$deleted = delete_plugins( [ $file ] );
		wp_clean_plugins_cache( false );
		if ( true !== $deleted ) {
			return Envelope::error( 'delete_failed', 'WordPress could not delete the plugin' . ( is_wp_error( $deleted ) ? ': ' . $deleted->get_error_message() : '.' ), $preview );
		}
		return Envelope::success( sprintf( 'Deleted %s %s.', $facts['name'], $facts['version'] ), array_merge( [ 'dry_run' => false, 'deleted' => true ], $preview, [
			'undo'        => null,
			'undo_reason' => 'manual',
			'undo_note'   => sprintf( 'A deleted plugin cannot be put back automatically. Reinstall %s %s (wp_install_plugin for a WordPress.org plugin); data its uninstall routine removed comes back only from a backup.', $facts['name'], $facts['version'] ),
		] ) );
	}

	private static function theme_facts( \WP_Theme $theme ) {
		$parent = $theme->parent();
		$req    = self::requirements( $theme->get( 'RequiresPHP' ), $theme->get( 'RequiresWP' ) );
		return array_merge( [
			'theme'   => $theme->get_stylesheet(),
			'name'    => (string) $theme->get( 'Name' ),
			'version' => (string) $theme->get( 'Version' ),
			'parent'  => $parent ? $parent->get_stylesheet() : ( $theme->get_template() !== $theme->get_stylesheet() ? $theme->get_template() : null ),
			'broken'  => $theme->errors() ? $theme->errors()->get_error_message() : null,
		], $req );
	}

	private static function handle_switch_theme( array $args ) {
		self::admin_includes();
		$refused = self::cap_refusal( 'switch_themes', 'wp_switch_theme' );
		if ( $refused ) {
			return $refused;
		}
		$slug = self::theme_arg( $args );
		if ( '' === $slug ) {
			return Envelope::error( 'theme_not_found', 'theme must be an installed theme folder name as wp_get_themes lists it.' );
		}
		$theme = wp_get_theme( $slug );
		$facts = self::theme_facts( $theme );
		$from  = get_stylesheet();
		if ( $from === $slug ) {
			return Envelope::success( sprintf( '%s is already the active theme; nothing changed.', $facts['name'] ), array_merge( $facts, [ 'undo' => null, 'undo_reason' => 'nothing_changed' ] ) );
		}
		if ( null !== $facts['broken'] ) {
			return Envelope::error( 'theme_broken', 'WordPress reports this theme as broken: ' . $facts['broken'], $facts );
		}
		if ( ! $theme->is_allowed() ) {
			return Envelope::error( 'theme_not_allowed', self::THEME_NOT_ALLOWED_MESSAGE, $facts );
		}
		if ( ! $facts['php_ok'] || ! $facts['wp_ok'] ) {
			return Envelope::error( 'requirements_not_met', sprintf( 'This theme needs PHP %s and WordPress %s.', $facts['requires_php'] ?? 'any', $facts['requires_wp'] ?? 'any' ), $facts );
		}
		$facts['from'] = $from;
		if ( self::is_dry_run( $args ) ) {
			return Envelope::success( sprintf( '[dry-run] The active theme would change from %s to %s, then the home page checked.', $from, $slug ), array_merge( [ 'dry_run' => true ], $facts ) );
		}
		$sw  = self::switch_checked( $slug );
		$out = array_merge( [ 'dry_run' => false ], $facts, $sw, [ 'active_theme' => get_stylesheet() ] );
		if ( isset( $sw['error'] ) ) {
			return Envelope::error( ! empty( $sw['revert_failed'] ) ? 'revert_failed' : ( $sw['rolled_back'] ? 'switch_broke_site' : 'switch_failed' ), (string) $sw['error'], $out );
		}
		return Envelope::success( sprintf( 'Switched the theme from %s to %s; %s.', $from, $slug, self::home_words( $sw ) ), $out, self::token( 'wp_switch_theme', [ 'from' => $from, 'to' => $slug ] ) );
	}

	private static function undo_switch_theme( array $payload, array $token_row ) {
		$from = (string) ( $payload['from'] ?? '' );
		$to   = (string) ( $payload['to'] ?? '' );
		if ( get_stylesheet() === $from ) {
			return [ 'restored_state_summary' => sprintf( '%s is already active.', $from ), 'restored' => false, 'skipped' => [ [ 'theme' => $from, 'reason' => 'already_restored' ] ] ];
		}
		$theme = wp_get_theme( $from );
		if ( ! $theme->exists() ) {
			return [ 'restored_state_summary' => sprintf( '%s is no longer installed; nothing restored.', $from ), 'restored' => false, 'skipped' => [ [ 'theme' => $from, 'reason' => 'theme_removed_since_write' ] ] ];
		}
		if ( $theme->errors() ) {
			return [ 'restored_state_summary' => sprintf( 'WordPress reports %s as broken now (%s), so the site was left on %s.', $from, $theme->errors()->get_error_message(), get_stylesheet() ), 'restored' => false, 'skipped' => [ [ 'theme' => $from, 'reason' => 'theme_broken' ] ] ];
		}
		if ( get_stylesheet() !== $to && empty( $token_row['force'] ) ) {
			return [
				'restored_state_summary' => sprintf( 'The theme was changed again after the switch (now %s); left alone (pass force: true to switch back to %s anyway).', get_stylesheet(), $from ),
				'restored'               => false,
				'skipped'                => [ [ 'theme' => $from, 'reason' => 'edited_since_write' ] ],
			];
		}
		$sw = self::switch_checked( $from );
		if ( isset( $sw['error'] ) ) {
			return array_merge( $sw, [ 'restored_state_summary' => (string) $sw['error'], 'restored' => false, 'skipped' => [ [ 'theme' => $from, 'reason' => ! empty( $sw['revert_failed'] ) ? 'revert_failed' : ( $sw['rolled_back'] ? 'switch_broke_site' : 'switch_failed' ) ] ] ] );
		}
		return array_merge( $sw, [ 'restored_state_summary' => sprintf( 'Switched back to %s; %s.', $from, self::home_words( $sw ) ), 'restored' => true ] );
	}

	private static function handle_delete_theme( array $args ) {
		self::admin_includes();
		$refused = self::cap_refusal( 'delete_themes', 'wp_delete_theme' );
		if ( $refused ) {
			return $refused;
		}
		$slug = self::theme_arg( $args );
		if ( '' === $slug ) {
			return Envelope::error( 'theme_not_found', 'theme must be an installed theme folder name as wp_get_themes lists it.' );
		}
		$theme = wp_get_theme( $slug );
		$facts = self::theme_facts( $theme );
		if ( get_stylesheet() === $slug ) {
			return Envelope::error( 'theme_active', 'This is the active theme; switch to another theme first (wp_switch_theme).', $facts );
		}
		if ( get_template() === $slug ) {
			return Envelope::error( 'theme_is_active_parent', 'The active theme is a child of this theme; it cannot be deleted while that child is active.', $facts );
		}
		if ( is_multisite() ) {
			return Envelope::error( 'multisite_network_admin', 'On a network, delete themes from Network Admin.', $facts );
		}
		$links = self::folder_link_refusal( $theme->get_stylesheet_directory(), 'theme' );
		if ( null !== $links ) {
			return Envelope::error( $links[0], $links[1], $facts );
		}
		$preview = array_merge( $facts, self::folder_size( $theme->get_stylesheet_directory() ) );
		if ( self::is_dry_run( $args ) ) {
			return Envelope::success( sprintf( '[dry-run] The theme %s would be deleted (%d files).', $facts['name'], $preview['files'] ), array_merge( [ 'dry_run' => true ], $preview ) );
		}
		$refused = self::file_mods_refusal( 'capability_update_core' );
		if ( $refused ) {
			return $refused;
		}
		$deleted = delete_theme( $slug );
		wp_clean_themes_cache( false );
		if ( true !== $deleted ) {
			return Envelope::error( 'delete_failed', 'WordPress could not delete the theme' . ( is_wp_error( $deleted ) ? ': ' . $deleted->get_error_message() : '.' ), $preview );
		}
		return Envelope::success( sprintf( 'Deleted the theme %s %s.', $facts['name'], $facts['version'] ), array_merge( [ 'dry_run' => false, 'deleted' => true ], $preview, [
			'undo'        => null,
			'undo_reason' => 'manual',
			'undo_note'   => sprintf( 'A deleted theme cannot be put back automatically. Reinstall %s %s (wp_install_theme for a WordPress.org theme) or restore it from a backup.', $facts['name'], $facts['version'] ),
		] ) );
	}

	private static function set_auto_update( $kind, $item, array $args ) {
		$option = 'plugin' === $kind ? 'auto_update_plugins' : 'auto_update_themes';
		$on     = isset( $args['enabled'] ) ? $args['enabled'] : null;
		if ( in_array( $on, [ true, 1, '1', 'true' ], true ) ) {
			$on = true;
		} elseif ( in_array( $on, [ false, 0, '0', 'false' ], true ) ) {
			$on = false;
		} else {
			return Envelope::error( 'invalid_args', 'enabled must be true or false.' );
		}
		if ( ! wp_is_auto_update_enabled_for_type( $kind ) ) {
			return Envelope::error( 'auto_updates_unavailable', sprintf( 'WordPress auto-updates for %ss are switched off on this site (a constant or filter).', $kind ) );
		}
		$list   = (array) get_site_option( $option, [] );
		$before = in_array( $item, $list, true );
		$out    = [ $kind => $item, 'before' => $before, 'enabled' => $on ];
		if ( $before === $on ) {
			return Envelope::success( sprintf( 'Auto-updates for %s are already %s; nothing changed.', $item, $on ? 'on' : 'off' ), array_merge( $out, [ 'changed' => false, 'undo' => null, 'undo_reason' => 'nothing_changed' ] ) );
		}
		if ( 'plugin' === $kind && is_plugin_active( $item ) && self::is_security_plugin( $item ) && ! filter_var( $args['force'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
			return Envelope::error( 'security_plugin', sprintf( '%s is this site\'s active security plugin: a failed automatic update, or missing security updates, can leave the site unprotected. Nothing was changed. Pass force: true if the site owner agrees.', $item ) );
		}
		if ( self::is_dry_run( $args ) ) {
			return Envelope::success( sprintf( '[dry-run] Auto-updates for %s would be turned %s.', $item, $on ? 'on' : 'off' ), array_merge( [ 'dry_run' => true, 'changed' => true ], $out ) );
		}
		$list = $on ? array_values( array_unique( array_merge( $list, [ $item ] ) ) ) : array_values( array_diff( $list, [ $item ] ) );
		update_site_option( $option, $list );
		return Envelope::success( sprintf( 'Turned auto-updates %s for %s.', $on ? 'on' : 'off', $item ), array_merge( [ 'dry_run' => false, 'changed' => true ], $out ), self::token( 'plugin' === $kind ? 'wp_set_plugin_auto_update' : 'wp_set_theme_auto_update', [ 'kind' => $kind, 'item' => $item, 'before' => $before ] ) );
	}

	private static function handle_plugin_auto_update( array $args ) {
		self::admin_includes();
		$refused = self::cap_refusal( 'update_plugins', 'wp_set_plugin_auto_update' );
		if ( $refused ) {
			return $refused;
		}
		$file = self::plugin_arg( $args );
		return '' === $file ? Envelope::error( 'plugin_not_found', 'plugin must be an installed plugin file as wp_get_plugins lists it.' ) : self::set_auto_update( 'plugin', $file, $args );
	}

	private static function handle_theme_auto_update( array $args ) {
		self::admin_includes();
		$refused = self::cap_refusal( 'update_themes', 'wp_set_theme_auto_update' );
		if ( $refused ) {
			return $refused;
		}
		$slug = self::theme_arg( $args );
		return '' === $slug ? Envelope::error( 'theme_not_found', 'theme must be an installed theme folder name as wp_get_themes lists it.' ) : self::set_auto_update( 'theme', $slug, $args );
	}

	private static function undo_auto_update( array $payload, array $token_row ) {
		$kind   = 'theme' === ( $payload['kind'] ?? '' ) ? 'theme' : 'plugin';
		$option = 'plugin' === $kind ? 'auto_update_plugins' : 'auto_update_themes';
		$item   = (string) ( $payload['item'] ?? '' );
		$before = ! empty( $payload['before'] );
		$list   = (array) get_site_option( $option, [] );
		if ( in_array( $item, $list, true ) === $before ) {
			return [ 'restored_state_summary' => 'Auto-updates are already as they were.', 'restored' => false, 'skipped' => [ [ $kind => $item, 'reason' => 'already_restored' ] ] ];
		}
		if ( 'plugin' === $kind ) {
			$security = self::undo_refusal( $item, $payload, $token_row );
			if ( null !== $security ) {
				return $security;
			}
		}
		$list = $before ? array_values( array_unique( array_merge( $list, [ $item ] ) ) ) : array_values( array_diff( $list, [ $item ] ) );
		update_site_option( $option, $list );
		return [ 'restored_state_summary' => sprintf( 'Auto-updates for %s turned %s again.', $item, $before ? 'on' : 'off' ), 'restored' => true ];
	}

	/**
	 * With file changes switched off, WordPress takes the update capabilities
	 * away from everyone, so an administrator is told the actual reason.
	 */
	private static function file_mods_off_for_admin() {
		if ( ! wp_is_file_mod_allowed( 'capability_update_core' ) && current_user_can( 'manage_options' ) ) {
			return self::error( 'file_mods_disabled', 'This site does not allow plugin, theme or WordPress files to be changed (DISALLOW_FILE_MODS or a filter).' );
		}
		return null;
	}

	/** Why files cannot be changed on this site, or null. */
	private static function file_mods_refusal( $context ) {
		if ( ! wp_is_file_mod_allowed( $context ) ) {
			return self::error( 'file_mods_disabled', 'This site does not allow plugin, theme or WordPress files to be changed (DISALLOW_FILE_MODS or a filter).' );
		}
		ob_start();
		$creds = request_filesystem_credentials( '', '', false, false, null );
		ob_end_clean();
		if ( false === $creds || ! WP_Filesystem( $creds ) ) {
			return self::error( 'filesystem_credentials_required', 'WordPress needs FTP or SSH details to change files on this site, so it cannot update from here. Update from wp-admin, or set the filesystem method in wp-config.php.' );
		}
		return null;
	}

	/** Load the home page once: status code and whether it looks healthy. */
	private static function home_check() {
		$res = wp_remote_get( home_url( '/' ), [
			'timeout'     => 10,
			'redirection' => 3,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
		] );
		if ( is_wp_error( $res ) ) {
			return [ 'home_status' => null, 'home_ok' => false, 'home_error' => $res->get_error_message() ];
		}
		$code     = (int) wp_remote_retrieve_response_code( $res );
		$critical = false !== stripos( (string) wp_remote_retrieve_body( $res ), 'There has been a critical error on this website' );
		return [ 'home_status' => $code, 'home_ok' => $code >= 200 && $code < 400 && ! $critical ];
	}

	/** Error text an upgrader skin collected. */
	private static function skin_errors( $skin ) {
		$out = [];
		if ( is_object( $skin ) && method_exists( $skin, 'get_error_messages' ) ) {
			$msg = trim( (string) $skin->get_error_messages() );
			if ( '' !== $msg ) {
				$out[] = $msg;
			}
		}
		if ( is_object( $skin ) && isset( $skin->result ) && is_wp_error( $skin->result ) ) {
			$out[] = $skin->result->get_error_message();
		}
		$text = str_replace( '\\', '/', implode( ' ', array_unique( $out ) ) );
		foreach ( [ WP_CONTENT_DIR, ABSPATH ] as $root ) {
			$text = str_replace( untrailingslashit( str_replace( '\\', '/', $root ) ), '', $text );
		}
		return $text;
	}

	private static function requirements( $requires_php, $requires_wp ) {
		$requires_php = is_scalar( $requires_php ) && '' !== (string) $requires_php ? (string) $requires_php : null;
		$requires_wp  = is_scalar( $requires_wp ) && '' !== (string) $requires_wp ? (string) $requires_wp : null;
		return [
			'requires_php' => $requires_php,
			'requires_wp'  => $requires_wp,
			'php_ok'       => null === $requires_php || is_php_version_compatible( $requires_php ),
			'wp_ok'        => null === $requires_wp || is_wp_version_compatible( $requires_wp ),
		];
	}

	/** The reply for an item that has no automatic undo. */
	private static function manual_undo_fields( $previous ) {
		return [
			'undo'             => null,
			'undo_reason'      => 'manual',
			'previous_version' => (string) $previous,
			'undo_note'        => sprintf( 'This update cannot be undone automatically. To go back to %s, restore the backup taken before the update.', $previous ),
		];
	}

	private static function success( $text, array $struct, $undo = null ) {
		$out = Envelope::success( (string) $text, $struct );
		if ( is_array( $undo ) && ! empty( $undo['token'] ) ) {
			$out['undo'] = $undo;
		}
		return $out;
	}

	private static function error( $code, $message, array $extra = [] ) {
		return Envelope::error( (string) $code, (string) $message, $extra );
	}

	/** Whether a waiting plugin update comes from WordPress.org. */
	private static function is_wporg( $offer, $kind ) {
		$id      = is_object( $offer ) ? (string) ( $offer->id ?? '' ) : (string) ( $offer['id'] ?? '' );
		$package = is_object( $offer ) ? ( $offer->package ?? '' ) : ( $offer['package'] ?? '' );
		$url     = is_object( $offer ) ? '' : (string) ( $offer['url'] ?? '' );
		if ( is_string( $package ) && '' !== $package ) {
			return 'https' === wp_parse_url( $package, PHP_URL_SCHEME ) && 'downloads.wordpress.org' === wp_parse_url( $package, PHP_URL_HOST );
		}
		if ( 'plugin' === $kind && 0 === strpos( $id, 'w.org/plugins/' ) ) {
			return true;
		}
		if ( 'theme' === $kind && 0 === strpos( $url, 'https://wordpress.org/themes/' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * WordPress's list of waiting updates for plugins or themes. Outside wp-admin nothing rebuilds
	 * it once an upgrade has deleted it, so a missing list is checked again here (WordPress limits
	 * how often it asks WordPress.org).
	 */
	private static function waiting_list( $kind ) {
		$name    = 'plugin' === $kind ? 'update_plugins' : 'update_themes';
		$waiting = get_site_transient( $name );
		if ( ! is_object( $waiting ) || empty( $waiting->last_checked ) ) {
			self::recheck( $kind );
			$waiting = get_site_transient( $name );
		}
		return is_object( $waiting ) ? $waiting : null;
	}

	/** Ask WordPress to check for plugin or theme updates now. */
	private static function recheck( $kind ) {
		if ( 'plugin' === $kind && function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		} elseif ( 'theme' === $kind && function_exists( 'wp_update_themes' ) ) {
			wp_update_themes();
		}
	}

	/** Take an installed update off the waiting list and keep every other entry. */
	private static function mark_updated( $kind, $key, $version ) {
		$name    = 'plugin' === $kind ? 'update_plugins' : 'update_themes';
		$waiting = get_site_transient( $name );
		if ( ! is_object( $waiting ) ) {
			self::recheck( $kind );
			return;
		}
		if ( isset( $waiting->response ) && is_array( $waiting->response ) ) {
			unset( $waiting->response[ $key ] );
		}
		if ( isset( $waiting->checked ) && is_array( $waiting->checked ) ) {
			$waiting->checked[ $key ] = $version;
		}
		set_site_transient( $name, $waiting );
	}

	private static function handle_update_plugin( array $args ) {
		$mods = self::file_mods_off_for_admin();
		if ( null !== $mods ) {
			return $mods;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return self::error( 'insufficient_caps', 'update_plugins capability required.' );
		}
		self::admin_includes();
		$file = isset( $args['plugin'] ) && is_string( $args['plugin'] ) ? trim( $args['plugin'] ) : '';
		$all  = get_plugins();
		if ( '' === $file || ! isset( $all[ $file ] ) ) {
			return self::error( 'plugin_not_found', 'No installed plugin with that file. Use the file as wp_get_plugins lists it.' );
		}
		if ( in_array( dirname( $file ), self::SELF_SLUGS, true ) ) {
			return self::error( 'cannot_update_self', 'Royal MCP cannot update itself while answering the request. Update it from wp-admin.' );
		}
		if ( is_plugin_active( $file ) && self::is_security_plugin( $file ) && ! filter_var( $args['force'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
			return self::error( 'security_plugin', sprintf( '%s is this site\'s active security plugin: a failed update can leave the site unprotected. Nothing was changed. Take a backup, then pass force: true if the site owner asked for it.', (string) ( $all[ $file ]['Name'] ?? $file ) ), [ 'plugin' => $file ] );
		}
		$waiting = self::waiting_list( 'plugin' );
		$offer   = is_object( $waiting ) && isset( $waiting->response[ $file ] ) && is_object( $waiting->response[ $file ] ) ? $waiting->response[ $file ] : null;
		$from    = (string) ( $all[ $file ]['Version'] ?? '' );
		if ( null === $offer || empty( $offer->new_version ) ) {
			return self::error( 'no_update_available', 'WordPress has no update waiting for this plugin.', [ 'plugin' => $file, 'version' => $from ] );
		}
		$to     = (string) $offer->new_version;
		$wporg  = self::is_wporg( $offer, 'plugin' );
		$facts  = array_merge(
			[
				'plugin'       => $file,
				'from_version' => $from,
				'to_version'   => $to,
				'active'       => is_plugin_active( $file ),
				'source'       => $wporg ? 'wordpress.org' : 'other',
			],
			self::requirements( $offer->requires_php ?? null, $offer->requires ?? null )
		);
		if ( ! $facts['php_ok'] || ! $facts['wp_ok'] ) {
			return self::error( 'requirements_not_met', sprintf( 'Version %s needs %s; this site does not meet it.', $to, implode( ' and ', array_filter( [ $facts['php_ok'] ? null : 'PHP ' . $facts['requires_php'], $facts['wp_ok'] ? null : 'WordPress ' . $facts['requires_wp'] ] ) ) ), $facts );
		}
		$refusal = self::file_mods_refusal( 'capability_update_core' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		if ( self::is_dry_run( $args ) ) {
			return self::success( sprintf( '[dry-run] %s would update from %s to %s.', $file, $from, $to ), array_merge( [ 'dry_run' => true ], $facts ) );
		}

		$was_active = $facts['active'];
		$network    = is_multisite() && is_plugin_active_for_network( $file );
		$skin       = new \WP_Ajax_Upgrader_Skin();
		$upgrader   = new \Plugin_Upgrader( $skin );
		$upgrader->bulk_upgrade( [ $file ], [ 'clear_update_cache' => false ] );
		wp_clean_plugins_cache( false );
		$now = get_plugins();
		$got = (string) ( $now[ $file ]['Version'] ?? '' );
		if ( '' === $got || ( version_compare( $got, $to, '!=' ) && ! version_compare( $got, $from, '>' ) ) ) {
			$why = self::skin_errors( $skin );
			return self::error( 'update_failed', sprintf( 'The update to %s did not install%s. The plugin is at %s.', $to, '' !== $why ? ': ' . $why : '', '' !== $got ? $got : 'an unknown version' ), [ 'plugin' => $file, 'from_version' => $from, 'version_now' => $got ] );
		}
		self::mark_updated( 'plugin', $file, $got );
		if ( $was_active && ! is_plugin_active( $file ) ) {
			activate_plugin( $file, '', $network, true );
		}
		$check  = self::home_check();
		$struct = array_merge(
			[ 'dry_run' => false, 'updated' => true, 'plugin' => $file, 'from_version' => $from, 'to_version' => $got, 'active' => is_plugin_active( $file ), 'source' => $facts['source'] ],
			$check
		);
		$struct = array_merge( $struct, self::manual_undo_fields( $from ), version_compare( $got, $to, '!=' ) ? [ 'offered_version' => $to ] : [] );
		return self::success( sprintf( 'Updated %s from %s to %s; home page %s.', $file, $from, $got, $check['home_ok'] ? 'loads' : 'did not load cleanly' ), $struct );
	}

	private static function handle_update_theme( array $args ) {
		$mods = self::file_mods_off_for_admin();
		if ( null !== $mods ) {
			return $mods;
		}
		if ( ! current_user_can( 'update_themes' ) ) {
			return self::error( 'insufficient_caps', 'update_themes capability required.' );
		}
		self::admin_includes();
		$slug  = self::theme_arg( $args );
		$theme = '' !== $slug ? wp_get_theme( $slug ) : null;
		if ( ! $theme || ! $theme->exists() ) {
			return self::error( 'theme_not_found', 'No installed theme with that folder. Use the slug as wp_get_themes lists it.' );
		}
		$waiting = self::waiting_list( 'theme' );
		$offer   = is_object( $waiting ) && isset( $waiting->response[ $slug ] ) && is_array( $waiting->response[ $slug ] ) ? $waiting->response[ $slug ] : null;
		$from    = (string) $theme->get( 'Version' );
		if ( null === $offer || empty( $offer['new_version'] ) ) {
			return self::error( 'no_update_available', 'WordPress has no update waiting for this theme.', [ 'theme' => $slug, 'version' => $from ] );
		}
		$to    = (string) $offer['new_version'];
		$wporg = self::is_wporg( $offer, 'theme' );
		$facts = array_merge(
			[
				'theme'        => $slug,
				'from_version' => $from,
				'to_version'   => $to,
				'active'       => get_stylesheet() === $slug || get_template() === $slug,
				'source'       => $wporg ? 'wordpress.org' : 'other',
			],
			self::requirements( $offer['requires_php'] ?? null, $offer['requires'] ?? null )
		);
		if ( ! $facts['php_ok'] || ! $facts['wp_ok'] ) {
			return self::error( 'requirements_not_met', sprintf( 'Version %s needs %s; this site does not meet it.', $to, implode( ' and ', array_filter( [ $facts['php_ok'] ? null : 'PHP ' . $facts['requires_php'], $facts['wp_ok'] ? null : 'WordPress ' . $facts['requires_wp'] ] ) ) ), $facts );
		}
		$refusal = self::file_mods_refusal( 'capability_update_core' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		if ( self::is_dry_run( $args ) ) {
			return self::success( sprintf( '[dry-run] Theme %s would update from %s to %s.', $slug, $from, $to ), array_merge( [ 'dry_run' => true ], $facts ) );
		}
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$upgrader->bulk_upgrade( [ $slug ], [ 'clear_update_cache' => false ] );
		wp_clean_themes_cache( false );
		$got = (string) wp_get_theme( $slug )->get( 'Version' );
		if ( '' === $got || ( version_compare( $got, $to, '!=' ) && ! version_compare( $got, $from, '>' ) ) ) {
			$why = self::skin_errors( $skin );
			return self::error( 'update_failed', sprintf( 'The update to %s did not install%s. The theme is at %s.', $to, '' !== $why ? ': ' . $why : '', '' !== $got ? $got : 'an unknown version' ), [ 'theme' => $slug, 'from_version' => $from, 'version_now' => $got ] );
		}
		self::mark_updated( 'theme', $slug, $got );
		$check  = self::home_check();
		$struct = array_merge( [ 'dry_run' => false, 'updated' => true, 'theme' => $slug, 'from_version' => $from, 'to_version' => $got, 'active' => $facts['active'], 'source' => $facts['source'] ], $check );
		$struct = array_merge( $struct, self::manual_undo_fields( $from ), version_compare( $got, $to, '!=' ) ? [ 'offered_version' => $to ] : [] );
		return self::success( sprintf( 'Updated theme %s from %s to %s; home page %s.', $slug, $from, $got, $check['home_ok'] ? 'loads' : 'did not load cleanly' ), $struct );
	}

	/**
	 * Whether a plugin file belongs to a security plugin.
	 *
	 * @param string $file Plugin file, e.g. wordfence/wordfence.php.
	 * @return bool
	 */
	private static function is_security_plugin( $file ) {
		/**
		 * Plugin folders treated as security plugins by the plugin lifecycle tools.
		 *
		 * @param string[] $folders Plugin folder names.
		 */
		$folders = array_map( 'strtolower', (array) apply_filters( 'royal_mcp_security_plugin_folders', self::SECURITY_FOLDERS ) );
		return in_array( strtolower( dirname( (string) $file ) ), $folders, true );
	}

	/**
	 * An undo that would switch off, remove or replace the active security plugin, left
	 * undone unless forced. Null when the undo may go ahead.
	 *
	 * @param string $file      Plugin file.
	 * @param array  $payload   Undo payload.
	 * @param array  $token_row Undo token row (force).
	 * @return array|null
	 */
	private static function undo_refusal( $file, array $payload, array $token_row ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! empty( $token_row['force'] ) || ! is_plugin_active( (string) $file ) || ! self::is_security_plugin( $file ) ) {
			return null;
		}
		return [
			'restored_state_summary' => sprintf( '%s is the site\'s active security plugin; the undo would switch it off or replace it, so it was left as it is (pass force: true to undo anyway).', (string) $file ),
			'restored'               => false,
			'skipped'                => [ [ 'plugin' => (string) $file, 'reason' => 'security_plugin' ] ],
			'remaining_payload'      => array_merge( $payload, [ 'force_only' => true ] ),
		];
	}

	// ------------------------------------------------------------
	// Tool definitions
	// ------------------------------------------------------------

	private static function schemas() {
		$dry  = [ 'type' => 'boolean', 'description' => 'Default true: report what would happen and change nothing. Pass false to apply.' ];
		$pl   = [ 'type' => 'string', 'description' => 'Installed plugin file as wp_get_plugins lists it, e.g. "akismet/akismet.php".' ];
		$th   = [ 'type' => 'string', 'description' => 'Installed theme folder name as wp_get_themes lists it, e.g. "twentytwentyfive".' ];
		$tool = static function ( $name, $desc, array $props, array $req ) {
			return [ 'name' => $name, 'description' => $desc, 'inputSchema' => [ 'type' => 'object', 'required' => $req, 'properties' => $props, 'additionalProperties' => false ] ];
		};
		$out  = [
			$tool( 'wp_update_plugin', 'Update one plugin to the version WordPress has waiting (see update_available on wp_get_plugins). dry_run (default true) returns from_version, to_version, active, requires_php / requires_wp and whether this site meets them, and source (wordpress.org or other). With dry_run false the plugin is updated through WordPress\'s own upgrader (an active plugin stays active; WordPress restores the old files if the install fails), the home page is loaded once, and the reply says updated, home_status and home_ok. There is no automatic way back to the earlier version: the reply carries undo_reason "manual" and previous_version, so take a backup first. Refused: no_update_available, requirements_not_met, cannot_update_self (Royal MCP), the active security plugin unless force is true (security_plugin), file_mods_disabled, filesystem_credentials_required. Not available in read-only mode. Requires update_plugins.', [ 'plugin' => $pl, 'dry_run' => $dry, 'force' => [ 'type' => 'boolean', 'description' => 'Update the active security plugin (Wordfence, GuardPress, Solid Security and others). Only when the site owner asked for it.' ] ], [ 'plugin' ] ),
			$tool( 'wp_update_theme', 'Update one theme to the version WordPress has waiting (see update_available on wp_get_themes). dry_run (default true) returns from_version, to_version, active, the new version\'s requirements and whether this site meets them, and source. With dry_run false the theme is updated through WordPress\'s own upgrader, the home page is loaded once, and the reply says updated, home_status and home_ok. There is no automatic way back: the reply carries undo_reason "manual" and previous_version, so take a backup first. Refused: no_update_available, requirements_not_met, file_mods_disabled, filesystem_credentials_required. Not available in read-only mode. Requires update_themes.', [ 'theme' => $th, 'dry_run' => $dry ], [ 'theme' ] ),
			$tool( 'wp_install_plugin', 'Install a plugin from WordPress.org by slug (only WordPress.org). dry_run (default true) shows name, version and requirements (php_ok, wp_ok); pass false to install. activate true also activates it and checks the home page (deactivated again if the page stops loading). Refused: already_installed, not_on_wordpress_org, requirements_not_met, file_mods_disabled. Undo removes the plugin (left alone if it was activated after the install, unless forced). Requires install_plugins.', [ 'slug' => [ 'type' => 'string', 'description' => 'WordPress.org slug, e.g. "query-monitor".' ], 'activate' => [ 'type' => 'boolean' ], 'dry_run' => $dry ], [ 'slug' ] ),
			$tool( 'wp_install_theme', 'Install a theme from WordPress.org by slug (not activated; use wp_switch_theme). dry_run (default true) previews. Refused: already_installed, not_on_wordpress_org, requirements_not_met. Undo removes it unless it is in use. Requires install_themes.', [ 'slug' => [ 'type' => 'string', 'description' => 'WordPress.org slug, e.g. "twentytwentyfive".' ], 'dry_run' => $dry ], [ 'slug' ] ),
			$tool( 'wp_activate_plugin', 'Activate an installed plugin, then load the home page; if it stops loading the plugin is deactivated again (activation_broke_site). dry_run (default true) previews. When the home page was already not loading before the call, the plugin is activated and the reply says the check could not be made (home_check_skipped). Refused: plugin_not_found, requirements_not_met. Undo deactivates it, with the same checks as wp_deactivate_plugin. Requires activate_plugins.', [ 'plugin' => $pl, 'dry_run' => $dry ], [ 'plugin' ] ),
			$tool( 'wp_deactivate_plugin', 'Deactivate a plugin, then load the home page; if it stops loading the plugin is activated again (deactivation_broke_site). dry_run (default true) previews. Refused: Royal MCP itself (would_disconnect), plugins other active plugins need (has_active_dependents), network-active plugins, and the site\'s security plugin unless force is true (security_plugin). Undo activates it again. Requires activate_plugins.', [ 'plugin' => $pl, 'dry_run' => $dry, 'force' => [ 'type' => 'boolean', 'description' => 'Deactivate a security plugin (Wordfence, GuardPress, Solid Security and others). Only when the site owner asked for it.' ] ], [ 'plugin' ] ),
			$tool( 'wp_delete_plugin', 'Delete an inactive plugin. dry_run (default true) shows its files, size and whether its uninstall routine runs (which may remove its data). Deleting a plugin removes its whole folder: refused while another plugin in that folder is active (plugin_folder_in_use), and the preview lists what else is deleted with it. Refused: active plugins (plugin_active), Royal MCP itself. No automatic undo (undo_reason "manual"): reinstall it, and restore its data from a backup. Requires delete_plugins.', [ 'plugin' => $pl, 'dry_run' => $dry ], [ 'plugin' ] ),
			$tool( 'wp_switch_theme', 'Switch the active theme, then load the home page; if it stops loading the previous theme comes back (switch_broke_site). dry_run (default true) shows the target, its parent and requirements. Refused: broken themes or a missing parent (theme_broken), requirements_not_met. Undo switches back (left alone if the theme was changed again, unless forced). Requires switch_themes.', [ 'theme' => $th, 'dry_run' => $dry ], [ 'theme' ] ),
			$tool( 'wp_delete_theme', 'Delete an installed theme. Never the active theme (theme_active) or the active child theme\'s parent (theme_is_active_parent). dry_run (default true) lists its files and size. No automatic undo (undo_reason "manual"). Requires delete_themes.', [ 'theme' => $th, 'dry_run' => $dry ], [ 'theme' ] ),
			$tool( 'wp_set_plugin_auto_update', 'Turn WordPress auto-updates on or off for one plugin. dry_run (default true) previews. Refused when the site switches plugin auto-updates off (auto_updates_unavailable), and for the active security plugin unless force is true (security_plugin). Undo restores the previous setting. Requires update_plugins.', [ 'plugin' => $pl, 'enabled' => [ 'type' => 'boolean' ], 'force' => [ 'type' => 'boolean', 'description' => 'Change it even for the active security plugin.' ], 'dry_run' => $dry ], [ 'plugin', 'enabled' ] ),
			$tool( 'wp_set_theme_auto_update', 'Turn WordPress auto-updates on or off for one theme. dry_run (default true) previews. Undo restores the previous setting. Requires update_themes.', [ 'theme' => $th, 'enabled' => [ 'type' => 'boolean' ], 'dry_run' => $dry ], [ 'theme', 'enabled' ] ),
		];
		return array_column( $out, null, 'name' );
	}
}
