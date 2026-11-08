<?php
/**
 * WPML compatibility helpers.
 *
 * Used by the status-change emails (an email to the customer is built in the
 * order's language), the product list (a small language tag on every row) and
 * every place BrikPanel writes a product price (a translation's price comes
 * from its original, as WooCommerce Multilingual keeps it).
 *
 * Everything here returns at its first line unless WPML itself runs: Polylang
 * answers some WPML hooks but defines neither ICL_SITEPRESS_VERSION nor
 * $sitepress, so a Polylang store never enters. The currency snapshot for
 * WooCommerce Multilingual's exchange rates is not here: WCML also runs
 * without WPML (multi-currency only), so it lives in brikpanel-currency.php.
 *
 * Reviewed with WPML's compatibility team (comp-4725, BrikPanel 3.3.45).
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether WPML itself runs on this request.
 *
 * @return bool
 */
function brikpanel_wpml_active() {
	return defined( 'ICL_SITEPRESS_VERSION' )
		&& isset( $GLOBALS['sitepress'] )
		&& is_object( $GLOBALS['sitepress'] );
}

/**
 * The store's active WPML languages, keyed by code. Empty when WPML is off or
 * the store has a single language, so callers can treat "empty" as "nothing to
 * mark or switch".
 *
 * @return array<string,array{code:string,name:string,locale:string}> name is in
 *         the admin's own language when WPML provides it.
 */
function brikpanel_wpml_languages() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}
	$memo = [];
	if ( ! brikpanel_wpml_active() || ! is_callable( [ $GLOBALS['sitepress'], 'get_active_languages' ] ) ) {
		return $memo;
	}
	$raw = $GLOBALS['sitepress']->get_active_languages();
	if ( ! is_array( $raw ) || count( $raw ) < 2 ) {
		return $memo;
	}
	foreach ( $raw as $code => $row ) {
		$code = sanitize_key( (string) $code );
		if ( '' === $code || ! is_array( $row ) ) {
			continue;
		}
		$name = '';
		foreach ( [ 'display_name', 'translated_name', 'native_name', 'english_name' ] as $key ) {
			if ( ! empty( $row[ $key ] ) && is_string( $row[ $key ] ) ) {
				$name = $row[ $key ];
				break;
			}
		}
		$memo[ $code ] = [
			'code'   => $code,
			'name'   => '' !== $name ? $name : strtoupper( $code ),
			'locale' => isset( $row['default_locale'] ) ? (string) $row['default_locale'] : '',
		];
	}
	if ( count( $memo ) < 2 ) {
		$memo = [];
	}
	return $memo;
}

/**
 * Whether products are translated with WPML on this store (WPML on, two or
 * more languages, products set to translatable).
 *
 * @return bool
 */
function brikpanel_wpml_products_translated() {
	static $memo = null;
	if ( null === $memo ) {
		$memo = brikpanel_wpml_languages()
			&& (bool) apply_filters( 'wpml_is_translated_post_type', null, 'product' );
	}
	return $memo;
}

/**
 * Language of each product, and its original when it is a translation, in
 * one query for the whole list (WPML keeps both in its icl_translations
 * table: a translation is a row whose source language is set, WooCommerce
 * Multilingual's own test for "not the original").
 *
 * Remembers what it read for the request, so a later single-product check
 * (a save handler, a bulk batch) costs no query.
 *
 * @param int[] $ids Product (parent) ids.
 * @return array<int,array{lang:string,original:int}> original is 0 for an
 *         original product, else the original's id. Ids WPML does not know
 *         are left out.
 */
function brikpanel_wpml_product_languages( array $ids ) {
	static $seen = [];
	if ( ! brikpanel_wpml_products_translated() ) {
		return [];
	}
	$ids  = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	$need = array_values( array_diff( $ids, array_keys( $seen ) ) );
	if ( $need ) {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_translations';
		foreach ( array_chunk( $need, 500 ) as $chunk ) {
			foreach ( $chunk as $id ) {
				$seen[ $id ] = null;
			}
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WPML's own table, read once per list page.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds one %d per id; $table is the prefixed WPML table name.
					"SELECT t.element_id, t.language_code, t.source_language_code, o.element_id AS original_id
					 FROM {$table} t
					 LEFT JOIN {$table} o
					   ON o.trid = t.trid AND o.element_type = t.element_type AND o.source_language_code IS NULL
					 WHERE t.element_type = 'post_product' AND t.element_id IN ($in)",
					$chunk
				)
			);
			foreach ( (array) $rows as $row ) {
				$is_translation = null !== $row->source_language_code && '' !== (string) $row->source_language_code;
				$original       = $is_translation ? (int) $row->original_id : 0;
				$seen[ (int) $row->element_id ] = [
					'lang'     => sanitize_key( (string) $row->language_code ),
					// A translation whose original was deleted keeps no link: it
					// is the only copy left, so its price is its own again.
					'original' => ( $original > 0 && $original !== (int) $row->element_id ) ? $original : 0,
				];
			}
		}
	}
	$out = [];
	foreach ( $ids as $id ) {
		if ( isset( $seen[ $id ] ) ) {
			$out[ $id ] = $seen[ $id ];
		}
	}
	return $out;
}

