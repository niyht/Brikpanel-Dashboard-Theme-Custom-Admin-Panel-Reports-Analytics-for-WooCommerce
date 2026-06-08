<?php
/**
 * BrikPanel — BrikControl
 *
 * Public façade for the Store Health panel: owns the admin page, the AJAX
 * endpoints, and the topbar / dashboard render hooks. Storage / scan logic
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
     * "stale until rescan" state, then queues a fresh background scan.
     */
    public function on_plugin_change() {
        if ( class_exists( 'Brikpanel_BrikControl_Storage' ) ) {
            delete_transient( Brikpanel_BrikControl_Storage::TRANSIENT_TOPBAR );
        }
        if ( class_exists( 'Brikpanel_BrikControl_Runner' ) ) {
            Brikpanel_BrikControl_Runner::trigger_manual_scan();
        }
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

    private function enqueue_page_assets() {
        wp_enqueue_style(
            self::SCRIPT_HANDLE,
            BRIKPANEL_URL . 'front-end/brikcontrol/assets/brikpanel-brikcontrol.css',
            [],
            BRIKPANEL_VERSION
        );

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            BRIKPANEL_URL . 'front-end/brikcontrol/assets/brikpanel-brikcontrol.js',
            [],
            BRIKPANEL_VERSION,
            true
        );

        wp_localize_script( self::SCRIPT_HANDLE, 'brikpanelBrikControl', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
            'page_url' => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
            'i18n'     => [
                'rescan'             => __( 'Rescan now', 'brikpanel' ),
                'rescanning'         => __( 'Scan queued — refreshing…', 'brikpanel' ),
                'rescan_failed'      => __( 'Could not start scan.', 'brikpanel' ),
                'never_scanned'      => __( 'Not scanned yet', 'brikpanel' ),
                'scan_in_progress'   => __( 'Scanning in background…', 'brikpanel' ),
                'just_now'           => __( 'just now', 'brikpanel' ),
                'scanning_progress'  => __( 'Scanning {cursor} / {total} products…', 'brikpanel' ),
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
        $on_dashboard   = ( $hook === 'admin_page_brikpanel-dashboard' );

        // The CSS file is shared by the topbar AND the dashboard banner. The
        // banner can render on the BrikPanel dashboard even when the topbar
        // is disabled, so we must load the CSS in that case too — otherwise
        // the unstyled SVG falls back to the 300×150 browser default.
        if ( ! $topbar_enabled && ! $on_dashboard ) {
            return;
        }

        wp_enqueue_style(
            self::TOPBAR_HANDLE,
            BRIKPANEL_URL . 'front-end/brikcontrol/assets/brikpanel-brikcontrol.css',
            [],
            BRIKPANEL_VERSION
        );

        // Topbar JS is the topbar's own concern; the banner doesn't need it.
        if ( ! $topbar_enabled ) {
            return;
        }

        wp_enqueue_script(
            self::TOPBAR_HANDLE,
            BRIKPANEL_URL . 'front-end/brikcontrol/assets/brikpanel-brikcontrol-topbar.js',
            [],
            BRIKPANEL_VERSION,
            true
        );

        wp_localize_script( self::TOPBAR_HANDLE, 'brikpanelBrikControlTopbar', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
            'page_url' => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
            'refresh_interval' => 60000,
            'i18n'     => [
                'all_ok'         => __( 'All checks passing', 'brikpanel' ),
                'view_report'    => __( 'Open Store Health', 'brikpanel' ),
                'last_scan'      => __( 'Last scan', 'brikpanel' ),
                'never_scanned'  => __( 'Not scanned yet', 'brikpanel' ),
                'scan_running'   => __( 'Scanning…', 'brikpanel' ),
                'just_now'       => __( 'just now', 'brikpanel' ),
                'minutes_ago'    => __( '%s min ago', 'brikpanel' ),
                'hours_ago'      => __( '%s h ago', 'brikpanel' ),
                'days_ago'       => __( '%s d ago', 'brikpanel' ),
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

        $bundle    = Brikpanel_BrikControl_Storage::get_results();
        $progress  = Brikpanel_BrikControl_Storage::get_progress();
        $is_active = Brikpanel_BrikControl_Storage::is_scan_active();
        $registry  = Brikpanel_BrikControl_Registry::get_all();

        // Surface registered checks that have not yet produced a result so the
        // page never looks empty on first visit.
        foreach ( $registry as $check_id => $check ) {
            if ( ! isset( $bundle['checks'][ $check_id ] ) ) {
                $bundle['checks'][ $check_id ] = [
                    'id'              => $check_id,
                    'label'           => $check->get_label(),
                    'category'        => $check->get_category(),
                    'status'          => 'unknown',
                    'score'           => 0,
                    'summary'         => __( 'Not scanned yet — run a scan to see results.', 'brikpanel' ),
                    'message'         => '',
                    'recommendations' => [],
                    'metadata'        => [],
                    'scanned_at'      => 0,
                    'duration_ms'     => 0,
                ];
            }
        }

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
                number_format_i18n( $critical )
            );
        } elseif ( $warning > 0 ) {
            $state       = 'warning';
            $badge_text  = (string) $warning;
            $title_attr  = sprintf(
                /* translators: %s: warning count */
                _n( '%s store health warning', '%s store health warnings', $warning, 'brikpanel' ),
                number_format_i18n( $warning )
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
    // DASHBOARD BANNER (critical-only, dismissable)
    // =========================================================================

    /**
     * Render a dismissable banner at the top of the BrikPanel dashboard if —
     * and only if — there is at least one critical health check the current
     * user has not already dismissed within the last 7 days.
     *
     * Administrators only: shop_manager (and other roles granted
     * manage_woocommerce) can reach the dashboard, but the banner phrasing
     * is alarming and shop staff usually cannot action the underlying fix
     * (plugin installs, server config, etc.), so we restrict it to users
     * who hold manage_options.
     */
    public function render_dashboard_banner() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $bundle   = Brikpanel_BrikControl_Storage::get_results();
        $critical = (int) ( $bundle['status_summary']['critical'] ?? 0 );
        if ( $critical < 1 ) {
            return;
        }
        if ( Brikpanel_BrikControl_Storage::is_dismissed( 'dashboard_banner' ) ) {
            return;
        }

        $page_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        ?>
        <div class="brikpanel-bc-banner" data-bc-banner role="alert">
            <div class="brikpanel-bc-banner-icon" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <div class="brikpanel-bc-banner-text">
                <strong>
                    <?php
                    printf(
                        /* translators: %s: critical issue count */
                        esc_html( _n(
                            '%s critical store health issue detected',
                            '%s critical store health issues detected',
                            $critical,
                            'brikpanel'
                        ) ),
                        esc_html( number_format_i18n( $critical ) )
                    );
                    ?>
                </strong>
                <span><?php esc_html_e( 'Open the BrikControl report to see what to fix and which plugins can help.', 'brikpanel' ); ?></span>
            </div>
            <div class="brikpanel-bc-banner-actions">
                <a class="brikpanel-bc-banner-cta" href="<?php echo esc_url( $page_url ); ?>">
                    <?php esc_html_e( 'View report', 'brikpanel' ); ?>
                </a>
                <button type="button" class="brikpanel-bc-banner-dismiss" data-bc-dismiss aria-label="<?php esc_attr_e( 'Dismiss for 7 days', 'brikpanel' ); ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    public function ajax_data() {
        $this->verify_ajax();

        $bundle   = Brikpanel_BrikControl_Storage::get_results();
        $progress = Brikpanel_BrikControl_Storage::get_progress();
        $active   = Brikpanel_BrikControl_Storage::is_scan_active();

        // Make sure registered-but-not-yet-scanned checks appear so the topbar
        // never shows an "empty" dropdown after install.
        foreach ( Brikpanel_BrikControl_Registry::get_all() as $check_id => $check ) {
            if ( ! isset( $bundle['checks'][ $check_id ] ) ) {
                $bundle['checks'][ $check_id ] = [
                    'id'      => $check_id,
                    'label'   => $check->get_label(),
                    'status'  => 'unknown',
                    'summary' => __( 'Not scanned yet', 'brikpanel' ),
                ];
            }
        }

        wp_send_json_success( [
            'bundle'    => $bundle,
            'progress'  => $progress,
            'is_active' => $active,
            'last_scan_relative' => $bundle['last_scan'] > 0 ? $this->relative_time( (int) $bundle['last_scan'] ) : '',
        ] );
    }

    public function ajax_rescan() {
        $this->verify_ajax();

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
        wp_send_json_success( [
            'progress'  => Brikpanel_BrikControl_Storage::get_progress(),
            'is_active' => Brikpanel_BrikControl_Storage::is_scan_active(),
            'bundle'    => Brikpanel_BrikControl_Storage::get_results(),
        ] );
    }

    public function ajax_dismiss() {
        $this->verify_ajax();

        $key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
        if ( $key === '' ) {
            wp_send_json_error( [ 'message' => __( 'Missing dismiss target.', 'brikpanel' ) ], 400 );
        }
        Brikpanel_BrikControl_Storage::dismiss( $key );
        wp_send_json_success( [ 'dismissed' => $key ] );
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
            return sprintf( _n( '%s min ago', '%s min ago', $m, 'brikpanel' ), number_format_i18n( $m ) );
        }
        if ( $diff < DAY_IN_SECONDS ) {
            $h = (int) floor( $diff / HOUR_IN_SECONDS );
            /* translators: %s: hours */
            return sprintf( _n( '%s h ago', '%s h ago', $h, 'brikpanel' ), number_format_i18n( $h ) );
        }
        $d = (int) floor( $diff / DAY_IN_SECONDS );
        /* translators: %s: days */
        return sprintf( _n( '%s d ago', '%s d ago', $d, 'brikpanel' ), number_format_i18n( $d ) );
    }
}
