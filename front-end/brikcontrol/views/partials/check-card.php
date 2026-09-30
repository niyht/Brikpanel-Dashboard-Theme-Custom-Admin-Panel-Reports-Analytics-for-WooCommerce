<?php
/**
 * BrikPanel — BrikControl Check Card Partial
 *
 * Variables in scope (from views/page.php loop):
 *   - $check_id     : string
 *   - $check_result : array  (CheckResult schema, already presented by the
 *                    check's bc_present(): sentences in the viewer's language,
 *                    score banded to the status or null for none)
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$status   = $check_result['status'] ?? 'unknown';
$score    = isset( $check_result['score'] ) && is_numeric( $check_result['score'] ) ? (int) $check_result['score'] : null;
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
        <?php if ( null !== $score ) : ?>
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

                // One link (`link`) or several (`links`: one recommendation
                // with a button per plugin, field test E6).
                $rec_links = [];
                if ( ! empty( $rec['links'] ) && is_array( $rec['links'] ) ) {
                    $rec_links = $rec['links'];
                } elseif ( ! empty( $rec['link'] ) && is_array( $rec['link'] ) ) {
                    $rec_links = [ $rec['link'] ];
                }
                ?>
                <li class="brikpanel-bc-rec brikpanel-bc-rec-<?php echo esc_attr( $priority ); ?>">
                    <span class="brikpanel-bc-rec-prio" aria-hidden="true"></span>
                    <span class="brikpanel-bc-rec-text"><?php echo esc_html( $text ); ?></span>
                    <?php if ( ! empty( $rec_links ) ) : ?>
                        <span class="brikpanel-bc-rec-actions">
                            <?php
                            foreach ( $rec_links as $link ) :
                                if ( ! is_array( $link ) ) {
                                    continue;
                                }
                                // Resolved per viewer: scans run as Action Scheduler
                                // workers without a current user, so a plugin link
                                // only carries slug/search, and the viewer gets the
                                // in-admin installer or the wp.org page.
                                $link_url = '';
                                if ( ! empty( $link['plugin_slug'] ) && class_exists( 'Brikpanel_BrikControl_Image_Plugins' ) ) {
                                    $link_url = Brikpanel_BrikControl_Image_Plugins::resolve_install_url(
                                        (string) $link['plugin_slug'],
                                        isset( $link['plugin_search'] ) ? (string) $link['plugin_search'] : ''
                                    );
                                } elseif ( ! empty( $link['url'] ) ) {
                                    $link_url = (string) $link['url'];
                                }
                                if ( '' === $link_url ) {
                                    continue;
                                }
                                $is_external = strpos( $link_url, 'wordpress.org' ) !== false;
                                $link_label  = isset( $link['label'] ) ? (string) $link['label'] : __( 'Open', 'brikpanel' );
                                ?>
                                <a class="brikpanel-btn brikpanel-btn--secondary" href="<?php echo esc_url( $link_url ); ?>"<?php echo $is_external ? ' target="_blank" rel="noopener"' : ''; ?><?php echo ! empty( $link['aria_label'] ) ? ' aria-label="' . esc_attr( (string) $link['aria_label'] ) . '"' : ''; ?>><?php echo esc_html( $link_label ); ?></a>
                            <?php endforeach; ?>
                        </span>
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
    $can_manage = current_user_can( 'manage_woocommerce' );
    $can_fix = $check_obj
        && method_exists( $check_obj, 'supports_fix' )
        && $check_obj->supports_fix()
        && $fixable > 0
        && $can_manage;

    // Every check hands over its own confirmation, counted with _n() in the
    // viewer's language; a check that has none gets a neutral one.
    $fix_confirm = isset( $meta['fix_confirm'] ) ? (string) $meta['fix_confirm'] : '';
    if ( '' === $fix_confirm ) {
        $fix_confirm = sprintf(
            /* translators: %s: number of items a Store Health cleanup would remove. */
            _n( 'Clean up %s item?', 'Clean up %s items?', $fixable, 'brikpanel' ),
            brikpanel_number( $fixable )
        );
    }
    $fix_confirm = str_replace( '{count}', brikpanel_number( $fixable ), $fix_confirm );

    // Undo is offered whenever the check kept a restore point, independently of
    // whether anything is currently flagged: after a successful correction
    // there is by definition nothing left to fix, and that is exactly the
    // moment the merchant is most likely to want the old figures back.
    $undoable = isset( $meta['undoable'] ) ? (int) $meta['undoable'] : 0;
    $can_undo = $check_obj
        && method_exists( $check_obj, 'run_undo' )
        && $undoable > 0
        && $can_manage;
    $undo_at  = isset( $meta['undo_at'] ) ? (int) $meta['undo_at'] : 0;
    // Same idea as fix_confirm. Results stored before 3.3.25 still carry a
    // {count} token, filled in here.
    $undo_confirm = isset( $meta['undo_confirm'] ) ? (string) $meta['undo_confirm'] : '';
    if ( '' === $undo_confirm ) {
        $undo_confirm = sprintf(
            /* translators: %s: number of database rows to put back. */
            _n(
                'Put the previous values back? This restores %s row exactly as it was before the last correction.',
                'Put the previous values back? This restores %s rows exactly as they were before the last correction.',
                $undoable,
                'brikpanel'
            ),
            brikpanel_number( $undoable )
        );
    }
    $undo_confirm = str_replace( '{count}', brikpanel_number( $undoable ), $undo_confirm );
    ?>
    <?php if ( $can_fix || $can_undo ) : ?>
        <div class="brikpanel-bc-card-actions">
            <?php if ( $can_fix ) : ?>
                <button type="button"
                        class="brikpanel-bc-button brikpanel-bc-button-primary"
                        data-bc-fix="<?php echo esc_attr( $check_id ); ?>"
                        data-bc-fix-confirm="<?php echo esc_attr( $fix_confirm ); ?>">
                    <span data-bc-fix-label><?php echo esc_html( $check_obj->get_fix_label() ); ?></span>
                </button>
            <?php endif; ?>
            <?php if ( $can_undo ) : ?>
                <button type="button"
                        class="brikpanel-bc-button"
                        data-bc-undo="<?php echo esc_attr( $check_id ); ?>"
                        data-bc-undo-confirm="<?php echo esc_attr( $undo_confirm ); ?>"
                        <?php if ( $undo_at > 0 ) : ?>title="<?php
                            printf(
                                /* translators: %s: human-readable date/time of the last correction. */
                                esc_attr__( 'Corrected %s', 'brikpanel' ),
                                esc_attr( wp_date( brikpanel_datetime_format(), $undo_at ) )
                            );
                        ?>"<?php endif; ?>>
                    <span data-bc-undo-label><?php esc_html_e( 'Undo last correction', 'brikpanel' ); ?></span>
                </button>
            <?php endif; ?>
            <span class="brikpanel-bc-fix-result" data-bc-fix-result role="status" aria-live="polite"></span>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $meta['stats'] ) && is_array( $meta['stats'] ) ) : ?>
        <div class="brikpanel-bc-stats" data-bp-tiles>
            <?php
            foreach ( $meta['stats'] as $stat ) :
                $tone       = isset( $stat['tone'] ) ? (string) $stat['tone'] : '';
                $tone_class = in_array( $tone, [ 'warn', 'good', 'error' ], true )
                    ? ' brikpanel-bc-stat-' . $tone
                    : '';
                ?>
                <div class="brikpanel-bc-stat<?php echo esc_attr( $tone_class ); ?>">
                    <span class="brikpanel-bc-stat-num"><?php echo esc_html( brikpanel_number( (float) ( $stat['value'] ?? 0 ) ) ); ?></span>
                    <span class="brikpanel-bc-stat-label"><?php echo esc_html( (string) ( $stat['label'] ?? '' ) ); ?></span>
                </div>
            <?php endforeach; ?>
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
            <div class="brikpanel-bc-table-wrap">
            <table class="brikpanel-bc-largest-table brikpanel-fit-table">
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
            </div>
        </details>
    <?php endif; ?>

    <?php if ( ! empty( $largest ) ) : ?>
        <details class="brikpanel-bc-details">
            <summary><?php esc_html_e( 'Largest images (top 10)', 'brikpanel' ); ?></summary>
            <div class="brikpanel-bc-table-wrap">
            <table class="brikpanel-bc-largest-table brikpanel-fit-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Image', 'brikpanel' ); ?></th>
                        <th><?php esc_html_e( 'Size', 'brikpanel' ); ?></th>
                        <th><?php esc_html_e( 'Format', 'brikpanel' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'brikpanel' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ( $largest as $entry ) :
                        $entry_post  = (int) ( $entry['post_id'] ?? 0 );
                        $entry_image = (int) ( $entry['id'] ?? 0 );
                        // Built for this viewer: a link stored by the scan worker
                        // (no current user) was always empty.
                        $entry_edit  = $entry_post > 0 ? (string) get_edit_post_link( $entry_post, 'raw' ) : '';
                        $entry_media = $entry_image > 0 ? (string) get_edit_post_link( $entry_image, 'raw' ) : '';
                        $entry_title = $entry_post > 0 ? brikpanel_plain_label( get_the_title( $entry_post ) ) : '';
                        ?>
                        <tr>
                            <td class="brikpanel-fit-lead">
                                <?php if ( '' !== $entry_edit ) : ?>
                                    <a href="<?php echo esc_url( $entry_edit ); ?>"><?php echo esc_html( '' !== $entry_title ? $entry_title : __( '(no title)', 'brikpanel' ) ); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html( '' !== $entry_title ? $entry_title : __( '(no title)', 'brikpanel' ) ); ?>
                                <?php endif; ?>
                            </td>
                            <td class="brikpanel-bc-nowrap"><?php
                                /* translators: %s: an image file size in megabytes, e.g. "2.40" */
                                echo esc_html( sprintf( __( '%s MB', 'brikpanel' ), brikpanel_number( (float) ( $entry['size_mb'] ?? 0 ), 2 ) ) );
                            ?></td>
                            <td><?php echo esc_html( $entry['mime'] ?? '' ); ?></td>
                            <td>
                                <?php if ( '' !== $entry_media ) : ?>
                                    <a class="brikpanel-bc-table-link" href="<?php echo esc_url( $entry_media ); ?>">
                                        <?php esc_html_e( 'Open', 'brikpanel' ); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </details>
    <?php endif; ?>

    <?php if ( $scanned > 0 ) : ?>
        <footer class="brikpanel-bc-card-foot">
            <span class="brikpanel-bc-card-meta">
                <?php
                printf(
                    /* translators: %s: human-readable date/time */
                    esc_html__( 'Scanned %s', 'brikpanel' ),
                    esc_html( wp_date( brikpanel_datetime_format(), $scanned ) )
                );
                if ( ! empty( $meta['scope'] ) ) {
                    echo ' · ' . esc_html( (string) $meta['scope'] );
                }
                ?>
            </span>
        </footer>
    <?php endif; ?>
</article>
