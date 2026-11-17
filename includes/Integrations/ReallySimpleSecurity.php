<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Tool_Refusal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Really Simple Security MCP Integration
 *
 * Two read-only tools. Settings are read through rsssl_get_option(), the
 * plugin's own accessor (it handles network-wide activation and legacy
 * keys). Certificate details and vulnerability results come from what the
 * plugin already stored: no tool opens a connection to this site or to any
 * outside service, and no setting is changed.
 */
class ReallySimpleSecurity {

	/** Hardening features in the free plugin, by setting id. */
	const HARDENING_BASIC = [
		'disable_anyone_can_register',
		'disable_file_editing',
		'block_code_execution_uploads',
		'hide_wordpress_version',
		'disable_login_feedback',
		'disable_indexing',
		'disable_user_enumeration',
		'disable_x_powered_by_header',
		'rename_admin_user',
		'disable_xmlrpc',
		'block_display_is_login',
	];

	/**
	 * Hardening features that only take effect with the plugin's paid version, as
	 * setting id => reply name. Reply names avoid words that the reply
	 * scrubber treats as credential names.
	 */
	const HARDENING_EXTENDED = [
		'change_debug_log_location'     => 'change_debug_log_location',
		'disable_application_passwords' => 'disable_application_logins',
		'block_admin_creation'          => 'block_admin_creation',
		'disable_http_methods'          => 'disable_http_methods',
		'permission_detection'          => 'permission_detection',
		'change_login_url_enabled'      => 'change_login_url_enabled',
	];

	const SEVERITIES = [ 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4 ];

	const TYPES = [ 'plugin', 'theme', 'core' ];

	/** Most vulnerability entries returned for one component. */
	const MAX_PER_COMPONENT = 20;

	public static function is_available() {
		return class_exists( 'REALLY_SIMPLE_SSL' ) && function_exists( 'rsssl_get_option' );
	}

	public static function get_tools() {
		return [
			[
				'name'        => 'rsssl_get_status',
				'description' => 'Read the Really Simple Security status: plugin version and paid_version_active; SSL detected and enabled, the HTTPS redirect method (none, wp_redirect or htaccess) and the mixed content fixer; HSTS settings; which basic and extended hardening features are on (disable_application_logins turns off WordPress application passwords); two-factor login, limit login attempts and firewall switches; vulnerability detection with the stored summary; the certificate expiry from the last cached check (up to a day old, or not_checked); and open-task and notice counts. Extended hardening and fields ending in not_in_free_version only take effect with the paid version. Read-only: changes nothing and makes no connection. Never returns captcha keys, the notification email address, the custom login address or the renamed admin username.',
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				],
			],
			[
				'name'        => 'rsssl_get_vulnerabilities',
				'description' => 'List the plugins, themes and WordPress core versions that Really Simple Security\'s vulnerability detection found vulnerable on its last check, with each finding\'s severity (low, medium, high, critical), publish date, affected version range, whether a fix exists, installed and latest known version. Also returns the count per severity and when the last check ran. Read-only: reads the results the plugin stored; it does not start a new check or contact any service. Results are empty when vulnerability detection is off or has not run yet (scanner_enabled tells you which).',
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => [
						'min_severity' => [ 'type' => 'string', 'enum' => array_keys( self::SEVERITIES ), 'description' => 'Only components whose worst finding is at least this severity. Default low (all).' ],
						'type'         => [ 'type' => 'string', 'enum' => self::TYPES, 'description' => 'Only this component type. Default: all types.' ],
						'limit'        => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Most components to return, worst first. Default 50.' ],
					],
					'additionalProperties' => false,
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use Really Simple Security tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Really Simple Security is not active' );
		}
		// Network-wide activation keeps one set of settings for the whole network.
		if ( is_multisite() && function_exists( 'rsssl_is_networkwide_active' ) && rsssl_is_networkwide_active() && ! current_user_can( 'manage_network_options' ) ) {
			throw new \Exception( 'You do not have permission to use Really Simple Security tools.' );
		}

		if ( is_object( $args ) ) {
			$args = get_object_vars( $args );
		}
		if ( null === $args ) {
			$args = [];
		}
		if ( ! is_array( $args ) ) {
			throw new Tool_Refusal( 'invalid_args', 'Arguments must be an object.' );
		}

		switch ( $name ) {
			case 'rsssl_get_status':
				self::only_keys( $args, [] );
				return self::get_status();
			case 'rsssl_get_vulnerabilities':
				self::only_keys( $args, [ 'min_severity', 'type', 'limit' ] );
				return self::get_vulnerabilities( $args );
			default:
				throw new \Exception( 'Unknown Really Simple Security tool: ' . esc_html( $name ) );
		}
	}

