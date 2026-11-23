<?php
/**
 * Tool groups that move money, send mail to many people, change subscriber lists or change many
 * store items at once. Each group is off until a site administrator switches it on (Royal MCP →
 * Safety → What AI clients may do). While a group is off, AI clients are refused its tools, and
 * undoing their changes, on every connection.
 *
 * @package Royal_MCP
 */

namespace Royal_MCP\MCP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tool_Groups {

	/** Settings key inside royal_mcp_settings holding [ group => bool ]. */
	const SETTING = 'tool_groups';

	/** How far back a call counts as "in use" when the switches are first set on an existing site. */
	const IN_USE_DAYS = 30;

	/** Group key => its tools. */
	const TOOLS = [
		'affiliate_money' => [ 'rafp_update_referral_status', 'rafp_create_campaign', 'rafp_create_challenge', 'rafp_assign_landing_page' ],
		'mail'            => [ 'mailpoet_schedule_newsletter', 'mailpoet_send_test', 'mailpoet_save_subscriber', 'mailpoet_set_subscriber_lists' ],
		'bulk_store'      => [ 'wc_batch_update_variations' ],
	];

	/**
	 * Group key => label, description and its tools.
	 *
	 * @return array<string, array{label:string, description:string, tools:string[]}>
	 */
	public static function groups() {
		return [
			'affiliate_money' => [
				'label'       => __( 'Affiliate money', 'royal-mcp' ),
				'description' => __( 'Approve or reject referrals, create campaigns and bonus challenges, and assign landing pages in Royal Affiliate Pro. These change what affiliates are paid.', 'royal-mcp' ),
				'tools'       => self::TOOLS['affiliate_money'],
			],
			'mail'            => [
				'label'       => __( 'Mail and subscriber lists', 'royal-mcp' ),
				'description' => __( 'Schedule MailPoet newsletters to whole lists, send test emails, and add, change or move subscribers. These send mail to many people or change who receives it.', 'royal-mcp' ),
				'tools'       => self::TOOLS['mail'],
			],
			'bulk_store'      => [
				'label'       => __( 'Bulk store changes', 'royal-mcp' ),
				'description' => __( 'Create, change or permanently delete many WooCommerce product variations in one call.', 'royal-mcp' ),
				'tools'       => self::TOOLS['bulk_store'],
			],
		];
	}

	/** The group a tool belongs to, or '' for a tool in no group. */
	public static function group_of( $tool ) {
		static $map = null;
		if ( null === $map ) {
			$map = [];
			foreach ( self::TOOLS as $key => $tools ) {
				foreach ( $tools as $name ) {
					$map[ $name ] = $key;
				}
			}
		}
		return $map[ (string) $tool ] ?? '';
	}

	/**
	 * Stored on / off state of every group. The first time it is read on a site, each group is
	 * set on only when one of its tools was called successfully in the last IN_USE_DAYS days
	 * (so a site already using a group keeps working after the update) and off otherwise; a new
	 * site has no calls, so every group starts off. A group added later is set the same way the
	 * first time it is read.
	 *
	 * @return array<string, bool>
	 */
	public static function states() {
		$settings = get_option( 'royal_mcp_settings', [] );
		$stored   = is_array( $settings ) && isset( $settings[ self::SETTING ] ) && is_array( $settings[ self::SETTING ] ) ? $settings[ self::SETTING ] : [];
		$missing  = array_diff( array_keys( self::TOOLS ), array_keys( $stored ) );
		if ( $missing ) {
			$stored = self::store_missing( $missing );
		}
		$out = [];
		foreach ( array_keys( self::TOOLS ) as $key ) {
			$out[ $key ] = ! empty( $stored[ $key ] );
		}
		return $out;
	}

	public static function is_on( $group ) {
		$states = self::states();
		return ! empty( $states[ (string) $group ] );
	}

	/** Refusal text for a tool whose group is off, or null when the tool may run. */
	public static function refusal( $tool ) {
		$group = self::group_of( $tool );
		if ( '' === $group || self::is_on( $group ) ) {
			return null;
		}
		return sprintf(
			/* translators: 1: tool name, 2: group name */
			__( '%1$s is in the "%2$s" group, which is switched off on this site. Nothing was changed. A site administrator can switch it on in Royal MCP → Safety → What AI clients may do.', 'royal-mcp' ),
			(string) $tool,
			self::groups()[ $group ]['label']
		);
	}