/**
 * The original product of a WPML translation; 0 for an original, a product
 * WPML does not know, or any store without WPML. A variation answers for its
 * parent: WooCommerce Multilingual translates a variable product as a whole.
 *
 * @param int $product_id Product or variation id.
 * @return int
 */
function brikpanel_wpml_original_of( $product_id ) {
	$product_id = absint( $product_id );
	if ( ! $product_id || ! brikpanel_wpml_products_translated() ) {
		return 0;
	}
	if ( 'product_variation' === get_post_type( $product_id ) ) {
		$product_id = (int) wp_get_post_parent_id( $product_id );
		if ( ! $product_id ) {
			return 0;
		}
	}
	$map = brikpanel_wpml_product_languages( [ $product_id ] );
	return isset( $map[ $product_id ] ) ? (int) $map[ $product_id ]['original'] : 0;
}

/**
 * After BrikPanel saved an original product, copy to its translations what
 * WPML and WooCommerce Multilingual keep in step (prices, sale dates, SKU,
 * stock, variations), as happens when the product is saved on WooCommerce's
 * own edit screen. Both run that copy on save only on the screens they know
 * (post.php and a few others), not on BrikPanel's AJAX saves, so an
 * original's new price used to stay off its translations.
 *
 * A translation (or a product WPML does not know) is left alone.
 *
 * @param int $product_id Product or variation id.
 * @return void
 */
