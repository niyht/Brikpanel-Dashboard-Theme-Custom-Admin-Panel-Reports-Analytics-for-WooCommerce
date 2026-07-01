<?php
/**
 * BrikPanel — Shared profit computation.
 *
 * Single source of truth for the dashboard Profit section AND the Google
 * Sheets "Profit" snapshot, so both always agree.
 *
 * Net profit = Revenue − Cost of goods − Expenses, where Expenses is the
 * composite of:
 *   - Ad spend          (Ad Platforms, store currency only)
 *   - Tax               (WooCommerce order tax on paid orders)
 *   - Vendor / Inventory (auto-expense rows from received purchase orders)
 *   - Other operating   (everything else in the Expenses module)
 *
 * Vendor/Inventory is NOT added on top of manual expenses — received POs
 * already write a row into wp_brikpanel_expenses, so it is a SUBSET of the
 * manual expenses total and only split out for the breakdown.
 *
 * @package BrikPanel
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marketplace-order exclusion fragment for a Profit-section query, gated by the
 * caller's intent.
 *
 * The Profit components must measure the SAME orders the Revenue card does. On
 * a BrikMarket store the Revenue KPI drops marketplace-imported orders, so when
 * the caller passes the matching basis ($exclude_marketplace = true) every
 * order-derived cost figure (COGS, tax, returns, coupons, coverage) drops them
 * too. Returns an empty fragment when not requested or when BrikMarket is
 * inactive, so single-channel stores are untouched and callers can append it
 * unconditionally.
 *
 * @param bool   $exclude_marketplace Whether the caller wants marketplace orders dropped.
 * @param bool   $is_hpos             Whether HPOS is active.
 * @param string $id_column           Column referencing the order id (e.g. 'o.id', 'p.ID').
 * @return array{sql: string, args: array}
 */
function brikpanel_profit_marketplace_excl( $exclude_marketplace, $is_hpos, $id_column ) {
	if ( ! $exclude_marketplace || ! function_exists( 'brikpanel_marketplace_order_exclusion_sql' ) ) {
		return [ 'sql' => '', 'args' => [] ];
	}
	return brikpanel_marketplace_order_exclusion_sql( $is_hpos, $id_column );
}

/**
 * Cost of goods sold for paid orders inside [$start_gmt, $end_gmt].
 *
 * Source of truth is BrikPanel's own `_brikpanel_cogs` product/variation
 * meta (always written by the product editor regardless of the WC native
 * COGS feature flag), multiplied by quantity sold. Works for BOTH simple and
 * variable products: variation lines prefer the variation's own cost and
 * fall back to the parent product's cost. Reads current cost (not a
 * sale-time snapshot) so past orders gain a cost the moment it is filled in.
 * Admin-placed orders are excluded so this reconciles with the Revenue KPI.
 * When $exclude_marketplace is true (BrikMarket store), marketplace-imported
 * orders are also dropped so COGS shares the SAME order basis as the Revenue
 * card — otherwise store-only revenue would be measured against a COGS that
 * still carries the cost of marketplace orders, manufacturing a permanent loss.
 *
 * @param string $start_gmt           Y-m-d H:i:s (UTC)
 * @param string $end_gmt             Y-m-d H:i:s (UTC)
 * @param bool   $exclude_marketplace Drop BrikMarket-imported orders to match the revenue basis.
 * @return float
 */
function brikpanel_profit_cogs( $start_gmt, $end_gmt, $exclude_marketplace = false ) {
	global $wpdb;

	$is_hpos  = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
	$statuses = brikpanel_paid_order_statuses();
	$sp       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

	$joins = "
		INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
				ON oi.order_id = %ORDER_ID% AND oi.order_item_type = 'line_item'
		INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty
				ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
		LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta pid
				ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
		LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta vid
				ON vid.order_item_id = oi.order_item_id AND vid.meta_key = '_variation_id'
		LEFT JOIN  {$wpdb->postmeta} vcog
				ON vcog.post_id = CAST(vid.meta_value AS UNSIGNED)
			   AND vcog.meta_key = '_brikpanel_cogs'
			   AND CAST(vid.meta_value AS UNSIGNED) > 0
		LEFT JOIN  {$wpdb->postmeta} pcog
				ON pcog.post_id = CAST(pid.meta_value AS UNSIGNED)
			   AND pcog.meta_key = '_brikpanel_cogs'";

	$sum = "COALESCE(SUM(
			CAST(qty.meta_value AS DECIMAL(20,4)) *
			CAST(COALESCE(NULLIF(vcog.meta_value, ''), pcog.meta_value, '0') AS DECIMAL(20,4))
		), 0)";

	if ( $is_hpos ) {
		$j    = str_replace( '%ORDER_ID%', 'o.id', $joins );
		$sql  = "SELECT {$sum}
			FROM {$wpdb->prefix}wc_orders o
			{$j}
			WHERE o.type = 'shop_order' AND o.status IN ($sp)
			  AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s";
		$args = array_merge( $statuses, [ $start_gmt, $end_gmt ] );
		$excl = brikpanel_admin_order_exclusion_sql( true );
	} else {
		$j    = str_replace( '%ORDER_ID%', 'p.ID', $joins );
		$sql  = "SELECT {$sum}
			FROM {$wpdb->posts} p
			{$j}
			WHERE p.post_type = 'shop_order' AND p.post_status IN ($sp)
			  AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s";
		$args = array_merge( $statuses, [ $start_gmt, $end_gmt ] );
		$excl = brikpanel_admin_order_exclusion_sql( false, 'p.ID' );
	}

	if ( ! empty( $excl['sql'] ) ) {
		$sql .= $excl['sql'];
		$args = array_merge( $args, $excl['args'] );
	}

	$mp = brikpanel_profit_marketplace_excl( $exclude_marketplace, $is_hpos, $is_hpos ? 'o.id' : 'p.ID' );
	if ( ! empty( $mp['sql'] ) ) {
		$sql .= $mp['sql'];
		$args = array_merge( $args, $mp['args'] );
	}

	return (float) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore
}

