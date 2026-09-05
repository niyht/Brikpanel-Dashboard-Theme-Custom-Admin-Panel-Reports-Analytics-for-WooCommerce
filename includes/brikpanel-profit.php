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
 * Source of truth is WooCommerce's native `_cogs_total_value` product/
 * variation meta, with BrikPanel's legacy `_brikpanel_cogs` as fallback for
 * values that only ever existed on the legacy key (both keys are kept in
 * lockstep by the live mirror and the one-time unification pass, and the
 * raw meta read works whether or not the WC COGS feature flag is on).
 * Multiplied by quantity sold; works for BOTH simple and variable products:
 * variation lines prefer the variation's own cost and fall back to the
 * parent product's cost (or ADD to it when the variation is flagged
 * additive). Reads current cost (not a sale-time snapshot) so past orders
 * gain a cost the moment it is filled in.
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

	// Cost meta joins are generated from the central key list (WooCommerce
	// native, BrikPanel legacy, plus any detected third-party cost plugin) so
	// the aggregate resolves cost exactly like brikpanel_product_cogs_raw()
	// does per product. Stores without such a plugin get the same two joins
	// they always had.
	$vcost = brikpanel_cogs_sql_join_set( 'vc', 'CAST(vid.meta_value AS UNSIGNED)', 'CAST(vid.meta_value AS UNSIGNED) > 0' );
	$pcost = brikpanel_cogs_sql_join_set( 'pc', 'CAST(pid.meta_value AS UNSIGNED)' );
	$vjoin = $vcost['joins'];
	$pjoin = $pcost['joins'];

	$joins = "
		INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
				ON oi.order_id = %ORDER_ID% AND oi.order_item_type = 'line_item'
		INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty
				ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
		LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta pid
				ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
		LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta vid
				ON vid.order_item_id = oi.order_item_id AND vid.meta_key = '_variation_id'
		LEFT JOIN  {$wpdb->postmeta} vadd
				ON vadd.post_id = CAST(vid.meta_value AS UNSIGNED)
			   AND vadd.meta_key = '_cogs_value_is_additive'
			   AND CAST(vid.meta_value AS UNSIGNED) > 0{$vjoin}{$pjoin}";

	// Unit cost resolution — WooCommerce's native `_cogs_total_value` is the
	// source of truth, BrikPanel's legacy `_brikpanel_cogs` is the fallback
	// (kept in lockstep by the mirror + one-time unification, so pre-existing
	// installs report identical numbers). A variation flagged additive
	// (`_cogs_value_is_additive` = yes, WC 9.5+) adds its own cost ON TOP of
	// the parent's instead of replacing it.
	$vval = $vcost['value'];
	$pval = $pcost['value'];
	$unit = "CASE WHEN vadd.meta_value = 'yes'
				THEN CAST(COALESCE({$vval}, '0') AS DECIMAL(20,4)) + CAST(COALESCE({$pval}, '0') AS DECIMAL(20,4))
				ELSE CAST(COALESCE({$vval}, {$pval}, '0') AS DECIMAL(20,4)) END";

	$sum = "COALESCE(SUM(
			CAST(qty.meta_value AS DECIMAL(20,4)) * {$unit}
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

	// Cost meta joins are generated from the central key list (WooCommerce
	// native, BrikPanel legacy, plus any detected third-party cost plugin) so
	// the aggregate resolves cost exactly like brikpanel_product_cogs_raw()
	// does per product. Stores without such a plugin get the same two joins
	// they always had.
	$vcost = brikpanel_cogs_sql_join_set( 'vc', 'CAST(vid.meta_value AS UNSIGNED)', 'CAST(vid.meta_value AS UNSIGNED) > 0' );
	$pcost = brikpanel_cogs_sql_join_set( 'pc', 'CAST(pid.meta_value AS UNSIGNED)' );
	$vjoin = $vcost['joins'];
	$pjoin = $pcost['joins'];

	$joins = "
		INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
				ON oi.order_id = %ORDER_ID% AND oi.order_item_type = 'line_item'
		LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta lt
				ON lt.order_item_id = oi.order_item_id AND lt.meta_key = '_line_total'
		LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta pid
				ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
		LEFT JOIN  {$wpdb->prefix}woocommerce_order_itemmeta vid
				ON vid.order_item_id = oi.order_item_id AND vid.meta_key = '_variation_id'
		LEFT JOIN  {$wpdb->postmeta} vadd
				ON vadd.post_id = CAST(vid.meta_value AS UNSIGNED)
			   AND vadd.meta_key = '_cogs_value_is_additive'
			   AND CAST(vid.meta_value AS UNSIGNED) > 0{$vjoin}{$pjoin}";

	// A line is "covered" when EITHER the variation or its parent has a cost
	// row recorded — even an explicit 0 (free sample, complimentary item,
	// digital good with no per-unit cost) is a valid answer the merchant
	// chose, not an absence. Missing strictly means "no cost meta exists on
	// file". The save handler deletes the meta when the field is cleared, so
	// a present row with an empty string is a legacy edge case we still treat
	// as missing for defensiveness.
	$has_cost = "({$vcost['value']} IS NOT NULL OR {$pcost['value']} IS NOT NULL)";
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

	// Cost meta joins are generated from the central key list (WooCommerce
	// native, BrikPanel legacy, plus any detected third-party cost plugin) so
	// the aggregate resolves cost exactly like brikpanel_product_cogs_raw()
	// does per product. Stores without such a plugin get the same two joins
	// they always had.
	$vcost = brikpanel_cogs_sql_join_set( 'vc', 'CAST(vid.meta_value AS UNSIGNED)', 'CAST(vid.meta_value AS UNSIGNED) > 0' );
	$pcost = brikpanel_cogs_sql_join_set( 'pc', 'CAST(pid.meta_value AS UNSIGNED)' );
	$vjoin = $vcost['joins'];
	$pjoin = $pcost['joins'];

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
		LEFT JOIN  {$wpdb->postmeta} vadd
				ON vadd.post_id = CAST(vid.meta_value AS UNSIGNED)
			   AND vadd.meta_key = '_cogs_value_is_additive'
			   AND CAST(vid.meta_value AS UNSIGNED) > 0{$vjoin}{$pjoin}
		LEFT JOIN  {$wpdb->posts} pp
				ON pp.ID = CAST(pid.meta_value AS UNSIGNED)";

	// "Missing" follows the same rule as the coverage helper: no cost row on
	// either side. Explicit 0 is treated as a deliberate cost — not missing.
	$missing_clause = "NOT ({$vcost['value']} IS NOT NULL OR {$pcost['value']} IS NOT NULL)";

	// Group by the *resolvable* product when one exists, otherwise fall back to
	// the order_item_name so unlinked rows for the same item collapse into a
	// single entry instead of spamming the tooltip with one row per order.
	// The two cases share a column shape so the caller can iterate uniformly.
	$group_key  = "COALESCE(NULLIF(pp.post_title, ''), oi.order_item_name)";
	// Every non-aggregate is wrapped, product_id included. The grouping key is
	// the product TITLE, so a bare `pid.meta_value` is not functionally
	// dependent on it and a host running ONLY_FULL_GROUP_BY (common on managed
	// WordPress hosting) rejected the whole query — which silently emptied the
	// "products with no cost on file" list on the dashboard profit card. Rows
	// in one group are the same product, so MAX() picks the same id the bare
	// column used to return, and skips the NULLs an unlinked line item leaves.
	$select     = "
		MAX(CAST(pid.meta_value AS UNSIGNED)) AS product_id,
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
 * Order meta key holding a per-order shipping cost that overrides the amount
 * charged to the customer. Absent/empty means "no override"; a stored 0 is a
 * real value ("this one cost me nothing to ship") and is honoured.
 */
const BRIKPANEL_SHIPPING_COST_META = '_brikpanel_shipping_cost';

/**
 * Whether Net profit should be reduced by a shipping cost at all.
 *
 * Off by default: switching it on changes the Net profit figure of every
 * existing store, so it has to be a deliberate choice rather than something an
 * update does to a merchant behind their back.
 *
 * @return bool
 */
