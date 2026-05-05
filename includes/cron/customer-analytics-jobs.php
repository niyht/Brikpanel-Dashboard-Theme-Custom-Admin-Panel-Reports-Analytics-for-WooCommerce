<?php
/**
 * BrikPanel — Customer Analytics background jobs.
 *
 * Recomputes per-customer LTV / RFM aggregates into
 * {prefix}brikpanel_customer_metrics so the Customer Analytics page,
 * Dashboard cards, and Segments filters can read precomputed values
 * instead of aggregating WooCommerce orders on every request.
 *
 * Scheduled to run nightly via Brikpanel_Cron (Action Scheduler).
 *
 * @package BrikPanel
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether HPOS (custom orders table) is enabled.
 *
 * @return bool
 */
function brikpanel_ca_is_hpos() {
	static $hpos = null;
	if ( $hpos === null ) {
		$hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
	}
	return $hpos;
}

/**
 * Order statuses that count toward LTV / RFM. Mirrors the Segments customer
 * aggregation (completed, processing, on-hold, pending, refunded). Cancelled
 * and failed orders are intentionally excluded — they don't represent paid
 * lifetime value.
 *
 * @return string[]
 */
function brikpanel_ca_counted_statuses() {
	return [ 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-refunded' ];
}

/**
 * Recompute customer LTV (Phase 1) into the customer_metrics table.
 *
 * Strategy:
 *  1. Aggregate per-customer (user_id or guest email) totals from orders.
 *  2. UPSERT into brikpanel_customer_metrics in batches.
 *  3. Delete rows that no longer have any matching orders (stale customers).
 *
 * RFM scoring is filled in by Phase 2; Phase 1 leaves r/f/m_score = 0 and
 * rfm_segment = NULL.
 *
 * @return array Diagnostics: rows written, duration, peak memory.
 */
function brikpanel_recompute_customer_metrics_handler() {
	global $wpdb;

	$start_ts   = microtime( true );
	$started_at = current_time( 'mysql', true );
	$metrics_tb = $wpdb->prefix . 'brikpanel_customer_metrics';
	$counted    = brikpanel_ca_counted_statuses();
	$status_in  = "'" . implode( "','", array_map( 'esc_sql', $counted ) ) . "'";

	$hpos = brikpanel_ca_is_hpos();

	// Aggregation runs entirely inside MySQL via INSERT...SELECT...ON DUPLICATE
	// KEY UPDATE. This avoids loading every customer row into PHP, which on a
	// 100k+ customer store would peak hundreds of MB and OOM weak hosts.
	// Recency / AOV are derived in SQL so PHP never sees the result set.
	if ( $hpos ) {
		$select_sql = "
			SELECT
				CASE
					WHEN o.customer_id > 0 THEN CONCAT('u:', o.customer_id)
					ELSE CONCAT('e:', LOWER(o.billing_email))
				END AS customer_key,
				MAX(o.customer_id) AS user_id,
				MAX(LOWER(o.billing_email)) AS customer_email,
				MIN(o.date_created_gmt) AS first_order_date,
				MAX(o.date_created_gmt) AS last_order_date,
				COUNT(*) AS order_count,
				COALESCE(SUM(o.total_amount), 0) AS total_spent,
				COALESCE(SUM(o.total_amount), 0) / NULLIF(COUNT(*), 0) AS aov,
				GREATEST(0, TIMESTAMPDIFF(DAY, MAX(o.date_created_gmt), UTC_TIMESTAMP())) AS recency_days
			FROM {$wpdb->prefix}wc_orders o
			WHERE o.type = 'shop_order'
			  AND o.status IN ({$status_in})
			  AND (o.customer_id > 0 OR (o.billing_email IS NOT NULL AND o.billing_email <> ''))
			GROUP BY customer_key
		";
	} else {
		$select_sql = "
			SELECT
				CASE
					WHEN cu_meta.meta_value+0 > 0 THEN CONCAT('u:', cu_meta.meta_value)
					ELSE CONCAT('e:', LOWER(IFNULL(em_meta.meta_value, '')))
				END AS customer_key,
				MAX(CAST(cu_meta.meta_value AS UNSIGNED)) AS user_id,
				MAX(LOWER(em_meta.meta_value)) AS customer_email,
				MIN(o.post_date_gmt) AS first_order_date,
				MAX(o.post_date_gmt) AS last_order_date,
				COUNT(*) AS order_count,
				COALESCE(SUM(CAST(tot_meta.meta_value AS DECIMAL(20,4))), 0) AS total_spent,
				COALESCE(SUM(CAST(tot_meta.meta_value AS DECIMAL(20,4))), 0) / NULLIF(COUNT(*), 0) AS aov,
				GREATEST(0, TIMESTAMPDIFF(DAY, MAX(o.post_date_gmt), UTC_TIMESTAMP())) AS recency_days
			FROM {$wpdb->posts} o
			LEFT JOIN {$wpdb->postmeta} cu_meta  ON cu_meta.post_id  = o.ID AND cu_meta.meta_key  = '_customer_user'
			LEFT JOIN {$wpdb->postmeta} em_meta  ON em_meta.post_id  = o.ID AND em_meta.meta_key  = '_billing_email'
			LEFT JOIN {$wpdb->postmeta} tot_meta ON tot_meta.post_id = o.ID AND tot_meta.meta_key = '_order_total'
			WHERE o.post_type = 'shop_order'
			  AND o.post_status IN ({$status_in})
			  AND (cu_meta.meta_value+0 > 0 OR em_meta.meta_value <> '')
			GROUP BY customer_key
		";
	}

	$upsert_sql = "INSERT INTO {$metrics_tb}
		(customer_key, user_id, customer_email, first_order_date, last_order_date, order_count, total_spent, aov, recency_days)
		SELECT
			agg.customer_key,
			IFNULL(agg.user_id, 0),
			IFNULL(agg.customer_email, ''),
			agg.first_order_date,
			agg.last_order_date,
			agg.order_count,
			agg.total_spent,
			IFNULL(agg.aov, 0),
			agg.recency_days
		FROM ( {$select_sql} ) AS agg
		WHERE agg.customer_key <> '' AND agg.customer_key <> 'e:'
		ON DUPLICATE KEY UPDATE
			user_id          = VALUES(user_id),
			customer_email   = VALUES(customer_email),
			first_order_date = VALUES(first_order_date),
			last_order_date  = VALUES(last_order_date),
			order_count      = VALUES(order_count),
			total_spent      = VALUES(total_spent),
			aov              = VALUES(aov),
			recency_days     = VALUES(recency_days),
			computed_at      = CURRENT_TIMESTAMP";

	$written = $wpdb->query( $upsert_sql ); // phpcs:ignore
	if ( $written === false ) {
		throw new \RuntimeException( 'Customer metrics aggregate query failed: ' . $wpdb->last_error );
	}

	// Prune rows whose customer_key is no longer present (e.g. all of their
	// orders were deleted/refunded out of the counted statuses). Stale rows
	// are detected as those not touched by this run (computed_at < $started_at).
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$metrics_tb} WHERE computed_at < %s",
		$started_at
	) ); // phpcs:ignore

	// Phase 2: compute R/F/M quintiles + segment labels in a single UPDATE.
	$rfm_assigned = brikpanel_ca_assign_rfm_scores();

	$duration = round( microtime( true ) - $start_ts, 3 );
	$peak     = function_exists( 'memory_get_peak_usage' ) ? round( memory_get_peak_usage( true ) / 1024 / 1024, 1 ) : 0;

	// Invalidate the read-side caches so the Customer Analytics page and the
	// Dashboard's LTV/RFM panels both reflect fresh metrics on the next render.
	if ( method_exists( 'Brikpanel_Customer_Analytics', 'bust_cache' ) ) {
		Brikpanel_Customer_Analytics::bust_cache();
	}
	if ( method_exists( 'Brikpanel_Dashboard', 'bust_dashboard_cache' ) ) {
		Brikpanel_Dashboard::bust_dashboard_cache();
	}

	error_log( sprintf(
		'[BrikPanel CA] LTV+RFM recompute: rows=%d, rfm_scored=%d, duration=%ss, peak_mem=%sMB, hpos=%s',
		$written,
		$rfm_assigned,
		$duration,
		$peak,
		$hpos ? '1' : '0'
	) );

	return [
		'rows_written' => $written,
		'rfm_scored'   => $rfm_assigned,
		'duration'     => $duration,
		'peak_mem_mb'  => $peak,
	];
}

