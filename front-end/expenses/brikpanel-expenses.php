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
										<label for="brikpanel-ex-kind"><?php esc_html_e( 'Type', 'brikpanel' ); ?></label>
										<select id="brikpanel-ex-kind">
											<option value="fixed"><?php esc_html_e( 'Fixed amount', 'brikpanel' ); ?></option>
											<option value="percent"><?php esc_html_e( 'Percentage of revenue', 'brikpanel' ); ?></option>
										</select>
									</div>
									<div class="brikpanel-ex-field">
									<label for="brikpanel-ex-amount"><?php esc_html_e( 'Amount', 'brikpanel' ); ?></label>
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
			$materialized = self::materialize_template( (object) [
				'id'           => $id,
				'expense_date' => $date,
				'category'     => $category,
				'description'  => $description,
				'amount'       => $amount,
				'recurring'    => $recurring,
			] );
		}

		self::bust_dashboard_cache();

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
		$wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
		// Deleting a recurring template removes its whole materialised series so
		// the occurrences don't linger as orphans. A no-op for one-off rows /
		// individual occurrences (nothing references their id as a parent).
		$wpdb->delete( $table, [ 'recurring_parent' => $id ], [ '%d' ] );

		self::bust_dashboard_cache();

		wp_send_json_success();
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
	 * @param object $tpl Row with id, expense_date, category, description, amount, recurring.
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

		$since = (string) get_option( 'brikpanel_recurring_engine_since', '' );
		$sql   = "SELECT id, expense_date, category, description, amount, recurring
			FROM {$table}
			WHERE recurring IN ('monthly','weekly','yearly') AND recurring_parent = 0";
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