/**
 * How much of the period's sales actually has a cost on file.
 *
 * The COGS figure silently treats any product without a `_brikpanel_cogs`
 * value as zero-cost, which inflates Net profit. This measures that gap so
 * the UI can warn instead of presenting an over-optimistic margin as fact.
 *
 * Coverage is revenue-weighted (line totals of items WITH a cost ÷ all line
 * totals) so a few cheap costless add-ons barely move it while a costless
 * hero product flags loudly. Works for simple AND variable products with the
 * same variation→parent cost fallback as brikpanel_profit_cogs().
 *
 * @return array{total_lines:int,missing_lines:int,total_revenue:float,missing_revenue:float,coverage_pct:float}
 */
function brikpanel_profit_cogs_coverage( $start_gmt, $end_gmt, $exclude_marketplace = false ) {
	global $wpdb;

	$is_hpos  = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
	$statuses = brikpanel_paid_order_statuses();
	$sp       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

	$joins = "
		INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
				ON oi.order_id = %ORDER_ID% AND oi.order_item_type = 'line_item'
		LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta lt
				ON lt.order_item_id = oi.order_item_id AND lt.meta_key = '_line_total'
		LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta pid
				ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
		LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta vid
				ON vid.order_item_id = oi.order_item_id AND vid.meta_key = '_variation_id'
		LEFT JOIN  {$wpdb->postmeta} vcog
				ON vcog.post_id = CAST(vid.meta_value AS UNSIGNED)
			   AND vcog.meta_key = '_brikpanel_cogs'
			   AND CAST(vid.meta_value AS UNSIGNED) > 0
		LEFT JOIN  {$wpdb->postmeta} pcog
				ON pcog.post_id = CAST(pid.meta_value AS UNSIGNED)
			   AND pcog.meta_key = '_brikpanel_cogs'";

	// A line is "covered" when EITHER the variation or its parent has a cost
	// row recorded — even an explicit 0 (free sample, complimentary item,
	// digital good with no per-unit cost) is a valid answer the merchant
	// chose, not an absence. Missing strictly means "no cost meta exists on
	// file". The save handler deletes the meta when the field is cleared, so
	// a present row with an empty string is a legacy edge case we still treat
	// as missing for defensiveness.
	$has_cost = "((vcog.meta_value IS NOT NULL AND vcog.meta_value <> '')"
		. " OR (pcog.meta_value IS NOT NULL AND pcog.meta_value <> ''))";
	$rev      = "CAST(COALESCE(lt.meta_value, '0') AS DECIMAL(20,4))";
	$select   = "
		COUNT(*) AS total_lines,
		COALESCE(SUM(CASE WHEN {$has_cost} THEN 0 ELSE 1 END), 0) AS missing_lines,
		COALESCE(SUM({$rev}), 0) AS total_revenue,
		COALESCE(SUM(CASE WHEN {$has_cost} THEN 0 ELSE {$rev} END), 0) AS missing_revenue";

	if ( $is_hpos ) {
		$j    = str_replace( '%ORDER_ID%', 'o.id', $joins );
		$sql  = "SELECT {$select}
			FROM {$wpdb->prefix}wc_orders o
			{$j}
			WHERE o.type = 'shop_order' AND o.status IN ($sp)
			  AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s";
		$args = array_merge( $statuses, [ $start_gmt, $end_gmt ] );
		$excl = brikpanel_admin_order_exclusion_sql( true );
	} else {
		$j    = str_replace( '%ORDER_ID%', 'p.ID', $joins );
		$sql  = "SELECT {$select}
			FROM {$wpdb->posts} p
			{$j}
			WHERE p.post_type = 'shop_order' AND p.post_status IN ($sp)
			  AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s";
		$args = array_merge( $statuses, [ $start_gmt, $end_gmt ] );
		$excl = brikpanel_admin_order_exclusion_sql( false, 'p.ID' );
	}

	if ( ! empty( $excl['sql'] ) ) {
		$sql .= $excl['sql'];
		$args = array_merge( $args, $excl['args'] );
	}

	$mp = brikpanel_profit_marketplace_excl( $exclude_marketplace, $is_hpos, $is_hpos ? 'o.id' : 'p.ID' );
	if ( ! empty( $mp['sql'] ) ) {
		$sql .= $mp['sql'];
		$args = array_merge( $args, $mp['args'] );
	}

	$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore

	$total_lines = (int) ( $row->total_lines ?? 0 );
	$missing     = (int) ( $row->missing_lines ?? 0 );
	$total_rev   = (float) ( $row->total_revenue ?? 0 );
	$missing_rev = (float) ( $row->missing_revenue ?? 0 );

	if ( $total_rev > 0 ) {
		$coverage = round( ( ( $total_rev - $missing_rev ) / $total_rev ) * 100, 1 );
	} elseif ( $total_lines > 0 ) {
		$coverage = round( ( ( $total_lines - $missing ) / $total_lines ) * 100, 1 );
	} else {
		$coverage = 100.0;
	}

	return [
		'total_lines'     => $total_lines,
		'missing_lines'   => $missing,
		'total_revenue'   => $total_rev,
		'missing_revenue' => $missing_rev,
		'coverage_pct'    => max( 0.0, min( 100.0, $coverage ) ),
	];
}