/**
 * Score every customer on Recency / Frequency / Monetary quintiles and
 * label them with an RFM segment in a single UPDATE … JOIN window-function
 * pass.
 *
 * Quintile semantics (5 = best, 1 = worst):
 *   - R: smallest recency_days → 5 (most recent buyers).
 *   - F: largest order_count → 5 (most frequent).
 *   - M: largest total_spent → 5 (highest spenders).
 *
 * Segments use the standard RFM matrix; CASE order matters since the most
 * specific rules must be evaluated first to claim the row before the
 * broader ones (Loyal, Potential Loyalist) do.
 *
 * @return int Number of rows updated.
 */
function brikpanel_ca_assign_rfm_scores() {
	global $wpdb;
	$tbl = $wpdb->prefix . 'brikpanel_customer_metrics';

	// MySQL 8.0+ supports NTILE() and UPDATE … JOIN on a derived table.
	// Stores running pre-8.0 won't get RFM until they upgrade — we degrade
	// gracefully by zeroing scores rather than fataling.
	$server_version = $wpdb->db_version();
	if ( version_compare( $server_version, '8.0', '<' ) ) {
		$wpdb->query( "UPDATE {$tbl} SET r_score=0, f_score=0, m_score=0, rfm_segment=NULL" ); // phpcs:ignore
		return 0;
	}

	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" ); // phpcs:ignore
	if ( $total === 0 ) {
		return 0;
	}

	// Derived table computes the three quintile scores per customer.
	// We exclude rows with NULL recency_days from R scoring (rare, but if
	// present they'd anchor the quintile boundaries unpredictably).
	$sql = "
		UPDATE {$tbl} m
		INNER JOIN (
			SELECT
				customer_key,
				NTILE(5) OVER (ORDER BY COALESCE(recency_days, 999999) DESC) AS r_score,
				NTILE(5) OVER (ORDER BY order_count ASC) AS f_score,
				NTILE(5) OVER (ORDER BY total_spent ASC) AS m_score
			FROM {$tbl}
		) scores ON m.customer_key = scores.customer_key
		SET
			m.r_score = scores.r_score,
			m.f_score = scores.f_score,
			m.m_score = scores.m_score,
			m.rfm_segment = CASE
				WHEN scores.r_score >= 4 AND scores.f_score >= 4 AND scores.m_score >= 4 THEN 'champions'
				WHEN scores.r_score <= 2 AND scores.f_score >= 4 AND scores.m_score >= 4 THEN 'cant_lose'
				WHEN scores.r_score <= 2 AND scores.f_score >= 3 AND scores.m_score >= 3 THEN 'at_risk'
				WHEN scores.r_score = 1 AND scores.f_score = 1 AND scores.m_score = 1 THEN 'lost'
				WHEN scores.r_score <= 2 AND scores.f_score <= 2 THEN 'hibernating'
				WHEN scores.f_score >= 3 AND scores.m_score >= 3 THEN 'loyal'
				WHEN scores.r_score >= 4 AND scores.f_score = 1 THEN 'new'
				WHEN scores.r_score >= 4 AND scores.f_score <= 3 THEN 'potential_loyalist'
				WHEN scores.r_score = 3 AND scores.f_score <= 2 THEN 'about_to_sleep'
				-- High-frequency, low-monetary buyers — engaged shoppers with a
				-- small basket. The previous version mapped these to 'loyal',
				-- which sent the wrong signal: a customer ordering 5× a year at
				-- ₺50 each is structurally different from one ordering 5× at
				-- ₺2,000. They deserve their own bucket so the merchant can act
				-- on it (raise AOV via cross-sell, basket-size promos, bundles).
				WHEN scores.f_score >= 3 THEN 'need_attention'
				-- ELSE is unreachable in the 5×5×5 lattice (every cell is now
				-- covered) but kept as a defence so future scoring tweaks can't
				-- silently nuke the segment column.
				ELSE 'hibernating'
			END
	";

	$updated = $wpdb->query( $sql ); // phpcs:ignore
	if ( $updated === false ) {
		throw new \RuntimeException( 'RFM assignment failed: ' . $wpdb->last_error );
	}
	return (int) $updated;
}

