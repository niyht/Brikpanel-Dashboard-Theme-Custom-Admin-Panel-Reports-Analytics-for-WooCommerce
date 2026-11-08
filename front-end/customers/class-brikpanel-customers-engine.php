<?php
/**
 * BrikPanel Customers: the one customer list behind the Customers page.
 *
 * Every screen that lists or counts customers asks this class: the All
 * customers tab and its CSV export, the customer page, Ctrl+K and the bell's
 * "New customers today". Before it, WooCommerce's report, Segments and
 * Customer Analytics counted customers three different ways, and one person
 * could show three different totals.
 *
 * Who is a customer here:
 * - a registered user, keyed u:<user id>, or a guest, keyed e:<lowercased
 *   billing email>. These are the keys of the customer metrics table, so the
 *   RFM segment joins straight onto them;
 * - everyone WooCommerce's Customers report lists: registered users with a
 *   customer role and no order yet, and the person behind every real order,
 *   even one still pending or one that failed or was cancelled. Their paid
 *   order count is then 0;
 * - never an order without an email, and never one WordPress's privacy eraser
 *   anonymised (deleted@site.invalid). Both used to collapse into one huge
 *   "guest" with dozens of orders.
 *
 * Order count, total spent and first and last order only count the statuses
 * of brikpanel_ltv_counted_statuses() (paid plus refunded), in the store's
 * base currency, the same numbers the dashboard and Customer Analytics show.
 *
 * Speed: on legacy (posts) storage every order field is joined once, never
 * read with a subquery per order (a subquery per order made the old Segments
 * list about 13 times slower than on HPOS: 1.14 s against 0.09 s for 2,527
 * orders). Names, phones and places are looked up only for the rows a page
 * shows, from each customer's latest order.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Brikpanel_Customers_Engine {

	/** Billing email WordPress's privacy eraser writes into an anonymised order. */
	const ERASED_EMAIL = 'deleted@site.invalid';

	/**
	 * Rows per CSV batch. Each batch reads the whole aggregate again, so fewer
	 * batches is faster; each costs about 10 MB of memory per 1,000 rows. On
	 * 100,000 orders (24,600 customers): 2,000 took 11.5 s, 5,000 took 5.9 s
	 * with 49 MB at its peak.
	 */
	const EXPORT_BATCH = 5000;

	/** Order statuses that are not real orders yet (or any more). */
	const NOT_ORDERS = [ 'trash', 'auto-draft', 'wc-auto-draft', 'draft', 'wc-checkout-draft', 'checkout-draft' ];

	/** @var bool|null Totals on the page query; null until window_totals() asks the server. */
	private static $window_totals = null;

	// =========================================================================
	// Basics
	// =========================================================================

	/**
	 * Whether orders live in WooCommerce's own tables.
	 *
	 * @return bool
	 */
	public static function hpos() {
		if ( function_exists( 'brikpanel_wc_hpos_enabled' ) ) {
			return (bool) brikpanel_wc_hpos_enabled();
		}
		return 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' );
	}

	/**
	 * Every filter the list understands, with its empty value. The same keys
	 * Segments' filter parser produces, plus the first-order window.
	 *
	 * @param array $f Values to keep.
	 * @return array
	 */
	public static function filters( array $f = [] ) {
		$defaults = [
			'date_from'           => '',
			'date_to'             => '',
			'countries'           => [],
			'city'                => '',
			'product_ids'         => [],
			'exclude_product_ids' => [],
			'category_ids'        => [],
			'search'              => '',
			'order_count_min'     => null,
			'order_count_max'     => null,
			'spent_min'           => null,
			'spent_max'           => null,
			'last_order_from'     => '',
			'last_order_to'       => '',
			'first_order_from'    => '',
			'first_order_to'      => '',
			'registered_from'     => '',
			'registered_to'       => '',
			'rfm_segments'        => [],
			'page'                => 1,
			'per_page'            => 25,
			'sort'                => '',
			'order'               => 'DESC',
		];
		$f = array_merge( $defaults, array_intersect_key( $f, $defaults ) );
		$f['page']     = max( 1, (int) $f['page'] );
		$f['per_page'] = min( 10000, max( 1, (int) $f['per_page'] ) );
		$f['order']    = 'ASC' === strtoupper( (string) $f['order'] ) ? 'ASC' : 'DESC';
		return $f;
	}

	/**
	 * True when a filter narrows the orders themselves (where, what, when).
	 * Then the list shows only the customers those paid orders belong to, as it
	 * always has; people without such an order cannot match it.
	 *
	 * @param array $f Filters.
	 * @return bool
	 */
	private static function has_order_filters( array $f ) {
		return ! empty( $f['countries'] ) || '' !== $f['city'] || ! empty( $f['product_ids'] )
			|| ! empty( $f['category_ids'] ) || '' !== $f['date_from'] || '' !== $f['date_to'];
	}

	/**
	 * Half-open store-day window (local Y-m-d, upper day inclusive) on a UTC
	 * column. The upper bound is exclusive so adjacent windows never overlap.
	 *
	 * @param string $column  UTC column or alias.
	 * @param string $from    Local 'Y-m-d' or ''.
	 * @param string $to      Local 'Y-m-d' (inclusive) or ''.
	 * @param array  $clauses By reference.
	 * @param array  $params  By reference.
	 * @return void
	 */
	public static function day_window( $column, $from, $to, array &$clauses, array &$params ) {
		$bounds = brikpanel_local_range_bounds_utc( $from, $to );
		if ( '' !== $bounds['start'] ) {
			$clauses[] = $column . ' >= %s';
			$params[]  = $bounds['start'];
		}
		if ( '' !== $bounds['end_exclusive'] ) {
			$clauses[] = $column . ' < %s';
			$params[]  = $bounds['end_exclusive'];
		}
	}

	/**
	 * SQL that is true when an order has a line of any of the given products,
	 * read from the order's own line items (written with the order, unlike
	 * WooCommerce's analytics lookup table). A variation's line keeps its
	 * parent in _product_id, so a variable product matches every variation.
	 *
	 * @param string $order_col Order id column of the outer query.
	 * @param int[]  $ids       Product or variation ids.
	 * @param string $alias     Alias prefix, unique within the query.
	 * @return array{0: string, 1: string[]}
	 */
	public static function product_line_exists_sql( $order_col, array $ids, $alias ) {
		global $wpdb;

		$ids          = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$li           = $alias . 'li';
		$im           = $alias . 'im';

		$sql = "EXISTS (SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items {$li}
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta {$im}
					ON {$im}.order_item_id = {$li}.order_item_id AND {$im}.meta_key IN ('_product_id','_variation_id')
				WHERE {$li}.order_id = {$order_col} AND {$li}.order_item_type = 'line_item' AND {$im}.meta_value IN ({$placeholders}))";

		return [ $sql, array_map( 'strval', $ids ) ];
	}

	/**
	 * Statuses whose orders do not make someone a customer: drafts and trash,
	 * exactly what WooCommerce's analytics importer skips. WooCommerce's
	 * Customers report therefore lists the person behind a failed or
	 * cancelled order too (with no paid orders), and so does this list; those
	 * rows sort last, behind everyone who has paid.
	 *
	 * @return string[]
	 */
	private static function unlisted_statuses() {
		return self::NOT_ORDERS;
	}

	// =========================================================================
	// Customer keys
	// =========================================================================

	/**
	 * A key as it may arrive in a URL, checked: u:<user id> or e:<email>.
	 *
	 * @param mixed $raw Raw value (already unslashed).
	 * @return array{type: string, id: int, email: string, key: string}|null
	 */
	public static function parse_key( $raw ) {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$raw = trim( $raw );
		if ( preg_match( '/^u:([1-9]\d{0,19})$/', $raw, $m ) ) {
			return [ 'type' => 'u', 'id' => (int) $m[1], 'email' => '', 'key' => 'u:' . (int) $m[1] ];
		}
		if ( 0 === strpos( $raw, 'e:' ) ) {
			$email = brikpanel_strtolower( substr( $raw, 2 ) );
			if ( '' === $email || strlen( $email ) > 254 || ! is_email( $email ) || sanitize_email( $email ) !== $email
				|| self::ERASED_EMAIL === $email ) {
				return null;
			}
			return [ 'type' => 'e', 'id' => 0, 'email' => $email, 'key' => 'e:' . $email ];
		}
		return null;
	}

	/**
	 * Address of a customer's page.
	 *
	 * The key is percent-encoded with rawurlencode(): add_query_arg() leaves
	 * values as they are, and a "+" in an email would come back as a space.
	 *
	 * @param string $key Customer key.
	 * @return string
	 */
	public static function profile_url( $key ) {
		return admin_url( 'admin.php?page=brikpanel-customers&customer=' . rawurlencode( (string) $key ) );
	}

	// =========================================================================
	// The list
	// =========================================================================

	/**
	 * A legacy (posts) order's four list fields, read from postmeta in one
	 * pass grouped by order, instead of four joins per order. Measured on a
	 * 2,527-order store: 0.05 s against 0.34 s, and the gap grows with the
	 * store. Each field is the key's FIRST row (lowest meta_id), the row
	 * get_post_meta() returns, so a duplicated meta row is never counted.
	 *
	 * @param string $restrict Extra condition on pm (already safe SQL), or ''.
	 * @return string
	 */
	private static function legacy_meta_sql( $restrict = '' ) {
		global $wpdb;

		$base  = esc_sql( BRIKPANEL_BASE_TOTAL_META );
		$first = static function ( $key ) {
			return "SUBSTRING_INDEX(GROUP_CONCAT(IF(pm.meta_key = '{$key}', pm.meta_value, NULL) ORDER BY pm.meta_id SEPARATOR 0x1f), 0x1f, 1)";
		};
		return 'SELECT pm.post_id, ' . $first( '_customer_user' ) . ' AS cu, ' . $first( '_billing_email' ) . ' AS em, '
			. $first( '_order_total' ) . ' AS tot, ' . $first( $base ) . " AS base
			FROM {$wpdb->postmeta} pm
			WHERE pm.meta_key IN ('_customer_user', '_billing_email', '_order_total', '{$base}'){$restrict}
			GROUP BY pm.post_id";
	}

	/**
	 * Where the orders come from, with every column the aggregate needs.
	 *
	 * @param bool   $hpos     WooCommerce's own order tables.
	 * @param bool   $everyone Count every real order, not just the paid ones.
	 * @param string $restrict Legacy only: condition limiting the orders read
	 *                         (see legacy_meta_sql()), or ''.
	 * @return array
	 */
	private static function order_source( $hpos, $everyone, $restrict = '' ) {
		global $wpdb;

		$counted_in = brikpanel_statuses_sql_in( brikpanel_ltv_counted_statuses() );
		$not_orders = "'" . implode( "','", array_map( 'esc_sql', self::unlisted_statuses() ) ) . "'";
		$erased     = esc_sql( self::ERASED_EMAIL );

		if ( $hpos ) {
			$fx    = brikpanel_base_total_sql( true, 'o.id', 'o.total_amount', 'bpcufx' );
			$where = [
				"o.type = 'shop_order'",
				$everyone ? "o.status NOT IN ({$not_orders})" : "o.status IN ({$counted_in})",
				// A guest needs a real email: blank ones and the eraser's
				// placeholder would otherwise each become one giant customer.
				"(o.customer_id > 0 OR (o.billing_email IS NOT NULL AND o.billing_email <> '' AND LOWER(o.billing_email) <> '{$erased}'))",
			];
			return [
				'from'    => "{$wpdb->prefix}wc_orders o" . $fx['join'],
				'where'   => $where,
				'key'     => "IF(o.customer_id > 0, CONCAT('u:', o.customer_id), CONCAT('e:', LOWER(o.billing_email)))",
				'uid'     => 'o.customer_id',
				'email'   => 'LOWER(o.billing_email)',
				'date'    => 'o.date_created_gmt',
				'id'      => 'o.id',
				'counted' => "o.status IN ({$counted_in})",
				'total'   => $fx['expr'],
			];
		}

		$where = [
			"o.post_type = 'shop_order'",
			$everyone ? "o.post_status NOT IN ({$not_orders})" : "o.post_status IN ({$counted_in})",
			"(IFNULL(m.cu + 0, 0) > 0 OR (m.em IS NOT NULL AND m.em <> '' AND LOWER(m.em) <> '{$erased}'))",
		];
		return [
			'from'    => "{$wpdb->posts} o INNER JOIN (" . self::legacy_meta_sql( $restrict ) . ') m ON m.post_id = o.ID',
			'where'   => $where,
			'key'     => "IF(IFNULL(m.cu + 0, 0) > 0, CONCAT('u:', m.cu + 0), CONCAT('e:', LOWER(m.em)))",
			'uid'     => 'IFNULL(m.cu + 0, 0)',
			'email'   => 'LOWER(m.em)',
			'date'    => 'o.post_date_gmt',
			'id'      => 'o.ID',
			'counted' => "o.post_status IN ({$counted_in})",
			// Same rule as brikpanel_base_total_sql(): the stored store-currency
			// total when there is one, otherwise the order's own total.
			'total'   => "COALESCE(NULLIF(m.base, ''), CAST(m.tot AS DECIMAL(20,4)))",
		];
	}

	/**
	 * Order-level filters: country, city, products, categories, order dates.
	 *
	 * @param array $f      Filters.
	 * @param bool  $hpos   Storage.
	 * @param array $where  By reference.
	 * @param array $params By reference.
	 * @return void
	 */
	private static function add_order_filters( array $f, $hpos, array &$where, array &$params ) {
		global $wpdb;

		if ( ! empty( $f['countries'] ) ) {
			$ph = implode( ',', array_fill( 0, count( $f['countries'] ), '%s' ) );
			$where[] = $hpos
				? "EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_order_addresses oa WHERE oa.order_id = o.id AND oa.address_type = 'billing' AND oa.country IN ({$ph}))"
				: "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_co WHERE pm_co.post_id = o.ID AND pm_co.meta_key = '_billing_country' AND pm_co.meta_value IN ({$ph}))";
			$params = array_merge( $params, array_values( $f['countries'] ) );
		}
		if ( '' !== $f['city'] ) {
			$where[] = $hpos
				? "EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_order_addresses oa2 WHERE oa2.order_id = o.id AND oa2.address_type = 'billing' AND oa2.city LIKE %s)"
				: "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_ci WHERE pm_ci.post_id = o.ID AND pm_ci.meta_key = '_billing_city' AND pm_ci.meta_value LIKE %s)";
			$params[] = '%' . $wpdb->esc_like( $f['city'] ) . '%';
		}
		if ( ! empty( $f['product_ids'] ) ) {
			list( $sql, $p ) = self::product_line_exists_sql( $hpos ? 'o.id' : 'o.ID', $f['product_ids'], 'inc' );
			$where[] = $sql;
			$params  = array_merge( $params, $p );
		}
		if ( ! empty( $f['category_ids'] ) ) {
			$ph  = implode( ',', array_fill( 0, count( $f['category_ids'] ), '%d' ) );
			$col = $hpos ? 'o.id' : 'o.ID';
			$where[] = "EXISTS (
				SELECT 1 FROM {$wpdb->prefix}wc_order_product_lookup opl2
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = opl2.product_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
				WHERE opl2.order_id = {$col} AND tt.term_id IN ({$ph})
			)";
			$params = array_merge( $params, array_map( 'absint', $f['category_ids'] ) );
		}
		self::day_window( $hpos ? 'o.date_created_gmt' : 'o.post_date_gmt', $f['date_from'], $f['date_to'], $where, $params );
	}

	/**
	 * Roles WooCommerce's Customers report treats as customers.
	 *
	 * @return string[]
	 */
	private static function customer_roles() {
		/** This filter is documented in WooCommerce (CustomersScheduler). */
		$roles = apply_filters( 'woocommerce_analytics_import_customer_roles', [ 'customer' ] );
		$roles = is_array( $roles ) ? array_values( array_filter( array_map( 'sanitize_key', $roles ) ) ) : [];
		return $roles ? $roles : [ 'customer' ];
	}

	/**
	 * The per-customer aggregate (and, when nobody narrowed the orders, the
	 * registered customers who have no order), as one derived table.
	 *
	 * @param array $f      Filters.
	 * @param bool  $hpos   Storage.
	 * @param array $params By reference.
	 * @return string SQL of the derived table, without its alias.
	 */
	private static function base_sql( array $f, $hpos, array &$params ) {
		global $wpdb;

		$everyone = ! self::has_order_filters( $f );
		$src      = self::order_source( $hpos, $everyone );
		$where    = $src['where'];

		// Staff and point-of-sale accounts the merchant excluded, as in
		// Customer Analytics. Guests (id 0) are never matched.
		if ( function_exists( 'brikpanel_excluded_customer_sql' ) ) {
			$excl = brikpanel_excluded_customer_sql( $src['uid'] );
			if ( '' !== $excl ) {
				$where[] = ltrim( substr( $excl, 5 ) );
			}
		}
		self::add_order_filters( $f, $hpos, $where, $params );

		$c   = $src['counted'];
		$sql = "SELECT {$src['key']} AS customer_key,
				MAX({$src['uid']}) AS user_id,
				MAX({$src['email']}) AS email,
				SUM(IF({$c}, 1, 0)) AS order_count,
				COALESCE(SUM(IF({$c}, {$src['total']}, 0)), 0) AS total_spent,
				MAX(IF({$c}, {$src['date']}, NULL)) AS last_order_date,
				MIN(IF({$c}, {$src['date']}, NULL)) AS first_order_date,
				MIN({$src['date']}) AS first_seen,
				MAX({$src['id']}) AS latest_order_id
			FROM {$src['from']}
			WHERE " . implode( ' AND ', $where ) . '
			GROUP BY customer_key';

		if ( ! $everyone ) {
			return $sql;
		}

		// Registered customers who never ordered: WooCommerce lists them too.
		// The role lives in the site's own capabilities row (blog prefix), so a
		// multisite network shows each site only its own members. Users who have
		// any real order are already above; the anti-join reads the set of
		// ordering users once instead of probing orders per user.
		$cap_key = $wpdb->get_blog_prefix() . 'capabilities';
		$likes   = [];
		foreach ( self::customer_roles() as $role ) {
			$likes[]  = 'cap.meta_value LIKE %s';
			$params[] = '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%';
		}
		$not_orders = "'" . implode( "','", array_map( 'esc_sql', self::unlisted_statuses() ) ) . "'";
		$ordering   = $hpos
			? "SELECT DISTINCT o.customer_id AS uid FROM {$wpdb->prefix}wc_orders o WHERE o.type = 'shop_order' AND o.customer_id > 0 AND o.status NOT IN ({$not_orders})"
			: "SELECT DISTINCT (cu.meta_value + 0) AS uid FROM {$wpdb->posts} o " . brikpanel_sql_single_meta_join( 'post', 'cu', 'o.ID', '_customer_user', '', 'INNER' )
				. " WHERE o.post_type = 'shop_order' AND o.post_status NOT IN ({$not_orders}) AND cu.meta_value + 0 > 0";
		$excl_u = function_exists( 'brikpanel_excluded_customer_sql' ) ? brikpanel_excluded_customer_sql( 'u.ID' ) : '';

		$zero = "SELECT CONCAT('u:', u.ID) AS customer_key,
				u.ID AS user_id,
				LOWER(u.user_email) AS email,
				0 AS order_count,
				0 AS total_spent,
				NULL AS last_order_date,
				NULL AS first_order_date,
				u.user_registered AS first_seen,
				0 AS latest_order_id
			FROM {$wpdb->users} u
			INNER JOIN {$wpdb->usermeta} cap ON cap.user_id = u.ID AND cap.meta_key = '" . esc_sql( $cap_key ) . "'
			LEFT JOIN ({$ordering}) ou ON ou.uid = u.ID
			WHERE ou.uid IS NULL AND (" . implode( ' OR ', $likes ) . "){$excl_u}";

		return "({$sql}) UNION ALL ({$zero})";
	}

	/**
	 * Joins and conditions applied to the aggregate. The joins come before the
	 * WHERE clause in the query, so their values come first in $params.
	 *
	 * @param array $f      Filters.
	 * @param bool  $hpos   Storage.
	 * @param array $params By reference: join values, then condition values.
	 * @return array{join: string, where: string}
	 */
	private static function outer_sql( array $f, $hpos, array &$params ) {
		global $wpdb;

		$join        = " LEFT JOIN {$wpdb->users} u ON u.ID = agg.user_id AND agg.user_id > 0";
		$join_params = [];
		$where       = [];
		$cond        = [];

		if ( null !== $f['spent_min'] ) {
			$where[] = 'agg.total_spent >= %f';
			$cond[]  = (float) $f['spent_min'];
		}
		if ( null !== $f['spent_max'] ) {
			$where[] = 'agg.total_spent <= %f';
			$cond[]  = (float) $f['spent_max'];
		}
		if ( null !== $f['order_count_min'] ) {
			$where[] = 'agg.order_count >= %d';
			$cond[]  = (int) $f['order_count_min'];
		}
		if ( null !== $f['order_count_max'] ) {
			$where[] = 'agg.order_count <= %d';
			$cond[]  = (int) $f['order_count_max'];
		}
		self::day_window( 'agg.last_order_date', $f['last_order_from'], $f['last_order_to'], $where, $cond );
		self::day_window( 'agg.first_order_date', $f['first_order_from'], $f['first_order_to'], $where, $cond );
		// wp_users.user_registered is UTC.
		self::day_window( 'u.user_registered', $f['registered_from'], $f['registered_to'], $where, $cond );

		if ( '' !== $f['search'] ) {
			$like = '%' . $wpdb->esc_like( $f['search'] ) . '%';
			// The account's billing name, and the latest order's billing name
			// and phone (how guests are found): joined only while searching.
			$join .= ' ' . brikpanel_sql_single_meta_join( 'user', 'sfn', 'u.ID', 'billing_first_name' )
				. ' ' . brikpanel_sql_single_meta_join( 'user', 'sln', 'u.ID', 'billing_last_name' );
			if ( $hpos ) {
				$join .= " LEFT JOIN {$wpdb->prefix}wc_order_addresses sa ON sa.order_id = agg.latest_order_id AND sa.address_type = 'billing'";
				$order_name  = "CONCAT_WS(' ', sa.first_name, sa.last_name)";
				$order_phone = 'sa.phone';
			} else {
				$join .= ' ' . brikpanel_sql_single_meta_join( 'post', 'ofn', 'agg.latest_order_id', '_billing_first_name' )
					. ' ' . brikpanel_sql_single_meta_join( 'post', 'oln', 'agg.latest_order_id', '_billing_last_name' )
					. ' ' . brikpanel_sql_single_meta_join( 'post', 'oph', 'agg.latest_order_id', '_billing_phone' );
				$order_name  = "CONCAT_WS(' ', ofn.meta_value, oln.meta_value)";
				$order_phone = 'oph.meta_value';
			}
			$where[] = "(agg.email LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR CONCAT_WS(' ', sfn.meta_value, sln.meta_value) LIKE %s OR {$order_name} LIKE %s OR {$order_phone} LIKE %s)";
			array_push( $cond, $like, $like, $like, $like, $like, $like );
		}

		if ( ! empty( $f['rfm_segments'] ) ) {
			$ph          = implode( ',', array_fill( 0, count( $f['rfm_segments'] ), '%s' ) );
			$join       .= " INNER JOIN {$wpdb->prefix}brikpanel_customer_metrics rfm ON rfm.customer_key = agg.customer_key AND rfm.rfm_segment IN ({$ph})";
			$join_params = array_merge( $join_params, array_values( $f['rfm_segments'] ) );
		}

		// Leave out everyone who ever bought one of these products in a paid
		// order. The key list is read once; NULL keys stay out of it, because
		// one NULL in a NOT IN list makes the test unknown for every customer.
		if ( ! empty( $f['exclude_product_ids'] ) ) {
			$src = self::order_source( $hpos, false );
			list( $pl, $pp ) = self::product_line_exists_sql( $hpos ? 'o.id' : 'o.ID', $f['exclude_product_ids'], 'exc' );
			$where[] = "agg.customer_key NOT IN (SELECT exk.ck FROM (SELECT DISTINCT {$src['key']} AS ck FROM {$src['from']} WHERE "
				. implode( ' AND ', $src['where'] ) . " AND {$pl}) exk WHERE exk.ck IS NOT NULL)";
			$cond = array_merge( $cond, $pp );
		}

		$params = array_merge( $params, $join_params, $cond );
		return [
			'join'  => $join,
			'where' => $where ? ( ' WHERE ' . implode( ' AND ', $where ) ) : '',
		];
	}

	/**
	 * ORDER BY for a sort key. NULLs (no paid order yet) always go last, and
	 * the customer key breaks ties so pages never repeat or skip a row.
	 *
	 * @param string $sort  Sort key.
	 * @param string $order ASC|DESC.
	 * @param string $since SQL of the customer-since date.
	 * @return string
	 */
	private static function order_by( $sort, $order, $since ) {
		$dir = 'ASC' === $order ? 'ASC' : 'DESC';
		switch ( $sort ) {
			case 'spent':
				$col = 'agg.total_spent';
				break;
			case 'count':
				$col = 'agg.order_count';
				break;
			case 'aov':
				$col = 'IF(agg.order_count > 0, agg.total_spent / agg.order_count, NULL)';
				break;
			case 'first_order':
				$col = 'agg.first_order_date';
				break;
			case 'since':
				$col = $since;
				break;
			default:
				$col = 'agg.last_order_date';
		}
		return "ISNULL({$col}) ASC, {$col} {$dir}, agg.customer_key ASC";
	}

	/**
	 * One page of the customer list, its total and its sums.
	 *
	 * @param array $f           Filters (see filters()).
	 * @param bool  $with_totals Count the whole list too (skipped by the export).
	 * @return array{items: array, total: int, page: int, per_page: int, pages: int, summary: array}
	 */
	public static function query( array $f, $with_totals = true ) {
		global $wpdb;

		$f    = self::filters( $f );
		$hpos = self::hpos();

		$base_params  = [];
		$base         = self::base_sql( $f, $hpos, $base_params );
		$outer_params = [];
		$outer        = self::outer_sql( $f, $hpos, $outer_params );
		$params       = array_merge( $base_params, $outer_params );
		$since        = "IF(u.user_registered IS NOT NULL AND u.user_registered > '1970-01-02' AND (agg.first_seen IS NULL OR u.user_registered < agg.first_seen), u.user_registered, agg.first_seen)";

		$total      = 0;
		$sum_spent  = 0.0;
		$sum_orders = 0;
		$count      = static function () use ( $wpdb, $base, $outer, $params, &$total, &$sum_spent, &$sum_orders ) {
			$count_sql = "SELECT COUNT(*) AS n, COALESCE(SUM(agg.total_spent), 0) AS spent, COALESCE(SUM(agg.order_count), 0) AS orders
				FROM ({$base}) agg {$outer['join']}{$outer['where']}";
			$row = $wpdb->get_row( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from literals and placeholders above.
			$total      = (int) ( $row->n ?? 0 );
			$sum_spent  = (float) ( $row->spent ?? 0 );
			$sum_orders = (int) ( $row->orders ?? 0 );
		};

		// Where the database runs window functions, the totals ride on the page
		// query instead of a second pass over every order: 0.8 s instead of
		// 1.4 s for 100,000 orders. Older servers count separately.
		$windowed = $with_totals && self::window_totals();
		if ( $with_totals && ! $windowed ) {
			$count();
		}

		$offset   = ( $f['page'] - 1 ) * $f['per_page'];
		$totals   = $windowed ? 'COUNT(*) OVER () AS bp_rows, SUM(agg.total_spent) OVER () AS bp_spent, SUM(agg.order_count) OVER () AS bp_orders, ' : '';
		$page_sql = "SELECT agg.*, {$totals}u.user_email AS account_email, u.display_name, u.user_registered, {$since} AS since_date
			FROM ({$base}) agg {$outer['join']}{$outer['where']}
			ORDER BY " . self::order_by( $f['sort'], $f['order'], $since ) . '
			LIMIT %d OFFSET %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $page_sql, array_merge( $params, [ $f['per_page'], $offset ] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from literals and placeholders above.
		if ( $windowed ) {
			if ( ! empty( $rows ) ) {
				$total      = (int) $rows[0]->bp_rows;
				$sum_spent  = (float) $rows[0]->bp_spent;
				$sum_orders = (int) $rows[0]->bp_orders;
			} elseif ( $f['page'] > 1 ) {
				// A page past the end has no row to carry them.
				$count();
			}
		}

		$items = self::hydrate( is_array( $rows ) ? $rows : [], $hpos );

		$aov = $sum_orders > 0 ? $sum_spent / $sum_orders : 0;
		return [
			'items'    => $items,
			'total'    => $total,
			'page'     => $f['page'],
			'per_page' => $f['per_page'],
			'pages'    => $f['per_page'] > 0 ? (int) ceil( $total / $f['per_page'] ) : 0,
			'summary'  => [
				'count'           => $total,
				'revenue'         => $sum_spent,
				'revenue_display' => wp_strip_all_tags( wc_price( $sum_spent ) ),
				'orders'          => $sum_orders,
				'aov'             => $aov,
				'aov_display'     => wp_strip_all_tags( wc_price( $aov ) ),
			],
		];
	}

	/**
	 * Legacy storage: the posts that are this customer's orders by the list's
	 * rule (the first _customer_user row; for a guest, no account and the
	 * first _billing_email row), any status or post type.
	 *
	 * Found through the customer's own meta rows, then checked on those posts
	 * only. Handing the customer's condition to the meta pivot as a subquery
	 * read the meta of every order instead: 5 s for one customer's numbers on
	 * 100,000 orders, against 0.1 s this way.
	 *
	 * @param array $k Parsed key (parse_key()).
	 * @return int[]
	 */
	public static function legacy_order_ids( array $k ) {
		global $wpdb;
		static $memo = [];

		if ( isset( $memo[ $k['key'] ] ) ) {
			return $memo[ $k['key'] ];
		}
		$account = 'u' === $k['type'];
		$found   = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
			$account ? '_customer_user' : '_billing_email',
			$account ? (string) (int) $k['id'] : (string) $k['email']
		) );
		$ids = [];
		foreach ( array_chunk( array_map( 'intval', (array) $found ), 2000 ) as $chunk ) {
			$ph   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from a count.
				"SELECT pm.post_id,
					SUBSTRING_INDEX(GROUP_CONCAT(IF(pm.meta_key = '_customer_user', pm.meta_value, NULL) ORDER BY pm.meta_id SEPARATOR 0x1f), 0x1f, 1) AS cu,
					SUBSTRING_INDEX(GROUP_CONCAT(IF(pm.meta_key = '_billing_email', pm.meta_value, NULL) ORDER BY pm.meta_id SEPARATOR 0x1f), 0x1f, 1) AS em
				FROM {$wpdb->postmeta} pm
				WHERE pm.post_id IN ({$ph}) AND pm.meta_key IN ('_customer_user', '_billing_email')
				GROUP BY pm.post_id",
				$chunk
			) );
			foreach ( (array) $rows as $r ) {
				$cu = (int) $r->cu;
				if ( $account ? $cu === (int) $k['id'] : ( $cu <= 0 && brikpanel_strtolower( (string) $r->em ) === $k['email'] ) ) {
					$ids[] = (int) $r->post_id;
				}
			}
		}
		return $memo[ $k['key'] ] = $ids;
	}

	/**
	 * Whether list totals ride on the page query (window functions: MySQL 8.0,
	 * MariaDB 10.2). tools/test-customers.php sets self::$window_totals to run
	 * both ways on one server.
	 *
	 * @return bool
	 */
	private static function window_totals() {
		if ( null === self::$window_totals ) {
			self::$window_totals = function_exists( 'brikpanel_db_supports_window_functions' ) && brikpanel_db_supports_window_functions();
		}
		return self::$window_totals;
	}

	/**
	 * Every matching customer, in batches, for the CSV export.
	 *
	 * @param array    $f    Filters.
	 * @param callable $emit Called with each item.
	 * @return int Rows emitted.
	 */
	public static function export( array $f, callable $emit ) {
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}
		$f             = self::filters( $f );
		$f['per_page'] = self::EXPORT_BATCH;
		$f['page']     = 1;
		$n             = 0;
		do {
			$res = self::query( $f, false );
			foreach ( $res['items'] as $item ) {
				$emit( $item );
				$n++;
			}
			$f['page']++;
		} while ( count( $res['items'] ) === self::EXPORT_BATCH );
		return $n;
	}

	/**
	 * Names, phones, places and RFM segments for the rows of one page, read
	 * from each customer's latest order and account in four small queries.
	 *
	 * @param object[] $rows Aggregate rows.
	 * @param bool     $hpos Storage.
	 * @return array[]
	 */
	private static function hydrate( array $rows, $hpos ) {
		global $wpdb;

		if ( ! $rows ) {
			return [];
		}

		$order_ids = [];
		$user_ids  = [];
		$keys      = [];
		foreach ( $rows as $r ) {
			if ( (int) $r->latest_order_id > 0 ) {
				$order_ids[] = (int) $r->latest_order_id;
			}
			if ( (int) $r->user_id > 0 ) {
				$user_ids[] = (int) $r->user_id;
			}
			$keys[] = (string) $r->customer_key;
		}
		$order_ids = array_values( array_unique( $order_ids ) );
		$user_ids  = array_values( array_unique( $user_ids ) );
		$decimals  = wc_get_price_decimals();

		// Latest order's billing details.
		$orders = [];
		if ( $order_ids ) {
			$ph = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
			if ( $hpos ) {
				$res = $wpdb->get_results( $wpdb->prepare(
					"SELECT order_id AS id, first_name, last_name, phone, city, country FROM {$wpdb->prefix}wc_order_addresses WHERE address_type = 'billing' AND order_id IN ({$ph})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$order_ids
				) );
				foreach ( (array) $res as $o ) {
					$orders[ (int) $o->id ] = [ 'first_name' => (string) $o->first_name, 'last_name' => (string) $o->last_name, 'phone' => (string) $o->phone, 'city' => (string) $o->city, 'country' => (string) $o->country ];
				}
			} else {
				$map = [ '_billing_first_name' => 'first_name', '_billing_last_name' => 'last_name', '_billing_phone' => 'phone', '_billing_city' => 'city', '_billing_country' => 'country' ];
				$res = $wpdb->get_results( $wpdb->prepare(
					"SELECT post_id AS id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$ph}) AND meta_key IN ('_billing_first_name','_billing_last_name','_billing_phone','_billing_city','_billing_country') ORDER BY meta_id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$order_ids
				) );
				foreach ( (array) $res as $m ) {
					$id = (int) $m->id;
					$k  = $map[ $m->meta_key ];
					if ( ! isset( $orders[ $id ][ $k ] ) ) {
						$orders[ $id ][ $k ] = (string) $m->meta_value;
					}
				}
			}
		}

		// The account's own billing details.
		$users = [];
		if ( $user_ids ) {
			$ph  = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
			$res = $wpdb->get_results( $wpdb->prepare(
				"SELECT user_id AS id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id IN ({$ph}) AND meta_key IN ('billing_first_name','billing_last_name','billing_phone','billing_city','billing_country') ORDER BY umeta_id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_ids
			) );
			foreach ( (array) $res as $m ) {
				$id = (int) $m->id;
				$k  = substr( (string) $m->meta_key, 8 ); // strip "billing_"
				if ( ! isset( $users[ $id ][ $k ] ) ) {
					$users[ $id ][ $k ] = (string) $m->meta_value;
				}
			}
		}

		// RFM segment, where the nightly table has the customer.
		$rfm = [];
		if ( $keys ) {
			$ph  = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			$res = $wpdb->get_results( $wpdb->prepare(
				"SELECT customer_key, rfm_segment FROM {$wpdb->prefix}brikpanel_customer_metrics WHERE customer_key IN ({$ph})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$keys
			) );
			foreach ( (array) $res as $m ) {
				$rfm[ (string) $m->customer_key ] = (string) $m->rfm_segment;
			}
		}
		$labels = function_exists( 'brikpanel_ca_rfm_segment_labels' ) ? brikpanel_ca_rfm_segment_labels() : [];

		$items = [];
		foreach ( $rows as $r ) {
			$uid   = (int) $r->user_id;
			$key   = (string) $r->customer_key;
			$o     = $orders[ (int) $r->latest_order_id ] ?? [];
			$u     = $users[ $uid ] ?? [];
			$guest = 0 !== strpos( $key, 'u:' );

			$account_name = trim( ( $u['first_name'] ?? '' ) . ' ' . ( $u['last_name'] ?? '' ) );
			$order_name   = trim( ( $o['first_name'] ?? '' ) . ' ' . ( $o['last_name'] ?? '' ) );
			$email        = $guest ? (string) $r->email : (string) ( $r->account_email ?: $r->email );
			if ( $guest ) {
				$name = $order_name;
			} else {
				$name = '' !== $account_name ? $account_name : $order_name;
				if ( '' === $name && function_exists( 'brikpanel_plain_name' ) ) {
					$name = brikpanel_plain_name( (string) $r->display_name );
				}
			}
			if ( '' === $name ) {
				$name = $email;
			}
			$phone   = $guest ? ( $o['phone'] ?? '' ) : ( ( $u['phone'] ?? '' ) !== '' ? $u['phone'] : ( $o['phone'] ?? '' ) );
			$city    = ( $o['city'] ?? '' ) !== '' ? $o['city'] : ( $u['city'] ?? '' );
			$country = ( $o['country'] ?? '' ) !== '' ? $o['country'] : ( $u['country'] ?? '' );

			// Money rounded to the store's decimals, so the CSV and the AJAX
			// answer carry 3259.75, not the sum's float noise (3259.7500000000005).
			$count = (int) $r->order_count;
			$spent = round( (float) $r->total_spent, $decimals );
			$aov   = $count > 0 ? round( $spent / $count, $decimals ) : 0;
			$seg   = $rfm[ $key ] ?? '';
			$label = '';
			if ( '' !== $seg && isset( $labels[ $seg ] ) ) {
				$label = is_array( $labels[ $seg ] ) ? (string) ( $labels[ $seg ]['label'] ?? $seg ) : (string) $labels[ $seg ];
			}

			$items[] = [
				'key'                 => $key,
				'user_id'             => $uid,
				'name'                => $name,
				'email'               => $email,
				'phone'               => (string) $phone,
				'city'                => (string) $city,
				'country'             => (string) $country,
				'location'            => trim( implode( ', ', array_filter( [ (string) $city, (string) $country ] ) ) ),
				// wp_users.user_registered and every *_date column here are UTC.
				'registered'          => brikpanel_local_date( $r->user_registered, $guest ? __( 'Guest', 'brikpanel' ) : '' ),
				'registered_export'   => brikpanel_local_datetime( $r->user_registered, 'Y-m-d H:i:s' ),
				'order_count'         => $count,
				'total_spent'         => $spent,
				'total_spent_display' => wp_strip_all_tags( wc_price( $spent ) ),
				'aov'                 => $aov,
				'aov_display'         => wp_strip_all_tags( wc_price( $aov ) ),
				'last_order'          => brikpanel_local_date( $r->last_order_date ),
				'last_order_export'   => brikpanel_local_datetime( $r->last_order_date, 'Y-m-d H:i:s' ),
				'first_order'         => brikpanel_local_date( $r->first_order_date ),
				'first_order_export'  => brikpanel_local_datetime( $r->first_order_date, 'Y-m-d H:i:s' ),
				'since'               => brikpanel_local_date( $r->since_date ),
				'since_export'        => brikpanel_local_datetime( $r->since_date, 'Y-m-d H:i:s' ),
				'rfm'                 => $seg,
				'rfm_label'           => $label,
				'is_guest'            => $guest,
				'profile_url'         => self::profile_url( $key ),
				'edit_url'            => $guest ? '' : admin_url( 'user-edit.php?user_id=' . $uid ),
			];
		}
		return $items;
	}

	// =========================================================================
	// Counts and single customers
	// =========================================================================

	/**
	 * How many customers placed their first paid order in a window: the
	 * number on the bell's "New customers today" row and the list it opens.
	 *
	 * Each order of the window is checked for an earlier paid order of the
	 * same customer on WooCommerce's customer index (customer_id, then the
	 * billing email for guests), so the count stays cheap however large the
	 * store is.
	 *
	 * @param string $from Local 'Y-m-d'.
	 * @param string $to   Local 'Y-m-d' (inclusive).
	 * @return int
	 */
	public static function first_time_buyers( $from, $to ) {
		global $wpdb;

		$bounds = brikpanel_local_range_bounds_utc( $from, $to );
		if ( '' === $bounds['start'] || '' === $bounds['end_exclusive'] ) {
			return 0;
		}
		$hpos       = self::hpos();
		$counted_in = brikpanel_statuses_sql_in( brikpanel_ltv_counted_statuses() );
		$erased     = esc_sql( self::ERASED_EMAIL );
		$excl       = '';

		if ( $hpos ) {
			if ( function_exists( 'brikpanel_excluded_customer_sql' ) ) {
				$excl = brikpanel_excluded_customer_sql( 'o.customer_id' );
			}
			$t   = "{$wpdb->prefix}wc_orders";
			$sql = "SELECT COUNT(DISTINCT IF(o.customer_id > 0, CONCAT('u:', o.customer_id), CONCAT('e:', LOWER(o.billing_email))))
				FROM {$t} o
				WHERE o.type = 'shop_order' AND o.status IN ({$counted_in})
				  AND o.date_created_gmt >= %s AND o.date_created_gmt < %s
				  AND (o.customer_id > 0 OR (o.billing_email IS NOT NULL AND o.billing_email <> '' AND LOWER(o.billing_email) <> '{$erased}'))
				  {$excl}
				  AND (o.customer_id = 0 OR NOT EXISTS (
					SELECT 1 FROM {$t} p
					WHERE p.customer_id = o.customer_id
					  AND p.type = 'shop_order' AND p.status IN ({$counted_in}) AND p.date_created_gmt < %s
				  ))
				  AND (o.customer_id > 0 OR NOT EXISTS (
					SELECT 1 FROM {$t} p
					WHERE p.customer_id = 0 AND p.billing_email = o.billing_email
					  AND p.type = 'shop_order' AND p.status IN ({$counted_in}) AND p.date_created_gmt < %s
				  ))";
			// One look-up per kind of customer, each a plain lookup on the
			// (customer_id, billing_email) index. A single subquery with an OR
			// for the email read every guest order of the store for each order
			// of the window: 40 s for a 30-day window on 100,000 orders.
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $bounds['start'], $bounds['end_exclusive'], $bounds['start'], $bounds['start'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// Legacy storage has no customer index, so the check runs in steps: the
		// window's paid orders by id (posts index), their customers from those
		// orders' meta only, then one pass for which of them paid before it.
		// Handing the window to the meta pivot as a subquery read the meta of
		// every order: 2.5 s for one day on 100,000 orders.
		$window = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the status list is built from literals.
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status IN ({$counted_in}) AND post_date_gmt >= %s AND post_date_gmt < %s",
			$bounds['start'],
			$bounds['end_exclusive']
		) );
		$keys = [];
		foreach ( array_chunk( array_map( 'intval', (array) $window ), 2000 ) as $chunk ) {
			$src   = self::order_source( false, false, ' AND pm.post_id IN (' . implode( ',', $chunk ) . ')' );
			$where = $src['where'];
			if ( function_exists( 'brikpanel_excluded_customer_sql' ) ) {
				$excl = brikpanel_excluded_customer_sql( $src['uid'] );
				if ( '' !== $excl ) {
					$where[] = ltrim( substr( $excl, 5 ) );
				}
			}
			$keys = array_merge( $keys, (array) $wpdb->get_col( "SELECT DISTINCT {$src['key']} FROM {$src['from']} WHERE " . implode( ' AND ', $where ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literals and integers only.
		}
		$keys = array_values( array_unique( $keys ) );
		if ( ! $keys ) {
			return 0;
		}
		$ids    = [];
		$emails = [];
		foreach ( $keys as $key ) {
			if ( 0 === strpos( $key, 'u:' ) ) {
				$ids[] = (string) (int) substr( $key, 2 );
			} else {
				$emails[] = (string) substr( $key, 2 );
			}
		}
		$before = 0;
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$ph      = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$before += (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT COUNT(DISTINCT r.meta_value) FROM {$wpdb->postmeta} r INNER JOIN {$wpdb->posts} p ON p.ID = r.post_id
				WHERE r.meta_key = '_customer_user' AND r.meta_value IN ({$ph})
				  AND p.post_type = 'shop_order' AND p.post_status IN ({$counted_in}) AND p.post_date_gmt < %s",
				array_merge( $chunk, [ $bounds['start'] ] )
			) );
		}
		foreach ( array_chunk( $emails, 500 ) as $chunk ) {
			$ph      = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$before += (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT COUNT(DISTINCT LOWER(r.meta_value)) FROM {$wpdb->postmeta} r INNER JOIN {$wpdb->posts} p ON p.ID = r.post_id
				" . brikpanel_sql_single_meta_join( 'post', 'rc', 'p.ID', '_customer_user' ) . "
				WHERE r.meta_key = '_billing_email' AND r.meta_value IN ({$ph}) AND IFNULL(rc.meta_value + 0, 0) = 0
				  AND p.post_type = 'shop_order' AND p.post_status IN ({$counted_in}) AND p.post_date_gmt < %s",
				array_merge( $chunk, [ $bounds['start'] ] )
			) );
		}
		return max( 0, count( $keys ) - $before );
	}

	/**
	 * One customer's numbers, by the same rules as the list, read with the
	 * customer's own index conditions (never by scanning every order).
	 *
	 * @param array $k                Parsed key (parse_key()).
	 * @param bool  $apply_exclusions Leave out accounts the merchant excluded,
	 *                                as the list does. The customer page reads
	 *                                a person's real numbers and passes false.
	 * @return array{order_count: int, other_count: int, total_spent: float, first_order: string, last_order: string, first_seen: string, latest_order_id: int}|null
	 *         Null when the customer has no real order in this site.
	 */
	public static function stats( array $k, $apply_exclusions = true ) {
		global $wpdb;

		$hpos     = self::hpos();
		$restrict = '';
		$params   = [];
		if ( ! $hpos ) {
			// Only this customer's orders are read, by id (legacy_order_ids()).
			$ids = self::legacy_order_ids( $k );
			if ( ! $ids ) {
				return null;
			}
			$restrict = ' AND pm.post_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
		}
		$src   = self::order_source( $hpos, true, $restrict );
		$where = $src['where'];

		if ( 'u' === $k['type'] ) {
			$where[]  = ( $hpos ? 'o.customer_id' : 'IFNULL(m.cu + 0, 0)' ) . ' = %d';
			$params[] = (int) $k['id'];
		} else {
			$where[]  = $hpos ? 'o.customer_id = 0 AND o.billing_email = %s' : 'IFNULL(m.cu + 0, 0) = 0 AND LOWER(m.em) = %s';
			$params[] = (string) $k['email'];
		}
		if ( $apply_exclusions && function_exists( 'brikpanel_excluded_customer_sql' ) ) {
			$excl = brikpanel_excluded_customer_sql( $src['uid'] );
			if ( '' !== $excl ) {
				$where[] = ltrim( substr( $excl, 5 ) );
			}
		}

		$c   = $src['counted'];
		$sql = "SELECT SUM(IF({$c}, 1, 0)) AS order_count,
				SUM(IF({$c}, 0, 1)) AS other_count,
				COALESCE(SUM(IF({$c}, {$src['total']}, 0)), 0) AS total_spent,
				MIN(IF({$c}, {$src['date']}, NULL)) AS first_order,
				MAX(IF({$c}, {$src['date']}, NULL)) AS last_order,
				MIN({$src['date']}) AS first_seen,
				MAX({$src['id']}) AS latest_order_id,
				COUNT(*) AS n
			FROM {$src['from']}
			WHERE " . implode( ' AND ', $where );
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row || 0 === (int) $row->n ) {
			return null;
		}
		return [
			'order_count'     => (int) $row->order_count,
			'other_count'     => (int) $row->other_count,
			'total_spent'     => (float) $row->total_spent,
			'first_order'     => (string) $row->first_order,
			'last_order'      => (string) $row->last_order,
			'first_seen'      => (string) $row->first_seen,
			'latest_order_id' => (int) $row->latest_order_id,
		];
	}
}