	// ============ rsssl_get_status ============

	private static function get_status() {
		$hardening_basic = [];
		foreach ( self::HARDENING_BASIC as $id ) {
			$hardening_basic[ $id ] = self::flag( rsssl_get_option( $id ) );
		}
		$hardening_extended = [];
		foreach ( self::HARDENING_EXTENDED as $id => $label ) {
			$hardening_extended[ $label ] = self::flag( rsssl_get_option( $id ) );
		}

		$redirect = rsssl_get_option( 'redirect' );
		if ( false === $redirect || '' === $redirect || null === $redirect ) {
			$redirect = 'not_set';
		} elseif ( ! in_array( $redirect, [ 'none', 'wp_redirect', 'htaccess' ], true ) ) {
			$redirect = 'unknown';
		}

		$max_age = rsssl_get_option( 'hsts_max_age' );
		$paid    = defined( 'rsssl_pro' );
		$vuln    = self::vulnerability_summary();

		return [
			'plugin_version'      => defined( 'rsssl_version' ) ? (string) rsssl_version : '',
			'paid_version_active' => $paid,
			'safe_mode'           => defined( 'RSSSL_SAFE_MODE' ) && RSSSL_SAFE_MODE,
			'ssl'                 => [
				'detected'                  => self::flag( rsssl_get_option( 'site_has_ssl' ) ),
				'enabled'                   => self::flag( rsssl_get_option( 'ssl_enabled' ) ),
				'home_url_is_https'         => 0 === strpos( (string) home_url(), 'https://' ),
				'redirect_method'           => $redirect,
				'mixed_content_fixer'       => self::flag( rsssl_get_option( 'mixed_content_fixer' ) ),
				'admin_mixed_content_fixer' => self::flag( rsssl_get_option( 'admin_mixed_content_fixer' ) ),
			],
			'hsts'                => [
				'enabled'            => self::flag( rsssl_get_option( 'hsts' ) ),
				'preload'            => self::flag( rsssl_get_option( 'hsts_preload' ) ),
				'include_subdomains' => self::flag( rsssl_get_option( 'hsts_subdomains' ) ),
				'max_age_seconds'    => is_numeric( $max_age ) ? (int) $max_age : null,
				'not_in_free_version' => true,
			],
			'hardening'           => [
				'basic'                  => $hardening_basic,
				'basic_enabled_count'    => count( array_filter( $hardening_basic ) ),
				'extended'               => $hardening_extended,
				'extended_enabled_count' => count( array_filter( $hardening_extended ) ),
				'extended_not_in_free_version' => true,
			],
			'login_protection'    => [
				'two_factor'           => self::flag( rsssl_get_option( 'login_protection_enabled' ) ),
				'limit_login_attempts' => self::flag( rsssl_get_option( 'enable_limited_login_attempts' ) ),
				'firewall'             => self::flag( rsssl_get_option( 'enable_firewall' ) ),
				'limit_login_attempts_and_firewall_not_in_free_version' => true,
			],
			'vulnerability_detection' => $vuln,
			'certificate'         => self::certificate(),
			'notices'             => self::notices(),
		];
	}