/**
 * Top products sold in the window that have no Cost of goods on file.
 *
 * Powers the dashboard "missing cost" tooltip: instead of just telling the
 * merchant "N items lack a cost", we name the worst offenders so they can
 * jump straight to those products and fix the most impactful gaps first.
 * Ranked by missing revenue (line total) so the highest-revenue costless
 * items rise to the top — that is where Net profit is most overstated.
 *
 * Variations roll up to their parent product (the parent is what the user
 * edits in the BrikPanel product editor); the variation's resolved cost
 * still uses the variation-first / parent-fallback resolution from the
 * coverage helper so the list never names a product that does have a cost.
 *
 * Lines whose product reference no longer resolves to a catalog row (deleted
 * products, marketplace orders that never linked to a local product) get
 * grouped by the line item's original `order_item_name`. We can't link to
 * an editor for those, but naming them keeps the merchant from staring at a
 * lone "N items lack a cost" warning with nothing to chase.
 *
 * @param string $start_gmt Y-m-d H:i:s (UTC)
 * @param string $end_gmt   Y-m-d H:i:s (UTC)
 * @param int    $limit     Hard cap on rows returned (kept small for payload size).
 * @return array<int,array{id:int,name:string,edit_url:string,units:int,missing_revenue:float,missing_revenue_html:string,unlinked:bool}>
 */
function brikpanel_profit_cogs_missing_products( $start_gmt, $end_gmt, $limit = 20, $exclude_marketplace = false ) {
	global $wpdb;

	$is_hpos  = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
	$statuses = brikpanel_paid_order_statuses();
	$sp       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
	$limit    = max( 1, min( 50, (int) $limit ) );

	$joins = "
		INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
				ON oi.order_id = %ORDER_ID% AND oi.order_item_type = 'line_item'
		LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta lt
				ON lt.order_item_id = oi.order_item_id AND lt.meta_key = '_line_total'
		LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta qty
				ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
		LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta pid
				ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
		LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta vid
				ON vid.order_item_id = oi.order_item_id AND vid.meta_key = '_variation_id'
		LEFT JOIN  {$wpdb->postmeta} vcog
				ON vcog.post_id = CAST(vid.meta_value AS UNSIGNED)
			   AND vcog.meta_key = '_brikpanel_cogs'
			   AND CAST(vid.meta_value AS UNSIGNED) > 0
		LEFT JOIN  {$wpdb->postmeta} pcog
				ON pcog.post_id = CAST(pid.meta_value AS UNSIGNED)
			   AND pcog.meta_key = '_brikpanel_cogs'
		LEFT JOIN  {$wpdb->posts} pp
				ON pp.ID = CAST(pid.meta_value AS UNSIGNED)";

	// "Missing" follows the same rule as the coverage helper: no cost row on
	// either side. Explicit 0 is treated as a deliberate cost — not missing.
	$missing_clause = "NOT ((vcog.meta_value IS NOT NULL AND vcog.meta_value <> '')"
		. " OR (pcog.meta_value IS NOT NULL AND pcog.meta_value <> ''))";

	// Group by the *resolvable* product when one exists, otherwise fall back to
	// the order_item_name so unlinked rows for the same item collapse into a
	// single entry instead of spamming the tooltip with one row per order.
	// The two cases share a column shape so the caller can iterate uniformly.
	$group_key  = "COALESCE(NULLIF(pp.post_title, ''), oi.order_item_name)";
	$select     = "
		CAST(pid.meta_value AS UNSIGNED) AS product_id,
		MAX(pp.post_title)               AS product_title,
		MAX(oi.order_item_name)          AS item_name,
		COALESCE(SUM(CAST(qty.meta_value AS DECIMAL(20,4))), 0) AS missing_units,
		COALESCE(SUM(CAST(IFNULL(lt.meta_value,'0') AS DECIMAL(20,4))), 0) AS missing_revenue";

	if ( $is_hpos ) {
		$j    = str_replace( '%ORDER_ID%', 'o.id', $joins );
		$sql  = "SELECT {$select}
			FROM {$wpdb->prefix}wc_orders o
			{$j}
			WHERE o.type = 'shop_order' AND o.status IN ($sp)
			  AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
			  AND {$missing_clause}";
		$args = array_merge( $statuses, [ $start_gmt, $end_gmt ] );
		$excl = brikpanel_admin_order_exclusion_sql( true );
	} else {
		$j    = str_replace( '%ORDER_ID%', 'p.ID', $joins );
		$sql  = "SELECT {$select}
			FROM {$wpdb->posts} p
			{$j}
			WHERE p.post_type = 'shop_order' AND p.post_status IN ($sp)
			  AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s
			  AND {$missing_clause}";
		$args = array_merge( $statuses, [ $start_gmt, $end_gmt ] );
		$excl = brikpanel_admin_order_exclusion_sql( false, 'p.ID' );
	}

	if ( ! empty( $excl['sql'] ) ) {
		$sql .= $excl['sql'];
		$args = array_merge( $args, $excl['args'] );
	}

	$mp = brikpanel_profit_marketplace_excl( $exclude_marketplace, $is_hpos, $is_hpos ? 'o.id' : 'p.ID' );
	if ( ! empty( $mp['sql'] ) ) {
		$sql .= $mp['sql'];
		$args = array_merge( $args, $mp['args'] );
	}

	$sql .= " GROUP BY {$group_key}
		ORDER BY missing_revenue DESC, missing_units DESC
		LIMIT {$limit}";

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore
	if ( empty( $rows ) ) {
		return [];
	}

	$out = [];
	foreach ( $rows as $r ) {
		$pid      = (int) $r->product_id;
		$title    = trim( (string) ( $r->product_title ?? '' ) );
		$line_nm  = trim( (string) ( $r->item_name ?? '' ) );
		$linked   = ( $pid > 0 && $title !== '' );
		$display  = $linked ? $title : ( $line_nm !== '' ? $line_nm : __( '(Unknown product)', 'brikpanel' ) );
		$rev      = (float) $r->missing_revenue;
		$out[] = [
			'id'                   => $linked ? $pid : 0,
			'name'                 => $display,
			'edit_url'             => $linked ? admin_url( 'admin.php?page=brikpanel-product-editor&product_id=' . $pid ) : '',
			'units'                => (int) $r->missing_units,
			'missing_revenue'      => $rev,
			'missing_revenue_html' => function_exists( 'wc_price' ) ? wc_price( $rev ) : (string) $rev,
			// `true` = no editable product behind this row (deleted product
			// or marketplace order that never linked to a local product). The
			// UI uses this to mark the name so the merchant doesn't go on a
			// fruitless hunt for it in the product list.
			'unlinked'             => ! $linked,
		];
	}
	return $out;
}

