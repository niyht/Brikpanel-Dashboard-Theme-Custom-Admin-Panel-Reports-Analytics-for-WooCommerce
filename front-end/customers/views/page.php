<?php
/**
 * BrikPanel Customers page: header, tab strip and the four tabs.
 *
 * Rendered by Brikpanel_Customers::render_page(). $bp_cus_tab is the tab the
 * request asked for: the other panels start hidden, so the right one paints
 * before any script runs. $currency_symbol is the store's currency symbol.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bp_cus_tab       = isset( $bp_cus_tab ) ? (string) $bp_cus_tab : 'all';
$bp_cus_analytics = class_exists( 'Brikpanel_Customer_Analytics' );
$bp_cus_tabs      = [
	'all'    => __( 'All customers', 'brikpanel' ),
	'rfm'    => __( 'RFM segments', 'brikpanel' ),
	'ltv'    => __( 'Lifetime value', 'brikpanel' ),
	'cohort' => __( 'Cohort retention', 'brikpanel' ),
];
if ( ! $bp_cus_analytics ) {
	$bp_cus_tabs = array_slice( $bp_cus_tabs, 0, 1, true );
}

// The header gives way in a fixed, measured order (front-end/shared/
// brikpanel-fit-row.js, CLAUDE.md "Başlık satırı kuralı"): one line while it
// fits; then Exclude and Recompute fold into "More actions"; then the buttons
// take their own row under the title. Nothing in it changes with the tab.
$bp_cus_header_fit = [
	'title'  => 'h1',
	'lines'  => [ '' ],
	'levels' => [
		'',
		'is-fold',
		[ 'cls' => 'is-fold is-two-rows', 'lines' => [ '.bp-cus-header-right' ] ],
	],
];
?>
<div class="wrap brikpanel-shell__page">
	<div class="bp-cus" id="bp-cus" data-tab="<?php echo esc_attr( $bp_cus_tab ); ?>">

		<div class="bp-cus-header" id="bp-cus-header" data-bp-fit-row="<?php echo esc_attr( wp_json_encode( $bp_cus_header_fit ) ); ?>">
			<?php wp_print_inline_script_tag( 'if(window.brikpanelFitRow){window.brikpanelFitRow.auto(document.getElementById("bp-cus-header"));}' ); ?>
			<div class="bp-cus-header-left">
				<h1><?php esc_html_e( 'Customers', 'brikpanel' ); ?></h1>
			</div>
			<div class="bp-cus-header-right">
				<?php if ( $bp_cus_analytics ) : ?>
					<div class="brikpanel-overflow">
						<?php
						if ( function_exists( 'brikpanel_overflow_trigger' ) ) {
							brikpanel_overflow_trigger( 'bp-cus-more-menu' );
						}
						?>
						<div class="brikpanel-overflow__menu" id="bp-cus-more-menu">
							<button type="button" class="brikpanel-btn brikpanel-btn--secondary" id="bp-ca-exclude">
								<?php esc_html_e( 'Exclude customers', 'brikpanel' ); ?>
								<span class="bp-ca-excl-badge" id="bp-ca-excl-badge" hidden></span>
							</button>
							<button type="button" class="brikpanel-btn brikpanel-btn--secondary" id="bp-ca-refresh" data-bp-fit-labels="<?php echo esc_attr( wp_json_encode( [ __( 'Recompute now', 'brikpanel' ), __( 'Refreshing…', 'brikpanel' ) ] ) ); ?>">
								<?php esc_html_e( 'Recompute now', 'brikpanel' ); ?>
							</button>
						</div>
					</div>
				<?php endif; ?>
				<button type="button" class="brikpanel-btn brikpanel-btn--primary" id="bp-cus-export">
					<?php esc_html_e( 'Export CSV', 'brikpanel' ); ?>
				</button>
			</div>
		</div>
		<?php brikpanel_header_end(); ?>

		<?php
		/**
		 * Fires between the Customers header and the tab strip. BrikMentor's
		 * pitch card (ask bm_pitch_ca) hooks here; kept under its old name.
		 *
		 * @since 3.2.13
		 */
		do_action( 'brikpanel_ca_after_header' );
		?>

		<nav class="bp-cus-tabs" role="tablist" data-bp-strip aria-label="<?php esc_attr_e( 'Customers', 'brikpanel' ); ?>">
			<?php foreach ( $bp_cus_tabs as $bp_cus_key => $bp_cus_label ) : ?>
				<a class="bp-cus-tab<?php echo $bp_cus_key === $bp_cus_tab ? ' is-active' : ''; ?>" role="tab"
					id="bp-cus-tab-<?php echo esc_attr( $bp_cus_key ); ?>"
					data-tab="<?php echo esc_attr( $bp_cus_key ); ?>"
					aria-selected="<?php echo $bp_cus_key === $bp_cus_tab ? 'true' : 'false'; ?>"
					href="<?php echo esc_url( Brikpanel_Customers::url( $bp_cus_key ) ); ?>"><?php echo esc_html( $bp_cus_label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php if ( $bp_cus_analytics ) : ?>
			<p class="bp-cus-meta" id="bp-ca-meta"><?php echo esc_html( Brikpanel_Customer_Analytics::refreshed_line() ); ?></p>
		<?php endif; ?>

		<div class="bp-cus-panel" id="bp-cus-panel-all" role="tabpanel" aria-labelledby="bp-cus-tab-all" data-panel="all"<?php echo 'all' === $bp_cus_tab ? '' : ' hidden'; ?>>
			<div class="brikpanel-segments" id="brikpanel-segments" data-tab="customers">
				<?php
				$bp_seg_tab          = 'customers';
				$bp_seg_reset_in_bar = true;
				include BRIKPANEL_PATH . 'front-end/segments/views/body.php';
				?>
			</div>
		</div>

		<?php
		if ( $bp_cus_analytics ) {
			Brikpanel_Customer_Analytics::render_panels( $bp_cus_tab );
		}
		?>
	</div>
</div>