	/**
	 * Sanitised group states from a settings save.
	 *
	 * @param mixed      $posted    Posted group values (the form sends only ticked boxes).
	 * @param array|null $prior     Stored states; null when they were never set.
	 * @param bool       $from_form Whether the input is the settings form (unticked = off).
	 * @return array<string, bool>
	 */
	public static function sanitize( $posted, $prior, $from_form ) {
		$posted = is_array( $posted ) ? $posted : [];
		$prior  = is_array( $prior ) ? $prior : [];
		$unset  = array_diff( array_keys( self::TOOLS ), array_keys( $prior ) );
		if ( $unset && ! $from_form ) {
			$prior = array_merge( $prior, self::initial_states( $unset ) );
		}
		$out = [];
		foreach ( array_keys( self::TOOLS ) as $key ) {
			if ( array_key_exists( $key, $posted ) ) {
				$v           = $posted[ $key ];
				$out[ $key ] = true === $v || ( is_scalar( $v ) && in_array( strtolower( trim( (string) $v ) ), [ '1', 'on', 'true', 'yes' ], true ) );
			} elseif ( $from_form ) {
				$out[ $key ] = false;
			} else {
				$out[ $key ] = ! empty( $prior[ $key ] );
			}
		}
		return $out;
	}

	/**
	 * Works out the missing groups and stores them. The option is read again just before the
	 * write and only this key is changed, so a settings change made meanwhile is kept; a group
	 * another request stored meanwhile is not overwritten.
	 *
	 * @param string[] $missing Group keys with no stored state.
	 * @return array<string, bool> The stored states after the write.
	 */
	private static function store_missing( array $missing ) {
		$found    = self::initial_states( $missing );
		wp_cache_delete( 'royal_mcp_settings', 'options' );
		$settings = get_option( 'royal_mcp_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];
		$stored   = isset( $settings[ self::SETTING ] ) && is_array( $settings[ self::SETTING ] ) ? $settings[ self::SETTING ] : [];
		$changed  = false;
		foreach ( $found as $key => $on ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				$stored[ $key ] = $on;
				$changed        = true;
			}
		}
		if ( $changed ) {
			$settings[ self::SETTING ] = $stored;
			\Royal_MCP\Admin\Settings_Page::save_programmatically( $settings );
		}
		return $stored;
	}

	/**
	 * On for each of the given groups with a successful call of one of its tools in the last
	 * IN_USE_DAYS days (one indexed query over the activity log), off otherwise.
	 *
	 * @param string[]|null $only Group keys to work out (all when null).
	 * @return array<string, bool>
	 */
	public static function initial_states( $only = null ) {
		global $wpdb;
		$keys    = null === $only ? array_keys( self::TOOLS ) : array_values( array_intersect( array_keys( self::TOOLS ), (array) $only ) );
		$out     = array_fill_keys( $keys, false );
		$actions = [];
		foreach ( $keys as $key ) {
			foreach ( self::TOOLS[ $key ] as $tool ) {
				$actions[] = 'tools/call:' . $tool;
			}
		}
		if ( ! $actions ) {
			return $out;
		}
		$table = $wpdb->prefix . 'royal_mcp_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time read of the plugin's own log table
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return $out;
		}
		$in = implode( ', ', array_fill( 0, count( $actions ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name from $wpdb->prefix; placeholders built above
		$used = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT action FROM `{$table}` WHERE timestamp >= %s AND status = 'success' AND action IN ( {$in} )", array_merge( [ gmdate( 'Y-m-d H:i:s', time() - self::IN_USE_DAYS * DAY_IN_SECONDS ) ], $actions ) ) );
		foreach ( $used as $action ) {
			$group = self::group_of( substr( (string) $action, strlen( 'tools/call:' ) ) );
			if ( '' !== $group && array_key_exists( $group, $out ) ) {
				$out[ $group ] = true;
			}
		}
		return $out;
	}
}
