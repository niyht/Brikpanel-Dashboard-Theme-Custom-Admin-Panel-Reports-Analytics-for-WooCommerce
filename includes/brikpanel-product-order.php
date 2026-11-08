<?php
/**
 * BrikPanel - The shop's custom product order.
 *
 * WooCommerce shows products in one default order, the "Default product sorting"
 * setting (Appearance > Customize). Its "Default sorting (custom ordering + name)"
 * sorts by each product's menu_order, then by name; "Sort by most recent" sorts
 * by date. This file keeps menu_order for the products list's Sort mode:
 *
 * - brikpanel_product_order_place(): the rows a user dragged take each other's
 *   places and no other product moves. Up to 3.3.45 the list numbered only the
 *   visible page from 0, so on a store that was never sorted (every product at 0)
 *   the first drag sent the products of every other page ahead of page 1.
 * - brikpanel_product_order_start_newest_first(): a store that shows the newest
 *   products first switches to a custom order that starts in that same order,
 *   so nothing moves until the owner moves a product.
 * - A product published for the first time in such a store goes on top, where
 *   "Sort by most recent" would show it.
 *
 * Global: products are published from wp-admin, BrikPanel's editor, the REST
 * API, imports, cron (scheduled products) and WP-CLI, and each of them must
 * take its place on top.
 *
 * @package BrikPanel
 * @since 3.3.46
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks a custom order started newest first ('yes'): new products go on top of it.
 * Internal state, not a setting.
 */
if ( ! defined( 'BRIKPANEL_PRODUCT_ORDER_NEWEST_OPTION' ) ) {
	define( 'BRIKPANEL_PRODUCT_ORDER_NEWEST_OPTION', 'brikpanel_product_order_newest_on_top' );
}

/**
 * The shop's default product order as the shop applies it: WooCommerce's
 * "Default product sorting" after the filter themes and plugins use.
 * 'menu_order' is the custom order, 'date' is "Sort by most recent".
 *
 * @return string
 */
function brikpanel_product_order_store_sort() {
	$value = apply_filters( 'woocommerce_default_catalog_orderby', get_option( 'woocommerce_default_catalog_orderby', 'menu_order' ) );
	return ( is_string( $value ) && '' !== $value ) ? $value : 'menu_order';
}

/**
 * Whether any product already holds a custom order number (menu_order other
 * than 0). Starting a custom order newest first replaces those numbers.
 *
 * @return bool
 */
function brikpanel_product_order_has_numbers() {
	global $wpdb;
	return (bool) $wpdb->get_var(
		"SELECT 1 FROM {$wpdb->posts}
		  WHERE post_type = 'product'
		    AND post_status NOT IN ('trash', 'auto-draft')
		    AND menu_order <> 0
		  LIMIT 1"
	);
}

/**
 * Every product in the order the Sort mode list shows them and the shop sorts
 * them: menu_order, then name, then ID. Trash and auto-drafts are not in it.
 *
 * Read straight from the posts table: a WP_Query here would follow the admin's
 * WPML language and other plugins' pre_get_posts filters, while the list shows
 * every language. MySQL sorts the names, as it does for the list and the shop.
 *
 * @return array<int,int> Product ID => menu_order, in order.
 */
function brikpanel_product_order_current() {
	global $wpdb;
	$rows  = $wpdb->get_results(
		"SELECT ID, menu_order FROM {$wpdb->posts}
		  WHERE post_type = 'product'
		    AND post_status NOT IN ('trash', 'auto-draft')
		  ORDER BY menu_order ASC, post_title ASC, ID ASC",
		ARRAY_N
	);
	$order = array();
	foreach ( (array) $rows as $row ) {
		$order[ (int) $row[0] ] = (int) $row[1];
	}
	return $order;
}

/**
 * Writes new menu_order values in batches of 500 and clears what caches them.
 *
 * Direct UPDATE, as WooCommerce's own sorting does: menu_order needs no
 * save_post side effects, and a store sorted for the first time renumbers
 * every product at once.
 *
 * @param array<int,int> $changes Product ID => new menu_order (only the ones that change).
 */
