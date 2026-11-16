<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;
use Royal_MCP\MCP\Support\Tool_Refusal;
use Royal_MCP\MCP\Undo_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jetpack: site stats read through Jetpack's own Stats reader, the module
 * list, and switching one module on or off with an undo token.
 *
 * Stats come from WordPress.com through the site's Jetpack connection, so
 * they need that connection; Jetpack caches each answer for a few minutes.
 * Modules are read and switched with Jetpack's own module functions, which
 * apply the same connection, offline mode and plan rules as the Jetpack
 * settings screen.
 */
class JetpackTools {

	const TOOL_STATS       = 'jetpack_get_stats';
	const TOOL_LIST        = 'jetpack_list_modules';
	const TOOL_SET         = 'jetpack_set_module';
	const TOOLS            = [ self::TOOL_STATS, self::TOOL_LIST, self::TOOL_SET ];
	const STATS_WHAT       = [ 'summary', 'top-posts', 'referrers' ];
	const STATS_PERIODS    = [ 'day', 'week', 'month' ];
	const STATS_READER     = 'Automattic\\Jetpack\\Stats\\Abilities\\Stats_Abilities';
	const MAX_PERIODS      = 90;
	const MAX_LIST_ITEMS   = 50;

	/**
	 * Switches that weaken login, brute-force or firewall protection, or open the
	 * site to remote sign-in or management: module slug => [ the state that needs
	 * confirm, what that switch does ].
	 */
	const SENSITIVE = [
		'protect'            => [ false, 'stops brute-force protection on the login page' ],
		'waf'                => [ false, 'stops the Jetpack firewall from filtering malicious traffic' ],
		'account-protection' => [ false, 'stops the compromised and weak password checks at login' ],
		'sso'                => [ true, 'lets people sign in to this site with a WordPress.com account' ],
		'json-api'           => [ true, 'lets WordPress.com and the apps connected to it manage this site remotely' ],
	];

	public static function is_available() {
		return class_exists( 'Jetpack' ) && defined( 'JETPACK__VERSION' );
	}

	public static function handles( $name ) {
		return in_array( $name, self::TOOLS, true );
	}