function brikpanel_shipping_cost_enabled() {
	return 'yes' === get_option( 'brikpanel_shipping_cost_enabled', 'no' );
}

/**
 * Option name behind brikpanel_shipping_cost_enabled().
 */
const BRIKPANEL_SHIPPING_COST_OPTION = 'brikpanel_shipping_cost_enabled';

// Flipping this switch changes Net profit, Expenses and the margin, but the
// dashboard serves a whole-response transient that is only invalidated by order
// events. Without this the merchant turns the setting off, returns to the
// dashboard and still sees the shipping cost deducted until the cache expires,
// which reads as "the setting does nothing". Mirrors how the paid/refunded
// status buckets invalidate on change (includes/brikpanel-helpers.php).
add_action( 'update_option_' . BRIKPANEL_SHIPPING_COST_OPTION, 'brikpanel_bust_data_caches' );
add_action( 'add_option_' . BRIKPANEL_SHIPPING_COST_OPTION, 'brikpanel_bust_data_caches' );

/**
 * Shipping cost for paid orders inside [$start_gmt, $end_gmt].
 *
 * WooCommerce has no field for what the merchant paid the courier — the only
 * shipping figure it stores is what was CHARGED to the customer. So that amount
 * is what we treat as the cost, which makes shipping profit-neutral by default
 * (it is added to Revenue and taken straight back out here). A merchant who
 * knows the real figure for an order can put it in the per-order override
 * (BRIKPANEL_SHIPPING_COST_META), which is the only way to account for an order
 * shipped free of charge: nothing was charged, so nothing would be deducted.
 *
 * Refunds deliberately do NOT reduce this: the courier was paid whether or not
 * the customer was later given their money back.
 *
 * Works identically for simple and variable products — shipping lives on the
 * order, not the line item.
 *
 * Uses the same paid-status set, admin-order exclusion and marketplace basis as
 * every other Profit component, so it measures exactly the orders the Revenue
 * card measures.
 *
 * @param string $start_gmt           Y-m-d H:i:s (UTC)
 * @param string $end_gmt             Y-m-d H:i:s (UTC)
 * @param bool   $exclude_marketplace Match the order basis of the revenue figure.
 * @return float
 */
function brikpanel_profit_shipping_cost( $start_gmt, $end_gmt, $exclude_marketplace = false ) {
	global $wpdb;

	if ( ! brikpanel_shipping_cost_enabled() ) {
		return 0.0; // not a single query on stores that never turned this on
	}

	$is_hpos  = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
	$statuses = brikpanel_paid_order_statuses();
	$sp       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
	$ovr_key  = BRIKPANEL_SHIPPING_COST_META;
	// The multi-currency module may not be loaded in every context that reads a
	// snapshot (e.g. the Sheets sync bootstraps profit.php on its own), so fall
	// back to the literal key rather than relying on the constant being defined.
	$fx_key   = defined( 'BRIKPANEL_BASE_TOTAL_META' ) ? BRIKPANEL_BASE_TOTAL_META : '_brikpanel_base_total';

	if ( $is_hpos ) {
		// COALESCE order matters: an override wins over the charged amount, and
		// NULLIF lets an empty-string meta fall through while a stored '0' does
		// not.
		$cost  = "CAST(COALESCE(NULLIF(ovr.meta_value, ''), od.shipping_total_amount, '0') AS DECIMAL(20,4))";
		$total = 'o.total_amount';
		$sql   = "SELECT COALESCE(SUM(%COST_EXPR%), 0)
			FROM {$wpdb->prefix}wc_orders o
			LEFT JOIN {$wpdb->prefix}wc_order_operational_data od ON od.order_id = o.id
			LEFT JOIN {$wpdb->prefix}wc_orders_meta ovr ON ovr.order_id = o.id AND ovr.meta_key = %s
			LEFT JOIN {$wpdb->prefix}wc_orders_meta bpfx ON bpfx.order_id = o.id AND bpfx.meta_key = %s
			WHERE o.type = 'shop_order' AND o.status IN ($sp)
			  AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s";
		$args  = array_merge( [ $ovr_key, $fx_key ], $statuses, [ $start_gmt, $end_gmt ] );
		$excl  = brikpanel_admin_order_exclusion_sql( true );
	} else {
		$cost  = "CAST(COALESCE(NULLIF(ovr.meta_value, ''), sh.meta_value, '0') AS DECIMAL(20,4))";
		$total = "CAST(COALESCE(tot.meta_value, '0') AS DECIMAL(20,4))";
		$sql   = "SELECT COALESCE(SUM(%COST_EXPR%), 0)
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} sh   ON sh.post_id   = p.ID AND sh.meta_key   = '_order_shipping'
			LEFT JOIN {$wpdb->postmeta} tot  ON tot.post_id  = p.ID AND tot.meta_key  = '_order_total'
			LEFT JOIN {$wpdb->postmeta} ovr  ON ovr.post_id  = p.ID AND ovr.meta_key  = %s
			LEFT JOIN {$wpdb->postmeta} bpfx ON bpfx.post_id = p.ID AND bpfx.meta_key = %s
			WHERE p.post_type = 'shop_order' AND p.post_status IN ($sp)
			  AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s";
		$args  = array_merge( [ $ovr_key, $fx_key ], $statuses, [ $start_gmt, $end_gmt ] );
		$excl  = brikpanel_admin_order_exclusion_sql( false, 'p.ID' );
	}

	// Multi-currency: the store keeps the base-currency equivalent of the whole
	// order total, not an exchange rate, so the order's own effective rate is
	// derived as base_total / total and applied to the shipping component. The
	// meta only exists on orders that needed converting, so a single-currency
	// store always takes the ELSE branch and is numerically untouched.
	$cost_expr = "CASE
			WHEN bpfx.meta_value IS NOT NULL AND bpfx.meta_value <> '' AND {$total} > 0
			THEN {$cost} * ( CAST(bpfx.meta_value AS DECIMAL(20,4)) / {$total} )
			ELSE {$cost}
		END";
	$sql = str_replace( '%COST_EXPR%', $cost_expr, $sql );

	if ( ! empty( $excl['sql'] ) ) {
		$sql .= $excl['sql'];
		$args = array_merge( $args, $excl['args'] );
	}

	$mp = brikpanel_profit_marketplace_excl( $exclude_marketplace, $is_hpos, $is_hpos ? 'o.id' : 'p.ID' );
	if ( ! empty( $mp['sql'] ) ) {
		$sql .= $mp['sql'];
		$args = array_merge( $args, $mp['args'] );
	}

	$shipping = (float) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore

	/**
	 * Filter the shipping cost that nets down Net profit.
	 *
	 * The natural place for an integration that DOES know the real carrier
	 * charge (a label-printing plugin, a 3PL feed) to replace the estimate.
	 *
	 * @param float  $shipping  Shipping cost for the window.
	 * @param string $start_gmt Y-m-d H:i:s (UTC)
	 * @param string $end_gmt   Y-m-d H:i:s (UTC)
	 */
	return (float) apply_filters( 'brikpanel_profit_shipping_cost', $shipping, $start_gmt, $end_gmt );
}

/**
 * Order meta keys the payment gateways already write their own transaction fee
 * into. Reading these is the whole integration: no API key, no HTTP call, no
 * per-gateway adapter — the number is on the order by the time we look.
 *
 * Two of the four keys are shared by more than one plugin, which is why this
 * list stays short: `_stripe_fee` is written by both the official WooCommerce
 * Stripe Gateway and Payment Plugins' Stripe, and `PayPal Transaction Fee` by
 * both the official PayPal Payments plugin and WooCommerce's own built-in
 * PayPal Standard IPN/PDT handler. `Stripe Fee` is the legacy key still sitting
 * on orders taken by older versions of the official plugin.
 *
 * Gateways that store no fee at all (Mollie, Square, Klarna) simply contribute
 * nothing; the component degrades to zero rather than guessing.
 *
 * @return string[]
 */