	/** The certificate as the plugin last cached it; no connection is made here. */
	private static function certificate() {
		$info = get_transient( 'rsssl_certinfo' );
		$out  = [
			'state'               => 'not_checked',
			'valid_from'          => null,
			'valid_to'            => null,
			'days_left'           => null,
			'issuer'              => null,
			'subject_common_name' => null,
			'generated_by_plugin' => (bool) get_option( 'rsssl_le_certificate_generated_by_rsssl' ),
		];
		if ( 'no-response' === $info ) {
			$out['state'] = 'no_response';
			return $out;
		}
		if ( 'not-valid' === $info ) {
			$out['state'] = 'not_valid';
			return $out;
		}
		if ( ! is_array( $info ) ) {
			return $out;
		}
		$from = isset( $info['validFrom_time_t'] ) && is_numeric( $info['validFrom_time_t'] ) ? (int) $info['validFrom_time_t'] : 0;
		$to   = isset( $info['validTo_time_t'] ) && is_numeric( $info['validTo_time_t'] ) ? (int) $info['validTo_time_t'] : 0;
		$now  = time();
		if ( $to > 0 ) {
			$out['valid_to']  = gmdate( 'c', $to );
			$out['days_left'] = (int) floor( ( $to - $now ) / DAY_IN_SECONDS );
		}
		if ( $from > 0 ) {
			$out['valid_from'] = gmdate( 'c', $from );
		}
		if ( $to > 0 && $to <= $now ) {
			$out['state'] = 'expired';
		} elseif ( $from > $now ) {
			$out['state'] = 'not_yet_valid';
		} elseif ( $to > 0 ) {
			$out['state'] = 'valid';
		} else {
			$out['state'] = 'unknown';
		}
		$issuer = $info['issuer'] ?? [];
		if ( is_array( $issuer ) ) {
			$name = $issuer['O'] ?? ( $issuer['CN'] ?? null );
			$out['issuer'] = is_string( $name ) ? self::clean( $name, 120 ) : null;
		}
		$cn = $info['subject']['CN'] ?? null;
		$out['subject_common_name'] = is_string( $cn ) ? self::clean( $cn, 255 ) : null;
		return $out;
	}

	/** Open-task count and the cached admin notices, as the plugin keeps them. */
	private static function notices() {
		$count = get_option( 'rsssl_plusone_count' );
		if ( 'empty' === $count ) {
			$open_tasks = 0;
		} elseif ( is_numeric( $count ) ) {
			$open_tasks = (int) $count;
		} else {
			$open_tasks = null;
		}

		$cached   = get_option( 'rsssl_admin_notices' );
		$warnings = [];
		$open     = 0;
		$total    = null;
		if ( 'empty' === $cached ) {
			$total = 0;
		} elseif ( is_array( $cached ) ) {
			$total = 0;
			foreach ( $cached as $id => $notice ) {
				if ( ! is_array( $notice ) ) {
					continue;
				}
				$icon = $notice['output']['icon'] ?? '';
				if ( 'warning' === $icon ) {
					$warnings[] = sanitize_key( (string) $id );
					$total++;
				} elseif ( 'open' === $icon ) {
					$open++;
					$total++;
				}
			}
		}

		return [
			'open_tasks_count'     => $open_tasks,
			'admin_notice_count'   => $total,
			'admin_warning_count'  => null === $total ? null : count( $warnings ),
			'admin_open_count'     => null === $total ? null : $open,
			'admin_warning_ids'    => array_slice( $warnings, 0, 50 ),
			'counted_by_plugin'    => null !== $open_tasks || null !== $total,
		];
	}

	private static function vulnerability_summary() {
		$snapshot = self::snapshot();
		$counts   = self::count_per_severity( self::vulnerable_components() );
		return [
			'enabled'                    => self::flag( rsssl_get_option( 'enable_vulnerability_scanner' ) ),
			'vulnerable_component_count' => array_sum( $counts['components'] ),
			'highest_severity'           => self::highest( $counts['components'] ),
			'last_checked'               => $snapshot['generated_at'],
		];
	}

	// ============ rsssl_get_vulnerabilities ============