/**
 * Customer returns (refund amount) on paid orders inside [$start_gmt, $end_gmt].
 *
 * Only refunds whose PARENT order is itself a paid order in the window count,
 * so this reconciles with the same order basis as Revenue/COGS: a partial
 * refund on a still-processing/completed order reduces what the merchant
 * actually kept, but a fully refunded order has already dropped out of the
 * paid-status set (Revenue never counted it, so its refund must not be
 * double-subtracted here). Works for BOTH simple and variable products — the
 * refund lives on the order, not the line item, so variation structure is
 * irrelevant. Admin orders excluded to match the revenue basis.
 *
 * Returned as a POSITIVE figure (the amount given back); callers subtract it.
 *
 * @return float
 */
function brikpanel_profit_returns( $start_gmt, $end_gmt, $exclude_marketplace = false ) {
	global $wpdb;

	$is_hpos  = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
	$statuses = brikpanel_paid_order_statuses();
	$sp       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

	if ( $is_hpos ) {
		$sql  = "SELECT COALESCE(SUM(ABS(r.total_amount)), 0)
			FROM {$wpdb->prefix}wc_orders r
			INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = r.parent_order_id
			WHERE r.type = 'shop_order_refund'
			  AND o.type = 'shop_order' AND o.status IN ($sp)
			  AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s";
		$args = array_merge( $statuses, [ $start_gmt, $end_gmt ] );
		$excl = brikpanel_admin_order_exclusion_sql( true );
		// The helper emits a bare `customer_id`; both wc_orders rows in this
		// join own that column, so qualify it to the parent (o) to avoid an
		// "ambiguous column" error.
		if ( ! empty( $excl['sql'] ) ) {
			$excl['sql'] = str_replace( 'customer_id', 'o.customer_id', $excl['sql'] );
		}
	} else {
		// Legacy: refund posts (post_parent = order) carry the given-back
		// amount in `_refund_amount` (positive). Sum it against orders in the
		// paid-status window.
		$sql  = "SELECT COALESCE(SUM(CAST(IFNULL(ra.meta_value, '0') AS DECIMAL(20,4))), 0)
			FROM {$wpdb->posts} r
			INNER JOIN {$wpdb->posts} o ON o.ID = r.post_parent
			LEFT JOIN {$wpdb->postmeta} ra ON ra.post_id = r.ID AND ra.meta_key = '_refund_amount'
			WHERE r.post_type = 'shop_order_refund'
			  AND o.post_type = 'shop_order' AND o.post_status IN ($sp)
			  AND o.post_date_gmt >= %s AND o.post_date_gmt <= %s";
		$args = array_merge( $statuses, [ $start_gmt, $end_gmt ] );
		$excl = brikpanel_admin_order_exclusion_sql( false, 'o.ID' );
	}

	if ( ! empty( $excl['sql'] ) ) {
		$sql .= $excl['sql'];
		$args = array_merge( $args, $excl['args'] );
	}

	// The marketplace marker meta lives on the PARENT order (alias o in both
	// branches), so exclude against o.id / o.ID, not the refund row.
	$mp = brikpanel_profit_marketplace_excl( $exclude_marketplace, $is_hpos, $is_hpos ? 'o.id' : 'o.ID' );
	if ( ! empty( $mp['sql'] ) ) {
		$sql .= $mp['sql'];
		$args = array_merge( $args, $mp['args'] );
	}

	$returns = (float) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore

	/**
	 * Filter the customer-returns total that nets down Revenue in the Profit
	 * section.
	 *
	 * @param float  $returns   Refund amount for the window (positive).
	 * @param string $start_gmt Y-m-d H:i:s (UTC)
	 * @param string $end_gmt   Y-m-d H:i:s (UTC)
	 */
	return (float) apply_filters( 'brikpanel_profit_returns', $returns, $start_gmt, $end_gmt );
}