function brikpanel_payment_fee_meta_keys() {
	/**
	 * Filter the order meta keys scanned for a payment processing fee.
	 *
	 * The seam for a gateway BrikPanel does not know about: a single added key
	 * is all it takes, which is what stops this from ever becoming a per-plugin
	 * integration surface. Keys are read in order and the first non-empty one
	 * wins per order, so put the more specific key first.
	 *
	 * @param string[] $keys Order meta keys holding a processing fee.
	 */
	return (array) apply_filters(
		'brikpanel_payment_fee_meta_keys',
		[
			'_stripe_fee',
			'Stripe Fee',
			'_fkwcs_stripe_fee',
			'PayPal Transaction Fee',
			'_paypal_fee',
			'_wcpay_transaction_fee',
		]
	);
}

/**
 * Order meta key holding the currency a Stripe fee is denominated in. This is
 * the STRIPE ACCOUNT's currency, not the order's — the two differ for any
 * merchant selling in a currency their Stripe account does not settle in.
 *
 * Kept for back-compat: it is the currency key for the OFFICIAL Stripe gateway
 * only. The authoritative per-key map is
 * brikpanel_payment_fee_currency_meta_keys().
 */
const BRIKPANEL_PAYMENT_FEE_CURRENCY_META = '_stripe_currency';

/**
 * Which currency meta key describes each fee meta key.
 *
 * A fee is only comparable to the order total when the two are denominated in
 * the same currency, and each gateway records that differently — the official
 * Stripe gateway writes `_stripe_currency`, FunnelKit's writes its own
 * `_fkwcs_stripe_currency`, and the PayPal plugins write none at all because
 * they settle the fee in the order's own currency. Reading one global key for
 * every gateway (what this used to do) silently compared a FunnelKit fee
 * against the OFFICIAL Stripe plugin's currency meta — absent on those stores,
 * so every fee was treated as "same currency" whether it was or not.
 *
 * A fee key absent from this map, or mapped to '', is taken as already being in
 * the order's currency. That is the correct default: it is what a gateway with
 * no currency meta means, and it keeps single-currency stores at zero cost.
 *
 * @return array<string,string> fee meta key => currency meta key ('' = order currency).
 */
function brikpanel_payment_fee_currency_meta_keys() {
	/**
	 * Filter the fee-key => currency-key map.
	 *
	 * Pair this with brikpanel_payment_fee_meta_keys when the gateway you are
	 * adding settles its fee in an account currency of its own.
	 *
	 * @param array<string,string> $map Fee meta key => currency meta key.
	 */
	return (array) apply_filters(
		'brikpanel_payment_fee_currency_meta_keys',
		[
			'_stripe_fee'            => BRIKPANEL_PAYMENT_FEE_CURRENCY_META,
			'Stripe Fee'             => BRIKPANEL_PAYMENT_FEE_CURRENCY_META,
			'_fkwcs_stripe_fee'      => '_fkwcs_stripe_currency',
			'PayPal Transaction Fee' => '',
			'_paypal_fee'            => '',
			'_wcpay_transaction_fee' => '',
		]
	);
}

/**
 * Option name behind brikpanel_payment_fees_enabled().
 */
const BRIKPANEL_PAYMENT_FEES_OPTION = 'brikpanel_payment_fees_enabled';

/**
 * Whether real gateway transaction fees are counted as an expense.
 *
 * The stored default is written on upgrade (brikpanel_enable_payment_fees_default
 * in brikpanel.php) rather than being a fallback here: a fallback would silently
 * re-enable the component for a merchant who deliberately turned it off, which
 * is exactly what an option row exists to prevent.
 *
 * @return bool
 */
function brikpanel_payment_fees_enabled() {
	return 'yes' === get_option( BRIKPANEL_PAYMENT_FEES_OPTION, 'no' );
}

// Flipping this changes Expenses, Net profit and the margin, but the dashboard
// serves a whole-response transient that only order events invalidate. Same
// reasoning as the shipping-cost toggle above.
add_action( 'update_option_' . BRIKPANEL_PAYMENT_FEES_OPTION, 'brikpanel_bust_data_caches' );
add_action( 'add_option_' . BRIKPANEL_PAYMENT_FEES_OPTION, 'brikpanel_bust_data_caches' );

/**
 * Invalidate the cached dashboard payload when a gateway writes its fee onto an
 * order after the fact.
 *
 * Stripe resolves the fee from a balance transaction asynchronously, so it lands
 * minutes to hours AFTER checkout — long after woocommerce_new_order and the
 * status transition have already busted the caches. HPOS fires no *_order_meta
 * action at all (only woocommerce_update_order, which the legacy store fires
 * too), so this is the one hook that covers both storage engines.
 *
 * Deliberately narrow: an unguarded bust here would fire on every order edit and
 * defeat the cache entirely. A fee can only be written to a recent order, and an
 * older order's edits already bust through the status hooks.
 *
 * @param int           $order_id
 * @param WC_Order|null $order
 * @return void
 */
function brikpanel_bust_data_caches_on_fee_meta( $order_id, $order = null ) {
	if ( ! function_exists( 'brikpanel_bust_data_caches' ) ) {
		return;
	}
	if ( ! $order instanceof WC_Order ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	}
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$created = $order->get_date_created();
	if ( ! $created || ( time() - $created->getTimestamp() ) > 7 * DAY_IN_SECONDS ) {
		return;
	}
	// No DB read: the caller already hydrated this object.
	foreach ( brikpanel_payment_fee_meta_keys() as $key ) {
		if ( '' !== (string) $order->get_meta( $key ) ) {
			brikpanel_bust_data_caches();
			return;
		}
	}
}
add_action( 'woocommerce_update_order', 'brikpanel_bust_data_caches_on_fee_meta', 99, 2 );

/**
 * Payment processing fees actually charged by the gateway on paid orders inside
 * [$start_gmt, $end_gmt].
 *
 * Unlike a percentage-of-revenue expense row (the only way to account for card
 * commission before this existed) these are the real per-order amounts Stripe,
 * PayPal or WooPayments deducted, so foreign-card surcharges, currency spreads
 * and refund adjustments are all already baked in.
 *
 * Uses the same paid-status set, admin-order exclusion and marketplace basis as
 * every other Profit component, so it measures exactly the orders the Revenue
 * card measures.
 *
 * Multi-currency is the subtle part, and it is why the fee currency is inspected
 * at all rather than the amount being summed at face value:
 *
 *   - No fee-currency meta, or it matches the ORDER's currency (PayPal always,
 *     and Stripe whenever the account settles in the sale currency): the amount
 *     is in the order's currency, so the same base_total/total ratio
 *     brikpanel_profit_shipping_cost() uses converts it correctly.
 *   - The fee currency is a THIRD currency (Stripe account settling in GBP on a
 *     store selling EUR): the order's own ratio is the wrong factor by
 *     construction — it is literally total_base/total_order — so those rows are
 *     pulled out and converted per currency from the merchant's rate table.
 *   - No rate available for that currency: the fee is EXCLUDED and counted in
 *     `unconverted_orders`. Leaving it raw would add pounds to a euro total,
 *     which is not a smaller error than omitting it, it is a wrong number. The
 *     count is surfaced so the shortfall is disclosed rather than silent.
 *
 * A single-currency store never reaches the second query and is numerically
 * identical to summing the raw meta.
 *
 * Works identically for simple and variable products: a processing fee is
 * charged on the order, not on the line item.
 *
 * @param string $start_gmt           Y-m-d H:i:s (UTC)
 * @param string $end_gmt             Y-m-d H:i:s (UTC)
 * @param bool   $exclude_marketplace Match the order basis of the revenue figure.
 * @return array{total:float,orders:int,orders_with_fee:int,unconverted_orders:int,coverage_pct:float}
 */
