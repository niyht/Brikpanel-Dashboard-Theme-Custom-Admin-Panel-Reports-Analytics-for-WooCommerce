<?php
/**
 * BrikPanel — Sheets column mapping registry.
 *
 * Holds the catalogue of columns the user can choose to export for each flow
 * (orders, customers), plus the default selection + which columns are
 * mandatory (cannot be unchecked because they form the primary key used for
 * de-duplication / row-update).
 *
 * Storage: one option per flow, holding a flat array of column keys in the
 * user's chosen order. Settings UI persists via these options; sync classes
 * read them at row-build time.
 *
 * @package BrikPanel
 * @since   2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Sheets_Mapping {

	const OPT_ORDERS    = 'brikpanel_gs_columns_orders';
	const OPT_CUSTOMERS = 'brikpanel_gs_columns_customers';
	const OPT_PRODUCTS  = 'brikpanel_gs_columns_products';
	const OPT_EXPENSES  = 'brikpanel_gs_columns_expenses';

	// Trailing glyph appended to writable column headers in the sheet so a
	// merchant can tell two-way (Sheets -> Woo) columns from display-only ones.
	// Cosmetic: matching/writeback always key off column keys, not header text.
	const WRITABLE_GLYPH = '⇅';

	// Discovered custom order/checkout fields the merchant can opt into. Stored
	// as a list of { syn, label, keys[] } so the catalogue can offer them as
	// selectable columns and the row builder can resolve their value from order
	// meta. See refresh_order_custom_fields() for how it is populated.
	const OPT_ORDER_CUSTOM_FIELDS = 'brikpanel_gs_orders_custom_fields';

	/**
	 * Catalogue of available columns + their human label, keyed by flow.
	 *
	 * @return array<string, array<string, array{label:string, mandatory?:bool, default?:bool, group?:string}>>
	 */
	public static function catalogue() {
		$cat = [
			'orders' => [
				// Order-level
				'order_id'             => [ 'label' => __( 'Order ID',             'brikpanel' ), 'mandatory' => true,  'default' => true,  'group' => 'order' ],
				'order_number'         => [ 'label' => __( 'Order number',         'brikpanel' ), 'default' => true,  'group' => 'order' ],
				'order_date'           => [ 'label' => __( 'Order date',           'brikpanel' ), 'default' => true,  'group' => 'order' ],
				// Two-way: an edit to this cell is read back by the orders pull
				// pass (see class-brikpanel-sheets-order-sync.php::pull_locked)
				// and applied via $order->update_status(). Every other orders
				// column is display-only.
				'order_status'         => [ 'label' => __( 'Order status',         'brikpanel' ), 'default' => true,  'group' => 'order', 'writable' => true ],
				'currency'             => [ 'label' => __( 'Currency',             'brikpanel' ), 'group' => 'order' ],
				'subtotal'             => [ 'label' => __( 'Subtotal',             'brikpanel' ), 'group' => 'order' ],
				'tax_total'            => [ 'label' => __( 'Tax total',            'brikpanel' ), 'group' => 'order' ],
				'shipping_total'       => [ 'label' => __( 'Shipping total',       'brikpanel' ), 'group' => 'order' ],
				'discount_total'       => [ 'label' => __( 'Discount total',       'brikpanel' ), 'group' => 'order' ],
				'total'                => [ 'label' => __( 'Order total',          'brikpanel' ), 'default' => true,  'group' => 'order' ],
				'payment_method'       => [ 'label' => __( 'Payment method (key)', 'brikpanel' ), 'group' => 'order' ],
				'payment_method_title' => [ 'label' => __( 'Payment method',       'brikpanel' ), 'default' => true,  'group' => 'order' ],
				'transaction_id'       => [ 'label' => __( 'Transaction ID',       'brikpanel' ), 'group' => 'order' ],
				'coupon_codes'         => [ 'label' => __( 'Coupon codes',         'brikpanel' ), 'group' => 'order' ],
				'customer_note'        => [ 'label' => __( 'Customer note',        'brikpanel' ), 'group' => 'order' ],
				'customer_id'          => [ 'label' => __( 'Customer user ID',     'brikpanel' ), 'group' => 'order' ],
				'shipping_method'      => [ 'label' => __( 'Shipping method',      'brikpanel' ), 'group' => 'order' ],
				'order_cogs_total'     => [ 'label' => __( 'Order COGS total',     'brikpanel' ), 'group' => 'order' ],

				// Billing
				'billing_first_name'   => [ 'label' => __( 'Billing first name',   'brikpanel' ), 'default' => true,  'group' => 'billing' ],
				'billing_last_name'    => [ 'label' => __( 'Billing last name',    'brikpanel' ), 'default' => true,  'group' => 'billing' ],
				'billing_email'        => [ 'label' => __( 'Billing email',        'brikpanel' ), 'default' => true,  'group' => 'billing' ],
				'billing_phone'        => [ 'label' => __( 'Billing phone',        'brikpanel' ), 'group' => 'billing' ],
				'billing_address_1'    => [ 'label' => __( 'Billing address 1',    'brikpanel' ), 'group' => 'billing' ],
				'billing_address_2'    => [ 'label' => __( 'Billing address 2',    'brikpanel' ), 'group' => 'billing' ],
				'billing_city'         => [ 'label' => __( 'Billing city',         'brikpanel' ), 'group' => 'billing' ],
				'billing_state'        => [ 'label' => __( 'Billing state',        'brikpanel' ), 'group' => 'billing' ],
				'billing_postcode'     => [ 'label' => __( 'Billing postcode',     'brikpanel' ), 'group' => 'billing' ],
				'billing_country'      => [ 'label' => __( 'Billing country',      'brikpanel' ), 'group' => 'billing' ],

				// Shipping
				'shipping_first_name'  => [ 'label' => __( 'Shipping first name',  'brikpanel' ), 'group' => 'shipping' ],
				'shipping_last_name'   => [ 'label' => __( 'Shipping last name',   'brikpanel' ), 'group' => 'shipping' ],
				'shipping_address_1'   => [ 'label' => __( 'Shipping address 1',   'brikpanel' ), 'group' => 'shipping' ],
				'shipping_address_2'   => [ 'label' => __( 'Shipping address 2',   'brikpanel' ), 'group' => 'shipping' ],
				'shipping_city'        => [ 'label' => __( 'Shipping city',        'brikpanel' ), 'group' => 'shipping' ],
				'shipping_state'       => [ 'label' => __( 'Shipping state',       'brikpanel' ), 'group' => 'shipping' ],
				'shipping_postcode'    => [ 'label' => __( 'Shipping postcode',    'brikpanel' ), 'group' => 'shipping' ],
				'shipping_country'     => [ 'label' => __( 'Shipping country',     'brikpanel' ), 'group' => 'shipping' ],

				// Line item
				'items_summary'        => [ 'label' => __( 'Items',                'brikpanel' ), 'group' => 'item' ],
				'line_item_id'         => [ 'label' => __( 'Line item ID',         'brikpanel' ), 'mandatory' => true, 'default' => true, 'group' => 'item' ],
				'product_id'           => [ 'label' => __( 'Product ID',           'brikpanel' ), 'default' => true, 'group' => 'item' ],
				'variation_id'         => [ 'label' => __( 'Variation ID',         'brikpanel' ), 'default' => true, 'group' => 'item' ],
				'product_sku'          => [ 'label' => __( 'SKU',                  'brikpanel' ), 'default' => true, 'group' => 'item' ],
				'product_name'         => [ 'label' => __( 'Product name',         'brikpanel' ), 'default' => true, 'group' => 'item' ],
				'variation_attributes' => [ 'label' => __( 'Variation attributes', 'brikpanel' ), 'default' => true, 'group' => 'item' ],
				'quantity'             => [ 'label' => __( 'Quantity',             'brikpanel' ), 'default' => true, 'group' => 'item' ],
				'unit_price'           => [ 'label' => __( 'Unit price',           'brikpanel' ), 'default' => true, 'group' => 'item' ],
				'line_subtotal'        => [ 'label' => __( 'Line subtotal',        'brikpanel' ), 'group' => 'item' ],
				'line_tax'             => [ 'label' => __( 'Line tax',             'brikpanel' ), 'group' => 'item' ],
				'line_total'           => [ 'label' => __( 'Line total',           'brikpanel' ), 'default' => true, 'group' => 'item' ],
				'line_cogs'            => [ 'label' => __( 'Line COGS (cost × qty)', 'brikpanel' ), 'group' => 'item' ],
			],

			'products' => [
				// Identification (mandatory: product ID is the join key for the
				// reverse-direction stock writeback).
				'product_id'    => [ 'label' => __( 'Product ID',     'brikpanel' ), 'mandatory' => true, 'default' => true ],
				'parent_id'     => [ 'label' => __( 'Parent ID',      'brikpanel' ), 'default' => true ],
				'type'          => [ 'label' => __( 'Type',           'brikpanel' ), 'default' => true ],
				'sku'           => [ 'label' => __( 'SKU',            'brikpanel' ), 'default' => true ],
				'name'          => [ 'label' => __( 'Name',           'brikpanel' ), 'default' => true ],
				'variation_attributes' => [ 'label' => __( 'Variation attributes', 'brikpanel' ), 'default' => true ],
				'short_description' => [ 'label' => __( 'Short description', 'brikpanel' ) ],
				'description'       => [ 'label' => __( 'Description',       'brikpanel' ) ],
				// 'price' is the derived active price (read-only); merchants edit
				// regular/sale instead, which are writable back to Woo.
				'price'         => [ 'label' => __( 'Price',          'brikpanel' ), 'default' => true ],
				'regular_price' => [ 'label' => __( 'Regular price',  'brikpanel' ), 'default' => true, 'writable' => true ],
				'sale_price'    => [ 'label' => __( 'Sale price',     'brikpanel' ), 'default' => true, 'writable' => true ],
				'cogs'          => [ 'label' => __( 'Cost (COGS)',    'brikpanel' ), 'writable' => true ],
				// Writable columns flow Sheets -> Woo on the reverse pull. They are
				// marked with a trailing two-way glyph in headers_for() and badged
				// in the settings column picker so the merchant sees at a glance
				// which edits reach the store. Everything else is display-only.
				'stock'         => [ 'label' => __( 'Stock',          'brikpanel' ), 'default' => true, 'writable' => true ],
				'stock_status'  => [ 'label' => __( 'Stock status',   'brikpanel' ), 'default' => true, 'writable' => true ],
				'status'        => [ 'label' => __( 'Status',         'brikpanel' ), 'default' => true, 'writable' => true ],
				'permalink'     => [ 'label' => __( 'Permalink',      'brikpanel' ) ],
			],

			'customers' => [
				'customer_key'   => [ 'label' => __( 'Customer key',          'brikpanel' ), 'mandatory' => true, 'default' => true ],
				'user_id'        => [ 'label' => __( 'User ID',               'brikpanel' ), 'default' => true ],
				'name'           => [ 'label' => __( 'Name',                  'brikpanel' ), 'default' => true ],
				'email'          => [ 'label' => __( 'Email',                 'brikpanel' ), 'mandatory' => true, 'default' => true ],
				'first_order'    => [ 'label' => __( 'First order date',      'brikpanel' ), 'default' => true ],
				'last_order'     => [ 'label' => __( 'Last order date',       'brikpanel' ), 'default' => true ],
				'order_count'    => [ 'label' => __( 'Order count',           'brikpanel' ), 'default' => true ],
				'total_spent'    => [ 'label' => __( 'Total spent',           'brikpanel' ), 'default' => true ],
				'aov'            => [ 'label' => __( 'Average order value',   'brikpanel' ), 'default' => true ],
				'recency_days'   => [ 'label' => __( 'Recency (days)',        'brikpanel' ), 'default' => true ],
				'r_score'        => [ 'label' => __( 'Recency score (R)',     'brikpanel' ), 'default' => true ],
				'f_score'        => [ 'label' => __( 'Frequency score (F)',   'brikpanel' ), 'default' => true ],
				'm_score'        => [ 'label' => __( 'Monetary score (M)',    'brikpanel' ), 'default' => true ],
				'rfm_segment'    => [ 'label' => __( 'RFM segment',           'brikpanel' ), 'default' => true ],
			],

			// Operational expenses. Almost every column is two-way here, unlike
			// the other flows: the whole point of the tab is to be the place a
			// merchant types costs in bulk instead of one modal at a time. The
			// four mandatory keys are what an expense minimally IS (identity,
			// when, what, how much), so the ingest can always locate them.
			'expenses' => [
				'expense_id'  => [ 'label' => __( 'Expense ID',  'brikpanel' ), 'mandatory' => true, 'default' => true ],
				'date'        => [ 'label' => __( 'Date',        'brikpanel' ), 'mandatory' => true, 'default' => true, 'writable' => true ],
				// Sheet column 'category' names the expense this cost is filed
				// under; it maps to the `parent_category` DB column, because the
				// legacy `category` column is what 'title' already carries. The
				// KEY stays 'category' whatever the label says — it is persisted
				// in every merchant's saved column mapping, so renaming it would
				// silently unmap their sheet.
				'category'    => [ 'label' => _x( 'Part of', 'the expense this cost is filed under', 'brikpanel' ), 'default' => true, 'writable' => true ],
				'title'       => [ 'label' => __( 'Title',       'brikpanel' ), 'mandatory' => true, 'default' => true, 'writable' => true ],
				'amount'      => [ 'label' => _x( 'Amount', 'money value of an expense', 'brikpanel' ), 'mandatory' => true, 'default' => true, 'writable' => true ],
				'type'        => [ 'label' => __( 'Type',        'brikpanel' ), 'default' => true, 'writable' => true ],
				'repeats'     => [ 'label' => __( 'Repeats',     'brikpanel' ), 'default' => true, 'writable' => true ],
				'description' => [ 'label' => __( 'Description', 'brikpanel' ), 'default' => true, 'writable' => true ],
				'created_at'  => [ 'label' => __( 'Added on',    'brikpanel' ) ],
			],
		];

		// Append any custom checkout / order fields the merchant has, discovered
		// via refresh_order_custom_fields(). Never default-selected: they live
		// under "Show more columns" until the merchant opts in.
		foreach ( self::order_custom_field_catalogue() as $syn => $meta ) {
			$cat['orders'][ $syn ] = $meta;
		}

		return $cat;
	}

	/**
	 * Catalogue entries for a single flow.
	 *
	 * @param string $flow orders|customers
	 * @return array
	 */
	public static function available_columns_for( $flow ) {
		$cat = self::catalogue();
		return isset( $cat[ $flow ] ) ? $cat[ $flow ] : [];
	}

	/**
	 * The default selection of column keys for a flow, in declared order.
	 *
	 * @param string $flow
	 * @return string[]
	 */
	public static function defaults_for( $flow ) {
		$out = [];
		foreach ( self::available_columns_for( $flow ) as $key => $meta ) {
			if ( ! empty( $meta['default'] ) || ! empty( $meta['mandatory'] ) ) {
				$out[] = $key;
			}
		}
		if ( 'orders' === $flow ) {
			$out = self::adapt_order_columns_to_layout( $out );
		}
		return $out;
	}

	/**
	 * Translate an orders column selection between the two row layouts.
	 *
	 * In the one-row-per-order layout the per-line-item identity columns make
	 * no sense (every cell would read 0 or repeat), so they are swapped for
	 * their order-level equivalents; in the one-row-per-line-item layout the
	 * aggregate "Items" cell is redundant with the per-item columns, so the
	 * reverse swap restores the item detail set. Keys keep their position so
	 * the merchant's column ordering survives the switch.
	 *
	 * @param string[] $columns Current selection.
	 * @param string   $layout  Target layout; defaults to the active one.
	 * @return string[]
	 */
	public static function adapt_order_columns_to_layout( array $columns, $layout = '' ) {
		if ( $layout === '' && class_exists( 'Brikpanel_Sheets_Order_Sync' ) ) {
			$layout = Brikpanel_Sheets_Order_Sync::row_layout();
		}
		if ( 'order' === $layout ) {
			$swap = [
				'line_item_id'         => 'items_summary',
				'product_name'         => 'items_summary',
				'product_id'           => '',
				'variation_id'         => '',
				'product_sku'          => '',
				'variation_attributes' => '',
				'unit_price'           => '',
				'line_subtotal'        => 'subtotal',
				'line_tax'             => 'tax_total',
				'line_total'           => 'total',
				'line_cogs'            => 'order_cogs_total',
			];
		} else {
			$swap = [
				'items_summary'    => 'line_item_id',
				'order_cogs_total' => 'line_cogs',
			];
		}
		$out = [];
		foreach ( $columns as $key ) {
			$mapped = array_key_exists( $key, $swap ) ? $swap[ $key ] : $key;
			if ( $mapped !== '' && ! in_array( $mapped, $out, true ) ) {
				$out[] = $mapped;
			}
		}
		return $out;
	}

	/**
	 * The keys that the user is not allowed to unselect.
	 *
	 * @param string $flow
	 * @return string[]
	 */
	public static function mandatory_for( $flow ) {
		$out = [];
		foreach ( self::available_columns_for( $flow ) as $key => $meta ) {
			if ( ! empty( $meta['mandatory'] ) ) {
				$out[] = $key;
			}
		}
		// line_item_id is the dedup key only when rows ARE line items. In the
		// one-row-per-order layout each order occupies exactly one row, so
		// order_id alone identifies it and a forced line_item_id column would
		// just print a 0 in every row.
		if ( 'orders' === $flow
			&& class_exists( 'Brikpanel_Sheets_Order_Sync' )
			&& 'order' === Brikpanel_Sheets_Order_Sync::row_layout() ) {
			$out = array_values( array_diff( $out, [ 'line_item_id' ] ) );
		}
		return $out;
	}

	/**
	 * The currently saved (or default) column list for a flow.
	 *
	 * @param string $flow
	 * @return string[]
	 */
	public static function get_columns( $flow ) {
		$opt = self::option_key_for( $flow );
		if ( $opt === '' ) {
			return [];
		}
		$saved = get_option( $opt, null );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			return self::defaults_for( $flow );
		}
		$valid = array_keys( self::available_columns_for( $flow ) );
		$saved = array_values( array_intersect( $saved, $valid ) );

		// Re-prepend any mandatory key that the user managed to drop. Build
		// the prefix in catalogue order first so that when multiple mandatory
		// keys are missing they end up in the natural [order_id, line_item_id]
		// order rather than the reverse that array_unshift-in-a-loop produces.
		$missing = [];
		foreach ( self::mandatory_for( $flow ) as $mk ) {
			if ( ! in_array( $mk, $saved, true ) ) {
				$missing[] = $mk;
			}
		}
		if ( ! empty( $missing ) ) {
			$saved = array_merge( $missing, $saved );
		}
		return $saved;
	}

	/**
	 * Persist a column selection.
	 *
	 * @param string   $flow
	 * @param string[] $columns
	 * @return bool
	 */
	public static function set_columns( $flow, array $columns ) {
		$opt = self::option_key_for( $flow );
		if ( $opt === '' ) {
			return false;
		}
		$valid = array_keys( self::available_columns_for( $flow ) );
		$clean = [];
		foreach ( $columns as $k ) {
			$k = is_string( $k ) ? $k : '';
			if ( $k !== '' && in_array( $k, $valid, true ) && ! in_array( $k, $clean, true ) ) {
				$clean[] = $k;
			}
		}
		// Re-prepend any mandatory keys in catalogue order (see get_columns()).
		$missing = [];
		foreach ( self::mandatory_for( $flow ) as $mk ) {
			if ( ! in_array( $mk, $clean, true ) ) {
				$missing[] = $mk;
			}
		}
		if ( ! empty( $missing ) ) {
			$clean = array_merge( $missing, $clean );
		}
		update_option( $opt, $clean, false );
		return true;
	}

	/**
	 * Header row labels for the given column keys, in the same order.
	 *
	 * @param string   $flow
	 * @param string[] $columns
	 * @return string[]
	 */
	public static function headers_for( $flow, array $columns ) {
		$cat = self::available_columns_for( $flow );
		$out = [];
		foreach ( $columns as $key ) {
			$label = isset( $cat[ $key ]['label'] ) ? (string) $cat[ $key ]['label'] : (string) $key;
			// Writable columns sync Sheets -> Woo; flag them in the header so the
			// merchant can tell editable columns from display-only ones right in
			// the sheet. The glyph is cosmetic only: row matching and writeback
			// key off the saved column keys, never the header text.
			if ( ! empty( $cat[ $key ]['writable'] ) ) {
				$label .= ' ' . self::WRITABLE_GLYPH;
			}
			$out[] = $label;
		}
		return $out;
	}

	private static function option_key_for( $flow ) {
		switch ( $flow ) {
			case 'orders':    return self::OPT_ORDERS;
			case 'customers': return self::OPT_CUSTOMERS;
			case 'products':  return self::OPT_PRODUCTS;
			case 'expenses':  return self::OPT_EXPENSES;
		}
		return '';
	}

	/**
	 * Indexed list of column-key → 0-based column position for the given
	 * column selection. Used by the reverse-direction pull to find a specific
	 * cell (e.g. "stock") inside an arbitrary user-customised column order.
	 *
	 * @param string[] $columns
	 * @return array<string, int>
	 */
	public static function column_index_map( array $columns ) {
		$out = [];
		foreach ( $columns as $i => $key ) {
			$out[ (string) $key ] = (int) $i;
		}
		return $out;
	}

	/**
	 * Whether a given column key is editable from Sheets (i.e. an edit to it
	 * is read back during the pull pass). Used by the row hash / writable
	 * filter; all other columns are display-only.
	 *
	 * @param string $flow
	 * @param string $column_key
	 * @return bool
	 */
	public static function is_writable( $flow, $column_key ) {
		$cat = self::available_columns_for( $flow );
		return ! empty( $cat[ $column_key ]['writable'] );
	}

	// =========================================================================
	// Custom order / checkout field discovery
	// =========================================================================

	/**
	 * The stored list of discovered custom order fields.
	 *
	 * @return array<int, array{syn:string, label:string, keys:string[]}>
	 */
	private static function order_custom_fields_raw() {
		$stored = get_option( self::OPT_ORDER_CUSTOM_FIELDS, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}
		$out = [];
		foreach ( $stored as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['syn'] ) || empty( $entry['keys'] ) ) {
				continue;
			}
			$keys = array_values( array_filter( array_map( 'strval', (array) $entry['keys'] ) ) );
			if ( empty( $keys ) ) {
				continue;
			}
			$out[] = [
				'syn'   => (string) $entry['syn'],
				'label' => isset( $entry['label'] ) ? (string) $entry['label'] : $keys[0],
				'keys'  => $keys,
			];
		}
		return $out;
	}

	/**
	 * Catalogue entries (keyed by synthetic column key) for the discovered
	 * custom order fields. Merged into the orders flow by catalogue().
	 *
	 * @return array<string, array{label:string, group:string}>
	 */
	public static function order_custom_field_catalogue() {
		$out = [];
		foreach ( self::order_custom_fields_raw() as $entry ) {
			$out[ $entry['syn'] ] = [
				'label' => $entry['label'],
				'group' => 'custom',
			];
		}
		return $out;
	}

	/**
	 * The order-meta key candidates for a synthetic custom-field column key.
	 * The row builder tries each in turn and uses the first non-empty value,
	 * which absorbs the two storage conventions checkout plugins use (the bare
	 * field key vs an underscore-prefixed copy).
	 *
	 * @param string $syn
	 * @return string[]
	 */
	public static function order_custom_field_keys( $syn ) {
		foreach ( self::order_custom_fields_raw() as $entry ) {
			if ( $entry['syn'] === $syn ) {
				return $entry['keys'];
			}
		}
		return [];
	}

	/**
	 * Stable, sanitize_key-safe synthetic column key for a custom field. Keyed
	 * off the primary meta key so the same field always resolves to the same
	 * column (a merchant's saved selection survives a re-scan).
	 *
	 * @param string $primary_key
	 * @return string
	 */
	private static function custom_field_syn( $primary_key ) {
		return 'cf_' . substr( md5( (string) $primary_key ), 0, 12 );
	}

	/**
	 * Discover custom checkout / order fields and persist them so they appear in
	 * the column picker. Two sources, merged and de-duplicated by meta key:
	 *
	 *   1. WooCommerce's checkout field registry (classic checkout + the block
	 *      "Additional fields" API) minus the core fields BrikPanel already maps
	 *      as first-class columns. This is the authoritative source for "fields
	 *      added in WooCommerce settings".
	 *   2. A scan of the order-meta keys actually present on this store, with the
	 *      known WooCommerce/WordPress/BrikPanel internals filtered out. This
	 *      catches values written by plugins that register their field only on
	 *      the front-end checkout (so step 1 would miss them in admin).
	 *
	 * Previously-discovered fields are kept even if a later scan no longer sees
	 * them, so a column the merchant selected never silently disappears.
	 *
	 * Safe to call on every settings-page render; it is a couple of cheap
	 * queries and a single option write only when the set actually changed.
	 *
	 * @return void
	 */
	public static function refresh_order_custom_fields() {
		$by_primary = [];

		// Seed with previously-discovered fields the merchant has actually
		// SELECTED, so their column choice never disappears on a re-scan. Fields
		// that were discovered before but never selected are intentionally not
		// carried forward: the fresh scan below re-adds them only if they still
		// qualify, which is what prunes entries a tightened denylist now rejects.
		$selected = (array) get_option( self::OPT_ORDERS, [] );
		foreach ( self::order_custom_fields_raw() as $entry ) {
			if ( in_array( $entry['syn'], $selected, true ) ) {
				$by_primary[ $entry['keys'][0] ] = $entry;
			}
		}

		// --- Source 1: WooCommerce checkout field registry ---
		$core_field_keys = self::core_checkout_field_keys();
		if ( function_exists( 'WC' ) && WC() && method_exists( WC(), 'checkout' ) ) {
			try {
				$groups = WC()->checkout()->get_checkout_fields();
				foreach ( (array) $groups as $group_fields ) {
					foreach ( (array) $group_fields as $field_key => $def ) {
						$field_key = (string) $field_key;
						if ( $field_key === '' || in_array( $field_key, $core_field_keys, true ) ) {
							continue;
						}
						$label = is_array( $def ) && ! empty( $def['label'] ) ? (string) $def['label'] : self::humanize_meta_key( $field_key );
						// Field value may be stored bare or underscore-prefixed.
						$keys  = array_values( array_unique( [ $field_key, '_' . ltrim( $field_key, '_' ) ] ) );
						self::merge_custom_field( $by_primary, $field_key, $label, $keys );
					}
				}
			} catch ( \Throwable $e ) {
				// Non-fatal — fall through to the meta scan.
			}
		}

		// --- Source 2: order-meta key scan ---
		foreach ( self::scan_order_meta_keys() as $meta_key ) {
			if ( self::is_internal_order_meta_key( $meta_key ) ) {
				continue;
			}
			self::merge_custom_field( $by_primary, $meta_key, self::humanize_meta_key( $meta_key ), [ $meta_key ] );
		}

		// Sort by label for a stable, readable picker order.
		$list = array_values( $by_primary );
		usort( $list, function ( $a, $b ) {
			return strcasecmp( $a['label'], $b['label'] );
		} );

		/**
		 * Final say over the discovered custom-order-field list. Lets a site add
		 * a field its plugin only registers on the front-end checkout, or drop
		 * ones it does not want offered.
		 *
		 * @param array $list [{syn,label,keys[]}]
		 */
		$list = apply_filters( 'brikpanel_gs_order_custom_fields', $list );

		update_option( self::OPT_ORDER_CUSTOM_FIELDS, is_array( $list ) ? $list : [], false );
	}

	/**
	 * Merge one discovered field into the working set keyed by primary meta key.
	 * If a field with the same primary key already exists, its key-candidate
	 * list is extended (so both storage conventions stay covered) but its
	 * synthetic key and existing label are preserved.
	 */
	private static function merge_custom_field( array &$by_primary, $primary_key, $label, array $keys ) {
		$primary_key = (string) $primary_key;
		if ( $primary_key === '' ) {
			return;
		}
		if ( isset( $by_primary[ $primary_key ] ) ) {
			$merged = array_values( array_unique( array_merge( $by_primary[ $primary_key ]['keys'], $keys ) ) );
			$by_primary[ $primary_key ]['keys'] = $merged;
			return;
		}
		$by_primary[ $primary_key ] = [
			'syn'   => self::custom_field_syn( $primary_key ),
			'label' => (string) $label,
			'keys'  => array_values( array_unique( $keys ) ),
		];
	}

	/**
	 * Core checkout field keys that are already exposed as first-class order
	 * columns (billing/shipping name, address, email, phone, order notes). These
	 * are excluded from the custom-field picker so it only surfaces extras.
	 *
	 * @return string[]
	 */
	private static function core_checkout_field_keys() {
		return [
			'billing_first_name', 'billing_last_name', 'billing_company', 'billing_country',
			'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state',
			'billing_postcode', 'billing_phone', 'billing_email',
			'shipping_first_name', 'shipping_last_name', 'shipping_company', 'shipping_country',
			'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_state',
			'shipping_postcode', 'shipping_phone',
			'account_password', 'account_username', 'order_comments',
		];
	}

	/**
	 * Distinct order-meta keys present on this store (HPOS-aware, capped to keep
	 * the query cheap). Returns [] on any failure.
	 *
	 * @return string[]
	 */
	private static function scan_order_meta_keys() {
		global $wpdb;
		$is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
		if ( $is_hpos && ! empty( $wpdb->prefix ) ) {
			$table = $wpdb->prefix . 'wc_orders_meta';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$keys = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$table} ORDER BY meta_key LIMIT 500" );
			if ( is_array( $keys ) && ! empty( $keys ) ) {
				return array_map( 'strval', $keys );
			}
			// HPOS on but meta table empty/missing — fall through to postmeta.
		}
		$keys = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm.meta_key
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = %s
			 ORDER BY pm.meta_key
			 LIMIT 500",
			'shop_order'
		) );
		return is_array( $keys ) ? array_map( 'strval', $keys ) : [];
	}

	/**
	 * Whether an order-meta key is a WooCommerce / WordPress / BrikPanel
	 * internal that should never be offered as an exportable custom field.
	 *
	 * @param string $key
	 * @return bool
	 */
	private static function is_internal_order_meta_key( $key ) {
		$key = (string) $key;
		if ( $key === '' ) {
			return true;
		}

		// Prefixes that are unambiguously internal/derived: our own + WooCommerce
		// /WordPress internals + the metadata families that well-known
		// integrations (marketplaces, analytics, shipping/tax plugins) write to
		// every order. These are machine fields, never something a merchant would
		// hand-add at checkout, so they only clutter the picker.
		$prefixes = [
			// BrikPanel / brksoft own meta + demo data.
			'_brikpanel', '_brksoft', '_bp_dyn',
			// WooCommerce / WordPress core + derived.
			'_wc_order_attribution', '_wp_', '_edit_',
			'_order_', '_billing_address_index', '_shipping_address_index',
			'_cogs_', '_recorded_', '_refund', '_refunded',
			// Marketplace / channel import metadata.
			'_amz', '_n11', '_ozon', '_trendyol', '_dokan',
			'_shopee', '_lazada', '_etsy', '_ebay', '_hepsiburada',
			// Analytics / pixel plugins (PixelYourSite, etc.).
			'pys_', '_pys', '_pixel',
			// Misc plugin internals seen in the wild.
			'_gzd', '_deleted_from', '_awcfe', '_kargo', '_additional_costs',
			'paytr', '_paytr', '_stripe', '_ppcp', '_paypal',
		];
		$prefixes = apply_filters( 'brikpanel_gs_order_custom_field_denylist', $prefixes );
		foreach ( (array) $prefixes as $p ) {
			if ( $p !== '' && strpos( $key, (string) $p ) === 0 ) {
				return true;
			}
		}

		// Exact WooCommerce core order-meta keys (legacy CPT storage + internals).
		static $exact = null;
		if ( $exact === null ) {
			$exact = array_flip( [
				'_order_key', '_customer_user', '_order_currency', '_prices_include_tax',
				'_order_version', '_cart_hash', '_created_via', '_date_paid', '_date_completed',
				'_paid_date', '_completed_date', '_order_stock_reduced', '_download_permissions_granted',
				'_new_order_email_sent', '_payment_method', '_payment_method_title', '_transaction_id',
				'_customer_ip_address', '_customer_user_agent', '_order_total', '_order_tax',
				'_order_shipping', '_order_shipping_tax', '_cart_discount', '_cart_discount_tax',
				'is_vat_exempt',
				'shipping_fee_recipient', 'tax_fee_recipient', 'shipping_tax_fee_recipient',
			] );
		}
		return isset( $exact[ $key ] );
	}

	/**
	 * Turn a raw meta/field key into a readable column label:
	 * "_delivery_date" → "Delivery date", "wc/gift_message" → "Gift message".
	 *
	 * @param string $key
	 * @return string
	 */
	private static function humanize_meta_key( $key ) {
		$key = (string) $key;
		$key = ltrim( $key, '_' );
		$key = str_replace( [ '/', '-', '.' ], ' ', $key );
		$key = str_replace( '_', ' ', $key );
		$key = trim( preg_replace( '/\s+/', ' ', $key ) );
		if ( $key === '' ) {
			return __( 'Custom field', 'brikpanel' );
		}
		return function_exists( 'mb_convert_case' )
			? mb_convert_case( $key, MB_CASE_TITLE, 'UTF-8' )
			: ucwords( $key );
	}
}
