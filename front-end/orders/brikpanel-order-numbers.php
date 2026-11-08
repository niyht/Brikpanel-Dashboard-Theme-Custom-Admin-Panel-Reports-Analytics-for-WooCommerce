<?php
/**
 * BrikPanel: sequential order numbers.
 *
 * WooCommerce shows the order's database ID as its number. Products,
 * variations, images, coupons and refunds take their IDs from the same
 * counter, so the numbers a shop and its customers see jump: on vermond-test
 * one variable product with five sizes put eight numbers between two orders
 * (93453, then 93462). Stores install a separate plugin for numbers that
 * follow each other; Shopify has them built in. This module gives every new
 * order the next number of its own series, with an optional prefix
 * (WEB-1001) and start number (Lincoln Cristiano, wp.org, 27 October 2026).
 *
 * How it stays safe:
 *
 *   - Off by default. Orders that exist keep the number they show, and a
 *     number never changes once an order has it, also after the switch goes
 *     off again ("display" state below).
 *   - The number lives in the `_order_number` meta, the key WebToffee and
 *     SkyVerge use, so a store switching over keeps the numbers those plugins
 *     gave, and plugins that look orders up by number (Flexible Refund,
 *     BrikPanel's own Ctrl+K search) find ours.
 *   - The next number comes from one atomic UPDATE on a counter row, so two
 *     checkouts in the same second never share one, and a number is checked
 *     against every number and plain ID in use before it is written.
 *   - Only `woocommerce_new_order` hands numbers out. WooCommerce fires it
 *     once an order is really placed (not for checkout drafts or the admin
 *     "Add order" draft), on every path: checkout, block checkout, admin,
 *     REST, wc_create_order(). Status changes, merges and imports of old
 *     orders never renumber anything.
 *
 * States per site:
 *   dormant  switch off and never used, or another numbering plugin is on:
 *            not a single hook is added.
 *   display  switch off after use: numbers given keep showing and searching.
 *   active   switch on: new orders get numbers too.
 *
 * The runtime part loads on every request (checkout, REST, webhooks, cron);
 * the settings card at the bottom only in wp-admin.
 *
 * @package BrikPanel
 */

defined( 'ABSPATH' ) || exit;

define( 'BRIKPANEL_ORDER_NUMBERS_OPT', 'brikpanel_order_numbers' );
define( 'BRIKPANEL_ORDER_NUMBERS_OPT_PREFIX', 'brikpanel_order_numbers_prefix' );
define( 'BRIKPANEL_ORDER_NUMBERS_OPT_USED', 'brikpanel_order_numbers_used' );
// Last number given in the current series. Bumped by SQL only, never through
// update_option(): add_option() is INSERT ... ON DUPLICATE KEY UPDATE and
// could write an older value over a number a parallel checkout just took.
define( 'BRIKPANEL_ORDER_NUMBERS_OPT_LAST', 'brikpanel_order_numbers_last' );
// Which prefix the counter belongs to, the counter value nothing has been
// given from yet ('seed'), and the highest number given under every prefix
// the store moved away from ('done'). Written on settings saves only.
define( 'BRIKPANEL_ORDER_NUMBERS_OPT_SERIES', 'brikpanel_order_numbers_series' );
define( 'BRIKPANEL_ORDER_NUMBERS_META', '_order_number' );
define( 'BRIKPANEL_ORDER_NUMBERS_MAX', 999999999 );

/**
 * Tell Import / Export how to carry these options.
 *
 * The switch and the prefix are a store's own series: copied to a second
 * store they would make two shops hand out the same numbers, and an import
 * would skip the checks a save runs. The rest is state.
 *
 * @param array $map Registry so far.
 * @return array
 */
add_filter( 'brikpanel_exportable_option_keys', 'brikpanel_order_numbers_register_export_keys' );
function brikpanel_order_numbers_register_export_keys( $map ) {
	$map[ BRIKPANEL_ORDER_NUMBERS_OPT ]        = [ 'class' => 'site', 'group' => 'orders' ];
	$map[ BRIKPANEL_ORDER_NUMBERS_OPT_PREFIX ] = [ 'class' => 'site', 'group' => 'orders' ];
	foreach ( [ BRIKPANEL_ORDER_NUMBERS_OPT_USED, BRIKPANEL_ORDER_NUMBERS_OPT_LAST, BRIKPANEL_ORDER_NUMBERS_OPT_SERIES ] as $key ) {
		$map[ $key ] = [ 'class' => 'internal' ];
	}
	return $map;
}

// =============================================================================
// State
// =============================================================================

/**
 * Meta keys an order's number can sit in, in the order they are read.
 *
 * `_order_number` is ours (and WebToffee's and SkyVerge's). The others are
 * where SkyVerge Pro, Tyche and BeRocket keep the formatted number, so their
 * orders keep showing it after the store moves to BrikPanel.
 *
 * @return string[]
 */
function brikpanel_order_numbers_display_keys() {
	return [ '_order_number_formatted', BRIKPANEL_ORDER_NUMBERS_META, '_alg_wc_full_custom_order_number', '_sequential_order_number' ];
}

function brikpanel_order_numbers_enabled() {
	return 'yes' === get_option( BRIKPANEL_ORDER_NUMBERS_OPT, 'no' );
}

function brikpanel_order_numbers_used() {
	return 'yes' === get_option( BRIKPANEL_ORDER_NUMBERS_OPT_USED, 'no' );
}

/**
 * Whether a prefix is allowed: empty, or up to 12 of A to Z, digits, - and _,
 * not ending in a digit. A digit at the end would let two series print the
 * same string (WEB1 + 5 and WEB + 15 are both WEB15).
 *
 * @param string $prefix Prefix.
 * @return bool
 */
function brikpanel_order_numbers_valid_prefix( $prefix ) {
	return '' === $prefix || 1 === preg_match( '/^[A-Za-z0-9_-]{0,11}[A-Za-z_-]$/', (string) $prefix );
}

/**
 * The saved prefix, or '' when the stored value is not one a save would accept
 * (written by hand or by an old import).
 *
 * @return string
 */
function brikpanel_order_numbers_prefix() {
	$prefix = (string) get_option( BRIKPANEL_ORDER_NUMBERS_OPT_PREFIX, '' );
	return brikpanel_order_numbers_valid_prefix( $prefix ) ? $prefix : '';
}

/**
 * Order numbering plugins BrikPanel steps aside for.
 *
 * `keeps` says whether BrikPanel reads the numbers that plugin wrote, so the
 * note can promise they stay. `setting` is a callback for plugins that only
 * number orders while one of their own settings is on.
 *
 * @return array<string,array>
 */
