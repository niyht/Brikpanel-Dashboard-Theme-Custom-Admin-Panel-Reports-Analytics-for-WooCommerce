<?php
/**
 * BrikPanel — Vendors (suppliers / procurement) CRUD page.
 *
 * Lists, creates, edits, archives and deletes vendor records stored in
 * `wp_brikpanel_vendors`. The list view augments each row with live stats
 * pulled from the related stock_orders table — total spend (last 90d),
 * average lead time (received_date - order_date) and active PO count —
 * computed in a single SQL pass to avoid N+1 queries.
 *
 * Mirrors the design language of the Expenses page (Shopify-style modal,
 * monochrome cards, AJAX-only) so the admin experience stays consistent.
 *
 * @package BrikPanel
 * @since   2.7.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Vendors {

	const PAGE_SLUG    = 'brikpanel-vendors';
	const NONCE_ACTION = 'brikpanel_vendors_nonce';
	const TABLE        = 'brikpanel_vendors';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_brikpanel_vendors_list',     [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_brikpanel_vendors_get',      [ $this, 'ajax_get' ] );
		add_action( 'wp_ajax_brikpanel_vendors_save',     [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_brikpanel_vendors_delete',   [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_brikpanel_vendors_search',   [ $this, 'ajax_search' ] );
		add_action( 'wp_ajax_brikpanel_vendors_defaults', [ $this, 'ajax_defaults' ] );
		add_action( 'wp_ajax_brikpanel_vendors_detail',   [ $this, 'ajax_detail' ] );
	}

	// =========================================================================
	// Page registration
	// =========================================================================

	public function register_page() {
		// Top-level "Vendors" menu in the WP admin sidebar. The whole feature
		// has its own home so admins don't have to dig into WooCommerce.
		add_menu_page(
			__( 'Suppliers', 'brikpanel' ),
			__( 'Suppliers', 'brikpanel' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'dashicons-store',
			3.55 // top of the sidebar; nav.php pins it just under Dashboard.
		);
		// Override the auto-generated first submenu (which would just say
		// "Vendors") so the parent and child labels stay in sync. Stock Orders
		// hooks itself onto this same parent slug, see Brikpanel_Stock_Orders.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Suppliers', 'brikpanel' ),
			__( 'Suppliers', 'brikpanel' ),
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
			'brikpanel-vendors',
			$base . 'brikpanel-vendors.css',
			[],
			file_exists( $path . 'brikpanel-vendors.css' ) ? filemtime( $path . 'brikpanel-vendors.css' ) : BRIKPANEL_VERSION
		);
		wp_enqueue_script(
			'brikpanel-vendors',
			$base . 'brikpanel-vendors.js',
			[],
			file_exists( $path . 'brikpanel-vendors.js' ) ? filemtime( $path . 'brikpanel-vendors.js' ) : BRIKPANEL_VERSION,
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
	// Render page
	// =========================================================================

	public function render_page() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$id     = isset( $_GET['id'] )     ? absint( $_GET['id'] )                        : 0;

		if ( $action === 'detail' && $id > 0 ) {
			$this->render_detail_page( $id );
			return;
		}

		$this->render_list_page();
	}

	/**
	 * List/CRUD view (default landing).
	 */
	private function render_list_page() {
		// Decode the WC currency entity (e.g. `&#36;` → `$`) so it renders in
		// JS textContent without the raw HTML entity bleeding through.
		$currency = function_exists( 'get_woocommerce_currency_symbol' )
			? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
			: '$';
		$nonce    = wp_create_nonce( self::NONCE_ACTION );
		$ajax_url = admin_url( 'admin-ajax.php' );
		$so_url   = admin_url( 'admin.php?page=' . Brikpanel_Stock_Orders::PAGE_SLUG );
		$detail_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=detail&id=' );
		?>
		<div class="wrap brikpanel-ven-wrap" id="brikpanel-vendors">
			<div class="brikpanel-ven-header">
				<div class="brikpanel-ven-header-left">
					<h1><?php esc_html_e( 'Suppliers', 'brikpanel' ); ?></h1>
					<p class="brikpanel-ven-subtitle"><?php esc_html_e( 'Manage your suppliers and track per-supplier spend and lead time.', 'brikpanel' ); ?></p>
				</div>
				<div class="brikpanel-ven-header-right">
					<button type="button" class="brikpanel-ven-btn brikpanel-ven-btn-primary" id="brikpanel-ven-add-btn">
						+ <?php esc_html_e( 'Add supplier', 'brikpanel' ); ?>
					</button>
				</div>
			</div>

			<!-- Summary bar -->
			<div class="brikpanel-ven-summary" id="brikpanel-ven-summary">
				<div class="brikpanel-ven-summary-card">
					<div class="brikpanel-ven-summary-label"><?php esc_html_e( 'Active suppliers', 'brikpanel' ); ?></div>
					<div class="brikpanel-ven-summary-value" id="brikpanel-ven-active-count">—</div>
				</div>
				<div class="brikpanel-ven-summary-card">
					<div class="brikpanel-ven-summary-label"><?php esc_html_e( 'Spend (last 90d)', 'brikpanel' ); ?></div>
					<div class="brikpanel-ven-summary-value" id="brikpanel-ven-spend-90d">—</div>
				</div>
				<div class="brikpanel-ven-summary-card">
					<div class="brikpanel-ven-summary-label"><?php esc_html_e( 'Open POs', 'brikpanel' ); ?></div>
					<div class="brikpanel-ven-summary-value" id="brikpanel-ven-open-pos">—</div>
				</div>
			</div>

			<!-- Search / filters -->
			<div class="brikpanel-ven-card brikpanel-ven-filters">
				<div class="brikpanel-ven-filter-row">
					<div class="brikpanel-ven-field brikpanel-ven-field-grow">
						<label for="brikpanel-ven-search"><?php esc_html_e( 'Search', 'brikpanel' ); ?></label>
						<input type="search" id="brikpanel-ven-search" placeholder="<?php esc_attr_e( 'Name, contact, email…', 'brikpanel' ); ?>" autocomplete="off" />
					</div>
					<div class="brikpanel-ven-field">
						<label for="brikpanel-ven-status-filter"><?php esc_html_e( 'Status', 'brikpanel' ); ?></label>
						<select id="brikpanel-ven-status-filter">
							<option value="active"><?php esc_html_e( 'Active', 'brikpanel' ); ?></option>
							<option value="archived"><?php esc_html_e( 'Archived', 'brikpanel' ); ?></option>
							<option value="all"><?php esc_html_e( 'All', 'brikpanel' ); ?></option>
						</select>
					</div>
				</div>
			</div>

			<!-- Table -->
			<div class="brikpanel-ven-card brikpanel-ven-table-card">
				<div class="brikpanel-ven-table-wrap">
					<table class="brikpanel-ven-table" id="brikpanel-ven-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Supplier', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Contact', 'brikpanel' ); ?></th>
								<th class="brikpanel-ven-num"><?php esc_html_e( 'Spend (90d)', 'brikpanel' ); ?></th>
								<th class="brikpanel-ven-num"><?php esc_html_e( 'Avg. lead time', 'brikpanel' ); ?></th>
								<th class="brikpanel-ven-num"><?php esc_html_e( 'Open POs', 'brikpanel' ); ?></th>
								<th class="brikpanel-ven-actions-th"></th>
							</tr>
						</thead>
						<tbody id="brikpanel-ven-tbody">
							<tr><td colspan="6" class="brikpanel-ven-empty"><?php esc_html_e( 'Loading…', 'brikpanel' ); ?></td></tr>
						</tbody>
					</table>
				</div>
				<div class="brikpanel-ven-pagination" id="brikpanel-ven-pagination" hidden>
					<button type="button" class="brikpanel-ven-btn brikpanel-ven-btn-secondary" id="brikpanel-ven-prev">&larr; <?php esc_html_e( 'Previous', 'brikpanel' ); ?></button>
					<span class="brikpanel-ven-page-info" id="brikpanel-ven-page-info">1 / 1</span>
					<button type="button" class="brikpanel-ven-btn brikpanel-ven-btn-secondary" id="brikpanel-ven-next"><?php esc_html_e( 'Next', 'brikpanel' ); ?> &rarr;</button>
				</div>
			</div>

			<!-- Add / Edit modal -->
			<div class="brikpanel-ven-overlay" id="brikpanel-ven-overlay" hidden>
				<div class="brikpanel-ven-modal" role="dialog" aria-modal="true" aria-labelledby="brikpanel-ven-modal-title">
					<div class="brikpanel-ven-modal-header">
						<h2 id="brikpanel-ven-modal-title"><?php esc_html_e( 'Add supplier', 'brikpanel' ); ?></h2>
						<button type="button" class="brikpanel-ven-modal-close" id="brikpanel-ven-modal-close" aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">&times;</button>
					</div>
					<form id="brikpanel-ven-form" autocomplete="off">
						<input type="hidden" id="brikpanel-ven-edit-id" value="" />
						<div class="brikpanel-ven-modal-body">
							<div class="brikpanel-ven-modal-grid">
								<div class="brikpanel-ven-field brikpanel-ven-field-full">
									<label for="brikpanel-ven-name"><?php esc_html_e( 'Supplier name', 'brikpanel' ); ?> <span class="brikpanel-ven-req">*</span></label>
									<input type="text" id="brikpanel-ven-name" required maxlength="190" placeholder="<?php esc_attr_e( 'e.g. Acme Wholesale Co.', 'brikpanel' ); ?>" />
								</div>
								<div class="brikpanel-ven-field">
									<label for="brikpanel-ven-contact"><?php esc_html_e( 'Contact name', 'brikpanel' ); ?></label>
									<input type="text" id="brikpanel-ven-contact" maxlength="190" />
								</div>
								<div class="brikpanel-ven-field">
									<label for="brikpanel-ven-email"><?php esc_html_e( 'Email', 'brikpanel' ); ?></label>
									<input type="email" id="brikpanel-ven-email" maxlength="190" />
								</div>
								<div class="brikpanel-ven-field">
									<label for="brikpanel-ven-phone"><?php esc_html_e( 'Phone', 'brikpanel' ); ?></label>
									<input type="text" id="brikpanel-ven-phone" maxlength="60" />
								</div>
								<div class="brikpanel-ven-field">
									<label for="brikpanel-ven-website"><?php esc_html_e( 'Website', 'brikpanel' ); ?></label>
									<input type="url" id="brikpanel-ven-website" maxlength="255" placeholder="https://" />
								</div>
								<div class="brikpanel-ven-field">
									<label for="brikpanel-ven-tax-id"><?php esc_html_e( 'Tax ID / VAT', 'brikpanel' ); ?></label>
									<input type="text" id="brikpanel-ven-tax-id" maxlength="60" />
								</div>
								<div class="brikpanel-ven-field">
									<label for="brikpanel-ven-lead-time"><?php esc_html_e( 'Default lead time (days)', 'brikpanel' ); ?></label>
									<input type="number" id="brikpanel-ven-lead-time" min="0" step="1" placeholder="0" />
								</div>
								<div class="brikpanel-ven-field">
									<label for="brikpanel-ven-shipping-fee"><?php esc_html_e( 'Default shipping fee', 'brikpanel' ); ?></label>
									<div class="brikpanel-ven-input-group">
										<span class="brikpanel-ven-prefix"><?php echo esc_html( $currency ); ?></span>
										<input type="number" id="brikpanel-ven-shipping-fee" min="0" step="0.01" placeholder="0.00" />
									</div>
								</div>
								<div class="brikpanel-ven-field brikpanel-ven-field-full">
									<label for="brikpanel-ven-address"><?php esc_html_e( 'Address', 'brikpanel' ); ?></label>
									<textarea id="brikpanel-ven-address" rows="2"></textarea>
								</div>
								<div class="brikpanel-ven-field brikpanel-ven-field-full">
									<label for="brikpanel-ven-notes"><?php esc_html_e( 'Notes', 'brikpanel' ); ?></label>
									<textarea id="brikpanel-ven-notes" rows="2" placeholder="<?php esc_attr_e( 'Internal notes — payment terms, packaging preferences…', 'brikpanel' ); ?>"></textarea>
								</div>
								<div class="brikpanel-ven-field brikpanel-ven-field-full">
									<label class="brikpanel-ven-checkbox-label">
										<input type="checkbox" id="brikpanel-ven-active" checked />
										<span><?php esc_html_e( 'Active supplier (uncheck to archive without deleting)', 'brikpanel' ); ?></span>
									</label>
								</div>
							</div>
						</div>
						<div class="brikpanel-ven-modal-footer">
							<button type="button" class="brikpanel-ven-btn brikpanel-ven-btn-secondary" id="brikpanel-ven-cancel-btn">
								<?php esc_html_e( 'Cancel', 'brikpanel' ); ?>
							</button>
							<button type="submit" class="brikpanel-ven-btn brikpanel-ven-btn-primary" id="brikpanel-ven-submit-btn">
								<?php esc_html_e( 'Save', 'brikpanel' ); ?>
							</button>
						</div>
					</form>
				</div>
			</div>
		</div>

		<script>
		window.brikpanelVendors = {
			mode:       'list',
			ajax_url:   <?php echo wp_json_encode( esc_url_raw( $ajax_url ) ); ?>,
			nonce:      <?php echo wp_json_encode( $nonce ); ?>,
			currency:   <?php echo wp_json_encode( $currency ); ?>,
			so_url:     <?php echo wp_json_encode( esc_url_raw( $so_url ) ); ?>,
			detail_url: <?php echo wp_json_encode( esc_url_raw( $detail_url ) ); ?>,
			i18n: {
				confirm_delete:   <?php echo wp_json_encode( __( 'Delete this supplier? Linked products and POs will keep working but will be unassigned.', 'brikpanel' ) ); ?>,
				error:            <?php echo wp_json_encode( __( 'Something went wrong.', 'brikpanel' ) ); ?>,
				no_vendors:       <?php echo wp_json_encode( __( 'No suppliers yet — click "Add supplier" to create your first.', 'brikpanel' ) ); ?>,
				edit_title:       <?php echo wp_json_encode( __( 'Edit supplier', 'brikpanel' ) ); ?>,
				add_title:        <?php echo wp_json_encode( __( 'Add supplier', 'brikpanel' ) ); ?>,
				name_required:    <?php echo wp_json_encode( __( 'Supplier name is required.', 'brikpanel' ) ); ?>,
				saved:            <?php echo wp_json_encode( __( 'Supplier saved.', 'brikpanel' ) ); ?>,
				deleted:          <?php echo wp_json_encode( __( 'Supplier deleted.', 'brikpanel' ) ); ?>,
				days_short:       <?php echo wp_json_encode( __( 'd', 'brikpanel' ) ); ?>,
				not_enough_data:  <?php echo wp_json_encode( __( '—', 'brikpanel' ) ); ?>,
				active:           <?php echo wp_json_encode( __( 'Active', 'brikpanel' ) ); ?>,
				archived:         <?php echo wp_json_encode( __( 'Archived', 'brikpanel' ) ); ?>,
				view:             <?php echo wp_json_encode( __( 'View', 'brikpanel' ) ); ?>,
				edit:             <?php echo wp_json_encode( __( 'Edit', 'brikpanel' ) ); ?>,
				delete:           <?php echo wp_json_encode( __( 'Delete', 'brikpanel' ) ); ?>,
				ship:             <?php echo wp_json_encode( __( 'Shipping', 'brikpanel' ) ); ?>,
				default_shipping: <?php echo wp_json_encode( __( 'Default shipping:', 'brikpanel' ) ); ?>,
			}
		};
		</script>
		<?php
	}

	// =========================================================================
	// Detail page — vendor profile with stats, recent POs, sourced products,
	// and a 12-month spend trend.
	// =========================================================================

	private function render_detail_page( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$vendor = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
		if ( ! $vendor ) {
			echo '<div class="wrap brikpanel-ven-wrap"><div class="brikpanel-ven-card" style="padding:1.5rem;">';
			echo esc_html__( 'Supplier not found.', 'brikpanel' );
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Back to suppliers', 'brikpanel' ) . '</a>';
			echo '</div></div>';
			return;
		}

		$nonce      = wp_create_nonce( self::NONCE_ACTION );
		$ajax_url   = admin_url( 'admin-ajax.php' );
		$back_url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$so_url     = admin_url( 'admin.php?page=' . Brikpanel_Stock_Orders::PAGE_SLUG );
		$so_new_url = $so_url . '&action=new&vendor_id=' . $id;
		$so_edit_url = $so_url . '&action=edit&id=';
		$currency   = function_exists( 'get_woocommerce_currency_symbol' )
			? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
			: '$';

		$archived_pill = '';
		if ( ! (int) $vendor->is_active ) {
			$archived_pill = ' <span class="brikpanel-ven-archived-pill">' . esc_html__( 'Archived', 'brikpanel' ) . '</span>';
		}

		$contact_bits = array_filter( [
			$vendor->contact_name,
			$vendor->email,
			$vendor->phone,
		] );
		?>
		<div class="wrap brikpanel-ven-wrap brikpanel-ven-detail-wrap" id="brikpanel-vendors">
			<div class="brikpanel-ven-detail-topbar">
				<a class="brikpanel-ven-back" href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Suppliers', 'brikpanel' ); ?></a>
			</div>

			<div class="brikpanel-ven-detail-header">
				<div class="brikpanel-ven-detail-header-left">
					<h1>
						<?php echo esc_html( $vendor->name ); ?>
						<?php echo $archived_pill; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — safe pre-built HTML ?>
					</h1>
					<?php if ( $contact_bits ) : ?>
						<p class="brikpanel-ven-subtitle"><?php echo esc_html( implode( ' · ', $contact_bits ) ); ?></p>
					<?php endif; ?>
				</div>
				<div class="brikpanel-ven-detail-header-right">
					<a class="brikpanel-ven-btn brikpanel-ven-btn-secondary" href="<?php echo esc_url( $so_new_url ); ?>"><?php esc_html_e( 'New stock order', 'brikpanel' ); ?></a>
					<button type="button" class="brikpanel-ven-btn brikpanel-ven-btn-secondary" id="brikpanel-ven-detail-edit-btn"><?php esc_html_e( 'Edit supplier', 'brikpanel' ); ?></button>
				</div>
			</div>

			<!-- Stats grid -->
			<div class="brikpanel-ven-detail-stats" id="brikpanel-ven-detail-stats">
				<div class="brikpanel-ven-summary-card"><div class="brikpanel-ven-summary-label"><?php esc_html_e( 'Lifetime spend', 'brikpanel' ); ?></div><div class="brikpanel-ven-summary-value" data-stat="lifetime_spend">—</div></div>
				<div class="brikpanel-ven-summary-card"><div class="brikpanel-ven-summary-label"><?php esc_html_e( 'Spend (90d)', 'brikpanel' ); ?></div><div class="brikpanel-ven-summary-value" data-stat="spend_90d">—</div></div>
				<div class="brikpanel-ven-summary-card"><div class="brikpanel-ven-summary-label"><?php esc_html_e( 'Open POs', 'brikpanel' ); ?></div><div class="brikpanel-ven-summary-value" data-stat="open_pos">—</div><div class="brikpanel-ven-summary-sub" data-stat-sub="open_value"></div></div>
				<div class="brikpanel-ven-summary-card"><div class="brikpanel-ven-summary-label"><?php esc_html_e( 'Avg. lead time', 'brikpanel' ); ?></div><div class="brikpanel-ven-summary-value" data-stat="avg_lead_time">—</div><div class="brikpanel-ven-summary-sub" data-stat-sub="default_lead_time"></div></div>
				<div class="brikpanel-ven-summary-card"><div class="brikpanel-ven-summary-label"><?php esc_html_e( 'Products sourced', 'brikpanel' ); ?></div><div class="brikpanel-ven-summary-value" data-stat="products_count">—</div></div>
			</div>

			<!-- Two-column layout -->
			<div class="brikpanel-ven-detail-grid">
				<!-- Left column: vendor info -->
				<div class="brikpanel-ven-card brikpanel-ven-detail-info">
					<header class="brikpanel-ven-card__header">
						<h2><?php esc_html_e( 'Supplier info', 'brikpanel' ); ?></h2>
					</header>
					<div class="brikpanel-ven-card__body">
						<dl class="brikpanel-ven-info-list">
							<?php if ( $vendor->website ) : ?>
								<dt><?php esc_html_e( 'Website', 'brikpanel' ); ?></dt>
								<dd><a href="<?php echo esc_url( $vendor->website ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $vendor->website ); ?></a></dd>
							<?php endif; ?>
							<?php if ( $vendor->tax_id ) : ?>
								<dt><?php esc_html_e( 'Tax ID / VAT', 'brikpanel' ); ?></dt>
								<dd><?php echo esc_html( $vendor->tax_id ); ?></dd>
							<?php endif; ?>
							<?php if ( $vendor->address ) : ?>
								<dt><?php esc_html_e( 'Address', 'brikpanel' ); ?></dt>
								<dd><?php echo nl2br( esc_html( $vendor->address ) ); ?></dd>
							<?php endif; ?>
							<dt><?php esc_html_e( 'Default lead time', 'brikpanel' ); ?></dt>
							<dd><?php echo (int) $vendor->default_lead_time_days > 0
								? esc_html( sprintf( _n( '%d day', '%d days', (int) $vendor->default_lead_time_days, 'brikpanel' ), (int) $vendor->default_lead_time_days ) )
								: '<span class="brikpanel-ven-muted">—</span>'; ?></dd>
							<dt><?php esc_html_e( 'Default shipping fee', 'brikpanel' ); ?></dt>
							<dd><?php echo (float) $vendor->default_shipping_fee > 0
								? esc_html( $this->money_fmt( (float) $vendor->default_shipping_fee ) )
								: '<span class="brikpanel-ven-muted">—</span>'; ?></dd>
							<?php if ( $vendor->notes ) : ?>
								<dt><?php esc_html_e( 'Notes', 'brikpanel' ); ?></dt>
								<dd class="brikpanel-ven-info-notes"><?php echo nl2br( esc_html( $vendor->notes ) ); ?></dd>
							<?php endif; ?>
						</dl>
					</div>
				</div>

				<!-- Right column: spend trend chart -->
				<div class="brikpanel-ven-card brikpanel-ven-detail-chart">
					<header class="brikpanel-ven-card__header">
						<h2><?php esc_html_e( 'Spend trend (12 months)', 'brikpanel' ); ?></h2>
						<p class="brikpanel-ven-card__desc"><?php esc_html_e( 'Total received PO value per month.', 'brikpanel' ); ?></p>
					</header>
					<div class="brikpanel-ven-card__body">
						<div class="brikpanel-ven-chart" id="brikpanel-ven-spend-chart" aria-label="<?php esc_attr_e( 'Monthly spend chart', 'brikpanel' ); ?>"></div>
						<div class="brikpanel-ven-chart-empty" id="brikpanel-ven-spend-chart-empty" hidden><?php esc_html_e( 'No received POs yet.', 'brikpanel' ); ?></div>
					</div>
				</div>
			</div>

			<!-- Recent POs -->
			<div class="brikpanel-ven-card">
				<header class="brikpanel-ven-card__header brikpanel-ven-card__header--row">
					<h2><?php esc_html_e( 'Recent stock orders', 'brikpanel' ); ?></h2>
					<a class="brikpanel-ven-card__link" href="<?php echo esc_url( $so_url . '&search=' . rawurlencode( $vendor->name ) ); ?>"><?php esc_html_e( 'View all →', 'brikpanel' ); ?></a>
				</header>
				<div class="brikpanel-ven-table-wrap">
					<table class="brikpanel-ven-table" id="brikpanel-ven-detail-pos">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Reference', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Status', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Ordered', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Received', 'brikpanel' ); ?></th>
								<th class="brikpanel-ven-num"><?php esc_html_e( 'Lead', 'brikpanel' ); ?></th>
								<th class="brikpanel-ven-num"><?php esc_html_e( 'Total', 'brikpanel' ); ?></th>
							</tr>
						</thead>
						<tbody id="brikpanel-ven-detail-pos-tbody">
							<tr><td colspan="6" class="brikpanel-ven-empty"><?php esc_html_e( 'Loading…', 'brikpanel' ); ?></td></tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Sourced products -->
			<div class="brikpanel-ven-card">
				<header class="brikpanel-ven-card__header">
					<h2><?php esc_html_e( 'Products sourced from this supplier', 'brikpanel' ); ?></h2>
					<p class="brikpanel-ven-card__desc"><?php esc_html_e( 'Products and variations whose supplier is set to this supplier.', 'brikpanel' ); ?></p>
				</header>
				<div class="brikpanel-ven-table-wrap">
					<table class="brikpanel-ven-table" id="brikpanel-ven-detail-products">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Product', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'SKU', 'brikpanel' ); ?></th>
								<th class="brikpanel-ven-num"><?php esc_html_e( 'Stock', 'brikpanel' ); ?></th>
								<th class="brikpanel-ven-num"><?php esc_html_e( 'Cost', 'brikpanel' ); ?></th>
								<th class="brikpanel-ven-num"><?php esc_html_e( 'Price', 'brikpanel' ); ?></th>
							</tr>
						</thead>
						<tbody id="brikpanel-ven-detail-products-tbody">
							<tr><td colspan="5" class="brikpanel-ven-empty"><?php esc_html_e( 'Loading…', 'brikpanel' ); ?></td></tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Edit modal (reused from list page markup) -->
			<div class="brikpanel-ven-overlay" id="brikpanel-ven-overlay" hidden>
				<div class="brikpanel-ven-modal" role="dialog" aria-modal="true" aria-labelledby="brikpanel-ven-modal-title">
					<div class="brikpanel-ven-modal-header">
						<h2 id="brikpanel-ven-modal-title"><?php esc_html_e( 'Edit supplier', 'brikpanel' ); ?></h2>
						<button type="button" class="brikpanel-ven-modal-close" id="brikpanel-ven-modal-close" aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">&times;</button>
					</div>
					<form id="brikpanel-ven-form" autocomplete="off">
						<input type="hidden" id="brikpanel-ven-edit-id" value="<?php echo esc_attr( (string) $id ); ?>" />
						<div class="brikpanel-ven-modal-body">
							<div class="brikpanel-ven-modal-grid">
								<div class="brikpanel-ven-field brikpanel-ven-field-full">
									<label for="brikpanel-ven-name"><?php esc_html_e( 'Supplier name', 'brikpanel' ); ?> <span class="brikpanel-ven-req">*</span></label>
									<input type="text" id="brikpanel-ven-name" required maxlength="190" />
								</div>
								<div class="brikpanel-ven-field">
									<label for="brikpanel-ven-contact"><?php esc_html_e( 'Contact name', 'brikpanel' ); ?></label>
									<input type="text" id="brikpanel-ven-contact" maxlength="190" />
								</div>
								<div class="brikpanel-ven-field">
									<label for="brikpanel-ven-email"><?php esc_html_e( 'Email', 'brikpanel' ); ?></label>
									<input type="email" id="brikpanel-ven-email" maxlength="190" />
								</div>
								<div class="brikpanel-ven-field">
									<label for="brikpanel-ven-phone"><?php esc_html_e( 'Phone', 'brikpanel' ); ?></label>
									<input type="text" id="brikpanel-ven-phone" maxlength="60" />
								</div>
								<div class="brikpanel-ven-field">
									<label for="brikpanel-ven-website"><?php esc_html_e( 'Website', 'brikpanel' ); ?></label>
									<input type="url" id="brikpanel-ven-website" maxlength="255" placeholder="https://" />
								</div>
								<div class="brikpanel-ven-field">
									<label for="brikpanel-ven-tax-id"><?php esc_html_e( 'Tax ID / VAT', 'brikpanel' ); ?></label>
									<input type="text" id="brikpanel-ven-tax-id" maxlength="60" />
								</div>
								<div class="brikpanel-ven-field">
									<label for="brikpanel-ven-lead-time"><?php esc_html_e( 'Default lead time (days)', 'brikpanel' ); ?></label>
									<input type="number" id="brikpanel-ven-lead-time" min="0" step="1" placeholder="0" />
								</div>
								<div class="brikpanel-ven-field">
									<label for="brikpanel-ven-shipping-fee"><?php esc_html_e( 'Default shipping fee', 'brikpanel' ); ?></label>
									<div class="brikpanel-ven-input-group">
										<span class="brikpanel-ven-prefix"><?php echo esc_html( $currency ); ?></span>
										<input type="number" id="brikpanel-ven-shipping-fee" min="0" step="0.01" placeholder="0.00" />
									</div>
								</div>
								<div class="brikpanel-ven-field brikpanel-ven-field-full">
									<label for="brikpanel-ven-address"><?php esc_html_e( 'Address', 'brikpanel' ); ?></label>
									<textarea id="brikpanel-ven-address" rows="2"></textarea>
								</div>
								<div class="brikpanel-ven-field brikpanel-ven-field-full">
									<label for="brikpanel-ven-notes"><?php esc_html_e( 'Notes', 'brikpanel' ); ?></label>
									<textarea id="brikpanel-ven-notes" rows="2"></textarea>
								</div>
								<div class="brikpanel-ven-field brikpanel-ven-field-full">
									<label class="brikpanel-ven-checkbox-label">
										<input type="checkbox" id="brikpanel-ven-active" checked />
										<span><?php esc_html_e( 'Active supplier (uncheck to archive without deleting)', 'brikpanel' ); ?></span>
									</label>
								</div>
							</div>
						</div>
						<div class="brikpanel-ven-modal-footer">
							<button type="button" class="brikpanel-ven-btn brikpanel-ven-btn-secondary" id="brikpanel-ven-cancel-btn">
								<?php esc_html_e( 'Cancel', 'brikpanel' ); ?>
							</button>
							<button type="submit" class="brikpanel-ven-btn brikpanel-ven-btn-primary" id="brikpanel-ven-submit-btn">
								<?php esc_html_e( 'Save', 'brikpanel' ); ?>
							</button>
						</div>
					</form>
				</div>
			</div>
		</div>

		<script>
		window.brikpanelVendors = {
			mode:        'detail',
			vendor_id:   <?php echo wp_json_encode( (int) $id ); ?>,
			ajax_url:    <?php echo wp_json_encode( esc_url_raw( $ajax_url ) ); ?>,
			nonce:       <?php echo wp_json_encode( $nonce ); ?>,
			currency:    <?php echo wp_json_encode( $currency ); ?>,
			so_edit_url: <?php echo wp_json_encode( esc_url_raw( $so_edit_url ) ); ?>,
			i18n: {
				error:           <?php echo wp_json_encode( __( 'Something went wrong.', 'brikpanel' ) ); ?>,
				saved:           <?php echo wp_json_encode( __( 'Supplier saved.', 'brikpanel' ) ); ?>,
				name_required:   <?php echo wp_json_encode( __( 'Supplier name is required.', 'brikpanel' ) ); ?>,
				edit_title:      <?php echo wp_json_encode( __( 'Edit supplier', 'brikpanel' ) ); ?>,
				days_short:      <?php echo wp_json_encode( __( 'd', 'brikpanel' ) ); ?>,
				vs_default:      <?php echo wp_json_encode( __( 'vs %s default', 'brikpanel' ) ); ?>,
				on_target:       <?php echo wp_json_encode( __( 'on target', 'brikpanel' ) ); ?>,
				slower:          <?php echo wp_json_encode( __( 'slower', 'brikpanel' ) ); ?>,
				faster:          <?php echo wp_json_encode( __( 'faster', 'brikpanel' ) ); ?>,
				no_pos:          <?php echo wp_json_encode( __( 'No stock orders yet for this supplier.', 'brikpanel' ) ); ?>,
				no_products:     <?php echo wp_json_encode( __( 'No products are sourced from this supplier yet.', 'brikpanel' ) ); ?>,
				default_no_pos:  <?php echo wp_json_encode( __( 'default — no received POs yet', 'brikpanel' ) ); ?>,
				open_value:      <?php echo wp_json_encode( _x( 'open value', 'sub-label under "open purchase orders" stat — total monetary value of open POs', 'brikpanel' ) ); ?>,
			}
		};
		</script>
		<?php
	}

	// =========================================================================
	// AJAX: list (with stats per vendor)
	// =========================================================================

	public function ajax_list() {
		$this->check_auth();
		global $wpdb;

		$search    = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		$status    = sanitize_key( $_POST['status'] ?? 'active' );
		$page      = max( 1, absint( $_POST['page'] ?? 1 ) );
		$per_page  = 25;
		$vendors_t = $wpdb->prefix . self::TABLE;
		$so_t      = $wpdb->prefix . 'brikpanel_stock_orders';

		$where  = [];
		$params = [];

		if ( $status === 'active' ) {
			$where[] = 'v.is_active = 1';
		} elseif ( $status === 'archived' ) {
			$where[] = 'v.is_active = 0';
		}

		if ( $search !== '' ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(v.name LIKE %s OR v.contact_name LIKE %s OR v.email LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		// Count
		$count_sql = "SELECT COUNT(*) FROM {$vendors_t} v {$where_sql}";
		$count = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore

		$offset = ( $page - 1 ) * $per_page;

		// List with aggregated stats — single query, GROUP BY vendor.
		// 90d window for spend; lead time averaged across received POs only.
		$cutoff = gmdate( 'Y-m-d', strtotime( '-90 days' ) );

		$list_sql = "
			SELECT
				v.*,
				COALESCE( SUM( CASE WHEN s.status='received' AND s.received_date >= %s THEN s.total ELSE 0 END ), 0 ) AS spend_90d,
				COALESCE( SUM( CASE WHEN s.status IN ('ordered','partially_received') THEN 1 ELSE 0 END ), 0 ) AS open_pos,
				AVG( CASE WHEN s.status='received' AND s.received_date IS NOT NULL AND s.order_date IS NOT NULL
				          THEN DATEDIFF(s.received_date, s.order_date) END ) AS avg_lead_time
			FROM {$vendors_t} v
			LEFT JOIN {$so_t} s ON s.vendor_id = v.id
			{$where_sql}
			GROUP BY v.id
			ORDER BY v.is_active DESC, v.name ASC
			LIMIT %d OFFSET %d
		";

		$list_params = array_merge( [ $cutoff ], $params, [ $per_page, $offset ] );
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) ); // phpcs:ignore

		// Aggregate summary cards (across the entire current filter, not just page).
		$summary_sql = "
			SELECT
				COUNT( DISTINCT v.id ) AS active_count,
				COALESCE( SUM( CASE WHEN s.status='received' AND s.received_date >= %s THEN s.total ELSE 0 END ), 0 ) AS spend_90d,
				COALESCE( SUM( CASE WHEN s.status IN ('ordered','partially_received') THEN 1 ELSE 0 END ), 0 ) AS open_pos
			FROM {$vendors_t} v
			LEFT JOIN {$so_t} s ON s.vendor_id = v.id
			WHERE v.is_active = 1
		";
		$summary = $wpdb->get_row( $wpdb->prepare( $summary_sql, $cutoff ) ); // phpcs:ignore

		$items = [];
		foreach ( $rows as $r ) {
			$items[] = [
				'id'                       => (int) $r->id,
				'name'                     => $r->name,
				'contact_name'             => $r->contact_name,
				'email'                    => $r->email,
				'phone'                    => $r->phone,
				'website'                  => $r->website,
				'address'                  => $r->address,
				'tax_id'                   => $r->tax_id,
				'default_lead_time_days'   => (int) $r->default_lead_time_days,
				'default_shipping_fee'     => (float) $r->default_shipping_fee,
				'default_shipping_fee_fmt' => (float) $r->default_shipping_fee > 0 ? $this->money_fmt( (float) $r->default_shipping_fee ) : '',
				'notes'                    => $r->notes,
				'is_active'                => (int) $r->is_active === 1,
				'spend_90d'                => (float) $r->spend_90d,
				'spend_90d_fmt'            => $this->money_fmt( (float) $r->spend_90d ),
				'open_pos'                 => (int) $r->open_pos,
				'avg_lead_time'            => $r->avg_lead_time === null ? null : (float) $r->avg_lead_time,
			];
		}

		wp_send_json_success( [
			'items'        => $items,
			'total_count'  => $count,
			'page'         => $page,
			'pages'        => max( 1, (int) ceil( $count / $per_page ) ),
			'summary'      => [
				'active_count' => $summary ? (int) $summary->active_count : 0,
				'spend_90d'    => $summary ? (float) $summary->spend_90d : 0.0,
				'spend_90d_fmt'=> $this->money_fmt( $summary ? (float) $summary->spend_90d : 0.0 ),
				'open_pos'     => $summary ? (int) $summary->open_pos : 0,
			],
		] );
	}

	// =========================================================================
	// AJAX: get one
	// =========================================================================

	public function ajax_get() {
		$this->check_auth();
		global $wpdb;

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'brikpanel' ) ] );
		}
		$table = $wpdb->prefix . self::TABLE;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Supplier not found.', 'brikpanel' ) ] );
		}
		wp_send_json_success( [
			'id'                     => (int) $row->id,
			'name'                   => $row->name,
			'contact_name'           => $row->contact_name,
			'email'                  => $row->email,
			'phone'                  => $row->phone,
			'website'                => $row->website,
			'address'                => $row->address,
			'tax_id'                 => $row->tax_id,
			'default_lead_time_days' => (int) $row->default_lead_time_days,
			'default_shipping_fee'   => (float) $row->default_shipping_fee,
			'notes'                  => $row->notes,
			'is_active'              => (int) $row->is_active === 1,
		] );
	}

	// =========================================================================
	// AJAX: save (insert or update)
	// =========================================================================

	public function ajax_save() {
		$this->check_auth();
		global $wpdb;

		$id            = absint( $_POST['id'] ?? 0 );
		$name          = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$contact_name  = sanitize_text_field( wp_unslash( $_POST['contact_name'] ?? '' ) );
		$email         = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone         = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$website       = esc_url_raw( wp_unslash( $_POST['website'] ?? '' ) );
		$address       = sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) );
		$tax_id        = sanitize_text_field( wp_unslash( $_POST['tax_id'] ?? '' ) );
		$lead_time     = max( 0, absint( $_POST['default_lead_time_days'] ?? 0 ) );
		$shipping_raw  = sanitize_text_field( wp_unslash( $_POST['default_shipping_fee'] ?? '' ) );
		$shipping_fee  = $shipping_raw !== '' ? max( 0.0, (float) $shipping_raw ) : 0.0;
		$notes         = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
		$is_active     = ! empty( $_POST['is_active'] ) ? 1 : 0;

		if ( $name === '' ) {
			wp_send_json_error( [ 'message' => __( 'Supplier name is required.', 'brikpanel' ) ] );
		}

		$table = $wpdb->prefix . self::TABLE;
		$data = [
			'name'                   => $name,
			'contact_name'           => $contact_name,
			'email'                  => $email,
			'phone'                  => $phone,
			'website'                => $website,
			'address'                => $address,
			'tax_id'                 => $tax_id,
			'default_lead_time_days' => $lead_time,
			'default_shipping_fee'   => $shipping_fee,
			'notes'                  => $notes,
			'is_active'              => $is_active,
		];
		$format = [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%d' ];

		if ( $id > 0 ) {
			$wpdb->update( $table, $data, [ 'id' => $id ], $format, [ '%d' ] );
		} else {
			$wpdb->insert( $table, $data, $format );
			$id = (int) $wpdb->insert_id;
		}

		if ( $wpdb->last_error ) {
			wp_send_json_error( [ 'message' => __( 'Database error.', 'brikpanel' ) ] );
		}

		do_action( 'brikpanel_vendor_saved', $id, $data );

		wp_send_json_success( [ 'id' => $id ] );
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
		$table   = $wpdb->prefix . self::TABLE;
		$so_t    = $wpdb->prefix . 'brikpanel_stock_orders';
		$wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

		// Detach this vendor from any stock orders without deleting them —
		// historical records stay intact but become "unassigned".
		$wpdb->update( $so_t, [ 'vendor_id' => 0 ], [ 'vendor_id' => $id ], [ '%d' ], [ '%d' ] );

		// Also detach from any product/variation meta.
		$wpdb->delete(
			$wpdb->postmeta,
			[ 'meta_key' => '_brikpanel_vendor_id', 'meta_value' => (string) $id ],
			[ '%s', '%s' ]
		);

		do_action( 'brikpanel_vendor_deleted', $id );

		wp_send_json_success();
	}

	// =========================================================================
	// AJAX: search (for product editor + PO editor dropdowns)
	// =========================================================================

	public function ajax_search() {
		$this->check_auth();
		global $wpdb;

		$term  = sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) );
		$table = $wpdb->prefix . self::TABLE;

		$where  = [ 'is_active = 1' ];
		$params = [];
		if ( $term !== '' ) {
			$like     = '%' . $wpdb->esc_like( $term ) . '%';
			$where[]  = '(name LIKE %s OR contact_name LIKE %s OR email LIKE %s)';
			$params   = [ $like, $like, $like ];
		}
		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$sql = "SELECT id, name FROM {$table} {$where_sql} ORDER BY name ASC LIMIT 30";
		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) // phpcs:ignore
			: $wpdb->get_results( $sql ); // phpcs:ignore

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [ 'id' => (int) $r->id, 'name' => $r->name ];
		}
		wp_send_json_success( $out );
	}

	// =========================================================================
	// AJAX: defaults (lightweight payload for the PO editor prefill)
	// =========================================================================

	public function ajax_defaults() {
		$this->check_auth();
		global $wpdb;
		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'brikpanel' ) ] );
		}
		$table = $wpdb->prefix . self::TABLE;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, name, default_lead_time_days, default_shipping_fee FROM {$table} WHERE id = %d",
			$id
		) ); // phpcs:ignore
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Supplier not found.', 'brikpanel' ) ] );
		}
		wp_send_json_success( [
			'id'                     => (int) $row->id,
			'name'                   => $row->name,
			'default_lead_time_days' => (int) $row->default_lead_time_days,
			'default_shipping_fee'   => (float) $row->default_shipping_fee,
		] );
	}

	// =========================================================================
	// AJAX: detail (vendor profile data — stats, recent POs, products, trend)
	// =========================================================================

	public function ajax_detail() {
		$this->check_auth();
		global $wpdb;

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'brikpanel' ) ] );
		}
		$v_t  = $wpdb->prefix . self::TABLE;
		$so_t = $wpdb->prefix . 'brikpanel_stock_orders';

		$vendor = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$v_t} WHERE id = %d", $id ) ); // phpcs:ignore
		if ( ! $vendor ) {
			wp_send_json_error( [ 'message' => __( 'Supplier not found.', 'brikpanel' ) ] );
		}

		$cutoff_90d = gmdate( 'Y-m-d', strtotime( '-90 days' ) );

		// Stats — single aggregated query.
		$stats_sql = "
			SELECT
				COUNT(*)                                                                   AS total_pos,
				SUM( CASE WHEN status='received'                                       THEN 1 ELSE 0 END )       AS received_pos,
				SUM( CASE WHEN status IN ('ordered','partially_received')              THEN 1 ELSE 0 END )       AS open_pos,
				COALESCE(SUM( CASE WHEN status='received'                              THEN total ELSE 0 END ),0) AS lifetime_spend,
				COALESCE(SUM( CASE WHEN status='received' AND received_date >= %s      THEN total ELSE 0 END ),0) AS spend_90d,
				COALESCE(SUM( CASE WHEN status IN ('ordered','partially_received')     THEN total ELSE 0 END ),0) AS open_value,
				AVG( CASE WHEN status='received' AND received_date IS NOT NULL AND order_date IS NOT NULL
				          THEN DATEDIFF(received_date, order_date) END )                     AS avg_lead_time
			FROM {$so_t}
			WHERE vendor_id = %d
		";
		$stats = $wpdb->get_row( $wpdb->prepare( $stats_sql, $cutoff_90d, $id ) ); // phpcs:ignore

		// Recent POs (last 10).
		$recent_pos = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, reference, status, order_date, expected_date, received_date, total
			   FROM {$so_t}
			   WHERE vendor_id = %d
			   ORDER BY COALESCE(order_date, DATE(created_at)) DESC, id DESC
			   LIMIT 10",
			$id
		) ); // phpcs:ignore

		$po_items = [];
		foreach ( (array) $recent_pos as $r ) {
			$lead = null;
			if ( $r->status === Brikpanel_Stock_Orders::STATUS_RECEIVED && $r->order_date && $r->received_date ) {
				$lead = (int) ( ( strtotime( $r->received_date ) - strtotime( $r->order_date ) ) / DAY_IN_SECONDS );
			}
			$po_items[] = [
				'id'            => (int) $r->id,
				'reference'     => $r->reference !== '' ? $r->reference : ( '#' . (int) $r->id ),
				'status'        => $r->status,
				'status_label'  => $this->so_status_label( $r->status ),
				'order_date'    => $r->order_date,
				'expected_date' => $r->expected_date,
				'received_date' => $r->received_date,
				'lead_days'     => $lead,
				'total_fmt'     => $this->money_fmt( (float) $r->total ),
			];
		}

		// Sourced products — both products and variations whose vendor meta = id.
		$product_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = %s AND meta_value = %s
			 LIMIT 50",
			Brikpanel_Vendor_Product_Editor::META_VENDOR_ID,
			(string) $id
		) ); // phpcs:ignore

		$products = [];
		foreach ( (array) $product_ids as $pid ) {
			$pid = (int) $pid;
			if ( ! $pid || ! function_exists( 'wc_get_product' ) ) { continue; }
			$product = wc_get_product( $pid );
			if ( ! $product ) { continue; }

			$is_variation = $product->is_type( 'variation' );
			$parent       = $is_variation ? wc_get_product( $product->get_parent_id() ) : null;
			$title        = $is_variation
				? ( ( $parent ? $parent->get_name() : '' ) . ' — ' . wp_strip_all_tags( wc_get_formatted_variation( $product, true ) ) )
				: $product->get_name();

			// Raw meta read (native first, legacy fallback) — unlike
			// get_cogs_value() it keeps working when the WC COGS feature
			// flag is off.
			$cost = '';
			$raw  = brikpanel_product_cogs_raw( $pid );
			if ( $raw !== '' ) {
				$cost = $this->money_fmt( (float) $raw );
			}

			$products[] = [
				'id'        => $pid,
				'edit_url'  => $is_variation && $parent ? get_edit_post_link( $parent->get_id(), '' ) : get_edit_post_link( $pid, '' ),
				'title'     => $title,
				'sku'       => $product->get_sku(),
				'stock'     => $product->managing_stock() ? (string) $product->get_stock_quantity() : '',
				'cost'      => $cost,
				'price'     => $product->get_price() !== '' ? $this->money_fmt( (float) $product->get_price() ) : '',
			];
		}
		// Sort by title for stable display.
		usort( $products, static function ( $a, $b ) { return strcasecmp( $a['title'], $b['title'] ); } );

		// 12-month spend trend.
		$start_month = gmdate( 'Y-m-01', strtotime( '-11 months' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(received_date, '%%Y-%%m') AS month, COALESCE(SUM(total),0) AS spend
			   FROM {$so_t}
			   WHERE vendor_id = %d AND status = 'received' AND received_date >= %s
			   GROUP BY month
			   ORDER BY month ASC",
			$id,
			$start_month
		) ); // phpcs:ignore

		$by_month = [];
		foreach ( (array) $rows as $r ) {
			$by_month[ $r->month ] = (float) $r->spend;
		}
		$trend = [];
		for ( $i = 11; $i >= 0; $i-- ) {
			$ts    = strtotime( "-{$i} months" );
			$key   = gmdate( 'Y-m', $ts );
			$label = gmdate( 'M', $ts );
			$value = isset( $by_month[ $key ] ) ? (float) $by_month[ $key ] : 0.0;
			$trend[] = [
				'month'    => $key,
				'label'    => $label,
				'value'    => $value,
				'value_fmt'=> $this->money_fmt( $value ),
			];
		}

		// Stats payload — pre-format money fields for the client.
		$avg_lead = $stats && $stats->avg_lead_time !== null ? (float) $stats->avg_lead_time : null;
		$payload = [
			'vendor' => [
				'id'                     => (int) $vendor->id,
				'name'                   => $vendor->name,
				'is_active'              => (int) $vendor->is_active === 1,
				'default_lead_time_days' => (int) $vendor->default_lead_time_days,
				'default_shipping_fee'   => (float) $vendor->default_shipping_fee,
			],
			'stats' => [
				'total_pos'        => $stats ? (int) $stats->total_pos : 0,
				'received_pos'     => $stats ? (int) $stats->received_pos : 0,
				'open_pos'         => $stats ? (int) $stats->open_pos : 0,
				'lifetime_spend'   => $stats ? (float) $stats->lifetime_spend : 0.0,
				'lifetime_spend_fmt' => $this->money_fmt( $stats ? (float) $stats->lifetime_spend : 0.0 ),
				'spend_90d'        => $stats ? (float) $stats->spend_90d : 0.0,
				'spend_90d_fmt'    => $this->money_fmt( $stats ? (float) $stats->spend_90d : 0.0 ),
				'open_value'       => $stats ? (float) $stats->open_value : 0.0,
				'open_value_fmt'   => $this->money_fmt( $stats ? (float) $stats->open_value : 0.0 ),
				'avg_lead_time'    => $avg_lead,
				'products_count'   => count( $products ),
			],
			'pos'      => $po_items,
			'products' => $products,
			'trend'    => $trend,
		];

		wp_send_json_success( $payload );
	}

	private function so_status_label( $status ) {
		switch ( $status ) {
			case 'draft':              return __( 'Draft', 'brikpanel' );
			case 'ordered':            return __( 'Ordered', 'brikpanel' );
			case 'partially_received': return __( 'Partial', 'brikpanel' );
			case 'received':           return __( 'Received', 'brikpanel' );
			case 'cancelled':          return __( 'Cancelled', 'brikpanel' );
		}
		return $status;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Format a number as money using WC's helper, stripped of HTML.
	 */
	private function money_fmt( $amount ) {
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return number_format_i18n( (float) $amount, 2 );
	}

	/**
	 * Return id => name map of all active vendors (for dropdowns).
	 *
	 * @return array<int,string>
	 */
	public static function get_active_vendor_options() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$rows  = $wpdb->get_results( "SELECT id, name FROM {$table} WHERE is_active = 1 ORDER BY name ASC" ); // phpcs:ignore
		$out   = [];
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->id ] = $r->name;
		}
		return $out;
	}
}
