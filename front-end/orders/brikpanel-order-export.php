<?php
/**
 * BrikPanel - export the orders list to Excel or CSV.
 *
 * The orders list gets an "Export" button in its title row (placed by
 * front-end/orders/brikpanel-orders.js, window in brikpanel-order-export.js).
 * It exports either the orders ticked on the page or every order the list
 * holds right now: the status tab, month, customer, search, shipping method
 * and whatever filter another plugin adds. One row per order (Excel and CSV);
 * in Excel a second sheet has one row per item line, so a variable product's
 * variations are counted apart. Both carry the weight the order screen shows
 * (front-end/order/brikpanel-order-weight.php): the order's total on its row,
 * each line's on the second sheet, for booking parcels.
 *
 * Flow:
 *  1. Prepare. The window posts to the list's own address, not to admin-ajax,
 *     because only a real list request carries the list's filters. A plugin
 *     that filters the list often registers itself on that screen alone, and
 *     WooCommerce's own search on the posts storage list asks for
 *     $pagenow === 'edit.php'. The list's query is captured before the page
 *     prints anything (HPOS: on load-<screen>, right after WooCommerce set its
 *     table up; posts storage: the main query, read on the `wp` action), every
 *     matching order id is read in one query, and the request ends with JSON.
 *  2. Steps (admin-ajax). Each request writes about four seconds' worth of
 *     orders to the job's files and saves where it got to, so a big store never
 *     hits a time limit. A step that died half way is safe to repeat: the files
 *     are cut back to the last saved length first.
 *  3. Download (admin-post). The file is sent and deleted.
 *  Cancel (admin-ajax) deletes the job and its files.
 *
 * The files live in the export folder (includes/brikpanel-export-files.php).
 *
 * Who: the enhanced orders page on (brikpanel_orders_enhancements), BrikPanel
 * not switched off for the account, and manage_woocommerce + edit_shop_orders,
 * like the other BrikPanel exports. Every request checks the nonce and the
 * capabilities; steps, cancel and download also check that the job belongs to
 * the person asking.
 *
 * @package BrikPanel
 */

defined( 'ABSPATH' ) || exit;

const BRIKPANEL_ORDER_EXPORT_NONCE = 'brikpanel_order_export';
const BRIKPANEL_ORDER_EXPORT_JOB   = 'brikpanel_oexp_';

/** Most orders that can be ticked on one list page (WordPress allows 999 per page). */
const BRIKPANEL_ORDER_EXPORT_MAX_SELECTED = 1000;

/** Seconds of work per step request. */
const BRIKPANEL_ORDER_EXPORT_BUDGET = 4.0;

/** Orders read and written together. */
const BRIKPANEL_ORDER_EXPORT_GROUP = 50;

/** Rows Excel holds per sheet. */
const BRIKPANEL_ORDER_EXPORT_SHEET_ROWS = 1048576;

// =============================================================================
// WHO AND WHERE
// =============================================================================

/**
 * Capabilities the export asks for.
 *
 * @return bool
 */
function brikpanel_order_export_user_can() {
	return current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_shop_orders' );
}

/**
 * Whether the current person gets the export.
 *
 * @return bool
 */
function brikpanel_order_export_enabled() {
	if ( get_option( 'brikpanel_orders_enhancements', 'yes' ) === 'no' ) {
		return false;
	}
	if ( function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize() ) {
		return false;
	}
	return brikpanel_order_export_user_can();
}

/**
 * What a file cut at Excel's row limit says, in the window and in its last row.
 *
 * @return string
 */
function brikpanel_order_export_capped_note() {
	return sprintf(
		/* translators: %s: the most rows one Excel sheet holds, already formatted (1,048,576). */
		__( 'Excel holds at most %s rows per sheet, so the file stops there. Export as CSV to get every order.', 'brikpanel' ),
		brikpanel_number( BRIKPANEL_ORDER_EXPORT_SHEET_ROWS )
	);
}

/**
 * Whether Excel files can be written here (the PHP zip extension).
 *
 * @return bool
 */
function brikpanel_order_export_can_xlsx() {
	return class_exists( 'ZipArchive' );
}

// =============================================================================
// ASSETS
// =============================================================================

/**
 * Script and styles for the window, on the orders list only.
 *
 * @return void
 */
function brikpanel_order_export_enqueue() {
	if ( ! function_exists( 'brikpanel_orders_compact_is_list_screen' ) || ! brikpanel_orders_compact_is_list_screen() ) {
		return;
	}
	if ( ! brikpanel_order_export_enabled() ) {
		return;
	}

	$css = 'front-end/orders/brikpanel-order-export.css';
	$js  = 'front-end/orders/brikpanel-order-export.js';
	// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- falls back to the plugin version.
	wp_enqueue_style( 'brikpanel_order_export', BRIKPANEL_URL . $css, [], @filemtime( BRIKPANEL_PATH . $css ) ?: BRIKPANEL_VERSION );
	wp_enqueue_script( 'brikpanel_order_export', BRIKPANEL_URL . $js, brikpanel_narrow_deps( [ 'format' ] ), @filemtime( BRIKPANEL_PATH . $js ) ?: BRIKPANEL_VERSION, true );
	// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged

	$can_xlsx = brikpanel_order_export_can_xlsx();
	wp_localize_script( 'brikpanel_order_export', 'brikpanelOrderExportConfig', [
		'ajax_url'     => admin_url( 'admin-ajax.php' ),
		'download_url' => admin_url( 'admin-post.php?action=brikpanel_order_export_download' ),
		'nonce'        => wp_create_nonce( BRIKPANEL_ORDER_EXPORT_NONCE ),
		'can_xlsx'     => $can_xlsx,
		// Filled in on admin_footer, once the list has been read.
		'total'        => 0,
		'filtered'     => false,
		'i18n'         => [
			'export'        => __( 'Export', 'brikpanel' ),
			'title'         => __( 'Export orders', 'brikpanel' ),
			'close'         => __( 'Close', 'brikpanel' ),
			'cancel'        => __( 'Cancel', 'brikpanel' ),
			'orders'        => __( 'Orders', 'brikpanel' ),
			/* translators: %s: number of orders ticked in the list. */
			'selected'      => brikpanel_js_plural( _n_noop( 'Selected order (%s)', 'Selected orders (%s)', 'brikpanel' ) ),
			/* translators: %s: number of orders the filtered list holds. */
			'in_list'       => brikpanel_js_plural( _n_noop( 'Order in this list (%s)', 'Orders in this list (%s)', 'brikpanel' ) ),
			/* translators: %s: number of orders in the store's list. */
			'all'           => brikpanel_js_plural( _n_noop( 'All orders (%s)', 'All orders (%s)', 'brikpanel' ) ),
			'file_type'     => __( 'File type', 'brikpanel' ),
			'xlsx'          => __( 'Excel (.xlsx)', 'brikpanel' ),
			'xlsx_hint'     => __( 'Orders and products on separate sheets', 'brikpanel' ),
			'csv'           => __( 'CSV', 'brikpanel' ),
			'csv_hint'      => __( 'One row per order, for other programs', 'brikpanel' ),
			'no_zip'        => __( 'This server cannot write Excel files (the PHP zip extension is missing). CSV works.', 'brikpanel' ),
			'preparing'     => __( 'Reading the list…', 'brikpanel' ),
			/* translators: 1: orders written so far, 2: orders to export. */
			'progress'      => brikpanel_js_plural( _n_noop( '%1$s of %2$s order', '%1$s of %2$s orders', 'brikpanel' ) ),
			'building'      => __( 'Creating the file…', 'brikpanel' ),
			/* translators: %s: number of orders in the downloaded file. */
			'done'          => brikpanel_js_plural( _n_noop( '%s order exported', '%s orders exported', 'brikpanel' ) ),
			'capped'        => brikpanel_order_export_capped_note(),
			'err_expired'   => __( 'Your session has expired. Please reload the page.', 'brikpanel' ),
			'err_empty'     => __( 'There are no orders to export.', 'brikpanel' ),
			'err_disk'      => __( 'The file could not be created. Check that the uploads folder can be written to.', 'brikpanel' ),
			'err_list'      => __( 'The list could not be read. Tick the orders you need and export those.', 'brikpanel' ),
			'err_failed'    => __( 'The export stopped. Please try again.', 'brikpanel' ),
		],
	] );
}
add_action( 'admin_enqueue_scripts', 'brikpanel_order_export_enqueue', 20 );