function brikpanel_order_numbers_known_rivals() {
	return [
		'wt-woocommerce-sequential-order-numbers/wt-advanced-order-number.php' => [
			'name' => 'Sequential Order Number for WooCommerce', 'keeps' => true,
			'probe' => static function () { return class_exists( 'Wt_Advanced_Order_Number', false ) || defined( 'WT_SEQUENCIAL_ORDNUMBER_VERSION' ); },
		],
		'wt-woocommerce-sequential-order-numbers-pro/wt-advanced-order-number-pro.php' => [
			'name' => 'Sequential Order Number for WooCommerce Pro', 'keeps' => true, 'probe' => null,
		],
		'woocommerce-sequential-order-numbers/woocommerce-sequential-order-numbers.php' => [
			'name' => 'Sequential Order Numbers for WooCommerce', 'keeps' => true,
			'probe' => static function () { return class_exists( 'WC_Seq_Order_Number', false ); },
		],
		'woocommerce-sequential-order-numbers-pro/woocommerce-sequential-order-numbers-pro.php' => [
			'name' => 'Sequential Order Numbers Pro for WooCommerce', 'keeps' => true,
			'probe' => static function () { return class_exists( 'WC_Seq_Order_Number_Pro', false ); },
		],
		'custom-order-numbers-for-woocommerce/custom-order-numbers-for-woocommerce.php' => [
			'name' => 'Custom Order Numbers for WooCommerce', 'keeps' => true,
			// Class only: its constants (CON_FILE, CON_VERSION) have generic names.
			'probe' => static function () { return class_exists( 'Tyche\CON\Custom_Order_Numbers', false ); },
			'setting' => static function () {
				$general = get_option( 'con_general_settings', [] );
				return ( is_array( $general ) && ! empty( $general['enabled'] ) ) || 'yes' === get_option( 'alg_wc_custom_order_numbers_enabled', 'no' );
			},
		],
		'sequential-order-numbers-for-woocommerce/sequential-order-numbers.php' => [
			'name' => 'Sequential Order Numbers for WooCommerce', 'keeps' => true,
			'probe' => static function () { return class_exists( 'BeRocket_Order_Numbers', false ) || defined( 'BeRocket_Sequential_Order_Numbers_version' ); },
		],
		'woocommerce-jetpack/woocommerce-jetpack.php' => [
			'name' => 'Booster for WooCommerce', 'keeps' => false, 'probe' => null,
			'setting' => static function () { return 'yes' === get_option( 'wcj_order_numbers_enabled', 'no' ); },
		],
		'yith-woocommerce-sequential-order-number-premium/init.php' => [
			'name' => 'YITH WooCommerce Sequential Order Number Premium', 'keeps' => false, 'probe' => null,
		],
	];
}

/**
 * The numbering plugin that is on right now, if any. Remembered per site for
 * the request; pass true to look again.
 *
 * @param bool $refresh Look again.
 * @return array{file:string,name:string,keeps:bool}|null
 */
function brikpanel_order_numbers_rival( $refresh = false ) {
	static $found = [];
	$blog = get_current_blog_id();
	if ( ! $refresh && array_key_exists( $blog, $found ) ) {
		return $found[ $blog ];
	}

	$active = (array) get_option( 'active_plugins', [] );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
	}

	$found[ $blog ] = null;
	foreach ( brikpanel_order_numbers_known_rivals() as $file => $rival ) {
		$on = in_array( $file, $active, true ) || ( is_callable( $rival['probe'] ) && call_user_func( $rival['probe'] ) );
		if ( $on && isset( $rival['setting'] ) && is_callable( $rival['setting'] ) && ! call_user_func( $rival['setting'] ) ) {
			$on = false;
		}
		if ( $on ) {
			$found[ $blog ] = [ 'file' => $file, 'name' => $rival['name'], 'keeps' => (bool) $rival['keeps'] ];
			break;
		}
	}
	return $found[ $blog ];
}

/**
 * dormant, display or active (see the file header).
 *
 * @return string
 */
function brikpanel_order_numbers_state() {
	$on = brikpanel_order_numbers_enabled();
	if ( ! $on && ! brikpanel_order_numbers_used() ) {
		return 'dormant';
	}
	if ( null !== brikpanel_order_numbers_rival() ) {
		return 'dormant';
	}
	return $on ? 'active' : 'display';
}

/**
 * Add or remove one hook without stacking it twice.
 */
function brikpanel_order_numbers_hook( $hook, $callback, $priority, $args, $on ) {
	if ( $on ) {
		if ( false === has_filter( $hook, $callback ) ) {
			add_filter( $hook, $callback, $priority, $args );
		}
	} else {
		remove_filter( $hook, $callback, $priority );
	}
}

/**
 * Put the runtime hooks in place for this site's state. Runs after every
 * plugin has loaded (so a numbering plugin is already detectable) and again
 * whenever multisite code switches to another site.
 */
function brikpanel_order_numbers_sync_hooks() {
	$state   = brikpanel_order_numbers_state();
	$display = 'dormant' !== $state;
	$active  = 'active' === $state;

	brikpanel_order_numbers_hook( 'woocommerce_order_number', 'brikpanel_order_numbers_display', 20, 2, $display );
	// At 5: WooCommerce casts the typed text to a number at 10, and WEB-1001
	// would become -1001.
	brikpanel_order_numbers_hook( 'woocommerce_shortcode_order_tracking_order_id', 'brikpanel_order_numbers_tracking_lookup', 5, 1, $display );
	brikpanel_order_numbers_hook( 'ast_formated_order_id', 'brikpanel_order_numbers_ast_lookup', 10, 1, $display );
	brikpanel_order_numbers_hook( 'woocommerce_order_list_table_prepare_items_query_args', 'brikpanel_order_numbers_list_exact', 5, 1, $display );
	brikpanel_order_numbers_hook( 'woocommerce_hpos_generate_where_for_search_filter', 'brikpanel_order_numbers_search_where', 10, 4, $display );
	brikpanel_order_numbers_hook( 'woocommerce_hpos_admin_search_filters', 'brikpanel_order_numbers_search_label', 10, 1, $display );
	brikpanel_order_numbers_hook( 'woocommerce_shop_order_search_fields', 'brikpanel_order_numbers_legacy_fields', 10, 1, $display );
	brikpanel_order_numbers_hook( 'woocommerce_shop_order_search_results', 'brikpanel_order_numbers_legacy_results', 10, 3, $display );

	// Ahead of everything else that reacts to a new order (phone
	// notifications queue at 20, the HPOS sync copies meta at 100), so the
	// number is there before any of them, and before emails and payment.
	brikpanel_order_numbers_hook( 'woocommerce_new_order', 'brikpanel_order_numbers_assign', 1, 2, $active );

	// WooCommerce Subscriptions copies a subscription's meta onto every
	// renewal; without this a renewal would carry an earlier order's number.
	foreach ( [ 'wc_subscriptions_renewal_order_data', 'wc_subscriptions_resubscribe_order_data', 'wc_subscriptions_subscription_data' ] as $hook ) {
		brikpanel_order_numbers_hook( $hook, 'brikpanel_order_numbers_wcs_strip', 10, 1, $active );
	}
	foreach ( [ 'wcs_renewal_order_created', 'wcs_resubscribe_order_created' ] as $hook ) {
		brikpanel_order_numbers_hook( $hook, 'brikpanel_order_numbers_wcs_created', 10, 1, $active );
	}
}
add_action( 'plugins_loaded', 'brikpanel_order_numbers_sync_hooks', 20 );
if ( is_multisite() ) {
	add_action( 'switch_blog', 'brikpanel_order_numbers_sync_hooks', 10, 0 );
}

/**
 * Whether BrikPanel's display filter is the only one on `woocommerce_order_number`,
 * so a screen can read numbers straight from the meta table instead of
 * loading every order.
 *
 * @return bool
 */
function brikpanel_order_numbers_filter_is_ours_only() {
	global $wp_filter;
	if ( empty( $wp_filter['woocommerce_order_number'] ) || ! is_object( $wp_filter['woocommerce_order_number'] ) ) {
		return false;
	}
	$ours = false;
	foreach ( $wp_filter['woocommerce_order_number']->callbacks as $callbacks ) {
		foreach ( $callbacks as $cb ) {
			if ( 'brikpanel_order_numbers_display' !== $cb['function'] ) {
				return false;
			}
			$ours = true;
		}
	}
	return $ours;
}

/**
 * Labels of the callbacks on `woocommerce_order_number` that are not ours.
 *
 * @return string[]
 */
function brikpanel_order_numbers_foreign_filters() {
	global $wp_filter;
	$out = [];
	if ( empty( $wp_filter['woocommerce_order_number'] ) || ! is_object( $wp_filter['woocommerce_order_number'] ) ) {
		return $out;
	}
	foreach ( $wp_filter['woocommerce_order_number']->callbacks as $callbacks ) {
		foreach ( $callbacks as $cb ) {
			if ( 'brikpanel_order_numbers_display' !== $cb['function'] ) {
				$out[] = is_string( $cb['function'] ) ? $cb['function'] : 'callback';
			}
		}
	}
	return $out;
}

// =============================================================================
// Storage helpers
// =============================================================================

