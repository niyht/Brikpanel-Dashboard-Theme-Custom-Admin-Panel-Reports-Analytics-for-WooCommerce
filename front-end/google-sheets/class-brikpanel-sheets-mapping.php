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

	/**
	 * Catalogue of available columns + their human label, keyed by flow.
	 *
	 * @return array<string, array<string, array{label:string, mandatory?:bool, default?:bool, group?:string}>>
	 */
	public static function catalogue() {
		return [
			'orders' => [
				// Order-level
				'order_id'             => [ 'label' => __( 'Order ID',             'brikpanel' ), 'mandatory' => true,  'default' => true,  'group' => 'order' ],
				'order_number'         => [ 'label' => __( 'Order number',         'brikpanel' ), 'default' => true,  'group' => 'order' ],
				'order_date'           => [ 'label' => __( 'Order date',           'brikpanel' ), 'default' => true,  'group' => 'order' ],
				'order_status'         => [ 'label' => __( 'Order status',         'brikpanel' ), 'default' => true,  'group' => 'order' ],
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
				'price'         => [ 'label' => __( 'Price',          'brikpanel' ), 'default' => true ],
				'regular_price' => [ 'label' => __( 'Regular price',  'brikpanel' ), 'default' => true ],
				'sale_price'    => [ 'label' => __( 'Sale price',     'brikpanel' ), 'default' => true ],
				// Stock is the only writable column on the reverse direction.
				// Header is marked with a trailing arrow in headers_for() so the
				// merchant sees at a glance which column edits flow back to Woo.
				'stock'         => [ 'label' => __( 'Stock',          'brikpanel' ), 'default' => true, 'writable' => true ],
				'stock_status'  => [ 'label' => __( 'Stock status',   'brikpanel' ), 'default' => true, 'writable' => true ],
				'status'        => [ 'label' => __( 'Status',         'brikpanel' ), 'default' => true ],
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
		];
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
			$out[] = isset( $cat[ $key ]['label'] ) ? (string) $cat[ $key ]['label'] : (string) $key;
		}
		return $out;
	}

	private static function option_key_for( $flow ) {
		switch ( $flow ) {
			case 'orders':    return self::OPT_ORDERS;
			case 'customers': return self::OPT_CUSTOMERS;
			case 'products':  return self::OPT_PRODUCTS;
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
}