function brikpanel_profit_payment_fees( $start_gmt, $end_gmt, $exclude_marketplace = false ) {
	global $wpdb;

	$out = [
		'total'              => 0.0,
		'orders'             => 0,
		'orders_with_fee'    => 0,
		'unconverted_orders' => 0,
		'coverage_pct'       => 0.0,
	];

	if ( ! brikpanel_payment_fees_enabled() ) {
		return $out; // not a single query on stores that turned this off
	}

	$keys = array_values( array_filter( array_map( 'strval', brikpanel_payment_fee_meta_keys() ) ) );
	if ( empty( $keys ) ) {
		return $out;
	}

	$is_hpos  = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
	$statuses = brikpanel_paid_order_statuses();
	$sp       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
	// The multi-currency module may not be loaded in every context that reads a
	// snapshot (the Sheets sync bootstraps profit.php on its own), so fall back
	// to the literal key rather than relying on the constant being defined.
	$fx_key   = defined( 'BRIKPANEL_BASE_TOTAL_META' ) ? BRIKPANEL_BASE_TOTAL_META : '_brikpanel_base_total';

	// Currency meta is per gateway, not global — see
	// brikpanel_payment_fee_currency_meta_keys(). Join each DISTINCT currency key
	// once and remember which alias serves which fee key, so a store running two
	// Stripe plugins does not join `_stripe_currency` twice.
	$cur_map     = brikpanel_payment_fee_currency_meta_keys();
	$cur_keys    = [];        // ordered list of distinct currency meta keys
	$cur_alias   = [];        // fee key index => currency alias, or '' when none
	foreach ( $keys as $i => $fee_key ) {
		$ck = isset( $cur_map[ $fee_key ] ) ? (string) $cur_map[ $fee_key ] : '';
		if ( '' === $ck ) {
			$cur_alias[ $i ] = '';
			continue;
		}
		$pos = array_search( $ck, $cur_keys, true );
		if ( false === $pos ) {
			$cur_keys[] = $ck;
			$pos        = count( $cur_keys ) - 1;
		}
		$cur_alias[ $i ] = 'fc' . $pos;
	}

	// One JOIN alias PER KEY. A single alias with `meta_key IN (...)` looks
	// tidier and is wrong: an order carrying both `_stripe_fee` and the legacy
	// `Stripe Fee` would fan out to two rows and its fee would be counted twice.
	$joins = '';
	foreach ( $keys as $i => $unused_key ) {
		$joins .= $is_hpos
			? " LEFT JOIN {$wpdb->prefix}wc_orders_meta f{$i} ON f{$i}.order_id = o.id AND f{$i}.meta_key = %s"
			: " LEFT JOIN {$wpdb->postmeta} f{$i} ON f{$i}.post_id = p.ID AND f{$i}.meta_key = %s";
	}

	$coalesce = [];
	$present  = [];
	foreach ( array_keys( $keys ) as $i ) {
		// NULLIF lets an empty-string meta fall through to the next key while a
		// stored '0' does not — a gateway that genuinely charged nothing is
		// covered data, not missing data.
		$coalesce[] = "NULLIF(f{$i}.meta_value, '')";
		$present[]  = "f{$i}.meta_value IS NOT NULL";
	}
	$raw_fee = 'COALESCE(' . implode( ', ', $coalesce ) . ", '0')";
	$has_fee = '(' . implode( ' OR ', $present ) . ')';

	// The currency that belongs to whichever key actually supplied the fee. Walks
	// the keys in the SAME priority order as the COALESCE above, so the currency
	// can never come from a different gateway than the amount did. A key with no
	// currency meta yields NULL, which $own_cur reads as "order currency".
	$cur_when = '';
	foreach ( array_keys( $keys ) as $i ) {
		$expr      = '' !== $cur_alias[ $i ] ? "{$cur_alias[ $i ]}.meta_value" : 'NULL';
		$cur_when .= " WHEN NULLIF(f{$i}.meta_value, '') IS NOT NULL THEN {$expr}";
	}
	$fee_cur = "CASE{$cur_when} ELSE NULL END";
	// ABS because several gateways store the deduction as a negative number (the
	// order screen renders it as "-0.55"). An expense component must be positive
	// or it would ADD to Net profit.
	$fee_amt = "ABS(CAST({$raw_fee} AS DECIMAL(20,4)))";

	$cur_joins = '';
	foreach ( array_keys( $cur_keys ) as $ci ) {
		$cur_joins .= $is_hpos
			? " LEFT JOIN {$wpdb->prefix}wc_orders_meta fc{$ci} ON fc{$ci}.order_id = o.id AND fc{$ci}.meta_key = %s"
			: " LEFT JOIN {$wpdb->postmeta} fc{$ci} ON fc{$ci}.post_id = p.ID AND fc{$ci}.meta_key = %s";
	}

	if ( $is_hpos ) {
		$total    = 'o.total_amount';
		$order_cur = 'o.currency';
		$from     = "FROM {$wpdb->prefix}wc_orders o"
			. $joins
			. $cur_joins
			. " LEFT JOIN {$wpdb->prefix}wc_orders_meta bpfx ON bpfx.order_id = o.id AND bpfx.meta_key = %s";
		$where    = " WHERE o.type = 'shop_order' AND o.status IN ($sp)"
			. ' AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s';
		$excl     = brikpanel_admin_order_exclusion_sql( true );
	} else {
		$total     = "CAST(COALESCE(tot.meta_value, '0') AS DECIMAL(20,4))";
		$order_cur = 'ocur.meta_value';
		$from      = "FROM {$wpdb->posts} p"
			. $joins
			. $cur_joins
			. " LEFT JOIN {$wpdb->postmeta} bpfx ON bpfx.post_id = p.ID AND bpfx.meta_key = %s"
			. " LEFT JOIN {$wpdb->postmeta} tot ON tot.post_id = p.ID AND tot.meta_key = '_order_total'"
			. " LEFT JOIN {$wpdb->postmeta} ocur ON ocur.post_id = p.ID AND ocur.meta_key = '_order_currency'";
		$where     = " WHERE p.post_type = 'shop_order' AND p.post_status IN ($sp)"
			. ' AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s';
		$excl      = brikpanel_admin_order_exclusion_sql( false, 'p.ID' );
	}

	// True when the fee is denominated in the order's own currency, which is the
	// case the order-level base_total/total ratio is valid for. Never NULL: the
	// IS NULL test short-circuits the OR before the comparison can go unknown.
	$own_cur = "({$fee_cur} IS NULL OR {$fee_cur} = '' OR UPPER({$fee_cur}) = UPPER({$order_cur}))";
	// Same conversion shape as brikpanel_profit_shipping_cost(): the store keeps
	// the base-currency equivalent of the whole order total, not a rate, so the
	// order's effective rate is derived as base_total/total. The meta only exists
	// on orders that needed converting, so a single-currency store always takes
	// the ELSE branch and is numerically untouched.
	$fee_expr = "CASE
			WHEN bpfx.meta_value IS NOT NULL AND bpfx.meta_value <> '' AND {$total} > 0
			THEN {$fee_amt} * ( CAST(bpfx.meta_value AS DECIMAL(20,4)) / {$total} )
			ELSE {$fee_amt}
		END";

	// $wpdb->prepare() binds POSITIONALLY in the order the % tokens appear in the
	// string. The SELECT list carries none, so the JOIN meta keys come first.
	$meta_args = array_merge( $keys, $cur_keys, [ $fx_key ] );
	$args      = array_merge( $meta_args, $statuses, [ $start_gmt, $end_gmt ] );

	$sql = "SELECT COUNT(*) AS orders,
			COALESCE(SUM(CASE WHEN {$has_fee} THEN 1 ELSE 0 END), 0) AS orders_with_fee,
			COALESCE(SUM(CASE WHEN {$has_fee} AND {$own_cur} THEN {$fee_expr} ELSE 0 END), 0) AS fee_total,
			COALESCE(SUM(CASE WHEN {$has_fee} AND NOT {$own_cur} THEN 1 ELSE 0 END), 0) AS foreign_orders
		{$from}{$where}";

	if ( ! empty( $excl['sql'] ) ) {
		$sql   = $sql . $excl['sql'];
		$args  = array_merge( $args, $excl['args'] );
	}

	$mp = brikpanel_profit_marketplace_excl( $exclude_marketplace, $is_hpos, $is_hpos ? 'o.id' : 'p.ID' );
	if ( ! empty( $mp['sql'] ) ) {
		$sql  .= $mp['sql'];
		$args  = array_merge( $args, $mp['args'] );
	}

	$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore
	if ( $row ) {
		$out['total']           = (float) $row->fee_total;
		$out['orders']          = (int) $row->orders;
		$out['orders_with_fee'] = (int) $row->orders_with_fee;
	}

	// Second pass, only for fees denominated in neither the order currency nor
	// (necessarily) the store's. Grouped by currency so one rate lookup covers
	// every order settled in it.
	if ( $row && (int) $row->foreign_orders > 0 ) {
		$g_sql  = "SELECT UPPER({$fee_cur}) AS fee_currency,
				COALESCE(SUM({$fee_amt}), 0) AS fee_total,
				COUNT(*) AS orders
			{$from}{$where} AND {$has_fee} AND NOT {$own_cur}";
		$g_args = array_merge( $meta_args, $statuses, [ $start_gmt, $end_gmt ] );
		if ( ! empty( $excl['sql'] ) ) {
			$g_sql  .= $excl['sql'];
			$g_args  = array_merge( $g_args, $excl['args'] );
		}
		if ( ! empty( $mp['sql'] ) ) {
			$g_sql  .= $mp['sql'];
			$g_args  = array_merge( $g_args, $mp['args'] );
		}
		$g_sql .= " GROUP BY UPPER({$fee_cur})";

		$base  = function_exists( 'brikpanel_base_currency' )
			? strtoupper( (string) brikpanel_base_currency() )
			: strtoupper( (string) get_option( 'woocommerce_currency' ) );
		$groups = (array) $wpdb->get_results( $wpdb->prepare( $g_sql, $g_args ) ); // phpcs:ignore

		foreach ( $groups as $g ) {
			$code   = strtoupper( (string) $g->fee_currency );
			$amount = (float) $g->fee_total;
			if ( $code === $base ) {
				$out['total'] += $amount; // already the reporting currency
				continue;
			}
			$factor = function_exists( 'brikpanel_manual_fx_factor' )
				? (float) brikpanel_manual_fx_factor( $code )
				: 0.0;
			if ( $factor > 0 ) {
				$out['total'] += $amount * $factor;
			} else {
				$out['unconverted_orders'] += (int) $g->orders;
			}
		}
	}

	$out['coverage_pct'] = $out['orders'] > 0
		? round( ( $out['orders_with_fee'] / $out['orders'] ) * 100, 1 )
		: 0.0;

	/**
	 * Filter the payment processing fees that net down Net profit.
	 *
	 * Receives the whole result, not just the total, so an integration that
	 * knows better can correct the coverage counts alongside the money instead
	 * of leaving the disclosure disagreeing with the figure.
	 *
	 * @param array  $out       Fee total plus coverage counts.
	 * @param string $start_gmt Y-m-d H:i:s (UTC)
	 * @param string $end_gmt   Y-m-d H:i:s (UTC)
	 */
	return (array) apply_filters( 'brikpanel_profit_payment_fees', $out, $start_gmt, $end_gmt );
}

