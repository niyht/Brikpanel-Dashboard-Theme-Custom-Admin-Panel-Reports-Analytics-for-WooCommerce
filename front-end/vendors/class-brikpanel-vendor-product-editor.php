<?php
/**
 * BrikPanel — Vendor selector inside the product editor.
 *
 * The vendor + vendor-SKU fields live INSIDE the BrikPanel simplified product
 * editor (next to "Cost of goods" for the parent, and next to the per-variation
 * COGS column in the variations table). When the
 * `brikpanel_vendor_field_in_editor` setting is on, the editor itself emits
 * the markup — there is no separate metabox or third-party hook required.
 *
 * This class is responsible for the runtime helpers that the editor pulls
 * from at render and save time:
 *
 *   - resolve_vendor_id()        : effective vendor for a product/variation
 *                                  (variation override wins, falls back to
 *                                  parent product meta)
 *   - vendor_field_enabled()     : settings gate
 *   - active_options()           : id => name map for the dropdown
 *   - persist_meta()             : single canonical writer for vendor meta
 *   - column hooks               : products-list "Vendor" column
 *
 * Storage:
 *   - `_brikpanel_vendor_id`   (post meta on product or variation)
 *   - `_brikpanel_vendor_sku`  (vendor's own SKU, optional)
 *
 * @package BrikPanel
 * @since   2.7.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Vendor_Product_Editor {

	const META_VENDOR_ID  = '_brikpanel_vendor_id';
	const META_VENDOR_SKU = '_brikpanel_vendor_sku';

	public function __construct() {
		// AJAX endpoint for any future client-side vendor refresh — currently
		// unused (data is server-rendered) but registered so external code can
		// rely on it.
		add_action( 'wp_ajax_brikpanel_vendor_options', [ $this, 'ajax_vendor_options' ] );

		// Products list column (independent of editor field — admins may want
		// the column without the editor field, or vice versa).
		if ( 'yes' === get_option( 'brikpanel_vendor_column_in_products_list', 'no' ) ) {
			add_filter( 'manage_edit-product_columns', [ $this, 'add_list_column' ], 100 );
			add_action( 'manage_product_posts_custom_column', [ $this, 'render_list_column' ], 10, 2 );
		}
	}

	// =========================================================================
	// Settings gate
	// =========================================================================

	public static function vendor_field_enabled() {
		return 'yes' === get_option( 'brikpanel_vendor_field_in_editor', 'yes' )
			&& 'yes' === get_option( 'brikpanel_vendors_enabled', 'no' );
	}

	// =========================================================================
	// Options for the dropdown — cached per request to avoid duplicate queries
	// when both the parent and N variations render the same select.
	// =========================================================================

	public static function active_options() {
		static $cache = null;
		if ( $cache !== null ) {
			return $cache;
		}
		$cache = Brikpanel_Vendors::get_active_vendor_options();
		return $cache;
	}

	// =========================================================================
	// Persist vendor meta for one product or variation.
	//
	// Called by the simplified editor's AJAX save handler from
	// brikpanel-product-editor.php. Centralised so future storage tweaks
	// (e.g. multi-vendor) only touch one place.
	// =========================================================================

	public static function persist_meta( $post_id, $vendor_id, $vendor_sku = '' ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}
		$vendor_id  = (int) $vendor_id;
		$vendor_sku = (string) $vendor_sku;

		if ( $vendor_id > 0 && self::vendor_exists( $vendor_id ) ) {
			update_post_meta( $post_id, self::META_VENDOR_ID, $vendor_id );
		} else {
			delete_post_meta( $post_id, self::META_VENDOR_ID );
		}

		if ( $vendor_sku !== '' ) {
			update_post_meta( $post_id, self::META_VENDOR_SKU, sanitize_text_field( $vendor_sku ) );
		} else {
			delete_post_meta( $post_id, self::META_VENDOR_SKU );
		}
	}

	private static function vendor_exists( $vendor_id ) {
		global $wpdb;
		$table = $wpdb->prefix . Brikpanel_Vendors::TABLE;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE id = %d", absint( $vendor_id ) ) ); // phpcs:ignore
	}

	// =========================================================================
	// Resolve effective vendor (variation override → parent fallback)
	// =========================================================================

	/**
	 * @param int $product_id
	 * @param int $variation_id
	 * @return int 0 when no vendor set anywhere up the chain.
	 */
	public static function resolve_vendor_id( $product_id, $variation_id = 0 ) {
		$variation_id = absint( $variation_id );
		if ( $variation_id ) {
			$override = (int) get_post_meta( $variation_id, self::META_VENDOR_ID, true );
			if ( $override > 0 ) {
				return $override;
			}
		}
		return (int) get_post_meta( absint( $product_id ), self::META_VENDOR_ID, true );
	}

	// =========================================================================
	// Products list column
	// =========================================================================

	public function add_list_column( $columns ) {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( $key === 'sku' ) {
				$out['brikpanel_vendor'] = __( 'Supplier', 'brikpanel' );
			}
		}
		if ( ! isset( $out['brikpanel_vendor'] ) ) {
			$out['brikpanel_vendor'] = __( 'Supplier', 'brikpanel' );
		}
		return $out;
	}

	public function render_list_column( $column, $post_id ) {
		if ( $column !== 'brikpanel_vendor' ) {
			return;
		}
		$vendor_id = (int) get_post_meta( $post_id, self::META_VENDOR_ID, true );
		if ( ! $vendor_id ) {
			echo '<span style="color:#8a8a8a;">—</span>';
			return;
		}
		$opts = self::active_options();
		echo isset( $opts[ $vendor_id ] ) ? esc_html( $opts[ $vendor_id ] ) : '<span style="color:#8a8a8a;">—</span>';
	}

	// =========================================================================
	// AJAX: vendor options (compat — used by external code, not the editor)
	// =========================================================================

	public function ajax_vendor_options() {
		check_ajax_referer( 'brikpanel_vendor_options', '_ajax_nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'brikpanel' ) ], 403 );
		}
		wp_send_json_success( self::active_options() );
	}
}