function brikpanel_product_order_write( array $changes ) {
	global $wpdb;
	if ( empty( $changes ) ) {
		return;
	}
	foreach ( array_chunk( $changes, 500, true ) as $chunk ) {
		$cases  = array();
		$values = array();
		foreach ( $chunk as $id => $order ) {
			$cases[]  = 'WHEN %d THEN %d';
			$values[] = (int) $id;
			$values[] = (int) $order;
		}
		$ids          = array_map( 'intval', array_keys( $chunk ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$case_sql     = implode( ' ', $cases );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts}
				    SET menu_order = CASE ID {$case_sql} ELSE menu_order END
				  WHERE post_type = 'product'
				    AND ID IN ($placeholders)",
				array_merge( $values, $ids )
			)
		);
		// phpcs:enable
		wp_cache_delete_multiple( $ids, 'posts' );
	}
	wp_cache_set_posts_last_changed();
	if ( class_exists( 'WC_Post_Data' ) && method_exists( 'WC_Post_Data', 'delete_product_query_transients' ) ) {
		WC_Post_Data::delete_product_query_transients();
	} elseif ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}
}

/**
 * Tells WooCommerce's listeners that the product order changed, as WooCommerce's
 * own sorting does: WP Rocket clears the shop and category pages, WPML
 * (WooCommerce Multilingual) and Polylang copy the order to translations.
 *
 * @param int            $product_id A product that moved.
 * @param array<int,int> $changes    Product ID => new menu_order.
 */
function brikpanel_product_order_announce( $product_id, array $changes ) {
	if ( empty( $changes ) ) {
		return;
	}
	ob_start(); // A listener's stray output must not break the JSON reply.
	do_action( 'woocommerce_after_product_ordering', (int) $product_id, $changes );
	ob_end_clean();
}

/**
 * Puts products in the order a user dragged them in the Sort mode list. The
 * products take each other's places and no other product moves, on any page
 * and in a filtered or searched list too.
 *
 * Their own numbers are given back in the new order when each number belongs
 * to one product only: then at most one page of rows is written. A store whose
 * products share numbers (one never sorted keeps all at 0) is numbered once
 * from 1 in its current order, the way WooCommerce's own sorting renumbers.
 *
 * @param int[] $ids Product IDs top to bottom, as the user left them.
 * @return array{orders: array<int,int>, changes: array<int,int>} The given
 *         products' numbers after the save, and every number that changed.
 */
function brikpanel_product_order_place( array $ids ) {
	$current = brikpanel_product_order_current();
	$given   = array();
	foreach ( $ids as $id ) {
		$id = (int) $id;
		if ( isset( $current[ $id ] ) && ! isset( $given[ $id ] ) ) {
			$given[ $id ] = $id;
		}
	}
	$ids = array_values( $given );
	if ( count( $ids ) < 2 ) {
		return array( 'orders' => array(), 'changes' => array() );
	}

	$values = array();
	foreach ( $ids as $id ) {
		$values[] = $current[ $id ];
	}
	sort( $values, SORT_NUMERIC );

	$holders = array_count_values( $current );
	$unique  = true;
	foreach ( $values as $value ) {
		if ( 1 !== $holders[ $value ] ) {
			$unique = false;
			break;
		}
	}

	$changes = array();
	if ( $unique ) {
		foreach ( $ids as $k => $id ) {
			if ( $current[ $id ] !== $values[ $k ] ) {
				$changes[ $id ] = $values[ $k ];
			}
		}
	} else {
		// The places these products hold in the whole list, top first: the
		// dragged products fill them in their new order.
		$order = array_keys( $current );
		$place = array_flip( $order );
		$slots = array();
		foreach ( $ids as $id ) {
			$slots[] = $place[ $id ];
		}
		sort( $slots, SORT_NUMERIC );
		foreach ( $slots as $k => $slot ) {
			$order[ $slot ] = $ids[ $k ];
		}
		foreach ( $order as $i => $id ) {
			if ( $current[ $id ] !== $i + 1 ) {
				$changes[ $id ] = $i + 1;
			}
		}
	}

	brikpanel_product_order_write( $changes );

	$orders = array();
	foreach ( $ids as $id ) {
		$orders[ $id ] = isset( $changes[ $id ] ) ? $changes[ $id ] : $current[ $id ];
	}
	return array( 'orders' => $orders, 'changes' => $changes );
}

/**
 * Starts a custom order that begins newest first, the order "Sort by most
 * recent" shows (date, then ID, newest on top), and makes it the shop's
 * default sorting. Nothing in the shop moves until the owner moves a product;
 * products published later go on top (brikpanel_product_order_place_new()).
 *
 * @return array{store_sort: string, first: int, changes: array<int,int>}
 *         The shop's sorting afterwards (a theme or plugin may still force
 *         its own), the newest product, every number that changed.
 */