/**
 * The order tables in charge on this site, with their column names.
 *
 * Asked fresh every time rather than through brikpanel_wc_hpos_enabled(),
 * which remembers the first answer: on multisite every site has its own.
 *
 * @return array{orders:string,id:string,type:string,status:string,meta:string,mid:string}
 */
function brikpanel_order_numbers_tables() {
	global $wpdb;
	$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	if ( $hpos ) {
		return [
			'orders' => $wpdb->prefix . 'wc_orders',
			'id'     => 'id',
			'type'   => 'type',
			'status' => 'status',
			'meta'   => $wpdb->prefix . 'wc_orders_meta',
			'mid'    => 'order_id',
		];
	}
	return [
		'orders' => $wpdb->posts,
		'id'     => 'ID',
		'type'   => 'post_type',
		'status' => 'post_status',
		'meta'   => $wpdb->postmeta,
		'mid'    => 'post_id',
	];
}

/**
 * `%s,%s,…` for a list.
 */
function brikpanel_order_numbers_holders( array $list, $holder = '%s' ) {
	return implode( ',', array_fill( 0, count( $list ), $holder ) );
}

/**
 * Statuses an order sits in before it is placed. Its ID is never shown to a
 * customer, so it does not block a number.
 *
 * (In the queries below, a serialized value, BeRocket's old array format, does
 * not count as a number: such an order shows its ID.)
 */
function brikpanel_order_numbers_draft_sql() {
	return "'auto-draft','draft','checkout-draft','wc-checkout-draft'";
}

/**
 * A typed order number reduced to what can be stored: no '#', no spaces, at
 * most 40 characters of A to Z, digits, - and _, with at least one digit.
 *
 * @param mixed $raw Typed value.
 * @return string '' when it cannot be an order number.
 */
function brikpanel_order_numbers_clean_term( $raw ) {
	if ( ! is_scalar( $raw ) ) {
		return '';
	}
	$term = trim( wp_strip_all_tags( (string) $raw ) );
	$term = ltrim( $term, "# \t" );
	if ( '' === $term || strlen( $term ) > 40 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $term ) || ! preg_match( '/\d/', $term ) ) {
		return '';
	}
	return $term;
}

/**
 * The stored numbers a typed term can mean: the term, and with digits only
 * also the term behind the current prefix (typing 1001 finds WEB-1001).
 *
 * @param mixed $raw Typed value.
 * @return string[]
 */
function brikpanel_order_numbers_candidates( $raw ) {
	$term = brikpanel_order_numbers_clean_term( $raw );
	if ( '' === $term ) {
		return [];
	}
	$out    = [ $term ];
	$prefix = brikpanel_order_numbers_prefix();
	if ( '' !== $prefix && ctype_digit( $term ) ) {
		$out[] = $prefix . $term;
	}
	return array_values( array_unique( $out ) );
}

/**
 * Orders whose stored number is one of the values, ranked in the order the
 * values are given: what was typed comes before what it can mean under the
 * current prefix (typing 1 lists order 1 before WEB-1). The comparison
 * follows the table's collation, so "web-1001" finds WEB-1001.
 *
 * @param string[] $values Stored numbers, most literal first.
 * @param int      $limit  Most IDs to return.
 * @return int[]
 */
function brikpanel_order_numbers_find_ids( array $values, $limit = 20 ) {
	global $wpdb;
	$values = array_values( array_unique( array_filter( array_map( 'strval', $values ), 'strlen' ) ) );
	if ( ! $values ) {
		return [];
	}
	$t    = brikpanel_order_numbers_tables();
	$keys = brikpanel_order_numbers_display_keys();
	$sql  = "SELECT m.{$t['mid']} AS oid, m.meta_value AS num FROM {$t['meta']} m
		INNER JOIN {$t['orders']} o ON o.{$t['id']} = m.{$t['mid']} AND o.{$t['type']} = 'shop_order'
		WHERE m.meta_key IN (" . brikpanel_order_numbers_holders( $keys ) . ')
		AND m.meta_value IN (' . brikpanel_order_numbers_holders( $values ) . ')
		LIMIT %d';
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb, values are bound.
	$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $keys, $values, [ max( 1, (int) $limit ) * 4 ] ) ), ARRAY_A );

	$rank = [];
	foreach ( $values as $i => $value ) {
		$rank[ strtolower( $value ) ] = $i;
	}
	$best = [];
	foreach ( $rows as $row ) {
		$id = (int) $row['oid'];
		$r  = $rank[ strtolower( (string) $row['num'] ) ] ?? count( $values );
		if ( ! isset( $best[ $id ] ) || $r < $best[ $id ] ) {
			$best[ $id ] = $r;
		}
	}
	asort( $best, SORT_NUMERIC );
	return array_slice( array_keys( $best ), 0, max( 1, (int) $limit ) );
}

/**
 * Order ID => stored number for the orders that have one, read in one query.
 * Orders without a number are left out (they show their ID).
 *
 * @param int[] $ids Order IDs.
 * @return array<int,string>
 */
function brikpanel_order_numbers_map( array $ids ) {
	global $wpdb;
	$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	if ( ! $ids ) {
		return [];
	}
	$t    = brikpanel_order_numbers_tables();
	$keys = brikpanel_order_numbers_display_keys();
	$sql  = "SELECT {$t['mid']} AS oid, meta_key, meta_value FROM {$t['meta']}
		WHERE {$t['mid']} IN (" . brikpanel_order_numbers_holders( $ids, '%d' ) . ')
		AND meta_key IN (' . brikpanel_order_numbers_holders( $keys ) . ')';
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb, values are bound.
	$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $ids, $keys ) ), ARRAY_A );

	$rank = array_flip( $keys );
	$best = [];
	foreach ( $rows as $row ) {
		$value = trim( (string) $row['meta_value'] );
		if ( '' === $value || is_serialized( $value ) ) {
			continue;
		}
		$id = (int) $row['oid'];
		$r  = $rank[ $row['meta_key'] ];
		if ( ! isset( $best[ $id ] ) || $r < $best[ $id ][0] ) {
			$best[ $id ] = [ $r, $value ];
		}
	}
	$out = [];
	foreach ( $best as $id => $pair ) {
		$out[ $id ] = $pair[1];
	}
	return $out;
}

/**
 * The number stored on an order, or ''.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function brikpanel_order_numbers_stored( WC_Order $order ) {
	foreach ( brikpanel_order_numbers_display_keys() as $key ) {
		$value = $order->get_meta( $key, true, 'edit' );
		if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
			return trim( (string) $value );
		}
	}
	return '';
}

/**
 * Whether a number is already shown by another order: stored on one, or,
 * for a number of digits only, the plain ID of an order that has no number.
 *
 * @param string $value      Number as shown.
 * @param int    $exclude_id The order asking.
 * @return bool
 */
function brikpanel_order_numbers_taken( $value, $exclude_id ) {
	global $wpdb;
	$value = (string) $value;
	$t     = brikpanel_order_numbers_tables();
	$keys  = brikpanel_order_numbers_display_keys();
	$kh    = brikpanel_order_numbers_holders( $keys );

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb, values are bound.
	$hit = $wpdb->get_var( $wpdb->prepare(
		"SELECT {$t['mid']} FROM {$t['meta']} WHERE meta_key IN ($kh) AND meta_value = %s AND {$t['mid']} <> %d LIMIT 1",
		array_merge( $keys, [ $value, (int) $exclude_id ] )
	) );
	if ( null !== $hit ) {
		return true;
	}

	if ( ctype_digit( $value ) && strlen( $value ) <= 18 ) {
		$hit = $wpdb->get_var( $wpdb->prepare(
			"SELECT o.{$t['id']} FROM {$t['orders']} o
			WHERE o.{$t['id']} = %d AND o.{$t['id']} <> %d AND o.{$t['type']} = 'shop_order'
			AND o.{$t['status']} NOT IN (" . brikpanel_order_numbers_draft_sql() . ")
			AND NOT EXISTS ( SELECT 1 FROM {$t['meta']} m WHERE m.{$t['mid']} = o.{$t['id']} AND m.meta_key IN ($kh) AND m.meta_value <> '' AND m.meta_value NOT LIKE 'a:%%' )
			LIMIT 1",
			array_merge( [ (int) $value, (int) $exclude_id ], $keys )
		) );
		if ( null !== $hit ) {
			return true;
		}
	}
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	return false;
}

