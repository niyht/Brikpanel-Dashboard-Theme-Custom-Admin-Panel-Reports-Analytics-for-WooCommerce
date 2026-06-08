<?php
/**
 * BrikPanel — Vendor settings tab integration.
 *
 * The actual settings UI lives where every other BrikPanel preference lives:
 * WooCommerce → Settings → BrikPanel → Vendors. That keeps configuration in
 * one well-known place and means admins don't have to learn a new surface.
 *
 * For convenience, when the master toggle is on we also surface a "Settings"
 * row inside the top-level Vendors menu that *links* directly to the
 * WC-settings section — one click, same page.
 *
 * Implementation: hooks the public BrikPanel filter chain registered in
 * front-end/orders/brikpanel-orders.php
 *
 *   - woocommerce_get_sections_brikpanel       — adds the section pill
 *   - brikpanel_settings_fields                — appends fields
 *   - brikpanel_settings_title_section_map     — maps title → section
 *
 * Defaults are intentionally generous (most automations on by default) —
 * the goal is "works out of the box, dial back what you don't want".
 *
 * @package BrikPanel
 * @since   2.7.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Vendor_Settings {

	const SECTION_ID = 'vendors';

	const TITLE_VENDORS  = 'brk_vendors_title';
	const TITLE_STOCK_PO = 'brk_stock_orders_title';
	const TITLE_PO_FLOW  = 'brk_po_flow_title';

	public function __construct() {
		// WC settings tab registration — always on, so the master toggle
		// stays discoverable even when the feature itself is disabled.
		add_filter( 'woocommerce_get_sections_brikpanel',     [ $this, 'register_section' ], 20 );
		add_filter( 'brikpanel_settings_fields',              [ $this, 'register_fields' ], 20 );
		add_filter( 'brikpanel_settings_title_section_map',   [ $this, 'map_titles' ], 20 );

		// Settings convenience link inside the top-level Vendors menu. Priority
		// 30 so this hook runs *after* Brikpanel_Vendors (10) and Stock_Orders
		// (10) have registered their submenus, keeping the link visually pinned
		// at the bottom of the group.
		add_action( 'admin_menu', [ $this, 'register_settings_link' ], 30 );
	}

	// =========================================================================
	// Section pill
	// =========================================================================

	public function register_section( $sections ) {
		// Insert after "products" so the pill ordering stays logical.
		$out = [];
		foreach ( $sections as $id => $label ) {
			$out[ $id ] = $label;
			if ( $id === 'products' ) {
				$out[ self::SECTION_ID ] = __( 'Suppliers', 'brikpanel' );
			}
		}
		if ( ! isset( $out[ self::SECTION_ID ] ) ) {
			$out[ self::SECTION_ID ] = __( 'Suppliers', 'brikpanel' );
		}
		return $out;
	}

	// =========================================================================
	// Field map (every automation is a discrete toggle)
	// =========================================================================

	public function register_fields( $fields ) {
		$vendor_fields = [
			// ---------- Vendors module master toggle ----------
			[
				'name' => __( 'Supplier management', 'brikpanel' ),
				'type' => 'title',
				'id'   => self::TITLE_VENDORS,
				'desc' => __( 'Track suppliers, purchase orders, and per-supplier sourcing. When off, the Suppliers and Stock Orders pages are hidden and no supplier data is collected.', 'brikpanel' ),
			],
			[
				'name'    => __( 'Enable supplier management', 'brikpanel' ),
				'id'      => 'brikpanel_vendors_enabled',
				'type'    => 'checkbox',
				'desc'    => __( 'Master switch — turns the entire suppliers / procurement feature on or off. Off by default; enable it to see the Suppliers menu in the WordPress sidebar.', 'brikpanel' ),
				'default' => 'no',
			],
			[
				'name'    => __( 'Show supplier field in product editor', 'brikpanel' ),
				'id'      => 'brikpanel_vendor_field_in_editor',
				'type'    => 'checkbox',
				'desc'    => __( 'Adds a "Sourcing" card to the BrikPanel product editor where you can pick the supplier and per-product supplier SKU. Variations inherit the parent supplier unless overridden.', 'brikpanel' ),
				'default' => 'yes',
			],
			[
				'name'    => __( 'Show supplier column on products list', 'brikpanel' ),
				'id'      => 'brikpanel_vendor_column_in_products_list',
				'type'    => 'checkbox',
				'desc'    => __( 'Adds a Supplier column to the WooCommerce products list table.', 'brikpanel' ),
				'default' => 'no',
			],
			[
				'type' => 'sectionend',
				'id'   => self::TITLE_VENDORS,
			],

			// ---------- Stock Orders (purchase orders) ----------
			[
				'name' => __( 'Stock orders (purchase orders)', 'brikpanel' ),
				'type' => 'title',
				'id'   => self::TITLE_STOCK_PO,
				'desc' => __( 'Place orders to suppliers, track expected vs. received dates, and let BrikPanel calculate average lead time per supplier.', 'brikpanel' ),
			],
			[
				'name'    => __( 'Enable stock orders', 'brikpanel' ),
				'id'      => 'brikpanel_stock_orders_enabled',
				'type'    => 'checkbox',
				'desc'    => __( 'Adds the Stock Orders page where you can record purchase orders sent to your suppliers.', 'brikpanel' ),
				'default' => 'yes',
			],
			[
				'name'        => __( 'Reference prefix', 'brikpanel' ),
				'id'          => 'brikpanel_po_reference_prefix',
				'type'        => 'text',
				'desc'        => __( 'Used when auto-generating purchase order numbers (e.g. PO-2026-0001).', 'brikpanel' ),
				'default'     => 'PO',
				'placeholder' => 'PO',
				'css'         => 'min-width:120px;',
			],
			[
				'type' => 'sectionend',
				'id'   => self::TITLE_STOCK_PO,
			],

			// ---------- "On receive" automation toggles ----------
			[
				'name' => __( 'When a purchase order is received', 'brikpanel' ),
				'type' => 'title',
				'id'   => self::TITLE_PO_FLOW,
				'desc' => __( 'Choose what BrikPanel should do automatically the moment you mark a stock order as received.', 'brikpanel' ),
			],
			[
				'name'    => __( 'Increase WooCommerce stock', 'brikpanel' ),
				'id'      => 'brikpanel_po_auto_update_stock',
				'type'    => 'checkbox',
				'desc'    => __( 'Add the received quantity to each product/variation\'s WooCommerce stock level. Items without stock management enabled are skipped.', 'brikpanel' ),
				'default' => 'yes',
			],
			[
				'name'    => __( 'Update product cost (COGS)', 'brikpanel' ),
				'id'      => 'brikpanel_po_auto_update_cogs',
				'type'    => 'checkbox',
				'desc'    => __( 'Write the unit cost from the purchase order into WooCommerce\'s Cost of Goods Sold field so future sales report correct profit.', 'brikpanel' ),
				'default' => 'yes',
			],
			[
				'name'    => __( 'COGS calculation method', 'brikpanel' ),
				'id'      => 'brikpanel_po_cogs_method',
				'type'    => 'select',
				'desc'    => __( 'How to update the cost when receiving stock. "Last cost" is simplest; "weighted average" smooths price swings across deliveries.', 'brikpanel' ),
				'options' => [
					'last_cost' => __( 'Use last purchase cost (overwrite)', 'brikpanel' ),
					'weighted'  => __( 'Weighted average across existing stock', 'brikpanel' ),
				],
				'default' => 'last_cost',
			],
			[
				'name'    => __( 'Record as expense', 'brikpanel' ),
				'id'      => 'brikpanel_po_auto_create_expense',
				'type'    => 'checkbox',
				'desc'    => __( 'Push the total order amount to the Expenses table under the "Inventory" category, so net profit reports stay accurate.', 'brikpanel' ),
				'default' => 'yes',
			],
			[
				'name'        => __( 'Expense category for received POs', 'brikpanel' ),
				'id'          => 'brikpanel_po_expense_category',
				'type'        => 'text',
				'desc'        => __( 'Category used when an automatic expense is created on receive.', 'brikpanel' ),
				'default'     => 'Inventory',
				'placeholder' => 'Inventory',
				'css'         => 'min-width:200px;',
			],
			[
				'type' => 'sectionend',
				'id'   => self::TITLE_PO_FLOW,
			],
		];

		// Append at the end — section slicer in brikpanel_settings_fields_for_section()
		// keys off title ids, so order in the master array doesn't matter for
		// visual placement (the sub-nav pill controls that).
		return array_merge( $fields, $vendor_fields );
	}

	// =========================================================================
	// Title → section map
	// =========================================================================

	public function map_titles( $map ) {
		$map[ self::TITLE_VENDORS ]  = self::SECTION_ID;
		$map[ self::TITLE_STOCK_PO ] = self::SECTION_ID;
		$map[ self::TITLE_PO_FLOW ]  = self::SECTION_ID;
		return $map;
	}

	// =========================================================================
	// "Settings" submenu link under the top-level Vendors menu.
	// =========================================================================

	/**
	 * Inject a Settings row into the Vendors top-level submenu that opens the
	 * WC-settings vendors section directly. We bypass add_submenu_page() and
	 * write into $submenu by hand because add_submenu_page treats the slug as
	 * a page id rather than a URL — going through it would force us to render
	 * a stub page that just redirects.
	 */
	public function register_settings_link() {
		global $submenu;

		// Only show the link if the master toggle is on. When off, the
		// Vendors top-level menu doesn't exist and the user is expected to
		// enable from WC → Settings → BrikPanel → Vendors directly.
		if ( 'yes' !== get_option( 'brikpanel_vendors_enabled', 'no' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$parent_slug = Brikpanel_Vendors::PAGE_SLUG;
		$target_url  = admin_url( 'admin.php?page=wc-settings&tab=brikpanel&section=' . self::SECTION_ID );

		// WP submenu row shape: [ title, cap, file, page_title, classes (opt) ].
		// Using an absolute URL as the file makes the row render as a normal
		// link straight to the WC settings page — no extra hop needed.
		$submenu[ $parent_slug ][] = [
			__( 'Settings', 'brikpanel' ),
			'manage_woocommerce',
			$target_url,
			__( 'Supplier settings', 'brikpanel' ),
		];
	}
}
