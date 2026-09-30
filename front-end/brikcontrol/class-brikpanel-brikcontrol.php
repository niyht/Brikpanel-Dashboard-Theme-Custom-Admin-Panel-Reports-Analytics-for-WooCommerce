<?php
/**
 * BrikPanel — BrikControl
 *
 * Public façade for the Store Health panel: owns the admin page, the AJAX
 * endpoints, and the topbar render hook. Storage / scan logic
 * lives in the sibling classes — this file is wiring + view glue.
 *
 * @package BrikPanel
 * @since   3.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_BrikControl {

    const PAGE_SLUG     = 'brikpanel-brikcontrol';
    const NONCE_ACTION  = 'brikpanel_brikcontrol_nonce';
    const SCRIPT_HANDLE = 'brikpanel-brikcontrol';
    const TOPBAR_HANDLE = 'brikpanel-brikcontrol-topbar';

    /**
     * Seconds to wait after a plugin activate/deactivate before scanning, so a
     * bulk operation is fully applied by the time the worker measures it.
     */
    const PLUGIN_CHANGE_DELAY = 90;

    /** Minimum spacing between plugin-change-triggered scans. */
    const PLUGIN_CHANGE_COOLDOWN = 15 * MINUTE_IN_SECONDS;

    /** Transient backing the cooldown above. */
    const TRANSIENT_PLUGIN_COOLDOWN = 'brikpanel_brikcontrol_plugin_scan_cooldown';

    /**
     * Checks whose figures another check's cleanup or undo changes, keyed by
     * the check that ran: the bot traffic cleanup deletes (and its undo puts
     * back) the scripted entries the "Abandoned cart entries" check counts.
     */
    const LINKED_CHECKS = [
        'bot_traffic' => [ 'cartab_bot_rows' ],
    ];

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',          [ $this, 'register_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_topbar_assets' ] );

        // AJAX
        add_action( 'wp_ajax_brikpanel_brikcontrol_data',     [ $this, 'ajax_data' ] );
        add_action( 'wp_ajax_brikpanel_brikcontrol_rescan',   [ $this, 'ajax_rescan' ] );
        add_action( 'wp_ajax_brikpanel_brikcontrol_progress', [ $this, 'ajax_progress' ] );
        add_action( 'wp_ajax_brikpanel_brikcontrol_dismiss',  [ $this, 'ajax_dismiss' ] );
        add_action( 'wp_ajax_brikpanel_brikcontrol_fix',      [ $this, 'ajax_fix' ] );
        add_action( 'wp_ajax_brikpanel_brikcontrol_undo',     [ $this, 'ajax_undo' ] );

        // Plugin activation / deactivation invalidates the cached health
        // verdict because the active optimizer set is what gates how we
        // score sidecar .webp files. Both hooks fire on the *next* request
        // after the change, so we trigger an async rescan instead of running
        // it inline (the AS worker picks it up within the same minute).
        add_action( 'activated_plugin',   [ $this, 'on_plugin_change' ], 10, 0 );
        add_action( 'deactivated_plugin', [ $this, 'on_plugin_change' ], 10, 0 );
    }

    /**
     * Invalidates the topbar transient so the next pageview reflects the
     * "stale until rescan" state, then queues ONE fresh background scan.
     *
     * Three independent guards, because this hook is far noisier than it looks:
     *
     *  1. Per-request static. WordPress fires activated_plugin /
     *     deactivated_plugin once PER PLUGIN, so a bulk toggle of 20 plugins
     *     entered this method 20 times in a single request and enqueued 20
     *     identical scans (plus 20 transient DELETEs).
     *  2. Cooldown transient. A third-party plugin whose own admin_init
     *     dependency check calls deactivate_plugins() re-fires this hook on
     *     EVERY admin page load; without a floor the queue re-arms as fast as
     *     the worker can drain it.
     *  3. The args-aware Action Scheduler dedupe inside trigger_manual_scan().
     *
     * The scan is deferred rather than enqueued async because this hook fires
     * BEFORE the rest of a bulk operation has been processed — running
     * immediately would measure a half-applied plugin set, which is precisely
     * the state the scan is supposed to report on.
     */
    public function on_plugin_change() {
        // BrikPanel's own deactivation fires this hook too (its deactivation
        // hook sets the flag first). A scan queued then would be left behind
        // for a plugin that is going away, so nothing is queued.
        if ( ! empty( $GLOBALS['brikpanel_self_deactivating'] ) ) {
            return;
        }

        static $handled = false;
        if ( $handled ) {
            return;
        }
        $handled = true;

        if ( class_exists( 'Brikpanel_BrikControl_Storage' ) ) {
            delete_transient( Brikpanel_BrikControl_Storage::TRANSIENT_TOPBAR );
        }
        if ( ! class_exists( 'Brikpanel_BrikControl_Runner' ) ) {
            return;
        }
        if ( get_transient( self::TRANSIENT_PLUGIN_COOLDOWN ) ) {
            // Inside the cooldown, but this is still a real plugin change. Move
            // the scan that is already queued out to the new deadline instead of
            // dropping the change on the floor: the cooldown exists to keep the
            // QUEUE at one row, not to make Store Health report on a plugin set
            // that stopped being current ten minutes ago.
            Brikpanel_BrikControl_Runner::defer_pending_manual_scan( self::PLUGIN_CHANGE_DELAY );
            return;
        }

        $queued = Brikpanel_BrikControl_Runner::trigger_manual_scan( self::PLUGIN_CHANGE_DELAY );

        // Arm the cooldown only once a scan is genuinely on the queue. Setting
        // it first and discarding the return meant that any failure to enqueue
        // — Action Scheduler not initialised yet, the store refusing the insert
        // — silently cost the merchant a rescan with no retry path, because
        // nothing ever deletes this transient before its TTL.
        if ( false === $queued ) {
            return;
        }
        if ( ! Brikpanel_Cron::is_scheduled( Brikpanel_BrikControl_Runner::HOOK_SCAN, Brikpanel_BrikControl_Runner::SCAN_ARGS_MANUAL ) ) {
            return;
        }

        set_transient( self::TRANSIENT_PLUGIN_COOLDOWN, 1, self::PLUGIN_CHANGE_COOLDOWN );
    }

    // =========================================================================
    // PAGE REGISTRATION
    // =========================================================================

    public function register_page() {
        $hook = add_submenu_page(
            '',
            __( 'Store Health', 'brikpanel' ),
            '',
            'manage_woocommerce',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );

        if ( $hook ) {
            add_action( 'load-' . $hook, [ $this, 'on_page_load' ] );
        }
    }

    public function on_page_load() {
        global $title;
        $title = __( 'Store Health', 'brikpanel' );
        $this->enqueue_page_assets();
    }

    /**
     * Asset version from the file's own change time, so an edited file is never
     * served stale from a browser cache while the plugin version stays the same.
     */
    private static function asset_version( $file ) {
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- falls back to the plugin version.
        return @filemtime( BRIKPANEL_PATH . 'front-end/brikcontrol/assets/' . $file ) ?: BRIKPANEL_VERSION;
    }

    private function enqueue_page_assets() {
        // Sample tables stack into cards when they do not fit (field test B6).
        $fit_style  = function_exists( 'brikpanel_fit_table_dep' ) ? brikpanel_fit_table_dep( 'style' ) : [];
        $fit_script = function_exists( 'brikpanel_fit_table_dep' ) ? brikpanel_fit_table_dep() : [];
        // Summary tiles and per-check figures never wrap 2 + 1 (field test C10).
        if ( function_exists( 'brikpanel_narrow_dep' ) ) {
            $fit_style  = array_merge( $fit_style, brikpanel_narrow_dep( 'tiles', 'style' ) );
            $fit_script = array_merge( $fit_script, brikpanel_narrow_dep( 'tiles' ) );
        }

        wp_enqueue_style(
            self::SCRIPT_HANDLE,
            BRIKPANEL_URL . 'front-end/brikcontrol/assets/brikpanel-brikcontrol.css',
            $fit_style,
            self::asset_version( 'brikpanel-brikcontrol.css' )
        );

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            BRIKPANEL_URL . 'front-end/brikcontrol/assets/brikpanel-brikcontrol.js',
            $fit_script,
            self::asset_version( 'brikpanel-brikcontrol.js' ),
            true
        );

        wp_localize_script( self::SCRIPT_HANDLE, 'brikpanelBrikControl', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
            'page_url' => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
            // Sentences with a number in them (progress, confirmations, results)
            // come from the server, written with _n() in the viewer's language.
            'i18n'     => [
                'rescanning'         => __( 'Scan queued. Refreshing…', 'brikpanel' ),
                'rescan_failed'      => __( 'Could not start scan.', 'brikpanel' ),
                'fix_running'        => __( 'Cleaning up…', 'brikpanel' ),
                'fix_failed'         => __( 'Cleanup failed. Please try again.', 'brikpanel' ),
                'undo_running'       => __( 'Restoring…', 'brikpanel' ),
                'undo_failed'        => __( 'Could not restore. Please try again.', 'brikpanel' ),
            ],
        ] );
    }

    // =========================================================================
    // TOPBAR ASSET ENQUEUE (every admin page where topbar renders)
    // =========================================================================

    public function enqueue_topbar_assets( $hook = '' ) {
        if ( ! class_exists( 'Brikpanel_Dashboard_Topbar' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        if ( wp_doing_ajax() ) {
            return;
        }

        $topbar_enabled = Brikpanel_Dashboard_Topbar::is_enabled();

        if ( ! $topbar_enabled ) {
            return;
        }

        wp_enqueue_style(
            self::TOPBAR_HANDLE,
            BRIKPANEL_URL . 'front-end/brikcontrol/assets/brikpanel-brikcontrol.css',
            [],
            self::asset_version( 'brikpanel-brikcontrol.css' )
        );

        wp_enqueue_script(
            self::TOPBAR_HANDLE,
            BRIKPANEL_URL . 'front-end/brikcontrol/assets/brikpanel-brikcontrol-topbar.js',
            [],
            self::asset_version( 'brikpanel-brikcontrol-topbar.js' ),
            true
        );

        wp_localize_script( self::TOPBAR_HANDLE, 'brikpanelBrikControlTopbar', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
            'page_url' => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
            'refresh_interval' => 60000,
            // "5 min ago" comes with every status reply (last_scan_relative),
            // written by relative_time() with _n().
            'i18n'     => [
                'all_ok'         => __( 'All checks passing', 'brikpanel' ),
                'never_scanned'  => __( 'Not scanned yet', 'brikpanel' ),
                'scan_running'   => __( 'Scanning…', 'brikpanel' ),
            ],
        ] );
    }

    // =========================================================================
    // PAGE RENDER
    // =========================================================================

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'brikpanel' ) );
        }

        // Revive a scan whose batch chain died between slices before reading
        // the lock, so the page never opens on a frozen progress bar.
        if ( class_exists( 'Brikpanel_BrikControl_Runner' ) ) {
            Brikpanel_BrikControl_Runner::maybe_resume();
        }

        $bundle    = Brikpanel_BrikControl_Storage::get_results();
        $progress  = Brikpanel_BrikControl_Storage::get_progress();
        $is_active = Brikpanel_BrikControl_Storage::is_scan_active();
        $registry  = Brikpanel_BrikControl_Registry::get_all();

        // Results stored before 3.3.25 hold sentences in the language of the
        // scan: queue one rescan so they are replaced by figures.
        Brikpanel_BrikControl_Storage::maybe_heal( $bundle );

        // Every check writes its own sentences, in this viewer's language.
        $bundle = Brikpanel_BrikControl_Storage::present_bundle( 'page', $bundle );

        // Surface registered checks that have not yet produced a result so the
        // page never looks empty on first visit.
        foreach ( $registry as $check_id => $check ) {
            if ( ! isset( $bundle['checks'][ $check_id ] ) ) {
                $bundle['checks'][ $check_id ] = [
                    'id'              => $check_id,
                    'label'           => $check->get_label(),
                    'category'        => $check->get_category(),
                    'status'          => 'unknown',
                    'score'           => null,
                    'summary'         => __( 'Not scanned yet. Run a scan to see results.', 'brikpanel' ),
                    'message'         => '',
                    'recommendations' => [],
                    'metadata'        => [],
                    'scanned_at'      => 0,
                    'duration_ms'     => 0,
                ];
            }
        }

        $progress_texts = self::progress_texts( $progress );

        include BRIKPANEL_PATH . 'front-end/brikcontrol/views/page.php';
    }

    // =========================================================================
    // TOPBAR BUTTON RENDER (called from Brikpanel_Dashboard_Topbar::render)
    // =========================================================================

    /**
     * Always-visible shield icon in the topbar. Color + badge derived from
     * the cached topbar payload — no AJAX on render.
     */
    public function render_topbar_button() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $payload = Brikpanel_BrikControl_Storage::get_topbar_payload();
        $summary = isset( $payload['summary'] ) ? $payload['summary'] : [ 'critical' => 0, 'warning' => 0, 'ok' => 0 ];

        $critical = (int) ( $summary['critical'] ?? 0 );
        $warning  = (int) ( $summary['warning'] ?? 0 );

        if ( $critical > 0 ) {
            $state       = 'critical';
            $badge_text  = (string) $critical;
            $title_attr  = sprintf(
                /* translators: %s: critical issue count */
                _n( '%s critical store health issue', '%s critical store health issues', $critical, 'brikpanel' ),
                brikpanel_number( $critical )
            );
        } elseif ( $warning > 0 ) {
            $state       = 'warning';
            $badge_text  = (string) $warning;
            $title_attr  = sprintf(
                /* translators: %s: warning count */
                _n( '%s store health warning', '%s store health warnings', $warning, 'brikpanel' ),
                brikpanel_number( $warning )
            );
        } else {
            $state       = 'ok';
            $badge_text  = '';
            $title_attr  = __( 'Store health: all checks passing', 'brikpanel' );
        }

        $svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V6l8-4z"/><path d="M9 12l2 2 4-4"/></svg>';
        ?>
        <div class="brikpanel-topbar-menu brikpanel-bc-menu" data-topbar-menu="brikcontrol" data-state="<?php echo esc_attr( $state ); ?>">
            <button type="button"
                    class="brikpanel-topbar-icon-btn brikpanel-bc-btn brikpanel-bc-state-<?php echo esc_attr( $state ); ?>"
                    data-topbar-toggle="brikcontrol"
                    aria-haspopup="menu"
                    aria-expanded="false"
                    title="<?php echo esc_attr( $title_attr ); ?>"
                    aria-label="<?php echo esc_attr( $title_attr ); ?>">
                <?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php if ( $badge_text !== '' ) : ?>
                    <span class="brikpanel-topbar-badge brikpanel-bc-badge"><?php echo esc_html( $badge_text ); ?></span>
                <?php endif; ?>
            </button>
            <div class="brikpanel-topbar-dropdown brikpanel-topbar-dropdown-wide brikpanel-bc-dropdown" role="menu" data-bc-dropdown>
                <div class="brikpanel-topbar-dropdown-header">
                    <span><?php esc_html_e( 'Store Health', 'brikpanel' ); ?></span>
                    <span class="brikpanel-bc-last-scan" data-bc-last-scan>
                        <?php
                        if ( ! empty( $payload['last_scan'] ) ) {
                            echo esc_html( $this->relative_time( (int) $payload['last_scan'] ) );
                        } else {
                            esc_html_e( 'Not scanned yet', 'brikpanel' );
                        }
                        ?>
                    </span>
                </div>
                <div class="brikpanel-bc-dropdown-list" data-bc-list>
                    <?php $this->render_topbar_check_rows( $payload ); ?>
                </div>
                <div class="brikpanel-bc-dropdown-actions">
                    <button type="button" class="brikpanel-bc-rescan-btn" data-bc-rescan>
                        <?php esc_html_e( 'Rescan now', 'brikpanel' ); ?>
                    </button>
                    <a class="brikpanel-bc-report-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">
                        <?php esc_html_e( 'Open full report', 'brikpanel' ); ?>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_topbar_check_rows( array $payload ) {
        $checks = isset( $payload['checks'] ) && is_array( $payload['checks'] ) ? $payload['checks'] : [];
        if ( empty( $checks ) ) {
            ?>
            <div class="brikpanel-bc-empty">
                <?php esc_html_e( 'Run a scan to see store health checks.', 'brikpanel' ); ?>
            </div>
            <?php
            return;
        }

        foreach ( $checks as $check ) {
            $status  = isset( $check['status'] ) ? (string) $check['status'] : 'unknown';
            $label   = isset( $check['label'] ) ? (string) $check['label'] : '';
            $summary = isset( $check['summary'] ) ? (string) $check['summary'] : '';
            ?>
            <div class="brikpanel-bc-row brikpanel-bc-row-<?php echo esc_attr( $status ); ?>">
                <span class="brikpanel-bc-dot brikpanel-bc-dot-<?php echo esc_attr( $status ); ?>" aria-hidden="true"></span>
                <div class="brikpanel-bc-row-text">
                    <span class="brikpanel-bc-row-label"><?php echo esc_html( $label ); ?></span>
                    <?php if ( $summary !== '' ) : ?>
                        <span class="brikpanel-bc-row-summary"><?php echo esc_html( $summary ); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    public function ajax_data() {
        $this->verify_ajax();

        if ( class_exists( 'Brikpanel_BrikControl_Runner' ) ) {
            Brikpanel_BrikControl_Runner::maybe_resume();
        }

        // What the topbar shows: label, status and the one-line summary per
        // check, written for this viewer. The full bundle (sample tables,
        // restore-point details) never leaves the page render.
        $payload = Brikpanel_BrikControl_Storage::get_topbar_payload();
        $checks  = [];
        foreach ( $payload['checks'] as $row ) {
            $checks[ $row['id'] ] = [
                'label'   => $row['label'],
                'status'  => $row['status'],
                'summary' => $row['summary'],
            ];
        }

        // Make sure registered-but-not-yet-scanned checks appear so the topbar
        // never shows an "empty" dropdown after install.
        foreach ( Brikpanel_BrikControl_Registry::get_all() as $check_id => $check ) {
            if ( ! isset( $checks[ $check_id ] ) ) {
                $checks[ $check_id ] = [
                    'label'   => $check->get_label(),
                    'status'  => 'unknown',
                    'summary' => __( 'Not scanned yet', 'brikpanel' ),
                ];
            }
        }

        $last_scan = (int) $payload['last_scan'];

        wp_send_json_success( [
            'bundle'             => [
                'last_scan'      => $last_scan,
                'status_summary' => $payload['summary'],
                'checks'         => $checks,
            ],
            'progress'           => Brikpanel_BrikControl_Storage::get_progress(),
            'is_active'          => Brikpanel_BrikControl_Storage::is_scan_active(),
            'last_scan_relative' => $last_scan > 0 ? $this->relative_time( $last_scan ) : '',
        ] );
    }

    public function ajax_rescan() {
        $this->verify_ajax();

        // A dead chain must not masquerade as a running scan and swallow the
        // click: revive (or release) it first, then decide.
        if ( class_exists( 'Brikpanel_BrikControl_Runner' ) ) {
            Brikpanel_BrikControl_Runner::maybe_resume();
        }

        if ( Brikpanel_BrikControl_Storage::is_scan_active() ) {
            wp_send_json_success( [
                'queued'   => false,
                'message'  => __( 'A scan is already running.', 'brikpanel' ),
                'progress' => Brikpanel_BrikControl_Storage::get_progress(),
            ] );
        }

        // Hard fail only when AS itself is missing — unique-conflict with an
        // already-pending scan returns 0, and that's still success from the
        // user's perspective (their scan request is satisfied).
        if ( ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
            wp_send_json_error( [ 'message' => __( 'Action Scheduler is unavailable.', 'brikpanel' ) ], 500 );
        }

        // is_scan_active() above only knows about a scan the WORKER has already
        // started — it reads the progress option, which handle_scan() writes.
        // Between enqueue and pickup it is false, and that window is exactly
        // where the 5-second progress poll lives, so repeated clicks stacked
        // rows. Ask Action Scheduler directly: args-aware, PENDING + RUNNING.
        if ( Brikpanel_Cron::is_scheduled( Brikpanel_BrikControl_Runner::HOOK_SCAN, Brikpanel_BrikControl_Runner::SCAN_ARGS_MANUAL ) ) {
            wp_send_json_success( [
                'queued'   => false,
                'message'  => __( 'A scan is already in the queue.', 'brikpanel' ),
                'progress' => Brikpanel_BrikControl_Storage::get_progress(),
            ] );
        }

        $action_id = Brikpanel_BrikControl_Runner::trigger_manual_scan();

        wp_send_json_success( [
            'queued'    => true,
            'action_id' => $action_id ?: 0,
            'message'   => $action_id
                ? __( 'Scan queued.', 'brikpanel' )
                : __( 'A scan is already in the queue.', 'brikpanel' ),
        ] );
    }

    public function ajax_progress() {
        $this->verify_ajax();
        if ( class_exists( 'Brikpanel_BrikControl_Runner' ) ) {
            Brikpanel_BrikControl_Runner::maybe_resume();
        }
        $progress = Brikpanel_BrikControl_Storage::get_progress();
        $bundle   = Brikpanel_BrikControl_Storage::get_results();
        wp_send_json_success( [
            'progress'  => $progress,
            'texts'     => self::progress_texts( $progress ),
            'is_active' => Brikpanel_BrikControl_Storage::is_scan_active(),
            // The page only redraws its status counts while a scan runs.
            'bundle'    => [
                'last_scan'      => (int) $bundle['last_scan'],
                'status_summary' => $bundle['status_summary'],
            ],
        ] );
    }

    public function ajax_dismiss() {
        $this->verify_ajax();

        $key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
        if ( $key === '' ) {
            wp_send_json_error( [ 'message' => __( 'Missing dismiss target.', 'brikpanel' ) ], 400 );
        }

        // The scope is computed here, never accepted from the request: the
        // client must not be able to widen its own dismissal and mute checks
        // the merchant was never shown.
        Brikpanel_BrikControl_Storage::dismiss( $key, Brikpanel_BrikControl_Storage::critical_check_ids() );
        wp_send_json_success( [ 'dismissed' => $key ] );
    }

    /**
     * AJAX: run a check's repair action.
     *
     * Checks opt in by overriding supports_fix() / run_fix(); everything is
     * probed with method_exists() so a check written against the older abstract
     * contract can never fatal here.
     *
     * @return void
     */
    public function ajax_fix() {
        $this->verify_ajax();

        $check_id = isset( $_POST['check_id'] ) ? sanitize_key( wp_unslash( $_POST['check_id'] ) ) : '';
        if ( $check_id === '' ) {
            wp_send_json_error( [ 'message' => __( 'Missing check id.', 'brikpanel' ) ], 400 );
        }

        // Resolved through the registry, so the posted value never reaches a
        // query — an unknown id simply has no check.
        $check = Brikpanel_BrikControl_Registry::get( $check_id );
        if ( ! $check || ! method_exists( $check, 'supports_fix' ) || ! $check->supports_fix() ) {
            wp_send_json_error( [ 'message' => __( 'This check has no automatic fix.', 'brikpanel' ) ], 400 );
        }

        // Destructive endpoint: one run at a time per check, so a double click
        // or a wedged tab cannot stack concurrent delete loops.
        $lock = 'brikpanel_bc_fix_' . $check_id;
        if ( get_transient( $lock ) ) {
            wp_send_json_error( [ 'message' => __( 'A cleanup is already running for this check.', 'brikpanel' ) ], 409 );
        }
        set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );

        try {
            $outcome = $check->run_fix();
        } catch ( \Throwable $e ) {
            delete_transient( $lock );
            wp_send_json_error( [ 'message' => __( 'The cleanup could not be completed.', 'brikpanel' ) ], 500 );
        }

        delete_transient( $lock );

        // Re-run the check so the card the page reloads into is truthful.
        // Batched checks cannot be re-run inline; they wait for the next scan.
        if ( ! $check->supports_batching() ) {
            Brikpanel_BrikControl_Storage::save_check_result( $check_id, $check->run( [] ) );
        }
        $this->rerun_linked_checks( $check_id );

        $outcome = is_array( $outcome ) ? $outcome : [];
        $parts   = [ method_exists( $check, 'bc_fix_done' ) ? $check->bc_fix_done( $outcome ) : '' ];
        if ( ! empty( $outcome['has_more'] ) ) {
            $parts[] = __( 'More rows remain. Run the cleanup again.', 'brikpanel' );
        }
        // A check can explain a partial or refused run (restore point full,
        // another operation running), already in the viewer's language.
        $parts[] = (string) ( $outcome['message'] ?? '' );

        wp_send_json_success( [
            'check_id' => $check_id,
            'removed'  => (int) ( $outcome['removed'] ?? 0 ),
            'has_more' => ! empty( $outcome['has_more'] ),
            'message'  => trim( implode( ' ', array_filter( $parts, 'strlen' ) ) ),
            // Something beyond the count to read: the page waits longer.
            'note'     => ! empty( $outcome['has_more'] ) || '' !== (string) ( $outcome['message'] ?? '' ),
        ] );
    }

    /**
     * Put a cleanup back the way it was.
     *
     * Separate endpoint rather than a flag on ajax_fix() because the two have
     * opposite risk profiles and must not share a lock: undo is the escape
     * hatch, and it has to stay reachable even while a fix is mid-flight on
     * another tab.
     *
     * Checks opt in by defining run_undo(); everything is probed with
     * method_exists() so a check written against the older contract cannot
     * fatal here.
     *
     * @since 3.3.1
     *
     * @return void
     */
    public function ajax_undo() {
        $this->verify_ajax();

        $check_id = isset( $_POST['check_id'] ) ? sanitize_key( wp_unslash( $_POST['check_id'] ) ) : '';
        if ( $check_id === '' ) {
            wp_send_json_error( [ 'message' => __( 'Missing check id.', 'brikpanel' ) ], 400 );
        }

        $check = Brikpanel_BrikControl_Registry::get( $check_id );
        if ( ! $check || ! method_exists( $check, 'run_undo' ) ) {
            wp_send_json_error( [ 'message' => __( 'This check has nothing to undo.', 'brikpanel' ) ], 400 );
        }

        $lock = 'brikpanel_bc_undo_' . $check_id;
        if ( get_transient( $lock ) ) {
            wp_send_json_error( [ 'message' => __( 'An undo is already running for this check.', 'brikpanel' ) ], 409 );
        }
        set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );

        try {
            $outcome = $check->run_undo();
        } catch ( \Throwable $e ) {
            delete_transient( $lock );
            wp_send_json_error( [ 'message' => __( 'The undo could not be completed.', 'brikpanel' ) ], 500 );
        }

        delete_transient( $lock );

        if ( ! $check->supports_batching() ) {
            Brikpanel_BrikControl_Storage::save_check_result( $check_id, $check->run( [] ) );
        }
        $this->rerun_linked_checks( $check_id );

        $outcome  = is_array( $outcome ) ? $outcome : [];
        $restored = (int) ( $outcome['restored'] ?? 0 );
        $parts    = [ ( $restored > 0 && method_exists( $check, 'bc_undo_done' ) ) ? $check->bc_undo_done( $outcome ) : '' ];
        $parts[]  = (string) ( $outcome['message'] ?? '' );

        wp_send_json_success( [
            'check_id' => $check_id,
            'restored' => $restored,
            'message'  => trim( implode( ' ', array_filter( $parts, 'strlen' ) ) ),
            'note'     => '' !== (string) ( $outcome['message'] ?? '' ),
        ] );
    }

    /**
     * Re-run and save the checks a cleanup or undo also changed, so their
     * cards and the top bar shield are right on the next page load instead
     * of after the next scan.
     *
     * A failure here never fails the cleanup that already happened: the
     * linked card keeps its previous result until the next scan.
     *
     * @param string $check_id Check whose cleanup or undo just ran.
     * @return void
     */
    private function rerun_linked_checks( $check_id ) {
        foreach ( self::LINKED_CHECKS[ $check_id ] ?? [] as $linked_id ) {
            $linked = Brikpanel_BrikControl_Registry::get( $linked_id );
            if ( ! $linked || $linked->supports_batching() ) {
                continue;
            }
            try {
                Brikpanel_BrikControl_Storage::save_check_result( $linked_id, $linked->run( [] ) );
            } catch ( \Throwable $e ) {
                unset( $e );
            }
        }
    }

    /**
     * Progress line and percentage for a scan in flight, in the viewer's
     * language (the page's first render and every poll use the same text).
     *
     * @param array $progress Storage::get_progress().
     * @return array{label:string, pct:string}
     */
    public static function progress_texts( array $progress ) {
        $total  = (int) ( $progress['total'] ?? 0 );
        $cursor = (int) ( $progress['cursor'] ?? 0 );
        if ( $total <= 0 ) {
            return [
                'label' => __( 'Scanning your store…', 'brikpanel' ),
                'pct'   => '',
            ];
        }
        return [
            'label' => brikpanel_safe_sprintf(
                /* translators: 1: products scanned so far, 2: products to scan in total. */
                _n( 'Scanning… %1$s / %2$s product', 'Scanning… %1$s / %2$s products', $total, 'brikpanel' ),
                brikpanel_number( $cursor ),
                brikpanel_number( $total )
            ),
            'pct'   => brikpanel_percent( min( 100, ( $cursor / max( 1, $total ) ) * 100 ), 0 ),
        ];
    }

    /**
     * "Leave this crawler out of your analytics" advice shared by the checks
     * that find automated traffic. The two setting names are the settings
     * page's own labels, and the link lands on the first of them.
     *
     * @return array Recommendation.
     */
    public static function exclusion_recommendation() {
        $rec = [
            'text'     => brikpanel_safe_sprintf(
                /* translators: 1: settings field name "Excluded user agents", 2: settings field name "Excluded IP addresses". */
                __( 'If a known crawler is behind it, add it to "%1$s" or "%2$s" in the analytics settings, so it stops being counted at all.', 'brikpanel' ),
                __( 'Excluded user agents', 'brikpanel' ),
                __( 'Excluded IP addresses', 'brikpanel' )
            ),
            'priority' => 'medium',
        ];
        if ( function_exists( 'brikpanel_user_can_open_settings' ) && brikpanel_user_can_open_settings() ) {
            $rec['link'] = [
                'url'   => admin_url( 'admin.php?page=wc-settings&tab=brikpanel&section=analytics' ) . '#bp-jump=brikpanel_excluded_user_agents',
                'label' => __( 'Analytics settings', 'brikpanel' ),
            ];
        }
        return $rec;
    }

    private function verify_ajax() {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'security', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'brikpanel' ) ], 403 );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'brikpanel' ) ], 403 );
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * "5 min ago" / "2 h ago" / "3 d ago" — same vocab the topbar JS uses so
     * server-rendered + client-updated strings match.
     *
     * @param int $timestamp
     * @return string
     */
    private function relative_time( $timestamp ) {
        if ( $timestamp <= 0 ) {
            return __( 'Not scanned yet', 'brikpanel' );
        }
        $diff = max( 0, time() - $timestamp );
        if ( $diff < 60 ) {
            return __( 'just now', 'brikpanel' );
        }
        if ( $diff < HOUR_IN_SECONDS ) {
            $m = (int) floor( $diff / 60 );
            /* translators: %s: minutes */
            return sprintf( _n( '%s min ago', '%s min ago', $m, 'brikpanel' ), brikpanel_number( $m ) );
        }
        if ( $diff < DAY_IN_SECONDS ) {
            $h = (int) floor( $diff / HOUR_IN_SECONDS );
            /* translators: %s: hours */
            return sprintf( _n( '%s h ago', '%s h ago', $h, 'brikpanel' ), brikpanel_number( $h ) );
        }
        $d = (int) floor( $diff / DAY_IN_SECONDS );
        /* translators: %s: days */
        return sprintf( _n( '%s d ago', '%s d ago', $d, 'brikpanel' ), brikpanel_number( $d ) );
    }
}