/**
 * Shipping revenue (what customers were charged) for paid orders in the window.
 *
 * Not part of the Net profit maths — it is already inside Revenue. Kept as a
 * public helper for surfaces that want to report delivery income on its own; it
 * has no caller inside the plugin, so nothing pays for it unless it is used.
 *
 * @param string $start_gmt Y-m-d H:i:s (UTC)
 * @param string $end_gmt   Y-m-d H:i:s (UTC)
 * @return float
 */
function brikpanel_profit_shipping_revenue( $start_gmt, $end_gmt, $exclude_marketplace = false ) {
	global $wpdb;

	$is_hpos  = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
	$statuses = brikpanel_paid_order_statuses();
	$sp       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

	if ( $is_hpos ) {
		$sql  = "SELECT COALESCE(SUM(od.shipping_total_amount), 0)
			FROM {$wpdb->prefix}wc_orders o
			LEFT JOIN {$wpdb->prefix}wc_order_operational_data od ON od.order_id = o.id
			WHERE o.type = 'shop_order' AND o.status IN ($sp)
			  AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s";
		$args = array_merge( $statuses, [ $start_gmt, $end_gmt ] );
		$excl = brikpanel_admin_order_exclusion_sql( true );
	} else {
		$sql  = "SELECT COALESCE(SUM(CAST(COALESCE(sh.meta_value, '0') AS DECIMAL(20,4))), 0)
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} sh ON sh.post_id = p.ID AND sh.meta_key = '_order_shipping'
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

	// Money rows only. A percentage cost stores a RATE and a per-order cost
	// stores a UNIT PRICE, neither of which is a period total; both are computed
	// separately (brikpanel_profit_percent_expenses / _per_order_expenses).
	$kinds = brikpanel_expense_money_kinds_sql();

	$total = (float) $wpdb->get_var( $wpdb->prepare(
		"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE expense_date BETWEEN %s AND %s{$kinds}",
		$start_date,
		$end_date
	) ); // phpcs:ignore

	$inventory = (float) $wpdb->get_var( $wpdb->prepare(
		"SELECT COALESCE(SUM(amount), 0) FROM {$table}
		 WHERE expense_date BETWEEN %s AND %s{$kinds} AND category = %s",
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

	$kinds = brikpanel_expense_money_kinds_sql();
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT COALESCE(category, '') AS category, SUM(amount) AS total
		 FROM {$table}
		 WHERE expense_date BETWEEN %s AND %s{$kinds}
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
 * The same manual expenses as brikpanel_profit_manual_expenses_by_category(),
 * one line per title, each carrying the title of the expense it is filed under
 * (its `parent`, '' when it stands alone).
 *
 * Deliberately NOT aggregated into groups: filing one expense under another is
 * a purely visual convenience, so every line keeps its own amount and nothing
 * is ever subtotalled or merged. A parent is itself an ordinary expense line;
 * the caller simply draws the lines that name it directly beneath it.
 *
 * Purely a display decomposition: these amounts sum to exactly the figure
 * brikpanel_profit_manual_expenses() returns, so Net profit is untouched.
 * Ordered by amount desc so the biggest costs lead.
 *
 * @param string $start_local Y-m-d H:i:s
 * @param string $end_local   Y-m-d H:i:s
 * @return array<int,array{title:string,parent:string,amount:float}>
 */
function brikpanel_profit_manual_expense_lines( $start_local, $end_local ) {
	global $wpdb;

	$table = $wpdb->prefix . 'brikpanel_expenses';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return [];
	}

	// Installs that have not run the schema upgrade yet have no parent column;
	// returning empty makes the caller fall back to the flat category list.
	$has_column = $wpdb->get_var( $wpdb->prepare(
		"SHOW COLUMNS FROM {$table} LIKE %s",
		'parent_category'
	) ); // phpcs:ignore
	if ( ! $has_column ) {
		return [];
	}

	$start_date = substr( (string) $start_local, 0, 10 );
	$end_date   = substr( (string) $end_local, 0, 10 );

	$kinds = brikpanel_expense_money_kinds_sql();
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT COALESCE(parent_category, '') AS parent, COALESCE(category, '') AS category, SUM(amount) AS total
		 FROM {$table}
		 WHERE expense_date BETWEEN %s AND %s{$kinds}
		 GROUP BY COALESCE(parent_category, ''), COALESCE(category, '')
		 HAVING total <> 0
		 ORDER BY total DESC",
		$start_date,
		$end_date
	) ); // phpcs:ignore

	$out = [];
	foreach ( (array) $rows as $r ) {
		$out[] = [
			'title'  => (string) $r->category,
			'parent' => (string) $r->parent,
			'amount' => (float) $r->total,
		];
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
 * The row id travels with each item so a surface that lists these costs can act
 * on one of them: two commissions can share a title AND a rate, which makes the
 * label useless as an identifier.
 *
 * @param string $start_gmt Y-m-d H:i:s (UTC)
 * @param string $end_gmt   Y-m-d H:i:s (UTC)
 * @return array{total:float,items:array<int,array{id:int,title:string,parent:string,rate:float,amount:float}>}
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

	// parent_category rides along so the caller can draw this line under the
	// expense it is filed under. It never changes the maths — a percentage cost
	// is computed from revenue whether or not it is nested. Selected defensively
	// so an install whose schema predates the column still works.
	$has_parent_col = (bool) $wpdb->get_var( $wpdb->prepare(
		"SHOW COLUMNS FROM {$table} LIKE %s",
		'parent_category'
	) ); // phpcs:ignore
	$parent_select = $has_parent_col ? "COALESCE(parent_category, '')" : "''";
	$rows = $wpdb->get_results(
		"SELECT id, category, amount, {$parent_select} AS parent FROM {$table} WHERE kind = 'percent' ORDER BY amount DESC"
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
			'id'     => (int) $r->id,
			'title'  => $title,
			'parent' => trim( (string) ( $r->parent ?? '' ) ),
			'rate'   => $rate,
			'amount' => $amount,
		];
		$out['total'] += $amount;
	}
	return $out;
}

