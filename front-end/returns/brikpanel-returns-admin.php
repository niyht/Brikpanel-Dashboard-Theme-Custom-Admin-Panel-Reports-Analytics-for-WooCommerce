<?php
/**
 * Customer returns: the store's side.
 *
 *   Returns (menu, under Orders)  list with status tabs, search, pages; one
 *                                 return with its items, photos, customer
 *                                 and history, and the actions.
 *   Order screen                  a "Returns" box in the right column.
 *   Orders list                   a badge next to the order number while a
 *                                 return is open.
 *   Top bar "Needs attention"     the number of requests waiting.
 *
 * Every action posts to admin-post.php with a nonce and the person's
 * edit_shop_orders capability, then comes back to the return (no double send
 * on refresh). Each action names the version of the return the person saw,
 * so two people cannot act on it twice.
 *
 * Loaded only while returns are switched on, and only in wp-admin.
 *
 * @package BrikPanel
 * @since   3.3.52
 */

defined( 'ABSPATH' ) || exit;

/** Admin page slug. */
const BRIKPANEL_RETURNS_PAGE = 'brikpanel-returns';

add_action( 'admin_menu', 'brikpanel_returns_admin_menu' );
add_action( 'brikpanel_nav_store_cluster_ready', 'brikpanel_returns_nav_place' );
add_filter( 'brikpanel_shell_pages', 'brikpanel_returns_shell_page' );
add_action( 'admin_enqueue_scripts', 'brikpanel_returns_admin_assets' );
add_action( 'admin_post_brikpanel_return_admin', 'brikpanel_returns_admin_post' );
add_action( 'add_meta_boxes', 'brikpanel_returns_order_metabox', 42, 2 );
add_filter( 'brikpanel_order_sidebar_boxes', 'brikpanel_returns_order_sidebar_box' );
add_action( 'brikpanel_topbar_attention_rows', 'brikpanel_returns_attention_row' );
if ( 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ) ) {
	add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'brikpanel_returns_orders_badge', 25, 2 );
} else {
	add_action( 'manage_shop_order_posts_custom_column', 'brikpanel_returns_orders_badge_legacy', 25, 2 );
}

// =============================================================================
// ACCESS
// =============================================================================

/**
 * Whether the person may handle returns: the capability WooCommerce's own
 * order screens ask for.
 *
 * @return bool
 */
function brikpanel_returns_user_can() {
	return current_user_can( 'edit_shop_orders' );
}

/**
 * Whether BrikPanel may add returns to WooCommerce's own screens for this
 * person (the master switch can be off for them).
 *
 * @return bool
 */
function brikpanel_returns_active() {
	if ( function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize() ) {
		return false;
	}
	return brikpanel_returns_user_can();
}

/**
 * Address of the Returns screen.
 *
 * @param array $args Query arguments.
 * @return string
 */
function brikpanel_returns_admin_url( array $args = array() ) {
	return add_query_arg( $args, admin_url( 'admin.php?page=' . BRIKPANEL_RETURNS_PAGE ) );
}

// =============================================================================
// MENU
// =============================================================================

/**
 * The menu row, with the number of requests waiting.
 *
 * @return void
 */
function brikpanel_returns_admin_menu() {
	$waiting = Brikpanel_Returns_Store::waiting_count();
	$label   = __( 'Returns', 'brikpanel' );
	if ( $waiting > 0 ) {
		$label .= ' <span class="menu-counter count-' . (int) $waiting . '"><span class="processing-count">' . esc_html( brikpanel_returns_number( $waiting ) ) . '</span></span>';
	}
	$hook = add_menu_page(
		__( 'Returns', 'brikpanel' ),
		$label,
		'edit_shop_orders',
		BRIKPANEL_RETURNS_PAGE,
		'brikpanel_returns_render_page',
		'dashicons-undo',
		// Right after WooCommerce (55.5) and before Customers (55.6) with the
		// classic sidebar; the modern sidebar pins it under Orders.
		55.55
	);
	if ( $hook ) {
		add_action( 'load-' . $hook, 'brikpanel_returns_on_load' );
	}
}

/**
 * Modern sidebar: directly under Orders.
 *
 * @param array $menu $menu-shaped rows, by reference.
 * @return void
 */
function brikpanel_returns_nav_place( &$menu ) {
	if ( function_exists( 'brikpanel_nav_move_after' ) && is_array( $menu ) ) {
		$menu = brikpanel_nav_move_after( $menu, BRIKPANEL_RETURNS_PAGE, 'woocommerce' );
	}
}

/**
 * @param array $pages Page slug => wide|narrow.
 * @return array
 */
function brikpanel_returns_shell_page( $pages ) {
	$pages[ BRIKPANEL_RETURNS_PAGE ] = 'wide';
	return $pages;
}

/**
 * Page title, and repair of a refund that died half way.
 *
 * @return void
 */
