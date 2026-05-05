<?php
/**
 * BrikPanel — BrikControl Page View
 *
 * Variables in scope (from Brikpanel_BrikControl::render_page):
 *   - $bundle    : array  full result bundle from storage
 *   - $progress  : array  current scan progress
 *   - $is_active : bool   whether a scan is in flight
 *   - $registry  : Brikpanel_BrikControl_Check[]
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$summary = $bundle['status_summary'];
$last    = (int) $bundle['last_scan'];

$last_label = $last > 0
    ? sprintf(
        /* translators: %s: human-readable date/time */
        esc_html__( 'Last scan: %s', 'brikpanel' ),
        esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) )
    )
    : esc_html__( 'No scan completed yet.', 'brikpanel' );

// Sort checks by status weight then priority so the most critical issues
// surface at the top of the page.
$status_weight = [ 'critical' => 0, 'warning' => 1, 'unknown' => 2, 'ok' => 3 ];
$ordered       = $bundle['checks'];
uasort( $ordered, static function ( $a, $b ) use ( $status_weight ) {
    $sa = $status_weight[ $a['status'] ?? 'unknown' ] ?? 4;
    $sb = $status_weight[ $b['status'] ?? 'unknown' ] ?? 4;
    return $sa <=> $sb;
} );
?>
<div class="brikpanel-bc-wrap">
    <div class="brikpanel-bc-header">
        <div class="brikpanel-bc-header-left">
            <h1 class="brikpanel-bc-title"><?php esc_html_e( 'Store Health', 'brikpanel' ); ?></h1>
            <p class="brikpanel-bc-subtitle"><?php echo $last_label; // already escaped above ?></p>
        </div>
        <div class="brikpanel-bc-header-right">
            <button type="button" class="brikpanel-bc-button brikpanel-bc-button-primary" data-bc-rescan-page<?php echo $is_active ? ' disabled' : ''; ?>>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                <span data-bc-rescan-label><?php esc_html_e( 'Scan now', 'brikpanel' ); ?></span>
            </button>
        </div>
    </div>

    <div class="brikpanel-bc-summary-card" data-bc-summary>
        <div class="brikpanel-bc-summary-chip brikpanel-bc-chip-critical">
            <span class="brikpanel-bc-chip-num" data-bc-count="critical"><?php echo esc_html( number_format_i18n( $summary['critical'] ) ); ?></span>
            <span class="brikpanel-bc-chip-label"><?php esc_html_e( 'Critical', 'brikpanel' ); ?></span>
        </div>
        <div class="brikpanel-bc-summary-chip brikpanel-bc-chip-warning">
            <span class="brikpanel-bc-chip-num" data-bc-count="warning"><?php echo esc_html( number_format_i18n( $summary['warning'] ) ); ?></span>
            <span class="brikpanel-bc-chip-label"><?php esc_html_e( 'Warnings', 'brikpanel' ); ?></span>
        </div>
        <div class="brikpanel-bc-summary-chip brikpanel-bc-chip-ok">
            <span class="brikpanel-bc-chip-num" data-bc-count="ok"><?php echo esc_html( number_format_i18n( $summary['ok'] ) ); ?></span>
            <span class="brikpanel-bc-chip-label"><?php esc_html_e( 'OK', 'brikpanel' ); ?></span>
        </div>
        <?php if ( $summary['unknown'] > 0 ) : ?>
            <div class="brikpanel-bc-summary-chip brikpanel-bc-chip-unknown">
                <span class="brikpanel-bc-chip-num" data-bc-count="unknown"><?php echo esc_html( number_format_i18n( $summary['unknown'] ) ); ?></span>
                <span class="brikpanel-bc-chip-label"><?php esc_html_e( 'Pending', 'brikpanel' ); ?></span>
            </div>
        <?php endif; ?>
    </div>

    <div class="brikpanel-bc-progress" data-bc-progress<?php echo $is_active ? '' : ' hidden'; ?>>
        <div class="brikpanel-bc-progress-text">
            <span data-bc-progress-label><?php esc_html_e( 'Scanning your store…', 'brikpanel' ); ?></span>
            <span data-bc-progress-pct>
                <?php
                if ( $progress['total'] > 0 ) {
                    echo esc_html( number_format_i18n( min( 100, ( $progress['cursor'] / max( 1, $progress['total'] ) ) * 100 ), 0 ) ) . '%';
                }
                ?>
            </span>
        </div>
        <div class="brikpanel-bc-progress-track">
            <div class="brikpanel-bc-progress-bar" data-bc-progress-bar style="width: <?php echo esc_attr( $progress['total'] > 0 ? min( 100, ( $progress['cursor'] / max( 1, $progress['total'] ) ) * 100 ) : 0 ); ?>%;"></div>
        </div>
    </div>

    <div class="brikpanel-bc-checks" data-bc-checks>
        <?php
        if ( empty( $ordered ) ) :
            ?>
            <div class="brikpanel-bc-card brikpanel-bc-empty-state">
                <p><?php esc_html_e( 'No checks registered yet. Once the first scan finishes, you will see results here.', 'brikpanel' ); ?></p>
            </div>
            <?php
        else :
            foreach ( $ordered as $check_id => $check_result ) {
                include BRIKPANEL_PATH . 'front-end/brikcontrol/views/partials/check-card.php';
            }
        endif;
        ?>
    </div>
</div>