/**
 * term_id => term_taxonomy_id for every product_shipping_class term, memoised.
 *
 * The relationship joins below are constrained to this set so each side can
 * match AT MOST one row: a product carries product_cat and product_tag
 * relationships too, and without the constraint the COALESCE that implements
 * "the variation's own class overrides the parent's" would be choosing between
 * whichever unrelated terms the join happened to line up.
 *
 * @return array<int,int>
 */
function brikpanel_shipping_class_ttid_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	global $wpdb;
	$map  = [];
	$rows = $wpdb->get_results(
		"SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_shipping_class'"
	); // phpcs:ignore
	foreach ( (array) $rows as $r ) {
		$map[ (int) $r->term_id ] = (int) $r->term_taxonomy_id;
	}
	return $map;
}

/**
 * Integer-only comma list of shipping-class term_taxonomy_ids, safe to
 * interpolate. '' on a store with no shipping classes at all.
 *
 * @return string
 */
function brikpanel_shipping_class_ttid_list() {
	$map = brikpanel_shipping_class_ttid_map();
	return $map ? implode( ',', array_map( 'absint', array_values( $map ) ) ) : '';
}

/**
 * Bust the order-count caches when the shipping-class term set changes.
 *
 * Deleting a class is not just "that one cost stops charging": the id list above
 * is a JOIN constraint, so removing a class can change which term the
 * variation-over-parent COALESCE resolves to for a DIFFERENT class, and every
 * bp_poc_* count computed before the change is then wrong. Adding or renaming
 * one is harmless, but the hook set is cheap and a merchant edits these roughly
 * never, so it covers all three rather than reasoning about each.
 */
function brikpanel_bust_caches_on_shipping_class( $term_id, $tt_id = 0, $taxonomy = '' ) {
	if ( 'product_shipping_class' !== $taxonomy ) {
		return;
	}
	if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
		brikpanel_bust_data_caches();
	}
}
add_action( 'created_term', 'brikpanel_bust_caches_on_shipping_class', 10, 3 );
add_action( 'edited_term',  'brikpanel_bust_caches_on_shipping_class', 10, 3 );
add_action( 'delete_term',  'brikpanel_bust_caches_on_shipping_class', 10, 3 );

/**
 * Correlated EXISTS body matching an order that contains at least one line item
 * whose EFFECTIVE shipping class is the given term_taxonomy_id (bound as %d).
 *
 * Effective class = the variation's own class when it has one, otherwise the
 * parent product's. WooCommerce's variation shipping-class control offers "Same
 * as parent" plus the class list and has no "none" option, so an absent
 * variation relationship means "inherit", which is exactly COALESCE. Simple
 * products fall out of the same expression for free: their _variation_id is 0,
 * no term_relationships row has object_id 0, so the variation side is NULL and
 * the parent side wins. Same shape as the variation-first COGS fallback in
 * brikpanel_profit_cogs().
 *
 * HPOS-agnostic on purpose: woocommerce_order_items.order_id is the order id
 * under BOTH storage engines, so only the OUTER orders table branches.
 *
 * @param string $oid   'o.id' (HPOS) or 'p.ID' (legacy).
 * @param string $alias Unique prefix so several copies can coexist in one query.
 * @return string '' when the store has no shipping classes (caller must skip).
 */
function brikpanel_profit_shipping_class_exists_sql( $oid, $alias ) {
	global $wpdb;

	$all = brikpanel_shipping_class_ttid_list();
	if ( '' === $all ) {
		return '';
	}
	$a = preg_replace( '/[^a-z0-9_]/', '', (string) $alias );

	return "EXISTS (
			SELECT 1
			  FROM {$wpdb->prefix}woocommerce_order_items {$a}li
			  JOIN {$wpdb->prefix}woocommerce_order_itemmeta {$a}pid
			    ON {$a}pid.order_item_id = {$a}li.order_item_id AND {$a}pid.meta_key = '_product_id'
			  LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta {$a}vid
			    ON {$a}vid.order_item_id = {$a}li.order_item_id AND {$a}vid.meta_key = '_variation_id'
			  LEFT JOIN {$wpdb->term_relationships} {$a}vtr
			    ON {$a}vtr.object_id = CAST({$a}vid.meta_value AS UNSIGNED)
			   AND {$a}vtr.term_taxonomy_id IN ({$all})
			  LEFT JOIN {$wpdb->term_relationships} {$a}ptr
			    ON {$a}ptr.object_id = CAST({$a}pid.meta_value AS UNSIGNED)
			   AND {$a}ptr.term_taxonomy_id IN ({$all})
			 WHERE {$a}li.order_id = {$oid}
			   AND {$a}li.order_item_type = 'line_item'
			   AND COALESCE({$a}vtr.term_taxonomy_id, {$a}ptr.term_taxonomy_id) = %d
		)";
}

/**
 * How many paid orders in [$start_gmt, $end_gmt] a per-order cost is charged on.
 *
 * Uses the same paid-status set, admin-order exclusion, marketplace basis and
 * inclusive GMT bounds as every other Profit component, so it counts exactly the
 * orders the Revenue card measures.
 *
 * Two rules keep the admin exclusion correct and must not be broken:
 *  1. brikpanel_admin_order_exclusion_sql( true ) emits a BARE
 *     "AND customer_id NOT IN (...)". That is unambiguous only because the outer
 *     FROM is wc_orders alone and none of the tables the EXISTS subqueries
 *     introduce has a customer_id column. NEVER add wc_order_stats or
 *     wc_order_product_lookup to this query.
 *  2. NEVER move the orders table into a derived table, because customer_id would fall
 *     out of scope for that clause entirely. (That is why the single ranked
 *     query, which would replace the N-pass below with one pass, is not used
 *     here; if profiling ever demands it, it must qualify the exclusion itself.)
 *
 * @param string $scope        '' (every order) | 'free_shipping' | 'shipping_class:<term_id>'
 * @param string $start_gmt    Y-m-d H:i:s (UTC)
 * @param string $end_gmt      Y-m-d H:i:s (UTC)
 * @param bool   $exclude_marketplace Match the order basis of the revenue figure.
 * @param int[]  $beaten_ttids term_taxonomy_ids of HIGHER-amount shipping-class
 *                             rows that already claimed the order: one parcel
 *                             gets one box surcharge, the largest one.
 * @return int
 */