/**
 * Total coupon/cart discount applied on paid orders inside [$start_gmt, $end_gmt].
 *
 * Informational only: the order total already reflects the discount, so this
 * is NOT subtracted again anywhere — it just tells the merchant how much they
 * gave away in promotions for the period. Admin orders excluded to match the
 * revenue basis. Works for simple and variable products alike (the discount
 * lives on the order).
 *
 * @return float
 */
function brikpanel_profit_coupons( $start_gmt, $end_gmt, $exclude_marketplace = false ) {
	global $wpdb;

	$is_hpos  = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
	$statuses = brikpanel_paid_order_statuses();
	$sp       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

	if ( $is_hpos ) {
		// HPOS keeps the discount amount in the operational-data table, not on
		// wc_orders itself (which has no discount column), so join it in.
		$sql  = "SELECT COALESCE(SUM(od.discount_total_amount), 0)
			FROM {$wpdb->prefix}wc_orders o
			LEFT JOIN {$wpdb->prefix}wc_order_operational_data od ON od.order_id = o.id
			WHERE o.type = 'shop_order' AND o.status IN ($sp)
			  AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s";
		$args = array_merge( $statuses, [ $start_gmt, $end_gmt ] );
		$excl = brikpanel_admin_order_exclusion_sql( true );
	} else {
		$sql  = "SELECT COALESCE(SUM(CAST(IFNULL(d.meta_value, '0') AS DECIMAL(20,4))), 0)
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '_cart_discount'
			WHERE p.post_type = 'shop_order' AND p.post_status IN ($sp)
			  AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s";
		$args = array_merge( $statuses, [ $start_gmt, $end_gmt ] );
		$excl = brikpanel_admin_order_exclusion_sql( false, 'p.ID' );
	}

	if ( ! empty( $excl['sql'] ) ) {
		$sql .= $excl['sql'];
		$args = array_merge( $args, $excl['args'] );
	}

	$mp = brikpanel_profit_marketplace_excl( $exclude_marketplace, $is_hpos, $is_hpos ? 'o.id' : 'p.ID' );
	if ( ! empty( $mp['sql'] ) ) {
		$sql .= $mp['sql'];
		$args = array_merge( $args, $mp['args'] );
	}

	$coupons = (float) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore

	/**
	 * Filter the coupon/discount total shown (info only) in the Profit section.
	 *
	 * @param float  $coupons   Discount given for the window.
	 * @param string $start_gmt Y-m-d H:i:s (UTC)
	 * @param string $end_gmt   Y-m-d H:i:s (UTC)
	 */
	return (float) apply_filters( 'brikpanel_profit_coupons', $coupons, $start_gmt, $end_gmt );
}

/**
 * Total WooCommerce tax on paid orders inside [$start_gmt, $end_gmt].
 * Admin orders excluded to match the revenue/COGS basis.
 *
 * @return float
 */
function brikpanel_profit_tax( $start_gmt, $end_gmt, $exclude_marketplace = false ) {
	global $wpdb;

	$is_hpos  = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
	$statuses = brikpanel_paid_order_statuses();
	$sp       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

	if ( $is_hpos ) {
		$sql  = "SELECT COALESCE(SUM(o.tax_amount), 0)
			FROM {$wpdb->prefix}wc_orders o
			WHERE o.type = 'shop_order' AND o.status IN ($sp)
			  AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s";
		$args = array_merge( $statuses, [ $start_gmt, $end_gmt ] );
		$excl = brikpanel_admin_order_exclusion_sql( true );
	} else {
		// Legacy: cart tax + shipping tax meta.
		$sql  = "SELECT COALESCE(SUM(
				CAST(IFNULL(t1.meta_value,'0') AS DECIMAL(20,4)) +
				CAST(IFNULL(t2.meta_value,'0') AS DECIMAL(20,4))
			), 0)
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} t1 ON t1.post_id = p.ID AND t1.meta_key = '_order_tax'
			LEFT JOIN {$wpdb->postmeta} t2 ON t2.post_id = p.ID AND t2.meta_key = '_order_shipping_tax'
			WHERE p.post_type = 'shop_order' AND p.post_status IN ($sp)
			  AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s";
		$args = array_merge( $statuses, [ $start_gmt, $end_gmt ] );
		$excl = brikpanel_admin_order_exclusion_sql( false, 'p.ID' );
	}

	if ( ! empty( $excl['sql'] ) ) {
		$sql .= $excl['sql'];
		$args = array_merge( $args, $excl['args'] );
	}

	$mp = brikpanel_profit_marketplace_excl( $exclude_marketplace, $is_hpos, $is_hpos ? 'o.id' : 'p.ID' );
	if ( ! empty( $mp['sql'] ) ) {
		$sql .= $mp['sql'];
		$args = array_merge( $args, $mp['args'] );
	}

	$tax = (float) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore

	/**
	 * Filter the tax total that feeds the Profit section.
	 *
	 * @param float  $tax       Order tax for the window.
	 * @param string $start_gmt Y-m-d H:i:s (UTC)
	 * @param string $end_gmt   Y-m-d H:i:s (UTC)
	 */
	return (float) apply_filters( 'brikpanel_profit_tax', $tax, $start_gmt, $end_gmt );
}