/**
 * How many orders the list holds and whether a filter narrows it, handed to
 * the window once the list has been read (admin_footer runs before the footer
 * scripts are printed).
 *
 * @return void
 */
function brikpanel_order_export_footer() {
	if ( ! wp_script_is( 'brikpanel_order_export', 'enqueued' ) ) {
		return;
	}
	$total = brikpanel_order_export_list_total();
	wp_add_inline_script(
		'brikpanel_order_export',
		'if(window.brikpanelOrderExportConfig){window.brikpanelOrderExportConfig.total=' . (int) $total . ';window.brikpanelOrderExportConfig.filtered=' . ( brikpanel_order_export_list_is_filtered() ? 'true' : 'false' ) . ';}',
		'before'
	);
}
add_action( 'admin_footer', 'brikpanel_order_export_footer', 5 );

/**
 * The number of orders the list said it holds.
 *
 * @return int
 */
function brikpanel_order_export_list_total() {
	$table = null;
	if ( isset( $GLOBALS['wp_list_table'] ) && $GLOBALS['wp_list_table'] instanceof WP_List_Table ) {
		$table = $GLOBALS['wp_list_table']; // Posts storage list.
	} else {
		$table = brikpanel_order_export_hpos_table();
	}
	if ( ! $table instanceof WP_List_Table ) {
		return 0;
	}
	return max( 0, (int) $table->get_pagination_arg( 'total_items' ) );
}

/**
 * Whether anything narrows the list (a status tab, a month, a customer, a
 * search or another plugin's filter), read from the address the way the list
 * reads it. The window then says "Orders in this list" instead of "All orders".
 *
 * @return bool
 */
function brikpanel_order_export_list_is_filtered() {
	// Keys that page, sort or belong to the form itself, never narrow it.
	$ignore = [ 'page', 'post_type', 'paged', 'orderby', 'order', 'action', 'action2', 'filter_action', '_wpnonce', '_wp_http_referer', 'search-filter', 'order_date_type', 'mode' ];
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only look at the list's own filters.
	foreach ( (array) $_GET as $key => $value ) {
		if ( in_array( (string) $key, $ignore, true ) ) {
			continue;
		}
		if ( is_array( $value ) ) {
			if ( array_filter( array_map( 'strval', $value ), 'strlen' ) ) {
				return true;
			}
			continue;
		}
		$value = trim( (string) wp_unslash( $value ) );
		if ( in_array( $value, [ '', '0', '-1', 'all' ], true ) ) {
			continue;
		}
		return true;
	}
	return false;
}

// =============================================================================
// PREPARE (posted to the list's own address)
// =============================================================================

/**
 * Whether this request is the window asking to start an export.
 *
 * @return bool
 */
function brikpanel_order_export_is_prepare_request() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked before anything is done.
	return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['brikpanel_order_export'] ) && 'prepare' === $_POST['brikpanel_order_export'];
}

/**
 * Whether the start request carries a valid nonce. Not check_ajax_referer():
 * WooCommerce sets its list screens up on that action.
 *
 * @return bool
 */
