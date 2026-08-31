<?php
namespace Royal_MCP\Integrations;

use Royal_MCP\MCP\Support\Envelope;

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
		// Always register so tools appear in MCP tools/list regardless of the
		// underlying plugin activation state. execute_tool gates at call time
		// with a clean 'not active' throw. Prevents ghost-tools UX where
		// activating a plugin post-MCP-connection requires the client to
		// reconnect before the tools become discoverable.

		return [
			[
				'name'        => 'wc_get_products',
				'description' => 'Get WooCommerce products',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'per_page'       => [ 'type' => 'integer', 'description' => 'Number of products (max 100)' ],
						'status'         => [ 'type' => 'string', 'description' => 'Product status (publish, draft, etc)' ],
						'category'       => [ 'type' => 'string', 'description' => 'Category slug to filter by' ],
						'search'         => [ 'type' => 'string', 'description' => 'Search term' ],
						'type'           => [ 'type' => 'string', 'description' => 'Product type (simple, variable, grouped, external)' ],
						'attribute'      => [ 'type' => 'string', 'description' => 'Global attribute taxonomy slug (e.g. pa_color from wc_get_product_attributes). Requires attribute_term.' ],
						'attribute_term' => [ 'type' => 'string', 'description' => 'Term slug or term_id within the attribute (e.g. black-color from wc_get_attribute_terms). Requires attribute.' ],
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
				'description' => 'Get single WooCommerce order by ID. Returns id, status, total, subtotal, tax, shipping, currency, payment_method (raw gateway ID — bacs, cheque, stripe, etc.), payment_method_title (human display name — may be empty if gateway not registered), customer_name, billing_city, billing_country, items (product line items), fee_lines (custom fees), shipping_lines (shipping methods with rate details), date_created, date_paid.',
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
				'description' => 'Update WooCommerce order status. Optional note may contain safe HTML — displayed in the WC admin order timeline.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'id'     => [ 'type' => 'integer', 'description' => 'Order ID' ],
						'status' => [ 'type' => 'string', 'description' => 'New status (processing, completed, on-hold, cancelled, refunded)' ],
						'note'   => [ 'type' => 'string', 'description' => 'Optional order note. May contain safe HTML (links, formatting).' ],
					],
					'required'   => [ 'id', 'status' ],
				],
			],
			[
				'name'        => 'wc_create_order',
				'description' => 'Create a WooCommerce order programmatically. Use for B2B, wholesale, phone orders, manual invoicing. Stock is decremented only when status transitions into processing/completed — create as pending, then update status to processing to trigger stock reduction. Order emails are NOT auto-fired; pass send_emails=true to trigger the New Order email.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'customer_id'    => [ 'type' => 'integer', 'description' => 'Optional WP user ID for the customer. Omit to create a guest order.' ],
						'billing'        => [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => true, 'description' => 'Billing address: first_name, last_name, address_1, address_2, city, state, postcode, country, email, phone.' ],
						'shipping'       => [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => true, 'description' => 'Shipping address (same shape as billing, minus email/phone).' ],
						'line_items'     => [
							'type'        => 'array',
							'description' => 'Array of {product_id, quantity, variation_id?}. variation_id must belong to product_id.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'product_id'   => [ 'type' => 'integer' ],
									'quantity'     => [ 'type' => 'integer' ],
									'variation_id' => [ 'type' => 'integer' ],
								],
								'required'   => [ 'product_id', 'quantity' ],
							],
						],
						'status'         => [ 'type' => 'string', 'description' => 'Initial order status (default pending). Accepted: pending, processing, on-hold, completed, cancelled.' ],
						'payment_method' => [ 'type' => 'string', 'description' => 'Payment method ID (e.g. bacs, cheque, cod, stripe).' ],
						'shipping_lines' => [
							'type'        => 'array',
							'description' => 'Optional shipping lines. Array of {method_id, method_title, total}.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'method_id'    => [ 'type' => 'string' ],
									'method_title' => [ 'type' => 'string' ],
									'total'        => [ 'type' => 'string' ],
								],
							],
						],
						'fee_lines'      => [
							'type'        => 'array',
							'description' => 'Optional fee lines. Array of {name, total}.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'name'  => [ 'type' => 'string' ],
									'total' => [ 'type' => 'string' ],
								],
							],
						],
						'meta_data'      => [
							'type'        => 'array',
							'description' => 'Optional custom order meta. Array of {key, value}.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'key'   => [ 'type' => 'string' ],
									'value' => [ 'type' => [ 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ] ],
								],
								'required'   => [ 'key' ],
							],
						],
						'customer_note'  => [ 'type' => 'string', 'description' => 'Customer-facing note attached to the order.' ],
						'send_emails'    => [ 'type' => 'boolean', 'description' => 'If true, fire the WC New Order email after creation. Default false.' ],
					],
					'required'   => [ 'line_items' ],
				],
			],
			[
				'name'        => 'wc_update_order',
				'description' => 'Update an existing WooCommerce order. All fields except order_id are optional. line_items with an id update or remove (quantity 0) existing items; line_items without an id add new items. Recalculates totals after mutation.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'order_id'      => [ 'type' => 'integer', 'description' => 'Order ID to update.' ],
						'billing'       => [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => true, 'description' => 'Partial billing address — only provided keys are updated.' ],
						'shipping'      => [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => true, 'description' => 'Partial shipping address — only provided keys are updated.' ],
						'customer_note' => [ 'type' => 'string', 'description' => 'Replace customer-facing order note.' ],
						'status'        => [ 'type' => 'string', 'description' => 'New order status.' ],
						'meta_data'     => [
							'type'        => 'array',
							'description' => 'Array of {key, value} to add/replace on the order meta.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'key'   => [ 'type' => 'string' ],
									'value' => [ 'type' => [ 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ] ],
								],
								'required'   => [ 'key' ],
							],
						],
						'line_items'    => [
							'type'        => 'array',
							'description' => 'Array of {product_id, quantity, variation_id?, id?}. id present + quantity 0 = remove; id present + quantity > 0 = update; no id = add.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'id'           => [ 'type' => 'integer' ],
									'product_id'   => [ 'type' => 'integer' ],
									'quantity'     => [ 'type' => 'integer' ],
									'variation_id' => [ 'type' => 'integer' ],
								],
							],
						],
					],
					'required'   => [ 'order_id' ],
				],
			],
			[
				'name'        => 'wc_add_order_note',
				'description' => 'Add a note to a WooCommerce order. Private notes are internal (staff timeline only). Customer notes are emailed to the customer and shown on their order view. Content may contain safe HTML (links, formatting) — sanitized via wp_kses_post.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'order_id'      => [ 'type' => 'integer', 'description' => 'Order ID.' ],
						'note'          => [ 'type' => 'string', 'description' => 'Note content. May contain safe HTML.' ],
						'customer_note' => [ 'type' => 'boolean', 'description' => 'If true, note is emailed to the customer. Default false (private/internal note).' ],
					],
					'required'   => [ 'order_id', 'note' ],
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
							'items'       => [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => true ],
						],
						'update'     => [
							'type'        => 'array',
							'description' => 'Variations to update — each must include variation_id',
							'items'       => [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => true ],
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
				'description' => 'Create a new WooCommerce coupon. Description may contain safe HTML.',
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
				'description' => 'Update an existing WooCommerce coupon; only supplied fields are changed. Description may contain safe HTML.',
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
		// Cap check runs BEFORE is_available for anti-fingerprint: unprivileged callers
		// get "no permission" not "WooCommerce is not active", so plugin presence is not
		// leaked to callers who couldn't use the tool anyway. Every WC tool gates behind
		// manage_woocommerce (the umbrella cap WC's own admin screens require: admins +
		// Shop Manager role have it; Customer, Subscriber, Contributor, and Editor do
		// NOT). Per-action additions (publish_products, delete_others_shop_orders, etc.)
		// layer on top below where the action is destructive.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			throw new \Exception( 'You do not have permission to use WooCommerce tools.' );
		}

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
				$has_attr = ! empty( $args['attribute'] );
				$has_term = ! empty( $args['attribute_term'] );
				if ( $has_attr xor $has_term ) {
					throw new \Exception( 'attribute and attribute_term must be provided together.' );
				}
				if ( $has_attr && $has_term ) {
					$taxonomy = sanitize_text_field( $args['attribute'] );
					$term     = sanitize_text_field( $args['attribute_term'] );
					if ( ! taxonomy_exists( $taxonomy ) ) {
						throw new \Exception( 'Unknown attribute taxonomy: ' . $taxonomy );
					}
					$query_args['tax_query'] = [
						[
							'taxonomy' => $taxonomy,
							'field'    => is_numeric( $term ) ? 'term_id' : 'slug',
							'terms'    => is_numeric( $term ) ? intval( $term ) : $term,
						],
					];
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
				// Undo removes the specific product row we created via
				// $product->delete(true). Row-scoped, no other products touched.
				$cp_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
					'op'      => 'wc_create_product',
					'summary' => sprintf( 'Delete the product %d created by this operation.', $product_id ),
					'target'  => [ 'product_id' => (int) $product_id ],
					'pre_op_state' => [
						'created_by_op' => true,
					],
				]);
				$cp_product_url = get_permalink( $product_id );
				return \Royal_MCP\MCP\Support\Envelope::success(
					sprintf( 'Created product %d (%s), undo available. View: %s',
						$product_id,
						$type,
						$cp_product_url ?: '(no permalink)'
					),
					[
						'id'      => (int) $product_id,
						'type'    => $type,
						'status'  => $product->get_status(),
						'url'     => $cp_product_url,
						'created' => true,
					],
					$cp_undo_envelope
				);

			case 'wc_update_product':
				$up_product_id = intval( $args['id'] );
				$up_product    = $up_product_id > 0 ? wc_get_product( $up_product_id ) : null;
				if ( ! $up_product ) {
					return Envelope::error( 'not_found', sprintf( 'Product %d not found.', $up_product_id ), [ 'id' => $up_product_id ] );
				}
				if ( ! current_user_can( 'edit_product', $up_product_id ) ) {
					throw new \Exception( 'edit_product capability required on this product.' );
				}

				// Requested-field extraction. Keys mirror the MCP tool arg
				// names. WC stores currency values as strings for precision,
				// so regular_price / sale_price are string-typed here.
				$up_requested = [];
				if ( isset( $args['name'] ) )              $up_requested['name']              = sanitize_text_field( (string) $args['name'] );
				if ( isset( $args['description'] ) )       $up_requested['description']       = wp_kses_post( (string) $args['description'] );
				if ( isset( $args['short_description'] ) ) $up_requested['short_description'] = wp_kses_post( (string) $args['short_description'] );
				if ( isset( $args['sku'] ) )               $up_requested['sku']               = sanitize_text_field( (string) $args['sku'] );
				if ( isset( $args['status'] ) )            $up_requested['status']            = sanitize_text_field( (string) $args['status'] );
				if ( isset( $args['regular_price'] ) )     $up_requested['regular_price']     = sanitize_text_field( (string) $args['regular_price'] );
				if ( isset( $args['sale_price'] ) )        $up_requested['sale_price']        = sanitize_text_field( (string) $args['sale_price'] );
				if ( isset( $args['stock_quantity'] ) )    $up_requested['stock_quantity']    = (int) $args['stock_quantity'];

				if ( empty( $up_requested ) ) {
					throw new \Exception( 'No update fields provided. Pass at least one of: name, description, short_description, sku, status, regular_price, sale_price, stock_quantity.' );
				}

				// Per-field reader closure — one call site for snapshot + verify.
				$up_read = function( $arg_key ) use ( $up_product_id ) {
					$p = wc_get_product( $up_product_id );
					if ( ! $p ) return null;
					switch ( $arg_key ) {
						case 'name':              return (string) $p->get_name();
						case 'description':       return (string) $p->get_description();
						case 'short_description': return (string) $p->get_short_description();
						case 'sku':               return (string) $p->get_sku();
						case 'status':            return (string) $p->get_status();
						case 'regular_price':     return (string) $p->get_regular_price();
						case 'sale_price':        return (string) $p->get_sale_price();
						case 'stock_quantity':    return (int) $p->get_stock_quantity();
					}
					return null;
				};

				// Snapshot BEFORE for requested fields. If stock_quantity is
				// in the request, ALSO snapshot manage_stock so undo can
				// restore the pre-op state fully — setting stock_quantity
				// flips manage_stock=true as a side effect.
				$up_before = [];
				foreach ( array_keys( $up_requested ) as $f ) {
					$up_before[ $f ] = $up_read( $f );
				}
                $up_manage_stock_before = null;
                if ( array_key_exists( 'stock_quantity', $up_requested ) ) {
                    $up_manage_stock_before = (bool) $up_product->get_manage_stock();
                }

				// Execute — same setter path as before, preserving the
				// manage_stock=true side effect on stock_quantity writes.
				if ( array_key_exists( 'name', $up_requested ) )              $up_product->set_name( $up_requested['name'] );
				if ( array_key_exists( 'description', $up_requested ) )       $up_product->set_description( $up_requested['description'] );
				if ( array_key_exists( 'short_description', $up_requested ) ) $up_product->set_short_description( $up_requested['short_description'] );
				if ( array_key_exists( 'sku', $up_requested ) )               $up_product->set_sku( $up_requested['sku'] );
				if ( array_key_exists( 'status', $up_requested ) )            $up_product->set_status( $up_requested['status'] );
				if ( array_key_exists( 'regular_price', $up_requested ) )     $up_product->set_regular_price( $up_requested['regular_price'] );
				if ( array_key_exists( 'sale_price', $up_requested ) )        $up_product->set_sale_price( $up_requested['sale_price'] );
				if ( array_key_exists( 'stock_quantity', $up_requested ) ) {
					$up_product->set_manage_stock( true );
					$up_product->set_stock_quantity( $up_requested['stock_quantity'] );
				}
				$up_product->save();
				wc_delete_product_transients( $up_product_id );

				// Re-read AFTER for requested fields.
				$up_actual = [];
				foreach ( array_keys( $up_requested ) as $f ) {
					$up_actual[ $f ] = $up_read( $f );
				}

				$up_diff = \Royal_MCP\MCP\Support\WriteVerifier::diff( $up_requested, $up_before, $up_actual );
				\Royal_MCP\MCP\Support\WriteVerifier::throw_if_dropped( $up_diff, 'wc_update_product' );

				// Undo envelope — includes manage_stock_before when relevant.
				$up_undo_pre = [
					'prior_values'   => $up_before,
					'applied_values' => $up_actual,
				];
                if ( $up_manage_stock_before !== null ) {
                    $up_undo_pre['manage_stock_before'] = $up_manage_stock_before;
                }
				$up_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
					'op'      => 'wc_update_product',
					'summary' => sprintf( 'Restore %d field(s) on product %d to prior values.', count( $up_before ), $up_product_id ),
					'target'  => [ 'product_id' => $up_product_id ],
					'pre_op_state' => $up_undo_pre,
				]);

				$up_struct = array_merge(
					[
						'id'      => $up_product_id,
						'updated' => true,
					],
					\Royal_MCP\MCP\Support\WriteVerifier::response_partial( $up_diff )
				);
				$up_summary = sprintf(
					'Updated product %d (%d field(s) applied%s), undo available.',
					$up_product_id,
					count( $up_diff['applied'] ) + count( $up_diff['silent_modifies'] ),
					! empty( $up_diff['silent_modifies'] ) ? ', WP modified value' : ''
				);
				return \Royal_MCP\MCP\Support\Envelope::success(
					$up_summary,
					$up_struct,
					$up_undo_envelope
				);

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
					// Return total_count alongside legacy 'total' for forward compatibility.
					'total'       => intval( $result->total ),
					'total_count' => intval( $result->total ),
					'total_pages' => intval( $result->max_num_pages ),
				];

			case 'wc_get_order':
				$order = wc_get_order( intval( $args['id'] ) );
				if ( ! $order || ! $order instanceof \WC_Order ) {
					return Envelope::error( 'not_found', sprintf( 'Order %d not found.', intval( $args['id'] ) ), [ 'id' => intval( $args['id'] ) ] );
				}
				$detail  = self::format_order_detail( $order );
				$summary = self::summarize_order( $detail );
				return Envelope::success( $summary, $detail );

			case 'wc_update_order_status':
				$os_order_id = intval( $args['id'] );
				$os_order    = wc_get_order( $os_order_id );
				if ( ! $os_order || ! $os_order instanceof \WC_Order ) {
					return Envelope::error( 'not_found', sprintf( 'Order %d not found.', $os_order_id ), [ 'id' => $os_order_id ] );
				}
				$os_allowed = [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ];
				$os_new     = sanitize_text_field( $args['status'] );
				if ( ! in_array( $os_new, $os_allowed, true ) ) {
					return Envelope::error( 'invalid_args', sprintf( 'Invalid order status "%s". Allowed: %s.', $os_new, implode( ', ', $os_allowed ) ), [ 'received' => $os_new, 'allowed' => $os_allowed ] );
				}
				// WC status column is stored as 'wc-<status>'; get_status() strips
				// the prefix so both prior and new values compare cleanly.
				$os_prior = $os_order->get_status();
				// WC_Order::add_order_note() already applies wp_kses_post
				// internally; sanitize_text_field was both redundant AND lossy
				// (stripped support-URL links from notes). Explicit wp_kses_post
				// here matches WC behavior + keeps the tool self-documenting.
				$os_note  = ! empty( $args['note'] ) ? wp_kses_post( $args['note'] ) : '';
				$os_order->update_status( $os_new, $os_note );

				// Re-read AFTER-state via a fresh instance — update_status may
				// short-circuit if the new status matches current (WC returns
				// early without re-emitting hooks). Re-fetching gives us the
				// authoritative post-op state regardless.
				$os_fresh   = wc_get_order( $os_order_id );
				$os_actual  = $os_fresh ? $os_fresh->get_status() : '';

				$os_diff = \Royal_MCP\MCP\Support\WriteVerifier::diff(
					[ 'status' => $os_new ],
					[ 'status' => $os_prior ],
					[ 'status' => $os_actual ]
				);
				\Royal_MCP\MCP\Support\WriteVerifier::throw_if_dropped( $os_diff, 'wc_update_order_status' );

				// Undo restores prior status. NOTE: side effects (email
				// notifications sent, inventory adjustments made,
				// woocommerce_order_status_changed hooks fired) are NOT
				// reversible — undo just walks the status back. WC will also
				// auto-add a note for the reverse transition, so the audit
				// trail records both moves. Documented on the response +
				// undo summary so callers know what's actually recoverable.
				$os_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
					'op'      => 'wc_update_order_status',
					'summary' => sprintf( 'Restore order %d status from %s back to %s. Note: email/inventory side effects are NOT reversed; a new order note will be added by WC for the reverse transition.', $os_order_id, $os_actual, $os_prior ),
					'target'  => [ 'order_id' => $os_order_id ],
					'pre_op_state' => [
						'prior_status'   => $os_prior,
						'applied_status' => $os_actual,
					],
				]);

				$os_struct = array_merge(
					[
						'id'           => $os_order_id,
						'status'       => $os_actual,
						'prior_status' => $os_prior,
					],
					\Royal_MCP\MCP\Support\WriteVerifier::response_partial( $os_diff )
				);
				$os_summary = sprintf(
					'Order %d: %s → %s%s, undo available (state only — side effects not reversed).',
					$os_order_id,
					$os_prior,
					$os_actual,
					! empty( $os_diff['silent_modifies'] ) ? ' (WP modified status)' : ''
				);
				return \Royal_MCP\MCP\Support\Envelope::success(
					$os_summary,
					$os_struct,
					$os_undo_envelope
				);

			case 'wc_create_order':
				if ( ! current_user_can( 'edit_shop_orders' ) ) {
					throw new \Exception( 'edit_shop_orders capability required.' );
				}
				$line_items = isset( $args['line_items'] ) && is_array( $args['line_items'] ) ? $args['line_items'] : [];
				if ( empty( $line_items ) ) {
					throw new \Exception( 'line_items is required and must be a non-empty array.' );
				}
				$new_order = wc_create_order();
				if ( is_wp_error( $new_order ) ) {
					throw new \Exception( 'wc_create_order failed: ' . esc_html( $new_order->get_error_message() ) );
				}
				// Add line items with pre-validation on variation_id belonging to product_id.
				foreach ( $line_items as $item ) {
					$product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
					$quantity     = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
					$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
					if ( $product_id <= 0 ) {
						throw new \Exception( 'line_items entry missing product_id.' );
					}
					$product = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
					if ( ! $product ) {
						throw new \Exception( 'Product not found: ' . esc_html( (string) ( $variation_id > 0 ? $variation_id : $product_id ) ) );
					}
					if ( $variation_id > 0 && (int) $product->get_parent_id() !== $product_id ) {
						throw new \Exception( 'variation_id ' . esc_html( (string) $variation_id ) . ' does not belong to product_id ' . esc_html( (string) $product_id ) . '.' );
					}
					$new_order->add_product( $product, $quantity );
				}
				// Optional shipping / fee lines.
				if ( ! empty( $args['shipping_lines'] ) && is_array( $args['shipping_lines'] ) ) {
					foreach ( $args['shipping_lines'] as $sl ) {
						$shipping_item = new \WC_Order_Item_Shipping();
						if ( ! empty( $sl['method_id'] ) )    { $shipping_item->set_method_id( sanitize_text_field( (string) $sl['method_id'] ) ); }
						if ( ! empty( $sl['method_title'] ) ) { $shipping_item->set_method_title( sanitize_text_field( (string) $sl['method_title'] ) ); }
						if ( isset( $sl['total'] ) )          { $shipping_item->set_total( (string) wc_format_decimal( $sl['total'] ) ); }
						$new_order->add_item( $shipping_item );
					}
				}
				if ( ! empty( $args['fee_lines'] ) && is_array( $args['fee_lines'] ) ) {
					foreach ( $args['fee_lines'] as $fee ) {
						$fee_item = new \WC_Order_Item_Fee();
						if ( ! empty( $fee['name'] ) ) { $fee_item->set_name( sanitize_text_field( (string) $fee['name'] ) ); }
						if ( isset( $fee['total'] ) )  { $fee_item->set_total( (string) wc_format_decimal( $fee['total'] ) ); }
						$new_order->add_item( $fee_item );
					}
				}
				// Billing / shipping / customer / payment_method / customer_note.
				if ( ! empty( $args['billing'] )  && is_array( $args['billing'] ) )  { $new_order->set_address( self::sanitize_address_fields( $args['billing'] ),  'billing' ); }
				if ( ! empty( $args['shipping'] ) && is_array( $args['shipping'] ) ) { $new_order->set_address( self::sanitize_address_fields( $args['shipping'] ), 'shipping' ); }
				if ( ! empty( $args['customer_id'] ) ) {
					$new_order->set_customer_id( (int) $args['customer_id'] );
				}
				if ( ! empty( $args['payment_method'] ) ) {
					$new_order->set_payment_method( sanitize_text_field( (string) $args['payment_method'] ) );
				}
				if ( ! empty( $args['customer_note'] ) ) {
					$new_order->set_customer_note( wp_kses_post( (string) $args['customer_note'] ) );
				}
				// Meta data.
				if ( ! empty( $args['meta_data'] ) && is_array( $args['meta_data'] ) ) {
					foreach ( $args['meta_data'] as $meta ) {
						if ( isset( $meta['key'] ) ) {
							$new_order->update_meta_data( sanitize_text_field( (string) $meta['key'] ), $meta['value'] ?? '' );
						}
					}
				}
				$new_order->calculate_totals();
				$initial_status = ! empty( $args['status'] ) ? sanitize_text_field( (string) $args['status'] ) : 'pending';
				$allowed_initial = [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled' ];
				if ( ! in_array( $initial_status, $allowed_initial, true ) ) {
					$initial_status = 'pending';
				}
				$new_order->set_status( $initial_status );
				$new_order->save();
				$co_email_sent = false;
				if ( ! empty( $args['send_emails'] ) ) {
					$mailer = \WC()->mailer();
					if ( $mailer && isset( $mailer->emails['WC_Email_New_Order'] ) ) {
						$mailer->emails['WC_Email_New_Order']->trigger( $new_order->get_id() );
						$co_email_sent = true;
					}
				}
				// Undo removes the specific order via WC_Order::delete(true) —
				// HPOS-aware. NOTE: if send_emails=true, the New Order email
				// has already been sent; that send is NOT reversible.
				$co_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
					'op'      => 'wc_create_order',
					'summary' => sprintf( 'Delete the order %d created by this operation.%s', $new_order->get_id(), $co_email_sent ? ' Note: New Order email was already sent and cannot be recalled.' : '' ),
					'target'  => [ 'order_id' => (int) $new_order->get_id() ],
					'pre_op_state' => [
						'created_by_op' => true,
						'email_sent'    => $co_email_sent,
					],
				]);
				return \Royal_MCP\MCP\Support\Envelope::success(
					sprintf( 'Created order %d (status: %s, total: %s), undo available.%s',
						$new_order->get_id(),
						$new_order->get_status(),
						$new_order->get_total(),
						$co_email_sent ? ' (New Order email sent — not recallable by undo.)' : ''
					),
					[
						'order_id'   => (int) $new_order->get_id(),
						'order_key'  => $new_order->get_order_key(),
						'total'      => $new_order->get_total(),
						'status'     => $new_order->get_status(),
						'email_sent' => $co_email_sent,
						'created'    => true,
					],
					$co_undo_envelope
				);

			case 'wc_update_order':
				$uo_order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
				$uo_order    = wc_get_order( $uo_order_id );
				if ( ! $uo_order || ! $uo_order instanceof \WC_Order ) {
					return Envelope::error( 'not_found', sprintf( 'Order %d not found.', $uo_order_id ), [ 'order_id' => $uo_order_id ] );
				}
				if ( ! current_user_can( 'edit_shop_order', $uo_order_id ) ) {
					throw new \Exception( 'edit_shop_order capability required on this order.' );
				}

				// Reversibility gate. billing / shipping / customer_note / status
				// get full snapshot + undo. meta_data and line_items retain their
				// existing write behavior but disable undo when included — the
				// reverse of an arbitrary meta_update or a line_item add/remove
				// requires domain-specific snapshotting (added-item's line_item_id
				// isn't preserved on re-add; removed-item's tax/shipping context
				// is lost). Same precedent as the 1MB-cap → no-undo path.
				$uo_untrackable_reasons = [];
				if ( ! empty( $args['meta_data'] ) && is_array( $args['meta_data'] ) ) {
					$uo_untrackable_reasons[] = 'meta_data writes are one-way (undo would require per-key prior-value snapshots not yet implemented for this tool)';
				}
				if ( ! empty( $args['line_items'] ) && is_array( $args['line_items'] ) ) {
					$uo_untrackable_reasons[] = 'line_items writes are one-way (added items get new IDs on undo re-add; removed items lose their original line_item_id + tax context)';
				}

				// Snapshot BEFORE for reversible fields, only when the caller
				// touched them. field_map is a flat arg-key => reader-callable
				// mapping so verify + undo iterate on one loop.
				$uo_billing_keys  = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ];
				$uo_shipping_keys = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ];

				$uo_requested = [];
				$uo_before    = [];
				if ( ! empty( $args['billing'] ) && is_array( $args['billing'] ) ) {
					foreach ( $uo_billing_keys as $k ) {
						if ( array_key_exists( $k, $args['billing'] ) ) {
							$sanitized = ( $k === 'email' ) ? sanitize_email( (string) $args['billing'][ $k ] ) : sanitize_text_field( (string) $args['billing'][ $k ] );
							$uo_requested[ "billing.$k" ] = $sanitized;
							$getter                       = 'get_billing_' . $k;
							$uo_before[ "billing.$k" ]    = (string) $uo_order->$getter();
						}
					}
				}
				if ( ! empty( $args['shipping'] ) && is_array( $args['shipping'] ) ) {
					foreach ( $uo_shipping_keys as $k ) {
						if ( array_key_exists( $k, $args['shipping'] ) ) {
							$uo_requested[ "shipping.$k" ] = sanitize_text_field( (string) $args['shipping'][ $k ] );
							$getter                        = 'get_shipping_' . $k;
							$uo_before[ "shipping.$k" ]    = (string) $uo_order->$getter();
						}
					}
				}
				if ( array_key_exists( 'customer_note', $args ) ) {
					$uo_requested['customer_note'] = wp_kses_post( (string) $args['customer_note'] );
					$uo_before['customer_note']    = (string) $uo_order->get_customer_note();
				}
				if ( ! empty( $args['status'] ) ) {
					$uo_new_status = sanitize_text_field( (string) $args['status'] );
					$uo_allowed    = [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ];
					if ( ! in_array( $uo_new_status, $uo_allowed, true ) ) {
						return Envelope::error( 'invalid_args', sprintf( 'Invalid order status "%s". Allowed: %s.', $uo_new_status, implode( ', ', $uo_allowed ) ), [ 'received' => $uo_new_status, 'allowed' => $uo_allowed ] );
					}
					$uo_requested['status'] = $uo_new_status;
					$uo_before['status']    = $uo_order->get_status();
				}

				// Execute — preserve existing behavior including the one-way
				// meta_data / line_items paths.
				if ( ! empty( $args['billing'] ) && is_array( $args['billing'] ) ) {
					$uo_order->set_address( array_merge( self::current_address( $uo_order, 'billing' ), self::sanitize_address_fields( $args['billing'] ) ), 'billing' );
				}
				if ( ! empty( $args['shipping'] ) && is_array( $args['shipping'] ) ) {
					$uo_order->set_address( array_merge( self::current_address( $uo_order, 'shipping' ), self::sanitize_address_fields( $args['shipping'] ) ), 'shipping' );
				}
				if ( array_key_exists( 'customer_note', $args ) ) {
					$uo_order->set_customer_note( wp_kses_post( (string) $args['customer_note'] ) );
				}
				if ( ! empty( $args['meta_data'] ) && is_array( $args['meta_data'] ) ) {
					foreach ( $args['meta_data'] as $meta ) {
						if ( isset( $meta['key'] ) ) {
							$uo_order->update_meta_data( sanitize_text_field( (string) $meta['key'] ), $meta['value'] ?? '' );
						}
					}
				}
				if ( ! empty( $args['line_items'] ) && is_array( $args['line_items'] ) ) {
					foreach ( $args['line_items'] as $item ) {
						$item_id      = isset( $item['id'] ) ? (int) $item['id'] : 0;
						$product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
						$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
						$quantity     = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
						if ( $item_id > 0 && $quantity === 0 ) {
							$uo_order->remove_item( $item_id );
							continue;
						}
						if ( $item_id > 0 ) {
							$existing = $uo_order->get_item( $item_id );
							if ( $existing ) {
								$existing->set_quantity( max( 1, $quantity ) );
								$existing->save();
							}
							continue;
						}
						if ( $product_id <= 0 ) {
							throw new \Exception( 'line_items entry without id must include product_id.' );
						}
						$product = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
						if ( ! $product ) {
							throw new \Exception( 'Product not found: ' . esc_html( (string) ( $variation_id > 0 ? $variation_id : $product_id ) ) );
						}
						if ( $variation_id > 0 && (int) $product->get_parent_id() !== $product_id ) {
							throw new \Exception( 'variation_id ' . esc_html( (string) $variation_id ) . ' does not belong to product_id ' . esc_html( (string) $product_id ) . '.' );
						}
						$uo_order->add_product( $product, max( 1, $quantity ) );
					}
				}
				if ( array_key_exists( 'status', $uo_requested ) ) {
					$uo_order->set_status( $uo_requested['status'] );
				}
				$uo_order->calculate_totals();
				$uo_order->save();

				// Re-read AFTER for the reversible fields.
				$uo_fresh   = wc_get_order( $uo_order_id );
				$uo_actual  = [];
				foreach ( array_keys( $uo_requested ) as $arg_key ) {
					if ( strpos( $arg_key, 'billing.' ) === 0 ) {
						$getter = 'get_billing_' . substr( $arg_key, 8 );
						$uo_actual[ $arg_key ] = (string) $uo_fresh->$getter();
					} elseif ( strpos( $arg_key, 'shipping.' ) === 0 ) {
						$getter = 'get_shipping_' . substr( $arg_key, 9 );
						$uo_actual[ $arg_key ] = (string) $uo_fresh->$getter();
					} elseif ( $arg_key === 'customer_note' ) {
						$uo_actual[ $arg_key ] = (string) $uo_fresh->get_customer_note();
					} elseif ( $arg_key === 'status' ) {
						$uo_actual[ $arg_key ] = $uo_fresh->get_status();
					}
				}

				$uo_diff = \Royal_MCP\MCP\Support\WriteVerifier::diff( $uo_requested, $uo_before, $uo_actual );
				\Royal_MCP\MCP\Support\WriteVerifier::throw_if_dropped( $uo_diff, 'wc_update_order' );

				// Undo envelope only if reversible fields were touched AND no
				// untrackable fields were included.
				$uo_undo_envelope = null;
				if ( ! empty( $uo_before ) && empty( $uo_untrackable_reasons ) ) {
					$uo_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
						'op'      => 'wc_update_order',
						'summary' => sprintf( 'Restore %d field(s) on order %d to prior values.', count( $uo_before ), $uo_order_id ),
						'target'  => [ 'order_id' => $uo_order_id ],
						'pre_op_state' => [
							'prior_values'   => $uo_before,
							'applied_values' => $uo_actual,
						],
					]);
				}

				$uo_struct = array_merge(
					[
						'order_id' => $uo_order_id,
						'updated'  => true,
						'total'    => $uo_fresh->get_total(),
						'status'   => $uo_fresh->get_status(),
					],
					\Royal_MCP\MCP\Support\WriteVerifier::response_partial( $uo_diff )
				);
				if ( ! empty( $uo_untrackable_reasons ) ) {
					$uo_struct['warnings'] = $uo_untrackable_reasons;
					$uo_struct['undo_available'] = false;
				} else {
					$uo_struct['undo_available'] = ( $uo_undo_envelope !== null );
				}

				$uo_summary = sprintf(
					'Updated order %d (%d reversible field(s) applied%s%s%s).',
					$uo_order_id,
					count( $uo_diff['applied'] ) + count( $uo_diff['silent_modifies'] ),
					! empty( $uo_diff['silent_modifies'] ) ? ', WP modified value' : '',
					! empty( $uo_untrackable_reasons ) ? sprintf( ', %d untrackable path(s) — undo disabled', count( $uo_untrackable_reasons ) ) : '',
					$uo_undo_envelope !== null ? ', undo available' : ' (no undo)'
				);
				return \Royal_MCP\MCP\Support\Envelope::success(
					$uo_summary,
					$uo_struct,
					$uo_undo_envelope
				);

			case 'wc_add_order_note':
				$an_order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
				$an_order    = wc_get_order( $an_order_id );
				if ( ! $an_order || ! $an_order instanceof \WC_Order ) {
					return Envelope::error( 'not_found', sprintf( 'Order %d not found.', $an_order_id ), [ 'order_id' => $an_order_id ] );
				}
				if ( ! current_user_can( 'edit_shop_order', $an_order_id ) ) {
					throw new \Exception( 'edit_shop_order capability required on this order.' );
				}
				$an_text = isset( $args['note'] ) ? wp_kses_post( (string) $args['note'] ) : '';
				if ( $an_text === '' ) {
					throw new \Exception( 'note is required.' );
				}
				$an_is_customer = ! empty( $args['customer_note'] );
				$an_note_id     = (int) $an_order->add_order_note( $an_text, $an_is_customer ? 1 : 0 );
				if ( $an_note_id <= 0 ) {
					throw new \Exception( 'add_order_note returned 0 — note was not persisted.' );
				}

				// Undo removes the specific note by comment_id (order notes are
				// stored as wp_comments rows with comment_type=order_note).
				// If customer_note=true, WC also emails the customer — that
				// send is NOT reversible.
				$an_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
					'op'      => 'wc_add_order_note',
					'summary' => sprintf( 'Remove order note %d added by this operation on order %d.%s', $an_note_id, $an_order_id, $an_is_customer ? ' Note: customer-note email was already sent and cannot be recalled.' : '' ),
					'target'  => [ 'note_id' => $an_note_id, 'order_id' => $an_order_id ],
					'pre_op_state' => [
						'added_by_op'   => true,
						'customer_note' => $an_is_customer,
					],
				]);

				return \Royal_MCP\MCP\Support\Envelope::success(
					sprintf( 'Added %s to order %d (note_id: %d), undo available.%s',
						$an_is_customer ? 'customer note' : 'private note',
						$an_order_id,
						$an_note_id,
						$an_is_customer ? ' (Email already sent — not recallable by undo.)' : ''
					),
					[
						'note_id'       => $an_note_id,
						'order_id'      => $an_order_id,
						'customer_note' => $an_is_customer,
						'created'       => true,
					],
					$an_undo_envelope
				);

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
				$cv_parent_id = intval( $args['product_id'] );
				$cv_parent    = wc_get_product( $cv_parent_id );
				if ( ! $cv_parent ) {
					return Envelope::error( 'not_found', sprintf( 'Product %d not found.', $cv_parent_id ), [ 'product_id' => $cv_parent_id ] );
				}
				if ( ! $cv_parent->is_type( 'variable' ) ) {
					return Envelope::error( 'invalid_args', sprintf( 'Product %d is not a variable product (type: %s).', $cv_parent_id, $cv_parent->get_type() ), [ 'product_id' => $cv_parent_id, 'type' => $cv_parent->get_type() ] );
				}
				$cv_variation = new \WC_Product_Variation();
				$cv_variation->set_parent_id( $cv_parent_id );
				self::apply_variation_fields( $cv_variation, $args );
				$cv_new_id = $cv_variation->save();
				if ( ! $cv_new_id ) {
					throw new \Exception( 'Failed to create variation' );
				}
				\WC_Product_Variable::sync( $cv_parent );
				// Undo removes the specific variation. Parent must be re-synced
				// after delete so price range + stock aggregation stay coherent.
				$cv_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
					'op'      => 'wc_create_variation',
					'summary' => sprintf( 'Delete the variation %d created by this operation and re-sync parent product %d.', $cv_new_id, $cv_parent_id ),
					'target'  => [ 'variation_id' => (int) $cv_new_id, 'product_id' => $cv_parent_id ],
					'pre_op_state' => [
						'created_by_op' => true,
					],
				]);
				return \Royal_MCP\MCP\Support\Envelope::success(
					sprintf( 'Created variation %d on product %d, undo available.', $cv_new_id, $cv_parent_id ),
					[
						'id'         => (int) $cv_new_id,
						'product_id' => $cv_parent_id,
						'created'    => true,
					],
					$cv_undo_envelope
				);

			case 'wc_update_variation':
				$uv_var_id  = intval( $args['variation_id'] );
				$uv_var     = $uv_var_id > 0 ? wc_get_product( $uv_var_id ) : null;
				if ( ! $uv_var || ! $uv_var->is_type( 'variation' ) ) {
					return Envelope::error( 'not_found', sprintf( 'Variation %d not found.', $uv_var_id ), [ 'variation_id' => $uv_var_id ] );
				}
				if ( $uv_var->get_parent_id() !== intval( $args['product_id'] ) ) {
					return Envelope::error( 'invalid_args', 'Variation does not belong to the specified product.', [ 'variation_id' => $uv_var_id, 'product_id' => intval( $args['product_id'] ) ] );
				}
				if ( ! current_user_can( 'edit_product', $uv_var_id ) ) {
					throw new \Exception( 'edit_product capability required on this variation.' );
				}

				// Build the requested-field map. Nested dimensions get dotted
				// keys (dimensions.length, etc). Attributes stay as-is (parsed
				// via parse_variation_attributes on write) — comparison is
				// json-canonicalized so key-order variance doesn't false-trigger
				// silent_modify.
				$uv_requested = [];
				if ( isset( $args['regular_price'] ) )  $uv_requested['regular_price']  = sanitize_text_field( (string) $args['regular_price'] );
				if ( isset( $args['sale_price'] ) )     $uv_requested['sale_price']     = sanitize_text_field( (string) $args['sale_price'] );
				if ( isset( $args['sku'] ) )            $uv_requested['sku']            = sanitize_text_field( (string) $args['sku'] );
				if ( isset( $args['status'] ) )         $uv_requested['status']         = in_array( $args['status'], [ 'publish', 'private' ], true ) ? $args['status'] : 'publish';
				if ( isset( $args['manage_stock'] ) )   $uv_requested['manage_stock']   = (bool) $args['manage_stock'];
				if ( isset( $args['stock_quantity'] ) ) $uv_requested['stock_quantity'] = (int) $args['stock_quantity'];
				if ( isset( $args['stock_status'] ) )   $uv_requested['stock_status']   = sanitize_text_field( (string) $args['stock_status'] );
				if ( isset( $args['weight'] ) )         $uv_requested['weight']         = sanitize_text_field( (string) $args['weight'] );
				if ( isset( $args['description'] ) )    $uv_requested['description']    = wp_kses_post( (string) $args['description'] );
				if ( isset( $args['image_id'] ) )       $uv_requested['image_id']       = (int) $args['image_id'];
				if ( isset( $args['dimensions']['length'] ) ) $uv_requested['dimensions.length'] = sanitize_text_field( (string) $args['dimensions']['length'] );
				if ( isset( $args['dimensions']['width'] ) )  $uv_requested['dimensions.width']  = sanitize_text_field( (string) $args['dimensions']['width'] );
				if ( isset( $args['dimensions']['height'] ) ) $uv_requested['dimensions.height'] = sanitize_text_field( (string) $args['dimensions']['height'] );

				if ( empty( $uv_requested ) && ! isset( $args['attributes'] ) ) {
					throw new \Exception( 'No update fields provided. Pass at least one field to update.' );
				}

				$uv_read = function( $arg_key ) use ( $uv_var_id ) {
					$v = wc_get_product( $uv_var_id );
					if ( ! $v ) return null;
					switch ( $arg_key ) {
						case 'regular_price':      return (string) $v->get_regular_price();
						case 'sale_price':         return (string) $v->get_sale_price();
						case 'sku':                return (string) $v->get_sku();
						case 'status':             return (string) $v->get_status();
						case 'manage_stock':       return (bool)   $v->get_manage_stock();
						case 'stock_quantity':     return (int)    $v->get_stock_quantity();
						case 'stock_status':       return (string) $v->get_stock_status();
						case 'weight':             return (string) $v->get_weight();
						case 'description':        return (string) $v->get_description();
						case 'image_id':           return (int)    $v->get_image_id();
						case 'dimensions.length':  return (string) $v->get_length();
						case 'dimensions.width':   return (string) $v->get_width();
						case 'dimensions.height':  return (string) $v->get_height();
					}
					return null;
				};

				// Snapshot BEFORE for tracked scalar fields
				$uv_before = [];
				foreach ( array_keys( $uv_requested ) as $f ) {
					$uv_before[ $f ] = $uv_read( $f );
				}
				// Attributes handled separately — one snapshot + one restore.
				// json_encode compare avoids key-order false silent_modifies.
				$uv_attrs_before_json = null;
				if ( isset( $args['attributes'] ) ) {
					$uv_attrs_before_json = wp_json_encode( $uv_var->get_attributes() );
				}

				self::apply_variation_fields( $uv_var, $args );
				$uv_var->save();
				$parent = wc_get_product( $uv_var->get_parent_id() );
				if ( $parent ) {
					\WC_Product_Variable::sync( $parent );
				}
				wc_delete_product_transients( $uv_var_id );
				if ( $parent ) wc_delete_product_transients( $parent->get_id() );

				// Re-read AFTER
				$uv_actual = [];
				foreach ( array_keys( $uv_requested ) as $f ) {
					$uv_actual[ $f ] = $uv_read( $f );
				}

				$uv_diff = \Royal_MCP\MCP\Support\WriteVerifier::diff( $uv_requested, $uv_before, $uv_actual );
				\Royal_MCP\MCP\Support\WriteVerifier::throw_if_dropped( $uv_diff, 'wc_update_variation' );

				// Undo envelope. Attributes stored as JSON so complex-shape
				// preservation on restore is guaranteed (arrays with mixed
				// numeric+string keys survive json round-trip).
				$uv_undo_pre = [
					'prior_values'   => $uv_before,
					'applied_values' => $uv_actual,
					'parent_id'      => (int) $uv_var->get_parent_id(),
				];
				if ( $uv_attrs_before_json !== null ) {
					$uv_undo_pre['attributes_before_json'] = $uv_attrs_before_json;
				}
				$uv_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
					'op'      => 'wc_update_variation',
					'summary' => sprintf( 'Restore %d field(s) on variation %d.', count( $uv_before ) + ( $uv_attrs_before_json ? 1 : 0 ), $uv_var_id ),
					'target'  => [ 'variation_id' => $uv_var_id, 'product_id' => (int) $uv_var->get_parent_id() ],
					'pre_op_state' => $uv_undo_pre,
				]);

				$uv_struct = array_merge(
					[
						'id'         => $uv_var_id,
						'product_id' => (int) $uv_var->get_parent_id(),
						'updated'    => true,
					],
					\Royal_MCP\MCP\Support\WriteVerifier::response_partial( $uv_diff )
				);
				return \Royal_MCP\MCP\Support\Envelope::success(
					sprintf( 'Updated variation %d (%d field(s) applied%s), undo available.',
						$uv_var_id,
						count( $uv_diff['applied'] ) + count( $uv_diff['silent_modifies'] ) + ( $uv_attrs_before_json ? 1 : 0 ),
						! empty( $uv_diff['silent_modifies'] ) ? ', WP modified value' : ''
					),
					$uv_struct,
					$uv_undo_envelope
				);

			case 'wc_delete_variation':
				$dv_var_id  = intval( $args['variation_id'] );
				$dv_var     = $dv_var_id > 0 ? wc_get_product( $dv_var_id ) : null;
				if ( ! $dv_var || ! $dv_var->is_type( 'variation' ) ) {
					return Envelope::error( 'not_found', sprintf( 'Variation %d not found.', $dv_var_id ), [ 'variation_id' => $dv_var_id ] );
				}
				$dv_parent_id = intval( $args['product_id'] );
				if ( $dv_var->get_parent_id() !== $dv_parent_id ) {
					return Envelope::error( 'invalid_args', 'Variation does not belong to the specified product.', [ 'variation_id' => $dv_var_id, 'product_id' => $dv_parent_id ] );
				}
				if ( ! current_user_can( 'delete_product', $dv_var_id ) ) {
					throw new \Exception( 'delete_product capability required on this variation.' );
				}
				$dv_force = isset( $args['force'] ) ? (bool) $args['force'] : true;

				// Snapshot BEFORE the delete. For soft-trash (force=false), we
				// just need the parent_id to re-sync on undo. For hard-delete
                // (force=true) capture the full field state so wc_create_variation
                // can rebuild it. Note: recreated variation gets a NEW ID
                // (WC auto-increments); undo summary flags this explicitly.
				$dv_full = null;
				if ( $dv_force ) {
					$dv_full = [
						'parent_id'      => $dv_parent_id,
						'regular_price'  => (string) $dv_var->get_regular_price(),
						'sale_price'     => (string) $dv_var->get_sale_price(),
						'sku'            => (string) $dv_var->get_sku(),
						'status'         => (string) $dv_var->get_status(),
						'manage_stock'   => (bool)   $dv_var->get_manage_stock(),
						'stock_quantity' => (int)    $dv_var->get_stock_quantity(),
						'stock_status'   => (string) $dv_var->get_stock_status(),
						'weight'         => (string) $dv_var->get_weight(),
						'length'         => (string) $dv_var->get_length(),
						'width'          => (string) $dv_var->get_width(),
						'height'         => (string) $dv_var->get_height(),
						'description'    => (string) $dv_var->get_description(),
						'image_id'       => (int)    $dv_var->get_image_id(),
						'attributes_json' => (string) wp_json_encode( $dv_var->get_attributes() ),
						'menu_order'     => (int)    $dv_var->get_menu_order(),
					];
				}

				$dv_var->delete( $dv_force );
				$dv_parent = wc_get_product( $dv_parent_id );
				if ( $dv_parent ) {
					\WC_Product_Variable::sync( $dv_parent );
				}

				$dv_undo_envelope = null;
				if ( $dv_force ) {
					$dv_reverse_json = (string) wp_json_encode( $dv_full );
					if ( strlen( gzcompress( $dv_reverse_json, 9 ) ) > 1024 * 1024 ) {
						// > 1MB snapshot cap; skip undo with warning
					} else {
						$dv_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
							'op'      => 'wc_delete_variation_force',
							'summary' => sprintf( 'Recreate the force-deleted variation on product %d. Note: the new variation ID will differ from the original (%d).', $dv_parent_id, $dv_var_id ),
							'target'  => [ 'original_variation_id' => $dv_var_id, 'product_id' => $dv_parent_id ],
							'pre_op_state' => [
								'row' => $dv_full,
							],
						]);
					}
				} else {
					// Soft trash — undo via wp_untrash_post
					$dv_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
						'op'      => 'wc_delete_variation_trash',
						'summary' => sprintf( 'Untrash variation %d and re-sync parent product %d.', $dv_var_id, $dv_parent_id ),
						'target'  => [ 'variation_id' => $dv_var_id, 'product_id' => $dv_parent_id ],
					]);
				}

				return \Royal_MCP\MCP\Support\Envelope::success(
					sprintf( '%s variation %d%s.',
						$dv_force ? 'Force-deleted' : 'Trashed',
						$dv_var_id,
						$dv_force ? ' (undo will recreate with new ID)' : ' (undo will untrash)'
					),
					[
						'id'              => $dv_var_id,
						'product_id'      => $dv_parent_id,
						'deleted'         => true,
						'force'           => $dv_force,
						'undo_available'  => $dv_undo_envelope !== null,
					],
					$dv_undo_envelope
				);

			case 'wc_batch_update_variations':
				$bv_pid     = intval( $args['product_id'] );
				$bv_product = $bv_pid > 0 ? wc_get_product( $bv_pid ) : null;
				if ( ! $bv_product ) {
					return Envelope::error( 'not_found', sprintf( 'Product %d not found.', $bv_pid ), [ 'product_id' => $bv_pid ] );
				}
				if ( ! $bv_product->is_type( 'variable' ) ) {
					return Envelope::error( 'invalid_args', sprintf( 'Product %d is not a variable product (type: %s).', $bv_pid, $bv_product->get_type() ), [ 'product_id' => $bv_pid, 'type' => $bv_product->get_type() ] );
				}
				if ( ! current_user_can( 'edit_product', $bv_pid ) ) {
					throw new \Exception( 'edit_product capability required on this product.' );
				}

				$bv_result = [ 'create' => [], 'update' => [], 'delete' => [] ];
				// Per-op undo snapshots. Every successful op gets an entry.
				$bv_undo_created = []; // [ new_variation_id, ... ]
				$bv_undo_updated = []; // [ [variation_id => {field => prior_value}], ... ]
				$bv_undo_deleted = []; // [ full row snapshot, ... ]

				// CREATE ops — snapshot the new IDs so undo can delete them
				foreach ( $args['create'] ?? [] as $data ) {
					$variation = new \WC_Product_Variation();
					$variation->set_parent_id( $bv_pid );
					self::apply_variation_fields( $variation, $data );
					$new_id = $variation->save();
					$bv_result['create'][] = [ 'id' => $new_id ];
					if ( $new_id ) {
						$bv_undo_created[] = (int) $new_id;
					}
				}

				// UPDATE ops — snapshot BEFORE state per updated field
				foreach ( $args['update'] ?? [] as $data ) {
					$var_id    = intval( $data['variation_id'] ?? 0 );
					$variation = wc_get_product( $var_id );
					if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
						$bv_result['update'][] = [ 'id' => $var_id, 'error' => 'Not found' ];
						continue;
					}
					if ( $variation->get_parent_id() !== $bv_pid ) {
						$bv_result['update'][] = [ 'id' => $var_id, 'error' => 'Variation does not belong to this product' ];
						continue;
					}
					// Snapshot BEFORE — same field-map as wc_update_variation.
					$prior = [];
					foreach ( [ 'regular_price', 'sale_price', 'sku', 'status', 'manage_stock', 'stock_quantity',
					            'stock_status', 'weight', 'length', 'width', 'height', 'description', 'image_id' ] as $f ) {
						if ( ! array_key_exists( $f, $data ) && ! isset( $data['dimensions'][ $f ] ) ) continue;
						switch ( $f ) {
							case 'regular_price':  $prior[ $f ] = (string) $variation->get_regular_price(); break;
							case 'sale_price':     $prior[ $f ] = (string) $variation->get_sale_price(); break;
							case 'sku':            $prior[ $f ] = (string) $variation->get_sku(); break;
							case 'status':         $prior[ $f ] = (string) $variation->get_status(); break;
							case 'manage_stock':   $prior[ $f ] = (bool)   $variation->get_manage_stock(); break;
							case 'stock_quantity': $prior[ $f ] = (int)    $variation->get_stock_quantity(); break;
							case 'stock_status':   $prior[ $f ] = (string) $variation->get_stock_status(); break;
							case 'weight':         $prior[ $f ] = (string) $variation->get_weight(); break;
							case 'length':         $prior[ $f ] = (string) $variation->get_length(); break;
							case 'width':          $prior[ $f ] = (string) $variation->get_width(); break;
							case 'height':         $prior[ $f ] = (string) $variation->get_height(); break;
							case 'description':    $prior[ $f ] = (string) $variation->get_description(); break;
							case 'image_id':       $prior[ $f ] = (int)    $variation->get_image_id(); break;
						}
					}
					if ( isset( $data['attributes'] ) ) {
						$prior['attributes_json'] = (string) wp_json_encode( $variation->get_attributes() );
					}
					self::apply_variation_fields( $variation, $data );
					$variation->save();
					$bv_result['update'][] = [ 'id' => $var_id ];
					if ( ! empty( $prior ) ) {
						$bv_undo_updated[] = [ 'variation_id' => $var_id, 'prior' => $prior ];
					}
				}

				// DELETE ops — snapshot full row BEFORE delete for recreate-on-undo
				foreach ( $args['delete'] ?? [] as $var_id ) {
					$var_id_int = intval( $var_id );
					$variation = wc_get_product( $var_id_int );
					if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
						$bv_result['delete'][] = [ 'id' => $var_id, 'error' => 'Not found' ];
						continue;
					}
					if ( $variation->get_parent_id() !== $bv_pid ) {
						$bv_result['delete'][] = [ 'id' => $var_id, 'error' => 'Variation does not belong to this product' ];
						continue;
					}
					// Full snapshot (matches wc_delete_variation force pattern)
					$row = [
						'original_id'    => $var_id_int,
						'regular_price'  => (string) $variation->get_regular_price(),
						'sale_price'     => (string) $variation->get_sale_price(),
						'sku'            => (string) $variation->get_sku(),
						'status'         => (string) $variation->get_status(),
						'manage_stock'   => (bool)   $variation->get_manage_stock(),
						'stock_quantity' => (int)    $variation->get_stock_quantity(),
						'stock_status'   => (string) $variation->get_stock_status(),
						'weight'         => (string) $variation->get_weight(),
						'length'         => (string) $variation->get_length(),
						'width'          => (string) $variation->get_width(),
						'height'         => (string) $variation->get_height(),
						'description'    => (string) $variation->get_description(),
						'image_id'       => (int)    $variation->get_image_id(),
						'attributes_json' => (string) wp_json_encode( $variation->get_attributes() ),
						'menu_order'     => (int)    $variation->get_menu_order(),
					];
					$variation->delete( true );
					$bv_result['delete'][] = [ 'id' => $var_id, 'deleted' => true ];
					$bv_undo_deleted[] = $row;
				}
				\WC_Product_Variable::sync( $bv_product );
				wc_delete_product_transients( $bv_pid );

				// Build undo envelope with all 3 op-type snapshots. Cap 1MB.
				$bv_undo_envelope = null;
				$bv_warnings      = [];
				$bv_undo_payload  = [
					'created' => $bv_undo_created,
					'updated' => $bv_undo_updated,
					'deleted' => $bv_undo_deleted,
				];
				$bv_op_count = count( $bv_undo_created ) + count( $bv_undo_updated ) + count( $bv_undo_deleted );
				if ( $bv_op_count === 0 ) {
					// Nothing to undo (all ops failed or empty batch)
				} else {
					$bv_snap_json = (string) wp_json_encode( $bv_undo_payload );
					if ( strlen( gzcompress( $bv_snap_json, 9 ) ) > 1024 * 1024 ) {
						$bv_warnings[] = sprintf( 'undo not available — snapshot of %d op(s) exceeds 1MB storage cap. SiteVault snapshot recommended for reversal.', $bv_op_count );
					} else {
						$bv_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
							'op'      => 'wc_batch_update_variations',
							'summary' => sprintf( 'Reverse batch on product %d: delete %d created, restore %d updated, recreate %d deleted. Recreated variations get new IDs.', $bv_pid, count( $bv_undo_created ), count( $bv_undo_updated ), count( $bv_undo_deleted ) ),
							'target'  => [ 'product_id' => $bv_pid ],
							'pre_op_state' => $bv_undo_payload,
						]);
					}
				}

				$bv_struct = array_merge(
					$bv_result,
					[
						'product_id'      => $bv_pid,
						'op_counts'       => [
							'created' => count( $bv_undo_created ),
							'updated' => count( $bv_undo_updated ),
							'deleted' => count( $bv_undo_deleted ),
						],
						'undo_available'  => $bv_undo_envelope !== null,
					]
				);
				if ( ! empty( $bv_warnings ) ) {
					$bv_struct['warnings'] = $bv_warnings;
				}
				return \Royal_MCP\MCP\Support\Envelope::success(
					sprintf( 'Batch on product %d: %d created, %d updated, %d deleted%s.',
						$bv_pid,
						count( $bv_undo_created ),
						count( $bv_undo_updated ),
						count( $bv_undo_deleted ),
						$bv_undo_envelope !== null ? ', undo available' : ' (no undo)'
					),
					$bv_struct,
					$bv_undo_envelope
				);

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
				$cpa_data = [
					'name'         => sanitize_text_field( $args['name'] ),
					'slug'         => sanitize_title( $args['slug'] ?? $args['name'] ),
					'type'         => in_array( $args['type'] ?? 'select', [ 'select', 'text', 'color', 'image', 'button' ], true ) ? ( $args['type'] ?? 'select' ) : 'select',
					'order_by'     => in_array( $args['order_by'] ?? 'menu_order', [ 'menu_order', 'name', 'name_num', 'id' ], true ) ? ( $args['order_by'] ?? 'menu_order' ) : 'menu_order',
					'has_archives' => (bool) ( $args['has_archives'] ?? false ),
				];
				$cpa_new_id = wc_create_attribute( $cpa_data );
				if ( is_wp_error( $cpa_new_id ) ) {
					return Envelope::error( 'invalid_args', $cpa_new_id->get_error_message(), [ 'code' => $cpa_new_id->get_error_code() ] );
				}
				$cpa_taxonomy = wc_attribute_taxonomy_name( $cpa_data['slug'] );
				// Undo removes the attribute via wc_delete_attribute — this
				// also cascades all terms in the attribute taxonomy (any
				// values customers configured for products using this
				// attribute). Non-recoverable side effect flagged in the
				// undo summary.
				$cpa_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
					'op'      => 'wc_create_product_attribute',
					'summary' => sprintf( 'Delete the product attribute %d (%s) created by this operation. Note: cascades all attribute terms — any product values using this attribute will be dropped.', (int) $cpa_new_id, $cpa_taxonomy ),
					'target'  => [ 'attribute_id' => (int) $cpa_new_id, 'slug' => $cpa_taxonomy ],
					'pre_op_state' => [
						'created_by_op' => true,
					],
				]);
				return \Royal_MCP\MCP\Support\Envelope::success(
					sprintf( 'Created product attribute %d (%s), undo available (cascades attribute terms).', (int) $cpa_new_id, $cpa_taxonomy ),
					[
						'id'      => (int) $cpa_new_id,
						'slug'    => $cpa_taxonomy,
						'created' => true,
					],
					$cpa_undo_envelope
				);

			case 'wc_set_product_attributes':
				$spa_pid = intval( $args['product_id'] );
				$spa_product = $spa_pid > 0 ? wc_get_product( $spa_pid ) : null;
				if ( ! $spa_product ) {
					return Envelope::error( 'not_found', sprintf( 'Product %d not found.', $spa_pid ), [ 'product_id' => $spa_pid ] );
				}
				if ( ! current_user_can( 'edit_product', $spa_pid ) ) {
					throw new \Exception( 'edit_product capability required on this product.' );
				}
				if ( ! isset( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
					return Envelope::error( 'invalid_args', 'attributes must be an array.', [ 'received_type' => gettype( $args['attributes'] ?? null ) ] );
				}

				// Snapshot BEFORE — full attribute set serialized to JSON so
				// mixed numeric+string keys survive the storage round-trip
				// (WC_Product_Attribute objects don't serialize cleanly via
				// standard PHP serialize + wp_options blob storage).
				$spa_before_attrs = $spa_product->get_attributes();
				$spa_before_snapshot = self::serialize_product_attributes( $spa_before_attrs );
				$spa_existing_count  = count( $spa_before_attrs );

				// Execute — same logic as before, restructured for envelope wrap.
				$spa_new_attrs = [];
				$spa_auto_pos  = 0;
				foreach ( $args['attributes'] as $attr_data ) {
					$attribute = new \WC_Product_Attribute();
					$attr_id   = intval( $attr_data['id'] ?? 0 );
					$attribute->set_id( $attr_id );
					$attribute->set_position( isset( $attr_data['position'] ) ? intval( $attr_data['position'] ) : $spa_auto_pos );
					$attribute->set_visible( (bool) ( $attr_data['visible'] ?? true ) );
					$attribute->set_variation( (bool) ( $attr_data['variation'] ?? false ) );
					if ( $attr_id > 0 ) {
						$global_attr = wc_get_attribute( $attr_id );
						if ( ! $global_attr || is_wp_error( $global_attr ) ) {
							return Envelope::error( 'not_found', sprintf( 'Attribute ID %d not found.', $attr_id ), [ 'attribute_id' => $attr_id ] );
						}
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
					$spa_new_attrs[] = $attribute;
					++$spa_auto_pos;
				}
				$spa_product->set_attributes( $spa_new_attrs );
				$spa_product->save();
				wc_delete_product_transients( $spa_pid );

				// Undo restores the prior full attribute set via serialize/
				// deserialize round-trip. Snapshot cap at 1MB.
				$spa_undo_envelope = null;
				$spa_warnings      = [];
				$spa_snap_json     = (string) wp_json_encode( $spa_before_snapshot );
				if ( strlen( gzcompress( $spa_snap_json, 9 ) ) > 1024 * 1024 ) {
					$spa_warnings[] = 'undo not available — prior attribute snapshot exceeds 1MB storage cap. SiteVault snapshot recommended for reversal.';
				} else {
					$spa_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
						'op'      => 'wc_set_product_attributes',
						'summary' => sprintf( 'Restore %d prior attribute(s) on product %d.', $spa_existing_count, $spa_pid ),
						'target'  => [ 'product_id' => $spa_pid ],
						'pre_op_state' => [
							'attributes_before' => $spa_before_snapshot,
						],
					]);
				}

				$spa_struct = [
					'id'               => $spa_pid,
					'attribute_count'  => count( $spa_new_attrs ),
					'prior_count'      => $spa_existing_count,
					'undo_available'   => $spa_undo_envelope !== null,
				];
				if ( $spa_existing_count > 0 ) {
					$spa_warnings[] = sprintf(
						'This operation replaced %d existing attribute(s). Any variations using removed attributes may be affected.',
						$spa_existing_count
					);
				}
				if ( ! empty( $spa_warnings ) ) {
					$spa_struct['warnings'] = $spa_warnings;
				}
				return \Royal_MCP\MCP\Support\Envelope::success(
					sprintf( 'Updated product %d attributes (%d set, %d prior%s)%s.',
						$spa_pid,
						count( $spa_new_attrs ),
						$spa_existing_count,
						$spa_existing_count > 0 ? ' replaced' : '',
						$spa_undo_envelope !== null ? ', undo available' : ' (no undo)'
					),
					$spa_struct,
					$spa_undo_envelope
				);

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
				$cc_code = strtolower( sanitize_text_field( $args['code'] ?? '' ) );
				if ( empty( $cc_code ) ) {
					throw new \Exception( 'Coupon code is required' );
				}
				// Note: wc_get_coupon_id_by_code + save is not atomic; a duplicate code
				// inserted concurrently between these two calls would result in two coupons
				// sharing a code. WooCommerce resolves this by using the most-recent one.
				// No mutex is available at the WP/PHP level; this is an accepted limitation.
				$cc_existing = wc_get_coupon_id_by_code( $cc_code );
				if ( $cc_existing ) {
					return Envelope::error( 'conflict', sprintf( 'A coupon with code "%s" already exists (id %d).', $cc_code, $cc_existing ), [ 'code' => $cc_code, 'existing_id' => (int) $cc_existing ] );
				}
				$cc_coupon = new \WC_Coupon();
				$cc_coupon->set_code( $cc_code );
				$cc_coupon->set_discount_type( 'fixed_cart' ); // explicit default; WC default matches but we make it clear
				self::apply_coupon_fields( $cc_coupon, $args );
				$cc_new_id = $cc_coupon->save();
				if ( ! $cc_new_id ) {
					throw new \Exception( 'Failed to create coupon' );
				}
				$cc_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
					'op'      => 'wc_create_coupon',
					'summary' => sprintf( 'Delete the coupon %d (%s) created by this operation.', (int) $cc_new_id, $cc_code ),
					'target'  => [ 'coupon_id' => (int) $cc_new_id, 'code' => $cc_code ],
					'pre_op_state' => [
						'created_by_op' => true,
					],
				]);
				return \Royal_MCP\MCP\Support\Envelope::success(
					sprintf( 'Created coupon %d with code "%s", undo available.', (int) $cc_new_id, $cc_code ),
					[
						'id'      => (int) $cc_new_id,
						'code'    => $cc_code,
						'created' => true,
					],
					$cc_undo_envelope
				);

			case 'wc_update_coupon':
				$uc_id = intval( $args['id'] );
				if ( $uc_id <= 0 ) {
					return Envelope::error( 'invalid_args', 'Coupon id must be a positive integer.', [ 'received' => $args['id'] ?? null ] );
				}
				$uc_coupon = new \WC_Coupon( $uc_id );
				if ( ! $uc_coupon->get_id() || get_post_type( $uc_coupon->get_id() ) !== 'shop_coupon' ) {
					return Envelope::error( 'not_found', sprintf( 'Coupon %d not found.', $uc_id ), [ 'id' => $uc_id ] );
				}
				if ( ! current_user_can( 'edit_shop_coupon', $uc_id ) && ! current_user_can( 'manage_woocommerce' ) ) {
					throw new \Exception( 'edit_shop_coupon capability required on this coupon.' );
				}

				// Requested-field extraction — includes 'code' (with the same
				// uniqueness pre-check as the legacy handler). Arrays go
				// through as-is; verifier uses strict equality (see json-diff
				// caveat comment below for product_ids ordering).
				$uc_requested = [];
				if ( isset( $args['code'] ) )                 $uc_requested['code']                    = strtolower( sanitize_text_field( (string) $args['code'] ) );
				if ( isset( $args['discount_type'] ) )        $uc_requested['discount_type']           = in_array( $args['discount_type'], [ 'percent', 'fixed_cart', 'fixed_product' ], true ) ? $args['discount_type'] : null;
				if ( isset( $args['amount'] ) )               $uc_requested['amount']                  = sanitize_text_field( (string) $args['amount'] );
				if ( isset( $args['description'] ) )          $uc_requested['description']             = wp_kses_post( (string) $args['description'] );
				if ( isset( $args['usage_limit'] ) )          $uc_requested['usage_limit']             = (int) $args['usage_limit'];
				if ( isset( $args['usage_limit_per_user'] ) ) $uc_requested['usage_limit_per_user']    = (int) $args['usage_limit_per_user'];
				if ( isset( $args['limit_usage_to_x_items'] ) ) $uc_requested['limit_usage_to_x_items'] = (int) $args['limit_usage_to_x_items'];
				if ( isset( $args['individual_use'] ) )       $uc_requested['individual_use']          = (bool) $args['individual_use'];
				if ( isset( $args['free_shipping'] ) )        $uc_requested['free_shipping']           = (bool) $args['free_shipping'];
				if ( isset( $args['exclude_sale_items'] ) )   $uc_requested['exclude_sale_items']      = (bool) $args['exclude_sale_items'];
				if ( isset( $args['minimum_amount'] ) )       $uc_requested['minimum_amount']          = sanitize_text_field( (string) $args['minimum_amount'] );
				if ( isset( $args['maximum_amount'] ) )       $uc_requested['maximum_amount']          = sanitize_text_field( (string) $args['maximum_amount'] );
				// date_expires normalizes to timestamp int or null in WC 3.x+
				if ( array_key_exists( 'date_expires', $args ) ) {
					$raw = sanitize_text_field( (string) $args['date_expires'] );
					if ( $raw === '' ) {
						$uc_requested['date_expires'] = null;
					} else {
						$ts = strtotime( $raw );
						if ( $ts === false ) {
							return Envelope::error( 'invalid_args', 'Invalid date_expires format. Pass a strtotime-parseable string or "" to clear.', [ 'received' => $args['date_expires'] ] );
						}
						$uc_requested['date_expires'] = (int) $ts;
					}
				}
				// Array fields — snapshot + verifier compare uses json_encode
				// canonicalization to sidestep key-order variance.
				$uc_array_fields = [ 'product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories', 'email_restrictions' ];
				foreach ( $uc_array_fields as $af ) {
					if ( isset( $args[ $af ] ) && is_array( $args[ $af ] ) ) {
						if ( $af === 'email_restrictions' ) {
							$uc_requested[ $af ] = array_values( array_filter( array_map( 'sanitize_email', $args[ $af ] ), 'is_email' ) );
						} else {
							$uc_requested[ $af ] = array_values( array_filter( array_map( 'intval', $args[ $af ] ), fn( $v ) => $v > 0 ) );
						}
					}
				}

				if ( empty( $uc_requested ) ) {
					throw new \Exception( 'No update fields provided.' );
				}

				// Uniqueness pre-check for code (matches legacy handler).
				if ( isset( $uc_requested['code'] ) ) {
					$existing = wc_get_coupon_id_by_code( $uc_requested['code'] );
					if ( $existing && $existing !== $uc_coupon->get_id() ) {
						return Envelope::error( 'conflict', sprintf( 'A coupon with code "%s" already exists (id %d).', $uc_requested['code'], $existing ), [ 'code' => $uc_requested['code'], 'existing_id' => (int) $existing ] );
					}
				}

				// Per-field reader closure
				$uc_read = function( $arg_key ) use ( $uc_id ) {
					$c = new \WC_Coupon( $uc_id );
					switch ( $arg_key ) {
						case 'code':                        return (string) $c->get_code();
						case 'discount_type':               return (string) $c->get_discount_type();
						case 'amount':                      return (string) $c->get_amount();
						case 'description':                 return (string) $c->get_description();
						case 'usage_limit':                 return (int)    $c->get_usage_limit();
						case 'usage_limit_per_user':        return (int)    $c->get_usage_limit_per_user();
						case 'limit_usage_to_x_items':      return (int)    $c->get_limit_usage_to_x_items();
						case 'individual_use':              return (bool)   $c->get_individual_use();
						case 'free_shipping':               return (bool)   $c->get_free_shipping();
						case 'exclude_sale_items':          return (bool)   $c->get_exclude_sale_items();
						case 'minimum_amount':              return (string) $c->get_minimum_amount();
						case 'maximum_amount':              return (string) $c->get_maximum_amount();
						case 'date_expires':                $d = $c->get_date_expires(); return $d ? (int) $d->getTimestamp() : null;
						case 'product_ids':                 return array_values( array_map( 'intval', (array) $c->get_product_ids() ) );
						case 'excluded_product_ids':        return array_values( array_map( 'intval', (array) $c->get_excluded_product_ids() ) );
						case 'product_categories':          return array_values( array_map( 'intval', (array) $c->get_product_categories() ) );
						case 'excluded_product_categories': return array_values( array_map( 'intval', (array) $c->get_excluded_product_categories() ) );
						case 'email_restrictions':          return array_values( (array) $c->get_email_restrictions() );
					}
					return null;
				};

				// Snapshot BEFORE
				$uc_before = [];
				foreach ( array_keys( $uc_requested ) as $f ) {
					$uc_before[ $f ] = $uc_read( $f );
				}

				// Execute — reuse existing set_code + apply_coupon_fields path
				if ( isset( $uc_requested['code'] ) ) {
					$uc_coupon->set_code( $uc_requested['code'] );
				}
				self::apply_coupon_fields( $uc_coupon, $args );
				$uc_coupon->save();
				wp_cache_delete( $uc_id, 'posts' );

				// Re-read AFTER
				$uc_actual = [];
				foreach ( array_keys( $uc_requested ) as $f ) {
					$uc_actual[ $f ] = $uc_read( $f );
				}

				$uc_diff = \Royal_MCP\MCP\Support\WriteVerifier::diff( $uc_requested, $uc_before, $uc_actual );
				\Royal_MCP\MCP\Support\WriteVerifier::throw_if_dropped( $uc_diff, 'wc_update_coupon' );

				$uc_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
					'op'      => 'wc_update_coupon',
					'summary' => sprintf( 'Restore %d field(s) on coupon %d to prior values.', count( $uc_before ), $uc_id ),
					'target'  => [ 'coupon_id' => $uc_id ],
					'pre_op_state' => [
						'prior_values'   => $uc_before,
						'applied_values' => $uc_actual,
					],
				]);

				$uc_struct = array_merge(
					[
						'id'      => $uc_id,
						'updated' => true,
					],
					\Royal_MCP\MCP\Support\WriteVerifier::response_partial( $uc_diff )
				);
				return \Royal_MCP\MCP\Support\Envelope::success(
					sprintf( 'Updated coupon %d (%d field(s) applied%s), undo available.',
						$uc_id,
						count( $uc_diff['applied'] ) + count( $uc_diff['silent_modifies'] ),
						! empty( $uc_diff['silent_modifies'] ) ? ', WP modified value' : ''
					),
					$uc_struct,
					$uc_undo_envelope
				);

			case 'wc_delete_coupon':
				$dc_id = intval( $args['id'] );
				if ( $dc_id <= 0 ) {
					return Envelope::error( 'invalid_args', 'Coupon id must be a positive integer.', [ 'received' => $args['id'] ?? null ] );
				}
				$dc_coupon = new \WC_Coupon( $dc_id );
				if ( ! $dc_coupon->get_id() || get_post_type( $dc_coupon->get_id() ) !== 'shop_coupon' ) {
					return Envelope::error( 'not_found', sprintf( 'Coupon %d not found.', $dc_id ), [ 'id' => $dc_id ] );
				}
				if ( ! current_user_can( 'delete_shop_coupon', $dc_id ) && ! current_user_can( 'manage_woocommerce' ) ) {
					throw new \Exception( 'delete_shop_coupon capability required on this coupon.' );
				}
				$dc_force   = isset( $args['force'] ) ? (bool) $args['force'] : false;
				$dc_status  = get_post_status( $dc_id );

				// Already-in-trash is a no-op (WC's original behavior); return
				// an envelope-shaped no-op success with reason so callers can
				// retry-classify.
				if ( ! $dc_force && 'trash' === $dc_status ) {
					return \Royal_MCP\MCP\Support\Envelope::success(
						sprintf( 'No-op: coupon %d is already in trash.', $dc_id ),
						[ 'id' => $dc_id, 'deleted' => false, 'reason' => 'already_in_trash' ]
					);
				}

				// Snapshot BEFORE the delete. For force=true (hard delete) capture
				// the full field state so undo can rebuild via wc_create_coupon.
				// New coupon ID will differ from original — flagged in summary.
				$dc_full = null;
				if ( $dc_force ) {
					$dc_de = $dc_coupon->get_date_expires();
					$dc_full = [
						'code'                        => $dc_coupon->get_code(),
						'discount_type'               => $dc_coupon->get_discount_type(),
						'amount'                      => $dc_coupon->get_amount(),
						'description'                 => $dc_coupon->get_description(),
						'usage_limit'                 => (int) $dc_coupon->get_usage_limit(),
						'usage_limit_per_user'        => (int) $dc_coupon->get_usage_limit_per_user(),
						'limit_usage_to_x_items'      => (int) $dc_coupon->get_limit_usage_to_x_items(),
						'individual_use'              => (bool) $dc_coupon->get_individual_use(),
						'free_shipping'               => (bool) $dc_coupon->get_free_shipping(),
						'exclude_sale_items'          => (bool) $dc_coupon->get_exclude_sale_items(),
						'minimum_amount'              => (string) $dc_coupon->get_minimum_amount(),
						'maximum_amount'              => (string) $dc_coupon->get_maximum_amount(),
						'date_expires'                => $dc_de ? (int) $dc_de->getTimestamp() : null,
						'product_ids'                 => array_values( array_map( 'intval', (array) $dc_coupon->get_product_ids() ) ),
						'excluded_product_ids'        => array_values( array_map( 'intval', (array) $dc_coupon->get_excluded_product_ids() ) ),
						'product_categories'          => array_values( array_map( 'intval', (array) $dc_coupon->get_product_categories() ) ),
						'excluded_product_categories' => array_values( array_map( 'intval', (array) $dc_coupon->get_excluded_product_categories() ) ),
						'email_restrictions'          => array_values( (array) $dc_coupon->get_email_restrictions() ),
					];
				}

				// Route through WC's data-store abstraction so cache invalidation
				// fires (wc_get_coupon_id_by_code + related transients).
				$dc_coupon->delete( $dc_force );

				$dc_undo_envelope = null;
				if ( $dc_force ) {
					$dc_reverse_json = (string) wp_json_encode( $dc_full );
					if ( strlen( gzcompress( $dc_reverse_json, 9 ) ) > 1024 * 1024 ) {
						// > 1MB snapshot cap; skip undo
					} else {
						$dc_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
							'op'      => 'wc_delete_coupon_force',
							'summary' => sprintf( 'Recreate the force-deleted coupon "%s". Note: the new coupon ID will differ from the original (%d).', $dc_full['code'], $dc_id ),
							'target'  => [ 'original_coupon_id' => $dc_id, 'code' => $dc_full['code'] ],
							'pre_op_state' => [
								'row' => $dc_full,
							],
						]);
					}
				} else {
					// Soft trash — undo via wp_untrash_post
					$dc_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
						'op'      => 'wc_delete_coupon_trash',
						'summary' => sprintf( 'Untrash coupon %d.', $dc_id ),
						'target'  => [ 'coupon_id' => $dc_id ],
					]);
				}

				return \Royal_MCP\MCP\Support\Envelope::success(
					sprintf( '%s coupon %d%s.',
						$dc_force ? 'Force-deleted' : 'Trashed',
						$dc_id,
						$dc_force ? ' (undo will recreate with new ID)' : ' (undo will untrash)'
					),
					[
						'id'             => $dc_id,
						'deleted'        => true,
						'force'          => $dc_force,
						'undo_available' => $dc_undo_envelope !== null,
					],
					$dc_undo_envelope
				);

			case 'wc_empty_coupon_trash':
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					throw new \Exception( 'manage_woocommerce capability required.' );
				}
				$ect_trashed = get_posts( [
					'post_type'      => 'shop_coupon',
					'post_status'    => 'trash',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				] );
				if ( empty( $ect_trashed ) ) {
					return \Royal_MCP\MCP\Support\Envelope::success(
						'Coupon trash is empty — nothing to delete.',
						[ 'deleted' => 0 ]
					);
				}

				// Snapshot every trashed coupon's full field state BEFORE the
				// bulk delete so undo can recreate them. Each recreated coupon
				// gets a new ID; the undo summary flags the mapping loss.
				$ect_rows = [];
				foreach ( $ect_trashed as $cid ) {
					$cid_int = (int) $cid;
					$c = new \WC_Coupon( $cid_int );
					$de = $c->get_date_expires();
					$ect_rows[] = [
						'original_id'                 => $cid_int,
						'code'                        => $c->get_code(),
						'discount_type'               => $c->get_discount_type(),
						'amount'                      => $c->get_amount(),
						'description'                 => $c->get_description(),
						'usage_limit'                 => (int) $c->get_usage_limit(),
						'usage_limit_per_user'        => (int) $c->get_usage_limit_per_user(),
						'limit_usage_to_x_items'      => (int) $c->get_limit_usage_to_x_items(),
						'individual_use'              => (bool) $c->get_individual_use(),
						'free_shipping'               => (bool) $c->get_free_shipping(),
						'exclude_sale_items'          => (bool) $c->get_exclude_sale_items(),
						'minimum_amount'              => (string) $c->get_minimum_amount(),
						'maximum_amount'              => (string) $c->get_maximum_amount(),
						'date_expires'                => $de ? (int) $de->getTimestamp() : null,
						'product_ids'                 => array_values( array_map( 'intval', (array) $c->get_product_ids() ) ),
						'excluded_product_ids'        => array_values( array_map( 'intval', (array) $c->get_excluded_product_ids() ) ),
						'product_categories'          => array_values( array_map( 'intval', (array) $c->get_product_categories() ) ),
						'excluded_product_categories' => array_values( array_map( 'intval', (array) $c->get_excluded_product_categories() ) ),
						'email_restrictions'          => array_values( (array) $c->get_email_restrictions() ),
					];
				}

				// Execute the bulk delete via WC_Coupon::delete so cache invalidation
				// fires per row (wc_get_coupon_id_by_code + related transients).
				$ect_deleted = 0;
				$ect_failed  = [];
				foreach ( $ect_trashed as $cid ) {
					$cid_int = (int) $cid;
					$cc = new \WC_Coupon( $cid_int );
					if ( $cc->get_id() ) {
						$cc->delete( true );
						$ect_deleted++;
					} else {
						$ect_failed[] = $cid_int;
					}
				}

				// Snapshot size cap — bulk deletes can easily exceed 1MB.
				$ect_undo_envelope = null;
				$ect_warnings      = [];
				$ect_reverse_json  = (string) wp_json_encode( $ect_rows );
				if ( strlen( gzcompress( $ect_reverse_json, 9 ) ) > 1024 * 1024 ) {
					$ect_warnings[] = sprintf( 'undo not available — snapshot of %d coupon(s) exceeds 1MB storage cap. SiteVault snapshot recommended for reversal.', count( $ect_rows ) );
				} else {
					$ect_undo_envelope = \Royal_MCP\MCP\Undo_Store::store([
						'op'      => 'wc_empty_coupon_trash',
						'summary' => sprintf( 'Recreate %d bulk-deleted coupon(s). Note: new coupon IDs will differ from the originals; existing references to old IDs will still be broken.', count( $ect_rows ) ),
						'target'  => [ 'deleted_count' => count( $ect_rows ) ],
						'pre_op_state' => [
							'rows' => $ect_rows,
						],
					]);
				}

				$ect_struct = [
					'deleted'         => (int) $ect_deleted,
					'failed_count'    => count( $ect_failed ),
					'undo_available'  => $ect_undo_envelope !== null,
				];
				if ( ! empty( $ect_failed ) ) {
					$ect_struct['failed_ids'] = $ect_failed;
				}
				if ( ! empty( $ect_warnings ) ) {
					$ect_struct['warnings'] = $ect_warnings;
				}
				return \Royal_MCP\MCP\Support\Envelope::success(
					sprintf( 'Permanently deleted %d coupon(s) from trash%s.',
						$ect_deleted,
						$ect_undo_envelope !== null ? ', undo available (recreates with new IDs)' : ' (no undo: snapshot too large)'
					),
					$ect_struct,
					$ect_undo_envelope
				);

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
			'id'                 => $product->get_id(),
			'name'               => $product->get_name(),
			'type'               => $product->get_type(),
			'status'             => $product->get_status(),
			'catalog_visibility' => $product->get_catalog_visibility(),
			'description'        => $product->get_description(),
			'short_description'  => $product->get_short_description(),
			'price'              => $product->get_price(),
			'regular_price'      => $product->get_regular_price(),
			'sale_price'         => $product->get_sale_price(),
			'sku'                => $product->get_sku(),
			'stock_status'       => $product->get_stock_status(),
			'stock_quantity'     => $product->get_stock_quantity(),
			'weight'             => $product->get_weight(),
			'categories'         => wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] ),
			'tags'               => wp_get_post_terms( $product->get_id(), 'product_tag', [ 'fields' => 'names' ] ),
			'url'                => get_permalink( $product->get_id() ),
			'date_created'       => $product->get_date_created() ? $product->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
			'date_created_iso'   => $product->get_date_created() ? gmdate( 'c', $product->get_date_created()->getTimestamp() ) : null,
		];
	}

	/**
	 * Sanitize an incoming address payload (billing or shipping) to the WC-expected shape.
	 * Only the address keys WC recognises are kept — arbitrary caller input is dropped.
	 */
	/**
	 * Serialize a WC product's attributes ([slug => WC_Product_Attribute])
	 * into a plain-array snapshot so the storage round-trip through the
	 * undo envelope preserves position/visible/variation/options exactly.
	 * WC_Product_Attribute objects don't survive standard PHP serialize
	 * through wp_options; extracting to plain arrays sidesteps that.
	 */
	private static function serialize_product_attributes( array $attrs ): array {
		$out = [];
		foreach ( $attrs as $slug => $attr ) {
			if ( ! ( $attr instanceof \WC_Product_Attribute ) ) continue;
			$out[] = [
				'id'        => $attr->get_id(),
				'name'      => $attr->get_name(),
				'position'  => $attr->get_position(),
				'visible'   => $attr->get_visible(),
				'variation' => $attr->get_variation(),
				'options'   => array_values( (array) $attr->get_options() ),
			];
		}
		return $out;
	}

	/**
	 * Public wrapper for the undo handler in MCP\Server so the deserialize
	 * helper can be reused without exposing the internal WC_Product_Attribute
	 * construction across namespaces.
	 */
	public static function deserialize_product_attributes_public( array $snapshot ): array {
		return self::deserialize_product_attributes( $snapshot );
	}

	/**
	 * Reverse of serialize_product_attributes — rebuild WC_Product_Attribute
	 * objects from a plain-array snapshot for restore on undo.
	 */
	private static function deserialize_product_attributes( array $snapshot ): array {
		$out = [];
		foreach ( $snapshot as $row ) {
			if ( ! is_array( $row ) ) continue;
			$attr = new \WC_Product_Attribute();
			$attr->set_id( (int) ( $row['id'] ?? 0 ) );
			$attr->set_name( (string) ( $row['name'] ?? '' ) );
			$attr->set_position( (int) ( $row['position'] ?? 0 ) );
			$attr->set_visible( (bool) ( $row['visible'] ?? true ) );
			$attr->set_variation( (bool) ( $row['variation'] ?? false ) );
			$options = isset( $row['options'] ) && is_array( $row['options'] ) ? $row['options'] : [];
			// Global attributes store term IDs (ints); custom store strings.
			// Preserve type by inspecting first element.
			if ( $attr->get_id() > 0 ) {
				$attr->set_options( array_map( 'intval', $options ) );
			} else {
				$attr->set_options( array_map( 'strval', $options ) );
			}
			$out[] = $attr;
		}
		return $out;
	}

	private static function sanitize_address_fields( array $address ): array {
		$allowed = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ];
		$out = [];
		foreach ( $allowed as $key ) {
			if ( isset( $address[ $key ] ) ) {
				$val = (string) $address[ $key ];
				$out[ $key ] = ( $key === 'email' ) ? sanitize_email( $val ) : sanitize_text_field( $val );
			}
		}
		return $out;
	}

	/**
	 * Return the order's current billing or shipping address as an associative array —
	 * used by wc_update_order to merge partial address updates with existing values.
	 */
	private static function current_address( \WC_Order $order, string $type ): array {
		$getter_prefix = 'get_' . $type . '_';
		return [
			'first_name' => $order->{$getter_prefix . 'first_name'}(),
			'last_name'  => $order->{$getter_prefix . 'last_name'}(),
			'company'    => $order->{$getter_prefix . 'company'}(),
			'address_1'  => $order->{$getter_prefix . 'address_1'}(),
			'address_2'  => $order->{$getter_prefix . 'address_2'}(),
			'city'       => $order->{$getter_prefix . 'city'}(),
			'state'      => $order->{$getter_prefix . 'state'}(),
			'postcode'   => $order->{$getter_prefix . 'postcode'}(),
			'country'    => $order->{$getter_prefix . 'country'}(),
			'email'      => ( $type === 'billing' ) ? $order->get_billing_email() : '',
			'phone'      => ( $type === 'billing' ) ? $order->get_billing_phone() : '',
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

	/**
	 * Build a one-line human summary for the wc_get_order envelope.
	 * Consumes the flat array from format_order_detail() so both stay in sync.
	 */
	private static function summarize_order( array $d ) : string {
		$item_count  = is_array( $d['items'] ?? null ) ? count( $d['items'] ) : 0;
		$fee_count   = is_array( $d['fee_lines'] ?? null ) ? count( $d['fee_lines'] ) : 0;
		$ship_count  = is_array( $d['shipping_lines'] ?? null ) ? count( $d['shipping_lines'] ) : 0;
		$parts       = [ sprintf( '%d item%s', $item_count, $item_count === 1 ? '' : 's' ) ];
		if ( $fee_count > 0 ) {
			$parts[] = sprintf( '%d fee%s', $fee_count, $fee_count === 1 ? '' : 's' );
		}
		if ( $ship_count > 0 ) {
			$parts[] = sprintf( '%d shipping', $ship_count );
		}
		$pay = $d['payment_method_title'] !== '' ? $d['payment_method_title'] : ( $d['payment_method'] ?: 'no payment method' );
		return sprintf(
			'Order #%d: %s, %s %s (%s), payment: %s%s',
			(int) $d['id'],
			(string) $d['status'],
			(string) $d['total'],
			(string) $d['currency'],
			implode( ' + ', $parts ),
			$pay,
			! empty( $d['date_paid'] ) ? sprintf( ', paid %s', $d['date_paid'] ) : ''
		);
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
		// Fees and shipping are separate WC item types — get_items() defaults
		// to 'line_item' (products only) and never enumerates them. Without
		// this a fee's existence is only provable via total − subtotal delta,
		// and shipping method is invisible to LLM clients doing verification.
		$fee_lines = [];
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$fee_lines[] = [
				'name'  => $fee->get_name(),
				'total' => $fee->get_total(),
			];
		}
		$shipping_lines = [];
		foreach ( $order->get_items( 'shipping' ) as $ship ) {
			$shipping_lines[] = [
				'method_title' => $ship->get_method_title(),
				'method_id'    => $ship->get_method_id(),
				'total'        => $ship->get_total(),
			];
		}
		return [
			'id'                   => $order->get_id(),
			'status'               => $order->get_status(),
			'total'                => $order->get_total(),
			'subtotal'             => $order->get_subtotal(),
			'tax'                  => $order->get_total_tax(),
			'shipping'             => $order->get_shipping_total(),
			'currency'             => $order->get_currency(),
			// payment_method now holds the raw gateway ID (bacs, cheque, stripe,
			// ...) — matches WC's own REST API shape and lets clients verify a
			// write regardless of whether that gateway is enabled on the site.
			// get_payment_method_title() returns empty when the gateway is
			// not registered, which made read-after-write unreliable on
			// demo/staging installs where BACS/COD aren't enabled.
			'payment_method'       => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'customer_name'        => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'billing_city'         => $order->get_billing_city(),
			'billing_country'      => $order->get_billing_country(),
			'items'                => $items,
			'fee_lines'            => $fee_lines,
			'shipping_lines'       => $shipping_lines,
			'date_created'         => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
			'date_paid'            => $order->get_date_paid() ? $order->get_date_paid()->format( 'Y-m-d H:i:s' ) : null,
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
			'_subscription_length'        => (int) get_post_meta( $coupon->get_id(), '_subscription_length', true ),
			'date_expires'                => $coupon->get_date_expires() ? $coupon->get_date_expires()->format( 'Y-m-d H:i:s' ) : null,
			'date_expires_iso'            => $coupon->get_date_expires() ? gmdate( 'c', $coupon->get_date_expires()->getTimestamp() ) : null,
			'date_created'                => $coupon->get_date_created() ? $coupon->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
			'date_created_iso'            => $coupon->get_date_created() ? gmdate( 'c', $coupon->get_date_created()->getTimestamp() ) : null,
			'date_modified'               => $coupon->get_date_modified() ? $coupon->get_date_modified()->format( 'Y-m-d H:i:s' ) : null,
			'date_modified_iso'           => $coupon->get_date_modified() ? gmdate( 'c', $coupon->get_date_modified()->getTimestamp() ) : null,
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
			// WC admin allows HTML in coupon descriptions; some themes render
			// them on cart/checkout with formatting preserved. Matches admin behavior.
			$coupon->set_description( wp_kses_post( $args['description'] ) );
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