function brikpanel_wpml_sync_translations( $product_id ) {
	$product_id = absint( $product_id );
	if ( ! $product_id || ! brikpanel_wpml_products_translated() ) {
		return;
	}
	if ( 'product_variation' === get_post_type( $product_id ) ) {
		$product_id = (int) wp_get_post_parent_id( $product_id );
	}
	$map = $product_id ? brikpanel_wpml_product_languages( [ $product_id ] ) : [];
	if ( ! isset( $map[ $product_id ] ) || $map[ $product_id ]['original'] ) {
		return;
	}
	$post = get_post( $product_id );
	if ( ! $post instanceof WP_Post ) {
		return;
	}
	// Same order as a save on WooCommerce's edit screen. First WPML copies
	// the fields its configuration marks "copy" (WooCommerce Multilingual's
	// file marks the prices, sale dates, SKU and stock).
	do_action( 'wpml_sync_all_custom_fields', $product_id );
	// Then WCML copies what it handles itself (variations and their prices,
	// attributes, stock status). It registers the hook early but builds what
	// it needs on init, and only when its own requirements are met (it stays
	// half-loaded without WPML String Translation): ask it only when it fully
	// runs, and never let its copy break the save that already happened.
	$wcml = isset( $GLOBALS['woocommerce_wpml'] ) ? $GLOBALS['woocommerce_wpml'] : null;
	if ( has_action( 'wcml_synchronize_product_translations' ) && is_object( $wcml ) && ! empty( $wcml->products ) && ! empty( $wcml->media ) ) {
		try {
			do_action( 'wcml_synchronize_product_translations', $post );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'BrikPanel: WooCommerce Multilingual could not copy product ' . $product_id . ' to its translations: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}
}

/**
 * The note shown where a translation's price would be typed, and its link.
 *
 * @param int $original_id The original product's id (0: no link).
 * @return array{text:string,link:string,url:string}
 */
function brikpanel_wpml_price_lock_note( $original_id = 0 ) {
	$original_id = absint( $original_id );
	return [
		'text' => __( 'This is a translation. Its price comes from the original product.', 'brikpanel' ),
		'link' => __( 'Edit the original', 'brikpanel' ),
		'url'  => $original_id ? (string) get_edit_post_link( $original_id, 'raw' ) : '',
	];
}

/**
 * The language a WooCommerce order was placed in, as a WPML language code;
 * '' when it cannot be told.
 *
 * WooCommerce Multilingual records it on the order (`wpml_language`), the
 * block checkout's orders included. Without that record (WPML running without
 * WooCommerce Multilingual, orders older than the multilingual setup, WCML
 * half-loaded because a plugin it needs is missing) the products on the order
 * decide: under WPML every language has its own product posts, so a Finnish
 * order holds the Finnish ones. The majority of the lines wins, as in
 * BrikMentor's order_locale().
 *
 * @param WC_Order $order Order.
 * @return string
 */
function brikpanel_wpml_order_language( $order ) {
	$languages = brikpanel_wpml_languages();
	if ( ! $languages || ! $order instanceof WC_Order ) {
		return '';
	}
	$stamped = sanitize_key( (string) $order->get_meta( 'wpml_language', true ) );
	if ( '' !== $stamped && isset( $languages[ $stamped ] ) ) {
		return $stamped;
	}
	$ids = [];
	foreach ( $order->get_items() as $item_id => $item ) {
		// The product stored on the line, not the one the item reports: in
		// wp-admin WooCommerce Multilingual swaps each line's product for the
		// admin's language when the items are read.
		$pid = function_exists( 'wc_get_order_item_meta' ) ? (int) wc_get_order_item_meta( $item_id, '_product_id', true ) : 0;
		if ( ! $pid && is_callable( [ $item, 'get_product_id' ] ) ) {
			$pid = (int) $item->get_product_id();
		}
		if ( $pid ) {
			$ids[] = $pid;
		}
	}
	$map   = brikpanel_wpml_product_languages( $ids );
	$votes = [];
	foreach ( $ids as $id ) {
		if ( isset( $map[ $id ] ) && isset( $languages[ $map[ $id ]['lang'] ] ) ) {
			$lang           = $map[ $id ]['lang'];
			$votes[ $lang ] = ( $votes[ $lang ] ?? 0 ) + 1;
		}
	}
	if ( ! $votes ) {
		return '';
	}
	arsort( $votes, SORT_NUMERIC );
	return (string) key( $votes );
}

/**
 * plugin_locale while a language is pinned: the locale WordPress was switched
 * to. On WordPress 6.0-6.1 the locale switcher does not filter
 * determine_locale(), which plugin translations load with in wp-admin (the
 * user's own language), so plugin strings would stay in the admin's language.
 * A function of our own, not 'get_locale' itself, so removing it never takes
 * down another plugin's identical pin.
 *
 * @return string
 */
function brikpanel_wpml_pinned_plugin_locale() {
	return get_locale();
}

/**
 * Build what follows in a WPML language: WPML's current language (String
 * Translation hands out that language's texts), WordPress's locale (__() and
 * dates follow) and, for the given options, String Translation's admin texts
 * also inside wp-admin, where it otherwise leaves them in the original so the
 * settings screens edit the originals. WooCommerce Multilingual asks the same
 * way before it builds WooCommerce's own emails.
 *
 * The language is switched without touching the visitor's language cookie:
 * do_action( 'wpml_switch_language' ) also rewrites it, which in wp-admin moves
 * the admin's own language switcher to the order's language.
 *
 * @param string   $lang    WPML language code.
 * @param string[] $options Option names String Translation should translate now.
 * @return array|null State for brikpanel_wpml_restore(); null when nothing changed.
 */
function brikpanel_wpml_switch( $lang, array $options = [] ) {
	$lang      = sanitize_key( (string) $lang );
	$languages = brikpanel_wpml_languages();
	if ( '' === $lang || ! isset( $languages[ $lang ] ) ) {
		return null;
	}
	$sitepress = $GLOBALS['sitepress'];
	$state     = [
		'lang'   => false,
		'locale' => false,
	];

	$current = apply_filters( 'wpml_current_language', null );
	if ( $current !== $lang && is_callable( [ $sitepress, 'switch_lang' ] ) ) {
		$sitepress->switch_lang( $lang, false );
		$state['lang'] = is_string( $current ) && '' !== $current ? $current : null;
	}

	$locale = $languages[ $lang ]['locale'];
	if ( '' !== $locale && function_exists( 'switch_to_locale' ) && determine_locale() !== $locale && switch_to_locale( $locale ) ) {
		add_filter( 'plugin_locale', 'brikpanel_wpml_pinned_plugin_locale', PHP_INT_MAX );
		$state['locale'] = true;
	}

	if ( $options ) {
		do_action( 'wpml_st_force_translate_admin_options', array_values( $options ) );
	}

	return ( false === $state['lang'] && ! $state['locale'] && ! $options ) ? null : $state;
}

/**
 * Undo brikpanel_wpml_switch(): WordPress's locale first, then WPML's
 * language, back to exactly what it was.
 *
 * @param array|null $state What brikpanel_wpml_switch() returned.
 * @return void
 */
function brikpanel_wpml_restore( $state ) {
	if ( ! is_array( $state ) ) {
		return;
	}
	if ( ! empty( $state['locale'] ) ) {
		remove_filter( 'plugin_locale', 'brikpanel_wpml_pinned_plugin_locale', PHP_INT_MAX );
		restore_previous_locale();
	}
	if ( false !== $state['lang'] && brikpanel_wpml_active() && is_callable( [ $GLOBALS['sitepress'], 'switch_lang' ] ) ) {
		$GLOBALS['sitepress']->switch_lang( $state['lang'], false );
	}
}
