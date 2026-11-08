<?php
/**
 * BrikPanel: Store Health unused images card
 *
 * Variables in scope (Brikpanel_BrikControl_Unused_Images::render_card()):
 *   - $view: array {
 *       running: bool, progress: array, result: array|null, count: int,
 *       summary: string, tiles: string (escaped markup), left: int,
 *       more_label: string, trash: array, trash_title: string, trash_left: int,
 *       trash_more_label: string, days: int, stale: bool
 *     }
 *
 * Every part of the card is in the page from the start; the script only
 * switches which one shows (intro, running, result) and fills the lists.
 *
 * @package BrikPanel
 * @since   3.3.39
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$bc_ui_running = ! empty( $view['running'] );
$bc_ui_result  = is_array( $view['result'] );
$bc_ui_state   = $bc_ui_running ? 'running' : ( $bc_ui_result ? 'result' : 'intro' );
$bc_ui_pct     = $bc_ui_running ? (float) ( $view['progress']['pct'] ?? 0 ) : 0;
$bc_ui_trash   = (int) $view['trash']['total'];
$bc_ui_days    = (int) $view['days'];
?>
<section class="brikpanel-bc-card brikpanel-unimg" id="brikpanel-unused-images" data-unimg data-state="<?php echo esc_attr( $bc_ui_state ); ?>" aria-labelledby="brikpanel-unimg-title">
    <header class="brikpanel-bc-card-head brikpanel-unimg-head">
        <h2 class="brikpanel-bc-card-title" id="brikpanel-unimg-title"><?php esc_html_e( 'Unused images', 'brikpanel' ); ?></h2>
        <button type="button" class="brikpanel-btn brikpanel-btn--secondary" data-unimg-start data-unimg-again<?php echo 'result' === $bc_ui_state ? '' : ' hidden'; ?>>
            <?php esc_html_e( 'Check again', 'brikpanel' ); ?>
        </button>
    </header>

    <div class="brikpanel-unimg-intro" data-unimg-part="intro"<?php echo 'intro' === $bc_ui_state ? '' : ' hidden'; ?>>
        <p class="brikpanel-unimg-lead"><?php esc_html_e( 'Find the images that no product, page or setting uses any more, look at them, and delete the ones you do not need.', 'brikpanel' ); ?></p>
        <div class="brikpanel-unimg-start">
            <button type="button" class="brikpanel-btn brikpanel-btn--primary" data-unimg-start><?php esc_html_e( 'Find unused images', 'brikpanel' ); ?></button>
            <span class="brikpanel-unimg-note"><?php esc_html_e( 'Nothing is deleted until you choose.', 'brikpanel' ); ?></span>
        </div>
    </div>

    <div class="brikpanel-unimg-running" data-unimg-part="running"<?php echo 'running' === $bc_ui_state ? '' : ' hidden'; ?>>
        <div class="brikpanel-bc-progress brikpanel-unimg-progress">
            <div class="brikpanel-bc-progress-text">
                <span data-unimg-label><?php echo esc_html( (string) ( $view['progress']['label'] ?? '' ) ); ?></span>
                <span data-unimg-pct><?php echo esc_html( (string) ( $view['progress']['pct_text'] ?? '' ) ); ?></span>
            </div>
            <div class="brikpanel-bc-progress-track">
                <div class="brikpanel-bc-progress-bar" data-unimg-bar style="width: <?php echo esc_attr( (string) min( 100, max( 0, $bc_ui_pct ) ) ); ?>%;"></div>
            </div>
        </div>
        <p class="brikpanel-unimg-note"><?php esc_html_e( 'You can leave this page. The check goes on in the background.', 'brikpanel' ); ?></p>
    </div>

    <div class="brikpanel-unimg-result" data-unimg-part="result"<?php echo 'result' === $bc_ui_state ? '' : ' hidden'; ?>>
        <div class="brikpanel-unimg-summary">
            <p class="brikpanel-unimg-lead" data-unimg-summary><?php echo esc_html( (string) $view['summary'] ); ?></p>
            <?php if ( $bc_ui_result ) : ?>
                <p class="brikpanel-unimg-note">
                    <?php
                    printf(
                        /* translators: %s: date and time of the check. */
                        esc_html__( 'Checked %s', 'brikpanel' ),
                        esc_html( wp_date( brikpanel_datetime_format(), (int) $view['result']['finished'] ) )
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>

        <?php if ( ! empty( $view['stale'] ) ) : ?>
            <p class="brikpanel-unimg-stale"><?php esc_html_e( 'This list is more than a week old. Check again before you delete anything.', 'brikpanel' ); ?></p>
        <?php endif; ?>

        <div class="brikpanel-unimg-view" data-unimg-view="list" data-left="<?php echo esc_attr( (string) (int) $view['left'] ); ?>" data-total="<?php echo esc_attr( (string) (int) $view['count'] ); ?>" data-bytes="<?php echo esc_attr( (string) (int) $view['bytes'] ); ?>"<?php echo ( (int) $view['count'] > 0 ) ? '' : ' hidden'; ?>>
            <div class="brikpanel-unimg-tools">
                <button type="button" class="brikpanel-btn brikpanel-btn--link brikpanel-unimg-selectall" data-unimg-all data-unimg-all-top><?php
                    echo esc_html( brikpanel_safe_sprintf(
                        /* translators: %s: number of images. */
                        _n( 'Select all %s', 'Select all %s', (int) $view['count'], 'brikpanel' ),
                        brikpanel_number( (int) $view['count'] )
                    ) );
                ?></button>
                <label class="brikpanel-unimg-sort">
                    <span class="brikpanel-unimg-sort-label"><?php esc_html_e( 'Sort', 'brikpanel' ); ?></span>
                    <select class="brikpanel-control brikpanel-unimg-sort-select" data-unimg-sort autocomplete="off">
                        <option value="size"><?php esc_html_e( 'Largest first', 'brikpanel' ); ?></option>
                        <option value="new"><?php esc_html_e( 'Newest first', 'brikpanel' ); ?></option>
                        <option value="old"><?php esc_html_e( 'Oldest first', 'brikpanel' ); ?></option>
                    </select>
                </label>
            </div>
            <ul class="brikpanel-unimg-grid" data-unimg-grid>
                <?php echo $view['tiles']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by tiles_html(). ?>
            </ul>
            <div class="brikpanel-unimg-more"<?php echo (int) $view['left'] > 0 ? '' : ' hidden'; ?>>
                <button type="button" class="brikpanel-btn brikpanel-btn--secondary" data-unimg-more><?php echo esc_html( (string) $view['more_label'] ); ?></button>
            </div>
            <div class="brikpanel-unimg-bar" data-unimg-selbar hidden>
                <div class="brikpanel-unimg-bar-inner">
                    <span class="brikpanel-unimg-bar-count" data-unimg-count dir="auto"></span>
                    <button type="button" class="brikpanel-unimg-bar-link" data-unimg-all></button>
                    <button type="button" class="brikpanel-unimg-bar-link" data-unimg-clear><?php esc_html_e( 'Clear', 'brikpanel' ); ?></button>
                    <span class="brikpanel-unimg-bar-actions">
                        <?php if ( $bc_ui_days > 0 ) : ?>
                            <button type="button" class="brikpanel-btn brikpanel-btn--primary brikpanel-unimg-act" data-unimg-act="trash"<?php echo ! empty( $view['stale'] ) ? ' disabled' : ''; ?>><?php esc_html_e( 'Move to trash', 'brikpanel' ); ?></button>
                        <?php else : ?>
                            <button type="button" class="brikpanel-btn brikpanel-btn--danger brikpanel-unimg-act" data-unimg-act="trash"<?php echo ! empty( $view['stale'] ) ? ' disabled' : ''; ?>><?php esc_html_e( 'Delete permanently', 'brikpanel' ); ?></button>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <p class="brikpanel-unimg-status" data-unimg-status role="status" aria-live="polite"></p>

    <details class="brikpanel-bc-details brikpanel-unimg-trash" data-unimg-trash<?php echo $bc_ui_trash > 0 ? '' : ' hidden'; ?>>
        <summary data-unimg-trash-title><?php echo esc_html( (string) $view['trash_title'] ); ?></summary>
        <p class="brikpanel-unimg-note">
            <?php
            echo esc_html( brikpanel_safe_sprintf(
                /* translators: %s: number of days WordPress keeps the trash. */
                _n(
                    'They are out of the Media Library, but their files stay on the server, so a page that still shows one keeps working. WordPress deletes them for good %s day after they were moved here.',
                    'They are out of the Media Library, but their files stay on the server, so a page that still shows one keeps working. WordPress deletes them for good %s days after they were moved here.',
                    max( 1, $bc_ui_days ),
                    'brikpanel'
                ),
                brikpanel_number( max( 1, $bc_ui_days ) )
            ) );
            ?>
        </p>
        <div class="brikpanel-unimg-view" data-unimg-view="trash" data-left="<?php echo esc_attr( (string) $bc_ui_trash ); ?>" data-total="<?php echo esc_attr( (string) $bc_ui_trash ); ?>" data-bytes="<?php echo esc_attr( (string) (int) $view['trash']['total_bytes'] ); ?>" data-loaded="0">
            <div class="brikpanel-unimg-tools">
                <button type="button" class="brikpanel-btn brikpanel-btn--link brikpanel-unimg-selectall" data-unimg-all data-unimg-all-top><?php
                    echo esc_html( brikpanel_safe_sprintf(
                        /* translators: %s: number of images. */
                        _n( 'Select all %s', 'Select all %s', $bc_ui_trash, 'brikpanel' ),
                        brikpanel_number( $bc_ui_trash )
                    ) );
                ?></button>
            </div>
            <ul class="brikpanel-unimg-grid" data-unimg-grid></ul>
            <div class="brikpanel-unimg-more" hidden>
                <button type="button" class="brikpanel-btn brikpanel-btn--secondary" data-unimg-more></button>
            </div>
            <div class="brikpanel-unimg-bar" data-unimg-selbar hidden>
                <div class="brikpanel-unimg-bar-inner">
                    <span class="brikpanel-unimg-bar-count" data-unimg-count dir="auto"></span>
                    <button type="button" class="brikpanel-unimg-bar-link" data-unimg-all></button>
                    <button type="button" class="brikpanel-unimg-bar-link" data-unimg-clear><?php esc_html_e( 'Clear', 'brikpanel' ); ?></button>
                    <span class="brikpanel-unimg-bar-actions">
                        <button type="button" class="brikpanel-btn brikpanel-btn--secondary brikpanel-unimg-act" data-unimg-act="restore"><?php esc_html_e( 'Restore', 'brikpanel' ); ?></button>
                        <button type="button" class="brikpanel-btn brikpanel-btn--danger brikpanel-unimg-act" data-unimg-act="purge"><?php esc_html_e( 'Delete permanently', 'brikpanel' ); ?></button>
                    </span>
                </div>
            </div>
        </div>
    </details>

    <footer class="brikpanel-bc-card-foot">
        <p class="brikpanel-bc-card-meta brikpanel-unimg-foot"><?php esc_html_e( 'Images used only outside this site, such as in e-mails you already sent or on social media, cannot be seen by this check. Images added in the last 24 hours are checked next time.', 'brikpanel' ); ?></p>
    </footer>

    <div class="brikpanel-unimg-dialog" data-unimg-dialog role="dialog" aria-modal="true" aria-labelledby="brikpanel-unimg-name" tabindex="-1">
        <div class="brikpanel-unimg-dialog-media">
            <img data-unimg-d-img alt="">
            <span class="brikpanel-unimg-missing" data-unimg-d-missing hidden>
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="m3 3 18 18"/><path d="M21 15l-5-5L5 21"/></svg>
                <span><?php esc_html_e( 'File missing', 'brikpanel' ); ?></span>
            </span>
            <button type="button" class="brikpanel-unimg-nav brikpanel-unimg-nav--prev" data-unimg-d-nav="-1" aria-label="<?php esc_attr_e( 'Previous image', 'brikpanel' ); ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <button type="button" class="brikpanel-unimg-nav brikpanel-unimg-nav--next" data-unimg-d-nav="1" aria-label="<?php esc_attr_e( 'Next image', 'brikpanel' ); ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6"/></svg>
            </button>
        </div>
        <div class="brikpanel-unimg-dialog-info">
            <div class="brikpanel-unimg-dialog-head">
                <p class="brikpanel-unimg-dialog-pos" data-unimg-d-pos dir="auto"></p>
                <button type="button" class="brikpanel-unimg-close" data-unimg-d-close aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="m5.5 5.5 9 9M14.5 5.5l-9 9"/></svg>
                </button>
            </div>
            <h3 class="brikpanel-unimg-dialog-name" id="brikpanel-unimg-name" data-unimg-d="name" dir="auto"></h3>
            <dl class="brikpanel-unimg-facts">
                <div data-unimg-d-row="size"><dt><?php esc_html_e( 'File size', 'brikpanel' ); ?></dt><dd data-unimg-d="size" dir="auto"></dd></div>
                <div data-unimg-d-row="dims"><dt><?php echo esc_html_x( 'Dimensions', 'width and height of an image', 'brikpanel' ); ?></dt><dd data-unimg-d="dims" dir="auto"></dd></div>
                <div data-unimg-d-row="date"><dt><?php esc_html_e( 'Uploaded', 'brikpanel' ); ?></dt><dd data-unimg-d="date" dir="auto"></dd></div>
                <div data-unimg-d-row="parent"><dt><?php esc_html_e( 'Uploaded to', 'brikpanel' ); ?></dt><dd><a href="#" data-unimg-d-link="parent_url"><span data-unimg-d="parent"></span></a><span data-unimg-d-plain="parent"></span></dd></div>
                <div data-unimg-d-row="until"><dt><?php esc_html_e( 'In the trash', 'brikpanel' ); ?></dt><dd data-unimg-d="until" dir="auto"></dd></div>
            </dl>
            <div class="brikpanel-unimg-dialog-actions">
                <a class="brikpanel-btn brikpanel-btn--secondary brikpanel-unimg-act" href="#" data-unimg-d-link="media" data-unimg-d-only="list"><?php esc_html_e( 'Open in Media Library', 'brikpanel' ); ?></a>
                <?php if ( $bc_ui_days > 0 ) : ?>
                    <button type="button" class="brikpanel-btn brikpanel-btn--primary brikpanel-unimg-act" data-unimg-d-act="trash" data-unimg-d-only="list"<?php echo ! empty( $view['stale'] ) ? ' disabled' : ''; ?>><?php esc_html_e( 'Move to trash', 'brikpanel' ); ?></button>
                <?php else : ?>
                    <button type="button" class="brikpanel-btn brikpanel-btn--danger brikpanel-unimg-act" data-unimg-d-act="trash" data-unimg-d-only="list"<?php echo ! empty( $view['stale'] ) ? ' disabled' : ''; ?>><?php esc_html_e( 'Delete permanently', 'brikpanel' ); ?></button>
                <?php endif; ?>
                <button type="button" class="brikpanel-btn brikpanel-btn--secondary brikpanel-unimg-act" data-unimg-d-act="restore" data-unimg-d-only="trash"><?php esc_html_e( 'Restore', 'brikpanel' ); ?></button>
                <button type="button" class="brikpanel-btn brikpanel-btn--danger brikpanel-unimg-act" data-unimg-d-act="purge" data-unimg-d-only="trash"><?php esc_html_e( 'Delete permanently', 'brikpanel' ); ?></button>
            </div>
        </div>
    </div>
</section>