function brikpanel_product_order_start_newest_first() {
	global $wpdb;
	$ids = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts}
		  WHERE post_type = 'product'
		    AND post_status NOT IN ('trash', 'auto-draft')
		  ORDER BY post_date DESC, ID DESC"
	);
	$current = brikpanel_product_order_current();
	$changes = array();
	foreach ( (array) $ids as $i => $id ) {
		$id = (int) $id;
		if ( ! isset( $current[ $id ] ) || $current[ $id ] !== $i + 1 ) {
			$changes[ $id ] = $i + 1;
		}
	}
	brikpanel_product_order_write( $changes );

	update_option( 'woocommerce_default_catalog_orderby', 'menu_order' );
	update_option( BRIKPANEL_PRODUCT_ORDER_NEWEST_OPTION, 'yes', false );

	return array(
		'store_sort' => brikpanel_product_order_store_sort(),
		'first'      => empty( $ids ) ? 0 : (int) $ids[0],
		'changes'    => $changes,
	);
}

/**
 * Remembers a product published for the first time while the shop uses a
 * custom order started newest first. It is placed at the end of the request:
 * WooCommerce and BrikPanel's editor may still save the product after this
 * hook, and WooCommerce's deferred product sync runs on shutdown (priority 10).
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Previous post status.
 * @param WP_Post $post       The post.
 */
function brikpanel_product_order_queue_new( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || ! ( $post instanceof WP_Post ) || 'product' !== $post->post_type ) {
		return;
	}
	if ( ! in_array( $old_status, array( 'new', 'auto-draft', 'draft', 'pending', 'future', 'importing' ), true ) ) {
		return;
	}
	if ( 'yes' !== get_option( BRIKPANEL_PRODUCT_ORDER_NEWEST_OPTION ) || 'menu_order' !== brikpanel_product_order_store_sort() ) {
		return;
	}
	$blog = get_current_blog_id();
	if ( empty( $GLOBALS['brikpanel_product_order_new'] ) ) {
		$GLOBALS['brikpanel_product_order_new'] = array();
		add_action( 'shutdown', 'brikpanel_product_order_place_new', 100 );
	}
	$GLOBALS['brikpanel_product_order_new'][ $blog ][ (int) $post->ID ] = (int) $post->ID;
}
add_action( 'transition_post_status', 'brikpanel_product_order_queue_new', 10, 3 );

/**
 * Puts the products published in this request on top of the custom order,
 * newest highest. A product goes on top only when it is newer than every other
 * published product, as "Sort by most recent" would show it: a backdated or
 * republished product keeps its place.
 */
function brikpanel_product_order_place_new() {
	global $wpdb;
	$queue = isset( $GLOBALS['brikpanel_product_order_new'] ) ? (array) $GLOBALS['brikpanel_product_order_new'] : array();
	unset( $GLOBALS['brikpanel_product_order_new'] );

	foreach ( $queue as $blog => $ids ) {
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			continue;
		}
		$switched = false;
		if ( is_multisite() && (int) $blog !== get_current_blog_id() ) {
			switch_to_blog( (int) $blog );
			$switched = true;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_date FROM {$wpdb->posts}
				  WHERE post_type = 'product' AND post_status = 'publish'
				    AND ID IN ($placeholders)
				  ORDER BY post_date ASC, ID ASC",
				$ids
			)
		);
		$newest = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ID, post_date FROM {$wpdb->posts}
				  WHERE post_type = 'product' AND post_status = 'publish'
				    AND ID NOT IN ($placeholders)
				  ORDER BY post_date DESC, ID DESC
				  LIMIT 1",
				$ids
			)
		);
		$top    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(menu_order) FROM {$wpdb->posts}
				  WHERE post_type = 'product'
				    AND post_status NOT IN ('trash', 'auto-draft')
				    AND ID NOT IN ($placeholders)",
				$ids
			)
		);
		// phpcs:enable

		$next    = null === $top ? 1 : (int) $top;
		$changes = array();
		foreach ( (array) $rows as $row ) {
			if ( $newest && ( $row->post_date < $newest->post_date || ( $row->post_date === $newest->post_date && (int) $row->ID < (int) $newest->ID ) ) ) {
				continue;
			}
			--$next;
			$changes[ (int) $row->ID ] = $next;
		}

		if ( ! empty( $changes ) ) {
			brikpanel_product_order_write( $changes );
			foreach ( array_keys( $changes ) as $id ) {
				clean_post_cache( $id );
			}
			brikpanel_product_order_announce( (int) array_key_last( $changes ), $changes );
		}

		if ( $switched ) {
			restore_current_blog();
		}
	}
}