/**
 * Canonical list of RFM segments used by the UI. Single source of truth so
 * the Customer Analytics page, Segments page filter, and Dashboard donut
 * stay in sync. Order is meaningful — controls the rendering order in the
 * UI grid (best/most actionable first).
 *
 * @return array<string, array{label: string, description: string, color: string}>
 */
function brikpanel_ca_rfm_segment_labels() {
	return [
		'champions' => [
			'label'       => __( 'Champions', 'brikpanel' ),
			'description' => __( 'Bought recently, often, and spend the most. Reward them.', 'brikpanel' ),
			'color'       => '#1a8917',
		],
		'loyal' => [
			'label'       => __( 'Loyal Customers', 'brikpanel' ),
			'description' => __( 'Spend often and well. Upsell higher-value products.', 'brikpanel' ),
			'color'       => '#2e7d32',
		],
		'potential_loyalist' => [
			'label'       => __( 'Potential Loyalist', 'brikpanel' ),
			'description' => __( 'Recent buyers with moderate frequency. Nurture them.', 'brikpanel' ),
			'color'       => '#5e8c61',
		],
		'new' => [
			'label'       => __( 'New Customers', 'brikpanel' ),
			'description' => __( 'Bought recently for the first time. Onboard them.', 'brikpanel' ),
			'color'       => '#6f9bd1',
		],
		'cant_lose' => [
			'label'       => __( "Can't Lose Them", 'brikpanel' ),
			'description' => __( 'High-value customers who haven\'t bought in a while. Win them back.', 'brikpanel' ),
			'color'       => '#c2410c',
		],
		'at_risk' => [
			'label'       => __( 'At Risk', 'brikpanel' ),
			'description' => __( 'Used to buy frequently but lapsed. Re-engage now.', 'brikpanel' ),
			'color'       => '#d97706',
		],
		'about_to_sleep' => [
			'label'       => __( 'About to Sleep', 'brikpanel' ),
			'description' => __( 'Below-average recency, frequency, and value. Recover them.', 'brikpanel' ),
			'color'       => '#a16207',
		],
		'need_attention' => [
			'label'       => __( 'Need Attention', 'brikpanel' ),
			'description' => __( 'Buy frequently but with a small basket. Raise basket size via cross-sell, bundles, or higher-tier upgrades.', 'brikpanel' ),
			'color'       => '#9c6f3a',
		],
		'hibernating' => [
			'label'       => __( 'Hibernating', 'brikpanel' ),
			'description' => __( 'Low engagement across the board. Test cheap re-activation.', 'brikpanel' ),
			'color'       => '#737373',
		],
		'lost' => [
			'label'       => __( 'Lost', 'brikpanel' ),
			'description' => __( 'Lowest scores everywhere. Likely gone for good.', 'brikpanel' ),
			'color'       => '#404040',
		],
		// 'others' was a catch-all bucket for (R, F, M) score combinations
		// that didn't match any named rule. The CASE expression in
		// brikpanel_ca_assign_rfm_scores() now covers all 125 combinations,
		// so no row should ever land on 'others'. The entry is intentionally
		// removed from this label registry: any stale 'others' string left
		// in the table from before the upgrade is now invisible to the UI
		// (the segment fallthrough renders the raw key, but UPSERT on the
		// next nightly recompute reclassifies the row to its proper segment).
	];
}