/**
 * The highest number in use under a prefix, counting what the orders show
 * and what BrikPanel has already given: for no prefix, the stored numbers of
 * digits only and the IDs of orders without a number; for a prefix, the
 * stored numbers that are that prefix plus digits.
 *
 * The order being numbered is left out: it is already in the table, without
 * a number yet, and its own ID must not count as one in use.
 *
 * @param string $prefix     Prefix.
 * @param int    $exclude_id Order being numbered (0 for none).
 * @return int
 */
function brikpanel_order_numbers_highest( $prefix, $exclude_id = 0 ) {
	global $wpdb;
	$prefix = (string) $prefix;
	$t      = brikpanel_order_numbers_tables();
	$keys   = brikpanel_order_numbers_display_keys();
	$kh     = brikpanel_order_numbers_holders( $keys );
	$max    = 0;

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb, values are bound.
	if ( '' === $prefix ) {
		$max = max( $max, (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX( CAST( meta_value AS UNSIGNED ) ) FROM {$t['meta']} WHERE meta_key IN ($kh) AND meta_value REGEXP '^[0-9]{1,12}$'",
			$keys
		) ) );
		// Newest first, stop at the first match: on a store without numbers
		// that is the newest order, one row read however many orders there are
		// (MAX() walked the whole index: 2,598 rows on vermond-test).
		$max = max( $max, (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT o.{$t['id']} FROM {$t['orders']} o
			WHERE o.{$t['type']} = 'shop_order' AND o.{$t['id']} <> %d AND o.{$t['status']} NOT IN (" . brikpanel_order_numbers_draft_sql() . ")
			AND NOT EXISTS ( SELECT 1 FROM {$t['meta']} m WHERE m.{$t['mid']} = o.{$t['id']} AND m.meta_key IN ($kh) AND m.meta_value <> '' AND m.meta_value NOT LIKE 'a:%%' )
			ORDER BY o.{$t['id']} DESC LIMIT 1",
			array_merge( [ (int) $exclude_id ], $keys )
		) ) );
	} else {
		// The prefix holds only A to Z, digits, - and _, so it needs no
		// escaping inside the pattern; LIKE narrows the rows through the index.
		$max = max( $max, (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX( CAST( SUBSTRING( meta_value, %d ) AS UNSIGNED ) ) FROM {$t['meta']}
			WHERE meta_key IN ($kh) AND meta_value LIKE %s AND meta_value REGEXP %s",
			array_merge( [ strlen( $prefix ) + 1 ], $keys, [ $wpdb->esc_like( $prefix ) . '%', '^' . $prefix . '[0-9]{1,12}$' ] )
		) ) );
	}
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	$series = brikpanel_order_numbers_series();
	$max    = max( $max, (int) ( $series['done'][ $prefix ] ?? 0 ) );
	if ( $series['prefix'] === $prefix ) {
		$last = brikpanel_order_numbers_counter();
		if ( null !== $last && $last > (int) $series['seed'] ) {
			$max = max( $max, $last );
		}
	}
	return $max;
}

/**
 * The number the next order would get under a prefix.
 *
 * @param string $prefix Prefix.
 * @param int    $start  A start number typed in the settings (0 for none).
 * @return int
 */
function brikpanel_order_numbers_next( $prefix, $start = 0 ) {
	if ( $start > 0 ) {
		return (int) $start;
	}
	$next   = brikpanel_order_numbers_highest( $prefix );
	$series = brikpanel_order_numbers_series();
	if ( $series['prefix'] === (string) $prefix ) {
		$next = max( $next, (int) brikpanel_order_numbers_counter() );
	}
	return $next + 1;
}

// =============================================================================
// The counter
// =============================================================================

/**
 * @return array{prefix:?string,seed:int,done:array<string,int>}
 */
function brikpanel_order_numbers_series() {
	$raw = get_option( BRIKPANEL_ORDER_NUMBERS_OPT_SERIES, [] );
	$raw = is_array( $raw ) ? $raw : [];
	return [
		'prefix' => isset( $raw['prefix'] ) && is_string( $raw['prefix'] ) ? $raw['prefix'] : null,
		'seed'   => isset( $raw['seed'] ) ? (int) $raw['seed'] : 0,
		'done'   => isset( $raw['done'] ) && is_array( $raw['done'] ) ? array_map( 'intval', $raw['done'] ) : [],
	];
}

function brikpanel_order_numbers_save_series( array $series ) {
	update_option( BRIKPANEL_ORDER_NUMBERS_OPT_SERIES, $series, false );
}

/**
 * The counter as it is in the database right now, or null when it has no row.
 *
 * @return int|null
 */
function brikpanel_order_numbers_counter() {
	global $wpdb;
	if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
		$wpdb->send_reads_to_masters(); // HyperDB / LudicrousDB: a replica may lag behind.
	}
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", BRIKPANEL_ORDER_NUMBERS_OPT_LAST ) );
	return null === $value ? null : (int) $value;
}

function brikpanel_order_numbers_counter_forget() {
	wp_cache_delete( BRIKPANEL_ORDER_NUMBERS_OPT_LAST, 'options' );
	$notoptions = wp_cache_get( 'notoptions', 'options' );
	if ( is_array( $notoptions ) && isset( $notoptions[ BRIKPANEL_ORDER_NUMBERS_OPT_LAST ] ) ) {
		unset( $notoptions[ BRIKPANEL_ORDER_NUMBERS_OPT_LAST ] );
		wp_cache_set( 'notoptions', $notoptions, 'options' );
	}
}

/**
 * Set the counter to a value, creating its row (never autoloaded) when needed.
 */
function brikpanel_order_numbers_counter_set( $value ) {
	global $wpdb;
	$value    = (string) max( 0, (int) $value );
	$autoload = function_exists( 'wp_autoload_values_to_autoload' ) ? 'off' : 'no';
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, %s )", BRIKPANEL_ORDER_NUMBERS_OPT_LAST, $value, $autoload ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $value, BRIKPANEL_ORDER_NUMBERS_OPT_LAST ) );
	brikpanel_order_numbers_counter_forget();
}

/**
 * Raise the counter to at least a value. Never lowers it, also when a
 * checkout bumps it at the same moment.
 */
function brikpanel_order_numbers_counter_raise( $value ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = GREATEST( CAST( option_value AS UNSIGNED ), %d ) WHERE option_name = %s", max( 0, (int) $value ), BRIKPANEL_ORDER_NUMBERS_OPT_LAST ) );
	brikpanel_order_numbers_counter_forget();
}

/**
 * Take the next number: one atomic UPDATE, so parallel checkouts each get
 * their own. The new value is read from the same connection that ran the
 * UPDATE (mysqli_insert_id), never through a SELECT a read replica could
 * answer. Drivers other than mysqli fall back to compare-and-swap.
 *
 * @return int 0 when the counter has no row or the database refused.
 */