function brikpanel_order_export_prepare_nonce_ok() {
	$nonce = isset( $_POST['brikpanel_oexp_nonce'] ) && is_string( $_POST['brikpanel_oexp_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['brikpanel_oexp_nonce'] ) ) : '';
	return '' !== $nonce && false !== wp_verify_nonce( $nonce, BRIKPANEL_ORDER_EXPORT_NONCE );
}

/**
 * Answer with an error code the window turns into a sentence, and stop.
 *
 * @param string $code   expired | denied | empty | disk | list | gone | bad | failed.
 * @param int    $status HTTP status.
 * @return void
 */
function brikpanel_order_export_fail( $code, $status = 400 ) {
	wp_send_json_error( [ 'code' => $code ], $status );
}

/**
 * The start request's own fields, checked; answers and stops when one is wrong.
 *
 * @return array{format: string, scope: string, ids: int[]}
 */
function brikpanel_order_export_prepare_input() {
	if ( ! brikpanel_order_export_prepare_nonce_ok() ) {
		brikpanel_order_export_fail( 'expired', 403 );
	}
	if ( ! brikpanel_order_export_enabled() ) {
		brikpanel_order_export_fail( 'denied', 403 );
	}
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
	$format = isset( $_POST['format'] ) && is_string( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : '';
	$scope  = isset( $_POST['scope'] ) && is_string( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
	$raw    = isset( $_POST['ids'] ) && is_string( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	if ( ! in_array( $format, [ 'xlsx', 'csv' ], true ) || ! in_array( $scope, [ 'selected', 'list' ], true ) ) {
		brikpanel_order_export_fail( 'bad' );
	}
	if ( 'xlsx' === $format && ! brikpanel_order_export_can_xlsx() ) {
		brikpanel_order_export_fail( 'bad' );
	}
	$ids = [];
	if ( 'selected' === $scope ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) );
		if ( count( $ids ) > BRIKPANEL_ORDER_EXPORT_MAX_SELECTED ) {
			brikpanel_order_export_fail( 'bad' );
		}
	}
	return [
		'format' => $format,
		'scope'  => $scope,
		'ids'    => $ids,
	];
}

/**
 * HPOS list: WooCommerce built its table on load-<screen> at priority 10.
 *
 * @return void
 */
function brikpanel_order_export_prepare_hpos() {
	if ( ! brikpanel_order_export_is_prepare_request() ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen detection; the nonce is checked next.
	$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( 'wc-orders' !== $page || in_array( $action, [ 'edit', 'new' ], true ) ) {
		return;
	}
	$input = brikpanel_order_export_prepare_input();
	if ( 'selected' === $input['scope'] ) {
		$ids = brikpanel_order_export_selected_ids( $input['ids'] );
	} else {
		$ids = brikpanel_order_export_hpos_list_ids();
		if ( null === $ids ) {
			brikpanel_order_export_fail( 'list', 500 );
		}
	}
	brikpanel_order_export_start( $ids, $input );
}
add_action( 'load-woocommerce_page_wc-orders', 'brikpanel_order_export_prepare_hpos', 20 );
add_action( 'load-admin_page_wc-orders', 'brikpanel_order_export_prepare_hpos', 20 );

/**
 * WooCommerce's HPOS list table, as the screen set it up.
 *
 * @return WP_List_Table|null
 */
function brikpanel_order_export_hpos_table() {
	$class = 'Automattic\\WooCommerce\\Internal\\Admin\\Orders\\ListTable';
	if ( ! function_exists( 'wc_get_container' ) || ! class_exists( $class ) ) {
		return null;
	}
	try {
		$table = wc_get_container()->get( $class );
	} catch ( Throwable $e ) {
		return null;
	}
	return $table instanceof WP_List_Table ? $table : null;
}

/**
 * Every order id the HPOS list holds, in its order. The list builds its
 * query (tab, month, customer, search, sort, and every plugin's filter on
 * woocommerce_order_list_table_prepare_items_query_args); the last filter in
 * the chain hands the result over and lets the list's own page query fetch a
 * single row, then all ids are read in one query.
 *
 * @return int[]|null Null when the list could not be read.
 */
function brikpanel_order_export_hpos_list_ids() {
	$table = brikpanel_order_export_hpos_table();
	if ( ! $table || ! method_exists( $table, 'prepare_items' ) ) {
		return null;
	}

	$captured = null;
	$capture  = static function ( $args ) use ( &$captured ) {
		$captured      = (array) $args;
		$args['limit'] = 1;
		$args['page']  = 1;
		return $args;
	};
	add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', $capture, PHP_INT_MAX );
	try {
		$table->prepare_items();
	} catch ( Throwable $e ) {
		$captured = null;
	}
	remove_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', $capture, PHP_INT_MAX );

	if ( ! is_array( $captured ) ) {
		return null;
	}
	unset( $captured['page'], $captured['paged'], $captured['offset'], $captured['paginate'], $captured['no_found_rows'] );
	$captured['limit']  = -1;
	$captured['return'] = 'ids';

	try {
		$ids = wc_get_orders( $captured );
	} catch ( Throwable $e ) {
		return null;
	}
	return is_array( $ids ) ? array_values( array_map( 'absint', $ids ) ) : null;
}

/**
 * Posts storage list: turn the list's own main query into "every id, no
 * paging" for the start request. Runs last, after WooCommerce turned a search
 * into ids and every filter had its say.
 *
 * @param WP_Query $query Query.
 * @return void
 */
function brikpanel_order_export_prepare_legacy_query( $query ) {
	global $pagenow;
	if ( 'edit.php' !== $pagenow || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
		return;
	}
	if ( 'shop_order' !== $query->get( 'post_type' ) || ! brikpanel_order_export_is_prepare_request() ) {
		return;
	}
	// The nonce first: a forged post must not get a query without paging.
	if ( ! brikpanel_order_export_prepare_nonce_ok() ) {
		return;
	}
	$query->set( 'fields', 'ids' );
	$query->set( 'posts_per_page', -1 );
	$query->set( 'nopaging', true );
	$query->set( 'no_found_rows', true );
}
add_action( 'pre_get_posts', 'brikpanel_order_export_prepare_legacy_query', PHP_INT_MAX );

/**
 * Posts storage list: the main query ran (wp_edit_posts_query() calls wp(),
 * which ends with this action); nothing has been printed yet.
 *
 * @return void
 */
function brikpanel_order_export_prepare_legacy_send() {
	global $pagenow, $wp_query;
	if ( 'edit.php' !== $pagenow || ! is_admin() || ! brikpanel_order_export_is_prepare_request() ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection; the nonce is checked next.
	$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
	if ( 'shop_order' !== $post_type ) {
		return;
	}
	$input = brikpanel_order_export_prepare_input();
	if ( 'selected' === $input['scope'] ) {
		$ids = brikpanel_order_export_selected_ids( $input['ids'] );
	} else {
		if ( ! $wp_query instanceof WP_Query || 'ids' !== $wp_query->get( 'fields' ) ) {
			brikpanel_order_export_fail( 'list', 500 );
		}
		$ids = array_values( array_map( 'absint', (array) $wp_query->posts ) );
	}
	brikpanel_order_export_start( $ids, $input );
}
add_action( 'wp', 'brikpanel_order_export_prepare_legacy_send', 0 );

/**
 * The ticked ids that are orders, in the order they were ticked.
 *
 * @param int[] $ids Ids from the page.
 * @return int[]
 */
function brikpanel_order_export_selected_ids( array $ids ) {
	if ( empty( $ids ) ) {
		return [];
	}
	global $wpdb;
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$hpos         = class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	if ( $hpos ) {
		$table = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name from WooCommerce, one %d per id.
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE type = 'shop_order' AND id IN ($placeholders)", $ids ) );
	} else {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %d per id.
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND ID IN ($placeholders)", $ids ) );
	}
	$found = array_flip( array_map( 'absint', (array) $found ) );
	return array_values( array_filter( $ids, static function ( $id ) use ( $found ) {
		return isset( $found[ $id ] );
	} ) );
}

/**
 * Create the job: the id list, the files with their header rows, the record
 * of where the export got to. Answers with the job and stops.
 *
 * @param int[] $ids   Orders to export, in order.
 * @param array $input Checked start fields.
 * @return void
 */
function brikpanel_order_export_start( array $ids, array $input ) {
	if ( empty( $ids ) ) {
		brikpanel_order_export_fail( 'empty' );
	}
	$dir = function_exists( 'brikpanel_export_dir' ) ? brikpanel_export_dir() : false;
	if ( ! $dir ) {
		brikpanel_order_export_fail( 'disk', 500 );
	}
	brikpanel_export_sweep();

	$token = bin2hex( random_bytes( 16 ) );
	$files = brikpanel_order_export_files( $dir, $token, $input['format'] );
	$cols  = brikpanel_order_export_columns();

	$ok = false !== file_put_contents( $files['ids'], wp_json_encode( array_values( $ids ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	if ( 'csv' === $input['format'] ) {
		$handle = $ok ? fopen( $files['orders'], 'wb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $handle ) {
			// UTF-8 BOM so Excel opens non-Latin text cleanly, then the header row.
			fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fputcsv( $handle, wp_list_pluck( $cols['orders'], 'label' ) );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		} else {
			$ok = false;
		}
	} elseif ( $ok ) {
		require_once BRIKPANEL_PATH . 'includes/brikpanel-xlsx-writer.php';
		foreach ( [ 'orders', 'items' ] as $sheet ) {
			$head = Brikpanel_XLSX_Writer::sheet_head_xml( wp_list_pluck( $cols[ $sheet ], 'width' ), true );
			$row  = [];
			foreach ( $cols[ $sheet ] as $col ) {
				$row[] = [ $col['label'], Brikpanel_XLSX_Writer::S_HEADER ];
			}
			$head .= Brikpanel_XLSX_Writer::row_xml( $row, 1 );
			if ( false === file_put_contents( $files[ $sheet ], $head ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$ok = false;
				break;
			}
		}
	}
	if ( ! $ok ) {
		brikpanel_order_export_delete_files( $dir, $token );
		brikpanel_order_export_fail( 'disk', 500 );
	}

	clearstatcache();
	$job = [
		'user'    => get_current_user_id(),
		'created' => time(),
		'format'  => $input['format'],
		'total'   => count( $ids ),
		'done'    => 0,
		'rows'    => [ 'orders' => 1, 'items' => 1 ],
		'bytes'   => [
			'orders' => (int) filesize( $files['orders'] ),
			'items'  => 'xlsx' === $input['format'] ? (int) filesize( $files['items'] ) : 0,
		],
		'capped'  => [],
		'ready'   => false,
	];
	set_transient( BRIKPANEL_ORDER_EXPORT_JOB . $token, $job, BRIKPANEL_EXPORT_FILE_MAX_AGE );
	brikpanel_export_schedule_sweep();

	wp_send_json_success( [
		'job'   => $token,
		'total' => $job['total'],
	] );
}

/**
 * The job's file paths.
 *
 * @param string $dir    Export folder.
 * @param string $token  Job token.
 * @param string $format xlsx | csv.
 * @return array<string, string>
 */
function brikpanel_order_export_files( $dir, $token, $format ) {
	if ( 'csv' === $format ) {
		return [
			'ids'    => $dir . '/' . $token . '-ids.json',
			'orders' => $dir . '/' . $token . '.csv',
			'file'   => $dir . '/' . $token . '.csv',
		];
	}
	return [
		'ids'    => $dir . '/' . $token . '-ids.json',
		'orders' => $dir . '/' . $token . '-orders.xml',
		'items'  => $dir . '/' . $token . '-items.xml',
		'file'   => $dir . '/' . $token . '.xlsx',
	];
}

/**
 * Delete every file of a job.
 *
 * @param string $dir   Export folder.
 * @param string $token Job token (32 hex characters).
 * @return void
 */
function brikpanel_order_export_delete_files( $dir, $token ) {
	if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $token ) ) {
		return;
	}
	// A listing, not glob(): the uploads path may hold glob's own wildcards.
	$names = scandir( $dir );
	foreach ( is_array( $names ) ? $names : [] as $name ) {
		if ( 0 === strpos( $name, $token ) && is_file( $dir . '/' . $name ) ) {
			wp_delete_file( $dir . '/' . $name );
		}
	}
}

// =============================================================================
// STEPS, CANCEL, DOWNLOAD
// =============================================================================

/**
 * The job a step, cancel or download names, checked; answers and stops when
 * anything is wrong.
 *
 * @param array $source $_POST or $_GET.
 * @param bool  $json   Answer with JSON (AJAX) or a WordPress error page.
 * @return array{0: string, 1: array, 2: string} Token, job, folder.
 */
function brikpanel_order_export_job_from( array $source, $json = true ) {
	$deny = static function ( $code, $status ) use ( $json ) {
		if ( $json ) {
			brikpanel_order_export_fail( $code, $status );
		}
		wp_die( esc_html__( 'This export is no longer available. Please export again.', 'brikpanel' ), '', [ 'response' => $status ] );
	};

	$nonce = isset( $source['_wpnonce'] ) && is_string( $source['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $source['_wpnonce'] ) ) : '';
	if ( '' === $nonce || false === wp_verify_nonce( $nonce, BRIKPANEL_ORDER_EXPORT_NONCE ) ) {
		$deny( 'expired', 403 );
	}
	if ( ! brikpanel_order_export_enabled() ) {
		$deny( 'denied', 403 );
	}
	$token = isset( $source['job'] ) && is_string( $source['job'] ) ? sanitize_key( wp_unslash( $source['job'] ) ) : '';
	if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
		$deny( 'bad', 400 );
	}
	$job = get_transient( BRIKPANEL_ORDER_EXPORT_JOB . $token );
	if ( ! is_array( $job ) || empty( $job['format'] ) ) {
		$deny( 'gone', 410 );
	}
	if ( (int) $job['user'] !== get_current_user_id() ) {
		$deny( 'denied', 403 );
	}
	$dir = function_exists( 'brikpanel_export_dir' ) ? brikpanel_export_dir( false ) : false;
	if ( ! $dir ) {
		$deny( 'gone', 410 );
	}
	return [ $token, $job, $dir ];
}

/**
 * One step: about four seconds of orders written to the files.
 *
 * @return void
 */
function brikpanel_order_export_ajax_step() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in brikpanel_order_export_job_from().
	list( $token, $job, $dir ) = brikpanel_order_export_job_from( $_POST );
	$files = brikpanel_order_export_files( $dir, $token, $job['format'] );

	if ( empty( $job['ready'] ) ) {
		$started = microtime( true );
		if ( $job['done'] < $job['total'] ) {
			$job = brikpanel_order_export_write( $token, $job, $files, $started );
			if ( ! empty( $job['cancelled'] ) ) {
				brikpanel_order_export_delete_files( $dir, $token );
				brikpanel_order_export_fail( 'gone', 410 );
			}
		}
		// The workbook is put together in a step of its own when this one has
		// used its time, so no request does both a full step and the zipping.
		if ( $job['done'] >= $job['total'] && microtime( true ) - $started < BRIKPANEL_ORDER_EXPORT_BUDGET / 2 ) {
			$job = brikpanel_order_export_finish( $token, $job, $files );
		}
	}

	wp_send_json_success( [
		'done'   => (int) $job['done'],
		'total'  => (int) $job['total'],
		'ready'  => ! empty( $job['ready'] ),
		'capped' => ! empty( $job['capped'] ),
	] );
}
add_action( 'wp_ajax_brikpanel_order_export_step', 'brikpanel_order_export_ajax_step' );

/**
 * Write orders until the time is up or the list is done.
 *
 * @param string $token   Job token.
 * @param array  $job     Job record.
 * @param array  $files   Job files.
 * @param float  $started microtime() of the request's start.
 * @return array Updated job.
 */
function brikpanel_order_export_write( $token, array $job, array $files, $started ) {
	$ids = json_decode( (string) file_get_contents( $files['ids'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! is_array( $ids ) ) {
		brikpanel_order_export_fail( 'gone', 410 );
	}
	$xlsx  = 'xlsx' === $job['format'];
	$parts = $xlsx ? [ 'orders', 'items' ] : [ 'orders' ];

	// Cut each file back to the last saved length: a step that died after
	// writing but before saving would otherwise repeat its rows.
	$handles = [];
	foreach ( $parts as $part ) {
		$handle = fopen( $files[ $part ], 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			brikpanel_order_export_fail( 'disk', 500 );
		}
		ftruncate( $handle, (int) $job['bytes'][ $part ] );
		fseek( $handle, (int) $job['bytes'][ $part ] );
		$handles[ $part ] = $handle;
	}
	if ( $xlsx ) {
		require_once BRIKPANEL_PATH . 'includes/brikpanel-xlsx-writer.php';
	}
	$cols = brikpanel_order_export_columns();

	while ( $job['done'] < $job['total'] ) {
		$slice  = array_slice( $ids, (int) $job['done'], BRIKPANEL_ORDER_EXPORT_GROUP );
		$orders = brikpanel_order_export_load( $slice );
		foreach ( $slice as $id ) {
			$order = isset( $orders[ $id ] ) ? $orders[ $id ] : null;
			if ( $order instanceof WC_Order ) {
				$job = brikpanel_order_export_write_order( $order, $job, $handles, $cols, $xlsx );
			}
			$job['done']++;
		}
		foreach ( $handles as $part => $handle ) {
			fflush( $handle );
			$job['bytes'][ $part ] = (int) ftell( $handle );
		}
		// Cancelled while this step ran: the record must not come back.
		if ( false === get_transient( BRIKPANEL_ORDER_EXPORT_JOB . $token ) ) {
			$job['cancelled'] = true;
			break;
		}
		set_transient( BRIKPANEL_ORDER_EXPORT_JOB . $token, $job, BRIKPANEL_EXPORT_FILE_MAX_AGE );
		if ( microtime( true ) - $started >= BRIKPANEL_ORDER_EXPORT_BUDGET ) {
			break;
		}
	}

	foreach ( $handles as $handle ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
	return $job;
}

/**
 * Orders by id for one group, with their items, refunds and products read in
 * a few queries instead of a few per order.
 *
 * @param int[] $ids Order ids.
 * @return array<int, WC_Order>
 */
function brikpanel_order_export_load( array $ids ) {
	if ( empty( $ids ) ) {
		return [];
	}
	if ( class_exists( 'WC_Data_Store' ) ) {
		try {
			$store = WC_Data_Store::load( 'order' );
			if ( is_callable( [ $store, 'prime_caches_for_orders' ] ) ) {
				$store->prime_caches_for_orders( $ids, [ 'type' => 'shop_order' ] );
			}
		} catch ( Throwable $e ) {
			// Priming only saves queries; every order is still read below.
			unset( $e );
		}
	}
	// Every registered status: the trash and drafts are lists too.
	$found = wc_get_orders( [
		'type'     => 'shop_order',
		'post__in' => $ids,
		'status'   => array_keys( get_post_stati() ),
		'limit'    => count( $ids ),
		'orderby'  => 'none',
	] );
	$orders = [];
	foreach ( is_array( $found ) ? $found : [] as $order ) {
		if ( $order instanceof WC_Order ) {
			$orders[ $order->get_id() ] = $order;
		}
	}
	// An order with a status nobody registers any more still exports.
	foreach ( $ids as $id ) {
		if ( ! isset( $orders[ $id ] ) ) {
			$order = wc_get_order( $id );
			if ( $order instanceof WC_Order ) {
				$orders[ $id ] = $order;
			}
		}
	}

	$product_ids = [];
	foreach ( $orders as $order ) {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( is_callable( [ $item, 'get_product_id' ] ) ) {
				$product_ids[] = (int) $item->get_product_id();
				$product_ids[] = (int) $item->get_variation_id();
			}
		}
	}
	$product_ids = array_values( array_unique( array_filter( $product_ids ) ) );
	if ( $product_ids && function_exists( '_prime_post_caches' ) ) {
		_prime_post_caches( $product_ids, true, true );
	}
	return $orders;
}

/**
 * Write one order's row (and in Excel its item rows).
 *
 * @param WC_Order $order   Order.
 * @param array    $job     Job record.
 * @param array    $handles Open files by part.
 * @param array    $cols    brikpanel_order_export_columns().
 * @param bool     $xlsx    Excel or CSV.
 * @return array Updated job.
 */
function brikpanel_order_export_write_order( $order, array $job, array $handles, array $cols, $xlsx ) {
	$values = brikpanel_order_export_order_values( $order );
	if ( ! $xlsx ) {
		fputcsv( $handles['orders'], brikpanel_csv_safe_row( brikpanel_order_export_csv_row( $cols['orders'], $values ) ) );
		return $job;
	}

	// One row short of the limit: the last row says the sheet was cut.
	$limit = BRIKPANEL_ORDER_EXPORT_SHEET_ROWS - 1;
	if ( $job['rows']['orders'] < $limit ) {
		$job['rows']['orders']++;
		fwrite( $handles['orders'], Brikpanel_XLSX_Writer::row_xml( brikpanel_order_export_xlsx_row( $cols['orders'], $values ), $job['rows']['orders'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	} else {
		$job['capped']['orders'] = true;
	}
	foreach ( brikpanel_order_export_item_values( $order, $values ) as $item_values ) {
		if ( $job['rows']['items'] >= $limit ) {
			$job['capped']['items'] = true;
			break;
		}
		$job['rows']['items']++;
		fwrite( $handles['items'], Brikpanel_XLSX_Writer::row_xml( brikpanel_order_export_xlsx_row( $cols['items'], $item_values ), $job['rows']['items'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}
	return $job;
}

/**
 * Close the files and, for Excel, put the workbook together.
 *
 * @param string $token Job token.
 * @param array  $job   Job record.
 * @param array  $files Job files.
 * @return array Updated job.
 */
function brikpanel_order_export_finish( $token, array $job, array $files ) {
	if ( 'xlsx' === $job['format'] ) {
		require_once BRIKPANEL_PATH . 'includes/brikpanel-xlsx-writer.php';
		$note = brikpanel_order_export_capped_note();
		foreach ( [ 'orders', 'items' ] as $part ) {
			$tail = '';
			if ( ! empty( $job['capped'][ $part ] ) ) {
				$tail .= Brikpanel_XLSX_Writer::row_xml( [ $note ], $job['rows'][ $part ] + 1 );
			}
			$tail .= Brikpanel_XLSX_Writer::sheet_tail_xml();
			$handle = fopen( $files[ $part ], 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $handle ) {
				brikpanel_order_export_fail( 'disk', 500 );
			}
			ftruncate( $handle, (int) $job['bytes'][ $part ] );
			fseek( $handle, (int) $job['bytes'][ $part ] );
			fwrite( $handle, $tail ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$writer = new Brikpanel_XLSX_Writer();
		$writer->add_sheet_file( __( 'Orders', 'brikpanel' ), $files['orders'] );
		$writer->add_sheet_file( __( 'Products', 'brikpanel' ), $files['items'] );
		if ( ! $writer->build_to_file( $files['file'] ) ) {
			brikpanel_order_export_fail( 'disk', 500 );
		}
		wp_delete_file( $files['orders'] );
		wp_delete_file( $files['items'] );
	}
	wp_delete_file( $files['ids'] );

	$job['ready'] = true;
	set_transient( BRIKPANEL_ORDER_EXPORT_JOB . $token, $job, BRIKPANEL_EXPORT_FILE_MAX_AGE );
	return $job;
}

/**
 * Cancel: the job and its files go.
 *
 * @return void
 */
function brikpanel_order_export_ajax_cancel() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in brikpanel_order_export_job_from().
	list( $token, , $dir ) = brikpanel_order_export_job_from( $_POST );
	brikpanel_order_export_delete_files( $dir, $token );
	delete_transient( BRIKPANEL_ORDER_EXPORT_JOB . $token );
	wp_send_json_success();
}
add_action( 'wp_ajax_brikpanel_order_export_cancel', 'brikpanel_order_export_ajax_cancel' );

/**
 * Send the finished file, then delete it.
 *
 * @return void
 */
function brikpanel_order_export_download() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in brikpanel_order_export_job_from().
	list( $token, $job, $dir ) = brikpanel_order_export_job_from( $_GET, false );
	$files = brikpanel_order_export_files( $dir, $token, $job['format'] );
	if ( empty( $job['ready'] ) || ! is_file( $files['file'] ) ) {
		wp_die( esc_html__( 'This export is no longer available. Please export again.', 'brikpanel' ), '', [ 'response' => 410 ] );
	}

	$ext   = 'xlsx' === $job['format'] ? 'xlsx' : 'csv';
	$stamp = wp_date( 'Y-m-d-Hi' );
	/* translators: File name of the orders export, before the date: lowercase, no spaces. */
	$base  = sanitize_file_name( _x( 'orders', 'export file name', 'brikpanel' ) );
	$ascii = sanitize_file_name( remove_accents( $base ) );
	if ( '' === $ascii || preg_match( '/[^\x20-\x7E]/', $ascii ) ) {
		$ascii = 'orders';
	}
	if ( '' === $base ) {
		$base = $ascii;
	}

	// Bounded: a buffer WordPress cannot close would loop forever.
	for ( $i = ob_get_level(); $i > 0; $i-- ) {
		if ( ! @ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			break;
		}
	}
	nocache_headers();
	header( 'Content-Type: ' . ( 'xlsx' === $ext ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8' ) );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Content-Disposition: attachment; filename="' . $ascii . '-' . $stamp . '.' . $ext . '"; filename*=UTF-8\'\'' . rawurlencode( $base . '-' . $stamp . '.' . $ext ) );
	header( 'Content-Length: ' . (int) filesize( $files['file'] ) );
	readfile( $files['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile

	brikpanel_order_export_delete_files( $dir, $token );
	delete_transient( BRIKPANEL_ORDER_EXPORT_JOB . $token );
	exit;
}
add_action( 'admin_post_brikpanel_order_export_download', 'brikpanel_order_export_download' );

// =============================================================================
// ROWS
// =============================================================================

/**
 * The columns of both sheets: key, header, kind and Excel width. CSV has the
 * orders sheet only.
 *
 * Kinds: text, money (rounded to the store's decimals), number, date (the
 * store's own clock; the header names the time zone). Weights are numbers in
 * the store's unit, which their header names.
 *
 * @return array{orders: array, items: array}
 */
function brikpanel_order_export_columns() {
	static $cols = null;
	if ( null !== $cols ) {
		return $cols;
	}
	/* translators: %s: the store's timezone, for example Europe/Istanbul. */
	$date   = sprintf( __( 'Date (%s)', 'brikpanel' ), wp_timezone_string() );
	$weight = brikpanel_safe_sprintf(
		/* translators: Column header: the weight of all items in an order, or of one product line. %s: the store's unit of weight (kg, g, lbs or oz). */
		__( 'Total weight (%s)', 'brikpanel' ),
		function_exists( 'brikpanel_order_weight_unit_label' ) ? brikpanel_order_weight_unit_label() : (string) get_option( 'woocommerce_weight_unit', 'kg' )
	);
	$col    = static function ( $key, $label, $kind, $width ) {
		return [
			'key'   => $key,
			'label' => $label,
			'kind'  => $kind,
			'width' => $width,
		];
	};
	$cols = [
		'orders' => [
			$col( 'number', __( 'Order', 'brikpanel' ), 'text', 12 ),
			$col( 'date', $date, 'date', 18 ),
			$col( 'status', __( 'Status', 'brikpanel' ), 'text', 14 ),
			$col( 'customer', __( 'Customer', 'brikpanel' ), 'text', 22 ),
			$col( 'company', __( 'Company', 'brikpanel' ), 'text', 18 ),
			$col( 'email', __( 'Email', 'brikpanel' ), 'text', 28 ),
			$col( 'phone', __( 'Phone', 'brikpanel' ), 'text', 16 ),
			$col( 'billing_address', __( 'Billing address', 'brikpanel' ), 'text', 32 ),
			$col( 'billing_city', __( 'Billing city', 'brikpanel' ), 'text', 16 ),
			$col( 'billing_state', __( 'Billing state', 'brikpanel' ), 'text', 16 ),
			$col( 'billing_postcode', __( 'Billing postcode', 'brikpanel' ), 'text', 11 ),
			$col( 'billing_country', __( 'Billing country', 'brikpanel' ), 'text', 16 ),
			$col( 'shipping_name', __( 'Shipping name', 'brikpanel' ), 'text', 22 ),
			$col( 'shipping_address', __( 'Shipping address', 'brikpanel' ), 'text', 32 ),
			$col( 'shipping_city', __( 'Shipping city', 'brikpanel' ), 'text', 16 ),
			$col( 'shipping_state', __( 'Shipping state', 'brikpanel' ), 'text', 16 ),
			$col( 'shipping_postcode', __( 'Shipping postcode', 'brikpanel' ), 'text', 11 ),
			$col( 'shipping_country', __( 'Shipping country', 'brikpanel' ), 'text', 16 ),
			$col( 'shipping_phone', __( 'Shipping phone', 'brikpanel' ), 'text', 16 ),
			$col( 'products', __( 'Products', 'brikpanel' ), 'text', 48 ),
			$col( 'quantity', _x( 'Items', 'column header: how many items an order has', 'brikpanel' ), 'number', 7 ),
			$col( 'weight', $weight, 'number', 14 ),
			$col( 'subtotal', __( 'Subtotal', 'brikpanel' ), 'money', 12 ),
			$col( 'discount', __( 'Discount', 'brikpanel' ), 'money', 11 ),
			$col( 'shipping', __( 'Shipping', 'brikpanel' ), 'money', 11 ),
			$col( 'fees', __( 'Fees', 'brikpanel' ), 'money', 10 ),
			$col( 'tax', __( 'Tax', 'brikpanel' ), 'money', 10 ),
			$col( 'total', __( 'Total', 'brikpanel' ), 'money', 12 ),
			$col( 'refunded', __( 'Refunded', 'brikpanel' ), 'money', 11 ),
			$col( 'currency', __( 'Currency', 'brikpanel' ), 'text', 9 ),
			$col( 'payment_method', __( 'Payment method', 'brikpanel' ), 'text', 20 ),
			$col( 'shipping_method', __( 'Shipping method', 'brikpanel' ), 'text', 20 ),
			$col( 'coupons', __( 'Coupons', 'brikpanel' ), 'text', 16 ),
			$col( 'note', __( 'Customer note', 'brikpanel' ), 'text', 32 ),
		],
		'items'  => [
			$col( 'number', __( 'Order', 'brikpanel' ), 'text', 12 ),
			$col( 'date', $date, 'date', 18 ),
			$col( 'status', __( 'Status', 'brikpanel' ), 'text', 14 ),
			$col( 'customer', __( 'Customer', 'brikpanel' ), 'text', 22 ),
			$col( 'product', __( 'Product', 'brikpanel' ), 'text', 36 ),
			$col( 'variation', __( 'Variation', 'brikpanel' ), 'text', 26 ),
			$col( 'sku', __( 'SKU', 'brikpanel' ), 'text', 16 ),
			$col( 'quantity', __( 'Quantity', 'brikpanel' ), 'number', 9 ),
			$col( 'weight', $weight, 'number', 14 ),
			$col( 'unit_price', __( 'Unit price', 'brikpanel' ), 'money', 12 ),
			$col( 'total', __( 'Total', 'brikpanel' ), 'money', 12 ),
			$col( 'tax', __( 'Tax', 'brikpanel' ), 'money', 10 ),
		],
	];
	return $cols;
}

/**
 * Plain text from a value that may carry tags or entities.
 *
 * @param mixed $text Text.
 * @return string
 */
function brikpanel_order_export_plain( $text ) {
	return trim( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
}

/**
 * A country's name in the admin's language, or its code when WooCommerce
 * does not know it.
 *
 * @param string $code Country code.
 * @return string
 */
function brikpanel_order_export_country( $code ) {
	static $names = null;
	$code = (string) $code;
	if ( '' === $code ) {
		return '';
	}
	if ( null === $names ) {
		$names = function_exists( 'WC' ) && WC()->countries ? (array) WC()->countries->get_countries() : [];
	}
	return isset( $names[ $code ] ) ? brikpanel_order_export_plain( $names[ $code ] ) : $code;
}

/**
 * A state's name ("İstanbul" for TR34), or what the order stored.
 *
 * @param string $country Country code.
 * @param string $state   State code or name.
 * @return string
 */
function brikpanel_order_export_state( $country, $state ) {
	static $states = [];
	$country = (string) $country;
	$state   = (string) $state;
	if ( '' === $state || '' === $country ) {
		return $state;
	}
	if ( ! isset( $states[ $country ] ) ) {
		$list               = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_states( $country ) : [];
		$states[ $country ] = is_array( $list ) ? $list : [];
	}
	return isset( $states[ $country ][ $state ] ) ? brikpanel_order_export_plain( $states[ $country ][ $state ] ) : $state;
}

/**
 * A status's name, worked out once per status: wc_get_order_status_name()
 * builds and translates the whole status list on every call.
 *
 * @param string $status Status slug.
 * @return string
 */
function brikpanel_order_export_status_name( $status ) {
	static $names = [];
	$status = (string) $status;
	if ( ! isset( $names[ $status ] ) ) {
		$names[ $status ] = brikpanel_order_export_plain( wc_get_order_status_name( $status ) );
	}
	return $names[ $status ];
}

/**
 * The name and options of each item line, worked out once per order: both
 * sheets use them, and reading an item's options is the slow part of a row.
 *
 * @param WC_Order $order Order.
 * @return array[] [ 'item' => WC_Order_Item_Product, 'name' => string, 'options' => string[] ]
 */
function brikpanel_order_export_lines( $order ) {
	$lines = [];
	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$parts   = function_exists( 'brikpanel_orders_compact_item_parts' )
			? brikpanel_orders_compact_item_parts( $item )
			: [ 'name' => brikpanel_order_export_plain( $item->get_name() ), 'options' => [] ];
		$lines[] = [
			'item'    => $item,
			'name'    => (string) $parts['name'],
			'options' => (array) $parts['options'],
		];
	}
	return $lines;
}

/**
 * An item line's SKU as WooCommerce shows it: the variation's own, else its
 * parent product's. Read from the product meta the group primed, not through
 * a product object: building one per line cost as much as the rest of the row.
 *
 * @param WC_Order_Item_Product $item Item line.
 * @return string
 */
function brikpanel_order_export_sku( $item ) {
	if ( ! is_callable( [ $item, 'get_product_id' ] ) ) {
		return '';
	}
	$variation_id = (int) $item->get_variation_id();
	if ( $variation_id && ! get_post_type( $variation_id ) ) {
		return ''; // The variation was deleted: WooCommerce shows no product, so no SKU.
	}
	$sku = $variation_id ? (string) get_post_meta( $variation_id, '_sku', true ) : '';
	if ( '' === $sku ) {
		$sku = (string) get_post_meta( (int) $item->get_product_id(), '_sku', true );
	}
	return $sku;
}

/**
 * Street lines of an address as one cell.
 *
 * @param string $line1 Address line 1.
 * @param string $line2 Address line 2.
 * @return string
 */
function brikpanel_order_export_street( $line1, $line2 ) {
	return implode( ', ', array_filter( [ trim( (string) $line1 ), trim( (string) $line2 ) ], 'strlen' ) );
}

/**
 * Everything one order's row says, keyed like the columns.
 *
 * @param WC_Order $order Order.
 * @return array
 */
function brikpanel_order_export_order_values( $order ) {
	$customer = function_exists( 'brikpanel_orders_compact_customer_name' )
		? brikpanel_orders_compact_customer_name( $order )
		: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

	$has_shipping   = $order->has_shipping_address();
	$shipping_name  = '';
	$shipping_phone = '';
	if ( $has_shipping ) {
		$shipping_name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		if ( '' === $shipping_name ) {
			$shipping_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		$shipping_phone = method_exists( $order, 'get_shipping_phone' ) ? (string) $order->get_shipping_phone() : '';
		if ( '' === $shipping_phone ) {
			$shipping_phone = (string) $order->get_billing_phone();
		}
	}

	static $qty_format = null;
	if ( null === $qty_format ) {
		/* translators: %s: item quantity. Shown before the product name, e.g. "2 ×". */
		$qty_format = __( '%s ×', 'brikpanel' );
	}
	$lines    = brikpanel_order_export_lines( $order );
	// The weights the order screen shows; that module loads on its own.
	$weights  = function_exists( 'brikpanel_order_weight_compute' ) ? brikpanel_order_weight_compute( $order ) : null;
	$products = [];
	foreach ( $lines as $line ) {
		$text = sprintf( $qty_format, wc_stock_amount( $line['item']->get_quantity() ) ) . ' ' . $line['name'];
		if ( $line['options'] ) {
			$text .= ' (' . implode( ' / ', $line['options'] ) . ')';
		}
		$products[] = $text;
	}

	$fees = 0.0;
	foreach ( $order->get_fees() as $fee ) {
		$fees += (float) $fee->get_total();
	}

	$coupons = method_exists( $order, 'get_coupon_codes' ) ? $order->get_coupon_codes() : $order->get_used_coupons();

	return [
		// The item lines and each weighted line's weight by line ID, for the
		// Products sheet; no column reads these keys.
		'_lines'            => $lines,
		'_weights'          => $weights ? $weights['lines'] : [],
		'number'            => (string) $order->get_order_number(),
		'date'              => $order->get_date_created(),
		'status'            => brikpanel_order_export_status_name( $order->get_status() ),
		'customer'          => $customer,
		'company'           => (string) $order->get_billing_company(),
		'email'             => (string) $order->get_billing_email(),
		'phone'             => (string) $order->get_billing_phone(),
		'billing_address'   => brikpanel_order_export_street( $order->get_billing_address_1(), $order->get_billing_address_2() ),
		'billing_city'      => (string) $order->get_billing_city(),
		'billing_state'     => brikpanel_order_export_state( $order->get_billing_country(), $order->get_billing_state() ),
		'billing_postcode'  => (string) $order->get_billing_postcode(),
		'billing_country'   => brikpanel_order_export_country( $order->get_billing_country() ),
		'shipping_name'     => $shipping_name,
		'shipping_address'  => $has_shipping ? brikpanel_order_export_street( $order->get_shipping_address_1(), $order->get_shipping_address_2() ) : '',
		'shipping_city'     => $has_shipping ? (string) $order->get_shipping_city() : '',
		'shipping_state'    => $has_shipping ? brikpanel_order_export_state( $order->get_shipping_country(), $order->get_shipping_state() ) : '',
		'shipping_postcode' => $has_shipping ? (string) $order->get_shipping_postcode() : '',
		'shipping_country'  => $has_shipping ? brikpanel_order_export_country( $order->get_shipping_country() ) : '',
		'shipping_phone'    => $shipping_phone,
		'products'          => implode( '; ', $products ),
		'quantity'          => (float) $order->get_item_count(),
		// Empty, not 0, when no product in the order has a weight.
		'weight'            => $weights && $weights['lines'] ? round( (float) $weights['total'], 3 ) : '',
		'subtotal'          => (float) $order->get_subtotal(),
		'discount'          => (float) $order->get_total_discount(),
		'shipping'          => (float) $order->get_shipping_total(),
		'fees'              => $fees,
		'tax'               => (float) $order->get_total_tax(),
		'total'             => (float) $order->get_total(),
		'refunded'          => (float) $order->get_total_refunded(),
		'currency'          => (string) $order->get_currency(),
		'payment_method'    => brikpanel_order_export_plain( $order->get_payment_method_title() ),
		'shipping_method'   => brikpanel_order_export_plain( $order->get_shipping_method() ),
		'coupons'           => implode( ', ', array_map( 'strval', (array) $coupons ) ),
		'note'              => trim( (string) $order->get_customer_note() ),
	];
}

/**
 * The item rows of one order (Excel's Products sheet), keyed like the columns.
 *
 * @param WC_Order $order  Order.
 * @param array    $values The order's own row (number, date, status, customer).
 * @return array[]
 */
function brikpanel_order_export_item_values( $order, array $values ) {
	$lines   = isset( $values['_lines'] ) ? $values['_lines'] : brikpanel_order_export_lines( $order );
	$weights = isset( $values['_weights'] ) ? (array) $values['_weights'] : [];
	$rows    = [];
	foreach ( $lines as $line ) {
		$item   = $line['item'];
		$qty    = (float) $item->get_quantity();
		$id     = (int) $item->get_id();
		$rows[] = [
			'number'     => $values['number'],
			'date'       => $values['date'],
			'status'     => $values['status'],
			'customer'   => $values['customer'],
			'product'    => $line['name'],
			'variation'  => implode( ' / ', $line['options'] ),
			'sku'        => brikpanel_order_export_sku( $item ),
			'quantity'   => $qty,
			'weight'     => isset( $weights[ $id ] ) ? round( (float) $weights[ $id ]['weight'], 3 ) : '',
			'unit_price' => $qty ? (float) $item->get_subtotal() / $qty : null,
			'total'      => (float) $item->get_total(),
			'tax'        => (float) $item->get_total_tax(),
		];
	}
	return $rows;
}

/**
 * A row for the CSV file: dates on the store's clock as Y-m-d H:i:s, amounts
 * with a point and the store's decimals, as other programs read them.
 *
 * @param array $cols   Columns.
 * @param array $values Values by key.
 * @return array
 */
function brikpanel_order_export_csv_row( array $cols, array $values ) {
	static $decimals = null;
	if ( null === $decimals ) {
		$decimals = wc_get_price_decimals();
	}
	$row = [];
	foreach ( $cols as $col ) {
		$value = isset( $values[ $col['key'] ] ) ? $values[ $col['key'] ] : '';
		switch ( $col['kind'] ) {
			case 'date':
				$row[] = $value instanceof WC_DateTime ? $value->date( 'Y-m-d H:i:s' ) : '';
				break;
			case 'money':
				$row[] = null === $value || '' === $value ? '' : wc_format_decimal( (float) $value, $decimals );
				break;
			case 'number':
				$row[] = null === $value || '' === $value ? '' : wc_format_decimal( (float) $value, false, true );
				break;
			default:
				$row[] = (string) $value;
		}
	}
	return $row;
}

/**
 * A row for the workbook: real numbers and real dates.
 *
 * @param array $cols   Columns.
 * @param array $values Values by key.
 * @return array
 */
function brikpanel_order_export_xlsx_row( array $cols, array $values ) {
	static $decimals = null;
	if ( null === $decimals ) {
		$decimals = wc_get_price_decimals();
	}
	$row = [];
	foreach ( $cols as $col ) {
		$value = isset( $values[ $col['key'] ] ) ? $values[ $col['key'] ] : '';
		switch ( $col['kind'] ) {
			case 'date':
				$row[] = $value instanceof WC_DateTime
					? [ Brikpanel_XLSX_Writer::date_serial( $value->getOffsetTimestamp() ), Brikpanel_XLSX_Writer::S_DATE ]
					: '';
				break;
			case 'money':
				$row[] = null === $value || '' === $value ? '' : round( (float) $value, $decimals );
				break;
			case 'number':
				$row[] = null === $value || '' === $value ? '' : (float) $value + 0;
				break;
			default:
				$row[] = (string) $value;
		}
	}
	return $row;
}
