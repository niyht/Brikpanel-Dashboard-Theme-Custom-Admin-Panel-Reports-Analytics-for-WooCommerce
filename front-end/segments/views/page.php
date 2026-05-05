<?php
/**
 * BrikPanel Segments - Page Template
 *
 * Rendered by Brikpanel_Segments::render_page(). $currency_symbol is
 * exposed by the renderer.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<div class="brikpanel-segments" id="brikpanel-segments">

		<div class="bp-seg-header">
			<div class="bp-seg-header-left">
				<h1><?php esc_html_e( 'Segments', 'brikpanel' ); ?></h1>
				<span class="bp-seg-count" id="bp-seg-count">0</span>
			</div>
			<div class="bp-seg-header-right">
				<button type="button" class="bp-seg-btn bp-seg-btn-secondary" id="bp-seg-reset">
					<?php esc_html_e( 'Reset filters', 'brikpanel' ); ?>
				</button>
				<button type="button" class="bp-seg-btn bp-seg-btn-primary" id="bp-seg-export">
					<?php esc_html_e( 'Export CSV', 'brikpanel' ); ?>
				</button>
			</div>
		</div>

		<div class="bp-seg-tabs" role="tablist">
			<button type="button" class="bp-seg-tab is-active" data-tab="orders" role="tab" aria-selected="true">
				<?php esc_html_e( 'Orders', 'brikpanel' ); ?>
			</button>
			<button type="button" class="bp-seg-tab" data-tab="customers" role="tab" aria-selected="false">
				<?php esc_html_e( 'Customers', 'brikpanel' ); ?>
			</button>
		</div>

		<div class="bp-seg-card bp-seg-filter-bar">
			<div class="bp-seg-chips" id="bp-seg-chips">
				<?php // Preset chips swap between Orders / Customers context in JS. ?>
			</div>
			<div class="bp-seg-quick-search">
				<input type="search" id="bp-seg-search" placeholder="<?php esc_attr_e( 'Search by name, email, order ID…', 'brikpanel' ); ?>" />
				<button type="button" class="bp-seg-btn bp-seg-btn-secondary" id="bp-seg-toggle-more" aria-expanded="false">
					<?php esc_html_e( 'More filters', 'brikpanel' ); ?>
					<span class="bp-seg-count-badge" id="bp-seg-active-filter-count" hidden>0</span>
				</button>
			</div>
		</div>

		<div class="bp-seg-card bp-seg-more" id="bp-seg-more" hidden>
			<div class="bp-seg-grid">

				<div class="bp-seg-field">
					<label for="bp-seg-date-from"><?php esc_html_e( 'Date from', 'brikpanel' ); ?></label>
					<input type="date" id="bp-seg-date-from" />
				</div>
				<div class="bp-seg-field">
					<label for="bp-seg-date-to"><?php esc_html_e( 'Date to', 'brikpanel' ); ?></label>
					<input type="date" id="bp-seg-date-to" />
				</div>

				<div class="bp-seg-field bp-seg-orders-only">
					<label><?php esc_html_e( 'Order status', 'brikpanel' ); ?></label>
					<div class="bp-seg-multi" id="bp-seg-statuses"></div>
				</div>

				<div class="bp-seg-field">
					<label for="bp-seg-country"><?php esc_html_e( 'Country', 'brikpanel' ); ?></label>
					<select id="bp-seg-country" multiple size="1"></select>
				</div>

				<div class="bp-seg-field">
					<label for="bp-seg-city"><?php esc_html_e( 'City', 'brikpanel' ); ?></label>
					<input type="text" id="bp-seg-city" placeholder="<?php esc_attr_e( 'Any city', 'brikpanel' ); ?>" />
				</div>

				<div class="bp-seg-field bp-seg-orders-only">
					<label><?php esc_html_e( 'Order total', 'brikpanel' ); ?></label>
					<div class="bp-seg-range">
						<div class="bp-seg-input-group">
							<span class="bp-seg-prefix"><?php echo esc_html( $currency_symbol ); ?></span>
							<input type="number" min="0" step="0.01" id="bp-seg-total-min" placeholder="<?php esc_attr_e( 'Min', 'brikpanel' ); ?>" />
						</div>
						<div class="bp-seg-input-group">
							<span class="bp-seg-prefix"><?php echo esc_html( $currency_symbol ); ?></span>
							<input type="number" min="0" step="0.01" id="bp-seg-total-max" placeholder="<?php esc_attr_e( 'Max', 'brikpanel' ); ?>" />
						</div>
					</div>
				</div>

				<div class="bp-seg-field bp-seg-customers-only">
					<label><?php esc_html_e( 'Total spent', 'brikpanel' ); ?></label>
					<div class="bp-seg-range">
						<div class="bp-seg-input-group">
							<span class="bp-seg-prefix"><?php echo esc_html( $currency_symbol ); ?></span>
							<input type="number" min="0" step="0.01" id="bp-seg-spent-min" placeholder="<?php esc_attr_e( 'Min', 'brikpanel' ); ?>" />
						</div>
						<div class="bp-seg-input-group">
							<span class="bp-seg-prefix"><?php echo esc_html( $currency_symbol ); ?></span>
							<input type="number" min="0" step="0.01" id="bp-seg-spent-max" placeholder="<?php esc_attr_e( 'Max', 'brikpanel' ); ?>" />
						</div>
					</div>
				</div>

				<div class="bp-seg-field bp-seg-customers-only">
					<label><?php esc_html_e( 'Order count', 'brikpanel' ); ?></label>
					<div class="bp-seg-range">
						<input type="number" min="0" step="1" id="bp-seg-count-min" placeholder="<?php esc_attr_e( 'Min', 'brikpanel' ); ?>" />
						<input type="number" min="0" step="1" id="bp-seg-count-max" placeholder="<?php esc_attr_e( 'Max', 'brikpanel' ); ?>" />
					</div>
				</div>

				<div class="bp-seg-field bp-seg-customers-only">
					<label for="bp-seg-last-order-from"><?php esc_html_e( 'Last order from', 'brikpanel' ); ?></label>
					<input type="date" id="bp-seg-last-order-from" />
				</div>
				<div class="bp-seg-field bp-seg-customers-only">
					<label for="bp-seg-last-order-to"><?php esc_html_e( 'Last order to', 'brikpanel' ); ?></label>
					<input type="date" id="bp-seg-last-order-to" />
				</div>

				<div class="bp-seg-field bp-seg-customers-only">
					<label for="bp-seg-rfm"><?php esc_html_e( 'RFM segment', 'brikpanel' ); ?></label>
					<select id="bp-seg-rfm" multiple size="1"></select>
				</div>

				<div class="bp-seg-field bp-seg-customers-only">
					<label for="bp-seg-registered-from"><?php esc_html_e( 'Registered from', 'brikpanel' ); ?></label>
					<input type="date" id="bp-seg-registered-from" />
				</div>
				<div class="bp-seg-field bp-seg-customers-only">
					<label for="bp-seg-registered-to"><?php esc_html_e( 'Registered to', 'brikpanel' ); ?></label>
					<input type="date" id="bp-seg-registered-to" />
				</div>

				<div class="bp-seg-field bp-seg-orders-only">
					<label for="bp-seg-payment"><?php esc_html_e( 'Payment method', 'brikpanel' ); ?></label>
					<select id="bp-seg-payment" multiple size="1"></select>
				</div>

				<div class="bp-seg-field bp-seg-orders-only">
					<label for="bp-seg-coupon"><?php esc_html_e( 'Coupon code', 'brikpanel' ); ?></label>
					<input type="text" id="bp-seg-coupon" placeholder="<?php esc_attr_e( 'Exact coupon', 'brikpanel' ); ?>" />
				</div>

				<div class="bp-seg-field bp-seg-full">
					<label for="bp-seg-products"><?php esc_html_e( 'Products', 'brikpanel' ); ?></label>
					<div class="bp-seg-product-picker">
						<input type="text" id="bp-seg-product-search" autocomplete="off" placeholder="<?php esc_attr_e( 'Type to search products…', 'brikpanel' ); ?>" />
						<div class="bp-seg-product-suggestions" id="bp-seg-product-suggestions" hidden></div>
						<div class="bp-seg-selected-products" id="bp-seg-selected-products"></div>
					</div>
				</div>

				<div class="bp-seg-field bp-seg-full">
					<label for="bp-seg-categories"><?php esc_html_e( 'Categories', 'brikpanel' ); ?></label>
					<select id="bp-seg-categories" multiple size="1"></select>
				</div>
			</div>
		</div>

		<div class="bp-seg-stats" id="bp-seg-stats">
			<div class="bp-seg-stat">
				<div class="bp-seg-stat-label"><?php esc_html_e( 'Results', 'brikpanel' ); ?></div>
				<div class="bp-seg-stat-value" id="bp-seg-stat-count">—</div>
			</div>
			<div class="bp-seg-stat">
				<div class="bp-seg-stat-label" id="bp-seg-stat-revenue-label"><?php esc_html_e( 'Total revenue', 'brikpanel' ); ?></div>
				<div class="bp-seg-stat-value" id="bp-seg-stat-revenue">—</div>
			</div>
			<div class="bp-seg-stat">
				<div class="bp-seg-stat-label"><?php esc_html_e( 'Average order value', 'brikpanel' ); ?></div>
				<div class="bp-seg-stat-value" id="bp-seg-stat-aov">—</div>
			</div>
		</div>

		<div class="bp-seg-card bp-seg-results">
			<div class="bp-seg-table-wrap">
				<table class="bp-seg-table" id="bp-seg-table">
					<thead id="bp-seg-thead"></thead>
					<tbody id="bp-seg-tbody">
						<tr><td class="bp-seg-empty" colspan="9"><?php esc_html_e( 'Loading…', 'brikpanel' ); ?></td></tr>
					</tbody>
				</table>
			</div>

			<div class="bp-seg-pagination" id="bp-seg-pagination" hidden>
				<button type="button" class="bp-seg-btn bp-seg-btn-secondary" id="bp-seg-prev">&larr; <?php esc_html_e( 'Previous', 'brikpanel' ); ?></button>
				<span class="bp-seg-page-info" id="bp-seg-page-info">1 / 1</span>
				<button type="button" class="bp-seg-btn bp-seg-btn-secondary" id="bp-seg-next"><?php esc_html_e( 'Next', 'brikpanel' ); ?> &rarr;</button>
			</div>
		</div>
	</div>
</div>