function brikpanel_order_numbers_bump() {
	global $wpdb;
	$dbh = $wpdb->dbh;
	if ( $dbh instanceof mysqli && ! defined( 'BRIKPANEL_ORDER_NUMBERS_FORCE_CAS' ) ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID( CAST( option_value AS UNSIGNED ) + 1 ) WHERE option_name = %s", BRIKPANEL_ORDER_NUMBERS_OPT_LAST ) );
		if ( 1 !== (int) $wpdb->rows_affected ) {
			return 0;
		}
		$dbh = $wpdb->dbh; // The connection that ran the UPDATE (HyperDB may have switched).
		$n   = $dbh instanceof mysqli ? (int) mysqli_insert_id( $dbh ) : 0;
		brikpanel_order_numbers_counter_forget();
		return max( 0, $n );
	}

	for ( $try = 0; $try < 25; $try++ ) {
		$current = brikpanel_order_numbers_counter();
		if ( null === $current ) {
			return 0;
		}
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			(string) ( $current + 1 ),
			BRIKPANEL_ORDER_NUMBERS_OPT_LAST,
			(string) $current
		) );
		if ( 1 === (int) $wpdb->rows_affected ) {
			brikpanel_order_numbers_counter_forget();
			return $current + 1;
		}
		usleep( wp_rand( 2000, 9000 ) );
	}
	return 0;
}

/**
 * Point the counter at a prefix's series. The highest number given under the
 * old prefix is kept, so going back to it later carries on instead of
 * repeating numbers.
 *
 * @param string $prefix     New prefix.
 * @param int    $start      Start number typed with it (0 for none).
 * @param int    $exclude_id Order being numbered (0 for none).
 */
function brikpanel_order_numbers_switch_series( $prefix, $start = 0, $exclude_id = 0 ) {
	$prefix = (string) $prefix;
	$series = brikpanel_order_numbers_series();
	$last   = brikpanel_order_numbers_counter();

	if ( null !== $series['prefix'] && $series['prefix'] !== $prefix && null !== $last && $last > $series['seed'] ) {
		$series['done'][ $series['prefix'] ] = max( (int) ( $series['done'][ $series['prefix'] ] ?? 0 ), $last );
	}
	if ( $series['prefix'] !== $prefix ) {
		// highest() must not count the old series' counter for the new prefix.
		$series['prefix'] = $prefix;
		$series['seed']   = PHP_INT_MAX;
		brikpanel_order_numbers_save_series( $series );
	}

	$base = brikpanel_order_numbers_highest( $prefix, $exclude_id );
	if ( $start > 0 ) {
		$base = max( $base, (int) $start - 1 );
	}
	brikpanel_order_numbers_counter_set( $base );
	$series['seed'] = $base;
	brikpanel_order_numbers_save_series( $series );
}

/**
 * Make sure the counter is past every number in use under the current prefix
 * (orders placed while the switch was off show their IDs).
 */
function brikpanel_order_numbers_catch_up() {
	$prefix = brikpanel_order_numbers_prefix();
	$series = brikpanel_order_numbers_series();
	$last   = brikpanel_order_numbers_counter();
	if ( null === $last || $series['prefix'] !== $prefix ) {
		brikpanel_order_numbers_switch_series( $prefix );
		return;
	}
	$highest = brikpanel_order_numbers_highest( $prefix );
	if ( $highest <= $last ) {
		return;
	}
	if ( $last <= $series['seed'] ) {
		// Nothing given in this series yet: the seed moves with it.
		brikpanel_order_numbers_counter_set( $highest );
		$series['seed'] = $highest;
		brikpanel_order_numbers_save_series( $series );
		return;
	}
	brikpanel_order_numbers_counter_raise( $highest );
}

/**
 * The next free number under the current prefix, as it will be shown.
 *
 * @param int $order_id The order it is for.
 * @return string '' when none could be taken (the order keeps its ID).
 */
function brikpanel_order_numbers_allocate( $order_id ) {
	$prefix = brikpanel_order_numbers_prefix();
	$series = brikpanel_order_numbers_series();
	if ( $series['prefix'] !== $prefix ) {
		// The prefix was changed outside the settings screen.
		brikpanel_order_numbers_switch_series( $prefix, 0, $order_id );
	}

	for ( $round = 0; $round < 5; $round++ ) {
		$n = brikpanel_order_numbers_bump();
		if ( $n <= 0 ) {
			brikpanel_order_numbers_switch_series( $prefix, 0, $order_id );
			$n = brikpanel_order_numbers_bump();
			if ( $n <= 0 ) {
				break;
			}
		}
		if ( $n > BRIKPANEL_ORDER_NUMBERS_MAX ) {
			break;
		}
		$formatted = $prefix . $n;
		if ( ! brikpanel_order_numbers_taken( $formatted, $order_id ) ) {
			return $formatted;
		}
		// Imported orders or another plugin hold numbers ahead of the counter:
		// jump past all of them at once instead of trying them one by one.
		brikpanel_order_numbers_counter_raise( brikpanel_order_numbers_highest( $prefix, $order_id ) );
	}

	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->warning(
			sprintf( 'Order %d kept its WooCommerce number: no free sequential number could be taken.', (int) $order_id ),
			[ 'source' => 'brikpanel-order-numbers' ]
		);
	}
	return '';
}

// =============================================================================
// Giving numbers
// =============================================================================

/**
 * `woocommerce_new_order`: give a placed order its number.
 *
 * Runs again when an order goes back to a draft and is placed a second time
 * (a failed REST create, a cancelled PayPal button payment); the order keeps
 * the number it already has.
 *
 * @param int           $order_id Order ID.
 * @param WC_Order|null $order    Order being saved.
 */
function brikpanel_order_numbers_assign( $order_id, $order = null ) {
	if ( 'active' !== brikpanel_order_numbers_state() ) {
		return;
	}
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
	}
	if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
		return;
	}
	if ( in_array( $order->get_status( 'edit' ), [ 'auto-draft', 'draft', 'checkout-draft' ], true ) ) {
		return;
	}

	/**
	 * Whether BrikPanel gives this new order the next sequential number.
	 *
	 * @param bool     $number Default true.
	 * @param WC_Order $order  The order being placed.
	 */
	if ( ! apply_filters( 'brikpanel_order_numbers_should_number', true, $order ) ) {
		return;
	}

	brikpanel_order_numbers_ensure_unique( $order );
}

/**
 * Keep an order's number when it is its own, otherwise give it the next one.
 * A number copied from another order (a duplicate-order plugin, a renewal)
 * counts as not its own.
 *
 * @param WC_Order $order Order.
 * @return string The number the order shows now.
 */
function brikpanel_order_numbers_ensure_unique( WC_Order $order ) {
	$have = brikpanel_order_numbers_stored( $order );
	if ( '' !== $have && ! brikpanel_order_numbers_taken( $have, $order->get_id() ) ) {
		return $have;
	}
	$number = brikpanel_order_numbers_allocate( $order->get_id() );
	if ( '' === $number ) {
		return $have;
	}
	brikpanel_order_numbers_write( $order, $number );
	return $number;
}

/**
 * Store a number on an order. Saved right away through the order's own meta
 * store: in `woocommerce_new_order` the order row is already written, so a
 * plain update_meta_data() would be lost, and writing postmeta directly would
 * be deleted by the next HPOS sync.
 *
 * @param WC_Order $order  Order.
 * @param string   $number Number as shown.
 */
function brikpanel_order_numbers_write( WC_Order $order, $number ) {
	$order->update_meta_data( BRIKPANEL_ORDER_NUMBERS_META, $number );
	// A copied number in another plugin's key would still win the display.
	foreach ( brikpanel_order_numbers_display_keys() as $key ) {
		if ( BRIKPANEL_ORDER_NUMBERS_META !== $key && $order->meta_exists( $key ) ) {
			$order->update_meta_data( $key, $number );
		}
	}
	$order->save_meta_data();

	if ( ! brikpanel_order_numbers_used() ) {
		brikpanel_update_option( BRIKPANEL_ORDER_NUMBERS_OPT_USED, 'yes' );
	}
}

/**
 * Leave our number out of the meta WooCommerce Subscriptions copies.
 *
 * @param mixed $data Meta key => value.
 * @return mixed
 */
function brikpanel_order_numbers_wcs_strip( $data ) {
	if ( is_array( $data ) ) {
		foreach ( brikpanel_order_numbers_display_keys() as $key ) {
			unset( $data[ $key ] );
		}
	}
	return $data;
}

/**
 * A renewal or resubscribe order: make sure it has a number of its own.
 *
 * @param mixed $order The new order.
 * @return mixed
 */