/**
 * Manual expenses inside a local date range, split into the
 * vendor/inventory category (auto-created from received POs) and everything
 * else. Returns [ total, inventory, other ]. All zeros when the Expenses
 * module table doesn't exist yet.
 *
 * @param string $start_local Y-m-d H:i:s
 * @param string $end_local   Y-m-d H:i:s
 * @return array{0:float,1:float,2:float}
 */
function brikpanel_profit_manual_expenses( $start_local, $end_local ) {
	global $wpdb;

	$table = $wpdb->prefix . 'brikpanel_expenses';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return [ 0.0, 0.0, 0.0 ];
	}

	$start_date = substr( (string) $start_local, 0, 10 );
	$end_date   = substr( (string) $end_local, 0, 10 );
	$inv_cat    = (string) get_option( 'brikpanel_po_expense_category', 'Inventory' );

	// kind != 'percent' so percentage-based costs (e.g. card commission) are
	// excluded here — their `amount` column holds a RATE, not money, and they
	// are computed separately in brikpanel_profit_percent_expenses().
	$total = (float) $wpdb->get_var( $wpdb->prepare(
		"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE expense_date BETWEEN %s AND %s AND kind <> 'percent'",
		$start_date,
		$end_date
	) ); // phpcs:ignore

	$inventory = (float) $wpdb->get_var( $wpdb->prepare(
		"SELECT COALESCE(SUM(amount), 0) FROM {$table}
		 WHERE expense_date BETWEEN %s AND %s AND kind <> 'percent' AND category = %s",
		$start_date,
		$end_date,
		$inv_cat
	) ); // phpcs:ignore

	$other = $total - $inventory;
	if ( $other < 0 ) {
		$other = 0.0;
	}

	return [ $total, $inventory, $other ];
}

/**
 * Manual expenses for a local date range, grouped by their own category so the
 * dashboard breakdown can show "Salaries", "Rent", "Shipping carriers" etc. as
 * their own lines instead of dumping everything non-inventory into a single
 * "Other" bucket. Rows with no category collapse under an empty-string key (the
 * caller labels that "Other"). Ordered by amount desc so the biggest costs lead.
 *
 * This is purely a display decomposition of the SAME total that
 * brikpanel_profit_manual_expenses() returns — summing every value here equals
 * that total — so Net profit and the expenses figure never change, only how the
 * breakdown is presented. All zeros (empty array) when the module table is absent.
 *
 * @param string $start_local Y-m-d H:i:s
 * @param string $end_local   Y-m-d H:i:s
 * @return array<string,float> category => amount, highest first.
 */
function brikpanel_profit_manual_expenses_by_category( $start_local, $end_local ) {
	global $wpdb;

	$table = $wpdb->prefix . 'brikpanel_expenses';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return [];
	}

	$start_date = substr( (string) $start_local, 0, 10 );
	$end_date   = substr( (string) $end_local, 0, 10 );

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT COALESCE(category, '') AS category, SUM(amount) AS total
		 FROM {$table}
		 WHERE expense_date BETWEEN %s AND %s AND kind <> 'percent'
		 GROUP BY COALESCE(category, '')
		 HAVING total <> 0
		 ORDER BY total DESC",
		$start_date,
		$end_date
	) ); // phpcs:ignore

	$out = [];
	foreach ( (array) $rows as $r ) {
		$out[ (string) $r->category ] = (float) $r->total;
	}
	return $out;
}

/**
 * Percentage-based expenses (e.g. Stripe / credit-card commission) for the window.
 *
 * Each such expense is a RATE (stored in the `amount` column) applied to the
 * gross revenue of whatever period is being viewed — a commission is "X% of
 * sales, always", so it simply scales with the window's revenue. It uses the
 * same revenue basis the dashboard shows (marketplace orders excluded when
 * BrikMarket is active) so the figure reconciles with the Revenue card. There
 * is deliberately no per-expense date or schedule: a percentage applies every
 * period by its nature, which is why the editor hides those fields for it.
 *
 * @param string $start_gmt Y-m-d H:i:s (UTC)
 * @param string $end_gmt   Y-m-d H:i:s (UTC)
 * @return array{total:float,items:array<int,array{title:string,rate:float,amount:float}>}
 */
