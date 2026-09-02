<?php
/**
 * BrikPanel — BrikControl Check Card Partial
 *
 * Variables in scope (from views/page.php loop):
 *   - $check_id     : string
 *   - $check_result : array  (CheckResult schema)
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$status   = $check_result['status'] ?? 'unknown';
$score    = (int) ( $check_result['score'] ?? 0 );
$label    = $check_result['label'] ?? $check_id;
$summary  = $check_result['summary'] ?? '';
$message  = $check_result['message'] ?? '';
$recs     = $check_result['recommendations'] ?? [];
$meta     = $check_result['metadata'] ?? [];
$scanned  = (int) ( $check_result['scanned_at'] ?? 0 );

$status_label = [
    'ok'       => __( 'OK', 'brikpanel' ),
    'warning'  => __( 'Warning', 'brikpanel' ),
    'critical' => __( 'Critical', 'brikpanel' ),
    'unknown'  => __( 'Pending', 'brikpanel' ),
][ $status ] ?? $status;

$largest = isset( $meta['largest'] ) && is_array( $meta['largest'] ) ? $meta['largest'] : [];
$plugins_active = isset( $meta['plugins']['active'] ) && is_array( $meta['plugins']['active'] ) ? $meta['plugins']['active'] : [];
?>
<article class="brikpanel-bc-card brikpanel-bc-card-<?php echo esc_attr( $status ); ?>" data-bc-check="<?php echo esc_attr( $check_id ); ?>">
    <header class="brikpanel-bc-card-head">
        <div class="brikpanel-bc-card-head-left">
            <span class="brikpanel-bc-status-badge brikpanel-bc-status-<?php echo esc_attr( $status ); ?>">
                <span class="brikpanel-bc-status-dot" aria-hidden="true"></span>
                <?php echo esc_html( $status_label ); ?>
            </span>
            <h2 class="brikpanel-bc-card-title"><?php echo esc_html( $label ); ?></h2>
        </div>
        <?php if ( $score > 0 || $status !== 'unknown' ) : ?>
            <div class="brikpanel-bc-card-score" title="<?php esc_attr_e( 'Health score', 'brikpanel' ); ?>">
                <span class="brikpanel-bc-score-num"><?php echo esc_html( $score ); ?></span>
                <span class="brikpanel-bc-score-suffix">/100</span>
            </div>
        <?php endif; ?>
    </header>

    <?php if ( $summary !== '' ) : ?>
        <p class="brikpanel-bc-card-summary"><?php echo esc_html( $summary ); ?></p>
    <?php endif; ?>

    <?php if ( $message !== '' && $message !== $summary ) : ?>
        <p class="brikpanel-bc-card-message"><?php echo esc_html( $message ); ?></p>
    <?php endif; ?>

    <?php if ( ! empty( $plugins_active ) ) : ?>
        <div class="brikpanel-bc-active-plugins">
            <span class="brikpanel-bc-active-plugins-label"><?php esc_html_e( 'Active optimizer plugins:', 'brikpanel' ); ?></span>
            <?php foreach ( $plugins_active as $slug => $plugin_label ) : ?>
                <span class="brikpanel-bc-plugin-pill"><?php echo esc_html( $plugin_label ); ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $recs ) ) : ?>
        <ul class="brikpanel-bc-recommendations">
            <?php foreach ( $recs as $rec ) :
                $priority = isset( $rec['priority'] ) ? $rec['priority'] : 'medium';
                $text     = isset( $rec['text'] ) ? $rec['text'] : '';
                $link     = isset( $rec['link'] ) && is_array( $rec['link'] ) ? $rec['link'] : null;

                // Resolve the URL at render time because scans run as AS
                // workers without a current user — the link metadata only
                // carries slug/search so we can pick the in-admin URL for
                // capable viewers and the wp.org URL for anyone else.
                $link_url = '';
                if ( $link ) {
                    if ( ! empty( $link['plugin_slug'] ) && class_exists( 'Brikpanel_BrikControl_Image_Plugins' ) ) {
                        $link_url = Brikpanel_BrikControl_Image_Plugins::resolve_install_url(
                            (string) $link['plugin_slug'],
                            isset( $link['plugin_search'] ) ? (string) $link['plugin_search'] : ''
                        );
                    } elseif ( ! empty( $link['url'] ) ) {
                        $link_url = (string) $link['url'];
                    }
                }
                $is_external = $link_url !== '' && strpos( $link_url, 'wordpress.org' ) !== false;
                ?>
                <li class="brikpanel-bc-rec brikpanel-bc-rec-<?php echo esc_attr( $priority ); ?>">
                    <span class="brikpanel-bc-rec-prio" aria-hidden="true"></span>
                    <span class="brikpanel-bc-rec-text"><?php echo esc_html( $text ); ?></span>
                    <?php if ( $link_url !== '' ) : ?>
                        <a class="brikpanel-bc-rec-link" href="<?php echo esc_url( $link_url ); ?>"<?php echo $is_external ? ' target="_blank" rel="noopener"' : ''; ?>>
                            <?php echo esc_html( $link['label'] ?? __( 'Open', 'brikpanel' ) ); ?>
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php
    // Repair action. Checks opt in via supports_fix(); `fixable` is the count
    // the button offers to clear, so a healthy card shows no button at all.
    $check_obj = class_exists( 'Brikpanel_BrikControl_Registry' )
        ? Brikpanel_BrikControl_Registry::get( $check_id )
        : null;
    $fixable = isset( $meta['fixable'] ) ? (int) $meta['fixable'] : 0;
    $can_fix = $check_obj
        && method_exists( $check_obj, 'supports_fix' )
        && $check_obj->supports_fix()
        && $fixable > 0
        && current_user_can( 'manage_woocommerce' );
    ?>
    <?php if ( $can_fix ) : ?>
        <div class="brikpanel-bc-card-actions">
            <button type="button"
                    class="brikpanel-bc-button brikpanel-bc-button-primary"
                    data-bc-fix="<?php echo esc_attr( $check_id ); ?>"
                    data-bc-fix-count="<?php echo esc_attr( $fixable ); ?>">
                <span data-bc-fix-label><?php echo esc_html( $check_obj->get_fix_label() ); ?></span>
            </button>
            <span class="brikpanel-bc-fix-result" data-bc-fix-result role="status" aria-live="polite"></span>
        </div>
    <?php endif; ?>

    <?php
    // Generic stats grid. Kept as an if/elseif so the image check's hard-coded
    // block below stays exactly as it was and no empty container is emitted for
    // a check whose totals use different keys.
    ?>
    <?php if ( ! empty( $meta['stats'] ) && is_array( $meta['stats'] ) ) : ?>
        <div class="brikpanel-bc-stats">
            <?php
            foreach ( $meta['stats'] as $stat ) :
                $tone       = isset( $stat['tone'] ) ? (string) $stat['tone'] : '';
                $tone_class = in_array( $tone, [ 'warn', 'good', 'error' ], true )
                    ? ' brikpanel-bc-stat-' . $tone
                    : '';
                ?>
                <div class="brikpanel-bc-stat<?php echo esc_attr( $tone_class ); ?>">
                    <span class="brikpanel-bc-stat-num"><?php echo esc_html( number_format_i18n( (float) ( $stat['value'] ?? 0 ) ) ); ?></span>
                    <span class="brikpanel-bc-stat-label"><?php echo esc_html( (string) ( $stat['label'] ?? '' ) ); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif ( ! empty( $meta['totals'] ) ) :
        $totals = $meta['totals'];
        ?>
        <div class="brikpanel-bc-stats">
            <?php if ( isset( $totals['attachments'] ) ) : ?>
                <div class="brikpanel-bc-stat">
                    <span class="brikpanel-bc-stat-num"><?php echo esc_html( number_format_i18n( $totals['attachments'] ) ); ?></span>
                    <span class="brikpanel-bc-stat-label"><?php esc_html_e( 'Total images', 'brikpanel' ); ?></span>
                </div>
            <?php endif; ?>
            <?php if ( isset( $totals['oversized'] ) ) : ?>
                <div class="brikpanel-bc-stat brikpanel-bc-stat-warn">
                    <span class="brikpanel-bc-stat-num"><?php echo esc_html( number_format_i18n( $totals['oversized'] ) ); ?></span>
                    <span class="brikpanel-bc-stat-label"><?php esc_html_e( 'Over 1 MB', 'brikpanel' ); ?></span>
                </div>
            <?php endif; ?>
            <?php if ( isset( $totals['webp_avif'] ) ) : ?>
                <div class="brikpanel-bc-stat brikpanel-bc-stat-good">
                    <span class="brikpanel-bc-stat-num"><?php echo esc_html( number_format_i18n( $totals['webp_avif'] ) ); ?></span>
                    <span class="brikpanel-bc-stat-label"><?php esc_html_e( 'WebP / AVIF', 'brikpanel' ); ?></span>
                </div>
            <?php endif; ?>
            <?php if ( isset( $totals['legacy'] ) ) : ?>
                <div class="brikpanel-bc-stat">
                    <span class="brikpanel-bc-stat-num"><?php echo esc_html( number_format_i18n( $totals['legacy'] ) ); ?></span>
                    <span class="brikpanel-bc-stat-label"><?php esc_html_e( 'JPEG / PNG', 'brikpanel' ); ?></span>
                </div>
            <?php endif; ?>
            <?php if ( ! empty( $totals['missing_files'] ) ) : ?>
                <div class="brikpanel-bc-stat brikpanel-bc-stat-error">
                    <span class="brikpanel-bc-stat-num"><?php echo esc_html( number_format_i18n( $totals['missing_files'] ) ); ?></span>
                    <span class="brikpanel-bc-stat-label"><?php esc_html_e( 'Missing files', 'brikpanel' ); ?></span>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php
    // Generic sample table: a check hands over plain rows plus its own column
    // headings, all translated server-side.
    if ( ! empty( $meta['samples'] ) && is_array( $meta['samples'] ) ) :
        $sample_cols  = ( ! empty( $meta['samples_cols'] ) && is_array( $meta['samples_cols'] ) )
            ? $meta['samples_cols']
            : [];
        $sample_title = ! empty( $meta['samples_title'] )
            ? (string) $meta['samples_title']
            : __( 'Details', 'brikpanel' );
        ?>
        <details class="brikpanel-bc-details">
            <summary><?php echo esc_html( $sample_title ); ?></summary>
            <table class="brikpanel-bc-largest-table">
                <?php if ( ! empty( $sample_cols ) ) : ?>
                    <thead>
                        <tr>
                            <?php foreach ( $sample_cols as $col ) : ?>
                                <th><?php echo esc_html( (string) $col ); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                <?php endif; ?>
                <tbody>
                    <?php foreach ( $meta['samples'] as $sample_row ) : ?>
                        <tr>
                            <?php foreach ( (array) $sample_row as $cell ) : ?>
                                <td><?php echo esc_html( (string) $cell ); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    <?php endif; ?>

    <?php if ( ! empty( $largest ) ) : ?>
        <details class="brikpanel-bc-details">
            <summary><?php esc_html_e( 'Largest images (top 10)', 'brikpanel' ); ?></summary>
            <table class="brikpanel-bc-largest-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Image', 'brikpanel' ); ?></th>
                        <th><?php esc_html_e( 'Size', 'brikpanel' ); ?></th>
                        <th><?php esc_html_e( 'Format', 'brikpanel' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'brikpanel' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $largest as $entry ) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( $entry['edit_url'] ?? '#' ); ?>">
                                    <?php
                                    $product_title = get_the_title( (int) ( $entry['post_id'] ?? 0 ) );
                                    echo esc_html( $product_title !== '' ? $product_title : __( '(no title)', 'brikpanel' ) );
                                    ?>
                                </a>
                            </td>
                            <td><?php echo esc_html( number_format_i18n( $entry['size_mb'] ?? 0, 2 ) ); ?> MB</td>
                            <td><?php echo esc_html( $entry['mime'] ?? '' ); ?></td>
                            <td>
                                <a class="brikpanel-bc-table-link" href="<?php echo esc_url( $entry['media_url'] ?? '#' ); ?>">
                                    <?php esc_html_e( 'Open', 'brikpanel' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    <?php endif; ?>

    <?php if ( $scanned > 0 ) : ?>
        <footer class="brikpanel-bc-card-foot">
            <span class="brikpanel-bc-card-meta">
                <?php
                printf(
                    /* translators: %s: human-readable date/time */
                    esc_html__( 'Scanned %s', 'brikpanel' ),
                    esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $scanned ) )
                );
                ?>
            </span>
        </footer>
    <?php endif; ?>
</article>
