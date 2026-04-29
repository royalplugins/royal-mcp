<?php
namespace Royal_MCP\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce MCP Integration
 *
 * Registers MCP tools for WooCommerce product, order, and customer management.
 * Only loaded when WooCommerce is active.
 */
class WooCommerce {

	/**
	 * Check if WooCommerce is available.
	 */
	public static function is_available() {
		return class_exists( 'WooCommerce' );
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
				'name'        => 'wc_get_products',
				'description' => 'Get WooCommerce products',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'per_page' => [ 'type' => 'integer', 'description' => 'Number of products (max 100)' ],
						'status'   => [ 'type' => 'string', 'description' => 'Product status (publish, draft, etc)' ],
						'category' => [ 'type' => 'string', 'description' => 'Category slug to filter by' ],
						'search'   => [ 'type' => 'string', 'description' => 'Search term' ],
						'type'     => [ 'type' => 'string', 'description' => 'Product type (simple, variable, grouped, external)' ],
					],
				],
			],
			[
				'name'        => 'wc_get_product',
				'description' => 'Get single WooCommerce product by ID',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'id' => [ 'type' => 'integer', 'description' => 'Product ID' ],
					],
					'required'   => [ 'id' ],
				],
			],
			[
				'name'        => 'wc_create_product',
				'description' => 'Create a WooCommerce product',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'name'          => [ 'type' => 'string', 'description' => 'Product name' ],
						'type'          => [ 'type' => 'string', 'enum' => [ 'simple', 'variable', 'grouped', 'external' ] ],
						'regular_price' => [ 'type' => 'string', 'description' => 'Regular price' ],
						'sale_price'    => [ 'type' => 'string', 'description' => 'Sale price' ],
						'description'   => [ 'type' => 'string', 'description' => 'Full description' ],
						'short_description' => [ 'type' => 'string', 'description' => 'Short description' ],
						'sku'           => [ 'type' => 'string', 'description' => 'SKU' ],
						'status'        => [ 'type' => 'string', 'enum' => [ 'publish', 'draft' ] ],
						'stock_quantity' => [ 'type' => 'integer', 'description' => 'Stock quantity' ],
						'categories'    => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Category IDs' ],
					],
					'required'   => [ 'name' ],
				],
			],
			[
				'name'        => 'wc_update_product',
				'description' => 'Update a WooCommerce product',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'id'            => [ 'type' => 'integer' ],
						'name'          => [ 'type' => 'string' ],
						'regular_price' => [ 'type' => 'string' ],
						'sale_price'    => [ 'type' => 'string' ],
						'description'   => [ 'type' => 'string' ],
						'short_description' => [ 'type' => 'string' ],
						'sku'           => [ 'type' => 'string' ],
						'status'        => [ 'type' => 'string' ],
						'stock_quantity' => [ 'type' => 'integer' ],
					],
					'required'   => [ 'id' ],
				],
			],
			[
				'name'        => 'wc_get_orders',
				'description' => 'Get WooCommerce orders',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'per_page' => [ 'type' => 'integer', 'description' => 'Number of orders (max 100)' ],
						'status'   => [ 'type' => 'string', 'description' => 'Order status (processing, completed, on-hold, etc)' ],
					],
				],
			],
			[
				'name'        => 'wc_get_order',
				'description' => 'Get single WooCommerce order by ID',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'id' => [ 'type' => 'integer', 'description' => 'Order ID' ],
					],
					'required'   => [ 'id' ],
				],
			],
			[
				'name'        => 'wc_update_order_status',
				'description' => 'Update WooCommerce order status',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'id'     => [ 'type' => 'integer', 'description' => 'Order ID' ],
						'status' => [ 'type' => 'string', 'description' => 'New status (processing, completed, on-hold, cancelled, refunded)' ],
						'note'   => [ 'type' => 'string', 'description' => 'Optional order note' ],
					],
					'required'   => [ 'id', 'status' ],
				],
			],
			[
				'name'        => 'wc_get_customers',
				'description' => 'Get WooCommerce customers',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'per_page' => [ 'type' => 'integer', 'description' => 'Number of customers (max 100)' ],
						'search'   => [ 'type' => 'string', 'description' => 'Search by name or email' ],
					],
				],
			],
			[
				'name'        => 'wc_get_store_stats',
				'description' => 'Get WooCommerce store statistics (revenue, orders, products)',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'period' => [ 'type' => 'string', 'description' => 'Period: today, week, month, year', 'enum' => [ 'today', 'week', 'month', 'year' ] ],
					],
				],
			],
		];
	}

	/**
	 * Execute a WooCommerce MCP tool.
	 *
	 * @param string $name Tool name.
	 * @param array  $args Tool arguments.
	 * @return mixed Result data.
	 * @throws \Exception If tool fails.
	 */
	public static function execute_tool( $name, $args ) {
		if ( ! self::is_available() ) {
			throw new \Exception( 'WooCommerce is not active' );
		}

		switch ( $name ) {
			case 'wc_get_products':
				$query_args = [
					'limit'  => min( intval( $args['per_page'] ?? 10 ), 100 ),
					'status' => sanitize_text_field( $args['status'] ?? 'publish' ),
					'return' => 'objects',
				];
				if ( ! empty( $args['search'] ) ) {
					$query_args['s'] = sanitize_text_field( $args['search'] );
				}
				if ( ! empty( $args['category'] ) ) {
					$query_args['category'] = [ sanitize_text_field( $args['category'] ) ];
				}
				if ( ! empty( $args['type'] ) ) {
					$query_args['type'] = sanitize_text_field( $args['type'] );
				}
				$products = wc_get_products( $query_args );
				return array_map( [ __CLASS__, 'format_product_summary' ], $products );

			case 'wc_get_product':
				$product = wc_get_product( intval( $args['id'] ) );
				if ( ! $product ) {
					throw new \Exception( 'Product not found' );
				}
				return self::format_product_detail( $product );

			case 'wc_create_product':
				$product = new \WC_Product_Simple();
				$product->set_name( sanitize_text_field( $args['name'] ) );
				if ( isset( $args['regular_price'] ) ) {
					$product->set_regular_price( sanitize_text_field( $args['regular_price'] ) );
				}
				if ( isset( $args['sale_price'] ) ) {
					$product->set_sale_price( sanitize_text_field( $args['sale_price'] ) );
				}
				if ( isset( $args['description'] ) ) {
					$product->set_description( wp_kses_post( $args['description'] ) );
				}
				if ( isset( $args['short_description'] ) ) {
					$product->set_short_description( wp_kses_post( $args['short_description'] ) );
				}
				if ( isset( $args['sku'] ) ) {
					$product->set_sku( sanitize_text_field( $args['sku'] ) );
				}
				if ( isset( $args['stock_quantity'] ) ) {
					$product->set_manage_stock( true );
					$product->set_stock_quantity( intval( $args['stock_quantity'] ) );
				}
				if ( isset( $args['categories'] ) ) {
					$product->set_category_ids( array_map( 'intval', $args['categories'] ) );
				}
				$product->set_status( in_array( $args['status'] ?? 'draft', [ 'publish', 'draft' ] ) ? $args['status'] : 'draft' );
				$product_id = $product->save();
				if ( ! $product_id ) {
					throw new \Exception( 'Failed to create product' );
				}
				return [ 'id' => $product_id, 'message' => 'Product created successfully', 'url' => get_permalink( $product_id ) ];

			case 'wc_update_product':
				$product = wc_get_product( intval( $args['id'] ) );
				if ( ! $product ) {
					throw new \Exception( 'Product not found' );
				}
				if ( isset( $args['name'] ) ) {
					$product->set_name( sanitize_text_field( $args['name'] ) );
				}
				if ( isset( $args['regular_price'] ) ) {
					$product->set_regular_price( sanitize_text_field( $args['regular_price'] ) );
				}
				if ( isset( $args['sale_price'] ) ) {
					$product->set_sale_price( sanitize_text_field( $args['sale_price'] ) );
				}
				if ( isset( $args['description'] ) ) {
					$product->set_description( wp_kses_post( $args['description'] ) );
				}
				if ( isset( $args['short_description'] ) ) {
					$product->set_short_description( wp_kses_post( $args['short_description'] ) );
				}
				if ( isset( $args['sku'] ) ) {
					$product->set_sku( sanitize_text_field( $args['sku'] ) );
				}
				if ( isset( $args['status'] ) ) {
					$product->set_status( sanitize_text_field( $args['status'] ) );
				}
				if ( isset( $args['stock_quantity'] ) ) {
					$product->set_manage_stock( true );
					$product->set_stock_quantity( intval( $args['stock_quantity'] ) );
				}
				$product->save();
				return [ 'id' => $args['id'], 'message' => 'Product updated successfully' ];

			case 'wc_get_orders':
				$limit  = min( intval( $args['per_page'] ?? 10 ), 100 );
				$status = ! empty( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'any';
				$orders = wc_get_orders( [
					'limit'  => $limit,
					'status' => $status,
					'orderby' => 'date',
					'order'   => 'DESC',
				] );
				return array_map( [ __CLASS__, 'format_order_summary' ], $orders );

			case 'wc_get_order':
				$order = wc_get_order( intval( $args['id'] ) );
				if ( ! $order ) {
					throw new \Exception( 'Order not found' );
				}
				return self::format_order_detail( $order );

			case 'wc_update_order_status':
				$order = wc_get_order( intval( $args['id'] ) );
				if ( ! $order ) {
					throw new \Exception( 'Order not found' );
				}
				$allowed_statuses = [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ];
				$new_status = sanitize_text_field( $args['status'] );
				if ( ! in_array( $new_status, $allowed_statuses ) ) {
					throw new \Exception( 'Invalid order status' );
				}
				$note = ! empty( $args['note'] ) ? sanitize_text_field( $args['note'] ) : '';
				$order->update_status( $new_status, $note );
				return [ 'id' => $args['id'], 'status' => $new_status, 'message' => 'Order status updated' ];

			case 'wc_get_customers':
				$limit = min( intval( $args['per_page'] ?? 10 ), 100 );
				$customer_args = [
					'number' => $limit,
					'role'   => 'customer',
				];
				if ( ! empty( $args['search'] ) ) {
					$customer_args['search']         = '*' . sanitize_text_field( $args['search'] ) . '*';
					$customer_args['search_columns']  = [ 'user_login', 'user_email', 'display_name' ];
				}
				$customers = get_users( $customer_args );
				return array_map( function( $user ) {
					$customer = new \WC_Customer( $user->ID );
					return [
						'id'           => $user->ID,
						'display_name' => $user->display_name,
						'order_count'  => $customer->get_order_count(),
						'total_spent'  => $customer->get_total_spent(),
						'city'         => $customer->get_billing_city(),
						'country'      => $customer->get_billing_country(),
					];
				}, $customers );

			case 'wc_get_store_stats':
				return self::get_store_stats( $args['period'] ?? 'month' );

			default:
				throw new \Exception( 'Unknown WooCommerce tool: ' . esc_html( $name ) );
		}
	}

	private static function format_product_summary( $product ) {
		return [
			'id'            => $product->get_id(),
			'name'          => $product->get_name(),
			'type'          => $product->get_type(),
			'status'        => $product->get_status(),
			'price'         => $product->get_price(),
			'regular_price' => $product->get_regular_price(),
			'sale_price'    => $product->get_sale_price(),
			'sku'           => $product->get_sku(),
			'stock_status'  => $product->get_stock_status(),
			'url'           => get_permalink( $product->get_id() ),
		];
	}

	private static function format_product_detail( $product ) {
		return [
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'type'              => $product->get_type(),
			'status'            => $product->get_status(),
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'price'             => $product->get_price(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'sku'               => $product->get_sku(),
			'stock_status'      => $product->get_stock_status(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'weight'            => $product->get_weight(),
			'categories'        => wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] ),
			'tags'              => wp_get_post_terms( $product->get_id(), 'product_tag', [ 'fields' => 'names' ] ),
			'url'               => get_permalink( $product->get_id() ),
			'date_created'      => $product->get_date_created() ? $product->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
		];
	}

	private static function format_order_summary( $order ) {
		return [
			'id'         => $order->get_id(),
			'status'     => $order->get_status(),
			'total'      => $order->get_total(),
			'currency'   => $order->get_currency(),
			'items'      => $order->get_item_count(),
			'customer'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'date'       => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
		];
	}

	private static function format_order_detail( $order ) {
		$items = [];
		foreach ( $order->get_items() as $item ) {
			$items[] = [
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'total'    => $item->get_total(),
				'sku'      => $item->get_product() ? $item->get_product()->get_sku() : '',
			];
		}
		return [
			'id'              => $order->get_id(),
			'status'          => $order->get_status(),
			'total'           => $order->get_total(),
			'subtotal'        => $order->get_subtotal(),
			'tax'             => $order->get_total_tax(),
			'shipping'        => $order->get_shipping_total(),
			'currency'        => $order->get_currency(),
			'payment_method'  => $order->get_payment_method_title(),
			'customer_name'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'billing_city'    => $order->get_billing_city(),
			'billing_country' => $order->get_billing_country(),
			'items'           => $items,
			'date_created'    => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
			'date_paid'       => $order->get_date_paid() ? $order->get_date_paid()->format( 'Y-m-d H:i:s' ) : null,
		];
	}

	private static function get_store_stats( $period ) {
		$periods = [
			'today' => '-1 day',
			'week'  => '-7 days',
			'month' => '-30 days',
			'year'  => '-365 days',
		];
		$after = gmdate( 'Y-m-d', strtotime( $periods[ $period ] ?? $periods['month'] ) );

		$orders = wc_get_orders( [
			'limit'      => -1,
			'status'     => [ 'completed', 'processing' ],
			'date_after' => $after,
			'return'     => 'objects',
		] );

		$revenue     = 0;
		$order_count = count( $orders );
		foreach ( $orders as $order ) {
			$revenue += (float) $order->get_total();
		}

		$product_count = wp_count_posts( 'product' );

		return [
			'period'         => $period,
			'revenue'        => round( $revenue, 2 ),
			'order_count'    => $order_count,
			'average_order'  => $order_count > 0 ? round( $revenue / $order_count, 2 ) : 0,
			'total_products' => (int) $product_count->publish,
			'currency'       => get_woocommerce_currency(),
		];
	}
}