	private static function get_vulnerabilities( array $args ) {
		$min   = self::enum_arg( $args, 'min_severity', array_keys( self::SEVERITIES ), 'low' );
		$type  = self::enum_arg( $args, 'type', self::TYPES, null );
		$limit = self::int_arg( $args, 'limit', 50, 1, 200 );

		$raw      = self::vulnerable_components();
		$counts   = self::count_per_severity( $raw );
		$versions = self::installed_versions();
		$list     = [];

		foreach ( $raw as $key => $component ) {
			$ctype = strtolower( (string) ( $component['type'] ?? '' ) );
			if ( ! in_array( $ctype, self::TYPES, true ) ) {
				$ctype = strtolower( (string) strtok( (string) $key, ':' ) );
			}
			if ( null !== $type && $type !== $ctype ) {
				continue;
			}
			$entries = [];
			$worst   = 0;
			foreach ( (array) $component['vulnerabilities'] as $v ) {
				if ( ! is_array( $v ) ) {
					continue;
				}
				$sev  = strtolower( (string) ( $v['severity'] ?? '' ) );
				$rank = self::SEVERITIES[ $sev ] ?? 0;
				$worst = max( $worst, $rank );
				$entries[] = [
					'id'               => self::clean( (string) ( $v['lookup'] ?? '' ), 64 ),
					'severity'         => $rank ? $sev : 'unknown',
					'published_at'     => self::clean( (string) ( $v['published_at'] ?? '' ), 40 ),
					'affected_from'    => self::clean( (string) ( $v['version_from'] ?? '' ), 40 ),
					'from_inclusive'   => ! empty( $v['from_inclusive'] ),
					'affected_to'      => self::clean( (string) ( $v['version_to'] ?? '' ), 40 ),
					'to_inclusive'     => ! empty( $v['to_inclusive'] ),
					'fix_available'    => ! empty( $v['fixed_in'] ),
				];
			}
			if ( $worst < self::SEVERITIES[ $min ] ) {
				continue;
			}
			usort( $entries, static function ( $a, $b ) {
				return ( self::SEVERITIES[ $b['severity'] ] ?? 0 ) <=> ( self::SEVERITIES[ $a['severity'] ] ?? 0 );
			} );
			$slug      = self::clean( (string) ( $component['slug'] ?? '' ), 200 );
			$installed = $versions[ $ctype . ':' . strtolower( $slug ) ] ?? null;
			$latest    = isset( $component['latestVersion'] ) && is_string( $component['latestVersion'] ) && '' !== $component['latestVersion']
				? self::clean( $component['latestVersion'], 40 )
				: null;
			$list[] = [
				'type'                => $ctype,
				'slug'                => $slug,
				'name'                => self::clean( (string) ( $component['name'] ?? $slug ), 200 ),
				'installed_version'   => $installed,
				'latest_version'      => $latest,
				'update_available'    => null !== $installed && null !== $latest ? version_compare( $installed, $latest, '<' ) : null,
				'highest_severity'    => array_search( $worst, self::SEVERITIES, true ) ?: 'unknown',
				'vulnerability_count' => count( $entries ),
				'vulnerabilities'     => array_slice( $entries, 0, self::MAX_PER_COMPONENT ),
				'_rank'               => $worst,
			];
		}

		usort( $list, static function ( $a, $b ) {
			return [ $b['_rank'], $a['name'] ] <=> [ $a['_rank'], $b['name'] ];
		} );
		$matched = count( $list );
		$list    = array_slice( $list, 0, $limit );
		foreach ( $list as &$item ) {
			unset( $item['_rank'] );
		}
		unset( $item );

		$snapshot = self::snapshot();
		return [
			'scanner_enabled'               => self::flag( rsssl_get_option( 'enable_vulnerability_scanner' ) ),
			'last_checked'                  => $snapshot['generated_at'],
			'vulnerable_component_count'    => array_sum( $counts['components'] ),
			'components_by_highest_severity' => $counts['components'],
			'findings_by_severity'          => $counts['findings'],
			'filters'                       => [ 'min_severity' => $min, 'type' => $type, 'limit' => $limit ],
			'matched'                       => $matched,
			'count'                         => count( $list ),
			'truncated'                     => $matched > count( $list ),
			'components'                    => $list,
		];
	}

	// ============ Stored vulnerability data ============

	/** Stored components that have at least one finding, keyed by the plugin's storage key. */
	private static function vulnerable_components() {
		$all  = null;
		$repo = '\\ReallySimplePlugins\\RSS\\Core\\Features\\Vulnerability\\Repositories\\VulnerabilityStorageRepository';
		if ( class_exists( $repo ) ) {
			try {
				$all = ( new $repo() )->getAllRaw();
			} catch ( \Throwable $e ) {
				$all = null;
			}
		}
		if ( ! is_array( $all ) ) {
			$all = get_option( 'rsssl_vulnerabilities', [] );
		}
		$out = [];
		foreach ( is_array( $all ) ? $all : [] as $key => $component ) {
			if ( is_array( $component ) && ! empty( $component['vulnerabilities'] ) && is_array( $component['vulnerabilities'] ) ) {
				$out[ (string) $key ] = $component;
			}
		}
		return $out;
	}