/**
 * Recompute monthly cohort retention matrix (Phase 3).
 *
 * Algorithm:
 *  1. For each customer, derive their `cohort_month` = first-order month.
 *  2. For each subsequent order, compute `period_offset` = months between
 *     cohort_month and order month (0 = first month, 1 = next month, …).
 *  3. Aggregate distinct customers per (cohort_month, period_offset).
 *  4. Cohort size is the count of distinct customers in offset 0.
 *  5. retention_rate = retained / cohort_size * 100.
 *
 * Window: last 24 months by default. Older cohorts are pruned to keep
 * the heatmap manageable on large stores.
 *
 * @return array Diagnostics.
 */
function brikpanel_recompute_cohort_retention_handler() {
	global $wpdb;

	$start_ts  = microtime( true );
	$tbl       = $wpdb->prefix . 'brikpanel_cohort_retention';
	$counted   = brikpanel_ca_counted_statuses();
	$status_in = "'" . implode( "','", array_map( 'esc_sql', $counted ) ) . "'";
	$months_back = 24;
	$cutoff_date = gmdate( 'Y-m-01', strtotime( "-{$months_back} months" ) );

	$hpos = brikpanel_ca_is_hpos();

	// Build the source query: every counted order with a customer key + date.
	if ( $hpos ) {
		$source_sql = "
			SELECT
				CASE
					WHEN o.customer_id > 0 THEN CONCAT('u:', o.customer_id)
					ELSE CONCAT('e:', LOWER(o.billing_email))
				END AS customer_key,
				DATE_FORMAT(o.date_created_gmt, '%Y-%m-01') AS order_month
			FROM {$wpdb->prefix}wc_orders o
			WHERE o.type = 'shop_order'
			  AND o.status IN ({$status_in})
			  AND (o.customer_id > 0 OR o.billing_email <> '')
		";
	} else {
		$source_sql = "
			SELECT
				CASE
					WHEN cu_meta.meta_value+0 > 0 THEN CONCAT('u:', cu_meta.meta_value)
					ELSE CONCAT('e:', LOWER(IFNULL(em_meta.meta_value, '')))
				END AS customer_key,
				DATE_FORMAT(o.post_date_gmt, '%Y-%m-01') AS order_month
			FROM {$wpdb->posts} o
			LEFT JOIN {$wpdb->postmeta} cu_meta ON cu_meta.post_id = o.ID AND cu_meta.meta_key = '_customer_user'
			LEFT JOIN {$wpdb->postmeta} em_meta ON em_meta.post_id = o.ID AND em_meta.meta_key = '_billing_email'
			WHERE o.post_type = 'shop_order'
			  AND o.post_status IN ({$status_in})
			  AND (cu_meta.meta_value+0 > 0 OR em_meta.meta_value <> '')
		";
	}

	// Single-pass aggregation. The CTE pattern would be cleaner but inline
	// derived tables work on MySQL 5.7 + MariaDB which we still support.
	// 1) `customer_first` resolves each customer's cohort_month.
	// 2) Joining back to source orders gives every (cohort, period_offset)
	//    pair for every distinct order; we count DISTINCT customers per
	//    bucket so a customer with 3 orders in offset=2 still counts once.
	// 3) Filter to the rolling window.
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$tbl} WHERE cohort_month < %s",
		$cutoff_date
	) ); // phpcs:ignore

	$matrix_sql = "
		INSERT INTO {$tbl} (cohort_month, period_offset, cohort_size, retained_customers, retention_rate)
		SELECT
			cohorts.cohort_month,
			TIMESTAMPDIFF(MONTH, cohorts.cohort_month, source.order_month) AS period_offset,
			cohort_sizes.cohort_size,
			COUNT(DISTINCT source.customer_key) AS retained_customers,
			ROUND(COUNT(DISTINCT source.customer_key) / cohort_sizes.cohort_size * 100, 2) AS retention_rate
		FROM (
			SELECT
				customer_key,
				MIN(order_month) AS cohort_month
			FROM ({$source_sql}) src
			GROUP BY customer_key
		) cohorts
		INNER JOIN (
			SELECT cohort_month, COUNT(*) AS cohort_size FROM (
				SELECT customer_key, MIN(order_month) AS cohort_month
				FROM ({$source_sql}) src2
				GROUP BY customer_key
			) cs
			GROUP BY cohort_month
		) cohort_sizes ON cohort_sizes.cohort_month = cohorts.cohort_month
		INNER JOIN ({$source_sql}) source
			ON source.customer_key = cohorts.customer_key
			AND source.order_month >= cohorts.cohort_month
		WHERE cohorts.cohort_month >= %s
		GROUP BY cohorts.cohort_month, period_offset, cohort_sizes.cohort_size
		ON DUPLICATE KEY UPDATE
			cohort_size = VALUES(cohort_size),
			retained_customers = VALUES(retained_customers),
			retention_rate = VALUES(retention_rate),
			computed_at = CURRENT_TIMESTAMP
	";

	$result = $wpdb->query( $wpdb->prepare( $matrix_sql, $cutoff_date ) ); // phpcs:ignore
	if ( $result === false ) {
		throw new \RuntimeException( 'Cohort retention recompute failed: ' . $wpdb->last_error );
	}

	$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" ); // phpcs:ignore
	$duration  = round( microtime( true ) - $start_ts, 3 );

	error_log( sprintf(
		'[BrikPanel CA] Cohort retention recompute: rows=%d, duration=%ss, hpos=%s',
		$row_count,
		$duration,
		$hpos ? '1' : '0'
	) );

	return [
		'rows_written' => $row_count,
		'duration'     => $duration,
	];
}