function brikpanel_order_numbers_wcs_created( $order ) {
	if ( $order instanceof WC_Order && 'shop_order' === $order->get_type() ) {
		$fresh = wc_get_order( $order->get_id() );
		if ( $fresh instanceof WC_Order ) {
			brikpanel_order_numbers_ensure_unique( $fresh );
		}
	}
	return $order;
}

// =============================================================================
// Showing and finding numbers
// =============================================================================

/**
 * `woocommerce_order_number`: the stored number, or WooCommerce's.
 *
 * @param string|int $number Number so far.
 * @param mixed      $order  Order.
 * @return string|int
 */
function brikpanel_order_numbers_display( $number, $order = null ) {
	if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
		return $number;
	}
	$stored = brikpanel_order_numbers_stored( $order );
	return '' !== $stored ? $stored : $number;
}

/**
 * The shop's "Track your order" form: the number the customer typed becomes
 * the order's ID. Several orders on one number (left over from an old plugin)
 * are told apart by the email typed with it.
 *
 * @param mixed $typed Typed order number.
 * @return mixed
 */
function brikpanel_order_numbers_tracking_lookup( $typed ) {
	$ids = brikpanel_order_numbers_find_ids( brikpanel_order_numbers_candidates( $typed ), 5 );
	if ( ! $ids ) {
		return $typed;
	}
	if ( count( $ids ) > 1 ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce checked the form's nonce before this filter.
		$email = isset( $_REQUEST['order_email'] ) ? strtolower( sanitize_email( wp_unslash( $_REQUEST['order_email'] ) ) ) : '';
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof WC_Order && '' !== $email && strtolower( (string) $order->get_billing_email() ) === $email ) {
				return $id;
			}
		}
	}
	return $ids[0];
}

/**
 * Advanced Shipment Tracking: a tracking CSV names orders by the number the
 * shop shows. Its REST API passes real IDs, so those are left alone.
 *
 * @param mixed $order_id Order number or ID.
 * @return mixed
 */
function brikpanel_order_numbers_ast_lookup( $order_id ) {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return $order_id;
	}
	$ids = brikpanel_order_numbers_find_ids( brikpanel_order_numbers_candidates( $order_id ), 1 );
	return $ids ? $ids[0] : $order_id;
}

/**
 * Orders list (HPOS): a search that is exactly a stored number lists that
 * order, instead of WooCommerce's match on the ID (a different order).
 *
 * @param array $args Order query arguments of the list.
 * @return array
 */
function brikpanel_order_numbers_list_exact( $args ) {
	if ( ! is_array( $args ) || empty( $args['s'] ) ) {
		return $args;
	}
	$type = $args['type'] ?? 'shop_order';
	if ( 'shop_order' !== $type && ! ( is_array( $type ) && in_array( 'shop_order', $type, true ) ) ) {
		return $args;
	}
	$filter = isset( $args['search_filter'] ) ? (string) $args['search_filter'] : '';
	if ( ! in_array( $filter, [ '', 'all', 'order_id' ], true ) ) {
		return $args;
	}
	$ids = brikpanel_order_numbers_find_ids( brikpanel_order_numbers_candidates( $args['s'] ), 100 );
	if ( ! $ids ) {
		return $args;
	}
	// Respect IDs an earlier filter already narrowed the list to.
	if ( ! empty( $args['post__in'] ) ) {
		$ids = array_values( array_intersect( array_map( 'absint', (array) $args['post__in'] ), $ids ) );
		if ( ! $ids ) {
			$ids = [ 0 ];
		}
	}
	unset( $args['s'], $args['search_filter'] );
	$args['post__in'] = $ids;
	return $args;
}

/**
 * Orders list (HPOS): part of a number with letters in it ("WEB-10") under
 * "Order number" or "All". WooCommerce only matches digits there.
 *
 * @param string $where  WHERE clause so far.
 * @param string $term   Search term.
 * @param string $filter Search filter.
 * @param object $query  OrdersTableQuery.
 * @return string
 */
function brikpanel_order_numbers_search_where( $where, $term, $filter, $query ) {
	if ( 'order_id' !== $filter || '' !== trim( (string) $where ) || ! is_object( $query ) || ! method_exists( $query, 'get_table_name' ) ) {
		return $where;
	}
	// Part of a number: "WEB-", "B-10". Digits alone stay WooCommerce's ID match.
	$clean = ltrim( trim( wp_strip_all_tags( (string) $term ) ), "# \t" );
	if ( strlen( $clean ) < 2 || strlen( $clean ) > 40 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $clean ) || ctype_digit( $clean ) ) {
		return $where;
	}
	global $wpdb;
	$orders = $query->get_table_name( 'orders' );
	$meta   = $query->get_table_name( 'meta' );
	$keys   = brikpanel_order_numbers_display_keys();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from WooCommerce, values are bound.
	return $wpdb->prepare(
		"`{$orders}`.id IN ( SELECT order_id FROM `{$meta}` WHERE meta_key IN (" . brikpanel_order_numbers_holders( $keys ) . ') AND meta_value LIKE %s )',
		array_merge( $keys, [ '%' . $wpdb->esc_like( $clean ) . '%' ] )
	);
}

/**
 * The search box option WooCommerce calls "Order ID" finds numbers now.
 *
 * @param array $options Search filters.
 * @return array
 */
function brikpanel_order_numbers_search_label( $options ) {
	if ( is_array( $options ) && isset( $options['order_id'] ) ) {
		$options['order_id'] = __( 'Order number', 'brikpanel' );
	}
	return $options;
}

/**
 * Orders list (posts storage): search the number keys too.
 *
 * @param array $fields Meta keys searched.
 * @return array
 */
function brikpanel_order_numbers_legacy_fields( $fields ) {
	return array_values( array_unique( array_merge( (array) $fields, brikpanel_order_numbers_display_keys() ) ) );
}

/**
 * Orders list (posts storage): an exact number lists only its order, not the
 * order whose ID happens to be the same digits.
 *
 * @param int[]  $ids    Matches so far.
 * @param string $term   Search term.
 * @param array  $fields Meta keys searched.
 * @return int[]
 */
function brikpanel_order_numbers_legacy_results( $ids, $term, $fields = [] ) {
	$exact = brikpanel_order_numbers_find_ids( brikpanel_order_numbers_candidates( $term ), 100 );
	return $exact ? $exact : $ids;
}

// =============================================================================
// Settings card (WooCommerce > Settings > BrikPanel > Orders)
// =============================================================================

if ( ! is_admin() ) {
	return;
}

add_filter( 'brikpanel_settings_title_section_map', static function ( $map ) {
	if ( is_array( $map ) ) {
		$map['brk_order_numbers_title'] = 'orders';
	}
	return $map;
} );

/**
 * The rule a prefix must follow, as the merchant reads it.
 */
function brikpanel_order_numbers_prefix_rule_text() {
	return __( 'The prefix can use letters A to Z, digits, - and _, up to 12 characters, and cannot end in a digit.', 'brikpanel' );
}

function brikpanel_order_numbers_start_format_text() {
	/* translators: %s: the largest start number, for example 999,999,999. */
	return sprintf( __( 'Start from must be a whole number from 1 to %s.', 'brikpanel' ), brikpanel_number( BRIKPANEL_ORDER_NUMBERS_MAX ) );
}

function brikpanel_order_numbers_start_low_text( $shown ) {
	/* translators: %s: an order number as the shop shows it, for example #WEB-1050. */
	return sprintf( __( 'Start from must be higher than %s, the highest order number in use with this prefix.', 'brikpanel' ), '#' . $shown );
}

/**
 * Note under the switch while another numbering plugin is on.
 *
 * @param array $rival From brikpanel_order_numbers_rival().
 * @return string Plain text.
 */