	public static function get_tools() {
		return [
			[
				'name'        => self::TOOL_STATS,
				'description' => 'Read Jetpack site stats from WordPress.com through Jetpack\'s own Stats reader and the site\'s Jetpack connection (a request to WordPress.com; Jetpack caches each answer for about 5 minutes). what: summary (views and visitors per period, with the total views), top-posts (most viewed posts and pages) or referrers (sites that sent visitors). period: day, week or month; num: how many periods back from today (1-90); limit: how many top items to return (1-50, default 10, top-posts and referrers only). Visitors are counted per period, so they cannot be added up into unique visitors. When Jetpack is not connected to WordPress.com the reply is a not_connected error and nothing is requested. Read-only. Requires manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => [
						'what'   => [ 'type' => 'string', 'enum' => self::STATS_WHAT, 'description' => 'summary (default), top-posts or referrers.' ],
						'period' => [ 'type' => 'string', 'enum' => self::STATS_PERIODS, 'description' => 'day (default), week or month.' ],
						'num'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PERIODS, 'description' => 'How many periods, ending with the current one. Default 7.' ],
						'limit'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIST_ITEMS, 'description' => 'How many top items to return for top-posts and referrers. Default 10.' ],
					],
					'additionalProperties' => false,
				],
			],
			[
				'name'        => self::TOOL_LIST,
				'description' => 'List every Jetpack module on this site: slug, name, short description, whether it is active, whether it needs a connection to WordPress.com (and a WordPress.com user connection), whether the site\'s Jetpack plan includes it, whether a filter or the host forces it on or off, and whether jetpack_set_module can turn it on right now. Also says whether Jetpack is connected and whether it is in offline mode. Reads local settings only; nothing is requested from WordPress.com. Read-only. Requires manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				],
			],
			[
				'name'        => self::TOOL_SET,
				'description' => 'Turn one Jetpack module on or off, through Jetpack\'s own module switch. module: a slug from jetpack_list_modules; active: true to turn it on, false to turn it off. Turning off a protection module (protect: brute-force login protection, waf: firewall, account-protection: password checks at login) or turning on remote sign-in or management (sso: WordPress.com sign-in, json-api: management from WordPress.com) needs confirm: true; without it the call is refused with confirm_required and nothing changes. Turning a module on is refused when the module needs a connection to WordPress.com and Jetpack is not connected (needs_connection), when Jetpack is neither connected nor in offline mode (not_connected), when the site\'s plan does not include it (not_in_plan), when a filter or the host forces its state (forced_by_site), or when an older standalone plugin that does the same job is active (conflicting_plugin). Turning a module off stops what it does on the site; the module keeps its settings. Returns an undo token; undo switches the module back, and is refused when switching it back is not possible now (for example a module that needs a connection after the site was disconnected). Undo of a switch of one of those five modules, in either direction, is refused with confirm_required unless mcp_undo_last_operation is called with force: true; the token stays valid. Not available in read-only mode. Requires manage_options.',
				'inputSchema' => [
					'type'                 => 'object',
					'required'             => [ 'module', 'active' ],
					'properties'           => [
						'module'  => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'description' => 'Module slug, for example "sharedaddy" or "stats". Use jetpack_list_modules for the list.' ],
						'active'  => [ 'type' => 'boolean', 'description' => 'true turns the module on, false turns it off.' ],
						'confirm' => [ 'type' => 'boolean', 'description' => 'Required to turn off a protection module or turn on remote sign-in/management.' ],
					],
					'additionalProperties' => false,
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use Jetpack tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Jetpack is not active' );
		}
		$args = is_array( $args ) ? $args : ( is_object( $args ) ? (array) $args : [] );

		switch ( $name ) {
			case self::TOOL_STATS:
				return self::get_stats( $args );
			case self::TOOL_LIST:
				self::refuse_unknown_args( $args, [] );
				return self::list_modules();
			case self::TOOL_SET:
				return self::set_module( $args );
		}
		throw new \Exception( 'Unknown Jetpack tool: ' . esc_html( (string) $name ) );
	}

	// ============ Stats ============

	private static function get_stats( array $args ) {
		self::refuse_unknown_args( $args, [ 'what', 'period', 'num', 'limit' ] );
		$what   = self::enum_arg( $args, 'what', self::STATS_WHAT, 'summary' );
		$period = self::enum_arg( $args, 'period', self::STATS_PERIODS, 'day' );
		$num    = self::int_arg( $args, 'num', 7, 1, self::MAX_PERIODS );
		$limit  = self::int_arg( $args, 'limit', 10, 1, self::MAX_LIST_ITEMS );

		if ( ! self::connected() ) {
			throw new Tool_Refusal( 'not_connected', 'Jetpack is not connected to WordPress.com, so its stats cannot be read. An administrator can connect it in wp-admin under Jetpack.' );
		}
		$reader = self::STATS_READER;
		if ( ! class_exists( $reader ) || ! is_callable( [ $reader, 'get_visits' ] ) || ! is_callable( [ $reader, 'get_top_content' ] ) ) {
			throw new Tool_Refusal( 'jetpack_unsupported', 'This version of Jetpack does not include the stats reader these tools use. Update Jetpack to read its stats here.' );
		}

		if ( 'summary' === $what ) {
			$raw = call_user_func( [ $reader, 'get_visits' ], [ 'period' => $period, 'num' => $num, 'fields' => [ 'views', 'visitors' ] ] );
		} else {
			$raw = call_user_func( [ $reader, 'get_top_content' ], [ 'type' => 'top-posts' === $what ? 'posts' : 'referrers', 'period' => $period, 'num' => $num, 'max' => $limit ] );
		}
		if ( is_wp_error( $raw ) ) {
			self::refuse_stats_error( $raw );
		}
		if ( ! is_array( $raw ) ) {
			throw new Tool_Refusal( 'stats_unavailable', 'Jetpack returned no stats. Try again in a few minutes.' );
		}

		$module_on = \Jetpack::is_module_active( 'stats' );
		$out       = [
			'what'                => $what,
			'period'              => $period,
			'num'                 => $num,
			'date'                => isset( $raw['date'] ) ? (string) $raw['date'] : current_time( 'Y-m-d' ),
			'stats_module_active' => $module_on,
		];
		if ( 'summary' === $what ) {
			$series = [];
			$total  = 0;
			foreach ( (array) ( $raw['series'] ?? [] ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$views    = isset( $row['views'] ) && is_numeric( $row['views'] ) ? (int) $row['views'] : null;
				$visitors = isset( $row['visitors'] ) && is_numeric( $row['visitors'] ) ? (int) $row['visitors'] : null;
				$series[] = [ 'date' => (string) ( $row['date'] ?? '' ), 'views' => $views, 'visitors' => $visitors ];
				$total    = ( null === $total || null === $views ) ? null : $total + $views;
			}
			$out['series']      = $series;
			$out['total_views'] = [] === $series ? null : $total;
		} else {
			$items = [];
			foreach ( (array) ( $raw['items'] ?? [] ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$row = [
					'rank'  => (int) ( $item['rank'] ?? 0 ),
					'label' => wp_strip_all_tags( (string) ( $item['label'] ?? '' ) ),
					'views' => (int) ( $item['value'] ?? 0 ),
				];
				if ( isset( $item['href'] ) && is_string( $item['href'] ) && '' !== $item['href'] ) {
					$row['url'] = esc_url_raw( $item['href'] );
				}
				if ( isset( $item['id'] ) && (int) $item['id'] > 0 ) {
					$row['post_id'] = (int) $item['id'];
				}
				$items[] = $row;
			}
			$out['limit'] = $limit;
			$out['items'] = $items;
		}
		if ( ! $module_on ) {
			$out['note'] = 'The Jetpack Stats module is off, so new visits are not being counted.';
		}
		return $out;
	}

	private static function refuse_stats_error( \WP_Error $error ) {
		$code = sanitize_key( (string) $error->get_error_code() );
		if ( in_array( $code, [ 'site_not_connected', 'missing_token', 'no_possible_tokens', 'malformed_token', 'invalid_token', 'unknown_token', 'signature_mismatch' ], true ) ) {
			throw new Tool_Refusal( 'not_connected', 'Jetpack is not connected to WordPress.com, so its stats cannot be read. An administrator can connect it in wp-admin under Jetpack.' );
		}
		throw new Tool_Refusal( 'stats_unavailable', 'WordPress.com did not return the stats. Try again in a few minutes.', [ 'jetpack_error' => $code ] );
	}

	// ============ Modules ============

	private static function list_modules() {
		$connected = self::connected();
		$offline   = self::offline();
		$active    = array_flip( (array) \Jetpack::get_active_modules() );
		$modules   = [];
		foreach ( self::available_slugs() as $slug ) {
			$mod = \Jetpack::get_module( $slug );
			if ( ! is_array( $mod ) ) {
				continue;
			}
			$info           = self::module_info( $slug, $mod, $connected, $offline );
			$info['active'] = isset( $active[ $slug ] );
			$modules[]      = $info;
		}
		usort( $modules, static function ( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] ) ?: strcmp( $a['slug'], $b['slug'] );
		} );
		$ordered = [];
		foreach ( $modules as $m ) {
			$ordered[] = [
				'slug'                     => $m['slug'],
				'name'                     => $m['name'],
				'description'              => $m['description'],
				'active'                   => $m['active'],
				'requires_connection'      => $m['requires_connection'],
				'requires_user_connection' => $m['requires_user_connection'],
				'available_on_plan'        => $m['available_on_plan'],
				'forced'                   => $m['forced'],
				'can_activate_now'         => ! $m['active'] && $m['can_activate'],
			];
		}
		return [
			'connected'    => $connected,
			'offline_mode' => $offline,
			'count'        => count( $ordered ),
			'active_count' => count( array_filter( array_column( $ordered, 'active' ) ) ),
			'modules'      => $ordered,
		];
	}

	private static function set_module( array $args ) {
		if ( \Royal_MCP\MCP\Server::read_only_mode() ) {
			throw new Tool_Refusal( 'read_only_mode', 'This site is in read-only mode: tools that change the site are switched off.' );
		}
		self::refuse_unknown_args( $args, [ 'module', 'active', 'confirm' ] );
		$slug = self::module_arg( $args );
		if ( ! array_key_exists( 'active', $args ) || ! is_bool( $args['active'] ) ) {
			throw new Tool_Refusal( 'invalid_args', 'active must be true or false.' );
		}
		if ( isset( $args['confirm'] ) && ! is_bool( $args['confirm'] ) ) {
			throw new Tool_Refusal( 'invalid_args', 'confirm must be true or false.' );
		}
		$confirm = true === ( $args['confirm'] ?? null );
		$desired = $args['active'];
		$before  = \Jetpack::is_module_active( $slug );
		$name    = self::module_name( $slug );
		if ( $before === $desired ) {
			return Envelope::success(
				sprintf( 'The %s module is already %s; nothing changed.', $name, $desired ? 'on' : 'off' ),
				[ 'module' => $slug, 'name' => $name, 'active' => $before, 'changed' => false ]
			);
		}
		$effect = self::sensitive_effect( $slug, $desired );
		if ( null !== $effect && ! $confirm ) {
			throw new Tool_Refusal(
				'confirm_required',
				sprintf( 'Turning the %1$s module %2$s %3$s. Nothing was changed. Call again with confirm: true to go ahead.', $name, $desired ? 'on' : 'off', $effect ),
				[ 'module' => $slug, 'active' => $desired ]
			);
		}
		$note = self::apply_state( $slug, $desired );
		$undo = Undo_Store::store( [
			'op'           => self::TOOL_SET,
			'summary'      => sprintf( 'Turn the Jetpack %s module back %s', $name, $before ? 'on' : 'off' ),
			'target'       => [ 'module' => $slug ],
			'pre_op_state' => [ 'before' => $before, 'after' => $desired ],
		] );
		$struct = [ 'module' => $slug, 'name' => $name, 'active' => $desired, 'changed' => true ];
		if ( '' !== $note ) {
			$struct['note'] = $note;
		}
		return Envelope::success(
			sprintf( 'Turned the Jetpack %s module %s.', $name, $desired ? 'on' : 'off' ) . ( '' !== $note ? ' ' . $note : '' ),
			$struct,
			$undo ?: null
		);
	}

	/**
	 * Undo of jetpack_set_module: switches the module back to its earlier state.
	 * For a module on the sensitive list the undo needs $force, in either direction.
	 */
	public static function undo( array $snapshot, $force = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use Jetpack tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'Jetpack is no longer active, so this change cannot be undone.' );
		}
		if ( self::TOOL_SET !== ( $snapshot['op'] ?? '' ) ) {
			throw new \Exception( 'Unsupported Jetpack undo.' );
		}
		$slug = (string) ( $snapshot['target']['module'] ?? '' );
		$pre  = (array) ( $snapshot['pre_op_state'] ?? [] );
		if ( '' === $slug || ! is_bool( $pre['before'] ?? null ) ) {
			throw new \Exception( 'Undo snapshot missing the earlier state.' );
		}
		if ( ! in_array( $slug, self::available_slugs(), true ) ) {
			throw new Tool_Refusal( 'module_not_found', 'This Jetpack module is no longer available on the site, so the change cannot be undone.' );
		}
		$before = $pre['before'];
		$name   = self::module_name( $slug );
		if ( \Jetpack::is_module_active( $slug ) === $before ) {
			return Envelope::success(
				sprintf( 'The %s module is already %s; nothing to undo.', $name, $before ? 'on' : 'off' ),
				[ 'undone' => false, 'op' => self::TOOL_SET, 'reason' => 'already_restored', 'module' => $slug, 'active' => $before ]
			);
		}
		if ( ! $force && isset( self::SENSITIVE[ $slug ] ) ) {
			$effect = self::sensitive_effect( $slug, $before );
			$what   = null !== $effect
				? sprintf( 'Undoing this turns the %1$s module %2$s again, which %3$s.', $name, $before ? 'on' : 'off', $effect )
				: sprintf( 'Undoing this switches the %1$s module, a security or sign-in setting, back %2$s.', $name, $before ? 'on' : 'off' );
			throw new Tool_Refusal(
				'confirm_required',
				$what . ' Nothing was changed and the undo token stays valid: undo again with force: true to go ahead.',
				[ 'module' => $slug, 'active' => $before ]
			);
		}
		$note = self::apply_state( $slug, $before );
		$out  = [ 'undone' => true, 'op' => self::TOOL_SET, 'module' => $slug, 'active' => $before ];
		if ( '' !== $note ) {
			$out['note'] = $note;
		}
		return Envelope::success( sprintf( 'The Jetpack %s module is %s again.', $name, $before ? 'on' : 'off' ), $out );
	}

	/**
	 * Switches a module through Jetpack and checks it reached the asked state.
	 * Returns a note for the reply ('' when there is none); throws a refusal otherwise.
	 */
	private static function apply_state( $slug, $desired ) {
		$mod = \Jetpack::get_module( $slug );
		if ( ! is_array( $mod ) ) {
			throw new Tool_Refusal( 'module_not_found', 'Unknown Jetpack module. Use jetpack_list_modules for the list.' );
		}
		$name   = self::module_name( $slug );
		$forced = self::forced( $slug );
		if ( 'none' !== $forced && ( 'active' === $forced ) !== $desired ) {
			throw new Tool_Refusal( 'forced_by_site', sprintf( 'A filter or the host keeps the %s module %s, so it cannot be switched here.', $name, 'active' === $forced ? 'on' : 'off' ) );
		}

		if ( $desired ) {
			$connected = self::connected();
			if ( ! $connected && ! empty( $mod['requires_connection'] ) ) {
				throw new Tool_Refusal( 'needs_connection', sprintf( 'The %s module needs a connection to WordPress.com, and Jetpack is not connected. An administrator can connect Jetpack in wp-admin under Jetpack, then turn the module on.', $name ) );
			}
			if ( ! $connected && ! self::offline() ) {
				throw new Tool_Refusal( 'not_connected', sprintf( 'Jetpack turns modules on only while it is connected to WordPress.com or in offline mode, and it is neither, so the %s module cannot be turned on. An administrator can connect Jetpack in wp-admin under Jetpack.', $name ) );
			}
			if ( ! self::plan_supports( $slug ) ) {
				throw new Tool_Refusal( 'not_in_plan', sprintf( 'The site\'s Jetpack plan does not include the %s module.', $name ) );
			}
			$conflict = self::conflicting_plugin( $slug );
			if ( null !== $conflict ) {
				throw new Tool_Refusal( 'conflicting_plugin', sprintf( 'The %1$s module cannot be turned on while the %2$s plugin is active. Deactivate that plugin on the Plugins screen first.', $name, $conflict ) );
			}
			$level = ob_get_level();
			ob_start();
			try {
				$ok = \Jetpack::activate_module( $slug, false, false );
			} finally {
				while ( ob_get_level() > $level ) {
					ob_end_clean();
				}
			}
			if ( ! $ok ) {
				throw new Tool_Refusal( 'activation_failed', sprintf( 'Jetpack did not turn the %s module on.', $name ) );
			}
		} else {
			$level = ob_get_level();
			ob_start();
			try {
				\Jetpack::deactivate_module( $slug );
			} finally {
				while ( ob_get_level() > $level ) {
					ob_end_clean();
				}
			}
		}

		// A filter on the active list can keep a module on or off after the switch.
		if ( \Jetpack::is_module_active( $slug ) !== $desired ) {
			throw new Tool_Refusal( 'state_mismatch', sprintf( 'The %s module did not reach the asked state; a filter on the site may be keeping it as it was.', $name ) );
		}

		$note = '';
		if ( $desired && ! empty( $mod['requires_user_connection'] ) ) {
			$note = 'This module needs a WordPress.com user connection to do its work.';
		}
		if ( ! $desired && is_callable( [ 'Jetpack', 'get_module_deactivation_note' ] ) ) {
			$note = wp_strip_all_tags( (string) \Jetpack::get_module_deactivation_note( $slug ) );
		}
		return $note;
	}

	// ============ Helpers ============

	/** What switching the module to $active does when that switch needs confirm, else null. */
	private static function sensitive_effect( $slug, $active ) {
		if ( ! isset( self::SENSITIVE[ $slug ] ) || self::SENSITIVE[ $slug ][0] !== (bool) $active ) {
			return null;
		}
		return self::SENSITIVE[ $slug ][1];
	}

	private static function connected() {
		return (bool) \Jetpack::is_connection_ready();
	}

	private static function offline() {
		return class_exists( 'Automattic\\Jetpack\\Status' ) && ( new \Automattic\Jetpack\Status() )->is_offline_mode();
	}

	/** @return string[] */
	private static function available_slugs() {
		return array_values( array_filter( (array) \Jetpack::get_available_modules(), 'is_string' ) );
	}

	private static function plan_supports( $slug ) {
		if ( is_callable( [ 'Automattic\\Jetpack\\Current_Plan', 'supports' ] ) ) {
			return (bool) \Automattic\Jetpack\Current_Plan::supports( $slug );
		}
		return true;
	}

	/** 'active' or 'inactive' when a filter or the host forces the module's state, else 'none'. */
	private static function forced( $slug ) {
		if ( ! class_exists( 'Jetpack_Modules_Overrides' ) || ! is_callable( [ 'Jetpack_Modules_Overrides', 'instance' ] ) ) {
			return 'none';
		}
		$override = \Jetpack_Modules_Overrides::instance()->get_module_override( $slug );
		return in_array( $override, [ 'active', 'inactive' ], true ) ? $override : 'none';
	}

	/**
	 * Name of an active standalone plugin that Jetpack would deactivate (with a
	 * redirect) when the module is turned on, or null when there is none.
	 */
	private static function conflicting_plugin( $slug ) {
		$jetpack = \Jetpack::init();
		if ( ! is_object( $jetpack ) || empty( $jetpack->plugins_to_deactivate[ $slug ] ) || ! is_array( $jetpack->plugins_to_deactivate[ $slug ] ) ) {
			return null;
		}
		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active_plugins = null;
		foreach ( $jetpack->plugins_to_deactivate[ $slug ] as $candidate ) {
			if ( ! is_array( $candidate ) || ! isset( $candidate[0], $candidate[1] ) ) {
				continue;
			}
			if ( is_plugin_active( (string) $candidate[0] ) ) {
				return (string) $candidate[1];
			}
			if ( null === $active_plugins ) {
				$active_plugins = (array) \Jetpack::get_active_plugins();
			}
			foreach ( $active_plugins as $active ) {
				$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $active, false, false );
				if ( isset( $data['Name'] ) && $data['Name'] === $candidate[1] ) {
					return (string) $candidate[1];
				}
			}
		}
		return null;
	}

	private static function module_name( $slug ) {
		$i18n = function_exists( 'jetpack_get_module_i18n' ) ? jetpack_get_module_i18n( $slug ) : null;
		if ( is_array( $i18n ) && ! empty( $i18n['name'] ) ) {
			return wp_strip_all_tags( (string) $i18n['name'] );
		}
		$mod = \Jetpack::get_module( $slug );
		return is_array( $mod ) && ! empty( $mod['name'] ) ? wp_strip_all_tags( (string) $mod['name'] ) : $slug;
	}

	private static function module_info( $slug, array $mod, $connected, $offline ) {
		$i18n     = function_exists( 'jetpack_get_module_i18n' ) ? jetpack_get_module_i18n( $slug ) : null;
		$desc     = is_array( $i18n ) && ! empty( $i18n['description'] ) ? (string) $i18n['description'] : (string) ( $mod['description'] ?? '' );
		$needs    = ! empty( $mod['requires_connection'] );
		$on_plan  = self::plan_supports( $slug );
		$forced   = self::forced( $slug );
		$can      = $on_plan && 'inactive' !== $forced && ( $connected || ( $offline && ! $needs ) );
		return [
			'slug'                     => $slug,
			'name'                     => self::module_name( $slug ),
			'description'              => wp_strip_all_tags( $desc ),
			'requires_connection'      => $needs,
			'requires_user_connection' => ! empty( $mod['requires_user_connection'] ),
			'available_on_plan'        => $on_plan,
			'forced'                   => $forced,
			'can_activate'             => $can,
		];
	}

	private static function module_arg( array $args ) {
		$v = $args['module'] ?? null;
		if ( ! is_string( $v ) || '' === $v ) {
			throw new Tool_Refusal( 'invalid_args', 'module must be a module slug. Use jetpack_list_modules for the list.' );
		}
		if ( ! preg_match( '/^[a-z0-9-]{1,64}$/', $v ) || ! in_array( $v, self::available_slugs(), true ) ) {
			throw new Tool_Refusal( 'module_not_found', 'Unknown Jetpack module. Use jetpack_list_modules for the list.' );
		}
		return $v;
	}

	private static function refuse_unknown_args( array $args, array $allowed ) {
		$unknown = array_diff( array_map( 'strval', array_keys( $args ) ), $allowed );
		if ( $unknown ) {
			throw new Tool_Refusal( 'invalid_args', sprintf( 'Unknown argument: %s.', esc_html( mb_substr( implode( ', ', $unknown ), 0, 100 ) ) ) );
		}
	}

	private static function enum_arg( array $args, $key, array $allowed, $default ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			return $default;
		}
		if ( ! is_string( $args[ $key ] ) || ! in_array( $args[ $key ], $allowed, true ) ) {
			throw new Tool_Refusal( 'invalid_args', sprintf( '%s must be one of: %s.', $key, implode( ', ', $allowed ) ) );
		}
		return $args[ $key ];
	}

	private static function int_arg( array $args, $key, $default, $min, $max ) {
		if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
			return $default;
		}
		$v = $args[ $key ];
		if ( ! is_int( $v ) && ! ( is_string( $v ) && preg_match( '/^-?\d{1,9}$/', $v ) ) ) {
			throw new Tool_Refusal( 'invalid_args', sprintf( '%s must be a whole number from %d to %d.', $key, $min, $max ) );
		}
		$v = (int) $v;
		if ( $v < $min || $v > $max ) {
			throw new Tool_Refusal( 'invalid_args', sprintf( '%s must be a whole number from %d to %d.', $key, $min, $max ) );
		}
		return $v;
	}
}