// =============================================================================
// CRON REGISTRATION
// =============================================================================
add_action( 'brikpanel_cron_register', function () {
	if ( ! class_exists( 'Brikpanel_Cron' ) ) {
		return;
	}

	Brikpanel_Cron::register_handler(
		'brikpanel_recompute_customer_metrics',
		'brikpanel_recompute_customer_metrics_handler',
		[
			'label'       => __( 'Recompute Customer Metrics (LTV + RFM)', 'brikpanel' ),
			'description' => __( 'Aggregates per-customer LTV totals from order history.', 'brikpanel' ),
		]
	);

	Brikpanel_Cron::register_handler(
		'brikpanel_recompute_cohort_retention',
		'brikpanel_recompute_cohort_retention_handler',
		[
			'label'       => __( 'Recompute Cohort Retention', 'brikpanel' ),
			'description' => __( 'Builds the monthly cohort × period retention matrix.', 'brikpanel' ),
		]
	);

	// Schedule the first runs for ~03:00 / 03:30 GMT tomorrow, daily after that.
	$now       = time();
	$next_3am  = strtotime( gmdate( 'Y-m-d', $now + DAY_IN_SECONDS ) . ' 03:00:00 GMT' );
	$next_330  = strtotime( gmdate( 'Y-m-d', $now + DAY_IN_SECONDS ) . ' 03:30:00 GMT' );

	Brikpanel_Cron::schedule_recurring(
		'brikpanel_recompute_customer_metrics',
		DAY_IN_SECONDS,
		[],
		max( 60, $next_3am - $now )
	);

	Brikpanel_Cron::schedule_recurring(
		'brikpanel_recompute_cohort_retention',
		DAY_IN_SECONDS,
		[],
		max( 60, $next_330 - $now )
	);
} );
