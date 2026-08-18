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
	const SKIPS_TABLE = 'brikpanel_expense_skips';

	public function __construct() {
		// Priority 11 so this hook runs after Brikpanel_Vendors (10) when the
		// master toggle is on — guarantees the parent slug exists by the time
		// we register the submenu, even though WP doesn't strictly require it.
		add_action( 'admin_menu', [ $this, 'register_page' ], 11 );
		add_action( 'wp_ajax_brikpanel_expenses_list',   [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_brikpanel_expenses_save',   [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_brikpanel_expenses_delete', [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_brikpanel_expense_line_delete', [ $this, 'ajax_line_delete' ] );
	}

	// =========================================================================
	// Page registration
	// =========================================================================

	public function register_page() {
		// Operational Expenses is ALWAYS reachable: the dashboard Profit section
		// subtracts these costs from Net profit, so an owner must be able to see
		// and manage them no matter which other modules are on. When the
		// Suppliers (Vendors) module is enabled it lives as a submenu there
		// (procurement grouping); when that module is off it stands on its own
		// as a top-level BrikPanel menu. The page slug — and the saved data
		// behind it — are identical either way, so toggling Suppliers never
		// orphans expenses or moves their URL.
		$vendors_on = class_exists( 'Brikpanel_Vendors' )
			&& 'yes' === get_option( 'brikpanel_vendors_enabled', 'no' );

		if ( $vendors_on ) {
			add_submenu_page(
				Brikpanel_Vendors::PAGE_SLUG,
				__( 'Operational Expenses', 'brikpanel' ),
				__( 'Operational Expenses', 'brikpanel' ),
				'manage_woocommerce',
				self::PAGE_SLUG,
				[ $this, 'render_page' ]
			);
			return;
		}

		// Standalone top-level entry (mirrors Segments / Customer Analytics).
		// The modern-nav layer (front-end/navigation/) swaps in its own icon and
		// pins it next to the other BrikPanel analytics items in the sidebar.
		$hook = add_menu_page(
			__( 'Operational Expenses', 'brikpanel' ),
			__( 'Expenses', 'brikpanel' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'dashicons-money-alt',
			56.7
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, function () {
				global $title;
				$title = __( 'Operational Expenses', 'brikpanel' );
			} );
		}
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
						<label for="brikpanel-ex-cat-filter"><?php esc_html_e( 'Title', 'brikpanel' ); ?></label>
						<select id="brikpanel-ex-cat-filter">
							<option value=""><?php esc_html_e( 'All titles', 'brikpanel' ); ?></option>
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
								<th><?php esc_html_e( 'Title', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Description', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Recurring', 'brikpanel' ); ?></th>
								<th class="brikpanel-ex-num"><?php echo esc_html( _x( 'Amount', 'money value of an expense', 'brikpanel' ) ); ?></th>
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
										<label for="brikpanel-ex-kind"><?php esc_html_e( 'Type', 'brikpanel' ); ?></label>
										<select id="brikpanel-ex-kind">
											<option value="fixed"><?php esc_html_e( 'Fixed amount', 'brikpanel' ); ?></option>
											<option value="percent"><?php esc_html_e( 'Percentage of revenue', 'brikpanel' ); ?></option>
										</select>
									</div>
									<div class="brikpanel-ex-field">
									<label for="brikpanel-ex-amount"><?php echo esc_html( _x( 'Amount', 'money value of an expense', 'brikpanel' ) ); ?></label>
									<div class="brikpanel-ex-input-group">
										<span class="brikpanel-ex-prefix" id="brikpanel-ex-prefix"><?php echo esc_html( $currency ); ?></span>
										<input type="number" id="brikpanel-ex-amount" min="0" step="0.01" placeholder="0.00" required />
											<span class="brikpanel-ex-prefix brikpanel-ex-suffix" id="brikpanel-ex-suffix" hidden>%</span>
									</div>
								</div>
								<div class="brikpanel-ex-field">
									<label for="brikpanel-ex-category"><?php esc_html_e( 'Title', 'brikpanel' ); ?></label>
									<input type="text" id="brikpanel-ex-category" placeholder="<?php esc_attr_e( 'e.g. Rent, Salaries, Credit card commission', 'brikpanel' ); ?>" list="brikpanel-ex-cat-list" required />
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
				ongoing:           <?php echo wp_json_encode( __( 'Ongoing', 'brikpanel' ) ); ?>,
				recurring_monthly: <?php echo wp_json_encode( __( 'Monthly', 'brikpanel' ) ); ?>,
				recurring_weekly:  <?php echo wp_json_encode( __( 'Weekly', 'brikpanel' ) ); ?>,
				recurring_yearly:  <?php echo wp_json_encode( __( 'Yearly', 'brikpanel' ) ); ?>,
				edit:              <?php echo wp_json_encode( __( 'Edit', 'brikpanel' ) ); ?>,
				delete:            <?php echo wp_json_encode( __( 'Delete', 'brikpanel' ) ); ?>,
				csv_date:          <?php echo wp_json_encode( __( 'Date', 'brikpanel' ) ); ?>,
				csv_category:      <?php echo wp_json_encode( __( 'Title', 'brikpanel' ) ); ?>,
				csv_description:   <?php echo wp_json_encode( __( 'Description', 'brikpanel' ) ); ?>,
				csv_recurring:     <?php echo wp_json_encode( __( 'Recurring', 'brikpanel' ) ); ?>,
				csv_amount:        <?php echo wp_json_encode( _x( 'Amount', 'money value of an expense', 'brikpanel' ) ); ?>,
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

		// Catch recurring templates up to today before listing so the page
		// always reflects the current month's occurrences.
		self::materialize_due();

		$date_from = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
		$date_to   = sanitize_text_field( wp_unslash( $_POST['date_to']   ?? '' ) );
		$category  = sanitize_text_field( wp_unslash( $_POST['category']  ?? '' ) );
		$page      = max( 1, absint( $_POST['page'] ?? 1 ) );
		$per_page  = 25;
		$table     = $wpdb->prefix . self::TABLE;

		$where  = [];
		$params = [];

		// Percentage costs (commission) are always-on, so they bypass the date
		// filter and stay visible whatever period is selected; fixed rows honour
		// the From/To range as before.
		$date_conds  = [];
		$date_params = [];
		if ( $date_from !== '' ) {
			$date_conds[]  = 'expense_date >= %s';
			$date_params[] = $date_from;
		}
		if ( $date_to !== '' ) {
			$date_conds[]  = 'expense_date <= %s';
			$date_params[] = $date_to;
		}
		if ( $date_conds ) {
			$where[]  = '( ( ' . implode( ' AND ', $date_conds ) . " ) OR kind = 'percent' )";
			$params   = array_merge( $params, $date_params );
		}
		if ( $category !== '' ) {
			$where[]  = 'category = %s';
			$params[] = $category;
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		// The filtered total is money only: percentage rows store a rate, not an
		// amount, so they are left out of the sum (still counted as entries).
		$count_sql = "SELECT COUNT(*), COALESCE(SUM(CASE WHEN kind <> 'percent' THEN amount ELSE 0 END), 0) AS total FROM {$table} {$where_sql}";
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
			$kind   = isset( $r->kind ) ? (string) $r->kind : 'fixed';
			$amount = (float) $r->amount;
			// Percentage rows display their rate ("2.9%"); fixed rows their money.
			$amount_fmt = ( 'percent' === $kind )
				? rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' ) . '%'
				: html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$items[] = [
				'id'           => (int) $r->id,
				'date'         => $r->expense_date,
				'category'     => $r->category,
				'description'  => $r->description,
				'amount'       => $amount,
				'amount_fmt'   => $amount_fmt,
				'recurring'    => $r->recurring,
				'kind'         => $kind,
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
		$kind        = sanitize_key( $_POST['kind'] ?? 'fixed' );
		if ( ! in_array( $kind, [ 'fixed', 'percent' ], true ) ) {
			$kind = 'fixed';
		}

		// A percentage cost has no meaningful single date (it applies every
		// period), so the editor hides the date field. Stamp today just so the
		// row has a created marker; the profit math ignores it for percent.
		if ( 'percent' === $kind && $date === '' ) {
			$date = current_time( 'Y-m-d' );
		}

		if ( $date === '' || $category === '' || $amount_raw === '' ) {
			wp_send_json_error( [ 'message' => __( 'Required fields missing.', 'brikpanel' ) ] );
		}

		$amount = (float) $amount_raw;
		if ( $amount < 0 ) {
			wp_send_json_error( [ 'message' => __( 'Amount must be a positive number.', 'brikpanel' ) ] );
		}

		// A percentage cost (e.g. card commission) stores a RATE in `amount`.
		// It applies to revenue every period from its date, so "recurring" is
		// meaningless for it (it never materialises into fixed dated rows).
		if ( 'percent' === $kind ) {
			if ( $amount > 100 ) {
				wp_send_json_error( [ 'message' => __( 'A percentage must be between 0 and 100.', 'brikpanel' ) ] );
			}
			$recurring = 'none';
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
			'kind'         => $kind,
		];
		$format = [ '%s', '%s', '%s', '%f', '%s', '%s' ];

		if ( $id > 0 ) {
			$wpdb->update( $table, $data, [ 'id' => $id ], $format, [ '%d' ] );
			// This row may previously have been a recurring template with its own
			// materialised occurrence rows. Clear them so the series is rebuilt
			// from scratch below: an edited start date, a changed frequency, a
			// switch to one-time, or a switch to a percentage cost must never
			// leave stale or duplicated occurrence rows silently feeding the
			// profit totals. (No-op for one-off rows and individual occurrences,
			// which own no children.)
			$wpdb->delete( $table, [ 'recurring_parent' => $id ], [ '%d' ] );
		} else {
			$wpdb->insert( $table, $data, $format );
			$id = $wpdb->insert_id;
		}

		if ( $wpdb->last_error ) {
			wp_send_json_error( [ 'message' => __( 'Database error.', 'brikpanel' ) ] );
		}

		// A recurring entry is a template: (re)build one concrete dated row per
		// elapsed period, up to today, so the figure is correct immediately and
		// not only after the next view. On edit the previous occurrences were
		// just cleared above, so this always produces a clean, current series.
		$materialized = 0;
		if ( 'none' !== $recurring ) {
			// Read the row back rather than hand-building it: the materialiser
			// needs the real created_at to look up which occurrences this
			// template had removed, and a synthetic object cannot supply it.
			$saved = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, expense_date, category, description, amount, recurring, created_at FROM {$table} WHERE id = %d",
				$id
			) ); // phpcs:ignore
			if ( $saved ) {
				$materialized = self::materialize_template( $saved );
			}
		}

		self::bust_dashboard_cache();

		/**
		 * Fires after an expense is created or edited in wp-admin.
		 *
		 * The Google Sheets expense sync listens for this to refresh its tab,
		 * so a cost added here shows up in the spreadsheet without waiting for
		 * a manual sync. Passed the row id and whether it was newly inserted.
		 *
		 * @param int  $id       Expense row id.
		 * @param bool $is_new   True when this call created the row.
		 */
		do_action( 'brikpanel_expense_saved', $id, 0 === absint( $_POST['id'] ?? 0 ) );

		wp_send_json_success( [ 'id' => $id, 'materialized' => $materialized ] );
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
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, expense_date, recurring, recurring_parent FROM {$table} WHERE id = %d",
			$id
		) ); // phpcs:ignore
		if ( ! $row ) {
			wp_send_json_success(); // already gone — deleting twice is not an error
		}

		// One occurrence of a recurring expense: record the date FIRST. The
		// materialiser only ever inserts, so a crash between these two steps
		// leaves a skip for a row that still exists — harmless, and the skip is
		// kept because that date is still part of the series. The other order
		// would resurrect the row on the very next read.
		if ( (int) $row->recurring_parent > 0 ) {
			self::add_skip( (int) $row->recurring_parent, (string) $row->expense_date );
		}

		$wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
		// Deleting a recurring template removes its whole materialised series so
		// the occurrences don't linger as orphans. A no-op for one-off rows /
		// individual occurrences (nothing references their id as a parent).
		$wpdb->delete( $table, [ 'recurring_parent' => $id ], [ '%d' ] );

		// The series is gone, so its skipped dates have nothing left to suppress.
		if ( 'none' !== (string) $row->recurring && 0 === (int) $row->recurring_parent ) {
			self::clear_skips( $id );
		}

		self::bust_dashboard_cache();

		/**
		 * Fires after an expense (and any generated repeats) is deleted.
		 *
		 * @param int $id Expense row id that was removed.
		 */
		do_action( 'brikpanel_expense_deleted', $id );

		wp_send_json_success();
	}

	// =========================================================================
	// AJAX: remove one line of the dashboard Profit ▸ Expenses breakdown
	//
	// A line on that card is not one expense: manual costs are grouped by title
	// over the selected dates, so "Cleaning $3,200" can be four rows from a
	// weekly series. The browser therefore never decides what to delete — it
	// asks (mode=preview) what a line covers, shows the answer, and sends back
	// the choice (mode=commit) together with the token the preview issued. If
	// anything moved in between (another admin, a received purchase order, a new
	// occurrence) the token stops matching and nothing is removed.
	// =========================================================================

	public function ajax_line_delete() {
		$this->check_auth();
		global $wpdb;

		$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? 'preview' ) );
		$type = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
		if ( ! in_array( $mode, [ 'preview', 'commit' ], true ) || ! in_array( $type, [ 'cat', 'percent' ], true ) ) {
			wp_send_json_error( [ 'code' => 'invalid_request', 'message' => __( 'Invalid request.', 'brikpanel' ) ] );
		}

		$plan = ( 'percent' === $type )
			? $this->plan_percent_line( absint( $_POST['id'] ?? 0 ) )
			: $this->plan_category_line(
				sanitize_text_field( wp_unslash( $_POST['cat'] ?? '' ) ),
				sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) ),
				sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) )
			);

		if ( isset( $plan['error'] ) ) {
			wp_send_json_error( [ 'code' => $plan['error'], 'message' => $plan['message'] ] );
		}

		if ( 'preview' === $mode ) {
			wp_send_json_success( $plan['public'] );
		}

		// The preview is recomputed from scratch above, so this compares what the
		// merchant was shown against what is true right now.
		if ( ! hash_equals( (string) $plan['public']['token'], (string) sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ) ) ) {
			wp_send_json_error( [
				'code'    => 'stale',
				'message' => __( 'These figures changed while this dialog was open. Close it and try again.', 'brikpanel' ),
			] );
		}

		$scope = sanitize_key( wp_unslash( $_POST['scope'] ?? '' ) );
		if ( empty( $plan['ids'][ $scope ] ) ) {
			wp_send_json_error( [ 'code' => 'invalid_scope', 'message' => __( 'Nothing to remove here.', 'brikpanel' ) ] );
		}

		$table   = $wpdb->prefix . self::TABLE;
		$ids     = array_map( 'absint', (array) $plan['ids'][ $scope ] );
		$ids     = array_values( array_filter( $ids ) );
		$deleted = 0;

		if ( $ids ) {
			// Skips FIRST. The materialiser only ever inserts, so a skip for a row
			// that still exists is harmless; a deleted row with no skip is back on
			// the next read.
			if ( 'period' === $scope ) {
				foreach ( (array) $plan['skip_pairs'] as $pair ) {
					self::add_skip( (int) $pair[0], (string) $pair[1] );
				}
			}

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$deleted      = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table} WHERE id IN ({$placeholders})", // phpcs:ignore
				$ids
			) ); // phpcs:ignore

			// Removing whole series: their skipped dates now point at nothing.
			if ( 'series' === $scope ) {
				foreach ( (array) $plan['series_ids'] as $tid ) {
					self::clear_skips( (int) $tid );
				}
			}
		}

		self::bust_dashboard_cache();

		// Fired only after every write, so a listener that reads the table back
		// (the Google Sheets push does) never sees a half-removed line. Generated
		// occurrences are not synced anywhere, so only managed rows are announced.
		foreach ( (array) ( $plan['managed_ids'][ $scope ] ?? [] ) as $mid ) {
			/** This is the documented per-row delete hook, see ajax_delete(). */
			do_action( 'brikpanel_expense_deleted', (int) $mid );
		}

		$removed = _n( '%d entry removed.', '%d entries removed.', $deleted, 'brikpanel' );

		wp_send_json_success( [
			'deleted' => $deleted,
			'message' => sprintf( $removed, $deleted ),
		] );
	}

	/**
	 * What removing a percentage cost would do. It is one row, always on, with no
	 * date window and therefore no choice of scope.
	 */
	private function plan_percent_line( int $id ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$row = $id > 0 ? $wpdb->get_row( $wpdb->prepare(
			"SELECT id, category, amount FROM {$table} WHERE id = %d AND kind = 'percent'",
			$id
		) ) : null; // phpcs:ignore

		if ( ! $row ) {
			return [ 'error' => 'not_found', 'message' => __( 'This expense no longer exists.', 'brikpanel' ) ];
		}

		// Same fallback the Profit card uses, so the dialog names the line the
		// way the card does.
		$title = trim( (string) $row->category );
		if ( '' === $title ) {
			$title = __( 'Commission', 'brikpanel' );
		}
		$rate = rtrim( rtrim( number_format( (float) $row->amount, 2, '.', '' ), '0' ), '.' );

		return [
			'public' => [
				'token'  => $this->plan_token( [ 'percent', (int) $row->id, (string) $row->amount ] ),
				'title'  => __( 'Remove this expense?', 'brikpanel' ),
				/* translators: 1: name of the cost, 2: percentage rate, e.g. 2.9. */
				'body'   => sprintf( __( '%1$s: %2$s%% of revenue.', 'brikpanel' ), $title, $rate ),
				'note'   => __( 'This cost is a percentage of revenue, so removing it affects every period.', 'brikpanel' ),
				'scopes' => [
					[
						'id'     => 'period',
						'label'  => __( 'Remove', 'brikpanel' ),
						'detail' => '',
					],
				],
			],
			'ids'         => [ 'period' => [ (int) $row->id ] ],
			'managed_ids' => [ 'period' => [ (int) $row->id ] ],
			'skip_pairs'  => [],
			'series_ids'  => [],
		];
	}

	/**
	 * What removing a grouped expense line would do, for both scopes.
	 *
	 * @param string $cat  RAW category as stored (may be ''), never a display label.
	 * @param string $from Y-m-d window start.
	 * @param string $to   Y-m-d window end.
	 */
	private function plan_category_line( string $cat, string $from, string $to ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		if ( ! self::is_ymd( $from ) || ! self::is_ymd( $to ) ) {
			// Never fall back to "today": a bad range must not delete a window
			// nobody asked about.
			return [ 'error' => 'invalid_range', 'message' => __( 'Invalid date range.', 'brikpanel' ) ];
		}
		if ( strtotime( $to ) < strtotime( $from ) ) {
			[ $from, $to ] = [ $to, $from ];
		}

		// kind <> 'percent' mirrors how the card groups these lines. Without it a
		// percentage cost sharing this title — which the card draws as its own
		// separate line — would be swept up silently.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, expense_date, description, category, amount, recurring, recurring_parent
			   FROM {$table}
			  WHERE expense_date BETWEEN %s AND %s AND kind <> 'percent' AND COALESCE(category,'') = %s
			  ORDER BY expense_date ASC, id ASC",
			$from,
			$to,
			$cat
		) ); // phpcs:ignore

		if ( empty( $rows ) ) {
			return [ 'error' => 'not_found', 'message' => __( 'This expense no longer exists.', 'brikpanel' ) ];
		}

		$one_ids    = [];   // standalone entries
		$inst_ids   = [];   // generated occurrences
		$skip_pairs = [];   // [template id, date] per occurrence
		$tpl_ids    = [];   // series whose starting entry sits inside the window
		$parents    = [];   // series reaching into the window
		$total      = 0.0;
		$tpl_total  = 0.0;
		$one_total  = 0.0;

		foreach ( $rows as $r ) {
			$total += (float) $r->amount;
			$parent = (int) $r->recurring_parent;
			if ( $parent > 0 ) {
				$inst_ids[]   = (int) $r->id;
				$skip_pairs[] = [ $parent, substr( (string) $r->expense_date, 0, 10 ) ];
				$parents[ $parent ] = true;
			} elseif ( 'none' !== (string) $r->recurring ) {
				$tpl_ids[]  = (int) $r->id;
				$tpl_total += (float) $r->amount;
				$parents[ (int) $r->id ] = true;
			} else {
				$one_ids[]  = (int) $r->id;
				$one_total += (float) $r->amount;
			}
		}

		$series_ids = array_map( 'absint', array_keys( $parents ) );
		sort( $series_ids );

		// Footprint of every series involved — by parentage, NOT by category. An
		// individual occurrence can be edited on the Expenses page and end up
		// under a different title, and "the whole series" must still account for
		// it rather than leave an orphan behind.
		$series_row_ids = [];
		$series_total   = 0.0;
		if ( $series_ids ) {
			$ph   = implode( ',', array_fill( 0, count( $series_ids ), '%d' ) );
			$args = array_merge( $series_ids, $series_ids );
			$srows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, amount FROM {$table} WHERE id IN ({$ph}) OR recurring_parent IN ({$ph})", // phpcs:ignore
				$args
			) ); // phpcs:ignore
			foreach ( (array) $srows as $sr ) {
				$series_row_ids[] = (int) $sr->id;
				$series_total    += (float) $sr->amount;
			}
		}

		$label = $this->line_label( $cat );

		// --- scope: only this period -----------------------------------------
		// The starting entry of a series is deliberately left out: its date is
		// what the whole series is derived from, so removing it alone would
		// either kill the series or silently re-anchor every future occurrence.
		$period_ids   = array_merge( $one_ids, $inst_ids );
		$period_total = $total - $tpl_total;

		$scopes  = [];
		$ids     = [];
		$managed = [];

		if ( $period_ids ) {
			$n      = count( $period_ids );
			$detail = _n( 'Removes %1$d entry · %2$s', 'Removes %1$d entries · %2$s', $n, 'brikpanel' );
			$scopes[] = [
				'id'     => 'period',
				'label'  => $series_ids ? __( 'Only this period', 'brikpanel' ) : __( 'Remove', 'brikpanel' ),
				'detail' => sprintf( $detail, $n, self::money_text( $period_total ) ),
			];
			$ids['period']     = $period_ids;
			$managed['period'] = $one_ids; // occurrences are not synced anywhere
		}

		// --- scope: the whole series -----------------------------------------
		if ( $series_ids ) {
			$all_ids = array_values( array_unique( array_merge( $one_ids, $series_row_ids ) ) );
			$n       = count( $all_ids );
			$detail  = _n( 'Removes %1$d entry · %2$s', 'Removes %1$d entries · %2$s', $n, 'brikpanel' );
			$detail  = sprintf( $detail, $n, self::money_text( $series_total + $one_total ) );

			$outside = $n - count( array_unique( array_merge( $one_ids, $inst_ids, $tpl_ids ) ) );
			if ( $outside > 0 ) {
				/* translators: %d: number of entries dated outside the period on screen. */
				$extra   = _n( 'Includes %d entry outside this period.', 'Includes %d entries outside this period.', $outside, 'brikpanel' );
				$detail .= ' ' . sprintf( $extra, $outside );
			}

			$scopes[] = [
				'id'     => 'series',
				'label'  => __( 'The whole repeating expense', 'brikpanel' ),
				'detail' => $detail,
			];
			$ids['series']     = $all_ids;
			// Only the templates and standalone rows are known to Google Sheets.
			$managed['series'] = array_values( array_unique( array_merge( $one_ids, $series_ids ) ) );
		}

		if ( ! $scopes ) {
			return [ 'error' => 'invalid_scope', 'message' => __( 'Nothing to remove here.', 'brikpanel' ) ];
		}

		// --- copy -------------------------------------------------------------
		$count = count( $rows );
		if ( 1 === $count ) {
			$r0    = $rows[0];
			$name  = trim( (string) $r0->description );
			if ( '' === $name ) {
				$name = $label;
			}
			$title = __( 'Remove this expense?', 'brikpanel' );
			/* translators: 1: expense name, 2: date, 3: amount. */
			$body  = sprintf( __( '%1$s · %2$s · %3$s', 'brikpanel' ), $name, self::date_text( (string) $r0->expense_date ), self::money_text( (float) $r0->amount ) );
		} else {
			$title = __( 'Remove these expenses?', 'brikpanel' );
			/* translators: 1: line name, 2: number of entries, 3: total amount. */
			$many  = _n( '%1$s: %2$d entry, %3$s in this period.', '%1$s: %2$d entries, %3$s in this period.', $count, 'brikpanel' );
			$body  = sprintf( $many, $label, $count, self::money_text( $total ) );
		}

		$notes = [];
		if ( $tpl_ids ) {
			// Only warn about the starting entry being kept when there is in fact
			// a choice; when the whole series is the only option, say that plainly
			// instead of describing an option the dialog is not offering.
			if ( isset( $ids['period'] ) ) {
				/* translators: %s: name of the repeating expense. */
				$notes[] = sprintf( __( 'This period is where %s starts repeating. "Only this period" keeps that first entry. Choose the whole repeating expense to remove it as well.', 'brikpanel' ), $label );
			} else {
				/* translators: %s: name of the repeating expense. */
				$notes[] = sprintf( __( '%s repeats, so removing it also stops every future occurrence.', 'brikpanel' ), $label );
			}
		}
		if ( '' !== $cat && $cat === (string) get_option( 'brikpanel_po_expense_category', 'Inventory' ) ) {
			$notes[] = __( 'These entries were created when stock orders were received. Removing them does not change the purchase orders.', 'brikpanel' );
		}

		$fingerprint = array_merge( [ 'cat', $cat, $from, $to, (string) round( $total, 4 ) ], array_map( 'strval', wp_list_pluck( $rows, 'id' ) ) );

		return [
			'public' => [
				'token'  => $this->plan_token( $fingerprint ),
				'title'  => $title,
				'body'   => $body,
				'note'   => implode( ' ', $notes ),
				'scopes' => $scopes,
			],
			'ids'         => $ids,
			'managed_ids' => $managed,
			'skip_pairs'  => $skip_pairs,
			'series_ids'  => $series_ids,
		];
	}

	/**
	 * How the Profit card names a stored category, mirrored so the dialog and the
	 * card always agree.
	 */
	private function line_label( string $cat ): string {
		if ( '' === $cat ) {
			return __( 'Other', 'brikpanel' );
		}
		if ( $cat === (string) get_option( 'brikpanel_po_expense_category', 'Inventory' ) ) {
			return __( 'Supplier / stock', 'brikpanel' );
		}
		return $cat;
	}

	/** Ties a preview to the exact rows and totals it was built from. */
	private function plan_token( array $parts ): string {
		return hash_hmac( 'sha256', implode( '|', $parts ), wp_salt( 'nonce' ) );
	}

	/** Strict Y-m-d, real calendar date. */
	private static function is_ymd( $value ): bool {
		return is_string( $value )
			&& preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m )
			&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/** Money as plain text: the dialog writes it with textContent, not as HTML. */
	private static function money_text( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** A stored date in the site's format. Midday avoids a timezone day-shift. */
	private static function date_text( string $ymd ): string {
		$ts = strtotime( substr( $ymd, 0, 10 ) . ' 12:00:00' );
		return $ts ? wp_date( (string) get_option( 'date_format' ), $ts ) : substr( $ymd, 0, 10 );
	}

	// =========================================================================
	// Helper: distinct categories
	// =========================================================================

	private function get_categories(): array {
		return self::categories();
	}

	/**
	 * Distinct expense categories already in use, for autocomplete suggestions.
	 * Public + static so other surfaces (the dashboard quick-add) can offer the
	 * same list without duplicating the query.
	 *
	 * @return string[]
	 */
	public static function categories(): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}
		$rows = $wpdb->get_col( "SELECT DISTINCT category FROM {$table} WHERE category != '' ORDER BY category ASC" ); // phpcs:ignore
		return $rows ?: [];
	}

	// =========================================================================
	// Skipped occurrences
	//
	// Deleting one generated occurrence of a recurring expense used to be
	// impossible: the row went away, but the very next read ran the materialiser,
	// which saw a period with no row and inserted it again. A skip records "the
	// merchant removed this date on purpose" so the materialiser leaves it alone.
	//
	// The skips live in their own table rather than as a marker row in
	// brikpanel_expenses, because that table is summed in several places without
	// any filter, and because BOTH paths that edit a recurring expense (saving it
	// here, or the Google Sheets sync updating it) delete every child row and
	// rebuild the series — which would erase an in-table tombstone and resurrect
	// the occurrence on an amount-only edit.
	//
	// Every lookup is keyed by (template id, template created_at). The second
	// half is what makes a recycled AUTO_INCREMENT id harmless: MariaDB rebuilds
	// its counter from MAX(id)+1 after a restart, so a fresh expense can be
	// handed a deleted template's id, and it must not inherit its skipped dates.
	// =========================================================================

	/**
	 * Whether the skips table exists. Cached per request: a missing table only
	 * happens between a plugin update and the next dbDelta run, and every caller
	 * degrades to "no skips" rather than fataling.
	 */
	private static function skips_ready(): bool {
		static $ready = null;
		if ( null !== $ready ) {
			return $ready;
		}
		global $wpdb;
		$table = $wpdb->prefix . self::SKIPS_TABLE;
		$ready = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		return $ready;
	}

	/**
	 * Remember that one occurrence of a recurring expense was removed.
	 *
	 * @param int    $template_id Recurring template the occurrence belonged to.
	 * @param string $date        Occurrence date, Y-m-d.
	 * @return bool True when a skip is now on record.
	 */
	public static function add_skip( int $template_id, string $date ): bool {
		global $wpdb;
		$date = substr( $date, 0, 10 );
		if ( $template_id <= 0 || ! self::skips_ready() || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}

		// Resolve the owning template. An orphan occurrence (its template was
		// deleted out of band) needs no skip: with no template there is nothing
		// left to regenerate it.
		$created_at = $wpdb->get_var( $wpdb->prepare(
			"SELECT created_at FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d AND recurring_parent = 0 AND recurring <> 'none'",
			$template_id
		) ); // phpcs:ignore
		if ( ! $created_at ) {
			return false;
		}

		// INSERT IGNORE against the unique key: two admins clicking at the same
		// moment can never lose one another's skip.
		$skips = $wpdb->prefix . self::SKIPS_TABLE;
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$skips} (template_id, skip_date, tpl_created_at) VALUES (%d, %s, %s)",
			$template_id,
			$date,
			$created_at
		) ); // phpcs:ignore

		return ! $wpdb->last_error;
	}

	/**
	 * Drop every skip belonging to a template. Called when the whole series is
	 * deleted — its skipped dates can no longer mean anything.
	 *
	 * @return int Rows removed.
	 */
	public static function clear_skips( int $template_id ): int {
		global $wpdb;
		if ( $template_id <= 0 || ! self::skips_ready() ) {
			return 0;
		}
		return (int) $wpdb->delete( $wpdb->prefix . self::SKIPS_TABLE, [ 'template_id' => $template_id ], [ '%d' ] );
	}

	/**
	 * Dates the merchant removed from one template.
	 *
	 * @param string $tpl_created_at The template's created_at, used as the
	 *                               identity fingerprint against id reuse.
	 * @return array<string,true> Map of Y-m-d => true.
	 */
	private static function skipped_dates( int $template_id, string $tpl_created_at ): array {
		global $wpdb;
		if ( $template_id <= 0 || '' === $tpl_created_at || ! self::skips_ready() ) {
			return [];
		}
		$skips = $wpdb->prefix . self::SKIPS_TABLE;
		$rows  = $wpdb->get_col( $wpdb->prepare(
			"SELECT skip_date FROM {$skips} WHERE template_id = %d AND tpl_created_at = %s",
			$template_id,
			$tpl_created_at
		) ); // phpcs:ignore

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ substr( (string) $r, 0, 10 ) ] = true;
		}
		return $out;
	}

	/**
	 * Remove skips whose template no longer exists — including the case where
	 * the id still exists but now belongs to a DIFFERENT expense (created_at no
	 * longer matches), which is exactly what a recycled AUTO_INCREMENT id looks
	 * like. One statement, run from the materialiser's own throttled sweep.
	 */
	private static function gc_skips(): void {
		global $wpdb;
		if ( ! self::skips_ready() ) {
			return;
		}
		$skips    = $wpdb->prefix . self::SKIPS_TABLE;
		$expenses = $wpdb->prefix . self::TABLE;
		$wpdb->query(
			"DELETE s FROM {$skips} s
			 LEFT JOIN {$expenses} e
			        ON e.id = s.template_id
			       AND e.recurring_parent = 0
			       AND e.recurring <> 'none'
			       AND e.created_at = s.tpl_created_at
			 WHERE e.id IS NULL"
		); // phpcs:ignore
	}

	// =========================================================================
	// Recurring materialiser
	//
	// A recurring expense is stored as ONE "template" row (recurring != none,
	// recurring_parent = 0). The materialiser turns it into concrete dated rows
	// (recurring = 'none', recurring_parent = template id) — one per elapsed
	// period — so the profit aggregation, which simply sums rows by date, never
	// has to know about recurrence and can't double-count. The template itself
	// is the first occurrence (it has a real date); instances cover every period
	// after it, up to today. Idempotent: an instance is only inserted when no
	// row already exists for that (template, date).
	// =========================================================================

	/**
	 * Period dates strictly AFTER $start, up to and including $until's period.
	 * Day-of-month is preserved and clamped to the target month's length
	 * (Jan 31 monthly → Feb 28/29), so a recurring expense never drifts.
	 *
	 * @return string[] Y-m-d dates.
	 */
	public static function occurrence_dates( string $start, string $recurring, string $until ): array {
		$out = [];
		try {
			$start_dt = new DateTimeImmutable( $start );
			$until_dt = new DateTimeImmutable( $until );
		} catch ( Exception $e ) {
			return $out;
		}
		if ( $start_dt > $until_dt ) {
			return $out;
		}
		$start_day = (int) $start_dt->format( 'j' );
		$start_m   = (int) $start_dt->format( 'n' );
		$start_y   = (int) $start_dt->format( 'Y' );

		// Hard safety cap: ~100 years of months. Stops any pathological loop.
		for ( $k = 1; $k <= 1200; $k++ ) {
			if ( 'weekly' === $recurring ) {
				$d = $start_dt->modify( '+' . ( 7 * $k ) . ' days' );
			} elseif ( 'yearly' === $recurring ) {
				$y   = $start_y + $k;
				$dim = (int) ( new DateTimeImmutable( sprintf( '%04d-%02d-01', $y, $start_m ) ) )->format( 't' );
				$d   = new DateTimeImmutable( sprintf( '%04d-%02d-%02d', $y, $start_m, min( $start_day, $dim ) ) );
			} else { // monthly
				$base = $start_dt->modify( "first day of +{$k} months" );
				$dim  = (int) $base->format( 't' );
				$d    = $base->setDate( (int) $base->format( 'Y' ), (int) $base->format( 'n' ), min( $start_day, $dim ) );
			}
			if ( $d > $until_dt ) {
				break;
			}
			$out[] = $d->format( 'Y-m-d' );
		}
		return $out;
	}

	/**
	 * Fill in any missing occurrence rows for one template, up to today.
	 *
	 * @param object $tpl Row with id, expense_date, category, description, amount,
	 *                    recurring and created_at (the last one identifies the
	 *                    template when reading its skipped dates).
	 * @return int Number of instance rows inserted.
	 */
	public static function materialize_template( $tpl ): int {
		global $wpdb;
		$recurring = (string) ( $tpl->recurring ?? 'none' );
		if ( ! in_array( $recurring, [ 'monthly', 'weekly', 'yearly' ], true ) ) {
			return 0;
		}
		$tid   = (int) $tpl->id;
		$table = $wpdb->prefix . self::TABLE;
		$today = current_time( 'Y-m-d' ); // site timezone — matches how expenses are dated
		$dates = self::occurrence_dates( substr( (string) $tpl->expense_date, 0, 10 ), $recurring, $today );

		// Skips are keyed by (template id, template created_at). A caller that
		// hand-builds the template object has no created_at to give us, so look
		// it up rather than treating the series as having no removals: getting
		// this wrong silently re-creates every occurrence the merchant deleted.
		$created_at = (string) ( $tpl->created_at ?? '' );
		if ( '' === $created_at ) {
			$created_at = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT created_at FROM {$table} WHERE id = %d",
				$tid
			) ); // phpcs:ignore
		}

		// Load and tidy the skipped dates BEFORE the early return below, so a
		// template whose next occurrence has not arrived yet (a yearly one, say)
		// keeps its skips instead of silently losing them.
		$skips = self::skipped_dates( $tid, $created_at );
		if ( $skips ) {
			// Only prune what is provably obsolete: a date that has already come
			// round AND falls inside the series we just recomputed AND is no
			// longer one of its occurrences. Anything in the future, or past the
			// last date this run produced, is left alone — occurrence_dates()
			// stops at today and caps at 1200 iterations, so "not in $dates" on
			// its own would throw away perfectly valid future skips.
			$last  = $dates ? end( $dates ) : '';
			$live  = array_flip( $dates );
			$stale = [];
			foreach ( array_keys( $skips ) as $d ) {
				if ( $d <= $today && '' !== $last && $d <= $last && ! isset( $live[ $d ] ) ) {
					$stale[] = $d;
				}
			}
			if ( $stale ) {
				$skips_table  = $wpdb->prefix . self::SKIPS_TABLE;
				$placeholders = implode( ',', array_fill( 0, count( $stale ), '%s' ) );
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$skips_table} WHERE template_id = %d AND skip_date IN ({$placeholders})", // phpcs:ignore
					array_merge( [ $tid ], $stale )
				) ); // phpcs:ignore
				foreach ( $stale as $d ) {
					unset( $skips[ $d ] );
				}
			}
		}

		if ( empty( $dates ) ) {
			return 0;
		}

		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT expense_date FROM {$table} WHERE recurring_parent = %d",
			$tid
		) ); // phpcs:ignore
		$have = [];
		foreach ( (array) $rows as $r ) {
			$have[ substr( (string) $r, 0, 10 ) ] = true;
		}
		// A removed occurrence counts as "already handled": same effect as the
		// row still being there, without the row.
		foreach ( array_keys( $skips ) as $d ) {
			$have[ $d ] = true;
		}

		$inserted = 0;
		foreach ( $dates as $d ) {
			if ( isset( $have[ $d ] ) ) {
				continue;
			}
			$wpdb->insert(
				$table,
				[
					'expense_date'     => $d,
					'category'         => (string) $tpl->category,
					'description'      => (string) $tpl->description,
					'amount'           => (float) $tpl->amount,
					'recurring'        => 'none',
					'recurring_parent' => $tid,
				],
				[ '%s', '%s', '%s', '%f', '%s', '%d' ]
			);
			if ( ! $wpdb->last_error ) {
				$inserted++;
			}
		}
		return $inserted;
	}

	/**
	 * Lazily catch every managed template up to today. Transient-gated so the
	 * sweep runs at most a few times a day (the only thing that adds work is a
	 * crossed period boundary). Only templates created at/after the engine
	 * became available are processed, so legacy "monthly"-labelled rows are
	 * left exactly as they aggregated before. Called from the surfaces that
	 * read expense totals (dashboard payload, the Expenses page) so the data is
	 * always current when viewed, with no cron to keep alive.
	 *
	 * @return int Rows inserted this run (0 when gated or nothing due).
	 */
	public static function materialize_due(): int {
		if ( get_transient( 'brikpanel_expenses_materialized' ) ) {
			return 0;
		}
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}

		// Retire skips whose template is gone (or whose id now belongs to a
		// different expense) while we are already doing the periodic sweep.
		self::gc_skips();

		// created_at comes along because it identifies the template when reading
		// its skipped dates. The kind filter keeps a percentage cost out: its
		// `amount` is a RATE, and materialising it would multiply that rate into
		// real money rows. ajax_save() forces percent rows to recurring='none',
		// so this only guards hand-written or legacy data.
		$since = (string) get_option( 'brikpanel_recurring_engine_since', '' );
		$sql   = "SELECT id, expense_date, category, description, amount, recurring, created_at
			FROM {$table}
			WHERE recurring IN ('monthly','weekly','yearly') AND recurring_parent = 0
			  AND kind <> 'percent'";
		if ( '' !== $since ) {
			$sql .= $wpdb->prepare( ' AND created_at >= %s', $since );
		}
		$templates = $wpdb->get_results( $sql ); // phpcs:ignore

		$inserted = 0;
		foreach ( (array) $templates as $tpl ) {
			$inserted += self::materialize_template( $tpl );
		}

		// 6h gate: a period boundary is crossed at most once a day, so this is
		// plenty fresh while keeping the read path almost always a no-op.
		set_transient( 'brikpanel_expenses_materialized', 1, 6 * HOUR_IN_SECONDS );

		if ( $inserted > 0 && function_exists( 'brikpanel_bust_data_caches' ) ) {
			brikpanel_bust_data_caches(); // new dated rows change the dashboard totals
		}
		return $inserted;
	}

	/**
	 * Invalidate the cached dashboard payload + revenue transients so a saved
	 * or deleted expense shows in the Profit section immediately.
	 */
	private static function bust_dashboard_cache(): void {
		if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
			brikpanel_bust_data_caches();
		}
		// The materialiser may need to re-run for a freshly-saved template even
		// though it ran earlier this period.
		delete_transient( 'brikpanel_expenses_materialized' );
	}
}

new Brikpanel_Expenses();
