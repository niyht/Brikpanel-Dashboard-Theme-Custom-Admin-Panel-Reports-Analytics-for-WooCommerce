<?php
/**
 * BrikPanel — Operational Expenses
 *
 * Manual expense entry and reporting. Expenses are stored in
 * wp_brikpanel_expenses and can be viewed/filtered by date range
 * and category. The totals feed into profit calculations.
 *
 * @package BrikPanel
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Expenses {

	const PAGE_SLUG   = 'brikpanel-expenses';
	const NONCE_ACTION = 'brikpanel_expenses_nonce';
	const TABLE       = 'brikpanel_expenses';

	public function __construct() {
		// Priority 11 so this hook runs after Brikpanel_Vendors (10) when the
		// master toggle is on — guarantees the parent slug exists by the time
		// we register the submenu, even though WP doesn't strictly require it.
		add_action( 'admin_menu', [ $this, 'register_page' ], 11 );
		add_action( 'wp_ajax_brikpanel_expenses_list',   [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_brikpanel_expenses_save',   [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_brikpanel_expenses_delete', [ $this, 'ajax_delete' ] );
	}

	// =========================================================================
	// Page registration
	// =========================================================================

	public function register_page() {
		// Operational Expenses is fully scoped to the Vendors module — it
		// lives as a submenu of the Vendors top-level menu and disappears
		// entirely when the vendor master toggle is off. AJAX handlers stay
		// registered so previously-saved data isn't orphaned, but there's no
		// surface to reach the page from until the user re-enables vendors.
		if (
			! class_exists( 'Brikpanel_Vendors' )
			|| 'yes' !== get_option( 'brikpanel_vendors_enabled', 'no' )
		) {
			return;
		}
		add_submenu_page(
			Brikpanel_Vendors::PAGE_SLUG,
			__( 'Operational Expenses', 'brikpanel' ),
			__( 'Operational Expenses', 'brikpanel' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
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
		$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '₺';
		$nonce    = wp_create_nonce( self::NONCE_ACTION );
		$ajax_url = admin_url( 'admin-ajax.php' );

		$categories = $this->get_categories();
		?>
		<div class="wrap brikpanel-expenses-wrap" id="brikpanel-expenses">
			<div class="brikpanel-ex-header">
				<div class="brikpanel-ex-header-left">
					<h1><?php esc_html_e( 'Operational Expenses', 'brikpanel' ); ?></h1>
					<p class="brikpanel-ex-subtitle"><?php esc_html_e( 'Track your operational costs to calculate net profit accurately.', 'brikpanel' ); ?></p>
				</div>
				<div class="brikpanel-ex-header-right">
					<button type="button" class="brikpanel-ex-btn brikpanel-ex-btn-primary" id="brikpanel-ex-add-btn">
						+ <?php esc_html_e( 'Add expense', 'brikpanel' ); ?>
					</button>
				</div>
			</div>

			<!-- Summary bar -->
			<div class="brikpanel-ex-summary" id="brikpanel-ex-summary">
				<div class="brikpanel-ex-summary-card">
					<div class="brikpanel-ex-summary-label"><?php esc_html_e( 'Total (filtered)', 'brikpanel' ); ?></div>
					<div class="brikpanel-ex-summary-value" id="brikpanel-ex-total">—</div>
				</div>
				<div class="brikpanel-ex-summary-card">
					<div class="brikpanel-ex-summary-label"><?php esc_html_e( 'Entries', 'brikpanel' ); ?></div>
					<div class="brikpanel-ex-summary-value" id="brikpanel-ex-count">—</div>
				</div>
			</div>

			<!-- Filters -->
			<div class="brikpanel-ex-card brikpanel-ex-filters">
				<div class="brikpanel-ex-filter-row">
					<div class="brikpanel-ex-field">
						<label for="brikpanel-ex-from"><?php esc_html_e( 'From', 'brikpanel' ); ?></label>
						<input type="date" id="brikpanel-ex-from" value="<?php echo esc_attr( gmdate( 'Y-m-01' ) ); ?>" />
					</div>
					<div class="brikpanel-ex-field">
						<label for="brikpanel-ex-to"><?php esc_html_e( 'To', 'brikpanel' ); ?></label>
						<input type="date" id="brikpanel-ex-to" value="<?php echo esc_attr( gmdate( 'Y-m-t' ) ); ?>" />
					</div>
					<div class="brikpanel-ex-field">
						<label for="brikpanel-ex-cat-filter"><?php esc_html_e( 'Category', 'brikpanel' ); ?></label>
						<select id="brikpanel-ex-cat-filter">
							<option value=""><?php esc_html_e( 'All categories', 'brikpanel' ); ?></option>
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( $cat ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="brikpanel-ex-filter-actions">
						<button type="button" class="brikpanel-ex-btn brikpanel-ex-btn-secondary" id="brikpanel-ex-search-btn">
							<?php esc_html_e( 'Apply', 'brikpanel' ); ?>
						</button>
						<button type="button" class="brikpanel-ex-btn brikpanel-ex-btn-secondary" id="brikpanel-ex-export-btn">
							<?php esc_html_e( 'Export CSV', 'brikpanel' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Table -->
			<div class="brikpanel-ex-card brikpanel-ex-table-card">
				<div class="brikpanel-ex-table-wrap">
					<table class="brikpanel-ex-table" id="brikpanel-ex-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Category', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Description', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Recurring', 'brikpanel' ); ?></th>
								<th class="brikpanel-ex-num"><?php esc_html_e( 'Amount', 'brikpanel' ); ?></th>
								<th class="brikpanel-ex-actions-th"></th>
							</tr>
						</thead>
						<tbody id="brikpanel-ex-tbody">
							<tr><td colspan="6" class="brikpanel-ex-empty"><?php esc_html_e( 'Loading…', 'brikpanel' ); ?></td></tr>
						</tbody>
					</table>
				</div>
				<div class="brikpanel-ex-pagination" id="brikpanel-ex-pagination" hidden>
					<button type="button" class="brikpanel-ex-btn brikpanel-ex-btn-secondary" id="brikpanel-ex-prev">&larr; <?php esc_html_e( 'Previous', 'brikpanel' ); ?></button>
					<span class="brikpanel-ex-page-info" id="brikpanel-ex-page-info">1 / 1</span>
					<button type="button" class="brikpanel-ex-btn brikpanel-ex-btn-secondary" id="brikpanel-ex-next"><?php esc_html_e( 'Next', 'brikpanel' ); ?> &rarr;</button>
				</div>
			</div>

			<!-- Add / Edit modal -->
			<div class="brikpanel-ex-overlay" id="brikpanel-ex-overlay" hidden>
				<div class="brikpanel-ex-modal" role="dialog" aria-modal="true" aria-labelledby="brikpanel-ex-modal-title">
					<div class="brikpanel-ex-modal-header">
						<h2 id="brikpanel-ex-modal-title"><?php esc_html_e( 'Add expense', 'brikpanel' ); ?></h2>
						<button type="button" class="brikpanel-ex-modal-close" id="brikpanel-ex-modal-close" aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">&times;</button>
					</div>
					<form id="brikpanel-ex-form" autocomplete="off">
						<input type="hidden" id="brikpanel-ex-edit-id" value="" />
						<div class="brikpanel-ex-modal-body">
							<div class="brikpanel-ex-modal-grid">
								<div class="brikpanel-ex-field">
									<label for="brikpanel-ex-date"><?php esc_html_e( 'Date', 'brikpanel' ); ?></label>
									<input type="date" id="brikpanel-ex-date" required value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" />
								</div>
								<div class="brikpanel-ex-field">
									<label for="brikpanel-ex-amount"><?php esc_html_e( 'Amount', 'brikpanel' ); ?></label>
									<div class="brikpanel-ex-input-group">
										<span class="brikpanel-ex-prefix"><?php echo esc_html( $currency ); ?></span>
										<input type="number" id="brikpanel-ex-amount" min="0" step="0.01" placeholder="0.00" required />
									</div>
								</div>
								<div class="brikpanel-ex-field">
									<label for="brikpanel-ex-category"><?php esc_html_e( 'Category', 'brikpanel' ); ?></label>
									<input type="text" id="brikpanel-ex-category" placeholder="<?php esc_attr_e( 'e.g. Rent, Salary, Software', 'brikpanel' ); ?>" list="brikpanel-ex-cat-list" required />
									<datalist id="brikpanel-ex-cat-list">
										<?php foreach ( $categories as $cat ) : ?>
											<option value="<?php echo esc_attr( $cat ); ?>"></option>
										<?php endforeach; ?>
									</datalist>
								</div>
								<div class="brikpanel-ex-field">
									<label for="brikpanel-ex-recurring"><?php esc_html_e( 'Recurring', 'brikpanel' ); ?></label>
									<select id="brikpanel-ex-recurring">
										<option value="none"><?php esc_html_e( 'One-time', 'brikpanel' ); ?></option>
										<option value="monthly"><?php esc_html_e( 'Monthly', 'brikpanel' ); ?></option>
										<option value="weekly"><?php esc_html_e( 'Weekly', 'brikpanel' ); ?></option>
										<option value="yearly"><?php esc_html_e( 'Yearly', 'brikpanel' ); ?></option>
									</select>
								</div>
								<div class="brikpanel-ex-field brikpanel-ex-field-full">
									<label for="brikpanel-ex-description"><?php esc_html_e( 'Description', 'brikpanel' ); ?></label>
									<textarea id="brikpanel-ex-description" rows="2" placeholder="<?php esc_attr_e( 'Optional notes…', 'brikpanel' ); ?>"></textarea>
								</div>
							</div>
						</div>
						<div class="brikpanel-ex-modal-footer">
							<button type="button" class="brikpanel-ex-btn brikpanel-ex-btn-secondary" id="brikpanel-ex-cancel-btn">
								<?php esc_html_e( 'Cancel', 'brikpanel' ); ?>
							</button>
							<button type="submit" class="brikpanel-ex-btn brikpanel-ex-btn-primary" id="brikpanel-ex-submit-btn">
								<?php esc_html_e( 'Save', 'brikpanel' ); ?>
							</button>
						</div>
					</form>
				</div>
			</div>
		</div>

		<script>
		window.brikpanelExpenses = {
			ajax_url: <?php echo wp_json_encode( esc_url_raw( $ajax_url ) ); ?>,
			nonce:    <?php echo wp_json_encode( $nonce ); ?>,
			currency: <?php echo wp_json_encode( $currency ); ?>,
			i18n: {
				confirm_delete: <?php echo wp_json_encode( __( 'Delete this expense?', 'brikpanel' ) ); ?>,
				error:          <?php echo wp_json_encode( __( 'Something went wrong.', 'brikpanel' ) ); ?>,
				no_expenses:    <?php echo wp_json_encode( __( 'No expenses found.', 'brikpanel' ) ); ?>,
				edit_title:     <?php echo wp_json_encode( __( 'Edit expense', 'brikpanel' ) ); ?>,
				add_title:      <?php echo wp_json_encode( __( 'Add expense', 'brikpanel' ) ); ?>,
				save:           <?php echo wp_json_encode( __( 'Save', 'brikpanel' ) ); ?>,
				recurring_none:    <?php echo wp_json_encode( __( 'One-time', 'brikpanel' ) ); ?>,
				recurring_monthly: <?php echo wp_json_encode( __( 'Monthly', 'brikpanel' ) ); ?>,
				recurring_weekly:  <?php echo wp_json_encode( __( 'Weekly', 'brikpanel' ) ); ?>,
				recurring_yearly:  <?php echo wp_json_encode( __( 'Yearly', 'brikpanel' ) ); ?>,
				edit:              <?php echo wp_json_encode( __( 'Edit', 'brikpanel' ) ); ?>,
				delete:            <?php echo wp_json_encode( __( 'Delete', 'brikpanel' ) ); ?>,
				csv_date:          <?php echo wp_json_encode( __( 'Date', 'brikpanel' ) ); ?>,
				csv_category:      <?php echo wp_json_encode( __( 'Category', 'brikpanel' ) ); ?>,
				csv_description:   <?php echo wp_json_encode( __( 'Description', 'brikpanel' ) ); ?>,
				csv_recurring:     <?php echo wp_json_encode( __( 'Recurring', 'brikpanel' ) ); ?>,
				csv_amount:        <?php echo wp_json_encode( __( 'Amount', 'brikpanel' ) ); ?>,
			}
		};
		</script>
		<?php
	}

	// =========================================================================
	// AJAX: list expenses
	// =========================================================================

	public function ajax_list() {
		$this->check_auth();
		global $wpdb;

		$date_from = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
		$date_to   = sanitize_text_field( wp_unslash( $_POST['date_to']   ?? '' ) );
		$category  = sanitize_text_field( wp_unslash( $_POST['category']  ?? '' ) );
		$page      = max( 1, absint( $_POST['page'] ?? 1 ) );
		$per_page  = 25;
		$table     = $wpdb->prefix . self::TABLE;

		$where  = [];
		$params = [];

		if ( $date_from !== '' ) {
			$where[]  = 'expense_date >= %s';
			$params[] = $date_from;
		}
		if ( $date_to !== '' ) {
			$where[]  = 'expense_date <= %s';
			$params[] = $date_to;
		}
		if ( $category !== '' ) {
			$where[]  = 'category = %s';
			$params[] = $category;
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$count_sql = "SELECT COUNT(*), COALESCE(SUM(amount), 0) AS total FROM {$table} {$where_sql}";
		$count_row = $params
			? $wpdb->get_row( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore
			: $wpdb->get_row( $count_sql ); // phpcs:ignore

		$total_count = (int) $count_row->{'COUNT(*)'};
		$total_amount = (float) $count_row->total;

		$offset = ( $page - 1 ) * $per_page;
		$list_sql = "SELECT * FROM {$table} {$where_sql} ORDER BY expense_date DESC, id DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, [ $per_page, $offset ] );
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) ); // phpcs:ignore

		$items = [];
		foreach ( $rows as $r ) {
			$items[] = [
				'id'           => (int) $r->id,
				'date'         => $r->expense_date,
				'category'     => $r->category,
				'description'  => $r->description,
				'amount'       => (float) $r->amount,
				'amount_fmt'   => html_entity_decode( wp_strip_all_tags( wc_price( (float) $r->amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'recurring'    => $r->recurring,
			];
		}

		wp_send_json_success( [
			'items'        => $items,
			'total_count'  => $total_count,
			'total_amount' => $total_amount,
			'total_fmt'    => html_entity_decode( wp_strip_all_tags( wc_price( $total_amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'page'         => $page,
			'pages'        => max( 1, (int) ceil( $total_count / $per_page ) ),
		] );
	}

	// =========================================================================
	// AJAX: save (insert or update)
	// =========================================================================

	public function ajax_save() {
		$this->check_auth();
		global $wpdb;

		$id          = absint( $_POST['id']          ?? 0 );
		$date        = sanitize_text_field( wp_unslash( $_POST['expense_date'] ?? '' ) );
		$category    = sanitize_text_field( wp_unslash( $_POST['category']     ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$amount_raw  = sanitize_text_field( wp_unslash( $_POST['amount']       ?? '' ) );
		$recurring   = sanitize_key( $_POST['recurring'] ?? 'none' );

		if ( $date === '' || $category === '' || $amount_raw === '' ) {
			wp_send_json_error( [ 'message' => __( 'Required fields missing.', 'brikpanel' ) ] );
		}

		$amount = (float) $amount_raw;
		if ( $amount < 0 ) {
			wp_send_json_error( [ 'message' => __( 'Amount must be a positive number.', 'brikpanel' ) ] );
		}

		$valid_recurring = [ 'none', 'monthly', 'weekly', 'yearly' ];
		if ( ! in_array( $recurring, $valid_recurring, true ) ) {
			$recurring = 'none';
		}

		$table = $wpdb->prefix . self::TABLE;
		$data  = [
			'expense_date' => $date,
			'category'     => $category,
			'description'  => $description,
			'amount'       => $amount,
			'recurring'    => $recurring,
		];
		$format = [ '%s', '%s', '%s', '%f', '%s' ];

		if ( $id > 0 ) {
			$wpdb->update( $table, $data, [ 'id' => $id ], $format, [ '%d' ] );
		} else {
			$wpdb->insert( $table, $data, $format );
			$id = $wpdb->insert_id;
		}

		if ( $wpdb->last_error ) {
			wp_send_json_error( [ 'message' => __( 'Database error.', 'brikpanel' ) ] );
		}

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

		$table = $wpdb->prefix . self::TABLE;
		$wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

		wp_send_json_success();
	}

	// =========================================================================
	// Helper: distinct categories
	// =========================================================================

	private function get_categories(): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$rows  = $wpdb->get_col( "SELECT DISTINCT category FROM {$table} WHERE category != '' ORDER BY category ASC" ); // phpcs:ignore
		return $rows ?: [];
	}
}

new Brikpanel_Expenses();