function brikpanel_order_numbers_rival_note( array $rival ) {
	$name = $rival['name'];
	if ( function_exists( 'get_plugins' ) ) {
		$plugins = get_plugins();
		if ( isset( $plugins[ $rival['file'] ]['Name'] ) && '' !== $plugins[ $rival['file'] ]['Name'] ) {
			$name = $plugins[ $rival['file'] ]['Name'];
		}
	}
	/* translators: %s: name of another plugin, for example Sequential Order Number for WooCommerce. */
	$note = sprintf( __( '%s numbers your orders right now. Turn it off on the Plugins screen to use this setting.', 'brikpanel' ), $name );
	return $note . ' ' . ( $rival['keeps']
		? __( 'The numbers it gave stay the same.', 'brikpanel' )
		: __( 'BrikPanel cannot read the numbers it gave, so those orders show their WooCommerce number after that.', 'brikpanel' ) );
}

/**
 * The card's fields. No database query in here: this list is built several
 * times per request (section slicing, the settings search index, the export
 * walker). The numbers are worked out in the row renderers below.
 *
 * @param array $fields Fields so far.
 * @return array
 */
add_filter( 'brikpanel_settings_fields', 'brikpanel_order_numbers_settings_fields', 9 );
function brikpanel_order_numbers_settings_fields( $fields ) {
	if ( ! is_array( $fields ) ) {
		return $fields;
	}
	$rival = brikpanel_order_numbers_rival();

	$switch = [
		'name'    => __( 'Sequential order numbers', 'brikpanel' ),
		'id'      => BRIKPANEL_ORDER_NUMBERS_OPT,
		'type'    => 'checkbox',
		'desc'    => __( 'Each new order gets the next number. A number never changes once an order has it, even if you turn this off later.', 'brikpanel' ),
		'default' => 'no',
	];
	$prefix = [
		'name'              => __( 'Prefix', 'brikpanel' ),
		'id'                => BRIKPANEL_ORDER_NUMBERS_OPT_PREFIX,
		'type'              => 'text',
		'default'           => '',
		'placeholder'       => 'WEB-',
		'css'               => 'width:160px;',
		'desc'              => __( 'Optional text in front of the number: WEB- gives WEB-1001. A new prefix starts its own series. Letters A to Z, digits, - and _, up to 12 characters, not ending in a digit.', 'brikpanel' ),
		// An order number reads left to right in every language; in an RTL
		// admin "WEB-" would otherwise show as "-WEB".
		'custom_attributes' => [ 'maxlength' => '12', 'autocomplete' => 'off', 'spellcheck' => 'false', 'dir' => 'ltr' ],
	];

	if ( $rival ) {
		$switch['value']    = get_option( BRIKPANEL_ORDER_NUMBERS_OPT, 'no' );
		$switch['disabled'] = true;
		$switch['desc_tip'] = '<span class="brikpanel-override-note">' . esc_html( brikpanel_order_numbers_rival_note( $rival ) ) . '</span>';
		$prefix['custom_attributes']['disabled'] = 'disabled';
	} elseif ( brikpanel_order_numbers_foreign_filters() ) {
		$switch['desc_tip'] = '<span class="brikpanel-override-note">' . esc_html__( 'Another plugin also changes order numbers on this site. Keep only one of them on, so every screen shows the same number.', 'brikpanel' ) . '</span>';
	}

	return array_merge( $fields, [
		[
			'name' => __( 'Order numbers', 'brikpanel' ),
			'type' => 'title',
			'id'   => 'brk_order_numbers_title',
			'desc' => __( 'Give new orders numbers that follow each other, like #1001, #1002, #1003. WooCommerce\'s own numbers skip, because products, images and coupons count on the same counter. Orders you already have keep the numbers they show now.', 'brikpanel' ),
		],
		$switch,
		$prefix,
		[
			'name'      => __( 'Start from', 'brikpanel' ),
			'id'        => 'brikpanel_order_numbers_start',
			'type'      => 'brikpanel_order_numbers_start',
			'is_option' => false,
		],
		[
			'name'      => __( 'Next order number', 'brikpanel' ),
			'id'        => 'brikpanel_order_numbers_preview_field',
			'type'      => 'brikpanel_order_numbers_preview',
			'is_option' => false,
		],
		[
			'type' => 'sectionend',
			'id'   => 'brk_order_numbers_title',
		],
	] );
}

/**
 * What the next order would get with the saved settings, worked out once per
 * request for both rows.
 *
 * @return array{next:int,prefix:string,locked:bool}
 */
function brikpanel_order_numbers_settings_state() {
	static $state = null;
	if ( null === $state ) {
		$prefix = brikpanel_order_numbers_prefix();
		$state  = [
			'next'   => brikpanel_order_numbers_next( $prefix ),
			'prefix' => $prefix,
			'locked' => null !== brikpanel_order_numbers_rival(),
		];
	}
	return $state;
}

add_action( 'woocommerce_admin_field_brikpanel_order_numbers_start', 'brikpanel_order_numbers_render_start' );
function brikpanel_order_numbers_render_start( $field ) {
	$state = brikpanel_order_numbers_settings_state();
	?>
	<tr valign="top">
		<th scope="row" class="titledesc"><label for="brikpanel_order_numbers_start"><?php echo esc_html( $field['name'] ); ?></label></th>
		<td class="forminp forminp-number">
			<input name="brikpanel_order_numbers_start" id="brikpanel_order_numbers_start" type="number" min="1" max="<?php echo esc_attr( (string) BRIKPANEL_ORDER_NUMBERS_MAX ); ?>" step="1" inputmode="numeric" dir="ltr" style="width:160px;" value="" placeholder="<?php echo esc_attr( (string) $state['next'] ); ?>"<?php disabled( $state['locked'] ); ?> />
			<p class="description"><?php esc_html_e( 'The number the next order gets. Leave it empty to carry on after the highest number in use.', 'brikpanel' ); ?></p>
		</td>
	</tr>
	<?php
}

add_action( 'woocommerce_admin_field_brikpanel_order_numbers_preview', 'brikpanel_order_numbers_render_preview' );
function brikpanel_order_numbers_render_preview( $field ) {
	$state = brikpanel_order_numbers_settings_state();
	?>
	<tr valign="top">
		<th scope="row" class="titledesc"><?php echo esc_html( $field['name'] ); ?></th>
		<td class="forminp">
			<strong dir="ltr" data-bp-onum-preview><?php echo esc_html( '#' . $state['prefix'] . $state['next'] ); ?></strong>
			<p class="description bp-onum-error" data-bp-onum-error aria-live="polite"></p>
		</td>
	</tr>
	<?php
}

/**
 * Check the three posted values together, once per save: the start number
 * depends on the prefix it is typed with. Values are read from $_POST
 * because WooCommerce has not stored any of them yet when its per-field
 * sanitize filters run (it checked the settings nonce before saving). The
 * verdict is kept until brikpanel_order_numbers_after_save() uses it up.
 *
 * @return array{verdict:string,was_on:bool,on:bool,prefix:string,old_prefix:string,start:int}
 */
