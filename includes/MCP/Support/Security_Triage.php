<?php
/**
 * Security_Triage — read-only facts for checking a site after a suspected
 * compromise: must-use plugins and drop-ins, plugin folders WordPress does
 * not list, plugin dates, users with capabilities beyond their role, whether
 * named options exist, and leftovers in wp-content/upgrade.
 *
 * Names, dates and counts only. No file contents, option values, emails or
 * logins are read into the result. Filesystem walks are bounded (file count
 * and time per check) and flag partial results.
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Security_Triage {

	const CHECKS        = [ 'mu_plugins', 'unregistered_plugin_folders', 'plugins', 'users', 'option_patterns', 'upgrade_folder' ];
	const MAX_FILES     = 5000;
	const MAX_SECONDS   = 3.0;
	const MAX_PATTERNS  = 20;
	const MAX_NAMES     = 50;
	const MAX_USERS     = 20000;
	const MAX_UPGRADE   = 200;
	const POWERFUL_CAPS = [ 'manage_options', 'install_plugins', 'activate_plugins', 'edit_plugins', 'update_plugins', 'delete_plugins', 'edit_users', 'create_users', 'promote_users', 'delete_users', 'unfiltered_html', 'edit_themes', 'manage_network', 'manage_network_options' ];

	/**
	 * Run the requested checks.
	 *
	 * @param array $args since, option_patterns, plugin_names, checks.
	 * @return array
	 * @throws \InvalidArgumentException On invalid input.
	 */
	public static function run( array $args ) {
		$since  = self::parse_since( $args['since'] ?? null );
		$checks = self::parse_checks( $args['checks'] ?? null );
		$names  = self::parse_names( $args['plugin_names'] ?? null );
		$pats   = self::parse_patterns( $args['option_patterns'] ?? null );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$out        = [ 'since' => null === $since ? null : gmdate( 'Y-m-d\TH:i:s\Z', $since ) ];
		$run        = [];
		$skipped    = [];
		$indicators = [];
		$partial    = false;

		$registered = get_plugins();

		if ( in_array( 'mu_plugins', $checks, true ) ) {
			$run[]             = 'mu_plugins';
			$out['mu_plugins'] = self::listed_files( get_mu_plugins(), WPMU_PLUGIN_DIR, $since, $indicators, 'must-use plugin' );
			$out['dropins']    = self::listed_files( get_dropins(), WP_CONTENT_DIR, $since, $indicators, 'drop-in' );
			// Files in mu-plugins that WordPress does not load as must-use plugins (subfolders, non-PHP).
			$out['mu_plugins_other_entries'] = self::other_entries( WPMU_PLUGIN_DIR, array_keys( get_mu_plugins() ) );
		}

		if ( in_array( 'unregistered_plugin_folders', $checks, true ) ) {
			$run[] = 'unregistered_plugin_folders';
			$found = self::unregistered_plugin_folders( $registered );
			$out['unregistered_plugin_folders'] = $found['items'];
			if ( $found['partial'] ) {
				$partial = true;
			}
			foreach ( $found['items'] as $item ) {
				$indicators[] = sprintf( 'Plugin %s %s is not a registered plugin', 'file' === $item['type'] ? 'file' : 'folder', $item['name'] );
			}
		}

		if ( in_array( 'plugins', $checks, true ) ) {
			$run[]          = 'plugins';
			$out['plugins'] = self::plugin_dates( $registered, $since, $indicators );
		}

		if ( in_array( 'users', $checks, true ) ) {
			$run[] = 'users';
			$users = self::users( $since, $indicators );
			$out['users_with_extra_caps']   = $users['items'];
			$out['administrators']          = $users['admins'];
			$out['admins_registered_since'] = $users['admins_since'];
			// Network administrators hold every capability on every site without a role row.
			if ( is_multisite() ) {
				$network = self::network_admins( $since, $indicators );
				$out['network_admins']                  = $network['count'];
				$out['network_admins_registered_since'] = $network['since'];
			}
			if ( $users['partial'] ) {
				$partial = true;
			}
		}

		if ( in_array( 'option_patterns', $checks, true ) ) {
			if ( [] === $pats ) {
				$skipped[] = [ 'check' => 'option_patterns', 'reason' => 'No option_patterns given.' ];
			} else {
				$run[]                  = 'option_patterns';
				$out['option_patterns'] = self::option_patterns( $pats, $indicators );
			}
		}

		if ( in_array( 'upgrade_folder', $checks, true ) ) {
			$run[]                 = 'upgrade_folder';
			$out['upgrade_folder'] = self::upgrade_folder( $since, $indicators );
		}

		if ( [] !== $names ) {
			$out['indicator_hits'] = self::indicator_hits( $names, $registered, $out['unregistered_plugin_folders'] ?? null );
			foreach ( $out['indicator_hits'] as $hit ) {
				$indicators[] = sprintf( 'Indicator %s found (%s)', $hit['name'], $hit['where'] );
			}
		}

		foreach ( array_diff( self::CHECKS, $checks ) as $not_asked ) {
			$skipped[] = [ 'check' => $not_asked, 'reason' => 'Not requested.' ];
		}

		$gp_active        = class_exists( '\GuardPress' );
		$out['guardpress'] = $gp_active
			? [ 'active' => true, 'note' => 'GuardPress is active: gp_get_security_status and gp_get_vulnerability_results give its own scan results.' ]
			: [ 'active' => false, 'note' => 'For continuous scanning and file integrity checks, GuardPress.' ];

		$out['indicators']     = $indicators;
		$out['checks_run']     = $run;
		$out['checks_skipped'] = $skipped;
		$out['partial']        = $partial;
		$n                     = count( $indicators );
		$out['summary']        = ( 0 === $n ? 'No indicators found among the checks run' : sprintf( '%d indicator%s found among the checks run', $n, 1 === $n ? '' : 's' ) )
			. ( $partial ? ' (some results are partial: a size or time limit was reached).' : '.' );
		return $out;
	}

	// ==================== Input ====================

	private static function parse_since( $since ) {
		if ( null === $since || '' === $since ) {
			return null;
		}
		if ( ! is_string( $since ) || strlen( $since ) > 40 ) {
			throw new \InvalidArgumentException( 'since must be a date such as 2026-10-17 or 2026-10-17T00:00:00Z.' );
		}
		$ts = strtotime( $since );
		if ( false === $ts || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $since ) ) {
			throw new \InvalidArgumentException( 'since must be a date such as 2026-10-17 or 2026-10-17T00:00:00Z.' );
		}
		return $ts;
	}

	private static function parse_checks( $checks ) {
		if ( null === $checks || [] === $checks ) {
			return self::CHECKS;
		}
		if ( ! is_array( $checks ) ) {
			throw new \InvalidArgumentException( 'checks must be a list of: ' . implode( ', ', self::CHECKS ) . '.' );
		}
		$unknown = array_diff( array_map( 'strval', $checks ), self::CHECKS );
		if ( [] !== $unknown ) {
			throw new \InvalidArgumentException( 'Unknown check(s): ' . esc_html( implode( ', ', array_slice( $unknown, 0, 5 ) ) ) . '. Use: ' . implode( ', ', self::CHECKS ) . '.' );
		}
		return array_values( array_intersect( self::CHECKS, array_map( 'strval', $checks ) ) );
	}

	private static function parse_names( $names ) {
		if ( null === $names || [] === $names ) {
			return [];
		}
		if ( ! is_array( $names ) || count( $names ) > self::MAX_NAMES ) {
			throw new \InvalidArgumentException( sprintf( 'plugin_names must be a list of at most %d plugin slugs or folder names.', self::MAX_NAMES ) );
		}
		$out = [];
		foreach ( $names as $name ) {
			$clean = is_string( $name ) ? sanitize_file_name( strtolower( trim( $name ) ) ) : '';
			$clean = preg_replace( '/\.php$/', '', $clean );
			if ( strlen( $clean ) < 3 ) {
				throw new \InvalidArgumentException( 'Each plugin name must be a slug or folder name of at least 3 characters.' );
			}
			$out[ $clean ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * Option name patterns: a full name, or a name with one * at the start
	 * or the end. At least 6 characters besides the *.
	 */
	private static function parse_patterns( $patterns ) {
		if ( null === $patterns || [] === $patterns ) {
			return [];
		}
		if ( ! is_array( $patterns ) || count( $patterns ) > self::MAX_PATTERNS ) {
			throw new \InvalidArgumentException( sprintf( 'option_patterns must be a list of at most %d option names or patterns.', self::MAX_PATTERNS ) );
		}
		$out = [];
		foreach ( $patterns as $pattern ) {
			$pattern = is_string( $pattern ) ? trim( $pattern ) : '';
			$core    = trim( $pattern, '*' );
			$stars   = substr_count( $pattern, '*' );
			$shaped  = 0 === $stars || ( 1 === $stars && ( '*' === $pattern[0] || '*' === substr( $pattern, -1 ) ) );
			if ( ! $shaped || strlen( $core ) < 6 || ! preg_match( '/^[A-Za-z0-9_.\-]+$/', $core ) ) {
				throw new \InvalidArgumentException( 'Each option pattern is a name of at least 6 characters (letters, digits, _ . -), optionally with one * at the start or the end.' );
			}
			$out[ $pattern ] = true;
		}
		return array_keys( $out );
	}

	// ==================== Checks ====================

	private static function iso( $ts ) {
		return false === $ts || null === $ts ? null : gmdate( 'Y-m-d\TH:i:s\Z', (int) $ts );
	}

	private static function mtime( $path ) {
		return file_exists( $path ) ? @filemtime( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable path reports null
	}

	private static function listed_files( array $found, $dir, $since, array &$indicators, $label ) {
		$items = [];
		foreach ( $found as $file => $data ) {
			$m = self::mtime( $dir . '/' . $file );
			$item = [
				'file'        => (string) $file,
				'name'        => '' !== (string) ( $data['Name'] ?? '' ) ? (string) $data['Name'] : (string) $file,
				'version'     => (string) ( $data['Version'] ?? '' ),
				'modified_at' => self::iso( $m ),
			];
			if ( null !== $since && false !== $m && $m >= $since ) {
				$item['flag_since'] = true;
				$indicators[]       = sprintf( 'The %s %s changed after %s', $label, $file, gmdate( 'Y-m-d', $since ) );
			}
			$items[] = $item;
		}
		return $items;
	}

	/** Entries in a folder other than the given files (no recursion, capped). */
	private static function other_entries( $dir, array $known ) {
		$items = [];
		if ( ! is_dir( $dir ) ) {
			return $items;
		}
		$known = array_fill_keys( $known, true );
		foreach ( (array) @scandir( $dir ) as $entry ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( '.' === $entry || '..' === $entry || isset( $known[ $entry ] ) || 'index.php' === $entry ) {
				continue;
			}
			$path    = $dir . '/' . $entry;
			$items[] = [ 'name' => (string) $entry, 'type' => is_link( $path ) ? 'link' : ( is_dir( $path ) ? 'folder' : 'file' ), 'modified_at' => self::iso( self::mtime( $path ) ) ];
			if ( count( $items ) >= self::MAX_UPGRADE ) {
				break;
			}
		}
		return $items;
	}

	/**
	 * Folders and loose PHP files in the plugins folder that get_plugins()
	 * does not list. Symbolic links are reported, never followed.
	 */
	private static function unregistered_plugin_folders( array $registered ) {
		$folders = [];
		$files   = [];
		foreach ( array_keys( $registered ) as $main ) {
			$d = dirname( $main );
			if ( '.' === $d ) {
				$files[ $main ] = true;
			} else {
				$folders[ strtok( $d, '/' ) ] = true;
			}
		}
		$items   = [];
		$partial = false;
		$budget  = self::MAX_FILES;
		$start   = microtime( true );
		foreach ( (array) @scandir( WP_PLUGIN_DIR ) as $entry ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( '.' === $entry || '..' === $entry || 'index.php' === $entry ) {
				continue;
			}
			$path = WP_PLUGIN_DIR . '/' . $entry;
			if ( is_link( $path ) ) {
				if ( ! isset( $folders[ $entry ] ) && ! isset( $files[ $entry ] ) ) {
					$ls      = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$items[] = [ 'name' => (string) $entry, 'type' => 'link', 'php_files' => null, 'newest_modified_at' => self::iso( is_array( $ls ) ? $ls['mtime'] : false ) ];
				}
				continue;
			}
			if ( is_file( $path ) ) {
				if ( '.php' === strtolower( substr( $entry, -4 ) ) && ! isset( $files[ $entry ] ) ) {
					$items[] = [ 'name' => (string) $entry, 'type' => 'file', 'php_files' => 1, 'newest_modified_at' => self::iso( self::mtime( $path ) ) ];
				}
				continue;
			}
			if ( ! is_dir( $path ) || isset( $folders[ $entry ] ) ) {
				continue;
			}
			$php    = 0;
			$newest = false;
			$cut    = false;
			try {
				$walk = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST, \RecursiveIteratorIterator::CATCH_GET_CHILD );
				foreach ( $walk as $f ) {
					if ( --$budget < 0 || microtime( true ) - $start > self::MAX_SECONDS ) {
						$cut = true;
						break;
					}
					if ( $f->isLink() || ! $f->isFile() ) {
						continue;
					}
					if ( 'php' === strtolower( $f->getExtension() ) ) {
						$php++;
					}
					$m = $f->getMTime();
					if ( false === $newest || $m > $newest ) {
						$newest = $m;
					}
				}
			} catch ( \Throwable $e ) {
				$cut = true;
			}
			if ( $cut ) {
				$partial = true;
			}
			if ( $php > 0 || $cut ) {
				$items[] = [ 'name' => (string) $entry, 'type' => 'folder', 'php_files' => $php, 'newest_modified_at' => self::iso( $newest ), 'partial' => $cut ];
			}
			if ( $budget < 0 || microtime( true ) - $start > self::MAX_SECONDS ) {
				$partial = true;
				break;
			}
		}
		return [ 'items' => $items, 'partial' => $partial ];
	}

	private static function plugin_dates( array $registered, $since, array &$indicators ) {
		$active  = (array) get_option( 'active_plugins', [] );
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) : [];
		$items   = [];
		foreach ( $registered as $main => $data ) {
			$d      = dirname( $main );
			$folder = '.' === $d ? WP_PLUGIN_DIR . '/' . $main : WP_PLUGIN_DIR . '/' . strtok( $d, '/' );
			$fm     = self::mtime( $folder );
			$mm     = self::mtime( WP_PLUGIN_DIR . '/' . $main );
			$item   = [
				'slug'                  => '.' === $d ? (string) basename( $main, '.php' ) : (string) strtok( $d, '/' ),
				'file'                  => (string) $main,
				'active'                => in_array( $main, $active, true ) || in_array( $main, $network, true ),
				'version'               => (string) ( $data['Version'] ?? '' ),
				'folder_modified_at'    => self::iso( $fm ),
				'main_file_modified_at' => self::iso( $mm ),
			];
			if ( null !== $since && ( ( false !== $fm && $fm >= $since ) || ( false !== $mm && $mm >= $since ) ) ) {
				$item['flag_since'] = true;
				$indicators[]       = sprintf( 'Plugin %s was installed or changed after %s', $item['slug'], gmdate( 'Y-m-d', $since ) );
			}
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * Users holding capabilities granted directly, beyond their roles (a key
	 * that is not a role this site defines counts too). Logins appear only as
	 * a short hash.
	 */
	private static function users( $since, array &$indicators ) {
		global $wpdb;
		$key  = $wpdb->get_blog_prefix() . 'capabilities';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT %d", $key, self::MAX_USERS + 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$partial = count( $rows ) > self::MAX_USERS;
		$rows    = array_slice( $rows, 0, self::MAX_USERS );
		$roles   = wp_roles();
		$items   = [];
		$admins  = 0;
		$since_n = 0;
		foreach ( $rows as $row ) {
			$caps = is_string( $row->meta_value ) ? @unserialize( $row->meta_value, [ 'allowed_classes' => false ] ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- WordPress's own capabilities row, no objects allowed
			if ( ! is_array( $caps ) ) {
				continue;
			}
			$user_roles = [];
			$extra      = [];
			foreach ( $caps as $cap => $granted ) {
				if ( ! $granted ) {
					continue;
				}
				if ( $roles->is_role( (string) $cap ) ) {
					$user_roles[] = (string) $cap;
				} else {
					$extra[] = (string) $cap;
				}
			}
			$uid = (int) $row->user_id;
			if ( in_array( 'administrator', $user_roles, true ) ) {
				$admins++;
				if ( null !== $since ) {
					$u = get_userdata( $uid );
					if ( $u && strtotime( $u->user_registered . ' UTC' ) >= $since ) {
						$since_n++;
					}
				}
			}
			if ( [] === $extra ) {
				continue;
			}
			sort( $extra );
			$u       = get_userdata( $uid );
			$items[] = [
				'id'              => $uid,
				'login_hash'      => $u ? substr( hash( 'sha256', (string) $u->user_login ), 0, 8 ) : null,
				'roles'           => $user_roles,
				'extra_caps'      => $extra,
				'powerful'        => [] !== array_intersect( $extra, self::POWERFUL_CAPS ),
				'user_registered' => $u ? (string) $u->user_registered : null,
			];
			$indicators[] = sprintf( 'User %d holds capabilities beyond their role (%s)', $uid, implode( ', ', array_slice( $extra, 0, 5 ) ) );
		}
		if ( $since_n > 0 ) {
			$indicators[] = sprintf( '%d administrator account%s registered after %s', $since_n, 1 === $since_n ? '' : 's', gmdate( 'Y-m-d', $since ) );
		}
		return [ 'items' => $items, 'admins' => $admins, 'admins_since' => null === $since ? null : $since_n, 'partial' => $partial ];
	}

	private static function network_admins( $since, array &$indicators ) {
		$logins  = array_values( array_filter( array_map( 'strval', (array) get_super_admins() ) ) );
		$since_n = 0;
		$missing = 0;
		foreach ( array_slice( $logins, 0, self::MAX_NAMES ) as $login ) {
			$u = get_user_by( 'login', $login );
			if ( ! $u ) {
				$missing++;
				continue;
			}
			if ( null !== $since && strtotime( $u->user_registered . ' UTC' ) >= $since ) {
				$since_n++;
			}
		}
		if ( $since_n > 0 ) {
			$indicators[] = sprintf( '%d network administrator account%s registered after %s', $since_n, 1 === $since_n ? '' : 's', gmdate( 'Y-m-d', $since ) );
		}
		if ( $missing > 0 ) {
			$indicators[] = sprintf( '%d network administrator name%s with no matching user', $missing, 1 === $missing ? '' : 's' );
		}
		return [ 'count' => count( $logins ), 'since' => null === $since ? null : $since_n ];
	}

	private static function option_patterns( array $patterns, array &$indicators ) {
		global $wpdb;
		$items = [];
		foreach ( $patterns as $pattern ) {
			$core = $wpdb->esc_like( trim( $pattern, '*' ) );
			$like = ( '*' === $pattern[0] ? '%' : '' ) . $core . ( '*' === substr( $pattern, -1 ) ? '%' : '' );
			$n    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$items[] = [ 'pattern' => $pattern, 'present' => $n > 0, 'count' => $n ];
			if ( $n > 0 ) {
				$indicators[] = sprintf( 'Option pattern %s matches %d option%s', $pattern, $n, 1 === $n ? '' : 's' );
			}
		}
		return $items;
	}

	private static function upgrade_folder( $since, array &$indicators ) {
		$items = self::other_entries( WP_CONTENT_DIR . '/upgrade', [] );
		foreach ( $items as &$item ) {
			$ts = null === $item['modified_at'] ? false : strtotime( $item['modified_at'] );
			if ( null !== $since && false !== $ts && $ts >= $since ) {
				$item['flag_since'] = true;
				$indicators[]       = sprintf( 'wp-content/upgrade/%s was left there after %s', $item['name'], gmdate( 'Y-m-d', $since ) );
			}
		}
		unset( $item );
		return $items;
	}

	private static function indicator_hits( array $names, array $registered, $unregistered ) {
		$where = [];
		foreach ( array_keys( $registered ) as $main ) {
			$d = dirname( $main );
			$where[ strtolower( '.' === $d ? basename( $main, '.php' ) : strtok( $d, '/' ) ) ][] = 'registered plugin';
		}
		foreach ( array_keys( get_mu_plugins() ) as $f ) {
			$where[ strtolower( basename( $f, '.php' ) ) ][] = 'must-use plugin';
		}
		foreach ( array_keys( get_dropins() ) as $f ) {
			$where[ strtolower( basename( $f, '.php' ) ) ][] = 'drop-in';
		}
		if ( null === $unregistered ) {
			$unregistered = self::unregistered_plugin_folders( $registered )['items'];
		}
		foreach ( $unregistered as $item ) {
			$where[ strtolower( preg_replace( '/\.php$/i', '', $item['name'] ) ) ][] = 'unregistered plugin ' . $item['type'];
		}
		$hits = [];
		foreach ( $names as $name ) {
			if ( isset( $where[ $name ] ) ) {
				$hits[] = [ 'name' => $name, 'where' => implode( ', ', array_unique( $where[ $name ] ) ) ];
			}
		}
		return $hits;
	}
}
