<?php
/**
 * BrikPanel — Stock Orders (Purchase Orders).
 *
 * List + editor surfaces for purchase orders sent to vendors. The editor is
 * a full page (not a modal) because POs hold a header + many line items and
 * benefit from real estate. Status flow:
 *
 *     draft → ordered → (partially_received) → received
 *               └─→ cancelled (terminal)
 *
 * On receive (or partial receive), the receive flow runs through three
 * independent automations, each gated by a settings toggle:
 *
 *   - `brikpanel_po_auto_update_stock`   (default ON) — adds qty_received to
 *     each product/variation\'s WC stock via wc_update_product_stock.
 *   - `brikpanel_po_auto_update_cogs`    (default ON) — writes the unit cost
 *     into WooCommerce\'s native COGS field. Method can be "last cost" or
 *     "weighted average" via `brikpanel_po_cogs_method`.
 *   - `brikpanel_po_auto_create_expense` (default ON) — pushes the order
 *     total to the BrikPanel Expenses table under the Inventory category.
 *
 * Both simple and variable products are first-class — the line picker writes
 * `variation_id` when a variation is chosen, and the receive flow uses the
 * variation id whenever present.
 *
 * @package BrikPanel
 * @since   2.7.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Stock_Orders {

	const PAGE_SLUG       = 'brikpanel-stock-orders';
	const NONCE_ACTION    = 'brikpanel_stock_orders_nonce';
	const TABLE           = 'brikpanel_stock_orders';
	const ITEMS_TABLE     = 'brikpanel_stock_order_items';

	const STATUS_DRAFT     = 'draft';
	const STATUS_ORDERED   = 'ordered';
	const STATUS_PARTIAL   = 'partially_received';
	const STATUS_RECEIVED  = 'received';
	const STATUS_CANCELLED = 'cancelled';

	public function __construct() {
		// Self-gate on the stock_orders sub-toggle. Vendor master toggle is
		// already handled by the bootstrap loader.
		if ( 'yes' !== get_option( 'brikpanel_stock_orders_enabled', 'yes' ) ) {
			return;
		}

		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_brikpanel_so_list',           [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_brikpanel_so_get',            [ $this, 'ajax_get' ] );
		add_action( 'wp_ajax_brikpanel_so_save',           [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_brikpanel_so_delete',         [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_brikpanel_so_status',         [ $this, 'ajax_change_status' ] );
		add_action( 'wp_ajax_brikpanel_so_search_product', [ $this, 'ajax_search_product' ] );
	}

	// =========================================================================
	// Page registration
	// =========================================================================

	public function register_page() {
		// Hangs off the top-level "Vendors" menu registered by Brikpanel_Vendors.
		add_submenu_page(
			Brikpanel_Vendors::PAGE_SLUG,
			__( 'Stock Orders', 'brikpanel' ),
			__( 'Stock Orders', 'brikpanel' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( (string) $hook, self::PAGE_SLUG ) === false ) {
			return;
		}
		$base = BRIKPANEL_URL . 'front-end/vendors/';
		$path = BRIKPANEL_PATH . 'front-end/vendors/';
		wp_enqueue_style(
			'brikpanel-stock-orders',
			$base . 'brikpanel-stock-orders.css',
			[],
			file_exists( $path . 'brikpanel-stock-orders.css' ) ? filemtime( $path . 'brikpanel-stock-orders.css' ) : BRIKPANEL_VERSION
		);
		wp_enqueue_script(
			'brikpanel-stock-orders',
			$base . 'brikpanel-stock-orders.js',
			[],
			file_exists( $path . 'brikpanel-stock-orders.js' ) ? filemtime( $path . 'brikpanel-stock-orders.js' ) : BRIKPANEL_VERSION,
			true
		);
	}

	// =========================================================================
	// Auth
	// =========================================================================

	private function check_auth() {
		check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'brikpanel' ) ], 403 );
		}
	}

	// =========================================================================
	// Render page (router: list vs editor)
	// =========================================================================

	public function render_page() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( $action === 'edit' || $action === 'new' ) {
			$this->render_editor( $id );
			return;
		}
		$this->render_list();
	}

	// =========================================================================
	// List view
	// =========================================================================

	private function render_list() {
		$nonce    = wp_create_nonce( self::NONCE_ACTION );
		$ajax_url = admin_url( 'admin-ajax.php' );
		$new_url  = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );
		$ven_url  = admin_url( 'admin.php?page=' . Brikpanel_Vendors::PAGE_SLUG );
		?>
		<div class="wrap brikpanel-so-wrap" id="brikpanel-stock-orders">
			<div class="brikpanel-so-header">
				<div class="brikpanel-so-header-left">
					<h1><?php esc_html_e( 'Stock Orders', 'brikpanel' ); ?></h1>
					<p class="brikpanel-so-subtitle"><?php esc_html_e( 'Track purchase orders sent to suppliers. Mark them received to update stock, costs, and expenses automatically.', 'brikpanel' ); ?></p>
				</div>
				<div class="brikpanel-so-header-right">
					<a class="brikpanel-so-btn brikpanel-so-btn-secondary" href="<?php echo esc_url( $ven_url ); ?>"><?php esc_html_e( 'Suppliers', 'brikpanel' ); ?></a>
					<a class="brikpanel-so-btn brikpanel-so-btn-primary" href="<?php echo esc_url( $new_url ); ?>">+ <?php esc_html_e( 'New stock order', 'brikpanel' ); ?></a>
				</div>
			</div>

			<!-- Summary -->
			<div class="brikpanel-so-summary" id="brikpanel-so-summary">
				<div class="brikpanel-so-summary-card">
					<div class="brikpanel-so-summary-label"><?php esc_html_e( 'Open POs', 'brikpanel' ); ?></div>
					<div class="brikpanel-so-summary-value" id="brikpanel-so-open">—</div>
				</div>
				<div class="brikpanel-so-summary-card">
					<div class="brikpanel-so-summary-label"><?php esc_html_e( 'Open value', 'brikpanel' ); ?></div>
					<div class="brikpanel-so-summary-value" id="brikpanel-so-open-value">—</div>
				</div>
				<div class="brikpanel-so-summary-card">
					<div class="brikpanel-so-summary-label"><?php esc_html_e( 'Received (90d)', 'brikpanel' ); ?></div>
					<div class="brikpanel-so-summary-value" id="brikpanel-so-recv-90d">—</div>
				</div>
			</div>

			<!-- Filters -->
			<div class="brikpanel-so-card brikpanel-so-filters">
				<div class="brikpanel-so-filter-row">
					<div class="brikpanel-so-field brikpanel-so-field-grow">
						<label for="brikpanel-so-search"><?php esc_html_e( 'Search', 'brikpanel' ); ?></label>
						<input type="search" id="brikpanel-so-search" placeholder="<?php esc_attr_e( 'Reference or supplier…', 'brikpanel' ); ?>" autocomplete="off" />
					</div>
					<div class="brikpanel-so-field">
						<label for="brikpanel-so-status-filter"><?php esc_html_e( 'Status', 'brikpanel' ); ?></label>
						<select id="brikpanel-so-status-filter">
							<option value=""><?php esc_html_e( 'All', 'brikpanel' ); ?></option>
							<option value="<?php echo esc_attr( self::STATUS_DRAFT ); ?>"><?php esc_html_e( 'Draft', 'brikpanel' ); ?></option>
							<option value="<?php echo esc_attr( self::STATUS_ORDERED ); ?>"><?php esc_html_e( 'Ordered', 'brikpanel' ); ?></option>
							<option value="<?php echo esc_attr( self::STATUS_PARTIAL ); ?>"><?php esc_html_e( 'Partially received', 'brikpanel' ); ?></option>
							<option value="<?php echo esc_attr( self::STATUS_RECEIVED ); ?>"><?php esc_html_e( 'Received', 'brikpanel' ); ?></option>
							<option value="<?php echo esc_attr( self::STATUS_CANCELLED ); ?>"><?php esc_html_e( 'Cancelled', 'brikpanel' ); ?></option>
						</select>
					</div>
					<div class="brikpanel-so-field">
						<label for="brikpanel-so-from"><?php esc_html_e( 'From', 'brikpanel' ); ?></label>
						<input type="date" id="brikpanel-so-from" />
					</div>
					<div class="brikpanel-so-field">
						<label for="brikpanel-so-to"><?php esc_html_e( 'To', 'brikpanel' ); ?></label>
						<input type="date" id="brikpanel-so-to" />
					</div>
				</div>
			</div>

			<!-- Table -->
			<div class="brikpanel-so-card brikpanel-so-table-card">
				<div class="brikpanel-so-table-wrap">
					<table class="brikpanel-so-table" id="brikpanel-so-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Reference', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Supplier', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Status', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Ordered', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Expected', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Received', 'brikpanel' ); ?></th>
								<th class="brikpanel-so-num"><?php esc_html_e( 'Total', 'brikpanel' ); ?></th>
								<th class="brikpanel-so-actions-th"></th>
							</tr>
						</thead>
						<tbody id="brikpanel-so-tbody">
							<tr><td colspan="8" class="brikpanel-so-empty"><?php esc_html_e( 'Loading…', 'brikpanel' ); ?></td></tr>
						</tbody>
					</table>
				</div>
				<div class="brikpanel-so-pagination" id="brikpanel-so-pagination" hidden>
					<button type="button" class="brikpanel-so-btn brikpanel-so-btn-secondary" id="brikpanel-so-prev">&larr; <?php esc_html_e( 'Previous', 'brikpanel' ); ?></button>
					<span class="brikpanel-so-page-info" id="brikpanel-so-page-info">1 / 1</span>
					<button type="button" class="brikpanel-so-btn brikpanel-so-btn-secondary" id="brikpanel-so-next"><?php esc_html_e( 'Next', 'brikpanel' ); ?> &rarr;</button>
				</div>
			</div>
		</div>

		<script>
		window.brikpanelStockOrders = {
			mode:     'list',
			ajax_url: <?php echo wp_json_encode( esc_url_raw( $ajax_url ) ); ?>,
			nonce:    <?php echo wp_json_encode( $nonce ); ?>,
			currency: <?php echo wp_json_encode( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); ?>,
			edit_url: <?php echo wp_json_encode( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&id=' ) ); ?>,
			i18n: {
				confirm_delete: <?php echo wp_json_encode( __( 'Delete this stock order? This cannot be undone.', 'brikpanel' ) ); ?>,
				no_orders:      <?php echo wp_json_encode( __( 'No stock orders yet — click "New stock order" to create one.', 'brikpanel' ) ); ?>,
				error:          <?php echo wp_json_encode( __( 'Something went wrong.', 'brikpanel' ) ); ?>,
				draft:          <?php echo wp_json_encode( __( 'Draft', 'brikpanel' ) ); ?>,
				ordered:        <?php echo wp_json_encode( __( 'Ordered', 'brikpanel' ) ); ?>,
				partial:        <?php echo wp_json_encode( __( 'Partial', 'brikpanel' ) ); ?>,
				received:       <?php echo wp_json_encode( __( 'Received', 'brikpanel' ) ); ?>,
				cancelled:      <?php echo wp_json_encode( __( 'Cancelled', 'brikpanel' ) ); ?>,
				open:           <?php echo wp_json_encode( __( 'Open', 'brikpanel' ) ); ?>,
				delete:         <?php echo wp_json_encode( __( 'Delete', 'brikpanel' ) ); ?>,
			}
		};
		</script>
		<?php
	}

	// =========================================================================
	// Editor view
	// =========================================================================

	private function render_editor( $id ) {
		global $wpdb;
		$nonce    = wp_create_nonce( self::NONCE_ACTION );
		$ajax_url = admin_url( 'admin-ajax.php' );
		$back_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		// WC's get_woocommerce_currency_symbol() returns HTML entities (e.g. `&#36;`
		// for USD). Decode here so JS can concatenate the symbol into textContent
		// without the entity bleeding through verbatim.
		$currency = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$vendors  = Brikpanel_Vendors::get_active_vendor_options();

		$po       = null;
		$items    = [];
		if ( $id ) {
			$po = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d", $id ) ); // phpcs:ignore
			if ( $po ) {
				$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . self::ITEMS_TABLE . " WHERE order_id = %d ORDER BY sort_order ASC, id ASC", $id ) ); // phpcs:ignore
			}
		}
		$is_new   = ! $po;
		$is_locked = $po && in_array( $po->status, [ self::STATUS_RECEIVED, self::STATUS_CANCELLED ], true );

		// Pre-selected vendor for new POs (link from vendor detail page).
		$preselect_vendor_id = 0;
		if ( $is_new && isset( $_GET['vendor_id'] ) ) {
			$preselect_vendor_id = absint( $_GET['vendor_id'] );
			if ( $preselect_vendor_id > 0 && ! isset( $vendors[ $preselect_vendor_id ] ) ) {
				$preselect_vendor_id = 0; // archived/unknown — don't preselect.
			}
		}

		$ref_prefix = (string) get_option( 'brikpanel_po_reference_prefix', 'PO' );
		?>
		<div class="wrap brikpanel-so-wrap brikpanel-so-editor-wrap" id="brikpanel-stock-orders">
			<div class="brikpanel-so-header">
				<div class="brikpanel-so-header-left">
					<a class="brikpanel-so-back" href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Stock orders', 'brikpanel' ); ?></a>
					<h1>
						<?php
						if ( $is_new ) {
							esc_html_e( 'New stock order', 'brikpanel' );
						} else {
							echo esc_html( $po->reference !== '' ? $po->reference : sprintf( __( 'Stock order #%d', 'brikpanel' ), $po->id ) );
						}
						?>
					</h1>
					<?php if ( $po ) : ?>
						<span class="brikpanel-so-status brikpanel-so-status--<?php echo esc_attr( $po->status ); ?>">
							<?php echo esc_html( $this->status_label( $po->status ) ); ?>
						</span>
					<?php endif; ?>
				</div>
				<div class="brikpanel-so-header-right">
					<?php if ( ! $is_locked ) : ?>
						<button type="button" class="brikpanel-so-btn brikpanel-so-btn-secondary" id="brikpanel-so-save-draft"><?php esc_html_e( 'Save', 'brikpanel' ); ?></button>
						<?php if ( ! $is_new && $po->status === self::STATUS_DRAFT ) : ?>
							<button type="button" class="brikpanel-so-btn brikpanel-so-btn-secondary" data-status="<?php echo esc_attr( self::STATUS_ORDERED ); ?>" data-action="status"><?php esc_html_e( 'Mark ordered', 'brikpanel' ); ?></button>
						<?php endif; ?>
						<?php if ( ! $is_new && in_array( $po->status, [ self::STATUS_ORDERED, self::STATUS_PARTIAL ], true ) ) : ?>
							<button type="button" class="brikpanel-so-btn brikpanel-so-btn-primary" data-status="<?php echo esc_attr( self::STATUS_RECEIVED ); ?>" data-action="status"><?php esc_html_e( 'Mark received', 'brikpanel' ); ?></button>
						<?php endif; ?>
						<?php if ( ! $is_new && $po->status !== self::STATUS_CANCELLED ) : ?>
							<button type="button" class="brikpanel-so-btn brikpanel-so-btn-danger" data-status="<?php echo esc_attr( self::STATUS_CANCELLED ); ?>" data-action="status"><?php esc_html_e( 'Cancel', 'brikpanel' ); ?></button>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>

			<form id="brikpanel-so-form" autocomplete="off" data-id="<?php echo esc_attr( (string) ( $po ? $po->id : 0 ) ); ?>" <?php echo $is_locked ? 'data-locked="1"' : ''; ?>>
				<!-- Header card: vendor, reference, dates -->
				<div class="brikpanel-so-card brikpanel-so-editor-card">
					<header class="brikpanel-so-card__header">
						<h2><?php esc_html_e( 'Order details', 'brikpanel' ); ?></h2>
					</header>
					<div class="brikpanel-so-card__body">
						<div class="brikpanel-so-grid">
							<div class="brikpanel-so-field">
								<label for="brikpanel-so-vendor"><?php esc_html_e( 'Supplier', 'brikpanel' ); ?> <span class="brikpanel-so-req">*</span></label>
								<select id="brikpanel-so-vendor" required>
									<option value="0"><?php esc_html_e( '— Select supplier —', 'brikpanel' ); ?></option>
									<?php
									$current_vendor_id = $po ? (int) $po->vendor_id : (int) $preselect_vendor_id;
									foreach ( $vendors as $vid => $vname ) :
										?>
										<option value="<?php echo esc_attr( (string) $vid ); ?>" <?php selected( $current_vendor_id, (int) $vid ); ?>><?php echo esc_html( $vname ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="brikpanel-so-field">
								<label for="brikpanel-so-reference"><?php esc_html_e( 'Reference', 'brikpanel' ); ?></label>
								<input type="text" id="brikpanel-so-reference" maxlength="40" value="<?php echo esc_attr( $po ? $po->reference : '' ); ?>" placeholder="<?php echo esc_attr( $ref_prefix . '-' . gmdate( 'Y' ) . '-…' ); ?>" />
							</div>
							<div class="brikpanel-so-field">
								<label for="brikpanel-so-order-date"><?php esc_html_e( 'Order date', 'brikpanel' ); ?></label>
								<input type="date" id="brikpanel-so-order-date" value="<?php echo esc_attr( $po && $po->order_date ? $po->order_date : '' ); ?>" />
							</div>
							<div class="brikpanel-so-field">
								<label for="brikpanel-so-expected-date"><?php esc_html_e( 'Expected date', 'brikpanel' ); ?></label>
								<input type="date" id="brikpanel-so-expected-date" value="<?php echo esc_attr( $po && $po->expected_date ? $po->expected_date : '' ); ?>" />
							</div>
							<div class="brikpanel-so-field">
								<label for="brikpanel-so-received-date"><?php esc_html_e( 'Received date', 'brikpanel' ); ?></label>
								<input type="date" id="brikpanel-so-received-date" value="<?php echo esc_attr( $po && $po->received_date ? $po->received_date : '' ); ?>" />
							</div>
							<div class="brikpanel-so-field">
								<label for="brikpanel-so-shipping"><?php esc_html_e( 'Shipping fee', 'brikpanel' ); ?></label>
								<div class="brikpanel-so-input-group">
									<span class="brikpanel-so-prefix"><?php echo esc_html( $currency ); ?></span>
									<input type="number" id="brikpanel-so-shipping" min="0" step="0.01" value="<?php echo esc_attr( $po ? (string) $po->shipping_fee : '0' ); ?>" />
								</div>
							</div>
							<div class="brikpanel-so-field">
								<label for="brikpanel-so-tax"><?php esc_html_e( 'Tax', 'brikpanel' ); ?></label>
								<div class="brikpanel-so-input-group">
									<span class="brikpanel-so-prefix"><?php echo esc_html( $currency ); ?></span>
									<input type="number" id="brikpanel-so-tax" min="0" step="0.01" value="<?php echo esc_attr( $po ? (string) $po->tax : '0' ); ?>" />
								</div>
							</div>
							<div class="brikpanel-so-field brikpanel-so-field-full">
								<label for="brikpanel-so-notes"><?php esc_html_e( 'Notes', 'brikpanel' ); ?></label>
								<textarea id="brikpanel-so-notes" rows="2" placeholder="<?php esc_attr_e( 'Internal notes — payment terms, container ID…', 'brikpanel' ); ?>"><?php echo esc_textarea( $po ? $po->notes : '' ); ?></textarea>
							</div>
						</div>
					</div>
				</div>

				<!-- Line items -->
				<div class="brikpanel-so-card brikpanel-so-editor-card">
					<header class="brikpanel-so-card__header">
						<h2><?php esc_html_e( 'Items', 'brikpanel' ); ?></h2>
						<p class="brikpanel-so-card__desc"><?php esc_html_e( 'Add the products you\'re ordering. Variations are supported — the picker will list each variation with its attributes.', 'brikpanel' ); ?></p>
					</header>
					<div class="brikpanel-so-card__body">
						<div class="brikpanel-so-product-picker">
							<input type="text" id="brikpanel-so-product-search" placeholder="<?php esc_attr_e( 'Search products by name or SKU…', 'brikpanel' ); ?>" autocomplete="off" />
							<div class="brikpanel-so-suggestions" id="brikpanel-so-suggestions" hidden></div>
						</div>

						<div class="brikpanel-so-items-wrap">
							<table class="brikpanel-so-items" id="brikpanel-so-items">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Product', 'brikpanel' ); ?></th>
										<th class="brikpanel-so-num"><?php esc_html_e( 'Qty ordered', 'brikpanel' ); ?></th>
										<th class="brikpanel-so-num"><?php esc_html_e( 'Qty received', 'brikpanel' ); ?></th>
										<th class="brikpanel-so-num"><?php esc_html_e( 'Unit cost', 'brikpanel' ); ?></th>
										<th class="brikpanel-so-num"><?php esc_html_e( 'Line total', 'brikpanel' ); ?></th>
										<th class="brikpanel-so-actions-th"></th>
									</tr>
								</thead>
								<tbody id="brikpanel-so-items-tbody">
									<?php if ( empty( $items ) ) : ?>
										<tr class="brikpanel-so-items-empty"><td colspan="6"><?php esc_html_e( 'No items yet — search above to add a product.', 'brikpanel' ); ?></td></tr>
									<?php endif; ?>
								</tbody>
							</table>
						</div>

						<div class="brikpanel-so-totals">
							<div><span><?php esc_html_e( 'Subtotal', 'brikpanel' ); ?></span><strong id="brikpanel-so-subtotal-value">—</strong></div>
							<div><span><?php esc_html_e( 'Shipping', 'brikpanel' ); ?></span><strong id="brikpanel-so-shipping-value">—</strong></div>
							<div><span><?php esc_html_e( 'Tax', 'brikpanel' ); ?></span><strong id="brikpanel-so-tax-value">—</strong></div>
							<div class="brikpanel-so-totals-grand"><span><?php esc_html_e( 'Total', 'brikpanel' ); ?></span><strong id="brikpanel-so-total-value">—</strong></div>
						</div>
					</div>
				</div>
			</form>
		</div>

		<script>
		window.brikpanelStockOrders = {
			mode:           'editor',
			ajax_url:       <?php echo wp_json_encode( esc_url_raw( $ajax_url ) ); ?>,
			nonce:          <?php echo wp_json_encode( $nonce ); ?>,
			vendors_nonce:  <?php echo wp_json_encode( wp_create_nonce( Brikpanel_Vendors::NONCE_ACTION ) ); ?>,
			currency:       <?php echo wp_json_encode( $currency ); ?>,
			back_url:       <?php echo wp_json_encode( esc_url_raw( $back_url ) ); ?>,
			ref_prefix:     <?php echo wp_json_encode( $ref_prefix ); ?>,
			items_seed:     <?php echo wp_json_encode( $this->seed_items_for_js( $items ) ); ?>,
			po_id:          <?php echo wp_json_encode( $po ? (int) $po->id : 0 ); ?>,
			preselect_vid:  <?php echo wp_json_encode( (int) ( $is_new ? $preselect_vendor_id : 0 ) ); ?>,
			locked:         <?php echo wp_json_encode( (bool) $is_locked ); ?>,
			i18n: {
				saved:           <?php echo wp_json_encode( __( 'Saved.', 'brikpanel' ) ); ?>,
				vendor_required: <?php echo wp_json_encode( __( 'Pick a supplier before saving.', 'brikpanel' ) ); ?>,
				items_required:  <?php echo wp_json_encode( __( 'Add at least one product before marking ordered.', 'brikpanel' ) ); ?>,
				confirm_receive: <?php echo wp_json_encode( __( 'Mark this PO as received? Stock, costs and expenses will be updated based on your settings.', 'brikpanel' ) ); ?>,
				confirm_cancel:  <?php echo wp_json_encode( __( 'Cancel this PO? It will be locked and excluded from reports.', 'brikpanel' ) ); ?>,
				error:           <?php echo wp_json_encode( __( 'Something went wrong.', 'brikpanel' ) ); ?>,
				no_results:      <?php echo wp_json_encode( __( 'No products found.', 'brikpanel' ) ); ?>,
				remove:          <?php echo wp_json_encode( __( 'Remove', 'brikpanel' ) ); ?>,
				prefilled:       <?php echo wp_json_encode( __( 'Defaults applied from supplier profile.', 'brikpanel' ) ); ?>,
				empty_items:     <?php echo wp_json_encode( __( 'No items yet — search above to add a product.', 'brikpanel' ) ); ?>,
				variation_label: <?php echo wp_json_encode( _x( 'Variation #', 'prefix before a variation id, e.g. "Variation #12"', 'brikpanel' ) ); ?>,
			}
		};
		</script>
		<?php
	}

	private function seed_items_for_js( $items ) {
		$out = [];
		foreach ( (array) $items as $r ) {
			$out[] = [
				'id'           => (int) $r->id,
				'product_id'   => (int) $r->product_id,
				'variation_id' => (int) $r->variation_id,
				'title'        => $r->title,
				'sku'          => $r->sku,
				'qty_ordered'  => (float) $r->qty_ordered,
				'qty_received' => (float) $r->qty_received,
				'unit_cost'    => (float) $r->unit_cost,
				'line_total'   => (float) $r->line_total,
			];
		}
		return $out;
	}

	// =========================================================================
	// AJAX: list
	// =========================================================================

	public function ajax_list() {
		$this->check_auth();
		global $wpdb;

		$search    = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		$status    = sanitize_key( $_POST['status'] ?? '' );
		$date_from = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
		$date_to   = sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) );
		$page      = max( 1, absint( $_POST['page'] ?? 1 ) );
		$per_page  = 25;

		$so_t = $wpdb->prefix . self::TABLE;
		$v_t  = $wpdb->prefix . Brikpanel_Vendors::TABLE;

		$where  = [];
		$params = [];

		if ( $status !== '' ) {
			$where[]  = 's.status = %s';
			$params[] = $status;
		}
		if ( $search !== '' ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(s.reference LIKE %s OR v.name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		if ( $date_from !== '' ) {
			$where[]  = '(s.order_date >= %s OR (s.order_date IS NULL AND s.created_at >= %s))';
			$params[] = $date_from;
			$params[] = $date_from . ' 00:00:00';
		}
		if ( $date_to !== '' ) {
			$where[]  = '(s.order_date <= %s OR (s.order_date IS NULL AND s.created_at <= %s))';
			$params[] = $date_to;
			$params[] = $date_to . ' 23:59:59';
		}
		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$count_sql = "SELECT COUNT(*) FROM {$so_t} s LEFT JOIN {$v_t} v ON v.id = s.vendor_id {$where_sql}";
		$count = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore

		$offset   = ( $page - 1 ) * $per_page;
		$list_sql = "
			SELECT s.*, v.name AS vendor_name
			FROM {$so_t} s
			LEFT JOIN {$v_t} v ON v.id = s.vendor_id
			{$where_sql}
			ORDER BY (s.status = '" . self::STATUS_DRAFT . "') DESC,
			         (s.status IN ('" . self::STATUS_ORDERED . "','" . self::STATUS_PARTIAL . "')) DESC,
			         COALESCE(s.order_date, DATE(s.created_at)) DESC,
			         s.id DESC
			LIMIT %d OFFSET %d
		";
		$list_params = array_merge( $params, [ $per_page, $offset ] );
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) ); // phpcs:ignore

		// Summary across all (not paginated)
		$cutoff = gmdate( 'Y-m-d', strtotime( '-90 days' ) );
		$summary = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COALESCE( SUM( CASE WHEN status IN ('ordered','partially_received') THEN 1 ELSE 0 END ), 0 ) AS open_count,
				COALESCE( SUM( CASE WHEN status IN ('ordered','partially_received') THEN total ELSE 0 END ), 0 ) AS open_value,
				COALESCE( SUM( CASE WHEN status='received' AND received_date >= %s THEN total ELSE 0 END ), 0 ) AS recv_90d
			 FROM {$so_t}",
			$cutoff
		) ); // phpcs:ignore

		$items = [];
		foreach ( $rows as $r ) {
			$items[] = [
				'id'            => (int) $r->id,
				'reference'     => $r->reference,
				'vendor_id'     => (int) $r->vendor_id,
				'vendor_name'   => $r->vendor_name ?: __( '— Unassigned —', 'brikpanel' ),
				'status'        => $r->status,
				'status_label'  => $this->status_label( $r->status ),
				'order_date'    => $r->order_date,
				'expected_date' => $r->expected_date,
				'received_date' => $r->received_date,
				'total'         => (float) $r->total,
				'total_fmt'     => $this->money_fmt( (float) $r->total ),
			];
		}

		wp_send_json_success( [
			'items'       => $items,
			'total_count' => $count,
			'page'        => $page,
			'pages'       => max( 1, (int) ceil( $count / $per_page ) ),
			'summary'     => [
				'open_count'    => $summary ? (int) $summary->open_count : 0,
				'open_value'    => $summary ? (float) $summary->open_value : 0.0,
				'open_value_fmt'=> $this->money_fmt( $summary ? (float) $summary->open_value : 0.0 ),
				'recv_90d'      => $summary ? (float) $summary->recv_90d : 0.0,
				'recv_90d_fmt'  => $this->money_fmt( $summary ? (float) $summary->recv_90d : 0.0 ),
			],
		] );
	}

	// =========================================================================
	// AJAX: get one (with items)
	// =========================================================================

	public function ajax_get() {
		$this->check_auth();
		global $wpdb;
		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'brikpanel' ) ] );
		}
		$so_t  = $wpdb->prefix . self::TABLE;
		$it_t  = $wpdb->prefix . self::ITEMS_TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$so_t} WHERE id = %d", $id ) ); // phpcs:ignore
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Not found.', 'brikpanel' ) ] );
		}
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$it_t} WHERE order_id = %d ORDER BY sort_order ASC, id ASC", $id ) ); // phpcs:ignore

		wp_send_json_success( [
			'po'    => (array) $row,
			'items' => $this->seed_items_for_js( $items ),
		] );
	}

	// =========================================================================
	// AJAX: save (insert or update header + replace line items)
	// =========================================================================

	public function ajax_save() {
		$this->check_auth();
		global $wpdb;

		$id            = absint( $_POST['id'] ?? 0 );
		$vendor_id     = absint( $_POST['vendor_id'] ?? 0 );
		$reference     = sanitize_text_field( wp_unslash( $_POST['reference'] ?? '' ) );
		$order_date    = sanitize_text_field( wp_unslash( $_POST['order_date'] ?? '' ) );
		$expected_date = sanitize_text_field( wp_unslash( $_POST['expected_date'] ?? '' ) );
		$received_date = sanitize_text_field( wp_unslash( $_POST['received_date'] ?? '' ) );
		$shipping_raw  = sanitize_text_field( wp_unslash( $_POST['shipping_fee'] ?? '0' ) );
		$tax_raw       = sanitize_text_field( wp_unslash( $_POST['tax'] ?? '0' ) );
		$notes         = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
		$items_json    = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '[]';

		$so_t = $wpdb->prefix . self::TABLE;
		$it_t = $wpdb->prefix . self::ITEMS_TABLE;

		$existing = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$so_t} WHERE id = %d", $id ) ) : null; // phpcs:ignore
		if ( $id && ! $existing ) {
			wp_send_json_error( [ 'message' => __( 'Stock order not found.', 'brikpanel' ) ] );
		}
		if ( $existing && in_array( $existing->status, [ self::STATUS_RECEIVED, self::STATUS_CANCELLED ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'This stock order is locked and cannot be edited.', 'brikpanel' ) ] );
		}

		// Validate dates
		foreach ( [ 'order_date', 'expected_date', 'received_date' ] as $key ) {
			$val = $$key;
			if ( $val !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $val ) ) {
				$$key = '';
			}
		}

		// Items
		$items = json_decode( $items_json, true );
		if ( ! is_array( $items ) ) {
			$items = [];
		}

		$shipping = max( 0.0, (float) $shipping_raw );
		$tax      = max( 0.0, (float) $tax_raw );

		$subtotal = 0.0;
		$clean_items = [];
		foreach ( $items as $i => $it ) {
			$pid = absint( $it['product_id'] ?? 0 );
			$vid = absint( $it['variation_id'] ?? 0 );
			$qty = max( 0.0, (float) ( $it['qty_ordered'] ?? 0 ) );
			$qty_recv = max( 0.0, (float) ( $it['qty_received'] ?? 0 ) );
			$cost = max( 0.0, (float) ( $it['unit_cost'] ?? 0 ) );
			if ( $pid === 0 || $qty <= 0 ) {
				continue;
			}
			if ( $qty_recv > $qty ) {
				$qty_recv = $qty;
			}
			$line_total = $qty * $cost;
			$subtotal  += $line_total;

			$clean_items[] = [
				'product_id'   => $pid,
				'variation_id' => $vid,
				'title'        => sanitize_text_field( (string) ( $it['title'] ?? '' ) ),
				'sku'          => sanitize_text_field( (string) ( $it['sku'] ?? '' ) ),
				'qty_ordered'  => $qty,
				'qty_received' => $qty_recv,
				'unit_cost'    => $cost,
				'line_total'   => $line_total,
				'sort_order'   => (int) $i,
			];
		}

		$total = $subtotal + $shipping + $tax;

		// Auto-generate reference for brand-new orders without one.
		if ( ! $id && $reference === '' ) {
			$reference = $this->generate_reference();
		}

		$status = $existing ? $existing->status : self::STATUS_DRAFT;

		$header = [
			'vendor_id'    => $vendor_id,
			'reference'    => $reference,
			'status'       => $status,
			'order_date'   => $order_date !== '' ? $order_date : null,
			'expected_date'=> $expected_date !== '' ? $expected_date : null,
			'received_date'=> $received_date !== '' ? $received_date : null,
			'subtotal'     => $subtotal,
			'shipping_fee' => $shipping,
			'tax'          => $tax,
			'total'        => $total,
			'currency'     => get_woocommerce_currency(),
			'notes'        => $notes,
		];
		$header_format = [ '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s' ];

		if ( $id ) {
			$wpdb->update( $so_t, $header, [ 'id' => $id ], $header_format, [ '%d' ] );
		} else {
			$header['created_by'] = get_current_user_id();
			$header_format[]      = '%d';
			$wpdb->insert( $so_t, $header, $header_format );
			$id = (int) $wpdb->insert_id;
		}

		if ( $wpdb->last_error ) {
			wp_send_json_error( [ 'message' => __( 'Database error: ', 'brikpanel' ) . $wpdb->last_error ] );
		}

		// Replace line items (simpler than diffing — POs are short).
		$wpdb->delete( $it_t, [ 'order_id' => $id ], [ '%d' ] );
		foreach ( $clean_items as $row ) {
			$row['order_id'] = $id;
			$wpdb->insert( $it_t, $row, [ '%d', '%d', '%s', '%s', '%f', '%f', '%f', '%f', '%d', '%d' ] );
		}

		do_action( 'brikpanel_stock_order_saved', $id, $header, $clean_items );

		wp_send_json_success( [ 'id' => $id, 'reference' => $reference, 'total' => $total ] );
	}

	// =========================================================================
	// AJAX: change status (also runs the receive automation)
	// =========================================================================

	public function ajax_change_status() {
		$this->check_auth();
		global $wpdb;

		$id     = absint( $_POST['id'] ?? 0 );
		$target = sanitize_key( $_POST['status'] ?? '' );
		$valid  = [ self::STATUS_DRAFT, self::STATUS_ORDERED, self::STATUS_PARTIAL, self::STATUS_RECEIVED, self::STATUS_CANCELLED ];
		if ( ! $id || ! in_array( $target, $valid, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'brikpanel' ) ] );
		}

		$so_t = $wpdb->prefix . self::TABLE;
		$po   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$so_t} WHERE id = %d", $id ) ); // phpcs:ignore
		if ( ! $po ) {
			wp_send_json_error( [ 'message' => __( 'Stock order not found.', 'brikpanel' ) ] );
		}

		// Locked terminals can\'t be reverted.
		if ( in_array( $po->status, [ self::STATUS_RECEIVED, self::STATUS_CANCELLED ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'This stock order is locked.', 'brikpanel' ) ] );
		}

		$update = [ 'status' => $target ];
		$format = [ '%s' ];

		if ( $target === self::STATUS_ORDERED && empty( $po->order_date ) ) {
			$update['order_date'] = current_time( 'Y-m-d' );
			$format[] = '%s';
		}

		if ( $target === self::STATUS_RECEIVED ) {
			if ( empty( $po->received_date ) ) {
				$update['received_date'] = current_time( 'Y-m-d' );
				$format[] = '%s';
			}
			// Run automations BEFORE writing the new status so a failure doesn\'t
			// leave the PO in a half-applied state.
			$this->run_receive_flow( $po );
		}

		$wpdb->update( $so_t, $update, [ 'id' => $id ], $format, [ '%d' ] );
		if ( $wpdb->last_error ) {
			wp_send_json_error( [ 'message' => __( 'Database error: ', 'brikpanel' ) . $wpdb->last_error ] );
		}

		do_action( 'brikpanel_stock_order_status_changed', $id, $target, $po->status );

		wp_send_json_success( [ 'id' => $id, 'status' => $target ] );
	}

	// =========================================================================
	// AJAX: delete
	// =========================================================================

	public function ajax_delete() {
		$this->check_auth();
		global $wpdb;
		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'brikpanel' ) ] );
		}
		$so_t = $wpdb->prefix . self::TABLE;
		$it_t = $wpdb->prefix . self::ITEMS_TABLE;
		$wpdb->delete( $it_t, [ 'order_id' => $id ], [ '%d' ] );
		$wpdb->delete( $so_t, [ 'id' => $id ], [ '%d' ] );
		do_action( 'brikpanel_stock_order_deleted', $id );
		wp_send_json_success();
	}

	// =========================================================================
	// AJAX: search products / variations for the picker
	// =========================================================================

	public function ajax_search_product() {
		$this->check_auth();

		$term = sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) );
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( [] );
		}

		$results = [];

		// First, query products by title or SKU.
		$args = [
			'post_type'      => 'product',
			'post_status'    => [ 'publish', 'private', 'draft' ],
			'posts_per_page' => 20,
			's'              => $term,
			'fields'         => 'ids',
		];
		$ids = get_posts( $args );

		// Also query variations / products by SKU directly.
		global $wpdb;
		$sku_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s LIMIT 20",
			'%' . $wpdb->esc_like( $term ) . '%'
		) ); // phpcs:ignore

		$candidate_ids = array_unique( array_map( 'absint', array_merge( (array) $ids, (array) $sku_ids ) ) );

		// Track unique product/variation pairs we've already pushed so SKU-hits
		// on a variation don't duplicate when its parent ID is also matched.
		$seen = [];

		foreach ( $candidate_ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}

			// SKU search can return a variation directly. Variations report
			// type='variation' (not 'variable'), so split the three branches
			// explicitly: variation → use parent_id + self_id; variable parent
			// → expand into all variations; everything else → simple product.
			if ( $product->is_type( 'variation' ) ) {
				$parent_id = $product->get_parent_id();
				$parent    = $parent_id ? wc_get_product( $parent_id ) : null;
				$key       = $parent_id . '|' . $product->get_id();
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$results[]    = [
					'product_id'   => $parent_id,
					'variation_id' => $product->get_id(),
					'title'        => ( $parent ? $parent->get_name() : '' ) . ' — ' . wp_strip_all_tags( wc_get_formatted_variation( $product, true ) ),
					'sku'          => $product->get_sku(),
					'cost'         => $this->resolve_existing_cost( $product ),
				];
			} elseif ( $product->is_type( 'variable' ) ) {
				$children = $product->get_children();
				foreach ( $children as $vid ) {
					$variation = wc_get_product( $vid );
					if ( ! $variation ) {
						continue;
					}
					$key = $product->get_id() . '|' . $variation->get_id();
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					$seen[ $key ] = true;
					$results[]    = [
						'product_id'   => $product->get_id(),
						'variation_id' => $variation->get_id(),
						'title'        => $product->get_name() . ' — ' . wp_strip_all_tags( wc_get_formatted_variation( $variation, true ) ),
						'sku'          => $variation->get_sku(),
						'cost'         => $this->resolve_existing_cost( $variation ),
					];
				}
			} else {
				$key = $product->get_id() . '|0';
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$results[]    = [
					'product_id'   => $product->get_id(),
					'variation_id' => 0,
					'title'        => $product->get_name(),
					'sku'          => $product->get_sku(),
					'cost'         => $this->resolve_existing_cost( $product ),
				];
			}
			if ( count( $results ) >= 30 ) {
				break;
			}
		}

		wp_send_json_success( $results );
	}

	// =========================================================================
	// Receive automation (stock + COGS + Expense)
	// =========================================================================

	/**
	 * Run all enabled automations for a PO that's transitioning to received.
	 * Each step is gated on a settings toggle so admins can opt out of any
	 * piece without losing the others.
	 *
	 * @param object $po Row from the stock_orders table.
	 */
	private function run_receive_flow( $po ) {
		global $wpdb;

		$do_stock   = ( 'yes' === get_option( 'brikpanel_po_auto_update_stock', 'yes' ) );
		$do_cogs    = ( 'yes' === get_option( 'brikpanel_po_auto_update_cogs', 'yes' ) );
		$do_expense = ( 'yes' === get_option( 'brikpanel_po_auto_create_expense', 'yes' ) );

		if ( ! $do_stock && ! $do_cogs && ! $do_expense ) {
			return;
		}

		$it_t  = $wpdb->prefix . self::ITEMS_TABLE;
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$it_t} WHERE order_id = %d", (int) $po->id ) ); // phpcs:ignore
		if ( empty( $items ) ) {
			$items = [];
		}

		// Force any unset received qty to the ordered qty when fully receiving.
		// We don\'t touch the row in DB here — caller already triggered the
		// status change; this is a runtime adjustment for the automations only.
		foreach ( $items as $it ) {
			if ( (float) $it->qty_received <= 0 ) {
				$it->qty_received = (float) $it->qty_ordered;
			}
		}

		$cogs_method = (string) get_option( 'brikpanel_po_cogs_method', 'last_cost' );

		$vendor_id = (int) $po->vendor_id;

		foreach ( $items as $it ) {
			$qty_recv = (float) $it->qty_received;
			if ( $qty_recv <= 0 ) {
				continue;
			}
			$target_id = (int) $it->variation_id > 0 ? (int) $it->variation_id : (int) $it->product_id;
			if ( $target_id <= 0 ) {
				continue;
			}
			$product = wc_get_product( $target_id );
			if ( ! $product ) {
				continue;
			}

			if ( $do_stock ) {
				$this->apply_stock_increment( $product, $qty_recv );
			}

			if ( $do_cogs ) {
				$this->apply_cogs_update( $product, (float) $it->unit_cost, $qty_recv, $cogs_method );
			}

			// Stamp the vendor onto the product/variation so per-vendor reports
			// can join on `_brikpanel_vendor_id` without requiring the user to
			// open every product editor manually. The most recent PO wins —
			// matches the real-world case where a SKU\'s primary supplier
			// changes over time.
			if ( $vendor_id > 0 ) {
				update_post_meta( $target_id, Brikpanel_Vendor_Product_Editor::META_VENDOR_ID, $vendor_id );
				// Also stamp on the parent product so the products-list column
				// stays meaningful when only variations are tracked.
				if ( (int) $it->variation_id > 0 && (int) $it->product_id > 0 ) {
					$parent_existing = (int) get_post_meta( (int) $it->product_id, Brikpanel_Vendor_Product_Editor::META_VENDOR_ID, true );
					if ( $parent_existing <= 0 ) {
						update_post_meta( (int) $it->product_id, Brikpanel_Vendor_Product_Editor::META_VENDOR_ID, $vendor_id );
					}
				}
			}
		}

		if ( $do_expense ) {
			$this->create_inventory_expense( $po );
		}

		do_action( 'brikpanel_stock_order_received', (int) $po->id, $po, $items );
	}

	/**
	 * Add `$qty` to the WC stock level for a product or variation. Skips
	 * items that don\'t have stock management enabled (because forcing one
	 * on would change reporting in unexpected ways for the user).
	 */
	private function apply_stock_increment( $product, $qty ) {
		if ( ! $product->managing_stock() ) {
			return;
		}
		$current = (float) $product->get_stock_quantity();
		$new     = $current + (float) $qty;
		// wc_update_product_stock fires the right hooks, refreshes caches and
		// flips `_stock_status` when crossing 0 — preferred over set_stock_quantity.
		wc_update_product_stock( $product, $new, 'set' );
	}

	/**
	 * Update the WC native COGS field (and every other cost meta key the store
	 * uses) based on the chosen calculation method.
	 *
	 * - `last_cost`: overwrite with the unit cost from this PO.
	 * - `weighted` : weighted average across `(existing_stock × existing_cost)`
	 *                + `(new_qty × new_cost)`. Falls back to `last_cost` when
	 *                stock isn\'t managed or the existing cost is zero.
	 */
	private function apply_cogs_update( $product, $unit_cost, $qty_recv, $method ) {
		if ( $unit_cost <= 0 ) {
			return;
		}
		$new_cost = (float) $unit_cost;

		if ( $method === 'weighted' && $product->managing_stock() ) {
			$existing_stock = max( 0.0, (float) $product->get_stock_quantity() );
			$existing_cost  = $this->get_existing_cost( $product );
			// Stock counter has already been incremented by apply_stock_increment(),
			// so subtract this delivery to recover the pre-receive stock level.
			$prior_stock = max( 0.0, $existing_stock - (float) $qty_recv );
			if ( $existing_cost > 0 && $prior_stock > 0 ) {
				$total_value = ( $prior_stock * $existing_cost ) + ( (float) $qty_recv * $new_cost );
				$total_qty   = $prior_stock + (float) $qty_recv;
				if ( $total_qty > 0 ) {
					$new_cost = $total_value / $total_qty;
				}
			}
		}

		$decimal = wc_format_decimal( $new_cost, '' );

		// WC native COGS (9.5+).
		if ( method_exists( $product, 'set_cogs_value' ) ) {
			$product->set_cogs_value( $decimal !== '' ? $decimal : null );
			$product->save();
		}
		// Write the whole cost-key set (BrikPanel legacy + any third-party cost
		// plugin in use) so the received cost lands wherever the store reads it.
		brikpanel_set_product_cogs_raw( $product->get_id(), $decimal );
	}

	private function get_existing_cost( $product ) {
		// Raw meta read (native first, legacy fallback) — unlike
		// get_cogs_value() it keeps working when the WC COGS feature flag
		// is off.
		$meta = brikpanel_product_cogs_raw( $product->get_id() );
		return $meta === '' ? 0.0 : (float) $meta;
	}

	/**
	 * Resolve the cost shown in the product picker — used to prefill unit_cost
	 * when adding a line. Picks WC native COGS first, falls back to legacy.
	 */
	private function resolve_existing_cost( $product ) {
		return $this->get_existing_cost( $product );
	}

	/**
	 * Create an Expenses table entry for the received PO, so net profit
	 * reports stay consistent. Idempotent on `(category, description, amount,
	 * expense_date)` — we look for an existing matching row and skip
	 * insertion to handle the rare case where receive runs twice.
	 */
	private function create_inventory_expense( $po ) {
		global $wpdb;
		$expenses_t = $wpdb->prefix . 'brikpanel_expenses';
		// Sanity: expenses table is part of BrikPanel core, but if the module
		// is somehow missing we silently skip rather than fatal.
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$expenses_t}'" ) !== $expenses_t ) {
			return;
		}

		$category    = (string) get_option( 'brikpanel_po_expense_category', 'Inventory' );
		$category    = $category !== '' ? $category : 'Inventory';
		$ref         = $po->reference !== '' ? $po->reference : sprintf( 'PO #%d', (int) $po->id );
		$vendor_name = '';
		if ( (int) $po->vendor_id > 0 ) {
			$v_t         = $wpdb->prefix . Brikpanel_Vendors::TABLE;
			$vendor_name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$v_t} WHERE id = %d", (int) $po->vendor_id ) ); // phpcs:ignore
		}
		$description = sprintf( '%s — %s', $ref, $vendor_name !== '' ? $vendor_name : __( 'Stock order', 'brikpanel' ) );
		$amount      = (float) $po->total;
		$date        = $po->received_date ?: current_time( 'Y-m-d' );

		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$expenses_t}
			 WHERE expense_date = %s AND category = %s AND description = %s AND ABS(amount - %f) < 0.0001
			 LIMIT 1",
			$date,
			$category,
			$description,
			$amount
		) ); // phpcs:ignore
		if ( $exists ) {
			return;
		}

		$wpdb->insert(
			$expenses_t,
			[
				'expense_date' => $date,
				'category'     => $category,
				'description'  => $description,
				'amount'       => $amount,
				'recurring'    => 'none',
			],
			[ '%s', '%s', '%s', '%f', '%s' ]
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function status_label( $status ) {
		switch ( $status ) {
			case self::STATUS_DRAFT:     return __( 'Draft', 'brikpanel' );
			case self::STATUS_ORDERED:   return __( 'Ordered', 'brikpanel' );
			case self::STATUS_PARTIAL:   return __( 'Partially received', 'brikpanel' );
			case self::STATUS_RECEIVED:  return __( 'Received', 'brikpanel' );
			case self::STATUS_CANCELLED: return __( 'Cancelled', 'brikpanel' );
		}
		return $status;
	}

	private function generate_reference() {
		global $wpdb;
		$prefix = (string) get_option( 'brikpanel_po_reference_prefix', 'PO' );
		$prefix = $prefix !== '' ? $prefix : 'PO';
		$year   = gmdate( 'Y' );
		$so_t   = $wpdb->prefix . self::TABLE;
		// Find the highest sequential number used this year for this prefix.
		$pattern = $wpdb->esc_like( $prefix . '-' . $year . '-' ) . '%';
		$max     = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX( CAST( SUBSTRING_INDEX(reference, '-', -1) AS UNSIGNED ) )
			 FROM {$so_t}
			 WHERE reference LIKE %s",
			$pattern
		) ); // phpcs:ignore
		$next = max( 1, $max + 1 );
		return sprintf( '%s-%s-%04d', $prefix, $year, $next );
	}

	private function money_fmt( $amount ) {
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return number_format_i18n( (float) $amount, 2 );
	}
}
