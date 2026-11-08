<?php
/**
 * BrikPanel Orders explorer: the order list with every Segments filter
 * (once the Orders tab of the Segments screen). Its customer half is the
 * Customers page now (front-end/customers/).
 *
 * Rendered by Brikpanel_Segments::render_page(). Opened from the Orders list;
 * a screen without a sidebar row of its own (Orders stays highlighted).
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The header gives way in a fixed, measured order (front-end/shared/
// brikpanel-fit-row.js, CLAUDE.md "Başlık satırı kuralı"): one line while it
// fits; then Reset filters folds into "More actions"; then the buttons take
// their own row under the title. No count beside the title: the Results tile
// shows it, and a number the script fills in later would move the title row.
$bp_seg_header_fit = [
	'title'  => 'h1',
	'lines'  => [ '' ],
	'levels' => [
		'',
		'is-fold',
		[ 'cls' => 'is-fold is-two-rows', 'lines' => [ '.bp-seg-header-right' ] ],
	],
];
?>
<div class="wrap brikpanel-shell__page">
	<div class="brikpanel-segments" id="brikpanel-segments" data-tab="orders">

		<div class="bp-seg-header" id="bp-seg-header" data-bp-fit-row="<?php echo esc_attr( wp_json_encode( $bp_seg_header_fit ) ); ?>">
			<?php wp_print_inline_script_tag( 'if(window.brikpanelFitRow){window.brikpanelFitRow.auto(document.getElementById("bp-seg-header"));}' ); ?>
			<div class="bp-seg-header-left">
				<h1><?php esc_html_e( 'Orders explorer', 'brikpanel' ); ?></h1>
			</div>
			<div class="bp-seg-header-right">
				<div class="brikpanel-overflow">
					<?php
					if ( function_exists( 'brikpanel_overflow_trigger' ) ) {
						brikpanel_overflow_trigger( 'bp-seg-more-menu' );
					}
					?>
					<div class="brikpanel-overflow__menu" id="bp-seg-more-menu">
						<button type="button" class="bp-seg-btn bp-seg-btn-secondary" id="bp-seg-reset">
							<?php esc_html_e( 'Reset filters', 'brikpanel' ); ?>
						</button>
					</div>
				</div>
				<button type="button" class="bp-seg-btn bp-seg-btn-primary" id="bp-seg-export">
					<?php esc_html_e( 'Export CSV', 'brikpanel' ); ?>
				</button>
			</div>
		</div>
		<?php brikpanel_header_end(); ?>

		<?php
		$bp_seg_tab          = 'orders';
		$bp_seg_reset_in_bar = false;
		include __DIR__ . '/body.php';
		?>
	</div>
</div>
