<?php
/**
 * BrikPanel Customer Analytics — page template.
 *
 * Rendered by Brikpanel_Customer_Analytics::render_page().
 * $metrics is exposed by the renderer.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<div class="bp-ca" id="bp-ca">

		<div class="bp-ca-header">
			<div class="bp-ca-header-left">
				<h1><?php esc_html_e( 'Customer Analytics', 'brikpanel' ); ?></h1>
				<span class="bp-ca-meta" id="bp-ca-meta">
					<?php
					if ( $metrics['last_computed_iso'] ) {
						/* translators: %s: timestamp */
						printf( esc_html__( 'Last refreshed: %s', 'brikpanel' ), esc_html( $metrics['last_computed'] ) );
					} else {
						esc_html_e( 'Not computed yet — running first sync…', 'brikpanel' );
					}
					?>
				</span>
			</div>
			<div class="bp-ca-header-right">
				<button type="button" class="bp-ca-btn bp-ca-btn-secondary" id="bp-ca-exclude">
					<?php esc_html_e( 'Exclude customers', 'brikpanel' ); ?>
					<span class="bp-ca-excl-badge" id="bp-ca-excl-badge" hidden></span>
				</button>
				<button type="button" class="bp-ca-btn bp-ca-btn-secondary" id="bp-ca-refresh">
					<?php esc_html_e( 'Recompute now', 'brikpanel' ); ?>
				</button>
				<button type="button" class="bp-ca-btn bp-ca-btn-primary" id="bp-ca-export">
					<?php esc_html_e( 'Export CSV', 'brikpanel' ); ?>
				</button>
			</div>
		</div>

		<?php
		/**
		 * Fires between the Customer Analytics header and the LTV / RFM / Cohort
		 * tab bar. Used to render the dismissible BrikMentor early-access card.
		 *
		 * @since 3.2.13
		 */
		do_action( 'brikpanel_ca_after_header' );
		?>

		<div class="bp-ca-tabs" role="tablist">
			<button type="button" class="bp-ca-tab is-active" data-tab="ltv" role="tab" aria-selected="true">
				<?php esc_html_e( 'Lifetime Value', 'brikpanel' ); ?>
			</button>
			<button type="button" class="bp-ca-tab" data-tab="rfm" role="tab" aria-selected="false">
				<?php esc_html_e( 'RFM Segments', 'brikpanel' ); ?>
			</button>
			<button type="button" class="bp-ca-tab" data-tab="cohort" role="tab" aria-selected="false">
				<?php esc_html_e( 'Cohort Retention', 'brikpanel' ); ?>
			</button>
		</div>

		<!-- ========================================================== -->
		<!-- LTV TAB                                                      -->
		<!-- ========================================================== -->
		<div class="bp-ca-tabpanel" data-panel="ltv">

			<div class="bp-ca-stats">
				<div class="bp-ca-stat">
					<div class="bp-ca-stat-label"><?php esc_html_e( 'Total customers', 'brikpanel' ); ?></div>
					<div class="bp-ca-stat-value" id="bp-ca-stat-customers">—</div>
					<div class="bp-ca-stat-sub" id="bp-ca-stat-repeat">—</div>
				</div>
				<div class="bp-ca-stat">
					<div class="bp-ca-stat-label"><?php esc_html_e( 'Average LTV', 'brikpanel' ); ?></div>
					<div class="bp-ca-stat-value" id="bp-ca-stat-avg-ltv">—</div>
					<div class="bp-ca-stat-sub">
						<span><?php esc_html_e( 'Median:', 'brikpanel' ); ?></span>
						<span id="bp-ca-stat-median-ltv">—</span>
					</div>
				</div>
				<div class="bp-ca-stat">
					<div class="bp-ca-stat-label"><?php esc_html_e( 'Total LTV', 'brikpanel' ); ?></div>
					<div class="bp-ca-stat-value" id="bp-ca-stat-total-ltv">—</div>
					<div class="bp-ca-stat-sub">
						<span><?php esc_html_e( 'AOV:', 'brikpanel' ); ?></span>
						<span id="bp-ca-stat-avg-aov">—</span>
					</div>
				</div>
				<div class="bp-ca-stat">
					<div class="bp-ca-stat-label"><?php esc_html_e( 'Top customer', 'brikpanel' ); ?></div>
					<div class="bp-ca-stat-value" id="bp-ca-stat-max-ltv">—</div>
					<div class="bp-ca-stat-sub"><?php esc_html_e( 'highest lifetime spend', 'brikpanel' ); ?></div>
				</div>
			</div>

			<div class="bp-ca-card">
				<div class="bp-ca-card-header">
					<h2><?php esc_html_e( 'LTV distribution', 'brikpanel' ); ?></h2>
					<p class="bp-ca-card-sub"><?php esc_html_e( 'How many customers fall into each lifetime-spend bracket.', 'brikpanel' ); ?></p>
				</div>
				<div class="bp-ca-chart-wrap">
					<canvas id="bp-ca-ltv-histogram" height="80"></canvas>
				</div>
			</div>

			<div class="bp-ca-card">
				<div class="bp-ca-card-header">
					<h2><?php esc_html_e( 'Top customers by LTV', 'brikpanel' ); ?></h2>
					<p class="bp-ca-card-sub"><?php esc_html_e( 'Sorted by lifetime spend, descending. Click a row to open the customer profile.', 'brikpanel' ); ?></p>
				</div>
				<div class="bp-ca-table-wrap">
					<table class="bp-ca-table" id="bp-ca-top-customers">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Customer', 'brikpanel' ); ?></th>
								<th class="num"><?php esc_html_e( 'Orders', 'brikpanel' ); ?></th>
								<th class="num"><?php esc_html_e( 'AOV', 'brikpanel' ); ?></th>
								<th class="num"><?php esc_html_e( 'LTV', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Last order', 'brikpanel' ); ?></th>
								<th class="num"><?php esc_html_e( 'Recency', 'brikpanel' ); ?></th>
							</tr>
						</thead>
						<tbody id="bp-ca-top-customers-body">
							<tr><td class="bp-ca-empty" colspan="6"><?php esc_html_e( 'Loading…', 'brikpanel' ); ?></td></tr>
						</tbody>
					</table>
				</div>
				<div class="bp-ca-pagination" id="bp-ca-pagination" hidden>
					<button type="button" class="bp-ca-btn bp-ca-btn-secondary" id="bp-ca-prev">&larr; <?php esc_html_e( 'Previous', 'brikpanel' ); ?></button>
					<span class="bp-ca-page-info" id="bp-ca-page-info">1 / 1</span>
					<button type="button" class="bp-ca-btn bp-ca-btn-secondary" id="bp-ca-next"><?php esc_html_e( 'Next', 'brikpanel' ); ?> &rarr;</button>
				</div>
			</div>
		</div>

		<!-- ========================================================== -->
		<!-- RFM TAB                                                      -->
		<!-- ========================================================== -->
		<div class="bp-ca-tabpanel" data-panel="rfm" hidden>

			<div class="bp-ca-card">
				<div class="bp-ca-card-header">
					<h2><?php esc_html_e( 'Customer segments', 'brikpanel' ); ?></h2>
					<p class="bp-ca-card-sub"><?php esc_html_e( 'Each customer is scored 1–5 on Recency, Frequency, and Monetary value, then placed into one of the RFM segments below. Click a card to drill into its customers.', 'brikpanel' ); ?></p>
				</div>
				<div class="bp-ca-rfm-layout">
					<div class="bp-ca-rfm-grid" id="bp-ca-rfm-grid">
						<div class="bp-ca-empty"><?php esc_html_e( 'Loading…', 'brikpanel' ); ?></div>
					</div>
					<div class="bp-ca-rfm-chart">
						<canvas id="bp-ca-rfm-donut" height="220"></canvas>
					</div>
				</div>
			</div>

			<div class="bp-ca-card" id="bp-ca-rfm-customers-card" hidden>
				<div class="bp-ca-card-header">
					<h2 id="bp-ca-rfm-customers-title"><?php esc_html_e( 'Segment customers', 'brikpanel' ); ?></h2>
					<p class="bp-ca-card-sub" id="bp-ca-rfm-customers-sub"></p>
				</div>
				<div class="bp-ca-rfm-actions">
					<button type="button" class="bp-ca-btn bp-ca-btn-secondary" id="bp-ca-rfm-export-segment">
						<?php esc_html_e( 'Export this segment (CSV)', 'brikpanel' ); ?>
					</button>
					<button type="button" class="bp-ca-btn bp-ca-btn-secondary" id="bp-ca-rfm-clear">
						<?php esc_html_e( 'Clear selection', 'brikpanel' ); ?>
					</button>
				</div>
				<div class="bp-ca-table-wrap">
					<table class="bp-ca-table" id="bp-ca-rfm-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Customer', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'RFM', 'brikpanel' ); ?></th>
								<th class="num"><?php esc_html_e( 'Orders', 'brikpanel' ); ?></th>
								<th class="num"><?php esc_html_e( 'AOV', 'brikpanel' ); ?></th>
								<th class="num"><?php esc_html_e( 'LTV', 'brikpanel' ); ?></th>
								<th><?php esc_html_e( 'Last order', 'brikpanel' ); ?></th>
								<th class="num"><?php esc_html_e( 'Recency', 'brikpanel' ); ?></th>
							</tr>
						</thead>
						<tbody id="bp-ca-rfm-tbody">
							<tr><td class="bp-ca-empty" colspan="7"><?php esc_html_e( 'Click a segment above to load its customers.', 'brikpanel' ); ?></td></tr>
						</tbody>
					</table>
				</div>
				<div class="bp-ca-pagination" id="bp-ca-rfm-pagination" hidden>
					<button type="button" class="bp-ca-btn bp-ca-btn-secondary" id="bp-ca-rfm-prev">&larr; <?php esc_html_e( 'Previous', 'brikpanel' ); ?></button>
					<span class="bp-ca-page-info" id="bp-ca-rfm-page-info">1 / 1</span>
					<button type="button" class="bp-ca-btn bp-ca-btn-secondary" id="bp-ca-rfm-next"><?php esc_html_e( 'Next', 'brikpanel' ); ?> &rarr;</button>
				</div>
			</div>
		</div>

		<!-- ========================================================== -->
		<!-- COHORT TAB                                                   -->
		<!-- ========================================================== -->
		<div class="bp-ca-tabpanel" data-panel="cohort" hidden>

			<div class="bp-ca-card">
				<div class="bp-ca-card-header bp-ca-cohort-header">
					<div>
						<h2><?php esc_html_e( 'Cohort retention', 'brikpanel' ); ?></h2>
						<p class="bp-ca-card-sub">
							<?php esc_html_e( 'Each row is a monthly cohort of customers acquired that month. Each cell shows the % of that cohort who placed at least one order N months later.', 'brikpanel' ); ?>
						</p>
					</div>
					<div class="bp-ca-cohort-controls">
						<label for="bp-ca-cohort-window"><?php esc_html_e( 'Window:', 'brikpanel' ); ?></label>
						<select id="bp-ca-cohort-window">
							<option value="6"><?php esc_html_e( 'Last 6 months', 'brikpanel' ); ?></option>
							<option value="12" selected><?php esc_html_e( 'Last 12 months', 'brikpanel' ); ?></option>
							<option value="24"><?php esc_html_e( 'Last 24 months', 'brikpanel' ); ?></option>
						</select>
					</div>
				</div>
				<div class="bp-ca-cohort-heatmap-wrap">
					<div id="bp-ca-cohort-heatmap" class="bp-ca-cohort-heatmap">
						<div class="bp-ca-empty"><?php esc_html_e( 'Loading…', 'brikpanel' ); ?></div>
					</div>
				</div>
			</div>

			<div class="bp-ca-card">
				<div class="bp-ca-card-header">
					<h2><?php esc_html_e( 'Average retention by month offset', 'brikpanel' ); ?></h2>
					<p class="bp-ca-card-sub"><?php esc_html_e( 'Across all cohorts in the selected window — useful for spotting where the typical drop-off happens.', 'brikpanel' ); ?></p>
				</div>
				<div class="bp-ca-chart-wrap">
					<canvas id="bp-ca-cohort-line" height="80"></canvas>
				</div>
				<div class="bp-ca-rfm-actions" style="margin-top:1rem;">
					<button type="button" class="bp-ca-btn bp-ca-btn-secondary" id="bp-ca-cohort-export">
						<?php esc_html_e( 'Export matrix (CSV)', 'brikpanel' ); ?>
					</button>
				</div>
			</div>
		</div>

	</div>

	<!-- ============================================================== -->
	<!-- EXCLUDE-CUSTOMERS MODAL                                          -->
	<!-- ============================================================== -->
	<div class="bp-ca-modal-overlay" id="bp-ca-excl-overlay" hidden>
		<div class="bp-ca-modal" role="dialog" aria-modal="true" aria-labelledby="bp-ca-excl-heading">
			<div class="bp-ca-modal-head">
				<h2 id="bp-ca-excl-heading"><?php esc_html_e( 'Exclude customers from analytics', 'brikpanel' ); ?></h2>
				<button type="button" class="bp-ca-modal-close" id="bp-ca-excl-close" aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">&times;</button>
			</div>
			<div class="bp-ca-modal-body">
				<p class="bp-ca-modal-intro"><?php esc_html_e( 'Pick the accounts you do not want counted as customers, for example a point-of-sale or staff login that places many orders. Their orders still count toward your shop revenue and order totals, but they are left out of customer averages, segments, and retention so the numbers reflect your real customers.', 'brikpanel' ); ?></p>

				<div class="bp-ca-excl-section">
					<h3><?php esc_html_e( 'People', 'brikpanel' ); ?></h3>
					<div class="bp-ca-excl-search-wrap">
						<input type="text" id="bp-ca-excl-search" class="bp-ca-excl-search" autocomplete="off" placeholder="<?php esc_attr_e( 'Search by name or email…', 'brikpanel' ); ?>">
						<div class="bp-ca-excl-results" id="bp-ca-excl-results" hidden></div>
					</div>
					<div class="bp-ca-excl-chips" id="bp-ca-excl-chips"></div>
				</div>

				<div class="bp-ca-excl-section">
					<h3><?php esc_html_e( 'Roles', 'brikpanel' ); ?></h3>
					<p class="bp-ca-excl-hint"><?php esc_html_e( 'Everyone with a checked role is excluded too.', 'brikpanel' ); ?></p>
					<div class="bp-ca-excl-roles" id="bp-ca-excl-roles"></div>
				</div>
			</div>
			<div class="bp-ca-modal-foot">
				<button type="button" class="bp-ca-btn bp-ca-btn-secondary" id="bp-ca-excl-cancel"><?php esc_html_e( 'Cancel', 'brikpanel' ); ?></button>
				<button type="button" class="bp-ca-btn bp-ca-btn-primary" id="bp-ca-excl-save"><?php esc_html_e( 'Save changes', 'brikpanel' ); ?></button>
			</div>
		</div>
	</div>
</div>
