<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BuddyPress MCP Integration
 *
 * Detection via BP_VERSION covers both BuddyPress (wp.org) and BuddyBoss
 * Platform (commercial fork) — BB defines BP_VERSION as it extends BP's
 * class lineage. Same reads work against either install.
 *
 * Component-gated tools (groups, activity) check bp_is_active() and return
 * an unavailable envelope when the component is off in Settings → BuddyPress.
 */
class BuddyPress {

	public static function is_available() {
		return defined( 'BP_VERSION' ) && class_exists( '\\BuddyPress' );
	}

	public static function get_tools() {
		return [
			[
				'name'        => 'bp_list_members',
				'description' => 'List BuddyPress community members with display name, username, email, registration date, and last activity. Supports pagination via limit + offset, ordering via type (active/newest/alphabetical/popular), and search via search_terms.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'        => [ 'type' => 'integer', 'description' => 'Max members to return (default 20, max 200).' ],
						'offset'       => [ 'type' => 'integer', 'description' => 'Row offset for pagination (default 0).' ],
						'type'         => [ 'type' => 'string', 'description' => 'Ordering: active (default), newest, alphabetical, popular, random.', 'enum' => [ 'active', 'newest', 'alphabetical', 'popular', 'random' ] ],
						'search_terms' => [ 'type' => 'string', 'description' => 'Optional search string to match against user names / logins.' ],
					],
				],
			],
			[
				'name'        => 'bp_get_member',
				'description' => 'Get a single BuddyPress member profile by WordPress user ID — display name, username, email, registration date, last activity, and community-role summary.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'user_id' => [ 'type' => 'integer', 'description' => 'WordPress user ID.' ],
					],
					'required'   => [ 'user_id' ],
				],
			],
			[
				'name'        => 'bp_list_groups',
				'description' => 'List BuddyPress community groups with name, slug, description, member count, and status. Requires the Groups component to be enabled — returns {state: unavailable, reason: component_disabled} when off.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'   => [ 'type' => 'integer', 'description' => 'Max groups to return (default 20, max 200).' ],
						'offset'  => [ 'type' => 'integer', 'description' => 'Row offset for pagination (default 0).' ],
						'orderby' => [ 'type' => 'string', 'description' => 'Sort column: date_created (default), last_activity, total_member_count, name.', 'enum' => [ 'date_created', 'last_activity', 'total_member_count', 'name' ] ],
					],
				],
			],
			[
				'name'        => 'bp_get_activity_feed',
				'description' => 'Read the BuddyPress activity stream — recent posts, comments, group activity, profile updates. Requires the Activity component to be enabled. Filter by user_id to scope to one member.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'   => [ 'type' => 'integer', 'description' => 'Max activity items to return (default 20, max 200).' ],
						'offset'  => [ 'type' => 'integer', 'description' => 'Row offset for pagination (default 0).' ],
						'user_id' => [ 'type' => 'integer', 'description' => 'Optional — filter to activity by a single WordPress user ID.' ],
					],
				],
			],
		];
	}

	public static function execute_tool( $name, $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'You do not have permission to use BuddyPress tools.' );
		}
		if ( ! self::is_available() ) {
			throw new \Exception( 'BuddyPress is not active' );
		}

		switch ( $name ) {
			case 'bp_list_members':
				return self::handle_list_members( $args );
			case 'bp_get_member':
				return self::handle_get_member( $args );
			case 'bp_list_groups':
				return self::handle_list_groups( $args );
			case 'bp_get_activity_feed':
				return self::handle_get_activity_feed( $args );
			default:
				throw new \Exception( 'Unknown BuddyPress tool: ' . esc_html( $name ) );
		}
	}

	private static function handle_list_members( $args ) {
		$limit    = min( max( intval( $args['limit']  ?? 20 ), 1 ), 200 );
		$offset   = max( intval( $args['offset'] ?? 0 ), 0 );
		$page     = (int) floor( $offset / $limit ) + 1;
		$type     = in_array( $args['type'] ?? 'active', [ 'active', 'newest', 'alphabetical', 'popular', 'random' ], true ) ? ( $args['type'] ?? 'active' ) : 'active';
		$search   = isset( $args['search_terms'] ) ? sanitize_text_field( $args['search_terms'] ) : false;

		$q_args = [
			'type'         => $type,
			'per_page'     => $limit,
			'page'         => $page,
			'search_terms' => $search,
			'count_total'  => 'count_query',
		];

		$result = bp_core_get_users( $q_args );
		$users  = $result['users'] ?? [];
		$total  = (int) ( $result['total'] ?? 0 );

		$out = [];
		foreach ( (array) $users as $u ) {
			$out[] = self::format_member_summary( $u );
		}

		$envelope = [
			'limit'   => $limit,
			'offset'  => $offset,
			'count'   => count( $out ),
			'total'   => $total,
			'entries' => $out,
		];

		// Diagnostic note for the "zero results but the site has WP users" case.
		// BP_User_Query filters out users who have never had bp_last_activity
		// populated (i.e. never viewed any BuddyPress page), so a fresh install
		// with real WP users can return count/total = 0 in ways that mislead
		// callers into thinking the site has no members at all. Only surface
		// the note when the total is zero AND the WP user table has users AND
		// no search-term scoping was applied (so the note is informational,
		// not noise on legitimate empty search results).
		if ( 0 === $total && ! $search ) {
			$wp_user_total = (int) count_users()['total_users'] ?? 0;
			if ( $wp_user_total > 0 ) {
				$envelope['note'] = 'No BuddyPress-active members found, but this site has ' . $wp_user_total . ' WordPress user(s). BuddyPress lists only members with a bp_last_activity record — users become listable after visiting any BuddyPress page or after bp_update_user_last_activity() is called for them. This is not the same as "site has no users."';
			}
		}

		return $envelope;
	}

	private static function handle_get_member( $args ) {
		$user_id = intval( $args['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			throw new \Exception( 'user_id is required' );
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			throw new \Exception( 'User not found: ' . $user_id );
		}

		$last_activity   = function_exists( 'bp_get_user_last_activity' ) ? bp_get_user_last_activity( $user_id ) : '';
		$profile_display = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $user_id ) : $user->display_name;
		$profile_url     = function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $user_id ) : '';

		return [
			'id'                  => (int) $user_id,
			'user_login'          => $user->user_login,
			'display_name'        => $profile_display,
			'user_email'          => $user->user_email,
			'user_registered'     => $user->user_registered,
			'user_registered_iso' => self::to_iso( $user->user_registered ),
			'last_activity'       => $last_activity,
			'last_activity_iso'   => self::to_iso( $last_activity ),
			'roles'               => (array) $user->roles,
			'profile_url'         => $profile_url,
		];
	}

	private static function handle_list_groups( $args ) {
		if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'groups' ) ) {
			return [
				'state'   => 'unavailable',
				'reason'  => 'component_disabled',
				'message' => 'The BuddyPress Groups component is disabled. Enable it under Settings → BuddyPress → Components.',
			];
		}

		$limit   = min( max( intval( $args['limit']  ?? 20 ), 1 ), 200 );
		$offset  = max( intval( $args['offset'] ?? 0 ), 0 );
		$page    = (int) floor( $offset / $limit ) + 1;
		$orderby = in_array( $args['orderby'] ?? 'date_created', [ 'date_created', 'last_activity', 'total_member_count', 'name' ], true ) ? ( $args['orderby'] ?? 'date_created' ) : 'date_created';

		$result = groups_get_groups( [
			'per_page' => $limit,
			'page'     => $page,
			'orderby'  => $orderby,
			'order'    => 'DESC',
		] );

		$groups = $result['groups'] ?? [];
		$total  = (int) ( $result['total'] ?? 0 );

		$out = [];
		foreach ( (array) $groups as $g ) {
			$out[] = self::format_group( $g );
		}

		return [
			'limit'   => $limit,
			'offset'  => $offset,
			'count'   => count( $out ),
			'total'   => $total,
			'entries' => $out,
		];
	}

	private static function handle_get_activity_feed( $args ) {
		if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'activity' ) ) {
			return [
				'state'   => 'unavailable',
				'reason'  => 'component_disabled',
				'message' => 'The BuddyPress Activity component is disabled. Enable it under Settings → BuddyPress → Components.',
			];
		}

		$limit   = min( max( intval( $args['limit']  ?? 20 ), 1 ), 200 );
		$offset  = max( intval( $args['offset'] ?? 0 ), 0 );
		$page    = (int) floor( $offset / $limit ) + 1;

		$q_args = [
			'per_page'    => $limit,
			'page'        => $page,
			'sort'        => 'DESC',
			'count_total' => true,
		];
		if ( ! empty( $args['user_id'] ) ) {
			$q_args['filter'] = [ 'user_id' => intval( $args['user_id'] ) ];
		}

		$result     = bp_activity_get( $q_args );
		$activities = $result['activities'] ?? [];
		$total      = (int) ( $result['total'] ?? 0 );

		$out = [];
		foreach ( (array) $activities as $a ) {
			$out[] = self::format_activity( $a );
		}

		return [
			'limit'   => $limit,
			'offset'  => $offset,
			'count'   => count( $out ),
			'total'   => $total,
			'entries' => $out,
		];
	}

	private static function format_member_summary( $user ) {
		// BP_User_Query hydrates a limited column set that varies by 'type'
		// query mode — user_email in particular is missing from the alphabetical
		// query path. Fall back to get_userdata() to close the gap without
		// changing the query args (which would change ordering semantics).
		$id            = isset( $user->ID ) ? (int) $user->ID : 0;
		$last_activity = $user->last_activity ?? '';
		$user_login    = $user->user_login ?? '';
		$user_email    = $user->user_email ?? '';
		$user_reg      = $user->user_registered ?? '';
		if ( $id > 0 && ( '' === $user_email || '' === $user_login || '' === $user_reg ) ) {
			$full = get_userdata( $id );
			if ( $full ) {
				if ( '' === $user_email ) $user_email = $full->user_email;
				if ( '' === $user_login ) $user_login = $full->user_login;
				if ( '' === $user_reg   ) $user_reg   = $full->user_registered;
			}
		}
		return [
			'id'                  => $id,
			'user_login'          => $user_login,
			'display_name'        => $user->display_name ?? '',
			'user_email'          => $user_email,
			'user_registered'     => $user_reg,
			'user_registered_iso' => self::to_iso( $user_reg ),
			'last_activity'       => $last_activity,
			'last_activity_iso'   => self::to_iso( $last_activity ),
		];
	}

	private static function format_group( $group ) {
		$date_created = $group->date_created ?? null;
		return [
			'id'                 => isset( $group->id ) ? (int) $group->id : null,
			'creator_id'         => isset( $group->creator_id ) ? (int) $group->creator_id : 0,
			'name'               => $group->name ?? '',
			'slug'               => $group->slug ?? '',
			'description'        => $group->description ?? '',
			'status'             => $group->status ?? '',
			'parent_id'          => isset( $group->parent_id ) ? (int) $group->parent_id : 0,
			'total_member_count' => isset( $group->total_member_count ) ? (int) $group->total_member_count : 0,
			'date_created'       => $date_created,
			'date_created_iso'   => self::to_iso( $date_created ),
			'last_activity'      => $group->last_activity ?? null,
			'last_activity_iso'  => self::to_iso( $group->last_activity ?? null ),
		];
	}

	private static function format_activity( $activity ) {
		$date_recorded = $activity->date_recorded ?? null;
		return [
			'id'                => isset( $activity->id ) ? (int) $activity->id : null,
			'user_id'           => isset( $activity->user_id ) ? (int) $activity->user_id : 0,
			'component'         => $activity->component ?? '',
			'type'              => $activity->type ?? '',
			'action'            => $activity->action ?? '',
			'content'           => $activity->content ?? '',
			'primary_link'      => $activity->primary_link ?? '',
			'item_id'           => isset( $activity->item_id ) ? (int) $activity->item_id : 0,
			'secondary_item_id' => isset( $activity->secondary_item_id ) ? (int) $activity->secondary_item_id : 0,
			'hide_sitewide'     => ! empty( $activity->hide_sitewide ),
			'date_recorded'     => $date_recorded,
			'date_recorded_iso' => self::to_iso( $date_recorded ),
		];
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
 * Manifest declaration. All BuddyPress tools are read-only in the Free tier.
 */
add_filter( 'royal_mcp_manifests', function ( $manifests ) {
	if ( ! defined( 'BP_VERSION' ) ) {
		return $manifests;
	}
	$manifests[] = [
		'royal_mcp_manifest_version' => '1.0',
		'plugin_slug'                => 'buddypress',
		'plugin_display_name'        => 'BuddyPress',
		'plugin_version'             => BP_VERSION,
		'vendor_name'                => 'BuddyPress team',
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