function brikpanel_profit_percent_expenses( $start_gmt, $end_gmt, $exclude_marketplace = null ) {
	global $wpdb;
	$out = [ 'total' => 0.0, 'items' => [] ];

	$table = $wpdb->prefix . 'brikpanel_expenses';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return $out;
	}
	if ( ! function_exists( 'brikpanel_get_total_revenue' ) ) {
		return $out; // revenue source not loaded (e.g. pure front-end context)
	}

	$rows = $wpdb->get_results(
		"SELECT category, amount FROM {$table} WHERE kind = 'percent' ORDER BY amount DESC"
	); // phpcs:ignore
	if ( empty( $rows ) ) {
		return $out;
	}

	// The commission must bite into the SAME revenue the rest of the snapshot
	// uses. Honour an explicit basis from the caller; fall back to the legacy
	// "exclude when BrikMarket is active" default for standalone callers.
	$exclude_mp = ( null === $exclude_marketplace )
		? ( function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active() )
		: (bool) $exclude_marketplace;
	$revenue    = (float) brikpanel_get_total_revenue( $start_gmt, $end_gmt, $exclude_mp );
	if ( $revenue <= 0 ) {
		return $out; // no sales in the window → nothing for a rate to bite into
	}

	foreach ( $rows as $r ) {
		$rate = (float) $r->amount;
		if ( $rate <= 0 ) {
			continue;
		}
		$amount = $revenue * ( $rate / 100 );
		if ( $amount <= 0 ) {
			continue;
		}
		$title = trim( (string) $r->category );
		if ( '' === $title ) {
			$title = __( 'Commission', 'brikpanel' );
		}
		$out['items'][] = [
			'title'  => $title,
			'rate'   => $rate,
			'amount' => $amount,
		];
		$out['total'] += $amount;
	}
	return $out;
}

/**
 * Store-currency ad spend split per platform for a local date range. Foreign-
 * currency spend is ignored (can't be converted reliably). Every known platform
 * key is always present (0.0 when absent) so callers can rely on the shape.
 * Empty array shape still holds when the Ad Platforms module is absent.
 *
 * @param string $start_local Y-m-d H:i:s
 * @param string $end_local   Y-m-d H:i:s
 * @return array<string,float> Keyed by platform slug (google_ads, meta_ads).
 */
function brikpanel_profit_ad_spend_by_platform( $start_local, $end_local ) {
	$out = [
		'google_ads' => 0.0,
		'meta_ads'   => 0.0,
	];
	if ( ! class_exists( 'Brikpanel_Ads_Store' ) ) {
		return $out;
	}
	$start_date = substr( (string) $start_local, 0, 10 );
	$end_date   = substr( (string) $end_local, 0, 10 );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
		return $out;
	}

	$store_cur = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
	if ( '' === $store_cur ) {
		return $out;
	}

	$rows = Brikpanel_Ads_Store::totals_for_range( $start_date, $end_date );
	foreach ( (array) $rows as $r ) {
		$platform = isset( $r['platform'] ) ? (string) $r['platform'] : '';
		if ( ! array_key_exists( $platform, $out ) ) {
			continue; // unknown platform — never silently fold into a known bucket
		}
		$cur = isset( $r['currency'] ) && $r['currency'] !== '' ? $r['currency'] : $store_cur;
		if ( $cur === $store_cur ) {
			$out[ $platform ] += (float) $r['spend'];
		}
	}

	return $out;
}

/**
 * Ad spend in the STORE currency for a local date range. Foreign-currency
 * spend is ignored (can't be converted reliably). 0 when the Ad Platforms
 * module is absent or has no data. This is the combined total across every
 * platform; the per-platform split lives in
 * brikpanel_profit_ad_spend_by_platform().
 *
 * @param string $start_local Y-m-d H:i:s
 * @param string $end_local   Y-m-d H:i:s
 * @return float
 */
function brikpanel_profit_ad_spend( $start_local, $end_local ) {
	$spend = (float) array_sum( brikpanel_profit_ad_spend_by_platform( $start_local, $end_local ) );

	/**
	 * Filter the store-currency ad spend that feeds the Profit section.
	 *
	 * @param float  $spend       Store-currency ad spend for the window.
	 * @param string $start_local Y-m-d H:i:s
	 * @param string $end_local   Y-m-d H:i:s
	 */
	return (float) apply_filters( 'brikpanel_profit_ad_spend', $spend, $start_local, $end_local );
}

/**
 * Full profit snapshot for one period. Revenue is passed in by the caller so
 * it always matches whatever "Total Sales" figure that surface already shows
 * (the dashboard never displays two different revenue numbers).
 *
 * The $exclude_marketplace flag MUST match the basis of the $revenue passed in:
 * if that revenue dropped BrikMarket-imported orders (the dashboard Revenue KPI
 * does on a marketplace store), pass true so COGS/tax/returns/coupons drop them
 * too. Mixing a store-only revenue with a marketplace-inclusive COGS is what
 * manufactured the permanent "loss" merchants reported.
 *
 * @param float  $revenue             Pre-computed revenue for the window.
 * @param string $start_gmt           Y-m-d H:i:s (UTC)
 * @param string $end_gmt             Y-m-d H:i:s (UTC)
 * @param string $start_local         Y-m-d H:i:s (site time)
 * @param string $end_local           Y-m-d H:i:s (site time)
 * @param bool   $exclude_marketplace Match the order basis of $revenue.
 * @return array
 */
