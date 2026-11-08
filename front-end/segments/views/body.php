<?php
/**
 * BrikPanel: the filter bar, "More filters", summary tiles and results table
 * shared by the Orders explorer (front-end/segments/views/page.php) and the
 * Customers page's All customers tab (front-end/customers/views/page.php).
 *
 * The caller opens the root element (#brikpanel-segments with data-tab)
 * around this file and sets:
 *   $bp_seg_tab          'orders' or 'customers' (the placeholder text);
 *   $bp_seg_reset_in_bar true to put "Reset filters" in the filter bar
 *                        (the Orders explorer keeps it in its header);
 *   $currency_symbol     store currency symbol for the money fields.
 * Both tabs' fields stay in the markup: CSS hides the other tab's, and the
 * script reads every field by id.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$bp_seg_tab          = isset( $bp_seg_tab ) && 'customers' === $bp_seg_tab ? 'customers' : 'orders';
$bp_seg_reset_in_bar = ! empty( $bp_seg_reset_in_bar );
$currency_symbol     = isset( $currency_symbol ) ? (string) $currency_symbol : '';
?>
<div class="bp-seg-card bp-seg-filter-bar">
	<div class="bp-seg-chips" id="bp-seg-chips">
		<?php // Preset chips, saved segments and "Save as segment": drawn per tab in JS. ?>
	</div>
	<div class="bp-seg-quick-search">
		<input class="brikpanel-control" type="search" id="bp-seg-search" placeholder="<?php echo esc_attr( 'customers' === $bp_seg_tab ? __( 'Search customers', 'brikpanel' ) : __( 'Search by name, email, order number…', 'brikpanel' ) ); ?>" />
		<button type="button" class="bp-seg-btn bp-seg-btn-secondary" id="bp-seg-toggle-more" aria-expanded="false">
			<?php esc_html_e( 'More filters', 'brikpanel' ); ?>
			<span class="bp-seg-count-badge" id="bp-seg-active-filter-count" hidden>0</span>
		</button>
		<?php if ( $bp_seg_reset_in_bar ) : ?>
			<button type="button" class="brikpanel-btn brikpanel-btn--link bp-seg-reset-link" id="bp-seg-reset"><?php esc_html_e( 'Reset filters', 'brikpanel' ); ?></button>
		<?php endif; ?>
	</div>
	<p class="bp-seg-save-status" id="bp-seg-save-status" role="status" aria-live="polite" hidden></p>
</div>

<div class="bp-seg-card bp-seg-more" id="bp-seg-more" hidden>
	<div class="bp-seg-grid">

		<div class="bp-seg-field brikpanel-field">
			<label for="bp-seg-date-from"><?php esc_html_e( 'Date from', 'brikpanel' ); ?></label>
			<input class="brikpanel-control" type="date" id="bp-seg-date-from" />
		</div>
		<div class="bp-seg-field brikpanel-field">
			<label for="bp-seg-date-to"><?php esc_html_e( 'Date to', 'brikpanel' ); ?></label>
			<input class="brikpanel-control" type="date" id="bp-seg-date-to" />
		</div>

		<div class="bp-seg-field brikpanel-field bp-seg-orders-only">
			<label><?php esc_html_e( 'Order status', 'brikpanel' ); ?></label>
			<div class="bp-seg-multi" id="bp-seg-statuses"></div>
		</div>

		<div class="bp-seg-field brikpanel-field">
			<label for="bp-seg-country"><?php esc_html_e( 'Country', 'brikpanel' ); ?></label>
			<select class="brikpanel-control" id="bp-seg-country" multiple size="1"></select>
		</div>

		<div class="bp-seg-field brikpanel-field">
			<label for="bp-seg-city"><?php esc_html_e( 'City', 'brikpanel' ); ?></label>
			<input class="brikpanel-control" type="text" id="bp-seg-city" placeholder="<?php esc_attr_e( 'Any city', 'brikpanel' ); ?>" />
		</div>

		<div class="bp-seg-field brikpanel-field bp-seg-orders-only">
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

		<div class="bp-seg-field brikpanel-field bp-seg-customers-only">
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

		<div class="bp-seg-field brikpanel-field bp-seg-customers-only">
			<label><?php esc_html_e( 'Order count', 'brikpanel' ); ?></label>
			<div class="bp-seg-range">
				<input class="brikpanel-control" type="number" min="0" step="1" id="bp-seg-count-min" placeholder="<?php esc_attr_e( 'Min', 'brikpanel' ); ?>" />
				<input class="brikpanel-control" type="number" min="0" step="1" id="bp-seg-count-max" placeholder="<?php esc_attr_e( 'Max', 'brikpanel' ); ?>" />
			</div>
		</div>

		<div class="bp-seg-field brikpanel-field bp-seg-customers-only">
			<label for="bp-seg-last-order-from"><?php esc_html_e( 'Last order from', 'brikpanel' ); ?></label>
			<input class="brikpanel-control" type="date" id="bp-seg-last-order-from" />
		</div>
		<div class="bp-seg-field brikpanel-field bp-seg-customers-only">
			<label for="bp-seg-last-order-to"><?php esc_html_e( 'Last order to', 'brikpanel' ); ?></label>
			<input class="brikpanel-control" type="date" id="bp-seg-last-order-to" />
		</div>

		<div class="bp-seg-field brikpanel-field bp-seg-customers-only">
			<label for="bp-seg-rfm"><?php esc_html_e( 'RFM segment', 'brikpanel' ); ?></label>
			<select class="brikpanel-control" id="bp-seg-rfm" multiple size="1"></select>
		</div>

		<div class="bp-seg-field brikpanel-field bp-seg-customers-only">
			<label for="bp-seg-registered-from"><?php esc_html_e( 'Registered from', 'brikpanel' ); ?></label>
			<input class="brikpanel-control" type="date" id="bp-seg-registered-from" />
		</div>
		<div class="bp-seg-field brikpanel-field bp-seg-customers-only">
			<label for="bp-seg-registered-to"><?php esc_html_e( 'Registered to', 'brikpanel' ); ?></label>
			<input class="brikpanel-control" type="date" id="bp-seg-registered-to" />
		</div>

		<div class="bp-seg-field brikpanel-field bp-seg-orders-only">
			<label for="bp-seg-payment"><?php esc_html_e( 'Payment method', 'brikpanel' ); ?></label>
			<select class="brikpanel-control" id="bp-seg-payment" multiple size="1"></select>
		</div>

		<div class="bp-seg-field brikpanel-field bp-seg-orders-only">
			<label for="bp-seg-coupon"><?php esc_html_e( 'Coupon code', 'brikpanel' ); ?></label>
			<input class="brikpanel-control" type="text" id="bp-seg-coupon" placeholder="<?php esc_attr_e( 'Exact coupon', 'brikpanel' ); ?>" />
		</div>

		<div class="bp-seg-field brikpanel-field bp-seg-full">
			<label for="bp-seg-product-search"><?php esc_html_e( 'Products', 'brikpanel' ); ?></label>
			<div class="bp-seg-product-picker">
				<input class="brikpanel-control" type="text" id="bp-seg-product-search" autocomplete="off" placeholder="<?php esc_attr_e( 'Type to search products…', 'brikpanel' ); ?>" />
				<div class="bp-seg-product-suggestions" id="bp-seg-product-suggestions" hidden></div>
				<div class="bp-seg-selected-products" id="bp-seg-selected-products"></div>
			</div>
		</div>

		<div class="bp-seg-field brikpanel-field bp-seg-full">
			<label for="bp-seg-exclude-search"><?php esc_html_e( 'Exclude products', 'brikpanel' ); ?></label>
			<div class="bp-seg-product-picker">
				<input class="brikpanel-control" type="text" id="bp-seg-exclude-search" autocomplete="off" placeholder="<?php esc_attr_e( 'Search products to exclude…', 'brikpanel' ); ?>" aria-describedby="bp-seg-exclude-hint-orders bp-seg-exclude-hint-customers" />
				<div class="bp-seg-product-suggestions" id="bp-seg-exclude-suggestions" hidden></div>
				<div class="bp-seg-selected-products" id="bp-seg-excluded-products"></div>
			</div>
			<p class="bp-seg-field-hint bp-seg-orders-only" id="bp-seg-exclude-hint-orders"><?php esc_html_e( 'Leaves out orders that contain any of these products.', 'brikpanel' ); ?></p>
			<p class="bp-seg-field-hint bp-seg-customers-only" id="bp-seg-exclude-hint-customers"><?php esc_html_e( 'Leaves out customers who ever bought any of these products.', 'brikpanel' ); ?></p>
		</div>

		<div class="bp-seg-field brikpanel-field bp-seg-full">
			<label for="bp-seg-categories"><?php esc_html_e( 'Categories', 'brikpanel' ); ?></label>
			<select class="brikpanel-control" id="bp-seg-categories" multiple size="1"></select>
		</div>
	</div>
</div>

<div class="bp-seg-stats" id="bp-seg-stats" data-bp-tiles>
	<div class="bp-seg-stat">
		<div class="bp-seg-stat-label"><?php echo esc_html( 'customers' === $bp_seg_tab ? __( 'Customers', 'brikpanel' ) : __( 'Results', 'brikpanel' ) ); ?></div>
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
				<tr><td class="bp-seg-empty" colspan="8"><?php esc_html_e( 'Loading…', 'brikpanel' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="bp-seg-pagination" id="bp-seg-pagination" hidden>
		<button type="button" class="bp-seg-btn bp-seg-btn-secondary" id="bp-seg-prev">&larr; <?php esc_html_e( 'Previous', 'brikpanel' ); ?></button>
		<span class="bp-seg-page-info" id="bp-seg-page-info">1 / 1</span>
		<button type="button" class="bp-seg-btn bp-seg-btn-secondary" id="bp-seg-next"><?php esc_html_e( 'Next', 'brikpanel' ); ?> &rarr;</button>
	</div>
</div>