	private static function count_per_severity( array $components ) {
		$by_component = array_fill_keys( array_keys( self::SEVERITIES ), 0 );
		$by_finding   = $by_component;
		foreach ( $components as $component ) {
			$worst = '';
			foreach ( (array) $component['vulnerabilities'] as $v ) {
				$sev = is_array( $v ) ? strtolower( (string) ( $v['severity'] ?? '' ) ) : '';
				if ( ! isset( self::SEVERITIES[ $sev ] ) ) {
					continue;
				}
				$by_finding[ $sev ]++;
				if ( '' === $worst || self::SEVERITIES[ $sev ] > self::SEVERITIES[ $worst ] ) {
					$worst = $sev;
				}
			}
			if ( '' !== $worst ) {
				$by_component[ $worst ]++;
			}
		}
		return [ 'components' => $by_component, 'findings' => $by_finding ];
	}

	private static function highest( array $counts ) {
		$best = null;
		foreach ( self::SEVERITIES as $sev => $rank ) {
			if ( ! empty( $counts[ $sev ] ) ) {
				$best = $sev;
			}
		}
		return $best;
	}

	/** When the plugin last stored a vulnerability snapshot. */
	private static function snapshot() {
		$at   = 0;
		$repo = '\\ReallySimplePlugins\\RSS\\Core\\Features\\Vulnerability\\Repositories\\VulnerabilitySnapshotRepository';
		if ( class_exists( $repo ) ) {
			try {
				$dto = ( new $repo() )->getLatest();
				if ( $dto && method_exists( $dto, 'getGeneratedAt' ) ) {
					$at = (int) $dto->getGeneratedAt();
				}
			} catch ( \Throwable $e ) {
				$at = 0;
			}
		}
		if ( $at <= 0 ) {
			$raw = get_option( 'rsssl_vulnerability_snapshot', null );
			$at  = is_array( $raw ) && isset( $raw['generatedAt'] ) && is_numeric( $raw['generatedAt'] ) ? (int) $raw['generatedAt'] : 0;
		}
		return [ 'generated_at' => $at > 0 ? gmdate( 'c', $at ) : null ];
	}

	/** Installed versions keyed "type:slug", for comparing with the stored findings. */
	private static function installed_versions() {
		$out = [ 'core:wordpress' => (string) get_bloginfo( 'version' ) ];
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( (array) get_plugins() as $file => $data ) {
			$dir  = dirname( (string) $file );
			$slug = '.' === $dir ? basename( (string) $file, '.php' ) : $dir;
			$out[ 'plugin:' . strtolower( $slug ) ] = (string) ( $data['Version'] ?? '' );
		}
		foreach ( (array) wp_get_themes() as $slug => $theme ) {
			$out[ 'theme:' . strtolower( (string) $slug ) ] = (string) $theme->get( 'Version' );
		}
		return $out;
	}

	// ============ Helpers ============

	/** A stored switch as true/false; the plugin keeps these as true, 1, "1" or an enabled-style string. */
	private static function flag( $value ) {
		if ( true === $value || 1 === $value ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), [ '1', 'true', 'yes', 'on', 'enabled' ], true );
		}
		return false;
	}

	private static function clean( $value, $max ) {
		return mb_substr( sanitize_text_field( (string) $value ), 0, (int) $max );
	}

	private static function only_keys( array $args, array $allowed ) {
		foreach ( array_keys( $args ) as $k ) {
			if ( ! in_array( $k, $allowed, true ) ) {
				throw new Tool_Refusal(
					'invalid_args',
					sprintf( 'Unknown argument: %s.%s', esc_html( mb_substr( (string) $k, 0, 60 ) ), $allowed ? ' Allowed: ' . implode( ', ', $allowed ) . '.' : ' This tool takes no arguments.' )
				);
			}
		}
	}

	private static function enum_arg( array $args, $key, array $allowed, $default ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			return $default;
		}
		if ( ! is_string( $args[ $key ] ) || ! in_array( $args[ $key ], $allowed, true ) ) {
			throw new Tool_Refusal( 'invalid_args', sprintf( '%s must be one of: %s.', esc_html( $key ), implode( ', ', $allowed ) ) );
		}
		return $args[ $key ];
	}

	private static function int_arg( array $args, $key, $default, $min, $max ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			return $default;
		}
		$v = $args[ $key ];
		if ( ! is_int( $v ) || $v < $min || $v > $max ) {
			throw new Tool_Refusal( 'invalid_args', sprintf( '%s must be a whole number from %d to %d.', esc_html( $key ), (int) $min, (int) $max ) );
		}
		return $v;
	}
}