function brikpanel_profit_snapshot( $revenue, $start_gmt, $end_gmt, $start_local, $end_local, $exclude_marketplace = false ) {
	$revenue  = (float) $revenue;
	$cogs     = brikpanel_profit_cogs( $start_gmt, $end_gmt, $exclude_marketplace );
	$coverage = brikpanel_profit_cogs_coverage( $start_gmt, $end_gmt, $exclude_marketplace );
	// Only resolve the "which products are missing a cost" list when there is
	// an actionable gap; skips the extra query on healthy stores.
	$missing_products = ( $revenue > 0 && (int) $coverage['missing_lines'] > 0 )
		? brikpanel_profit_cogs_missing_products( $start_gmt, $end_gmt, 20, $exclude_marketplace )
		: [];
	$tax      = brikpanel_profit_tax( $start_gmt, $end_gmt, $exclude_marketplace );
	$returns  = brikpanel_profit_returns( $start_gmt, $end_gmt, $exclude_marketplace );
	$coupons  = brikpanel_profit_coupons( $start_gmt, $end_gmt, $exclude_marketplace );
	$ads_by   = brikpanel_profit_ad_spend_by_platform( $start_local, $end_local );
	$ads      = brikpanel_profit_ad_spend( $start_local, $end_local );

	list( $exp_manual, $exp_inventory, $exp_other ) = brikpanel_profit_manual_expenses( $start_local, $end_local );
	$exp_by_category = brikpanel_profit_manual_expenses_by_category( $start_local, $end_local );
	$percent         = brikpanel_profit_percent_expenses( $start_gmt, $end_gmt, $exclude_marketplace );

	// Net revenue = gross sales minus what was handed back to customers. This
	// is the figure the dashboard's Revenue card shows and the basis for every
	// margin %, so margins reflect money actually kept, not money invoiced.
	// `revenue` (the gross, passed in) is preserved separately so surfaces that
	// must agree with the "Total Sales" KPI (and the Sheets snapshot's Revenue
	// column) keep showing gross.
	$revenue_net    = $revenue - $returns;
	// manual already includes inventory; percent (commission-style) costs scale
	// with revenue and are computed in brikpanel_profit_percent_expenses().
	$expenses_total = $tax + $ads + $exp_manual + $percent['total'];
	$net            = $revenue_net - $cogs - $expenses_total;

	// Percentages are share-of-net-revenue so they line up with the Revenue
	// figure actually displayed. Guard the zero/negative denominator.
	$pct = function ( $part ) use ( $revenue_net ) {
		return $revenue_net > 0 ? round( ( $part / $revenue_net ) * 100, 1 ) : 0.0;
	};

	return [
		'revenue_raw'        => $revenue,        // gross (Total Sales) — unchanged for Sheets/KPI parity
		'revenue_net_raw'    => $revenue_net,    // gross − returns (what the Revenue card shows)
		'returns_raw'        => $returns,
		'coupons_raw'        => $coupons,
		'cogs_raw'           => $cogs,
		'tax_raw'            => $tax,
		'ad_spend_raw'       => $ads,
		'exp_manual_raw'     => $exp_manual,
		'exp_inventory_raw'  => $exp_inventory,
		'exp_other_raw'      => $exp_other,
		'expenses_total_raw' => $expenses_total,
		'net_raw'            => $net,
		'has_cogs'           => $cogs > 0,
		// Data-quality signals so the UI never presents an over-optimistic
		// Net profit as hard fact. "incomplete" is the actionable one:
		// there is revenue, but part of it has no cost on file.
		'cogs_coverage_pct'    => $coverage['coverage_pct'],
		'cogs_missing_lines'   => $coverage['missing_lines'],
		'cogs_incomplete'      => ( $revenue > 0 && $coverage['missing_lines'] > 0 && $coverage['coverage_pct'] < 99.5 ),
		'cogs_missing_products' => $missing_products,
		'cogs_pct'           => $pct( $cogs ),
		'expenses_pct'       => $pct( $expenses_total ),
		'margin'             => $pct( $net ),
		// Ordered components that sum to expenses_total. Inventory + Other
		// together equal the manual expenses table; ad spend (split per
		// platform) + Tax are external. Per-platform keys keep the dashboard
		// breakdown honest about where the ad budget actually went; empty
		// platforms are dropped downstream so a single-platform store still
		// sees just one line.
		'breakdown'          => [
			'google_ads' => $ads_by['google_ads'],
			'meta_ads'   => $ads_by['meta_ads'],
			'tax'        => $tax,
			'inventory'  => $exp_inventory,
			'other'      => $exp_other,
		],
		// Manual expenses split by their own category (Salaries, Rent, …) so the
		// dashboard breakdown can list each instead of one "Other" lump. Summing
		// these equals exp_manual; the inventory + other split above is kept for
		// the Sheets snapshot columns.
		'expense_categories' => $exp_by_category,
		// Percentage-based costs (card commission etc.): each item is
		// {title, rate, amount} where amount = rate% × applicable gross revenue.
		'percent_expenses'   => $percent['items'],
	];
}
