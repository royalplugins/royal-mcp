<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Royal Ledger MCP Integration
 *
 * Registers MCP tools for the Royal Ledger cost-tracker and license-vault plugin.
 * Only loaded when Royal Ledger is active.
 *
 * SECURITY NOTE: License key VALUES are never exposed through MCP. The rl_get_keys
 * tool returns key names, masked previews, and metadata only — never the decrypted
 * key. Decrypting a stored key requires manually visiting the Royal Ledger admin.
 */
class RoyalLedger {

	/**
	 * Check if Royal Ledger is available.
	 */
	public static function is_available() {
		return class_exists( 'RLEDGER_Items' );
	}

	/**
	 * Get tool definitions for MCP tools/list response.
	 */
	public static function get_tools() {
		if ( ! self::is_available() ) {
			return [];
		}

		return [
			[
				'name'        => 'rl_get_costs',
				'description' => 'List Royal Ledger cost items (premium plugins, hosting, domains, CDN, SaaS subscriptions tracked by the user).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'category' => [ 'type' => 'string', 'description' => 'Optional category filter: plugins, themes, hosting, domains, saas, other' ],
						'status'   => [ 'type' => 'string', 'description' => 'Optional status filter: active, paused, expired (default: active)' ],
						'limit'    => [ 'type' => 'integer', 'description' => 'Max items to return (default 50)' ],
					],
				],
			],
			[
				'name'        => 'rl_create_cost',
				'description' => 'Add a new tracked cost item to Royal Ledger. Use when the user mentions a new subscription, hosting renewal, premium plugin purchase, etc.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'name'           => [ 'type' => 'string', 'description' => 'Item name, e.g. "WPForms Pro" or "SiteGround GoGeek"' ],
						'category'       => [ 'type' => 'string', 'enum' => [ 'plugins', 'themes', 'hosting', 'domains', 'saas', 'other' ] ],
						'cost'           => [ 'type' => 'number', 'description' => 'Cost amount per billing cycle' ],
						'currency'       => [ 'type' => 'string', 'description' => 'ISO 4217 currency code (default: USD)' ],
						'billing_cycle'  => [ 'type' => 'string', 'enum' => [ 'monthly', 'quarterly', 'annual', 'biennial', 'one-time', 'custom' ], 'description' => 'How often the cost recurs' ],
						'renewal_date'   => [ 'type' => 'string', 'description' => 'Next renewal date in YYYY-MM-DD format' ],
						'url'            => [ 'type' => 'string', 'description' => 'Vendor or product URL (optional)' ],
						'notes'          => [ 'type' => 'string', 'description' => 'Free-form notes (optional)' ],
					],
					'required'   => [ 'name', 'category', 'cost' ],
				],
			],
			[
				'name'        => 'rl_get_renewals',
				'description' => 'Get upcoming Royal Ledger renewals within N days. Useful for "what subscriptions am I about to be charged for?" queries.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit' => [ 'type' => 'integer', 'description' => 'Max renewals to return (default 10)' ],
					],
				],
			],
			[
				'name'        => 'rl_get_keys',
				'description' => 'List license keys stored in the Royal Ledger vault. Returns key name, associated cost item, masked preview (first 4 + last 4 chars), and expiry date. RAW DECRYPTED KEYS ARE NEVER RETURNED — that requires logging into the admin.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'item_id' => [ 'type' => 'integer', 'description' => 'Optional cost item ID to filter keys by' ],
						'limit'   => [ 'type' => 'integer', 'description' => 'Max keys to return (default 50)' ],
					],
				],
			],
		];
	}

	/**
	 * Execute a Royal Ledger MCP tool.
	 *
	 * @param string $name Tool name.
	 * @param array  $args Tool arguments.
	 * @return mixed Result data.
	 * @throws \Exception If tool fails.
	 */
	public static function execute_tool( $name, $args ) {
		if ( ! self::is_available() ) {
			throw new \Exception( 'Royal Ledger is not active' );
		}

		switch ( $name ) {
			case 'rl_get_costs':
				// 1.4.26 — Royal Ledger holds financial bookkeeping (costs,
				// renewals, licensed-products). manage_options matches the
				// plugin's own admin-screen gating.
				if ( ! current_user_can( 'manage_options' ) ) {
					throw new \Exception( 'You do not have permission to view Royal Ledger cost data.' );
				}
				$query = [
					'limit'  => min( intval( $args['limit'] ?? 50 ), 200 ),
					'status' => sanitize_text_field( $args['status'] ?? 'active' ),
				];
				if ( ! empty( $args['category'] ) ) {
					$query['category'] = sanitize_text_field( $args['category'] );
				}
				$items = \RLEDGER_Items::get_all( $query );
				return array_map( [ __CLASS__, 'format_cost_item' ], $items );

			case 'rl_create_cost':
				if ( ! current_user_can( 'manage_options' ) ) {
					throw new \Exception( 'You do not have permission to create Royal Ledger cost entries.' );
				}
				$name_arg = sanitize_text_field( $args['name'] ?? '' );
				if ( empty( $name_arg ) ) {
					throw new \Exception( 'name is required' );
				}
				$category = sanitize_text_field( $args['category'] ?? 'other' );
				$valid    = [ 'plugins', 'themes', 'hosting', 'domains', 'saas', 'other' ];
				if ( ! in_array( $category, $valid, true ) ) {
					throw new \Exception( 'Invalid category. Allowed: ' . esc_html( implode( ', ', $valid ) ) );
				}
				$data = [
					'name'          => $name_arg,
					'category'      => $category,
					'cost'          => floatval( $args['cost'] ?? 0 ),
					'currency'      => sanitize_text_field( $args['currency'] ?? 'USD' ),
					'billing_cycle' => sanitize_text_field( $args['billing_cycle'] ?? 'annual' ),
					'status'        => 'active',
				];
				if ( ! empty( $args['renewal_date'] ) ) {
					$data['renewal_date'] = sanitize_text_field( $args['renewal_date'] );
				}
				if ( ! empty( $args['url'] ) ) {
					$data['url'] = esc_url_raw( $args['url'] );
				}
				if ( ! empty( $args['notes'] ) ) {
					$data['notes'] = sanitize_textarea_field( $args['notes'] );
				}
				$item_id = \RLEDGER_Items::create( $data );
				if ( ! $item_id ) {
					throw new \Exception( 'Failed to create cost item' );
				}
				$created = \RLEDGER_Items::get( $item_id );
				return self::format_cost_item( $created );

			case 'rl_get_renewals':
				if ( ! current_user_can( 'manage_options' ) ) {
					throw new \Exception( 'You do not have permission to view Royal Ledger renewal data.' );
				}
				$limit  = min( intval( $args['limit'] ?? 10 ), 100 );
				$items  = \RLEDGER_Items::get_upcoming_renewals( $limit );
				return array_map(
					function ( $item ) {
						$days_until = $item->renewal_date ? (int) ( ( strtotime( $item->renewal_date ) - time() ) / DAY_IN_SECONDS ) : null;
						return [
							'id'            => (int) $item->id,
							'name'          => $item->name,
							'category'      => $item->category,
							'cost'          => (float) $item->cost,
							'currency'      => $item->currency,
							'billing_cycle' => $item->billing_cycle,
							'renewal_date'  => $item->renewal_date,
							'days_until'    => $days_until,
							'url'           => $item->url,
						];
					},
					$items
				);

			case 'rl_get_keys':
				// 1.4.26 — license-key listings (even masked) are admin-only.
				// The masked preview still reveals the existence + prefix of
				// every license key on the site.
				if ( ! current_user_can( 'manage_options' ) ) {
					throw new \Exception( 'You do not have permission to view Royal Ledger license keys.' );
				}
				if ( ! class_exists( 'RLEDGER_Keys' ) ) {
					throw new \Exception( 'RLEDGER_Keys class not loaded' );
				}
				$query = [ 'limit' => min( intval( $args['limit'] ?? 50 ), 200 ) ];
				if ( ! empty( $args['item_id'] ) ) {
					$query['item_id'] = intval( $args['item_id'] );
				}
				$keys = \RLEDGER_Keys::get_all( $query );
				return array_map(
					function ( $key ) {
						// Get masked preview ONLY — decrypt internally, mask, then discard the decrypted value.
						$preview = '';
						if ( method_exists( '\RLEDGER_Keys', 'get_decrypted' ) && method_exists( '\RLEDGER_Keys', 'mask_key' ) ) {
							$decrypted_obj = \RLEDGER_Keys::get_decrypted( $key->id );
							if ( $decrypted_obj && ! empty( $decrypted_obj->license_key ) && is_string( $decrypted_obj->license_key ) ) {
								$preview = \RLEDGER_Keys::mask_key( $decrypted_obj->license_key );
							}
						}
						return [
							'id'             => (int) $key->id,
							'item_id'        => (int) $key->item_id,
							'item_name'      => $key->item_name ?? '',
							'key_name'       => $key->key_name,
							'masked_preview' => $preview,
							'expiry_date'    => $key->expiry_date,
							'created_at'     => $key->created_at,
							'note'           => 'Decrypted key value is never exposed through MCP. To view, log into wp-admin > Royal Ledger > License Keys.',
						];
					},
					$keys
				);

			default:
				throw new \Exception( 'Unknown Royal Ledger tool: ' . esc_html( $name ) );
		}
	}

	/**
	 * Format a cost item for response.
	 */
	private static function format_cost_item( $item ) {
		if ( ! $item ) {
			return null;
		}
		$days_until = $item->renewal_date ? (int) ( ( strtotime( $item->renewal_date ) - time() ) / DAY_IN_SECONDS ) : null;
		return [
			'id'            => (int) $item->id,
			'name'          => $item->name,
			'category'      => $item->category,
			'cost'          => (float) $item->cost,
			'currency'      => $item->currency,
			'billing_cycle' => $item->billing_cycle,
			'renewal_date'  => $item->renewal_date,
			'days_until'    => $days_until,
			'status'        => $item->status,
			'url'           => $item->url,
			'notes'         => $item->notes,
		];
	}
}