function brikpanel_profit_scoped_order_count( $scope, $start_gmt, $end_gmt, $exclude_marketplace = false, array $beaten_ttids = [] ) {
	global $wpdb;

	$is_hpos  = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
	$statuses = brikpanel_paid_order_statuses();
	$sp       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
	$oid      = $is_hpos ? 'o.id' : 'p.ID';

	$preds = [];
	$pargs = [];

	if ( 'free_shipping' === $scope ) {
		// An order that HAS a shipping line whose cost is 0, never "an order
		// with no shipping line". On the reference store 1387 of 1464 paid
		// orders carry no shipping item at all (virtual products, pickup,
		// imported orders), so an INNER JOIN or a NOT EXISTS would sweep 95% of
		// the store into this bucket.
		//
		// The signal is `cost` (NO leading underscore on shipping items), not
		// method_id: 82 of 90 shipping lines on the reference store have an
		// EMPTY method_id. method_id IS used to drop local_pickup, which also
		// books a zero-cost shipping line but cost the merchant no courier.
		//
		// SUBSTRING_INDEX because the stored form is not stable across the
		// WooCommerce versions a shop's order history spans: current WC writes a
		// bare 'local_pickup', older releases and several importers write
		// 'local_pickup:3' (method:instance). Comparing the whole string would
		// quietly count every legacy pickup order as a free-shipped one.
		$preds[] = "EXISTS (
			SELECT 1
			  FROM {$wpdb->prefix}woocommerce_order_items bpsh
			  JOIN {$wpdb->prefix}woocommerce_order_itemmeta bpshc
			    ON bpshc.order_item_id = bpsh.order_item_id AND bpshc.meta_key = 'cost'
			  LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta bpshm
			    ON bpshm.order_item_id = bpsh.order_item_id AND bpshm.meta_key = 'method_id'
			 WHERE bpsh.order_id = {$oid}
			   AND bpsh.order_item_type = 'shipping'
			   AND CAST(COALESCE(NULLIF(bpshc.meta_value, ''), '0') AS DECIMAL(20,4)) = 0
			   AND SUBSTRING_INDEX(COALESCE(bpshm.meta_value, ''), ':', 1) <> 'local_pickup'
		)";
	} elseif ( 0 === strpos( (string) $scope, 'shipping_class:' ) ) {
		$map  = brikpanel_shipping_class_ttid_map();
		$ttid = (int) ( $map[ (int) substr( (string) $scope, 15 ) ] ?? 0 );
		if ( ! $ttid ) {
			return 0; // term deleted → this cost charges nothing
		}
		$mine = brikpanel_profit_shipping_class_exists_sql( $oid, 'bpc' );
		if ( '' === $mine ) {
			return 0; // store has no shipping classes at all
		}
		$preds[] = $mine;
		$pargs[] = $ttid;
		foreach ( array_values( array_unique( array_map( 'absint', $beaten_ttids ) ) ) as $i => $bt ) {
			$preds[] = 'NOT ' . brikpanel_profit_shipping_class_exists_sql( $oid, 'bpx' . $i );
			$pargs[] = $bt;
		}
	}
	// '' (every order) adds no predicate at all.

	// Cache the COUNT, never the money: the merchant can edit the unit price
	// without touching a single order. Mirrors the bp_rev_* key including the
	// status set, which is part of the identity. The beaten list is in the key
	// because the same scope yields a different count depending on which rows
	// outrank it. Deliberately NOT in the key, both bounded and both already
	// true of bp_rev_*: the admin-user id list (itself cached) and the
	// shipping-class term set.
	$ck = 'bp_poc_' . brikpanel_data_cache_ver() . '_' . md5(
		$start_gmt . '|' . $end_gmt . '|' . (string) $scope . '|' . implode( ',', $pargs )
		. '|' . ( $exclude_marketplace ? 'nomp' : '' ) . '|' . implode( ',', $statuses )
	);
	$hit = get_transient( $ck );
	if ( false !== $hit ) {
		return (int) $hit;
	}

	if ( $is_hpos ) {
		$sql  = "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders o
			WHERE o.type = 'shop_order' AND o.status IN ($sp)
			  AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s";
		$args = array_merge( $statuses, [ $start_gmt, $end_gmt ] );
		$excl = brikpanel_admin_order_exclusion_sql( true );
	} else {
		$sql  = "SELECT COUNT(*) FROM {$wpdb->posts} p
			WHERE p.post_type = 'shop_order' AND p.post_status IN ($sp)
			  AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s";
		$args = array_merge( $statuses, [ $start_gmt, $end_gmt ] );
		$excl = brikpanel_admin_order_exclusion_sql( false, 'p.ID' );
	}

	// Placeholder ORDER is the contract: prepare() binds positionally in the
	// order the %-tokens appear IN THE STRING. Scope predicates are appended
	// after the status/date tokens so their args follow, and the two exclusion
	// fragments are appended last so their args go last.
	foreach ( $preds as $pred ) {
		$sql .= "\n\t\t\t  AND {$pred}";
	}
	$args = array_merge( $args, $pargs );

	if ( ! empty( $excl['sql'] ) ) {
		$sql .= $excl['sql'];
		$args = array_merge( $args, $excl['args'] );
	}

	// 'o.id' / 'p.ID', NEVER a bare 'id'. On HPOS an unqualified id binds to
	// the subquery's OWN column and excludes nothing.
	$mp = brikpanel_profit_marketplace_excl( $exclude_marketplace, $is_hpos, $is_hpos ? 'o.id' : 'p.ID' );
	if ( ! empty( $mp['sql'] ) ) {
		$sql .= $mp['sql'];
		$args = array_merge( $args, $mp['args'] );
	}

	$count = (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore
	set_transient( $ck, $count, brikpanel_cache_ttl( 60 ) );
	return $count;
}

/**
 * Translated name for a per-order cost's "Applies to" scope.
 *
 * @param string $scope '' | 'free_shipping' | 'shipping_class:<term_id>'
 * @return string
 */
function brikpanel_per_order_scope_label( $scope ) {
	if ( 'free_shipping' === $scope ) {
		return __( 'Orders shipped free', 'brikpanel' );
	}
	if ( 0 === strpos( (string) $scope, 'shipping_class:' ) ) {
		$term = get_term( (int) substr( (string) $scope, 15 ), 'product_shipping_class' );
		return ( $term instanceof WP_Term ) ? $term->name : __( 'Shipping class (removed)', 'brikpanel' );
	}
	return __( 'Every order', 'brikpanel' );
}

/**
 * Per-order expenses (packaging, a courier's flat fee on free-shipped orders, a
 * surcharge for a bulky shipping class) for the window.
 *
 * Each such expense stores a UNIT PRICE in the `amount` column and is charged
 * once per MATCHING ORDER in whatever period is being viewed. Like a percentage
 * cost there is deliberately no per-expense date or schedule: a box costs what a
 * box costs, every period, which is why the editor hides those fields for it.
 * The consequence mirrors percent exactly and is worth stating plainly: adding
 * a packaging cost today also reduces last year's Net profit when last year is
 * viewed.
 *
 * Only ONE shipping-class cost may charge a given order: a parcel gets one box,
 * and the largest applicable surcharge is the one that describes it. Rows are
 * therefore ranked by amount and each one counts only the orders no
 * higher-ranked class row already claimed. "Every order" and "Orders shipped
 * free" costs stack on top independently, because a free-shipping courier fee and a
 * bulky box are two different real costs on the same parcel.
 *
 * Multi-currency: NO conversion is applied, and this is the one place the
 * treatment differs from brikpanel_profit_shipping_cost(). That figure reads a
 * value STORED ON THE ORDER (the charged shipping, or the per-order override),
 * denominated in the ORDER's currency, hence the base_total/total ratio it
 * applies. A per-order expense is a number the merchant typed into the Expenses
 * module, which has no currency field and has always been store currency for
 * every other kind. A box costs what it costs whatever currency the customer
 * paid in, so count × amount is already store currency.
 *
 * Refunds deliberately do NOT reduce this: the box was used and the courier paid
 * whether or not the customer was later given their money back, the same
 * reasoning as brikpanel_profit_shipping_cost(). (An order moved to a refunded
 * STATUS drops out of brikpanel_paid_order_statuses() and therefore out of the
 * count; that is a pre-existing property of every Profit component.)
 *
 * Works identically for simple and variable products: the shipping-class scope
 * resolves a variation's own class first and falls back to the parent's.
 *
 * @param string $start_gmt Y-m-d H:i:s (UTC)
 * @param string $end_gmt   Y-m-d H:i:s (UTC)
 * @param bool|null $exclude_marketplace null = decide from BrikMarket being active.
 * @return array{total:float,items:array<int,array{id:int,title:string,parent:string,unit:float,orders:int,scope:string,scope_label:string,amount:float}>}
 */
