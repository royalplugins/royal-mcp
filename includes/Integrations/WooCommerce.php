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
				'description' => 'Get WooCommerce orders. Returns {orders, page, per_page, total, total_pages} — iterate page until page >= total_pages.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'per_page' => [ 'type' => 'integer', 'description' => 'Number of orders per page (default 10, max 100)' ],
						'page'     => [ 'type' => 'integer', 'description' => 'Page number, 1-indexed (default 1)' ],
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
			[
				'name'        => 'wc_get_product_variations',
				'description' => 'Get all variations for a variable WooCommerce product',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'product_id' => [ 'type' => 'integer', 'description' => 'Parent variable product ID' ],
						'per_page'   => [ 'type' => 'integer', 'description' => 'Number of variations to return (max 100)' ],
					],
					'required'   => [ 'product_id' ],
				],
			],
			[
				'name'        => 'wc_get_variation',
				'description' => 'Get a single product variation by ID',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'product_id'   => [ 'type' => 'integer', 'description' => 'Parent product ID' ],
						'variation_id' => [ 'type' => 'integer', 'description' => 'Variation ID' ],
					],
					'required'   => [ 'product_id', 'variation_id' ],
				],
			],
			[
				'name'        => 'wc_create_variation',
				'description' => 'Create a new variation for a variable product',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'product_id'     => [ 'type' => 'integer', 'description' => 'Parent variable product ID' ],
						'attributes'     => [
							'type'        => 'array',
							'description' => 'Variation attributes, e.g. [{"name":"color","option":"red"}]',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'name'   => [ 'type' => 'string' ],
									'option' => [ 'type' => 'string' ],
								],
							],
						],
						'regular_price'  => [ 'type' => 'string', 'description' => 'Regular price' ],
						'sale_price'     => [ 'type' => 'string', 'description' => 'Sale price' ],
						'sku'            => [ 'type' => 'string', 'description' => 'SKU' ],
						'status'         => [ 'type' => 'string', 'enum' => [ 'publish', 'private' ] ],
						'manage_stock'   => [ 'type' => 'boolean', 'description' => 'Enable stock management' ],
						'stock_quantity' => [ 'type' => 'integer', 'description' => 'Stock quantity' ],
						'stock_status'   => [ 'type' => 'string', 'enum' => [ 'instock', 'outofstock', 'onbackorder' ] ],
						'weight'         => [ 'type' => 'string', 'description' => 'Weight' ],
						'dimensions'     => [
							'type'        => 'object',
							'description' => 'Product dimensions',
							'properties'  => [
								'length' => [ 'type' => 'string' ],
								'width'  => [ 'type' => 'string' ],
								'height' => [ 'type' => 'string' ],
							],
						],
						'description'    => [ 'type' => 'string', 'description' => 'Variation description' ],
						'image_id'       => [ 'type' => 'integer', 'description' => 'Image attachment ID' ],
					],
					'required'   => [ 'product_id' ],
				],
			],
			[
				'name'        => 'wc_update_variation',
				'description' => 'Update an existing product variation',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'product_id'     => [ 'type' => 'integer', 'description' => 'Parent product ID' ],
						'variation_id'   => [ 'type' => 'integer', 'description' => 'Variation ID' ],
						'attributes'     => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'name'   => [ 'type' => 'string' ],
									'option' => [ 'type' => 'string' ],
								],
							],
						],
						'regular_price'  => [ 'type' => 'string' ],
						'sale_price'     => [ 'type' => 'string' ],
						'sku'            => [ 'type' => 'string' ],
						'status'         => [ 'type' => 'string', 'enum' => [ 'publish', 'private' ] ],
						'manage_stock'   => [ 'type' => 'boolean' ],
						'stock_quantity' => [ 'type' => 'integer' ],
						'stock_status'   => [ 'type' => 'string', 'enum' => [ 'instock', 'outofstock', 'onbackorder' ] ],
						'weight'         => [ 'type' => 'string' ],
						'dimensions'     => [
							'type'       => 'object',
							'properties' => [
								'length' => [ 'type' => 'string' ],
								'width'  => [ 'type' => 'string' ],
								'height' => [ 'type' => 'string' ],
							],
						],
						'description'    => [ 'type' => 'string' ],
						'image_id'       => [ 'type' => 'integer' ],
					],
					'required'   => [ 'product_id', 'variation_id' ],
				],
			],
			[
				'name'        => 'wc_delete_variation',
				'description' => 'Delete a product variation',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'product_id'   => [ 'type' => 'integer', 'description' => 'Parent product ID' ],
						'variation_id' => [ 'type' => 'integer', 'description' => 'Variation ID' ],
						'force'        => [ 'type' => 'boolean', 'description' => 'Permanently delete (true) or trash (false). Default true.' ],
					],
					'required'   => [ 'product_id', 'variation_id' ],
				],
			],
			[
				'name'        => 'wc_batch_update_variations',
				'description' => 'Batch create, update, and/or delete product variations in one call. All operations are scoped to product_id — updates/deletes for variations belonging to a different product are rejected. Batch deletes are always permanent (force=true).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'product_id' => [ 'type' => 'integer', 'description' => 'Parent variable product ID' ],
						'create'     => [
							'type'        => 'array',
							'description' => 'Variations to create (same fields as wc_create_variation minus product_id)',
							'items'       => [ 'type' => 'object' ],
						],
						'update'     => [
							'type'        => 'array',
							'description' => 'Variations to update — each must include variation_id',
							'items'       => [ 'type' => 'object' ],
						],
						'delete'     => [
							'type'        => 'array',
							'description' => 'Variation IDs to permanently delete',
							'items'       => [ 'type' => 'integer' ],
						],
					],
					'required'   => [ 'product_id' ],
				],
			],
			[
				'name'        => 'wc_get_product_attributes',
				'description' => 'List all registered global WooCommerce product attributes with their pa_* taxonomy slugs and IDs. Use this before wc_set_product_attributes or wc_get_attribute_terms to discover correct attribute IDs and slugs.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
			],
			[
				'name'        => 'wc_get_attribute_terms',
				'description' => 'List all valid term options for a global WooCommerce attribute (e.g. all colours for pa_color). Pass the taxonomy slug (pa_*) returned by wc_get_product_attributes.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'taxonomy'     => [ 'type' => 'string', 'description' => 'Attribute taxonomy slug, e.g. pa_color (returned by wc_get_product_attributes)' ],
						'attribute_id' => [ 'type' => 'integer', 'description' => 'Attribute ID (alternative to taxonomy)' ],
						'hide_empty'   => [ 'type' => 'boolean', 'description' => 'Exclude terms with no products (default false)' ],
					],
				],
			],
			[
				'name'        => 'wc_create_product_attribute',
				'description' => 'Register a new global WooCommerce product attribute taxonomy (e.g. "Color" becomes pa_color). Returns the new attribute ID and pa_* slug.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'name'         => [ 'type' => 'string', 'description' => 'Attribute label shown in admin (e.g. Color)' ],
						'slug'         => [ 'type' => 'string', 'description' => 'Slug without pa_ prefix (auto-derived from name if omitted)' ],
						'type'         => [ 'type' => 'string', 'enum' => [ 'select', 'text', 'color', 'image', 'button' ], 'description' => 'Field type (default select)' ],
						'order_by'     => [ 'type' => 'string', 'enum' => [ 'menu_order', 'name', 'name_num', 'id' ], 'description' => 'Default sort order for terms (default menu_order)' ],
						'has_archives' => [ 'type' => 'boolean', 'description' => 'Enable public attribute archive pages (default false)' ],
					],
					'required'   => [ 'name' ],
				],
			],
			[
				'name'        => 'wc_set_product_attributes',
				'description' => 'Set which attributes a variable product uses — required before creating variations. For global attributes supply the attribute id (from wc_get_product_attributes) and options as term slugs or names. For custom (non-global) attributes use id 0 and supply a name.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'product_id' => [ 'type' => 'integer', 'description' => 'Product ID' ],
						'attributes' => [
							'type'        => 'array',
							'description' => 'Attribute definitions',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'id'        => [ 'type' => 'integer', 'description' => 'Global attribute ID (0 for custom attribute)' ],
									'name'      => [ 'type' => 'string', 'description' => 'Custom attribute name (required when id is 0)' ],
									'options'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Term slugs/names (global) or plain values (custom)' ],
									'position'  => [ 'type' => 'integer', 'description' => 'Sort order (auto-assigned if omitted)' ],
									'visible'   => [ 'type' => 'boolean', 'description' => 'Show on product page (default true)' ],
									'variation' => [ 'type' => 'boolean', 'description' => 'Used for variation selection (default false)' ],
								],
							],
						],
					],
					'required'   => [ 'product_id', 'attributes' ],
				],
			],
			[
				'name'        => 'wc_get_coupons',
				'description' => 'List WooCommerce coupons with optional code search, status filter, and pagination',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'search'   => [ 'type' => 'string', 'description' => 'Search by coupon code' ],
						'status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'trash', 'any' ], 'description' => 'Coupon status (default: publish)' ],
						'per_page' => [ 'type' => 'integer', 'description' => 'Results per page (max 100, default 10)' ],
						'page'     => [ 'type' => 'integer', 'description' => 'Page number (default 1)' ],
					],
				],
			],
			[
				'name'        => 'wc_get_coupon',
				'description' => 'Get a single WooCommerce coupon by ID or code',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'id'   => [ 'type' => 'integer', 'description' => 'Coupon post ID' ],
						'code' => [ 'type' => 'string', 'description' => 'Coupon code (used if id is not provided)' ],
					],
				],
			],
			[
				'name'        => 'wc_get_coupon_count',
				'description' => 'Return published, draft, and trashed WooCommerce coupon counts',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
			],
			[
				'name'        => 'wc_create_coupon',
				'description' => 'Create a new WooCommerce coupon',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'code'                        => [ 'type' => 'string', 'description' => 'Coupon code (required, always stored lowercase)' ],
						'discount_type'               => [ 'type' => 'string', 'enum' => [ 'percent', 'fixed_cart', 'fixed_product' ], 'description' => 'Discount type (default: fixed_cart)' ],
						'amount'                      => [ 'type' => 'string', 'description' => 'Discount amount' ],
						'description'                 => [ 'type' => 'string', 'description' => 'Internal coupon description' ],
						'date_expires'                => [ 'type' => 'string', 'description' => 'Expiry date/time (e.g. "2026-12-31" or "2026-12-31T23:59:59")' ],
						'usage_limit'                 => [ 'type' => 'integer', 'description' => 'Max total uses (0 = unlimited)' ],
						'usage_limit_per_user'        => [ 'type' => 'integer', 'description' => 'Max uses per customer (0 = unlimited)' ],
						'limit_usage_to_x_items'      => [ 'type' => 'integer', 'description' => 'Max cart items the discount applies to (0 = all)' ],
						'individual_use'              => [ 'type' => 'boolean', 'description' => 'Cannot be combined with other coupons' ],
						'free_shipping'               => [ 'type' => 'boolean', 'description' => 'Grant free shipping' ],
						'exclude_sale_items'          => [ 'type' => 'boolean', 'description' => 'Exclude sale-priced items' ],
						'minimum_amount'              => [ 'type' => 'string', 'description' => 'Minimum order subtotal required' ],
						'maximum_amount'              => [ 'type' => 'string', 'description' => 'Maximum order subtotal allowed' ],
						'product_ids'                 => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Product IDs the coupon applies to' ],
						'excluded_product_ids'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Product IDs excluded from the coupon' ],
						'product_categories'          => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Category IDs the coupon applies to' ],
						'excluded_product_categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Category IDs excluded from the coupon' ],
						'email_restrictions'          => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Restrict coupon to these email addresses' ],
					],
					'required' => [ 'code' ],
				],
			],
			[
				'name'        => 'wc_update_coupon',
				'description' => 'Update an existing WooCommerce coupon; only supplied fields are changed',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'id'                          => [ 'type' => 'integer', 'description' => 'Coupon post ID' ],
						'code'                        => [ 'type' => 'string', 'description' => 'New coupon code (stored lowercase)' ],
						'discount_type'               => [ 'type' => 'string', 'enum' => [ 'percent', 'fixed_cart', 'fixed_product' ] ],
						'amount'                      => [ 'type' => 'string' ],
						'description'                 => [ 'type' => 'string' ],
						'date_expires'                => [ 'type' => 'string', 'description' => 'Expiry date/time, or empty string to clear' ],
						'usage_limit'                 => [ 'type' => 'integer' ],
						'usage_limit_per_user'        => [ 'type' => 'integer' ],
						'limit_usage_to_x_items'      => [ 'type' => 'integer' ],
						'individual_use'              => [ 'type' => 'boolean' ],
						'free_shipping'               => [ 'type' => 'boolean' ],
						'exclude_sale_items'          => [ 'type' => 'boolean' ],
						'minimum_amount'              => [ 'type' => 'string' ],
						'maximum_amount'              => [ 'type' => 'string' ],
						'product_ids'                 => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
						'excluded_product_ids'        => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
						'product_categories'          => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
						'excluded_product_categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
						'email_restrictions'          => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					],
					'required' => [ 'id' ],
				],
			],
			[
				'name'        => 'wc_delete_coupon',
				'description' => 'Delete a WooCommerce coupon; moves to trash by default, set force=true to permanently delete',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'id'    => [ 'type' => 'integer', 'description' => 'Coupon post ID' ],
						'force' => [ 'type' => 'boolean', 'description' => 'Permanently delete instead of moving to trash (default: false)' ],
					],
					'required' => [ 'id' ],
				],
			],
			[
				'name'        => 'wc_empty_coupon_trash',
				'description' => 'Permanently delete all trashed WooCommerce coupons',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => new \stdClass(),
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

		// 1.4.26 — every WC tool gates behind manage_woocommerce. This is the
		// umbrella cap WC's own admin screens require: admins + Shop Manager
		// role have it; Customer, Subscriber, Contributor, and Editor do NOT.
		// Per-action additions (publish_products, delete_others_shop_orders,
		// etc.) layer on top below where the action is destructive.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			throw new \Exception( 'You do not have permission to use WooCommerce tools.' );
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
				$type              = sanitize_text_field( $args['type'] ?? 'simple' );
				$product_class_map = [
					'simple'   => '\WC_Product_Simple',
					'variable' => '\WC_Product_Variable',
					'grouped'  => '\WC_Product_Grouped',
					'external' => '\WC_Product_External',
				];
				if ( ! isset( $product_class_map[ $type ] ) ) {
					throw new \Exception( 'Unsupported product type: ' . $type . '. Supported types: simple, variable, grouped, external.' );
				}
				$class = $product_class_map[ $type ];
				if ( ! class_exists( $class ) ) {
					throw new \Exception( 'Product class not available: ' . $class . ' (WooCommerce may not be fully loaded)' );
				}
				$product = new $class();
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
				$per_page = max( 1, min( intval( $args['per_page'] ?? 10 ), 100 ) );
				$page     = max( 1, intval( $args['page'] ?? 1 ) );
				$status   = ! empty( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'any';
				$result   = wc_get_orders( [
					'limit'    => $per_page,
					'paged'    => $page,
					'status'   => $status,
					'type'     => 'shop_order',
					'orderby'  => 'date',
					'order'    => 'DESC',
					'paginate' => true,
				] );
				return [
					'orders'      => array_map( [ __CLASS__, 'format_order_summary' ], $result->orders ),
					'page'        => $page,
					'per_page'    => $per_page,
					'total'       => intval( $result->total ),
					'total_pages' => intval( $result->max_num_pages ),
				];

			case 'wc_get_order':
				$order = wc_get_order( intval( $args['id'] ) );
				if ( ! $order || ! $order instanceof \WC_Order ) {
					throw new \Exception( 'Order not found' );
				}
				return self::format_order_detail( $order );

			case 'wc_update_order_status':
				$order = wc_get_order( intval( $args['id'] ) );
				if ( ! $order || ! $order instanceof \WC_Order ) {
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


			case 'wc_get_product_variations':
				$product = wc_get_product( intval( $args['product_id'] ) );
				if ( ! $product ) {
					throw new \Exception( 'Product not found' );
				}
				if ( ! $product->is_type( 'variable' ) ) {
					throw new \Exception( 'Product is not a variable product' );
				}
				$limit         = min( intval( $args['per_page'] ?? 100 ), 100 );
				$variation_ids = array_slice( $product->get_children(), 0, $limit );
				$variations    = array_filter( array_map( 'wc_get_product', $variation_ids ) );
				return array_values( array_map( [ __CLASS__, 'format_variation' ], $variations ) );

			case 'wc_get_variation':
				$variation = wc_get_product( intval( $args['variation_id'] ) );
				if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
					throw new \Exception( 'Variation not found' );
				}
				if ( $variation->get_parent_id() !== intval( $args['product_id'] ) ) {
					throw new \Exception( 'Variation does not belong to the specified product' );
				}
				return self::format_variation( $variation );

			case 'wc_create_variation':
				$product = wc_get_product( intval( $args['product_id'] ) );
				if ( ! $product ) {
					throw new \Exception( 'Product not found' );
				}
				if ( ! $product->is_type( 'variable' ) ) {
					throw new \Exception( 'Product is not a variable product' );
				}
				$variation = new \WC_Product_Variation();
				$variation->set_parent_id( intval( $args['product_id'] ) );
				self::apply_variation_fields( $variation, $args );
				$variation_id = $variation->save();
				if ( ! $variation_id ) {
					throw new \Exception( 'Failed to create variation' );
				}
				\WC_Product_Variable::sync( $product );
				return [ 'id' => $variation_id, 'message' => 'Variation created successfully' ];

			case 'wc_update_variation':
				$variation = wc_get_product( intval( $args['variation_id'] ) );
				if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
					throw new \Exception( 'Variation not found' );
				}
				if ( $variation->get_parent_id() !== intval( $args['product_id'] ) ) {
					throw new \Exception( 'Variation does not belong to the specified product' );
				}
				self::apply_variation_fields( $variation, $args );
				$variation->save();
				$parent = wc_get_product( $variation->get_parent_id() );
				if ( $parent ) {
					\WC_Product_Variable::sync( $parent );
				}
				return [ 'id' => intval( $args['variation_id'] ), 'message' => 'Variation updated successfully' ];

			case 'wc_delete_variation':
				$variation = wc_get_product( intval( $args['variation_id'] ) );
				if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
					throw new \Exception( 'Variation not found' );
				}
				if ( $variation->get_parent_id() !== intval( $args['product_id'] ) ) {
					throw new \Exception( 'Variation does not belong to the specified product' );
				}
				$force = isset( $args['force'] ) ? (bool) $args['force'] : true;
				$variation->delete( $force );
				$parent = wc_get_product( intval( $args['product_id'] ) );
				if ( $parent ) {
					\WC_Product_Variable::sync( $parent );
				}
				return [ 'id' => intval( $args['variation_id'] ), 'deleted' => true, 'force' => $force ];

			case 'wc_batch_update_variations':
				$product = wc_get_product( intval( $args['product_id'] ) );
				if ( ! $product ) {
					throw new \Exception( 'Product not found' );
				}
				if ( ! $product->is_type( 'variable' ) ) {
					throw new \Exception( 'Product is not a variable product' );
				}
				$result = [ 'create' => [], 'update' => [], 'delete' => [] ];
				foreach ( $args['create'] ?? [] as $data ) {
					$variation = new \WC_Product_Variation();
					$variation->set_parent_id( intval( $args['product_id'] ) );
					self::apply_variation_fields( $variation, $data );
					$new_id            = $variation->save();
					$result['create'][] = [ 'id' => $new_id ];
				}
				foreach ( $args['update'] ?? [] as $data ) {
					$var_id    = intval( $data['variation_id'] ?? 0 );
					$variation = wc_get_product( $var_id );
					if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
						$result['update'][] = [ 'id' => $var_id, 'error' => 'Not found' ];
						continue;
					}
					if ( $variation->get_parent_id() !== intval( $args['product_id'] ) ) {
						$result['update'][] = [ 'id' => $var_id, 'error' => 'Variation does not belong to this product' ];
						continue;
					}
					self::apply_variation_fields( $variation, $data );
					$variation->save();
					$result['update'][] = [ 'id' => $var_id ];
				}
				foreach ( $args['delete'] ?? [] as $var_id ) {
					$variation = wc_get_product( intval( $var_id ) );
					if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
						$result['delete'][] = [ 'id' => $var_id, 'error' => 'Not found' ];
						continue;
					}
					if ( $variation->get_parent_id() !== intval( $args['product_id'] ) ) {
						$result['delete'][] = [ 'id' => $var_id, 'error' => 'Variation does not belong to this product' ];
						continue;
					}
					$variation->delete( true );
					$result['delete'][] = [ 'id' => $var_id, 'deleted' => true ];
				}
				\WC_Product_Variable::sync( $product );
				return $result;

			case 'wc_get_product_attributes':
				$attributes = wc_get_attribute_taxonomies();
				return array_values( array_map( function( $attr ) {
					return [
						'id'           => (int) $attr->attribute_id,
						'name'         => $attr->attribute_label,
						'slug'         => wc_attribute_taxonomy_name( $attr->attribute_name ),
						'type'         => $attr->attribute_type,
						'order_by'     => $attr->attribute_orderby,
						'has_archives' => (bool) $attr->attribute_public,
					];
				}, $attributes ) );

			case 'wc_get_attribute_terms':
				if ( ! empty( $args['attribute_id'] ) ) {
					$attr_obj = wc_get_attribute( intval( $args['attribute_id'] ) );
					if ( ! $attr_obj || is_wp_error( $attr_obj ) ) {
						throw new \Exception( 'Attribute not found' );
					}
					// wc_get_attribute() returns slug already prefixed with pa_; don't double-prefix.
					$taxonomy = $attr_obj->slug;
				} elseif ( ! empty( $args['taxonomy'] ) ) {
					$taxonomy = sanitize_text_field( $args['taxonomy'] );
				} else {
					throw new \Exception( 'Either taxonomy or attribute_id is required' );
				}
				if ( ! taxonomy_exists( $taxonomy ) ) {
					throw new \Exception( 'Taxonomy does not exist: ' . esc_html( $taxonomy ) );
				}
				$terms = get_terms( [
					'taxonomy'   => $taxonomy,
					'hide_empty' => (bool) ( $args['hide_empty'] ?? false ),
				] );
				if ( is_wp_error( $terms ) ) {
					throw new \Exception( esc_html( $terms->get_error_message() ) );
				}
				return array_values( array_map( function( $term ) {
					return [
						'id'    => $term->term_id,
						'name'  => $term->name,
						'slug'  => $term->slug,
						'count' => $term->count,
					];
				}, $terms ) );

			case 'wc_create_product_attribute':
				$attr_data = [
					'name'         => sanitize_text_field( $args['name'] ),
					'slug'         => sanitize_title( $args['slug'] ?? $args['name'] ),
					'type'         => in_array( $args['type'] ?? 'select', [ 'select', 'text', 'color', 'image', 'button' ], true ) ? ( $args['type'] ?? 'select' ) : 'select',
					'order_by'     => in_array( $args['order_by'] ?? 'menu_order', [ 'menu_order', 'name', 'name_num', 'id' ], true ) ? ( $args['order_by'] ?? 'menu_order' ) : 'menu_order',
					'has_archives' => (bool) ( $args['has_archives'] ?? false ),
				];
				$new_id = wc_create_attribute( $attr_data );
				if ( is_wp_error( $new_id ) ) {
					throw new \Exception( esc_html( $new_id->get_error_message() ) );
				}
				$new_taxonomy = wc_attribute_taxonomy_name( $attr_data['slug'] );
				return [
					'id'      => $new_id,
					'slug'    => $new_taxonomy,
					'message' => 'Attribute created successfully',
				];

			case 'wc_set_product_attributes':
				$product = wc_get_product( intval( $args['product_id'] ) );
				if ( ! $product ) {
					throw new \Exception( 'Product not found' );
				}
				$existing_attribute_count = count( $product->get_attributes() );
				$product_attributes = [];
				$auto_position      = 0;
				foreach ( $args['attributes'] as $attr_data ) {
					$attribute = new \WC_Product_Attribute();
					$attr_id   = intval( $attr_data['id'] ?? 0 );
					$attribute->set_id( $attr_id );
					$attribute->set_position( isset( $attr_data['position'] ) ? intval( $attr_data['position'] ) : $auto_position );
					$attribute->set_visible( (bool) ( $attr_data['visible'] ?? true ) );
					$attribute->set_variation( (bool) ( $attr_data['variation'] ?? false ) );
					if ( $attr_id > 0 ) {
						$global_attr = wc_get_attribute( $attr_id );
						if ( ! $global_attr || is_wp_error( $global_attr ) ) {
							throw new \Exception( 'Attribute ID not found: ' . esc_html( $attr_id ) );
						}
						// wc_get_attribute() returns slug already prefixed with pa_; don't double-prefix.
						$taxonomy = $global_attr->slug;
						$attribute->set_name( $taxonomy );
						$term_ids = [];
						foreach ( $attr_data['options'] ?? [] as $option ) {
							$term = get_term_by( 'slug', sanitize_title( $option ), $taxonomy );
							if ( ! $term ) {
								$term = get_term_by( 'name', sanitize_text_field( $option ), $taxonomy );
							}
							if ( $term ) {
								$term_ids[] = $term->term_id;
							}
						}
						$attribute->set_options( $term_ids );
					} else {
						$attribute->set_name( sanitize_text_field( $attr_data['name'] ?? '' ) );
						$attribute->set_options( array_map( 'sanitize_text_field', $attr_data['options'] ?? [] ) );
					}
					$product_attributes[] = $attribute;
					++$auto_position;
				}
				$product->set_attributes( $product_attributes );
				$product->save();
				$response = [
					'id'              => intval( $args['product_id'] ),
					'attribute_count' => count( $product_attributes ),
					'message'         => 'Product attributes updated successfully',
				];
				if ( $existing_attribute_count > 0 ) {
					$response['warning'] = sprintf(
						'This operation replaced %d existing attribute(s). Any variations using removed attributes may be affected.',
						$existing_attribute_count
					);
				}
				return $response;

			case 'wc_get_coupons':
				$per_page        = min( intval( $args['per_page'] ?? 10 ), 100 );
				$paged           = max( intval( $args['page'] ?? 1 ), 1 );
				$allowed_status  = [ 'publish', 'draft', 'trash', 'any' ];
				$status          = in_array( $args['status'] ?? 'publish', $allowed_status, true ) ? ( $args['status'] ?? 'publish' ) : 'publish';
				$query_args      = [
					'post_type'      => 'shop_coupon',
					'post_status'    => $status,
					'posts_per_page' => $per_page,
					'paged'          => $paged,
				];
				if ( ! empty( $args['search'] ) ) {
					$query_args['s'] = sanitize_text_field( $args['search'] );
				}
				$posts = get_posts( $query_args );
				return array_map( function( $post ) {
					return self::format_coupon_summary( new \WC_Coupon( $post->ID ) );
				}, $posts );

			case 'wc_get_coupon':
				if ( isset( $args['id'] ) ) {
					$id = intval( $args['id'] );
					if ( $id <= 0 ) {
						throw new \Exception( 'Invalid coupon ID' );
					}
					$coupon = new \WC_Coupon( $id );
				} elseif ( isset( $args['code'] ) ) {
					$coupon = new \WC_Coupon( sanitize_text_field( $args['code'] ) );
				} else {
					throw new \Exception( 'id or code is required' );
				}
				if ( ! $coupon->get_id() || get_post_type( $coupon->get_id() ) !== 'shop_coupon' ) {
					throw new \Exception( 'Coupon not found' );
				}
				return self::format_coupon_detail( $coupon );

			case 'wc_get_coupon_count':
				$counts = wp_count_posts( 'shop_coupon' );
				return [
					'publish' => (int) $counts->publish,
					'draft'   => (int) $counts->draft,
					'trash'   => (int) $counts->trash,
				];

			case 'wc_create_coupon':
				$code = strtolower( sanitize_text_field( $args['code'] ?? '' ) );
				if ( empty( $code ) ) {
					throw new \Exception( 'Coupon code is required' );
				}
				// Note: wc_get_coupon_id_by_code + save is not atomic; a duplicate code
				// inserted concurrently between these two calls would result in two coupons
				// sharing a code. WooCommerce resolves this by using the most-recent one.
				// No mutex is available at the WP/PHP level; this is an accepted limitation.
				if ( wc_get_coupon_id_by_code( $code ) ) {
					throw new \Exception( 'A coupon with this code already exists' );
				}
				$coupon = new \WC_Coupon();
				$coupon->set_code( $code );
				$coupon->set_discount_type( 'fixed_cart' ); // explicit default; WC default matches but we make it clear
				self::apply_coupon_fields( $coupon, $args );
				$coupon_id = $coupon->save();
				if ( ! $coupon_id ) {
					throw new \Exception( 'Failed to create coupon' );
				}
				return [ 'id' => $coupon_id, 'code' => $code, 'message' => 'Coupon created successfully' ];

			case 'wc_update_coupon':
				$id = intval( $args['id'] );
				if ( $id <= 0 ) {
					throw new \Exception( 'Invalid coupon ID' );
				}
				$coupon = new \WC_Coupon( $id );
				if ( ! $coupon->get_id() || get_post_type( $coupon->get_id() ) !== 'shop_coupon' ) {
					throw new \Exception( 'Coupon not found' );
				}
				if ( isset( $args['code'] ) ) {
					$new_code = strtolower( sanitize_text_field( $args['code'] ) );
					$existing = wc_get_coupon_id_by_code( $new_code );
					if ( $existing && $existing !== $coupon->get_id() ) {
						throw new \Exception( 'A coupon with this code already exists' );
					}
					$coupon->set_code( $new_code );
				}
				self::apply_coupon_fields( $coupon, $args );
				$coupon->save();
				return [ 'id' => $coupon->get_id(), 'message' => 'Coupon updated successfully' ];

			case 'wc_delete_coupon':
				$id = intval( $args['id'] );
				if ( $id <= 0 ) {
					throw new \Exception( 'Invalid coupon ID' );
				}
				$coupon = new \WC_Coupon( $id );
				if ( ! $coupon->get_id() || get_post_type( $coupon->get_id() ) !== 'shop_coupon' ) {
					throw new \Exception( 'Coupon not found' );
				}
				$force       = isset( $args['force'] ) ? (bool) $args['force'] : false;
				$coupon_id   = $coupon->get_id();
				$post_status = get_post_status( $coupon_id );
				if ( ! $force && 'trash' === $post_status ) {
					return [ 'id' => $coupon_id, 'message' => 'Coupon is already in trash' ];
				}
				$result  = wp_delete_post( $coupon_id, $force );
				if ( ! $result ) {
					throw new \Exception( 'Failed to delete coupon' );
				}
				$message = $force ? 'Coupon permanently deleted' : 'Coupon moved to trash';
				return [ 'id' => $coupon_id, 'message' => $message ];

			case 'wc_empty_coupon_trash':
				$trashed = get_posts( [
					'post_type'      => 'shop_coupon',
					'post_status'    => 'trash',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				] );
				if ( empty( $trashed ) ) {
					return [ 'deleted' => 0, 'message' => 'Coupon trash is empty' ];
				}
				$deleted = 0;
				foreach ( $trashed as $post_id ) {
					if ( wp_delete_post( intval( $post_id ), true ) ) {
						$deleted++;
					}
				}
				return [ 'deleted' => (int) $deleted, 'message' => 'Permanently deleted ' . (int) $deleted . ' coupon(s) from trash' ];

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
			'type'       => 'shop_order',
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

	private static function format_variation( $variation ) {
		$attributes = [];
		foreach ( $variation->get_attributes() as $name => $value ) {
			$attributes[] = [ 'name' => $name, 'option' => $value ];
		}
		return [
			'id'             => $variation->get_id(),
			'parent_id'      => $variation->get_parent_id(),
			'status'         => $variation->get_status(),
			'sku'            => $variation->get_sku(),
			'price'          => $variation->get_price(),
			'regular_price'  => $variation->get_regular_price(),
			'sale_price'     => $variation->get_sale_price(),
			'stock_status'   => $variation->get_stock_status(),
			'stock_quantity' => $variation->get_stock_quantity(),
			'manage_stock'   => $variation->get_manage_stock(),
			'weight'         => $variation->get_weight(),
			'dimensions'     => [
				'length' => $variation->get_length(),
				'width'  => $variation->get_width(),
				'height' => $variation->get_height(),
			],
			'description'    => $variation->get_description(),
			'image_id'       => $variation->get_image_id(),
			'attributes'     => $attributes,
			'date_created'   => $variation->get_date_created() ? $variation->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
			'date_modified'  => $variation->get_date_modified() ? $variation->get_date_modified()->format( 'Y-m-d H:i:s' ) : null,
		];
	}

	private static function apply_variation_fields( \WC_Product_Variation $variation, array $args ) {
		if ( isset( $args['attributes'] ) ) {
			$variation->set_attributes( self::parse_variation_attributes( $args['attributes'] ) );
		}
		if ( isset( $args['regular_price'] ) ) {
			$variation->set_regular_price( sanitize_text_field( $args['regular_price'] ) );
		}
		if ( isset( $args['sale_price'] ) ) {
			$variation->set_sale_price( sanitize_text_field( $args['sale_price'] ) );
		}
		if ( isset( $args['sku'] ) ) {
			$variation->set_sku( sanitize_text_field( $args['sku'] ) );
		}
		if ( isset( $args['status'] ) ) {
			$variation->set_status( in_array( $args['status'], [ 'publish', 'private' ], true ) ? $args['status'] : 'publish' );
		}
		if ( isset( $args['manage_stock'] ) ) {
			$variation->set_manage_stock( (bool) $args['manage_stock'] );
		}
		if ( isset( $args['stock_quantity'] ) ) {
			$variation->set_stock_quantity( intval( $args['stock_quantity'] ) );
		}
		if ( isset( $args['stock_status'] ) ) {
			$variation->set_stock_status( sanitize_text_field( $args['stock_status'] ) );
		}
		if ( isset( $args['weight'] ) ) {
			$variation->set_weight( sanitize_text_field( $args['weight'] ) );
		}
		if ( isset( $args['dimensions'] ) ) {
			if ( isset( $args['dimensions']['length'] ) ) {
				$variation->set_length( sanitize_text_field( $args['dimensions']['length'] ) );
			}
			if ( isset( $args['dimensions']['width'] ) ) {
				$variation->set_width( sanitize_text_field( $args['dimensions']['width'] ) );
			}
			if ( isset( $args['dimensions']['height'] ) ) {
				$variation->set_height( sanitize_text_field( $args['dimensions']['height'] ) );
			}
		}
		if ( isset( $args['description'] ) ) {
			$variation->set_description( wp_kses_post( $args['description'] ) );
		}
		if ( isset( $args['image_id'] ) ) {
			$variation->set_image_id( intval( $args['image_id'] ) );
		}
	}

	private static function parse_variation_attributes( array $attributes ) {
		$parsed = [];
		foreach ( $attributes as $attr ) {
			if ( empty( $attr['name'] ) || ! isset( $attr['option'] ) ) {
				continue;
			}
			// sanitize_title converts "Color" -> "color", "pa_Color" -> "pa_color"
			$parsed[ sanitize_title( $attr['name'] ) ] = sanitize_text_field( $attr['option'] );
		}
		return $parsed;
	}

	private static function format_coupon_summary( $coupon ) {
		return [
			'id'            => $coupon->get_id(),
			'code'          => $coupon->get_code(),
			'discount_type' => $coupon->get_discount_type(),
			'amount'        => $coupon->get_amount(),
			'usage_count'   => $coupon->get_usage_count(),
			'usage_limit'   => $coupon->get_usage_limit(),
			'date_expires'  => $coupon->get_date_expires() ? $coupon->get_date_expires()->format( 'Y-m-d' ) : null,
		];
	}

	private static function format_coupon_detail( $coupon ) {
		return [
			'id'                          => $coupon->get_id(),
			'code'                        => $coupon->get_code(),
			'description'                 => $coupon->get_description(),
			'discount_type'               => $coupon->get_discount_type(),
			'amount'                      => $coupon->get_amount(),
			'individual_use'              => $coupon->get_individual_use(),
			'product_ids'                 => $coupon->get_product_ids(),
			'excluded_product_ids'        => $coupon->get_excluded_product_ids(),
			'usage_limit'                 => $coupon->get_usage_limit(),
			'usage_limit_per_user'        => $coupon->get_usage_limit_per_user(),
			'limit_usage_to_x_items'      => $coupon->get_limit_usage_to_x_items(),
			'usage_count'                 => $coupon->get_usage_count(),
			'free_shipping'               => $coupon->get_free_shipping(),
			'product_categories'          => $coupon->get_product_categories(),
			'excluded_product_categories' => $coupon->get_excluded_product_categories(),
			'exclude_sale_items'          => $coupon->get_exclude_sale_items(),
			'minimum_amount'              => $coupon->get_minimum_amount(),
			'maximum_amount'              => $coupon->get_maximum_amount(),
			'email_restrictions'          => $coupon->get_email_restrictions(),
			'date_expires'                => $coupon->get_date_expires() ? $coupon->get_date_expires()->format( 'Y-m-d H:i:s' ) : null,
			'date_created'                => $coupon->get_date_created() ? $coupon->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
			'date_modified'               => $coupon->get_date_modified() ? $coupon->get_date_modified()->format( 'Y-m-d H:i:s' ) : null,
		];
	}

	private static function apply_coupon_fields( $coupon, $args ) {
		$allowed_types = [ 'percent', 'fixed_cart', 'fixed_product' ];
		if ( isset( $args['discount_type'] ) && in_array( $args['discount_type'], $allowed_types, true ) ) {
			$coupon->set_discount_type( $args['discount_type'] );
		}
		if ( isset( $args['amount'] ) ) {
			$coupon->set_amount( sanitize_text_field( $args['amount'] ) );
		}
		if ( isset( $args['description'] ) ) {
			$coupon->set_description( sanitize_text_field( $args['description'] ) );
		}
		if ( isset( $args['date_expires'] ) ) {
			$raw = sanitize_text_field( $args['date_expires'] );
			if ( '' === $raw ) {
				$coupon->set_date_expires( null ); // null clears the expiry date in WC 3.x+
			} else {
				$timestamp = strtotime( $raw );
				if ( false === $timestamp ) {
					throw new \Exception( 'Invalid date_expires format' );
				}
				$coupon->set_date_expires( $timestamp );
			}
		}
		if ( isset( $args['usage_limit'] ) ) {
			$coupon->set_usage_limit( intval( $args['usage_limit'] ) );
		}
		if ( isset( $args['usage_limit_per_user'] ) ) {
			$coupon->set_usage_limit_per_user( intval( $args['usage_limit_per_user'] ) );
		}
		if ( isset( $args['limit_usage_to_x_items'] ) ) {
			$coupon->set_limit_usage_to_x_items( intval( $args['limit_usage_to_x_items'] ) );
		}
		if ( isset( $args['individual_use'] ) ) {
			$coupon->set_individual_use( (bool) $args['individual_use'] );
		}
		if ( isset( $args['free_shipping'] ) ) {
			$coupon->set_free_shipping( (bool) $args['free_shipping'] );
		}
		if ( isset( $args['exclude_sale_items'] ) ) {
			$coupon->set_exclude_sale_items( (bool) $args['exclude_sale_items'] );
		}
		if ( isset( $args['minimum_amount'] ) ) {
			$coupon->set_minimum_amount( sanitize_text_field( $args['minimum_amount'] ) );
		}
		if ( isset( $args['maximum_amount'] ) ) {
			$coupon->set_maximum_amount( sanitize_text_field( $args['maximum_amount'] ) );
		}
		if ( isset( $args['product_ids'] ) && is_array( $args['product_ids'] ) ) {
			$coupon->set_product_ids( array_values( array_filter( array_map( 'intval', $args['product_ids'] ), function( $v ) { return $v > 0; } ) ) );
		}
		if ( isset( $args['excluded_product_ids'] ) && is_array( $args['excluded_product_ids'] ) ) {
			$coupon->set_excluded_product_ids( array_values( array_filter( array_map( 'intval', $args['excluded_product_ids'] ), function( $v ) { return $v > 0; } ) ) );
		}
		if ( isset( $args['product_categories'] ) && is_array( $args['product_categories'] ) ) {
			$coupon->set_product_categories( array_values( array_filter( array_map( 'intval', $args['product_categories'] ), function( $v ) { return $v > 0; } ) ) );
		}
		if ( isset( $args['excluded_product_categories'] ) && is_array( $args['excluded_product_categories'] ) ) {
			$coupon->set_excluded_product_categories( array_values( array_filter( array_map( 'intval', $args['excluded_product_categories'] ), function( $v ) { return $v > 0; } ) ) );
		}
		if ( isset( $args['email_restrictions'] ) && is_array( $args['email_restrictions'] ) ) {
			$emails = array_values( array_filter( array_map( 'sanitize_email', $args['email_restrictions'] ), 'is_email' ) );
			$coupon->set_email_restrictions( $emails );
		}
	}

}