function brikpanel_returns_on_load() {
	$GLOBALS['title'] = __( 'Returns', 'brikpanel' );
	$id               = isset( $_GET['return'] ) ? absint( $_GET['return'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen choice.
	if ( $id ) {
		/* translators: %s: return number */
		$GLOBALS['title'] = sprintf( __( 'Return #%s', 'brikpanel' ), $id );
	}
	brikpanel_returns_repair_stuck();
}

// =============================================================================
// ASSETS
// =============================================================================

/**
 * @param string $hook Screen hook.
 * @return void
 */
function brikpanel_returns_admin_assets( $hook ) {
	if ( 'toplevel_page_' . BRIKPANEL_RETURNS_PAGE !== $hook ) {
		return;
	}
	$dir = BRIKPANEL_PATH . 'front-end/returns/';
	$url = BRIKPANEL_URL . 'front-end/returns/';

	$style_deps  = array();
	$script_deps = array();
	if ( function_exists( 'brikpanel_fit_table_dep' ) ) {
		$style_deps  = array_merge( $style_deps, brikpanel_fit_table_dep( 'style' ) );
		$script_deps = array_merge( $script_deps, brikpanel_fit_table_dep() );
	}
	if ( function_exists( 'brikpanel_fit_row_dep' ) ) {
		$script_deps = array_merge( $script_deps, brikpanel_fit_row_dep() );
	}
	if ( function_exists( 'brikpanel_narrow_deps' ) ) {
		$style_deps  = array_merge( $style_deps, brikpanel_narrow_deps( array( 'scroll_strip', 'overflow', 'ui', 'sheet' ), 'style' ) );
		$script_deps = array_merge( $script_deps, brikpanel_narrow_deps( array( 'scroll_strip', 'overflow', 'format', 'sheet' ) ) );
	}

	wp_enqueue_style( 'brikpanel-returns-admin', $url . 'brikpanel-returns-admin.css', array_values( array_unique( $style_deps ) ), (string) filemtime( $dir . 'brikpanel-returns-admin.css' ) );
	wp_enqueue_script( 'brikpanel-returns-admin', $url . 'brikpanel-returns-admin.js', array_values( array_unique( $script_deps ) ), (string) filemtime( $dir . 'brikpanel-returns-admin.js' ), true );
	wp_localize_script(
		'brikpanel-returns-admin',
		'brikpanelReturnsAdmin',
		array(
			'decimals' => wc_get_price_decimals(),
			'i18n'     => array(
				'close'    => __( 'Close', 'brikpanel' ),
				'working'  => __( 'Working…', 'brikpanel' ),
				'tooMuch'  => __( 'The deduction is larger than the amount.', 'brikpanel' ),
			),
		)
	);
}

// =============================================================================
// SCREENS
// =============================================================================

/**
 * The Returns screen: the list, or one return.
 *
 * @return void
 */
function brikpanel_returns_render_page() {
	if ( ! brikpanel_returns_user_can() ) {
		wp_die( esc_html__( 'You are not allowed to see returns.', 'brikpanel' ), '', array( 'response' => 403 ) );
	}
	$id = isset( $_GET['return'] ) ? absint( $_GET['return'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen choice.
	if ( $id ) {
		brikpanel_returns_render_detail( $id );
		return;
	}
	brikpanel_returns_render_list();
}

/**
 * The person's message from the last action (one request long).
 *
 * @return array{tone:string,text:string}|null
 */
function brikpanel_returns_take_flash() {
	$key   = 'brikpanel_returns_flash_' . get_current_user_id();
	$flash = get_transient( $key );
	if ( is_array( $flash ) ) {
		delete_transient( $key );
		return $flash;
	}
	return null;
}

/**
 * @param string $tone success|error.
 * @param string $text Message.
 * @return void
 */
function brikpanel_returns_set_flash( $tone, $text ) {
	set_transient(
		'brikpanel_returns_flash_' . get_current_user_id(),
		array(
			'tone' => 'error' === $tone ? 'error' : 'success',
			'text' => (string) $text,
		),
		5 * MINUTE_IN_SECONDS
	);
}

/**
 * @return void
 */
function brikpanel_returns_print_flash() {
	$flash = brikpanel_returns_take_flash();
	if ( ! $flash ) {
		return;
	}
	echo '<div class="bp-ret-flash is-' . esc_attr( $flash['tone'] ) . '" role="' . ( 'error' === $flash['tone'] ? 'alert' : 'status' ) . '">' . esc_html( $flash['text'] ) . '</div>';
}

/**
 * Tabs of the list: key => [label, statuses counted].
 *
 * @return array<string,array{label:string,count:int}>
 */
function brikpanel_returns_tabs() {
	$c = Brikpanel_Returns_Store::counts();
	$n = static function ( $key ) use ( $c ) {
		return isset( $c[ $key ] ) ? (int) $c[ $key ] : 0;
	};
	return array(
		'requested' => array(
			'label' => _x( 'Requested', 'return status', 'brikpanel' ),
			'count' => $n( 'requested' ),
		),
		'approved'  => array(
			'label' => _x( 'Approved', 'return status', 'brikpanel' ),
			'count' => $n( 'approved' ),
		),
		'received'  => array(
			'label' => _x( 'Item received', 'return status', 'brikpanel' ),
			'count' => $n( 'received' ) + $n( 'resolving' ),
		),
		'done'      => array(
			'label' => _x( 'Refunded', 'return status', 'brikpanel' ),
			'count' => $n( 'refunded' ),
		),
		'rejected'  => array(
			'label' => _x( 'Rejected', 'return status', 'brikpanel' ),
			'count' => $n( 'rejected' ),
		),
		'all'       => array(
			'label' => __( 'All', 'brikpanel' ),
			'count' => array_sum( array_map( 'intval', $c ) ),
		),
	);
}

/**
 * Orders a search term can mean: a displayed order number, or a name.
 *
 * @param string $term Search term.
 * @return int[]
 */
function brikpanel_returns_search_orders( $term ) {
	$term = trim( (string) $term );
	if ( '' === $term ) {
		return array();
	}
	$ids = array();
	if ( function_exists( 'brikpanel_order_numbers_find_ids' ) ) {
		$values = function_exists( 'brikpanel_order_numbers_candidates' ) ? (array) brikpanel_order_numbers_candidates( ltrim( $term, '#' ) ) : array( ltrim( $term, '#' ) );
		$ids    = array_merge( $ids, array_map( 'intval', (array) brikpanel_order_numbers_find_ids( $values, 50 ) ) );
	}
	if ( ctype_digit( ltrim( $term, '#' ) ) ) {
		$ids[] = (int) ltrim( $term, '#' );
	}
	if ( function_exists( 'wc_order_search' ) && strlen( $term ) >= 3 && ! ctype_digit( ltrim( $term, '#' ) ) ) {
		$ids = array_merge( $ids, array_slice( array_map( 'intval', (array) wc_order_search( $term ) ), 0, 200 ) );
	}
	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * The list.
 *
 * @return void
 */
function brikpanel_returns_render_list() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters of a list.
	$tabs   = brikpanel_returns_tabs();
	$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = $tabs['requested']['count'] > 0 ? 'requested' : 'all';
	}
	$per    = 20;
	$status = 'all' === $tab ? '' : ( 'received' === $tab ? 'received' : $tab );
	$result = Brikpanel_Returns_Store::query(
		array(
			'status'    => $status,
			'search'    => $search,
			'order_ids' => brikpanel_returns_search_orders( $search ),
			'page'      => $page,
			'per_page'  => $per,
		)
	);
	$rows   = $result['rows'];
	$total  = $result['total'];
	$pages  = max( 1, (int) ceil( $total / $per ) );

	$fit = array(
		'title'  => 'h1',
		'lines'  => array( '' ),
		'levels' => array(
			'',
			array(
				'cls'   => 'is-two-rows',
				'lines' => array( '.bp-ret-header-right' ),
			),
		),
	);
	?>
	<div class="wrap brikpanel-shell__page">
		<div class="bp-ret" id="bp-ret">
			<div class="bp-ret-header" id="bp-ret-header" data-bp-fit-row="<?php echo esc_attr( wp_json_encode( $fit ) ); ?>">
				<?php wp_print_inline_script_tag( 'if(window.brikpanelFitRow){window.brikpanelFitRow.auto(document.getElementById("bp-ret-header"));}' ); ?>
				<div class="bp-ret-header-left">
					<h1><?php esc_html_e( 'Returns', 'brikpanel' ); ?></h1>
				</div>
				<div class="bp-ret-header-right">
					<form method="get" class="bp-ret-search" role="search" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
						<input type="hidden" name="page" value="<?php echo esc_attr( BRIKPANEL_RETURNS_PAGE ); ?>">
						<input type="hidden" name="tab" value="all">
						<label class="screen-reader-text" for="bp-ret-s"><?php esc_html_e( 'Search returns', 'brikpanel' ); ?></label>
						<input type="search" id="bp-ret-s" name="s" class="brikpanel-control brikpanel-control--quiet bp-ret-search-input" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Order number, name or email', 'brikpanel' ); ?>">
					</form>
					<?php if ( function_exists( 'brikpanel_user_can_open_settings' ) && brikpanel_user_can_open_settings() ) : ?>
						<a class="brikpanel-btn brikpanel-btn--secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=brikpanel&section=returns' ) ); ?>"><?php esc_html_e( 'Settings', 'brikpanel' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
			<?php brikpanel_header_end(); ?>
			<?php brikpanel_returns_print_flash(); ?>

			<nav class="bp-ret-tabs" role="tablist" data-bp-strip aria-label="<?php esc_attr_e( 'Returns', 'brikpanel' ); ?>">
				<?php foreach ( $tabs as $key => $info ) : ?>
					<a class="bp-ret-tab<?php echo $key === $tab ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $key === $tab ? 'true' : 'false'; ?>" href="<?php echo esc_url( brikpanel_returns_admin_url( array( 'tab' => $key ) ) ); ?>">
						<?php echo esc_html( $info['label'] ); ?>
						<span class="bp-ret-tab-count"><?php echo esc_html( brikpanel_returns_number( $info['count'] ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="bp-ret-card">
				<?php if ( ! $rows ) : ?>
					<div class="bp-ret-empty">
						<p class="bp-ret-empty-title"><?php echo esc_html( '' !== $search ? __( 'No returns match your search.', 'brikpanel' ) : __( 'No returns here yet.', 'brikpanel' ) ); ?></p>
						<?php if ( '' === $search ) : ?>
							<p><?php esc_html_e( 'Customers ask for returns from their account. Their requests show up here, and you get an email for each one.', 'brikpanel' ); ?></p>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<table class="bp-ret-table brikpanel-fit-table" id="bp-ret-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Return', 'brikpanel' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Order', 'brikpanel' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Customer', 'brikpanel' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Items', 'brikpanel' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Reason', 'brikpanel' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Requested', 'brikpanel' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'brikpanel' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $ret ) : ?>
								<?php brikpanel_returns_render_list_row( $ret ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php if ( $total > $per ) : ?>
				<nav class="bp-ret-pages" aria-label="<?php esc_attr_e( 'Pages', 'brikpanel' ); ?>">
					<span class="bp-ret-pages-info">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: first row, 2: last row, 3: total rows */
								__( '%1$s to %2$s of %3$s', 'brikpanel' ),
								brikpanel_returns_number( ( $page - 1 ) * $per + 1 ),
								brikpanel_returns_number( min( $total, $page * $per ) ),
								brikpanel_returns_number( $total )
							)
						);
						?>
					</span>
					<?php
					$base = array(
						'tab' => $tab,
						's'   => $search,
					);
					if ( $page > 1 ) {
						echo '<a class="brikpanel-btn brikpanel-btn--secondary" href="' . esc_url( brikpanel_returns_admin_url( $base + array( 'paged' => $page - 1 ) ) ) . '">' . esc_html__( 'Previous', 'brikpanel' ) . '</a>';
					}
					if ( $page < $pages ) {
						echo '<a class="brikpanel-btn brikpanel-btn--secondary" href="' . esc_url( brikpanel_returns_admin_url( $base + array( 'paged' => $page + 1 ) ) ) . '">' . esc_html__( 'Next', 'brikpanel' ) . '</a>';
					}
					?>
				</nav>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * One row of the list.
 *
 * @param array $ret Return.
 * @return void
 */
function brikpanel_returns_render_list_row( array $ret ) {
	$order = wc_get_order( $ret['order_id'] );
	$url   = brikpanel_returns_admin_url( array( 'return' => $ret['id'] ) );
	$units = 0;
	foreach ( $ret['items'] as $line ) {
		if ( empty( $line['parent'] ) ) {
			$units += (int) $line['qty'];
		}
	}
	$name = $order ? trim( $order->get_formatted_billing_full_name() ) : '';
	if ( '' === $name && ! empty( $ret['statement']['name'] ) ) {
		$name = (string) $ret['statement']['name'];
	}
	?>
	<tr>
		<td class="brikpanel-fit-lead">
			<a class="bp-ret-link" href="<?php echo esc_url( $url ); ?>">#<?php echo esc_html( (string) $ret['id'] ); ?></a>
			<?php if ( ! empty( $ret['withdrawal'] ) ) : ?>
				<span class="brikpanel-badge bp-ret-eu"><?php esc_html_e( 'EU withdrawal', 'brikpanel' ); ?></span>
			<?php endif; ?>
		</td>
		<td>
			<?php if ( $order ) : ?>
				<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
			<?php else : ?>
				<span class="bp-ret-muted">#<?php echo esc_html( (string) $ret['order_id'] ); ?></span>
			<?php endif; ?>
		</td>
		<td>
			<span class="bp-ret-cell-main"><?php echo esc_html( '' !== $name ? $name : $ret['email'] ); ?></span>
			<?php if ( '' !== $name && '' !== $ret['email'] ) : ?>
				<span class="bp-ret-cell-sub"><?php echo esc_html( $ret['email'] ); ?></span>
			<?php endif; ?>
		</td>
		<td>
			<?php
			/* translators: %s: number of items */
			echo esc_html( sprintf( _n( '%s item', '%s items', $units, 'brikpanel' ), brikpanel_returns_number( $units ) ) );
			?>
		</td>
		<td><?php echo '' !== $ret['reason'] ? esc_html( $ret['reason'] ) : '<span class="bp-ret-muted">&ndash;</span>'; ?></td>
		<td><?php echo esc_html( function_exists( 'brikpanel_local_date' ) ? brikpanel_local_date( $ret['created_at'] ) : brikpanel_returns_date( $ret['created_at'] ) ); ?></td>
		<td class="brikpanel-fit-headline"><span class="bp-ret-status is-<?php echo esc_attr( $ret['status'] ); ?>"><?php echo esc_html( brikpanel_returns_status_label( $ret['status'] ) ); ?></span></td>
	</tr>
	<?php
}

/**
 * Date of a stored UTC moment (also used by the list without the helper).
 *
 * @param string $utc DATETIME in UTC.
 * @return string
 */
function brikpanel_returns_admin_moment( $utc ) {
	if ( function_exists( 'brikpanel_local_datetime' ) ) {
		return (string) brikpanel_local_datetime( $utc );
	}
	$ts = $utc ? strtotime( $utc . ' UTC' ) : false;
	return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '';
}

/**
 * Who did a step, for the history.
 *
 * @param int $user_id User id (0 = the customer or the system).
 * @return string
 */
function brikpanel_returns_actor( $user_id ) {
	if ( $user_id > 0 ) {
		$user = get_userdata( $user_id );
		if ( $user ) {
			return $user->display_name;
		}
	}
	return '';
}

/**
 * One return.
 *
 * @param int $id Return id.
 * @return void
 */
function brikpanel_returns_render_detail( $id ) {
	$ret   = Brikpanel_Returns_Store::get( $id, true );
	$order = $ret ? wc_get_order( $ret['order_id'] ) : false;
	$back  = brikpanel_returns_admin_url();
	echo '<div class="wrap brikpanel-shell__page"><div class="bp-ret bp-ret-detail" id="bp-ret">';
	echo '<a class="bp-ret-back" href="' . esc_url( $back ) . '"><span aria-hidden="true">&larr;</span> ' . esc_html__( 'Returns', 'brikpanel' ) . '</a>';
	if ( ! $ret ) {
		echo '<h1>' . esc_html__( 'Return not found', 'brikpanel' ) . '</h1>';
		brikpanel_header_end();
		echo '<p>' . esc_html__( 'This return no longer exists.', 'brikpanel' ) . '</p></div></div>';
		return;
	}

	$status  = $ret['status'];
	$can     = array(
		'approve' => in_array( $status, brikpanel_returns_action_from( 'approve' ), true ),
		'receive' => in_array( $status, brikpanel_returns_action_from( 'receive' ), true ),
		'refund'  => $order && in_array( $status, brikpanel_returns_action_from( 'refund' ), true ),
		'reject'  => in_array( $status, brikpanel_returns_action_from( 'reject' ), true ),
	);
	$primary = $can['approve'] ? 'approve' : ( 'approved' === $status ? 'receive' : ( $can['refund'] ? 'refund' : '' ) );
	$labels  = array(
		'approve' => __( 'Approve', 'brikpanel' ),
		'receive' => __( 'Item received', 'brikpanel' ),
		'refund'  => __( 'Refund', 'brikpanel' ),
		'reject'  => __( 'Reject', 'brikpanel' ),
	);
	$title   = ! empty( $ret['withdrawal'] )
		/* translators: %s: return number */
		? sprintf( __( 'Withdrawal #%s', 'brikpanel' ), $ret['id'] )
		/* translators: %s: return number */
		: sprintf( __( 'Return #%s', 'brikpanel' ), $ret['id'] );
	$fit     = array(
		'title'  => 'h1',
		'lines'  => array( '' ),
		'levels' => array(
			'',
			'is-fold',
			array(
				'cls'   => 'is-fold is-two-rows',
				'lines' => array( '.bp-ret-header-right' ),
			),
		),
	);
	?>
	<div class="bp-ret-header" id="bp-ret-header" data-bp-fit-row="<?php echo esc_attr( wp_json_encode( $fit ) ); ?>">
		<?php wp_print_inline_script_tag( 'if(window.brikpanelFitRow){window.brikpanelFitRow.auto(document.getElementById("bp-ret-header"));}' ); ?>
		<div class="bp-ret-header-left">
			<h1><?php echo esc_html( $title ); ?></h1>
			<span class="bp-ret-status is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( brikpanel_returns_status_label( $status ) ); ?></span>
		</div>
		<div class="bp-ret-header-right">
			<?php
			$secondary = array();
			foreach ( array( 'receive', 'refund', 'reject' ) as $act ) {
				if ( $can[ $act ] && $act !== $primary ) {
					$secondary[] = $act;
				}
			}
			if ( $secondary && function_exists( 'brikpanel_overflow_trigger' ) ) :
				?>
				<div class="brikpanel-overflow">
					<?php brikpanel_overflow_trigger( 'bp-ret-more-menu' ); ?>
					<div class="brikpanel-overflow__menu" id="bp-ret-more-menu">
						<?php foreach ( $secondary as $act ) : ?>
							<button type="button" class="brikpanel-btn brikpanel-btn--secondary" data-bp-ret-open="bp-ret-dialog-<?php echo esc_attr( $act ); ?>"><?php echo esc_html( $labels[ $act ] ); ?></button>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
			<?php if ( '' !== $primary ) : ?>
				<button type="button" class="brikpanel-btn brikpanel-btn--primary" data-bp-ret-open="bp-ret-dialog-<?php echo esc_attr( $primary ); ?>"><?php echo esc_html( $labels[ $primary ] ); ?></button>
			<?php endif; ?>
		</div>
	</div>
	<?php brikpanel_header_end(); ?>
	<?php brikpanel_returns_print_flash(); ?>

	<?php if ( 'resolving' === $status ) : ?>
		<div class="bp-ret-flash is-error" role="status"><?php esc_html_e( 'A refund for this return is in progress. Reload the page in a minute.', 'brikpanel' ); ?></div>
	<?php endif; ?>

	<div class="bp-ret-cols">
		<div class="bp-ret-main">
			<?php brikpanel_returns_card_items( $ret, $order ); ?>
			<?php brikpanel_returns_card_reason( $ret ); ?>
			<?php brikpanel_returns_card_photos( $ret ); ?>
			<?php brikpanel_returns_card_statement( $ret ); ?>
		</div>
		<div class="bp-ret-side">
			<?php brikpanel_returns_card_customer( $ret, $order ); ?>
			<?php brikpanel_returns_card_history( $ret ); ?>
		</div>
	</div>

	<?php
	if ( $can['approve'] ) {
		brikpanel_returns_dialog_message( $ret, 'approve', __( 'Approve the return', 'brikpanel' ), __( 'Message to the customer (optional)', 'brikpanel' ), __( 'For example where to send the items and how to pack them.', 'brikpanel' ), false, __( 'Approve and email the customer', 'brikpanel' ) );
	}
	if ( $can['reject'] ) {
		brikpanel_returns_dialog_message( $ret, 'reject', __( 'Reject the return', 'brikpanel' ), __( 'Reason for the customer', 'brikpanel' ), __( 'The customer gets this text by email.', 'brikpanel' ), true, __( 'Reject and email the customer', 'brikpanel' ) );
	}
	if ( $can['receive'] ) {
		brikpanel_returns_dialog_receive( $ret );
	}
	if ( $can['refund'] && $order ) {
		brikpanel_returns_dialog_refund( $ret, $order );
	}
	brikpanel_returns_photo_dialog();
	echo '</div></div>';
}

/**
 * Items card.
 *
 * @param array          $ret   Return.
 * @param WC_Order|false $order Order.
 * @return void
 */
function brikpanel_returns_card_items( array $ret, $order ) {
	?>
	<section class="bp-ret-section" aria-labelledby="bp-ret-items-title">
		<h2 class="bp-ret-section-title" id="bp-ret-items-title"><?php esc_html_e( 'Items', 'brikpanel' ); ?></h2>
		<table class="bp-ret-lines brikpanel-fit-table" id="bp-ret-lines">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Product', 'brikpanel' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Quantity', 'brikpanel' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Paid per item', 'brikpanel' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $ret['items'] as $line ) : ?>
					<?php
					if ( ! empty( $line['parent'] ) ) {
						continue;
					}
					$item    = $order ? $order->get_item( (int) $line['item_id'] ) : null;
					$product = $item ? $item->get_product() : null;
					$name    = $item ? $item->get_name() : (string) $line['name'];
					?>
					<tr>
						<td class="brikpanel-fit-lead">
							<span class="bp-ret-product">
								<?php if ( $product ) : ?>
									<span class="bp-ret-thumb"><?php echo wp_kses_post( $product->get_image( array( 40, 40 ) ) ); ?></span>
								<?php endif; ?>
								<span class="bp-ret-product-text">
									<?php if ( $product ) : ?>
										<a href="<?php echo esc_url( (string) get_edit_post_link( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) ); ?>"><?php echo esc_html( $name ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $name ); ?>
									<?php endif; ?>
									<?php
									if ( $item ) {
										echo wp_kses_post(
											wc_display_item_meta(
												$item,
												array(
													'echo'         => false,
													'before'       => '<span class="bp-ret-cell-sub">',
													'after'        => '</span>',
													'separator'    => ', ',
													'label_before' => '',
													'label_after'  => ': ',
													'autop'        => false,
												)
											)
										);
									}
									?>
								</span>
							</span>
						</td>
						<td class="brikpanel-fit-headline"><?php echo esc_html( brikpanel_returns_number( (int) $line['qty'] ) ); ?></td>
						<td><?php echo $order ? wp_kses_post( wc_price( (float) $line['unit'], array( 'currency' => $order->get_currency() ) ) ) : '&ndash;'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( 'refunded' === $ret['status'] && $order ) : ?>
			<p class="bp-ret-note">
				<?php
				/* translators: %s: refunded amount */
				echo esc_html( sprintf( __( 'Refunded: %s', 'brikpanel' ), brikpanel_returns_plain_price( $ret['amount'], $order->get_currency() ) ) );
				?>
			</p>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * Reason, note and the store's message.
 *
 * @param array $ret Return.
 * @return void
 */
function brikpanel_returns_card_reason( array $ret ) {
	if ( '' === $ret['reason'] && '' === $ret['comment'] && '' === $ret['admin_message'] ) {
		return;
	}
	?>
	<section class="bp-ret-section" aria-labelledby="bp-ret-reason-title">
		<h2 class="bp-ret-section-title" id="bp-ret-reason-title"><?php esc_html_e( 'Reason', 'brikpanel' ); ?></h2>
		<?php if ( '' !== $ret['reason'] ) : ?>
			<p class="bp-ret-reason"><?php echo esc_html( $ret['reason'] ); ?></p>
		<?php endif; ?>
		<?php if ( '' !== $ret['comment'] ) : ?>
			<blockquote class="bp-ret-quote" dir="auto"><?php echo wp_kses_post( wpautop( esc_html( $ret['comment'] ) ) ); ?></blockquote>
		<?php endif; ?>
		<?php if ( '' !== $ret['admin_message'] ) : ?>
			<p class="bp-ret-label"><?php esc_html_e( 'Your message to the customer', 'brikpanel' ); ?></p>
			<blockquote class="bp-ret-quote" dir="auto"><?php echo wp_kses_post( wpautop( esc_html( $ret['admin_message'] ) ) ); ?></blockquote>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * Photos.
 *
 * @param array $ret Return.
 * @return void
 */
function brikpanel_returns_card_photos( array $ret ) {
	if ( ! $ret['photos'] ) {
		return;
	}
	?>
	<section class="bp-ret-section" aria-labelledby="bp-ret-photos-title">
		<h2 class="bp-ret-section-title" id="bp-ret-photos-title"><?php esc_html_e( 'Photos', 'brikpanel' ); ?></h2>
		<div class="bp-ret-photos">
			<?php foreach ( $ret['photos'] as $i => $name ) : ?>
				<?php $src = brikpanel_returns_photo_url( $ret['id'], $name ); ?>
				<button type="button" class="bp-ret-photo" data-bp-ret-photo="<?php echo esc_url( $src ); ?>">
					<img src="<?php echo esc_url( $src ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: photo number */ __( 'Photo %s from the customer', 'brikpanel' ), brikpanel_returns_number( $i + 1 ) ) ); ?>" loading="lazy">
				</button>
			<?php endforeach; ?>
		</div>
	</section>
	<?php
}

/**
 * The EU withdrawal statement.
 *
 * @param array $ret Return.
 * @return void
 */
function brikpanel_returns_card_statement( array $ret ) {
	if ( empty( $ret['withdrawal'] ) ) {
		return;
	}
	$st  = is_array( $ret['statement'] ) ? $ret['statement'] : array();
	$sub = $ret['submitted_at'] ? strtotime( $ret['submitted_at'] . ' UTC' ) : 0;
	?>
	<section class="bp-ret-section" aria-labelledby="bp-ret-st-title">
		<h2 class="bp-ret-section-title" id="bp-ret-st-title"><?php esc_html_e( 'Withdrawal statement', 'brikpanel' ); ?></h2>
		<dl class="bp-ret-dl">
			<dt><?php esc_html_e( 'Name', 'brikpanel' ); ?></dt>
			<dd><?php echo esc_html( isset( $st['name'] ) ? $st['name'] : '' ); ?></dd>
			<dt><?php esc_html_e( 'Email', 'brikpanel' ); ?></dt>
			<dd><?php echo esc_html( isset( $st['email'] ) ? $st['email'] : '' ); ?></dd>
			<dt><?php esc_html_e( 'Sent on', 'brikpanel' ); ?></dt>
			<dd><?php echo esc_html( brikpanel_returns_format_moment( $ret['submitted_at'] ) ); ?></dd>
			<?php if ( $sub ) : ?>
				<dt><?php esc_html_e( 'Refund due by', 'brikpanel' ); ?></dt>
				<dd><?php echo esc_html( wp_date( function_exists( 'brikpanel_date_format' ) ? brikpanel_date_format() : get_option( 'date_format' ), $sub + BRIKPANEL_RETURNS_EU_DAYS * DAY_IN_SECONDS ) ); ?></dd>
			<?php endif; ?>
		</dl>
		<p class="bp-ret-note"><?php esc_html_e( 'You may wait with the refund until the items are back or the customer shows they sent them.', 'brikpanel' ); ?></p>
	</section>
	<?php
}

/**
 * Customer and order.
 *
 * @param array          $ret   Return.
 * @param WC_Order|false $order Order.
 * @return void
 */
function brikpanel_returns_card_customer( array $ret, $order ) {
	?>
	<section class="bp-ret-section" aria-labelledby="bp-ret-cust-title">
		<h2 class="bp-ret-section-title" id="bp-ret-cust-title"><?php esc_html_e( 'Customer', 'brikpanel' ); ?></h2>
		<?php if ( $order ) : ?>
			<p class="bp-ret-strong"><?php echo esc_html( trim( $order->get_formatted_billing_full_name() ) ); ?></p>
			<p><a href="<?php echo esc_url( 'mailto:' . $ret['email'] ); ?>"><?php echo esc_html( $ret['email'] ); ?></a></p>
			<?php if ( $order->get_billing_phone() ) : ?>
				<p><?php echo esc_html( $order->get_billing_phone() ); ?></p>
			<?php endif; ?>
			<dl class="bp-ret-dl">
				<dt><?php esc_html_e( 'Order', 'brikpanel' ); ?></dt>
				<dd><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></dd>
				<dt><?php esc_html_e( 'Order status', 'brikpanel' ); ?></dt>
				<dd><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></dd>
				<dt><?php esc_html_e( 'Ordered on', 'brikpanel' ); ?></dt>
				<dd><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></dd>
				<?php if ( $order->get_payment_method_title() ) : ?>
					<dt><?php esc_html_e( 'Payment', 'brikpanel' ); ?></dt>
					<dd><?php echo esc_html( $order->get_payment_method_title() ); ?></dd>
				<?php endif; ?>
			</dl>
		<?php else : ?>
			<p><?php echo esc_html( $ret['email'] ); ?></p>
			<p class="bp-ret-note"><?php esc_html_e( 'The order of this return no longer exists.', 'brikpanel' ); ?></p>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * History.
 *
 * @param array $ret Return.
 * @return void
 */
function brikpanel_returns_card_history( array $ret ) {
	?>
	<section class="bp-ret-section" aria-labelledby="bp-ret-hist-title">
		<h2 class="bp-ret-section-title" id="bp-ret-hist-title"><?php esc_html_e( 'History', 'brikpanel' ); ?></h2>
		<ol class="bp-ret-history">
			<?php
			// "Refunding" only lasts while WooCommerce books the refund: shown while it is
			// the current step (a stuck refund), hidden once a later step follows.
			$steps = $ret['history'];
			$last  = count( $steps ) - 1;
			foreach ( $steps as $i => $step ) {
				if ( isset( $step['s'] ) && 'resolving' === $step['s'] && $i !== $last ) {
					unset( $steps[ $i ] );
				}
			}
			?>
			<?php foreach ( array_reverse( $steps ) as $step ) : ?>
				<?php
				$who = brikpanel_returns_actor( isset( $step['u'] ) ? (int) $step['u'] : 0 );
				?>
				<li>
					<span class="bp-ret-history-status"><?php echo esc_html( brikpanel_returns_status_label( isset( $step['s'] ) ? $step['s'] : '' ) ); ?></span>
					<span class="bp-ret-cell-sub">
						<?php
						echo esc_html( brikpanel_returns_admin_moment( isset( $step['t'] ) ? $step['t'] : '' ) );
						if ( '' !== $who ) {
							echo esc_html( ', ' . $who );
						}
						?>
					</span>
					<?php if ( ! empty( $step['n'] ) ) : ?>
						<span class="bp-ret-history-note" dir="auto"><?php echo esc_html( $step['n'] ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	</section>
	<?php
}

/**
 * Hidden fields every action form carries.
 *
 * @param array  $ret    Return.
 * @param string $action Action.
 * @return void
 */
function brikpanel_returns_form_fields( array $ret, $action ) {
	echo '<input type="hidden" name="action" value="brikpanel_return_admin">';
	echo '<input type="hidden" name="do" value="' . esc_attr( $action ) . '">';
	echo '<input type="hidden" name="return_id" value="' . esc_attr( (string) $ret['id'] ) . '">';
	echo '<input type="hidden" name="version" value="' . esc_attr( (string) $ret['version'] ) . '">';
	wp_nonce_field( 'brikpanel_return_admin_' . $ret['id'], 'brikpanel_return_nonce' );
}

/**
 * Approve / reject: a message and a button.
 *
 * @param array  $ret         Return.
 * @param string $action      approve|reject.
 * @param string $title       Dialog title.
 * @param string $label       Field label.
 * @param string $hint        Field hint.
 * @param bool   $required    Message required.
 * @param string $submit      Button text.
 * @return void
 */
function brikpanel_returns_dialog_message( array $ret, $action, $title, $label, $hint, $required, $submit ) {
	$id = 'bp-ret-dialog-' . $action;
	?>
	<dialog class="bp-ret-dialog" id="<?php echo esc_attr( $id ); ?>" aria-labelledby="<?php echo esc_attr( $id ); ?>-title">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-bp-ret-form>
			<h2 class="bp-ret-dialog-title" id="<?php echo esc_attr( $id ); ?>-title"><?php echo esc_html( $title ); ?></h2>
			<label class="bp-ret-field-label" for="<?php echo esc_attr( $id ); ?>-msg"><?php echo esc_html( $label ); ?></label>
			<textarea class="brikpanel-control bp-ret-textarea" id="<?php echo esc_attr( $id ); ?>-msg" name="message" rows="5" maxlength="2000" <?php echo $required ? 'required' : ''; ?>></textarea>
			<p class="bp-ret-note"><?php echo esc_html( $hint ); ?></p>
			<?php brikpanel_returns_form_fields( $ret, $action ); ?>
			<div class="bp-ret-dialog-actions">
				<button type="button" class="brikpanel-btn brikpanel-btn--secondary" data-bp-ret-close><?php esc_html_e( 'Cancel', 'brikpanel' ); ?></button>
				<button type="submit" class="brikpanel-btn <?php echo 'reject' === $action ? 'brikpanel-btn--danger' : 'brikpanel-btn--primary'; ?>"><?php echo esc_html( $submit ); ?></button>
			</div>
		</form>
	</dialog>
	<?php
}

/**
 * Item received.
 *
 * @param array $ret Return.
 * @return void
 */
function brikpanel_returns_dialog_receive( array $ret ) {
	?>
	<dialog class="bp-ret-dialog" id="bp-ret-dialog-receive" aria-labelledby="bp-ret-dialog-receive-title">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-bp-ret-form>
			<h2 class="bp-ret-dialog-title" id="bp-ret-dialog-receive-title"><?php esc_html_e( 'Did the items arrive?', 'brikpanel' ); ?></h2>
			<p><?php esc_html_e( 'Mark the return as received when the items are back. You refund in the next step.', 'brikpanel' ); ?></p>
			<?php brikpanel_returns_form_fields( $ret, 'receive' ); ?>
			<div class="bp-ret-dialog-actions">
				<button type="button" class="brikpanel-btn brikpanel-btn--secondary" data-bp-ret-close><?php esc_html_e( 'Cancel', 'brikpanel' ); ?></button>
				<button type="submit" class="brikpanel-btn brikpanel-btn--primary"><?php esc_html_e( 'Items received', 'brikpanel' ); ?></button>
			</div>
		</form>
	</dialog>
	<?php
}

/**
 * Refund: the amount, shipping, a deduction, stock and the payment route.
 *
 * @param array    $ret   Return.
 * @param WC_Order $order Order.
 * @return void
 */
function brikpanel_returns_dialog_refund( array $ret, $order ) {
	$plan      = brikpanel_returns_refund_plan( $order, $ret );
	$ship_plan = brikpanel_returns_refund_plan(
		$order,
		array_merge( $ret, array( 'items' => array() ) ),
		array( 'shipping' => true )
	);
	$shipping  = (float) $ship_plan['amount'];
	$remaining = (float) $order->get_remaining_refund_amount();
	$gateway   = brikpanel_returns_gateway_can_refund( $order );
	$restocked = false;
	foreach ( $ret['items'] as $line ) {
		if ( ! empty( $line['restocked'] ) ) {
			$restocked = true;
		}
	}
	$restock  = ! $restocked && ( 'received' === $ret['status'] || in_array( $order->get_status(), array( 'processing', 'on-hold' ), true ) );
	$all_back = true;
	foreach ( brikpanel_returns_lines( $order, (int) $ret['id'] ) as $item_id => $line ) {
		$taken = 0;
		foreach ( $ret['items'] as $r ) {
			if ( (int) $r['item_id'] === (int) $item_id ) {
				$taken += (int) $r['qty'];
			}
		}
		if ( $line['left'] > $taken ) {
			$all_back = false;
		}
	}
	$ship_on  = $shipping > 0 && ! empty( $ret['withdrawal'] ) && $all_back;
	$currency = $order->get_currency();
	?>
	<dialog class="bp-ret-dialog" id="bp-ret-dialog-refund" aria-labelledby="bp-ret-dialog-refund-title">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-bp-ret-form data-bp-ret-refund data-goods="<?php echo esc_attr( (string) $plan['goods'] ); ?>" data-shipping="<?php echo esc_attr( (string) $shipping ); ?>" data-remaining="<?php echo esc_attr( (string) $remaining ); ?>" data-symbol="<?php echo esc_attr( html_entity_decode( get_woocommerce_currency_symbol( $currency ), ENT_QUOTES, 'UTF-8' ) ); ?>">
			<h2 class="bp-ret-dialog-title" id="bp-ret-dialog-refund-title"><?php esc_html_e( 'Refund the return', 'brikpanel' ); ?></h2>
			<dl class="bp-ret-dl bp-ret-sum">
				<dt><?php esc_html_e( 'Items', 'brikpanel' ); ?></dt>
				<dd><?php echo wp_kses_post( wc_price( $plan['goods'], array( 'currency' => $currency ) ) ); ?></dd>
				<?php if ( $shipping > 0 ) : ?>
					<dt>
						<label class="bp-ret-check">
							<input type="checkbox" name="shipping" value="1" data-bp-ret-shipping <?php checked( $ship_on ); ?>>
							<?php esc_html_e( 'Also refund shipping', 'brikpanel' ); ?>
						</label>
					</dt>
					<dd><?php echo wp_kses_post( wc_price( $shipping, array( 'currency' => $currency ) ) ); ?></dd>
				<?php endif; ?>
				<dt><label for="bp-ret-deduction"><?php esc_html_e( 'Deduction (optional)', 'brikpanel' ); ?></label></dt>
				<dd><input type="number" class="brikpanel-control bp-ret-money" id="bp-ret-deduction" name="deduction" min="0" step="<?php echo esc_attr( (string) ( 1 / pow( 10, max( 0, wc_get_price_decimals() ) ) ) ); ?>" value="0" data-bp-ret-deduction></dd>
				<dt class="bp-ret-total-label"><?php esc_html_e( 'Refund amount', 'brikpanel' ); ?></dt>
				<dd class="bp-ret-total" data-bp-ret-total><?php echo wp_kses_post( wc_price( $plan['goods'] + ( $ship_on ? $shipping : 0 ), array( 'currency' => $currency ) ) ); ?></dd>
			</dl>
			<p class="bp-ret-note" data-bp-ret-error hidden></p>
			<label class="bp-ret-check">
				<input type="checkbox" name="restock" value="1" <?php checked( $restock ); ?>>
				<?php esc_html_e( 'Put the items back in stock', 'brikpanel' ); ?>
			</label>
			<?php if ( $restocked ) : ?>
				<p class="bp-ret-note"><?php esc_html_e( 'These items were already put back in stock by an earlier refund.', 'brikpanel' ); ?></p>
			<?php endif; ?>
			<input type="hidden" name="refunded_total" value="<?php echo esc_attr( (string) $order->get_total_refunded() ); ?>">
			<?php brikpanel_returns_form_fields( $ret, 'refund' ); ?>
			<div class="bp-ret-dialog-actions">
				<button type="button" class="brikpanel-btn brikpanel-btn--secondary" data-bp-ret-close><?php esc_html_e( 'Cancel', 'brikpanel' ); ?></button>
				<?php if ( $gateway ) : ?>
					<button type="submit" name="route" value="manual" class="brikpanel-btn brikpanel-btn--secondary"><?php esc_html_e( 'Refund manually', 'brikpanel' ); ?></button>
					<button type="submit" name="route" value="gateway" class="brikpanel-btn brikpanel-btn--primary">
						<?php
						/* translators: %s: payment method such as Stripe */
						echo esc_html( sprintf( __( 'Refund via %s', 'brikpanel' ), $order->get_payment_method_title() ) );
						?>
					</button>
				<?php else : ?>
					<button type="submit" name="route" value="manual" class="brikpanel-btn brikpanel-btn--primary"><?php esc_html_e( 'Refund manually', 'brikpanel' ); ?></button>
				<?php endif; ?>
			</div>
			<?php if ( ! $gateway ) : ?>
				<p class="bp-ret-note"><?php esc_html_e( 'This payment method cannot send money back by itself. Record the refund here and pay the customer yourself, for example by bank transfer.', 'brikpanel' ); ?></p>
			<?php endif; ?>
		</form>
	</dialog>
	<?php
}

/**
 * The photo viewer.
 *
 * @return void
 */
function brikpanel_returns_photo_dialog() {
	?>
	<dialog class="bp-ret-dialog bp-ret-viewer" id="bp-ret-viewer" aria-label="<?php esc_attr_e( 'Photo from the customer', 'brikpanel' ); ?>">
		<img src="" alt="<?php esc_attr_e( 'Photo from the customer', 'brikpanel' ); ?>" data-bp-ret-viewer-img>
		<div class="bp-ret-dialog-actions">
			<button type="button" class="brikpanel-btn brikpanel-btn--secondary" data-bp-ret-close><?php esc_html_e( 'Close', 'brikpanel' ); ?></button>
		</div>
	</dialog>
	<?php
}

// =============================================================================
// ACTIONS
// =============================================================================

/**
 * Every action from the Returns screen.
 *
 * @return void
 */
function brikpanel_returns_admin_post() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is checked right below.
	$id      = isset( $_POST['return_id'] ) ? absint( $_POST['return_id'] ) : 0;
	$version = isset( $_POST['version'] ) ? absint( $_POST['version'] ) : 0;
	$do      = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	if ( ! brikpanel_returns_user_can() ) {
		wp_die( esc_html__( 'You are not allowed to handle returns.', 'brikpanel' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'brikpanel_return_admin_' . $id, 'brikpanel_return_nonce' );
	$back = brikpanel_returns_admin_url( array( 'return' => $id ) );

	if ( 'refund' === $do ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$done = brikpanel_returns_refund(
			$id,
			$version,
			array(
				'shipping'       => ! empty( $_POST['shipping'] ),
				'deduction'      => isset( $_POST['deduction'] ) ? (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['deduction'] ) ) ) : 0.0,
				'restock'        => ! empty( $_POST['restock'] ),
				'gateway'        => isset( $_POST['route'] ) && 'gateway' === sanitize_key( wp_unslash( $_POST['route'] ) ),
				'refunded_total' => isset( $_POST['refunded_total'] ) ? (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['refunded_total'] ) ) ) : 0.0,
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$ok_text = __( 'The return is refunded. WooCommerce sent the customer its refund email.', 'brikpanel' );
	} elseif ( in_array( $do, array( 'approve', 'reject', 'receive' ), true ) ) {
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$done    = brikpanel_returns_act( $do, $id, $version, $message );
		$texts   = array(
			'approve' => __( 'The return is approved and the customer got an email.', 'brikpanel' ),
			'reject'  => __( 'The return is rejected and the customer got an email.', 'brikpanel' ),
			'receive' => __( 'The return is marked as received.', 'brikpanel' ),
		);
		$ok_text = $texts[ $do ];
	} else {
		$done    = new WP_Error( 'brikpanel_returns_action', __( 'Unknown action.', 'brikpanel' ) );
		$ok_text = '';
	}

	if ( is_wp_error( $done ) ) {
		brikpanel_returns_set_flash( 'error', $done->get_error_message() );
	} else {
		brikpanel_returns_set_flash( 'success', $ok_text );
	}
	wp_safe_redirect( $back );
	exit;
}

// =============================================================================
// WOOCOMMERCE SCREENS
// =============================================================================

/**
 * The order screen's "Returns" box, when the order has any.
 *
 * @param string          $screen_or_type Screen id or post type.
 * @param WC_Order|WP_Post $context        Order (HPOS) or post (legacy).
 * @return void
 */
function brikpanel_returns_order_metabox( $screen_or_type, $context ) {
	if ( ! brikpanel_returns_active() ) {
		return;
	}
	$order = null;
	if ( $context instanceof WC_Abstract_Order ) {
		$order = $context;
	} elseif ( $context instanceof WP_Post && function_exists( 'wc_get_order' ) ) {
		$order = wc_get_order( $context->ID );
	}
	if ( ! $order instanceof WC_Order || ! Brikpanel_Returns_Store::for_order( $order->get_id() ) ) {
		return;
	}
	add_meta_box( 'brikpanel_order_returns', __( 'Returns', 'brikpanel' ), 'brikpanel_returns_render_order_box', $screen_or_type, 'side', 'high' );
}

/**
 * @param string[] $ids Boxes that open in the right column.
 * @return string[]
 */
function brikpanel_returns_order_sidebar_box( $ids ) {
	$ids[] = 'brikpanel_order_returns';
	return $ids;
}

/**
 * @param WC_Order|WP_Post $context Order or post.
 * @return void
 */
function brikpanel_returns_render_order_box( $context ) {
	$order_id = $context instanceof WC_Abstract_Order ? $context->get_id() : ( $context instanceof WP_Post ? $context->ID : 0 );
	echo '<ul class="bp-ret-orderbox">';
	foreach ( array_reverse( Brikpanel_Returns_Store::for_order( $order_id ) ) as $ret ) {
		echo '<li><a href="' . esc_url( brikpanel_returns_admin_url( array( 'return' => $ret['id'] ) ) ) . '">#' . esc_html( (string) $ret['id'] ) . '</a> ';
		echo '<span class="bp-ret-status is-' . esc_attr( $ret['status'] ) . '">' . esc_html( brikpanel_returns_status_label( $ret['status'] ) ) . '</span> ';
		echo '<span class="bp-ret-cell-sub">' . esc_html( function_exists( 'brikpanel_local_date' ) ? brikpanel_local_date( $ret['created_at'] ) : $ret['created_at'] ) . '</span></li>';
	}
	echo '</ul>';
	echo '<style>.bp-ret-orderbox{margin:0}.bp-ret-orderbox li{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px;margin:0 0 8px}.bp-ret-orderbox .bp-ret-status{display:inline-flex;padding:0 8px;border-radius:999px;font-size:12px;font-weight:550;line-height:20px;background:#f1f1f1;color:#303030}.bp-ret-orderbox .bp-ret-status.is-requested{background:#fff1d6;color:#7a5000}.bp-ret-orderbox .bp-ret-status.is-refunded{background:#e4f5e1;color:#1a6b15}.bp-ret-orderbox .bp-ret-status.is-rejected{background:#fce4e4;color:#a61b1b}.bp-ret-orderbox .bp-ret-cell-sub{color:#616161;font-size:12px}</style>';
}

/**
 * Open returns of the orders on the screen, in one query.
 *
 * @return array<int,array>
 */
function brikpanel_returns_open_map() {
	static $map = null;
	if ( null === $map ) {
		$map = array();
		global $wpdb;
		if ( Brikpanel_Returns_Store::ensure_table() ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, order_id, status, withdrawal FROM %i WHERE open_order_id IS NOT NULL LIMIT 2000', Brikpanel_Returns_Store::table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $row ) {
				$map[ (int) $row['order_id'] ] = $row;
			}
		}
	}
	return $map;
}

/**
 * The badge under the order number (HPOS list).
 *
 * @param string   $column Column.
 * @param WC_Order $order  Order.
 * @return void
 */
function brikpanel_returns_orders_badge( $column, $order ) {
	if ( 'order_number' !== $column || ! $order instanceof WC_Abstract_Order || ! brikpanel_returns_active() ) {
		return;
	}
	brikpanel_returns_print_badge( $order->get_id() );
}

/**
 * The badge under the order number (legacy list).
 *
 * @param string $column  Column.
 * @param int    $post_id Order id.
 * @return void
 */
function brikpanel_returns_orders_badge_legacy( $column, $post_id ) {
	if ( 'order_number' !== $column || ! brikpanel_returns_active() ) {
		return;
	}
	brikpanel_returns_print_badge( (int) $post_id );
}

/**
 * @param int $order_id Order id.
 * @return void
 */
function brikpanel_returns_print_badge( $order_id ) {
	$map = brikpanel_returns_open_map();
	if ( ! isset( $map[ $order_id ] ) ) {
		return;
	}
	$label = ! empty( $map[ $order_id ]['withdrawal'] ) ? __( 'Withdrawal', 'brikpanel' ) : __( 'Return', 'brikpanel' );
	echo ' <a class="brikpanel-badge bp-ret-order-badge" href="' . esc_url( brikpanel_returns_admin_url( array( 'return' => (int) $map[ $order_id ]['id'] ) ) ) . '">' . esc_html( $label . ': ' . brikpanel_returns_status_label( $map[ $order_id ]['status'] ) ) . '</a>';
}

/**
 * "Return requests" in the top bar's Needs attention list.
 *
 * @return void
 */
function brikpanel_returns_attention_row() {
	if ( ! brikpanel_returns_user_can() ) {
		return;
	}
	$n = Brikpanel_Returns_Store::waiting_count();
	?>
	<a class="brikpanel-topbar-dropdown-row" role="menuitem" href="<?php echo esc_url( brikpanel_returns_admin_url( array( 'tab' => 'requested' ) ) ); ?>">
		<span class="brikpanel-topbar-dropdown-row-label"><?php esc_html_e( 'Return requests', 'brikpanel' ); ?></span>
		<span class="brikpanel-topbar-dropdown-row-count"><?php echo esc_html( brikpanel_returns_number( $n ) ); ?></span>
	</a>
	<?php
}