function brikpanel_profit_per_order_expenses( $start_gmt, $end_gmt, $exclude_marketplace = null ) {
	global $wpdb;
	$out = [ 'total' => 0.0, 'items' => [] ];

	$table = $wpdb->prefix . 'brikpanel_expenses';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return $out;
	}

	// No scope column means a schema older than this feature, on which no
	// per_order row can exist. Bail before touching anything else.
	$has_scope = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'scope' ) ); // phpcs:ignore
	if ( ! $has_scope ) {
		return $out;
	}

	$has_parent_col = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'parent_category' ) ); // phpcs:ignore
	$parent_select  = $has_parent_col ? "COALESCE(parent_category, '')" : "''";

	// amount DESC, id ASC. The id tie-break is load-bearing: two equal-amount
	// shipping-class rows would otherwise have their winner decided by MySQL row
	// order and the breakdown would flip between renders.
	$rows = $wpdb->get_results(
		"SELECT id, category, amount, COALESCE(scope, '') AS scope, {$parent_select} AS parent
		   FROM {$table} WHERE kind = 'per_order' ORDER BY amount DESC, id ASC"
	); // phpcs:ignore
	if ( empty( $rows ) ) {
		return $out;
	}

	$exclude_mp = ( null === $exclude_marketplace )
		? ( function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active() )
		: (bool) $exclude_marketplace;

	$class_map = brikpanel_shipping_class_ttid_map();
	$beaten    = []; // ttids already claimed by a higher-amount shipping-class row

	foreach ( $rows as $r ) {
		$unit = (float) $r->amount;
		// Dropped BEFORE ranking: a zero-amount row must never consume an order
		// from a lower-but-positive one.
		if ( $unit <= 0 ) {
			continue;
		}

		$scope    = (string) $r->scope;
		$is_class = ( 0 === strpos( $scope, 'shipping_class:' ) );
		$ttid     = 0;
		if ( $is_class ) {
			$ttid = (int) ( $class_map[ (int) substr( $scope, 15 ) ] ?? 0 );
			if ( ! $ttid ) {
				continue; // class deleted → charges nothing, and does not rank
			}
		}

		$orders = brikpanel_profit_scoped_order_count(
			$scope,
			$start_gmt,
			$end_gmt,
			$exclude_mp,
			$is_class ? $beaten : [] // only class rows beat class rows
		);
		if ( $is_class ) {
			$beaten[] = $ttid;
		}
		if ( $orders <= 0 ) {
			continue;
		}

		$title = trim( (string) $r->category );
		if ( '' === $title ) {
			$title = __( 'Cost per order', 'brikpanel' );
		}

		$out['items'][] = [
			'id'          => (int) $r->id,
			'title'       => $title,
			'parent'      => trim( (string) ( $r->parent ?? '' ) ),
			'unit'        => $unit,
			'orders'      => $orders,
			'scope'       => $scope,
			'scope_label' => brikpanel_per_order_scope_label( $scope ),
			'amount'      => $unit * $orders,
		];
	}

	/**
	 * Filter the per-order costs that net down Net profit.
	 *
	 * Receives the ITEMS, not the total, and the total is re-derived below, so
	 * the figure and the breakdown lines that explain it can never disagree.
	 * (brikpanel_profit_shipping_cost filters a bare float because it has no
	 * lines to keep in step.)
	 *
	 * @param array  $items     Per-order cost lines for the window.
	 * @param string $start_gmt Y-m-d H:i:s (UTC)
	 * @param string $end_gmt   Y-m-d H:i:s (UTC)
	 */
	$out['items'] = (array) apply_filters( 'brikpanel_profit_per_order_expenses', $out['items'], $start_gmt, $end_gmt );

	foreach ( $out['items'] as $item ) {
		$out['total'] += (float) ( $item['amount'] ?? 0 );
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
	$shipping = brikpanel_profit_shipping_cost( $start_gmt, $end_gmt, $exclude_marketplace );
	$fees     = brikpanel_profit_payment_fees( $start_gmt, $end_gmt, $exclude_marketplace );
	$returns  = brikpanel_profit_returns( $start_gmt, $end_gmt, $exclude_marketplace );
	$coupons  = brikpanel_profit_coupons( $start_gmt, $end_gmt, $exclude_marketplace );
	$ads_by   = brikpanel_profit_ad_spend_by_platform( $start_local, $end_local );
	$ads      = brikpanel_profit_ad_spend( $start_local, $end_local );

	list( $exp_manual, $exp_inventory, $exp_other ) = brikpanel_profit_manual_expenses( $start_local, $end_local );
	$exp_by_category = brikpanel_profit_manual_expenses_by_category( $start_local, $end_local );
	$exp_lines       = brikpanel_profit_manual_expense_lines( $start_local, $end_local );
	$percent         = brikpanel_profit_percent_expenses( $start_gmt, $end_gmt, $exclude_marketplace );
	$per_order       = brikpanel_profit_per_order_expenses( $start_gmt, $end_gmt, $exclude_marketplace );

	// Net revenue = gross sales minus what was handed back to customers. This
	// is the figure the dashboard's Revenue card shows and the basis for every
	// margin %, so margins reflect money actually kept, not money invoiced.
	// `revenue` (the gross, passed in) is preserved separately so surfaces that
	// must agree with the "Total Sales" KPI (and the Sheets snapshot's Revenue
	// column) keep showing gross.
	$revenue_net    = $revenue - $returns;
	// manual already includes inventory; percent (commission-style) costs scale
	// with revenue and per-order costs (packaging, a courier fee on free-shipped
	// orders) scale with the order count, each computed in its own function.
	// Shipping cost joins the composite rather than becoming its own deduction
	// on purpose: every surface that already reports "Expenses" or "Net profit"
	// (the Excel export, the Sheets Profit tab) then stays correct without
	// knowing this component exists. Payment fees join for the same reason: the
	// real gateway deduction is an operating cost like any other, and adding it
	// to the composite keeps every "Expenses"/"Net profit" surface correct.
	$expenses_total = $tax + $ads + $exp_manual + $percent['total'] + $per_order['total'] + $shipping + $fees['total'];
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
		'shipping_cost_raw'  => $shipping,
		// Scalar alongside the items below because the Excel export and the
		// Sheets Profit tab read scalars, not lines — the same reason
		// shipping_cost_raw exists.
		'per_order_total_raw' => $per_order['total'],
		// Same reason as the two scalars above: the Excel export and the Sheets
		// Profit tab read scalars, not breakdown lines.
		'payment_fees_raw'   => $fees['total'],
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
		// Same contract as the COGS signals above, for the same reason: a fee
		// total drawn from only part of the orders must never read as fact.
		// `missing` is the expected-and-harmless case (bank transfer, cash on
		// delivery: no processor, no fee). `unconverted` is the actionable one —
		// those fees exist, could not be converted, and are therefore NOT in the
		// total, so the expense is understated until a rate is entered.
		'payment_fees_coverage_pct' => $fees['coverage_pct'],
		'payment_fees_missing'      => max( 0, $fees['orders'] - $fees['orders_with_fee'] ),
		'payment_fees_unconverted'  => $fees['unconverted_orders'],
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
			// Position is the source of truth for row order on every surface
			// downstream (dashboard breakdown, Sheets column). Keep it here.
			'payment_fees' => $fees['total'],
			'shipping'   => $shipping,
			'inventory'  => $exp_inventory,
			'other'      => $exp_other,
		],
		// Manual expenses split by their own category (Salaries, Rent, …) so the
		// dashboard breakdown can list each instead of one "Other" lump. Summing
		// these equals exp_manual; the inventory + other split above is kept for
		// the Sheets snapshot columns.
		'expense_categories' => $exp_by_category,
		// The same money as `expense_categories`, one line per title, each
		// naming the expense it is filed under. Kept alongside rather than
		// replacing it: the flat map is part of the published snapshot shape.
		'expense_lines'      => $exp_lines,
		// Percentage-based costs (card commission etc.): each item is
		// {title, rate, amount} where amount = rate% × applicable gross revenue.
		// Deliberately absent from `breakdown` above, which is the mutually
		// exclusive component split Sheets and Excel consume — the same reason
		// per_order_expenses is not there either.
		'percent_expenses'   => $percent['items'],
		// Per-order costs (packaging, free-shipping courier fee, bulky
		// surcharge): each item is {title, unit, orders, scope, amount} where
		// amount = unit × the number of matching paid orders in the window.
		'per_order_expenses' => $per_order['items'],
	];
}
