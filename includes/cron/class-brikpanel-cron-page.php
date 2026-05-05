<?php
/**
 * BrikPanel — Scheduled Tasks admin page.
 *
 * Surfaces every action stored under the `brikpanel` Action Scheduler
 * group, with run-now / retry / cancel / view-log controls. Designed to
 * stay scoped to BrikPanel jobs only — WooCommerce's own scheduled
 * actions remain visible at Tools → Scheduled Actions and never appear
 * here.
 *
 * @package BrikPanel
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Cron_Page {

	const PAGE_SLUG    = 'brikpanel-cron';
	const NONCE_ACTION = 'brikpanel_cron_nonce';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_action( 'wp_ajax_brikpanel_cron_list',     [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_brikpanel_cron_kpis',     [ $this, 'ajax_kpis' ] );
		add_action( 'wp_ajax_brikpanel_cron_run_now',  [ $this, 'ajax_run_now' ] );
		add_action( 'wp_ajax_brikpanel_cron_retry',    [ $this, 'ajax_retry' ] );
		add_action( 'wp_ajax_brikpanel_cron_cancel',   [ $this, 'ajax_cancel' ] );
		add_action( 'wp_ajax_brikpanel_cron_logs',     [ $this, 'ajax_logs' ] );
	}

	// =========================================================================
	// Menu registration
	// =========================================================================

	public function register_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Scheduled Tasks', 'brikpanel' ),
			__( 'Scheduled Tasks', 'brikpanel' ),
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
	// Page render (HTML shell — table body is hydrated via AJAX)
	// =========================================================================

	public function render_page() {
		$nonce    = wp_create_nonce( self::NONCE_ACTION );
		$ajax_url = admin_url( 'admin-ajax.php' );
		$known    = Brikpanel_Cron::get_registered_hooks();
		$as_ready = Brikpanel_Cron::is_available();
		?>
		<div class="wrap brikpanel-cron-wrap" id="brikpanel-cron">
			<div class="brikpanel-cron-header">
				<div class="brikpanel-cron-header-left">
					<h1><?php esc_html_e( 'Scheduled Tasks', 'brikpanel' ); ?></h1>
					<p class="brikpanel-cron-subtitle">
						<?php esc_html_e( 'Background jobs and recurring tasks running through Action Scheduler.', 'brikpanel' ); ?>
					</p>
				</div>
				<div class="brikpanel-cron-header-right">
					<button type="button" class="brikpanel-cron-btn brikpanel-cron-btn-secondary" id="brikpanel-cron-refresh-btn">
						<?php esc_html_e( 'Refresh', 'brikpanel' ); ?>
					</button>
				</div>
			</div>

			<?php if ( ! $as_ready ) : ?>
				<div class="brikpanel-cron-card brikpanel-cron-warning">
					<strong><?php esc_html_e( 'Action Scheduler not loaded.', 'brikpanel' ); ?></strong>
					<p><?php esc_html_e( 'Make sure WooCommerce is active. Background tasks are paused until Action Scheduler is available.', 'brikpanel' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- KPI cards -->
			<div class="brikpanel-cron-kpis" id="brikpanel-cron-kpis">
				<div class="brikpanel-cron-kpi" data-kpi="pending">
					<div class="brikpanel-cron-kpi-label"><?php esc_html_e( 'Pending', 'brikpanel' ); ?></div>
					<div class="brikpanel-cron-kpi-value">—</div>
				</div>
				<div class="brikpanel-cron-kpi" data-kpi="running">
					<div class="brikpanel-cron-kpi-label"><?php esc_html_e( 'Running', 'brikpanel' ); ?></div>
					<div class="brikpanel-cron-kpi-value">—</div>
				</div>
				<div class="brikpanel-cron-kpi" data-kpi="failed">
					<div class="brikpanel-cron-kpi-label"><?php esc_html_e( 'Failed (24h)', 'brikpanel' ); ?></div>
					<div class="brikpanel-cron-kpi-value">—</div>
				</div>
				<div class="brikpanel-cron-kpi" data-kpi="complete">
					<div class="brikpanel-cron-kpi-label"><?php esc_html_e( 'Done (24h)', 'brikpanel' ); ?></div>
					<div class="brikpanel-cron-kpi-value">—</div>
				</div>
			</div>

			<!-- Filters -->
			<div class="brikpanel-cron-card brikpanel-cron-filters">
				<div class="brikpanel-cron-filter-row">
					<div class="brikpanel-cron-field">
						<label for="brikpanel-cron-status-filter"><?php esc_html_e( 'Status', 'brikpanel' ); ?></label>
						<select id="brikpanel-cron-status-filter">
							<option value=""><?php esc_html_e( 'All statuses', 'brikpanel' ); ?></option>
							<option value="pending"><?php esc_html_e( 'Pending', 'brikpanel' ); ?></option>
							<option value="in-progress"><?php esc_html_e( 'Running', 'brikpanel' ); ?></option>
							<option value="complete"><?php esc_html_e( 'Done', 'brikpanel' ); ?></option>
							<option value="failed"><?php esc_html_e( 'Failed', 'brikpanel' ); ?></option>
							<option value="canceled"><?php esc_html_e( 'Cancelled', 'brikpanel' ); ?></option>
						</select>
					</div>
					<div class="brikpanel-cron-field">
						<label for="brikpanel-cron-hook-filter"><?php esc_html_e( 'Job type', 'brikpanel' ); ?></label>
						<select id="brikpanel-cron-hook-filter">
							<option value=""><?php esc_html_e( 'All job types', 'brikpanel' ); ?></option>
							<?php foreach ( $known as $hook => $meta ) : ?>
								<option value="<?php echo esc_attr( $hook ); ?>">
									<?php echo esc_html( $meta['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="brikpanel-cron-filter-actions">
						<button type="button" class="brikpanel-cron-btn brikpanel-cron-btn-secondary" id="brikpanel-cron-apply-btn">
							<?php esc_html_e( 'Apply', 'brikpanel' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Actions table -->
			<div class="brikpanel-cron-card brikpanel-cron-table-card">
				<div class="brikpanel-cron-table-wrap">
					<table class="brikpanel-cron-table" id="brikpanel-cron-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Job type', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Status', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Scheduled', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Recurring', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Args', 'brikpanel' ); ?></th>
								<th class="brikpanel-cron-actions-th"></th>
							</tr>
						</thead>
						<tbody id="brikpanel-cron-tbody">
							<tr><td colspan="6" class="brikpanel-cron-empty"><?php esc_html_e( 'Loading…', 'brikpanel' ); ?></td></tr>
						</tbody>
					</table>
				</div>
				<div class="brikpanel-cron-pagination" id="brikpanel-cron-pagination" hidden>
					<button type="button" class="brikpanel-cron-btn brikpanel-cron-btn-secondary" id="brikpanel-cron-prev">&larr; <?php esc_html_e( 'Previous', 'brikpanel' ); ?></button>
					<span class="brikpanel-cron-page-info" id="brikpanel-cron-page-info">1 / 1</span>
					<button type="button" class="brikpanel-cron-btn brikpanel-cron-btn-secondary" id="brikpanel-cron-next"><?php esc_html_e( 'Next', 'brikpanel' ); ?> &rarr;</button>
				</div>
			</div>

			<!-- Log modal -->
			<div class="brikpanel-cron-overlay" id="brikpanel-cron-log-overlay" hidden>
				<div class="brikpanel-cron-modal" role="dialog" aria-modal="true" aria-labelledby="brikpanel-cron-log-title">
					<div class="brikpanel-cron-modal-header">
						<h2 id="brikpanel-cron-log-title"><?php esc_html_e( 'Action log', 'brikpanel' ); ?></h2>
						<button type="button" class="brikpanel-cron-modal-close" id="brikpanel-cron-log-close" aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">&times;</button>
					</div>
					<div class="brikpanel-cron-modal-body" id="brikpanel-cron-log-body">
						<div class="brikpanel-cron-empty"><?php esc_html_e( 'Loading…', 'brikpanel' ); ?></div>
					</div>
				</div>
			</div>

			<!-- Toast -->
			<div class="brikpanel-cron-toast" id="brikpanel-cron-toast" hidden></div>
		</div>

		<script>
			window.brikpanelCron = {
				ajax_url: <?php echo wp_json_encode( esc_url_raw( $ajax_url ) ); ?>,
				nonce:    <?php echo wp_json_encode( $nonce ); ?>,
				i18n: {
					confirm_cancel: <?php echo wp_json_encode( __( 'Cancel this scheduled job?', 'brikpanel' ) ); ?>,
					confirm_run:    <?php echo wp_json_encode( __( 'Run this job now?', 'brikpanel' ) ); ?>,
					error:          <?php echo wp_json_encode( __( 'Something went wrong.', 'brikpanel' ) ); ?>,
					no_jobs:        <?php echo wp_json_encode( __( 'No scheduled jobs match these filters.', 'brikpanel' ) ); ?>,
					no_logs:        <?php echo wp_json_encode( __( 'No log entries for this action.', 'brikpanel' ) ); ?>,
					run_now:        <?php echo wp_json_encode( __( 'Run now', 'brikpanel' ) ); ?>,
					retry:          <?php echo wp_json_encode( __( 'Retry', 'brikpanel' ) ); ?>,
					cancel:         <?php echo wp_json_encode( __( 'Cancel', 'brikpanel' ) ); ?>,
					view_logs:      <?php echo wp_json_encode( __( 'Logs', 'brikpanel' ) ); ?>,
					recurring_yes:  <?php echo wp_json_encode( __( 'Yes', 'brikpanel' ) ); ?>,
					recurring_no:   <?php echo wp_json_encode( __( 'No', 'brikpanel' ) ); ?>,
					done_running:   <?php echo wp_json_encode( __( 'Job executed.', 'brikpanel' ) ); ?>,
					done_retried:   <?php echo wp_json_encode( __( 'Job re-queued.', 'brikpanel' ) ); ?>,
					done_cancelled: <?php echo wp_json_encode( __( 'Job cancelled.', 'brikpanel' ) ); ?>,
				}
			};
		</script>
		<?php
	}

	// =========================================================================
	// AJAX: KPI counts
	// =========================================================================

	public function ajax_kpis() {
		$this->check_auth();

		$pending  = Brikpanel_Cron::count( 'pending' );
		$running  = Brikpanel_Cron::count( 'in-progress' );
		$failed   = $this->count_recent( 'failed', DAY_IN_SECONDS );
		$complete = $this->count_recent( 'complete', DAY_IN_SECONDS );

		wp_send_json_success( [
			'pending'  => $pending,
			'running'  => $running,
			'failed'   => $failed,
			'complete' => $complete,
		] );
	}

	/**
	 * Count actions in our group with the given status whose date_gmt is
	 * within the last $window seconds.
	 *
	 * @param string $status
	 * @param int    $window
	 * @return int
	 */
	private function count_recent( $status, $window ) {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return 0;
		}
		try {
			$store = ActionScheduler::store();
		} catch ( \Throwable $e ) {
			return 0;
		}
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $window );
		return (int) $store->query_actions( [
			'group'         => Brikpanel_Cron::GROUP,
			'status'        => $status,
			'date'          => $since,
			'date_compare'  => '>=',
		], 'count' );
	}

	// =========================================================================
	// AJAX: list actions
	// =========================================================================

	public function ajax_list() {
		$this->check_auth();

		$status   = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );
		$hook     = sanitize_text_field( wp_unslash( $_POST['hook']   ?? '' ) );
		$page     = max( 1, absint( $_POST['page'] ?? 1 ) );
		$per_page = 25;

		if ( ! class_exists( 'ActionScheduler' ) ) {
			wp_send_json_success( [
				'items' => [], 'total_count' => 0, 'page' => 1, 'pages' => 1,
			] );
		}

		try {
			$store = ActionScheduler::store();
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}

		$query = [
			'group'    => Brikpanel_Cron::GROUP,
			'per_page' => $per_page,
			'offset'   => ( $page - 1 ) * $per_page,
			'orderby'  => 'date',
			'order'    => 'DESC',
		];
		if ( $status !== '' ) {
			$query['status'] = $status;
		}
		if ( $hook !== '' ) {
			$query['hook'] = $hook;
		}

		$total_count = (int) $store->query_actions(
			array_diff_key( $query, [ 'per_page' => 1, 'offset' => 1 ] ),
			'count'
		);
		$action_ids  = $store->query_actions( $query );

		$items   = [];
		$known   = Brikpanel_Cron::get_registered_hooks();
		$dt_fmt  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		foreach ( (array) $action_ids as $aid ) {
			$aid    = (int) $aid;
			$action = $store->fetch_action( $aid );
			if ( ! $action ) {
				continue;
			}
			$row_status = $store->get_status( $aid );
			$status_def = Brikpanel_Cron::describe_status( $row_status );

			$schedule = method_exists( $action, 'get_schedule' ) ? $action->get_schedule() : null;
			$next_dt  = ( $schedule && method_exists( $schedule, 'get_date' ) ) ? $schedule->get_date() : null;

			$is_recurring = false;
			if ( $schedule ) {
				if ( method_exists( $schedule, 'is_recurring' ) ) {
					$is_recurring = (bool) $schedule->is_recurring();
				} else {
					$is_recurring = ( $schedule instanceof \ActionScheduler_IntervalSchedule )
						|| ( $schedule instanceof \ActionScheduler_CronSchedule );
				}
			}

			$hook_name = $action->get_hook();
			$args      = (array) $action->get_args();
			// We always wrap args as [ $payload ] in enqueue_async; unwrap for display.
			$display_args = ( count( $args ) === 1 && is_array( $args[0] ?? null ) ) ? $args[0] : $args;

			$items[] = [
				'id'             => $aid,
				'hook'           => $hook_name,
				'label'          => $known[ $hook_name ]['label']       ?? self::humanise( $hook_name ),
				'description'    => $known[ $hook_name ]['description'] ?? '',
				'status'         => (string) $row_status,
				'status_label'   => $status_def['label'],
				'status_tone'    => $status_def['tone'],
				'recurring'      => $is_recurring,
				'scheduled_iso'  => $next_dt ? $next_dt->format( 'c' ) : '',
				'scheduled_fmt'  => $next_dt ? wp_date( $dt_fmt, $next_dt->getTimestamp() ) : '—',
				'args_preview'   => $this->args_preview( $display_args ),
			];
		}

		wp_send_json_success( [
			'items'       => $items,
			'total_count' => $total_count,
			'page'        => $page,
			'pages'       => max( 1, (int) ceil( $total_count / $per_page ) ),
		] );
	}

	private static function humanise( $hook ) {
		$hook = preg_replace( '/^brikpanel[_\-]/', '', (string) $hook );
		$hook = str_replace( [ '_', '-' ], ' ', $hook );
		return ucwords( strtolower( $hook ) );
	}

	private function args_preview( $args ) {
		if ( empty( $args ) ) {
			return '—';
		}
		$json = wp_json_encode( $args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( $json === false ) {
			return '—';
		}
		if ( strlen( $json ) > 80 ) {
			$json = substr( $json, 0, 77 ) . '…';
		}
		return $json;
	}

	// =========================================================================
	// AJAX: run now
	// =========================================================================

	public function ajax_run_now() {
		$this->check_auth();
		$id = absint( $_POST['action_id'] ?? 0 );
		$result = Brikpanel_Cron::run_now( $id );
		if ( ! $result['ok'] ) {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}
		wp_send_json_success( [ 'message' => $result['message'] ] );
	}

	// =========================================================================
	// AJAX: retry
	// =========================================================================

	public function ajax_retry() {
		$this->check_auth();
		$id = absint( $_POST['action_id'] ?? 0 );
		$result = Brikpanel_Cron::retry( $id );
		if ( ! $result['ok'] ) {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}
		wp_send_json_success( [ 'message' => $result['message'], 'new_id' => $result['new_id'] ?? 0 ] );
	}

	// =========================================================================
	// AJAX: cancel
	// =========================================================================

	public function ajax_cancel() {
		$this->check_auth();
		$id = absint( $_POST['action_id'] ?? 0 );
		$result = Brikpanel_Cron::cancel_by_id( $id );
		if ( ! $result['ok'] ) {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}
		wp_send_json_success( [ 'message' => $result['message'] ] );
	}

	// =========================================================================
	// AJAX: logs
	// =========================================================================

	public function ajax_logs() {
		$this->check_auth();
		$id = absint( $_POST['action_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid action ID.', 'brikpanel' ) ] );
		}
		// Confirm ownership before exposing logs.
		try {
			$action = ActionScheduler::store()->fetch_action( $id );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		if ( ! $action || ( method_exists( $action, 'get_group' ) && $action->get_group() !== Brikpanel_Cron::GROUP ) ) {
			wp_send_json_error( [ 'message' => __( 'Action not found.', 'brikpanel' ) ], 404 );
		}
		$entries = Brikpanel_Cron::get_logs( $id );
		wp_send_json_success( [ 'entries' => $entries ] );
	}
}

new Brikpanel_Cron_Page();