function brikpanel_order_numbers_posted() {
	if ( isset( $GLOBALS['brikpanel_order_numbers_posted'] ) && is_array( $GLOBALS['brikpanel_order_numbers_posted'] ) ) {
		return $GLOBALS['brikpanel_order_numbers_posted'];
	}
	$old_prefix = brikpanel_order_numbers_prefix();
	$result     = [
		'verdict'    => 'ok',
		'was_on'     => brikpanel_order_numbers_enabled(),
		'on'         => brikpanel_order_numbers_enabled(),
		'prefix'     => $old_prefix,
		'old_prefix' => $old_prefix,
		'start'      => 0,
	];
	if ( null !== brikpanel_order_numbers_rival() ) {
		$result['verdict'] = 'locked';
		$GLOBALS['brikpanel_order_numbers_posted'] = $result;
		return $result;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC_Admin_Settings::save() verified the nonce.
	$on     = isset( $_POST[ BRIKPANEL_ORDER_NUMBERS_OPT ] ) && in_array( wp_unslash( $_POST[ BRIKPANEL_ORDER_NUMBERS_OPT ] ), [ '1', 'yes' ], true );
	$prefix = isset( $_POST[ BRIKPANEL_ORDER_NUMBERS_OPT_PREFIX ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ BRIKPANEL_ORDER_NUMBERS_OPT_PREFIX ] ) ) ) : $old_prefix;
	$start  = isset( $_POST['brikpanel_order_numbers_start'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['brikpanel_order_numbers_start'] ) ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$result['on']     = $on;
	$result['prefix'] = $prefix;

	$problem = '';
	if ( ! brikpanel_order_numbers_valid_prefix( $prefix ) ) {
		$problem = brikpanel_order_numbers_prefix_rule_text();
	} elseif ( '' !== $start ) {
		if ( ! ctype_digit( $start ) || (int) $start < 1 || (int) $start > BRIKPANEL_ORDER_NUMBERS_MAX ) {
			$problem = brikpanel_order_numbers_start_format_text();
		} else {
			$highest = brikpanel_order_numbers_highest( $prefix );
			if ( (int) $start <= $highest ) {
				$problem = brikpanel_order_numbers_start_low_text( $prefix . $highest );
			} else {
				$result['start'] = (int) $start;
			}
		}
	}

	if ( '' !== $problem ) {
		$result['verdict'] = 'error';
		if ( class_exists( 'WC_Admin_Settings' ) ) {
			/* translators: %s: what was wrong, for example "Start from must be a whole number from 1 to 999,999,999." */
			WC_Admin_Settings::add_error( sprintf( __( 'Order numbers were not saved: %s Your other settings were saved.', 'brikpanel' ), $problem ) );
		}
	}
	$GLOBALS['brikpanel_order_numbers_posted'] = $result;
	return $result;
}

add_filter( 'woocommerce_admin_settings_sanitize_option_' . BRIKPANEL_ORDER_NUMBERS_OPT, 'brikpanel_order_numbers_sanitize_switch', 10, 3 );
function brikpanel_order_numbers_sanitize_switch( $value, $option = [], $raw = null ) {
	$posted = brikpanel_order_numbers_posted();
	if ( 'locked' === $posted['verdict'] ) {
		// The switch is drawn disabled and not posted; keep what was stored.
		return get_option( BRIKPANEL_ORDER_NUMBERS_OPT, 'no' );
	}
	return 'error' === $posted['verdict'] ? null : $value;
}

add_filter( 'woocommerce_admin_settings_sanitize_option_' . BRIKPANEL_ORDER_NUMBERS_OPT_PREFIX, 'brikpanel_order_numbers_sanitize_prefix', 10, 3 );
function brikpanel_order_numbers_sanitize_prefix( $value, $option = [], $raw = null ) {
	$posted = brikpanel_order_numbers_posted();
	return 'ok' === $posted['verdict'] ? $posted['prefix'] : null;
}

/**
 * After WooCommerce has stored the section (its save runs at 10 and is
 * added later than this file), move the counter to what was asked for.
 */
add_action( 'woocommerce_update_options_brikpanel', 'brikpanel_order_numbers_after_save', 20 );
function brikpanel_order_numbers_after_save() {
	if ( ! function_exists( 'brikpanel_settings_get_current_section' ) || 'orders' !== brikpanel_settings_get_current_section() ) {
		return;
	}
	// The verdict the sanitize filters reached for this save, used up here.
	$posted = isset( $GLOBALS['brikpanel_order_numbers_posted'] ) ? $GLOBALS['brikpanel_order_numbers_posted'] : null;
	unset( $GLOBALS['brikpanel_order_numbers_posted'] );
	if ( ! is_array( $posted ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	if ( 'ok' !== $posted['verdict'] || ! $posted['on'] ) {
		return;
	}
	$series = brikpanel_order_numbers_series();
	if ( $posted['prefix'] !== $posted['old_prefix'] || $series['prefix'] !== $posted['prefix'] || null === brikpanel_order_numbers_counter() ) {
		brikpanel_order_numbers_switch_series( $posted['prefix'], $posted['start'] );
	} elseif ( $posted['start'] > 0 ) {
		// Validated above: higher than every number in use with this prefix.
		brikpanel_order_numbers_counter_set( $posted['start'] - 1 );
		$series['seed'] = $posted['start'] - 1;
		brikpanel_order_numbers_save_series( $series );
	} elseif ( ! $posted['was_on'] ) {
		brikpanel_order_numbers_catch_up();
	}
}

/**
 * Live "Next order number" while the prefix or start is being typed. The
 * reply carries finished, translated text; the script only places it.
 */
add_action( 'wp_ajax_brikpanel_order_numbers_preview', 'brikpanel_order_numbers_ajax_preview' );
function brikpanel_order_numbers_ajax_preview() {
	check_ajax_referer( 'brikpanel_order_numbers_preview' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( [], 403 );
	}
	$prefix = isset( $_POST['prefix'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['prefix'] ) ) ) : '';
	$start  = isset( $_POST['start'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['start'] ) ) ) : '';

	if ( ! brikpanel_order_numbers_valid_prefix( $prefix ) ) {
		wp_send_json_success( [ 'next' => '', 'placeholder' => '', 'error' => brikpanel_order_numbers_prefix_rule_text() ] );
	}
	$natural = brikpanel_order_numbers_next( $prefix );
	$error   = '';
	$next    = $natural;
	if ( '' !== $start ) {
		if ( ! ctype_digit( $start ) || (int) $start < 1 || (int) $start > BRIKPANEL_ORDER_NUMBERS_MAX ) {
			$error = brikpanel_order_numbers_start_format_text();
		} else {
			$highest = brikpanel_order_numbers_highest( $prefix );
			if ( (int) $start <= $highest ) {
				$error = brikpanel_order_numbers_start_low_text( $prefix . $highest );
			} else {
				$next = (int) $start;
			}
		}
	}
	wp_send_json_success( [
		'next'        => '' === $error ? '#' . $prefix . $next : '',
		'placeholder' => (string) $natural,
		'error'       => $error,
	] );
}

add_action( 'admin_enqueue_scripts', 'brikpanel_order_numbers_enqueue' );
function brikpanel_order_numbers_enqueue() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- screen detection only.
	if ( ! isset( $_GET['page'], $_GET['tab'] ) || 'wc-settings' !== sanitize_key( wp_unslash( $_GET['page'] ) ) || 'brikpanel' !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
		return;
	}
	if ( ! function_exists( 'brikpanel_settings_get_current_section' ) || 'orders' !== brikpanel_settings_get_current_section() ) {
		return;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	$file = 'front-end/orders/brikpanel-order-numbers-settings.js';
	$path = BRIKPANEL_PATH . $file;
	wp_enqueue_script( 'brikpanel-order-numbers-settings', BRIKPANEL_URL . $file, [], is_readable( $path ) ? (string) filemtime( $path ) : BRIKPANEL_VERSION, true );
	wp_localize_script( 'brikpanel-order-numbers-settings', 'brikpanelOrderNumbers', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'brikpanel_order_numbers_preview' ),
	] );
}

/**
 * The error line under the preview in the shop's error red. Printed only on
 * the Orders section of the settings tab.
 */
add_action( 'admin_head', 'brikpanel_order_numbers_settings_style', 21 );
function brikpanel_order_numbers_settings_style() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- screen detection only.
	if ( ! isset( $_GET['page'], $_GET['tab'] ) || 'wc-settings' !== sanitize_key( wp_unslash( $_GET['page'] ) ) || 'brikpanel' !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
		return;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( ! function_exists( 'brikpanel_settings_get_current_section' ) || 'orders' !== brikpanel_settings_get_current_section() ) {
		return;
	}
	echo '<style id="brikpanel-order-numbers-style">.bp-settings-card .bp-onum-error{color:#d72c0d;margin-top:.25rem}.bp-settings-card .bp-onum-error:empty{display:none}</style>';
}
