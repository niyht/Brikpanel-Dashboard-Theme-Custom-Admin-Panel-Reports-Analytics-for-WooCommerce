<?php
/**
 * BrikPanel - Custom Dashboard
 *
 * Replaces the default WordPress dashboard with a modern,
 * Shopify-inspired analytics dashboard for WooCommerce.
 *
 * @package BrikPanel
 * @since 1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'brikpanel_dash_format_count' ) ) {
    /**
     * Format an integer count using WooCommerce's configured thousand
     * separator (no decimals) so KPI counts line up visually with the
     * currency cards on the same dashboard row, independent of WP locale.
     *
     * @param int|float $value
     * @return string
     */
    function brikpanel_dash_format_count( $value ) {
        return number_format(
            (float) $value,
            0,
            wc_get_price_decimal_separator(),
            wc_get_price_thousand_separator()
        );
    }
}

class Brikpanel_Dashboard {

    private $is_hpos = null;

    // Whole-response cache TTL. The cache key carries
    // brikpanel_data_cache_ver(), so any order event invalidates every
    // cached payload via the shared bumper in brikpanel-helpers.php — no
    // duplicate hooks needed here.
    const CACHE_TTL = 120; // 2 min with object cache; 10 min without (helper x5)

    public function __construct() {
        if ( get_option( 'brikpanel_modern_dashboard', 'yes' ) !== 'yes' ) {
            return;
        }

        add_action( 'admin_menu', [ $this, 'register_page' ] );
        add_action( 'admin_init', [ $this, 'redirect_dashboard' ] );

        // Batch data endpoint
        add_action( 'wp_ajax_brikpanel_dashboard_data', [ $this, 'ajax_dashboard_data' ] );
        // Live visitors endpoint (separate for polling)
        add_action( 'wp_ajax_brikpanel_dashboard_live', [ $this, 'ajax_dashboard_live' ] );
        // CSV export of the current date-range report (streamed download).
        add_action( 'admin_post_brikpanel_dashboard_export', [ $this, 'handle_export' ] );
    }

    /**
     * Backwards-compatible bust API for callers that explicitly invalidate
     * the dashboard response (e.g. the nightly customer-analytics recompute).
     */
    public static function bust_dashboard_cache() {
        if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
            brikpanel_bust_data_caches();
        }
    }

    // =========================================================================
    // HPOS DETECTION (cached)
    // =========================================================================

    private function is_hpos() {
        if ( $this->is_hpos === null ) {
            $this->is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
        }
        return $this->is_hpos;
    }

    // =========================================================================
    // PAGE REGISTRATION & REDIRECT
    // =========================================================================

    public function register_page() {
        $hook = add_submenu_page(
            '',
            __( 'Dashboard', 'brikpanel' ),
            '',
            'manage_woocommerce',
            'brikpanel-dashboard',
            [ $this, 'render_page' ]
        );

        if ( $hook ) {
            add_action( 'load-' . $hook, function () {
                global $title;
                $title = __( 'Dashboard', 'brikpanel' );
            });
        }
    }

    public function redirect_dashboard() {
        global $pagenow;

        if ( $pagenow !== 'index.php' ) {
            return;
        }
        if ( wp_doing_ajax() ) {
            return;
        }
        // On multisite, /wp-admin/network/index.php and /wp-admin/user/index.php
        // also resolve to $pagenow === 'index.php'. Hijacking those would yank
        // super admins out of Network Admin and User Admin into a subsite
        // dashboard, breaking core navigation. Only hijack the per-site
        // Dashboard.
        if ( is_network_admin() || is_user_admin() ) {
            return;
        }
        // Only hijack a bare Dashboard visit. If any query args are present
        // (e.g. ?page=foo submenu pages, ?oauth2callback=1 / ?gatoscallback=1
        // from Google Site Kit, or any other plugin hooking into index.php),
        // let the original request flow through untouched.
        if ( ! empty( $_GET ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=brikpanel-dashboard' ) );
        exit;
    }

    // =========================================================================
    // RENDER PAGE
    // =========================================================================

    /**
     * Ordered list of dashboard section keys the customer can reorder via
     * the `brikpanel_dashboard_section_order` filter or the Settings UI.
     * Callables map each key to its renderer.
     */
    private function get_sections() {
        $sections = [
            'profit'          => [ $this, 'render_section_profit' ],
            'kpis'            => [ $this, 'render_section_kpis' ],
            'sales_live'      => [ $this, 'render_section_sales_live' ],
            'funnel_rates'    => [ $this, 'render_section_funnel_rates' ],
            'locations'       => [ $this, 'render_section_locations' ],
            'products_orders' => [ $this, 'render_section_products_orders' ],
            'views_cart'      => [ $this, 'render_section_views_cart' ],
            'devices'         => [ $this, 'render_section_devices' ],
            'customer_segments' => [ $this, 'render_section_customer_segments' ],
            'stock_returns'   => [ $this, 'render_section_stock_returns' ],
            'subscriptions'   => [ $this, 'render_section_subscriptions' ],
            'wp_widgets'      => [ $this, 'render_embedded_wp_widgets' ],
        ];
        if ( function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active() ) {
            // Insert the marketplace analytics section right after KPIs so the
            // marketplace breakdown sits next to the (now site-only) headline
            // numbers it's separating out.
            $reordered = [];
            foreach ( $sections as $key => $cb ) {
                $reordered[ $key ] = $cb;
                if ( 'kpis' === $key ) {
                    $reordered['marketplace_analytics'] = [ $this, 'render_section_marketplace_analytics' ];
                }
            }
            $sections = $reordered;
        }
        return $sections;
    }

    /**
     * Human-readable labels for each section key, used by the Settings UI to
     * populate the "Visible dashboard sections" multiselect. Order mirrors
     * get_sections() so admins see the cards in the same sequence as the
     * dashboard renders them. Marketplace label is only included when
     * BrikMarket is active so it doesn't show up as a phantom option.
     */
    public static function get_section_labels() {
        $labels = [
            'profit'            => __( 'Profit (Revenue, Cost of goods, Net profit)', 'brikpanel' ),
            'kpis'              => __( 'KPI cards (Sales, Orders, AOV, Visitors, Conversion)', 'brikpanel' ),
            'sales_live'        => __( 'Sales over time + Live visitors', 'brikpanel' ),
            'funnel_rates'      => __( 'Conversion funnel + Order rates', 'brikpanel' ),
            'locations'         => __( 'Order locations globe + Top countries/cities', 'brikpanel' ),
            'products_orders'   => __( 'Top products + Recent orders', 'brikpanel' ),
            'views_cart'        => __( 'Most viewed pages + Most added to cart', 'brikpanel' ),
            'devices'           => __( 'Visitors by device + Customer types', 'brikpanel' ),
            'customer_segments' => __( 'Customer segments (RFM)', 'brikpanel' ),
            'stock_returns'     => __( 'Low stock + Customer lifetime value', 'brikpanel' ),
            'subscriptions'     => __( 'Subscriptions', 'brikpanel' ),
            'wp_widgets'        => __( 'WordPress dashboard widgets', 'brikpanel' ),
        ];
        if ( function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active() ) {
            $reordered = [];
            foreach ( $labels as $k => $v ) {
                $reordered[ $k ] = $v;
                if ( 'kpis' === $k ) {
                    $reordered['marketplace_analytics'] = __( 'Marketplace analytics (BrikMarket)', 'brikpanel' );
                }
            }
            $labels = $reordered;
        }
        return $labels;
    }

    /**
     * Resolve the set of section keys the admin has chosen to display.
     *
     * Empty/missing means "show all" — that covers the default install (option
     * never written), a cleared multiselect (WC saves `[]`), and the legacy
     * `''` value some hosts persisted before WC normalised the type. An
     * explicit non-empty list is allowlisted against current sections so a
     * stale key for a removed section can never reach the renderer.
     */
    private function get_visible_sections( array $sections ) {
        $default = array_keys( $sections );
        $saved   = get_option( 'brikpanel_dashboard_visible_sections' );
        if ( ! is_array( $saved ) || empty( $saved ) ) {
            $visible = $default;
        } else {
            $visible = array_values( array_intersect( $saved, $default ) );
            if ( empty( $visible ) ) {
                $visible = $default;
            }

            // Newly introduced sections must default to VISIBLE — otherwise a
            // flagship feature shipped in an update (e.g. Profit) would stay
            // hidden forever on every install that ever touched these
            // settings, because it can't appear in a list saved before it
            // existed. The persisted section-order option records every
            // section key known at the last save (the save handler appends
            // all known keys), so any current key missing from it is brand
            // new — show it. Keys that ARE in the order list but absent from
            // the visible list were deliberately hidden and stay hidden.
            $order_raw = get_option( 'brikpanel_dashboard_section_order', '' );
            $known_at_save = [];
            if ( is_string( $order_raw ) && '' !== $order_raw ) {
                $decoded = json_decode( $order_raw, true );
                if ( is_array( $decoded ) ) {
                    $known_at_save = array_values( array_filter( $decoded, 'is_string' ) );
                }
            }
            if ( ! empty( $known_at_save ) ) {
                foreach ( $default as $slug ) {
                    if ( ! in_array( $slug, $known_at_save, true )
                        && ! in_array( $slug, $visible, true ) ) {
                        $visible[] = $slug;
                    }
                }
            }
        }

        /**
         * Filter the visible dashboard sections.
         *
         * @param string[] $visible  Section keys that should render.
         * @param string[] $default  All known section keys for this install.
         */
        $visible = apply_filters( 'brikpanel_dashboard_visible_sections', $visible, $default );
        return is_array( $visible ) ? array_values( array_intersect( $visible, $default ) ) : $default;
    }

    /**
     * Resolve the final section order from (1) the Settings UI and (2) the
     * filter hook. Unknown keys are discarded; known keys missing from the
     * saved order are appended in their default position so newly added
     * sections remain visible after a plugin update.
     */
    private function resolve_section_order( array $sections ) {
        $default = array_keys( $sections );

        // Order comes from the Settings UI's reorderable picker (see
        // brikpanel-dashboard-section-order.php). When nothing is persisted
        // yet, that helper falls back to the legacy
        // `brikpanel_dashboard_wp_widgets_position` toggle so installs
        // upgrading from pre-reorder versions don't see wp_widgets jump.
        $order = function_exists( 'brikpanel_dashboard_get_section_order' )
            ? brikpanel_dashboard_get_section_order()
            : $default;

        $order = apply_filters( 'brikpanel_dashboard_section_order', $order, $sections );
        if ( ! is_array( $order ) ) {
            $order = $default;
        }

        // Allowlist + preserve discovery of new defaults.
        $clean = [];
        foreach ( $order as $k ) {
            if ( isset( $sections[ $k ] ) && ! in_array( $k, $clean, true ) ) {
                $clean[] = $k;
            }
        }
        foreach ( $default as $k ) {
            if ( ! in_array( $k, $clean, true ) ) {
                $clean[] = $k;
            }
        }
        return $clean;
    }

    public function render_page() {
        $sections = $this->get_sections();
        $order    = $this->resolve_section_order( $sections );
        $visible  = array_flip( $this->get_visible_sections( $sections ) );
        ?>
        <div id="brikpanel-dashboard" class="brikpanel-dashboard">
            <?php wp_nonce_field( 'brikpanel_dashboard_nonce', 'security' ); ?>

            <?php
            // Critical-only, 7-day-dismissable Store Health banner. Renders
            // nothing when there are no critical findings or the user has
            // already dismissed it within the suppression window.
            if ( class_exists( 'Brikpanel_BrikControl' ) ) {
                Brikpanel_BrikControl::instance()->render_dashboard_banner();
            }
            ?>

            <!-- Header -->
            <div class="brikpanel-dash-header">
                <h1>
                    <?php esc_html_e( 'Dashboard', 'brikpanel' ); ?>
                    <?php if ( function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active() ) : ?>
                        <span class="brikpanel-dash-header-suffix"><?php esc_html_e( '— With Marketplace', 'brikpanel' ); ?></span>
                    <?php endif; ?>
                </h1>
                <div class="brikpanel-dash-filters">
                    <div class="brikpanel-dash-copy-wrap">
                        <button type="button" class="brikpanel-dash-copy-summary" id="brikpanel-copy-summary">
                            <span class="brikpanel-dash-copy-icon" aria-hidden="true">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                            </span>
                            <span class="brikpanel-dash-copy-label"><?php esc_html_e( 'Copy everything', 'brikpanel' ); ?></span>
                            <span class="brikpanel-dash-copy-progress" aria-hidden="true"><span></span></span>
                        </button>
                        <span class="brikpanel-dash-copy-help" tabindex="0" role="button"
                              aria-label="<?php esc_attr_e( 'What does “Copy everything” do?', 'brikpanel' ); ?>">
                            <svg class="brikpanel-dash-copy-help-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"></circle>
                                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                                <line x1="12" y1="17" x2="12.01" y2="17"></line>
                            </svg>
                            <span class="brikpanel-dash-copy-help-tip" role="tooltip">
                                <span class="brikpanel-dash-copy-help-title"><?php esc_html_e( 'Copy everything', 'brikpanel' ); ?></span>
                                <span class="brikpanel-dash-copy-help-body"><?php esc_html_e( 'Bundles your store’s key data — KPIs, top products, recent orders, customers and settings — into a single Markdown report and copies it to your clipboard. Paste it into ChatGPT, Claude or any AI tool to get instant analysis, insights and recommendations about your store.', 'brikpanel' ); ?></span>
                            </span>
                        </span>
                        <?php
                        // Ad Platforms quick-access CTA. Self-gates: only renders
                        // when the module is enabled. Label adapts to whether any
                        // platform is already connected.
                        if ( class_exists( 'Brikpanel_Ads_Tokens' )
                            && function_exists( 'brikpanel_ads_module_is_enabled' )
                            && brikpanel_ads_module_is_enabled() ) :
                            $bp_ads_connected = Brikpanel_Ads_Tokens::is_connected( 'google_ads' )
                                || Brikpanel_Ads_Tokens::is_connected( 'meta_ads' );
                            ?>
                            <a class="brikpanel-dash-ads-cta" href="<?php echo esc_url( admin_url( 'admin.php?page=brikpanel-ad-platforms' ) ); ?>">
                                <span class="brikpanel-dash-ads-cta-icon" aria-hidden="true">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l18-5v12L3 14v-3z"></path><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"></path></svg>
                                </span>
                                <span class="brikpanel-dash-ads-cta-label">
                                    <?php echo $bp_ads_connected
                                        ? esc_html__( 'Ad spend settings', 'brikpanel' )
                                        : esc_html__( 'Connect ad accounts', 'brikpanel' ); ?>
                                </span>
                                <span class="brikpanel-beta-badge"><?php esc_html_e( 'Beta', 'brikpanel' ); ?></span>
                            </a>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="brikpanel-dash-export" id="brikpanel-export-xlsx"
                            title="<?php esc_attr_e( 'Download the selected period as an Excel workbook (opens in Excel / Google Sheets)', 'brikpanel' ); ?>">
                        <span class="brikpanel-dash-export-icon" aria-hidden="true">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        </span>
                        <span class="brikpanel-dash-export-label"><?php esc_html_e( 'Export Excel', 'brikpanel' ); ?></span>
                    </button>
                    <div class="brikpanel-dash-range-wrap">
                        <div class="brikpanel-dash-presets">
                            <button class="brikpanel-dash-preset active" data-range="today"><?php esc_html_e( 'Today', 'brikpanel' ); ?></button>
                            <button class="brikpanel-dash-preset" data-range="yesterday"><?php esc_html_e( 'Yesterday', 'brikpanel' ); ?></button>
                            <button class="brikpanel-dash-preset" data-range="7days"><?php esc_html_e( 'Last 7 Days', 'brikpanel' ); ?></button>
                            <button class="brikpanel-dash-preset" data-range="30days"><?php esc_html_e( 'Last 30 Days', 'brikpanel' ); ?></button>
                            <button class="brikpanel-dash-preset" data-range="custom"><?php esc_html_e( 'Custom', 'brikpanel' ); ?></button>
                        </div>
                        <div class="brikpanel-dash-custom-range" style="display:none;">
                            <input type="text" id="brikpanel-dash-datepicker" placeholder="<?php esc_attr_e( 'Select dates', 'brikpanel' ); ?>" readonly>
                        </div>
                    </div>
                </div>
            </div>

            <div class="brikpanel-dash-period" id="brikpanel-dash-period" aria-live="polite">
                <span class="brikpanel-dash-period-icon" aria-hidden="true">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                </span>
                <span class="brikpanel-dash-period-text"><?php esc_html_e( 'Loading…', 'brikpanel' ); ?></span>
            </div>

            <?php
            foreach ( $order as $section_key ) {
                if ( ! isset( $visible[ $section_key ] ) ) {
                    continue;
                }
                if ( isset( $sections[ $section_key ] ) && is_callable( $sections[ $section_key ] ) ) {
                    call_user_func( $sections[ $section_key ] );
                }
            }
            ?>

        </div>
        <?php
    }

    // =========================================================================
    // DASHBOARD SECTIONS (reorderable)
    // =========================================================================

    public function render_section_kpis() {
        ?>
            <!-- Summary Cards -->
            <div class="brikpanel-dash-cards">
                <div class="brikpanel-dash-card" data-metric="total_sales">
                    <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Total Sales', 'brikpanel' ); ?></span>
                    <span class="brikpanel-dash-card-value" id="card-total-sales">--</span>
                    <span class="brikpanel-dash-card-delta" id="delta-total-sales"></span>
                </div>
                <div class="brikpanel-dash-card" data-metric="orders">
                    <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Orders', 'brikpanel' ); ?></span>
                    <span class="brikpanel-dash-card-value" id="card-orders">--</span>
                    <span class="brikpanel-dash-card-delta" id="delta-orders"></span>
                </div>
                <div class="brikpanel-dash-card" data-metric="aov">
                    <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Avg. Order Value', 'brikpanel' ); ?></span>
                    <span class="brikpanel-dash-card-value" id="card-aov">--</span>
                    <span class="brikpanel-dash-card-delta" id="delta-aov"></span>
                </div>
                <div class="brikpanel-dash-card" data-metric="visitors">
                    <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Visitors', 'brikpanel' ); ?></span>
                    <span class="brikpanel-dash-card-value" id="card-visitors">--</span>
                    <span class="brikpanel-dash-card-delta" id="delta-visitors"></span>
                </div>
                <div class="brikpanel-dash-card" data-metric="conversion">
                    <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Conversion Rate', 'brikpanel' ); ?></span>
                    <span class="brikpanel-dash-card-value" id="card-conversion">--</span>
                    <span class="brikpanel-dash-card-delta" id="delta-conversion"></span>
                </div>
            </div>
            <?php
            /**
             * Fires immediately after the headline KPI cards render. Used by
             * the Ad Platforms module to inject its Ad Spend / ROAS / Net
             * Profit cards in-place when at least one platform is connected.
             *
             * @since 3.0.0
             */
            do_action( 'brikpanel_dashboard_after_kpis' );
            ?>
        <?php
    }

    /**
     * Profit section — Revenue, Cost of goods, Expenses and Net profit for the
     * selected date range. Standalone: it does NOT require any ad platform to
     * be connected. Values fill in from the shared AJAX payload (data.profit).
     */
    public function render_section_profit() {
        ?>
            <!-- Profit -->
            <div class="brikpanel-dash-profit" id="brikpanel-profit-section">
                <div class="brikpanel-dash-cards brikpanel-dash-cards-profit" id="brikpanel-profit-cards">
                    <div class="brikpanel-dash-card" data-metric="profit_revenue">
                        <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Revenue', 'brikpanel' ); ?></span>
                        <span class="brikpanel-dash-card-value" id="card-profit-revenue">--</span>
                        <span class="brikpanel-dash-card-delta" id="delta-profit-revenue"></span>
                    </div>
                    <div class="brikpanel-dash-card" data-metric="profit_cogs">
                        <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Cost of Goods', 'brikpanel' ); ?></span>
                        <span class="brikpanel-dash-card-value" id="card-profit-cogs">--</span>
                        <span class="brikpanel-dash-card-delta brikpanel-dash-card-delta-static" id="delta-profit-cogs"></span>
                    </div>
                    <div class="brikpanel-dash-card" data-metric="profit_expenses" id="profit-expenses-card">
                        <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Expenses', 'brikpanel' ); ?></span>
                        <span class="brikpanel-dash-card-value" id="card-profit-expenses">--</span>
                        <span class="brikpanel-dash-card-delta brikpanel-dash-card-delta-static" id="delta-profit-expenses"></span>
                        <button type="button" class="brikpanel-dash-bd-toggle" id="profit-bd-toggle"
                                aria-expanded="false" aria-controls="profit-bd-collapse" hidden
                                title="<?php esc_attr_e( 'Show expense breakdown', 'brikpanel' ); ?>"
                                aria-label="<?php esc_attr_e( 'Show expense breakdown', 'brikpanel' ); ?>">
                            <svg class="brikpanel-dash-bd-chevron" width="14" height="14" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                 stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </button>
                        <div class="brikpanel-dash-bd-collapse" id="profit-bd-collapse">
                            <div class="brikpanel-dash-bd-inner">
                                <div class="brikpanel-dash-bd-list" id="profit-expenses-breakdown"></div>
                            </div>
                        </div>
                    </div>
                    <div class="brikpanel-dash-card" data-metric="profit_net">
                        <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Net Profit', 'brikpanel' ); ?></span>
                        <span class="brikpanel-dash-card-value" id="card-profit-net">--</span>
                        <span class="brikpanel-dash-card-delta" id="delta-profit-net"></span>
                    </div>
                </div>
            </div>
        <?php
    }

    public function render_section_marketplace_analytics() {
        if ( ! function_exists( 'brikpanel_brikmarket_active' ) || ! brikpanel_brikmarket_active() ) {
            return;
        }
        ?>
            <!-- Marketplace Analytics (BrikMarket) -->
            <div class="brikpanel-dash-marketplace" id="brikpanel-marketplace-section" data-empty="0">
                <!-- Marketplace KPI cards -->
                <div class="brikpanel-dash-cards brikpanel-dash-mp-cards">
                    <div class="brikpanel-dash-card">
                        <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Marketplace Sales', 'brikpanel' ); ?></span>
                        <span class="brikpanel-dash-card-value" id="card-mp-sales">--</span>
                        <span class="brikpanel-dash-card-delta" id="delta-mp-sales"></span>
                    </div>
                    <div class="brikpanel-dash-card">
                        <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Marketplace Orders', 'brikpanel' ); ?></span>
                        <span class="brikpanel-dash-card-value" id="card-mp-orders">--</span>
                        <span class="brikpanel-dash-card-delta" id="delta-mp-orders"></span>
                    </div>
                    <div class="brikpanel-dash-card">
                        <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Avg. Marketplace Order', 'brikpanel' ); ?></span>
                        <span class="brikpanel-dash-card-value" id="card-mp-aov">--</span>
                        <span class="brikpanel-dash-card-delta" id="delta-mp-aov"></span>
                    </div>
                    <div class="brikpanel-dash-card">
                        <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Share of Total Revenue', 'brikpanel' ); ?></span>
                        <span class="brikpanel-dash-card-value" id="card-mp-share">--</span>
                        <span class="brikpanel-dash-card-delta brikpanel-dash-card-delta-static" id="delta-mp-share"></span>
                    </div>
                </div>

                <!-- Per-marketplace breakdown -->
                <div class="brikpanel-dash-row brikpanel-dash-row-1-1">
                    <div class="brikpanel-dash-panel">
                        <h2><?php esc_html_e( 'Revenue by Marketplace', 'brikpanel' ); ?></h2>
                        <div class="brikpanel-dash-mp-list" id="brikpanel-mp-list">
                            <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                        </div>
                    </div>
                    <div class="brikpanel-dash-panel">
                        <h2><?php esc_html_e( 'Marketplace Share', 'brikpanel' ); ?></h2>
                        <div class="brikpanel-dash-chart-wrap brikpanel-dash-chart-short">
                            <canvas id="brikpanel-mp-share-chart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Top categories + per-marketplace categories -->
                <div class="brikpanel-dash-row brikpanel-dash-row-1-1">
                    <div class="brikpanel-dash-panel">
                        <h2><?php esc_html_e( 'Top Categories from Marketplaces', 'brikpanel' ); ?></h2>
                        <div class="brikpanel-dash-table-wrap" id="brikpanel-mp-categories">
                            <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                        </div>
                    </div>
                    <div class="brikpanel-dash-panel">
                        <h2><?php esc_html_e( 'Top Marketplace Products', 'brikpanel' ); ?></h2>
                        <div class="brikpanel-dash-table-wrap" id="brikpanel-mp-products">
                            <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        <?php
    }

    public function render_section_sales_live() {
        ?>
            <!-- Row: Sales Chart + Live Visitors -->
            <div class="brikpanel-dash-row brikpanel-dash-row-2-1">
                <div class="brikpanel-dash-panel">
                    <h2><?php esc_html_e( 'Sales Over Time', 'brikpanel' ); ?></h2>
                    <div class="brikpanel-dash-chart-wrap">
                        <canvas id="brikpanel-sales-chart"></canvas>
                    </div>
                </div>
                <div class="brikpanel-dash-panel brikpanel-dash-live">
                    <div class="brikpanel-dash-live-header">
                        <h2><?php esc_html_e( 'Live Visitors', 'brikpanel' ); ?></h2>
                        <span class="brikpanel-dash-live-count" id="live-count">0</span>
                    </div>
                    <div class="brikpanel-dash-live-list" id="live-visitors-list">
                        <p class="brikpanel-dash-empty"><?php esc_html_e( 'No active visitors', 'brikpanel' ); ?></p>
                    </div>
                </div>
            </div>
        <?php
    }

    public function render_section_funnel_rates() {
        ?>
            <!-- Row: Conversion Funnel + Order Rates -->
            <div class="brikpanel-dash-row brikpanel-dash-row-1-1">
                <div class="brikpanel-dash-panel">
                    <h2><?php esc_html_e( 'Conversion Funnel', 'brikpanel' ); ?></h2>
                    <div class="brikpanel-dash-chart-wrap brikpanel-dash-chart-short">
                        <canvas id="brikpanel-funnel-chart"></canvas>
                    </div>
                </div>
                <div class="brikpanel-dash-panel">
                    <h2><?php esc_html_e( 'Order Rates', 'brikpanel' ); ?></h2>
                    <div class="brikpanel-dash-chart-wrap brikpanel-dash-chart-short">
                        <canvas id="brikpanel-rates-chart"></canvas>
                    </div>
                </div>
            </div>
        <?php
    }

    public function render_section_locations() {
        ?>
            <!-- Row: Order Locations Globe + Tables -->
            <div class="brikpanel-dash-row brikpanel-dash-row-2-1">
                <div class="brikpanel-dash-panel brikpanel-dash-globe-panel" id="globe-panel">
                    <div class="brikpanel-dash-globe-header">
                        <div class="brikpanel-dash-globe-title-group">
                            <h2 id="globe-panel-title"><?php esc_html_e( 'Order Locations', 'brikpanel' ); ?></h2>
                            <div class="brikpanel-loc-tabs" role="group" aria-label="<?php esc_attr_e( 'View mode', 'brikpanel' ); ?>">
                                <button class="brikpanel-loc-tab brikpanel-loc-tab--active" data-view="orders" type="button">
                                    <?php esc_html_e( 'Orders', 'brikpanel' ); ?>
                                </button>
                                <button class="brikpanel-loc-tab" data-view="customers" type="button">
                                    <?php esc_html_e( 'Customers', 'brikpanel' ); ?>
                                </button>
                            </div>
                        </div>
                        <button class="brikpanel-dash-globe-theme-btn" id="globe-theme-toggle" type="button" title="<?php esc_attr_e( 'Toggle theme', 'brikpanel' ); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                        </button>
                    </div>
                    <div class="brikpanel-dash-globe-wrap" id="globe-container">
                        <canvas id="brikpanel-globe"></canvas>
                    </div>
                </div>
                <div class="brikpanel-dash-panel brikpanel-dash-locations-panel">
                    <h2 id="loc-panel-countries-title"><?php esc_html_e( 'Top Countries', 'brikpanel' ); ?></h2>
                    <div class="brikpanel-dash-table-wrap" id="top-countries-table">
                        <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                    </div>
                    <h2 class="brikpanel-dash-locations-h2" id="loc-panel-cities-title"><?php esc_html_e( 'Top Cities', 'brikpanel' ); ?></h2>
                    <div class="brikpanel-dash-table-wrap" id="top-cities-table">
                        <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                    </div>
                </div>
            </div>
        <?php
    }

    public function render_section_products_orders() {
        ?>
            <!-- Row: Top Products + Recent Orders -->
            <div class="brikpanel-dash-row brikpanel-dash-row-1-1">
                <div class="brikpanel-dash-panel">
                    <h2><?php esc_html_e( 'Top Products', 'brikpanel' ); ?></h2>
                    <div class="brikpanel-dash-table-wrap" id="top-products-table">
                        <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                    </div>
                </div>
                <div class="brikpanel-dash-panel">
                    <h2><?php esc_html_e( 'Recent Orders', 'brikpanel' ); ?></h2>
                    <div class="brikpanel-dash-table-wrap" id="recent-orders-table">
                        <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                    </div>
                </div>
            </div>
        <?php
    }

    public function render_section_views_cart() {
        ?>
            <!-- Row: Most Viewed + Most Added to Cart -->
            <div class="brikpanel-dash-row brikpanel-dash-row-1-1">
                <div class="brikpanel-dash-panel">
                    <h2><?php esc_html_e( 'Most Viewed Pages', 'brikpanel' ); ?></h2>
                    <div class="brikpanel-dash-table-wrap" id="most-viewed-table">
                        <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                    </div>
                </div>
                <div class="brikpanel-dash-panel">
                    <h2><?php esc_html_e( 'Most Added to Cart', 'brikpanel' ); ?></h2>
                    <div class="brikpanel-dash-table-wrap" id="most-cart-table">
                        <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                    </div>
                </div>
            </div>
        <?php
    }

    public function render_section_devices() {
        ?>
        <div class="brikpanel-dash-row brikpanel-dash-row-1-1">
            <div class="brikpanel-dash-panel">
                <div class="brikpanel-dash-panel-head">
                    <h2 id="brikpanel-device-title"><?php esc_html_e( 'Visitors by Device', 'brikpanel' ); ?></h2>
                    <div class="brikpanel-loc-tabs" role="group" aria-label="<?php esc_attr_e( 'Device breakdown view', 'brikpanel' ); ?>">
                        <button class="brikpanel-loc-tab brikpanel-loc-tab--active brikpanel-device-tab" data-device-view="visitors" type="button">
                            <?php esc_html_e( 'Visitors', 'brikpanel' ); ?>
                        </button>
                        <button class="brikpanel-loc-tab brikpanel-device-tab" data-device-view="orders" type="button">
                            <?php esc_html_e( 'Orders', 'brikpanel' ); ?>
                        </button>
                    </div>
                </div>
                <div id="brikpanel-device-breakdown">
                    <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                </div>
            </div>
            <div class="brikpanel-dash-panel">
                <h2><?php esc_html_e( 'Customer Types', 'brikpanel' ); ?></h2>
                <div id="brikpanel-customer-types">
                    <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_section_customer_segments() {
        ?>
        <div class="brikpanel-dash-row" style="grid-template-columns:1fr;">
            <div class="brikpanel-dash-panel">
                <h2>
                    <?php esc_html_e( 'Customer Segments (RFM)', 'brikpanel' ); ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=brikpanel-customer-analytics' ) ); ?>" class="brikpanel-dash-panel-link" style="float:right;font-size:0.75rem;font-weight:550;text-decoration:none;color:#616161;">
                        <?php esc_html_e( 'View details →', 'brikpanel' ); ?>
                    </a>
                </h2>
                <div id="brikpanel-rfm-segments" style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;">
                    <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * New vs repeat customer split from WooCommerce analytics (wc_order_stats).
     * Uses returning_customer flag: 0 = new, 1 = returning.
     */
    private function get_customer_type_breakdown( string $start_gmt, string $end_gmt ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_order_stats';

        // Bail early if WC analytics table doesn't exist.
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ) { // phpcs:ignore
            return [ 'new' => 0, 'repeat' => 0 ];
        }

        // Use the same successful-status set as the rest of the dashboard KPIs.
        // The previous NOT-IN list silently included wc-trash and wc-change
        // (Subscriptions membership-change rows), inflating both counts.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN returning_customer = 0 THEN 1 ELSE 0 END), 0) AS new_count,
                COALESCE(SUM(CASE WHEN returning_customer = 1 THEN 1 ELSE 0 END), 0) AS repeat_count
            FROM {$table}
            WHERE date_created_gmt BETWEEN %s AND %s
            AND status IN ('wc-processing', 'wc-completed')",
            $start_gmt,
            $end_gmt
        ) );

        return [
            'new'    => $row ? (int) $row->new_count    : 0,
            'repeat' => $row ? (int) $row->repeat_count : 0,
        ];
    }

    /**
     * Aggregate device-type visitor counts from wp_brikpanel_visitors for a date range.
     */
    private function get_device_breakdown( string $start_local, string $end_local ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'brikpanel_visitors';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COALESCE(SUM(mobile_count), 0)  AS mobile,
                COALESCE(SUM(tablet_count), 0)  AS tablet,
                COALESCE(SUM(desktop_count), 0) AS desktop
            FROM {$table}
            WHERE date_column BETWEEN %s AND %s",
            $start_local,
            $end_local
        ) );

        if ( ! $row ) {
            return [ 'mobile' => 0, 'tablet' => 0, 'desktop' => 0 ];
        }

        return [
            'mobile'  => (int) $row->mobile,
            'tablet'  => (int) $row->tablet,
            'desktop' => (int) $row->desktop,
        ];
    }

    /**
     * Aggregate device-type counts from WooCommerce orders (UA-based) for the
     * given GMT range. Counts only successful orders (processing + completed)
     * to mirror the rest of the dashboard KPIs and the customer-type breakdown.
     *
     * The UA strings are bucketed in PHP using the same regex as the visitor
     * tracker (brikpanel_detect_device_type) so "Visitors by Device" and
     * "Orders by Device" classify identically.
     *
     * @param string $start_gmt MySQL datetime (UTC).
     * @param string $end_gmt   MySQL datetime (UTC).
     * @return array{mobile:int,tablet:int,desktop:int}
     */
    private function get_order_device_breakdown( string $start_gmt, string $end_gmt ): array {
        global $wpdb;

        $is_hpos    = $this->is_hpos();
        $exclusion  = brikpanel_admin_order_exclusion_sql( $is_hpos, $is_hpos ? 'id' : 'p.ID' );
        $mp_exclude = ( function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active() )
            ? brikpanel_marketplace_order_exclusion_sql( $is_hpos, $is_hpos ? 'id' : 'p.ID' )
            : [ 'sql' => '', 'args' => [] ];

        $statuses = [ 'wc-processing', 'wc-completed' ];
        $status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

        if ( $is_hpos ) {
            $sql = "SELECT user_agent
                    FROM {$wpdb->prefix}wc_orders
                    WHERE type = 'shop_order'
                      AND status IN ({$status_placeholders})
                      AND date_created_gmt BETWEEN %s AND %s
                      AND user_agent IS NOT NULL
                      AND user_agent <> ''"
                . $exclusion['sql']
                . $mp_exclude['sql'];
            $args = array_merge( $statuses, [ $start_gmt, $end_gmt ], $exclusion['args'], $mp_exclude['args'] );
        } else {
            $sql = "SELECT pm.meta_value AS user_agent
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm
                        ON pm.post_id = p.ID AND pm.meta_key = '_customer_user_agent'
                    WHERE p.post_type = 'shop_order'
                      AND p.post_status IN ({$status_placeholders})
                      AND p.post_date_gmt BETWEEN %s AND %s
                      AND pm.meta_value <> ''"
                . $exclusion['sql']
                . $mp_exclude['sql'];
            $args = array_merge( $statuses, [ $start_gmt, $end_gmt ], $exclusion['args'], $mp_exclude['args'] );
        }

        $rows = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore

        $counts = [ 'mobile' => 0, 'tablet' => 0, 'desktop' => 0 ];
        if ( empty( $rows ) ) {
            return $counts;
        }

        foreach ( $rows as $ua ) {
            $bucket = brikpanel_detect_device_type( $ua );
            $counts[ $bucket ]++;
        }

        return $counts;
    }

    public function render_section_stock_returns() {
        ?>
            <!-- Row: Low Stock + Customer Lifetime Value -->
            <div class="brikpanel-dash-row brikpanel-dash-row-1-1">
                <div class="brikpanel-dash-panel">
                    <h2><?php esc_html_e( 'Low Stock', 'brikpanel' ); ?></h2>
                    <div class="brikpanel-dash-table-wrap" id="low-stock-table">
                        <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                    </div>
                </div>
                <div class="brikpanel-dash-panel">
                    <h2>
                        <?php esc_html_e( 'Customer Lifetime Value', 'brikpanel' ); ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=brikpanel-customer-analytics' ) ); ?>" style="float:right;font-size:0.75rem;font-weight:550;text-decoration:none;color:#616161;">
                            <?php esc_html_e( 'View details →', 'brikpanel' ); ?>
                        </a>
                    </h2>
                    <div id="brikpanel-ltv-panel">
                        <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                    </div>
                </div>
            </div>
        <?php
    }

    /**
     * Returns up to $limit products/variations that WooCommerce has flagged as
     * "lowstock", sorted by remaining quantity ascending.
     */
    private function get_low_stock_products( int $limit = 12 ): array {
        global $wpdb;

        // WooCommerce never assigns a "lowstock" value to `_stock_status` —
        // valid values are instock / outofstock / onbackorder. Low stock is
        // computed by comparing the per-product `_stock` against the
        // per-product `_low_stock_amount` (falls back to the global threshold
        // `woocommerce_notify_low_stock_amount`). We do this directly in SQL
        // for performance: returning <= threshold AND > 0 (out-of-stock items
        // are surfaced separately).
        $global_threshold = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
        if ( $global_threshold < 1 ) {
            $global_threshold = 2;
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_type, pm_stock.meta_value AS stock,
                    pm_threshold.meta_value AS threshold
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_manage   ON p.ID = pm_manage.post_id   AND pm_manage.meta_key = '_manage_stock'
             INNER JOIN {$wpdb->postmeta} pm_stock    ON p.ID = pm_stock.post_id    AND pm_stock.meta_key = '_stock'
             LEFT  JOIN {$wpdb->postmeta} pm_threshold ON p.ID = pm_threshold.post_id AND pm_threshold.meta_key = '_low_stock_amount'
             WHERE p.post_type IN ('product','product_variation')
               AND p.post_status = 'publish'
               AND pm_manage.meta_value = 'yes'
               AND pm_stock.meta_value IS NOT NULL
               AND pm_stock.meta_value != ''
               AND CAST(pm_stock.meta_value AS SIGNED) > 0
               AND CAST(pm_stock.meta_value AS SIGNED) <= COALESCE(NULLIF(pm_threshold.meta_value,'') + 0, %d)
             ORDER BY CAST(pm_stock.meta_value AS SIGNED) ASC
             LIMIT %d",
            $global_threshold,
            $limit
        ) );

        $products = [];
        foreach ( $rows as $row ) {
            $product = wc_get_product( $row->ID );
            if ( ! $product ) {
                continue;
            }
            $name = ( $row->post_type === 'product_variation' )
                ? $product->get_formatted_name()
                : $product->get_name();

            // get_formatted_name() returns HTML for variations (e.g. trailing
            // <span class="description"></span>) — we render as plain text in
            // the dashboard table, so strip tags and decode entities.
            $name = html_entity_decode( wp_strip_all_tags( $name ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            // For variations the link should point at the parent product
            // editor (variations don't have their own edit screen).
            $edit_id  = $row->post_type === 'product_variation'
                ? (int) wp_get_post_parent_id( $row->ID )
                : (int) $row->ID;
            $edit_url = $edit_id ? admin_url( 'post.php?post=' . $edit_id . '&action=edit' ) : '';

            $products[] = [
                'id'       => (int) $row->ID,
                'name'     => $name,
                'stock'    => (int) $product->get_stock_quantity(),
                'sku'      => $product->get_sku(),
                'edit_url' => $edit_url,
            ];
        }

        return $products;
    }

    // =========================================================================
    // EMBEDDED WORDPRESS DASHBOARD WIDGETS
    // =========================================================================

    /**
     * Render user-selected WordPress dashboard widgets inside the BrikPanel
     * dashboard, styled as BrikPanel cards in a responsive 3-column grid.
     */
    public function render_embedded_wp_widgets() {
        $selected = (array) get_option( 'brikpanel_dashboard_wp_widgets', [] );
        if ( empty( $selected ) ) {
            return;
        }

        if ( ! function_exists( 'brikpanel_collect_dashboard_widgets' ) ) {
            return;
        }

        $widgets = brikpanel_collect_dashboard_widgets();
        // Preserve user-selected order.
        $chosen = [];
        foreach ( $selected as $widget_id ) {
            if ( isset( $widgets[ $widget_id ] ) ) {
                $chosen[ $widget_id ] = $widgets[ $widget_id ];
            }
        }
        if ( empty( $chosen ) ) {
            return;
        }
        ?>
        <div class="brikpanel-dash-section brikpanel-dash-wp-widgets-section">
            <h2 class="brikpanel-dash-section-title"><?php esc_html_e( 'WordPress widgets', 'brikpanel' ); ?></h2>
            <?php /* `#dashboard-widgets` + `.postbox` + `.inside` structure is
                    what wp-admin/js/dashboard.js and site-health.js expect. We
                    keep our own classes alongside so the BrikPanel card styles
                    still apply. */ ?>
            <div id="dashboard-widgets-wrap">
                <div id="dashboard-widgets" class="brikpanel-dash-wp-widgets-grid metabox-holder">
                    <?php foreach ( $chosen as $widget_id => $widget ) : ?>
                        <div id="<?php echo esc_attr( $widget_id ); ?>" class="postbox brikpanel-dash-panel brikpanel-dash-wp-widget" data-widget-id="<?php echo esc_attr( $widget_id ); ?>">
                            <div class="postbox-header">
                                <h2 class="hndle"><span><?php echo esc_html( $widget['title'] ); ?></span></h2>
                            </div>
                            <div class="inside brikpanel-dash-wp-widget-body">
                                <?php
                                if ( is_callable( $widget['callback'] ) ) {
                                    try {
                                        call_user_func( $widget['callback'], null, [
                                            'id'    => $widget_id,
                                            'args'  => $widget['args'],
                                        ] );
                                    } catch ( \Throwable $e ) {
                                        echo '<p class="brikpanel-dash-empty">' . esc_html__( 'Widget failed to load.', 'brikpanel' ) . '</p>';
                                    }
                                }
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
        // Community Events (WordPress News widget) relies on Underscore JS
        // templates that WP only prints on wp-admin/index.php. Emit them here
        // when the `dashboard_primary` widget is active so dashboard.js can
        // render the event list without throwing "Template not found".
        if ( isset( $chosen['dashboard_primary'] ) && function_exists( 'wp_print_community_events_templates' ) ) {
            wp_print_community_events_templates();
        }
    }

    // =========================================================================
    // SUBSCRIPTIONS WIDGET
    // =========================================================================

    public function render_section_subscriptions() {
        if ( ! class_exists( 'WC_Subscriptions' ) ) {
            return;
        }
        ?>
        <div class="brikpanel-dash-panel brikpanel-dash-subs-panel">
            <h2><?php esc_html_e( 'Subscriptions', 'brikpanel' ); ?></h2>
            <div id="brikpanel-subscriptions-wrap" class="brikpanel-subs-grid">
                <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Returns subscription status counts. Works for both HPOS and legacy storage.
     */
    private function get_subscription_stats(): array {
        if ( ! class_exists( 'WC_Subscriptions' ) ) {
            return [];
        }

        global $wpdb;

        $statuses = [
            'wc-active'         => __( 'Active',               'brikpanel' ),
            'wc-on-hold'        => __( 'On hold',              'brikpanel' ),
            'wc-cancelled'      => __( 'Cancelled',            'brikpanel' ),
            'wc-expired'        => __( 'Expired',              'brikpanel' ),
            'wc-pending'        => __( 'Pending payment',      'brikpanel' ),
            'wc-pending-cancel' => __( 'Pending cancellation', 'brikpanel' ),
        ];

        if ( $this->is_hpos() ) {
            $rows = $wpdb->get_results( // phpcs:ignore
                "SELECT status, COUNT(*) AS cnt FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_subscription' GROUP BY status"
            );
        } else {
            $rows = $wpdb->get_results( // phpcs:ignore
                "SELECT post_status AS status, COUNT(*) AS cnt FROM {$wpdb->posts} WHERE post_type = 'shop_subscription' GROUP BY post_status"
            );
        }

        $counts = [];
        foreach ( $rows as $row ) {
            $counts[ $row->status ] = (int) $row->cnt;
        }

        $result = [];
        foreach ( $statuses as $key => $label ) {
            $result[] = [
                'status' => $key,
                'label'  => $label,
                'count'  => $counts[ $key ] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Build the Profit payload block for a single period from the shared
     * profit helper (the same code the Google Sheets snapshot uses, so the
     * two never disagree). Revenue is passed in so it always matches the
     * headline Total Sales KPI exactly — the customer never sees two
     * different "revenue" numbers on the same screen.
     *
     * Expenses is the composite of ad spend + tax + manual operating
     * expenses (which already includes vendor/inventory PO auto-expenses);
     * the breakdown lets the card explain what it's made of.
     *
     * @return array
     */
    private function build_profit_block( $revenue, $start_gmt, $end_gmt, $start_local, $end_local ) {
        $s = brikpanel_profit_snapshot( $revenue, $start_gmt, $end_gmt, $start_local, $end_local );

        $labels = [
            'google_ads' => __( 'Google Ads', 'brikpanel' ),
            'meta_ads'   => __( 'Meta Ads', 'brikpanel' ),
            'tax'        => __( 'Tax', 'brikpanel' ),
            'inventory'  => __( 'Supplier / stock', 'brikpanel' ),
            'other'      => __( 'Other', 'brikpanel' ),
        ];
        $breakdown = [];
        foreach ( $s['breakdown'] as $key => $amount ) {
            if ( (float) $amount <= 0 || ! isset( $labels[ $key ] ) ) {
                continue; // hide empty / unlabelled components to keep the card clean
            }
            $breakdown[] = [
                'key'    => $key,
                'label'  => $labels[ $key ],
                'amount' => wc_price( (float) $amount ),
                'raw'    => (float) $amount,
            ];
        }

        return [
            'revenue'       => wc_price( $s['revenue_raw'] ),
            'revenue_raw'   => $s['revenue_raw'],
            'cogs'          => wc_price( $s['cogs_raw'] ),
            'cogs_raw'      => $s['cogs_raw'],
            'cogs_pct'      => $s['cogs_pct'],
            'has_cogs'      => $s['has_cogs'],
            'cogs_incomplete'    => $s['cogs_incomplete'],
            'cogs_missing_lines' => $s['cogs_missing_lines'],
            'cogs_coverage_pct'  => $s['cogs_coverage_pct'],
            'cogs_missing_products' => $s['cogs_missing_products'] ?? [],
            'expenses'      => wc_price( $s['expenses_total_raw'] ),
            'expenses_raw'  => $s['expenses_total_raw'],
            'expenses_pct'  => $s['expenses_pct'],
            'breakdown'     => $breakdown,
            'net'           => wc_price( $s['net_raw'] ),
            'net_raw'       => $s['net_raw'],
            'margin'        => $s['margin'],
        ];
    }

    // =========================================================================
    // AJAX: BATCH DASHBOARD DATA
    // =========================================================================

    public function ajax_dashboard_data() {
        if ( ! check_ajax_referer( 'brikpanel_dashboard_nonce', 'security', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce.' ] );
            wp_die();
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
            wp_die();
        }

        $range = isset( $_POST['range'] ) ? sanitize_key( $_POST['range'] ) : 'today';

        // Whole-response cache. The dashboard runs ~28 SQL queries per render
        // (KPIs + funnel + 4 chart sections + LTV + RFM + locations + …), so on
        // weak hosts the cold render can take 5-15s. With caching, only the
        // first hit pays that price; range toggles, navigation back-and-forth,
        // and other admins on the same store all serve from the transient.
        // Cache busts automatically on new orders / status changes (registered
        // in __construct), so freshly-placed orders show up immediately.
        $cache_ver = brikpanel_data_cache_ver();
        $exclude_mp_for_key = ( function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active() ) ? 1 : 0;
        $cache_key = 'bp_dash_' . $cache_ver . '_' . $range . '_mp' . $exclude_mp_for_key;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
        }

        $payload = $this->build_dashboard_payload( $range );

        $ttl = function_exists( 'brikpanel_cache_ttl' )
            ? brikpanel_cache_ttl( self::CACHE_TTL )
            : self::CACHE_TTL;
        set_transient( $cache_key, $payload, $ttl );

        wp_send_json_success( $payload );
    }

    /**
     * Build the full dashboard data payload for a date range.
     *
     * The single source of truth for *everything* the dashboard shows —
     * KPIs, funnel, profit, order rates, products, locations, devices,
     * customer segments, LTV, RFM, subscriptions, low stock, the lot.
     * Both the AJAX endpoint (cached) and the Excel export consume this so
     * the report can never drift from the screen, and anything added here
     * flows into the export automatically.
     *
     * @param string      $range        today|yesterday|7days|30days|custom
     * @param string|null $custom_start Y-m-d (custom range only)
     * @param string|null $custom_end   Y-m-d (custom range only)
     * @return array The filtered payload (same shape AJAX returns).
     */
    private function build_dashboard_payload( $range, $custom_start = null, $custom_end = null ) {
        // Calculate date ranges (local + GMT)
        $dates     = $this->calculate_dates( $range, $custom_start, $custom_end );
        $start_gmt = $dates['start_gmt'];
        $end_gmt   = $dates['end_gmt'];
        $start_local = $dates['start_local'];
        $end_local   = $dates['end_local'];

        // Previous period for delta comparison
        $prev       = $dates['prev'];
        $prev_start_gmt = $prev['start_gmt'];
        $prev_end_gmt   = $prev['end_gmt'];
        $prev_start_local = $prev['start_local'];
        $prev_end_local   = $prev['end_local'];

        // When BrikMarket is active, the storefront KPIs (sales, orders, AOV,
        // conversion) must NOT count marketplace-imported orders — those don't
        // come from on-site visitors and would distort the conversion rate
        // and per-visitor averages.
        $exclude_mp = function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active();

        // --- Current period data ---
        $total_sales   = brikpanel_get_total_revenue( $start_gmt, $end_gmt, $exclude_mp );
        $order_count   = brikpanel_get_order_count( $start_gmt, $end_gmt, $exclude_mp );
        $aov           = brikpanel_get_average_order_value( $start_gmt, $end_gmt, $exclude_mp );
        $visitor_count = brikpanel_get_visitor_count( $start_local, $end_local );
        // Cap at 100% — visitor tracking is JS-pixel-based and may miss historical
        // visits or include orders from sources without a tracked website visit
        // (admin-created, imported), producing ratios above 100%.
        $conversion    = $visitor_count > 0 ? min( 100, round( ( $order_count / $visitor_count ) * 100, 2 ) ) : 0;

        // Funnel data (uses local dates for brikpanel_visitors table)
        $product_views  = brikpanel_get_product_view_count( $start_local, $end_local );
        $add_to_cart    = brikpanel_get_add_to_cart_count( $start_local, $end_local );
        $checkout_count = brikpanel_get_checkout_count( $start_local, $end_local );

        // Order rates
        $order_rates = $this->get_order_rates( $start_gmt, $end_gmt, $exclude_mp );

        // Top products, most viewed, most cart
        $top_products = $this->get_top_products( $start_gmt, $end_gmt, $exclude_mp );
        $most_viewed  = $this->get_most_viewed( $start_local, $end_local );
        $most_cart    = $this->get_most_cart( $start_local, $end_local );

        // Sales over time
        $sales_over_time = $this->get_sales_over_time( $start_gmt, $end_gmt, $exclude_mp );

        // Recent orders
        $recent_orders = $this->get_recent_orders();

        // Order locations (for globe)
        $order_locations = $this->get_order_locations( $start_gmt, $end_gmt, $exclude_mp );

        // Device breakdown (uses local dates for brikpanel_visitors table)
        $devices = $this->get_device_breakdown( $start_local, $end_local );

        // Same-period device breakdown for orders (UA on wc_orders / postmeta).
        // Surfaced inside the same panel as a secondary view — see the
        // visitors/orders tab toggle in the JS.
        $order_devices = $this->get_order_device_breakdown( $start_gmt, $end_gmt );

        // New vs repeat customer breakdown (uses WC analytics table, UTC dates)
        $customer_types = $this->get_customer_type_breakdown( $start_gmt, $end_gmt );

        // Subscription status distribution (date-independent — always current)
        $subscription_stats = $this->get_subscription_stats();

        // Low stock (always current, date-independent)
        $low_stock = $this->get_low_stock_products();

        // Returns & refunds (date-dependent)
        $return_count = brikpanel_get_order_count_by_status( [ 'wc-return-draft', 'wc-refunded' ], $start_gmt, $end_gmt, $exclude_mp );
        $total_orders = brikpanel_get_total_orders_count( $start_gmt, $end_gmt, $exclude_mp );
        $return_rate  = $total_orders > 0 ? round( ( $return_count / $total_orders ) * 100, 1 ) : 0;
        $returns_data = [
            'count' => $return_count,
            'total' => $total_orders,
            'rate'  => $return_rate,
        ];

        // Marketplace analytics (BrikMarket only)
        $marketplace_analytics = $exclude_mp
            ? $this->get_marketplace_analytics( $start_gmt, $end_gmt, $prev_start_gmt, $prev_end_gmt, $total_sales )
            : null;

        // All-time customer LTV roll-up from precomputed metrics. Date-range
        // independent — represents the lifetime value across the whole
        // customer base, refreshed nightly by Action Scheduler.
        global $wpdb;
        $ltv_tbl = $wpdb->prefix . 'brikpanel_customer_metrics';
        $ltv_row = $wpdb->get_row( "SELECT
                COUNT(*) AS total_customers,
                COALESCE(AVG(total_spent), 0) AS avg_ltv,
                COALESCE(SUM(total_spent), 0) AS total_ltv,
                COALESCE(MAX(total_spent), 0) AS max_ltv,
                COUNT(CASE WHEN order_count > 1 THEN 1 END) AS repeat_customers
            FROM {$ltv_tbl}" ); // phpcs:ignore
        $ltv_total_customers = (int) ( $ltv_row->total_customers ?? 0 );
        $avg_ltv_raw = (float) ( $ltv_row->avg_ltv ?? 0 );
        $ltv_panel = [
            'total_customers'  => $ltv_total_customers,
            'avg_ltv'          => $avg_ltv_raw > 0 ? wc_price( $avg_ltv_raw ) : '—',
            'total_ltv'        => $ltv_row && $ltv_row->total_ltv > 0 ? wc_price( (float) $ltv_row->total_ltv ) : '—',
            'max_ltv'          => $ltv_row && $ltv_row->max_ltv > 0 ? wc_price( (float) $ltv_row->max_ltv ) : '—',
            'repeat_customers' => (int) ( $ltv_row->repeat_customers ?? 0 ),
            'repeat_rate'      => $ltv_total_customers > 0 ? round( ( (int) ( $ltv_row->repeat_customers ?? 0 ) ) / $ltv_total_customers * 100, 1 ) : 0,
        ];

        // RFM segment distribution (all-time) — drives the Customer Segments
        // donut card. Uses the canonical labels function so the order +
        // colors stay consistent with the Customer Analytics page.
        $rfm_distribution = [];
        if ( function_exists( 'brikpanel_ca_rfm_segment_labels' ) ) {
            $rfm_rows = $wpdb->get_results( "SELECT rfm_segment, COUNT(*) AS customers FROM {$ltv_tbl} WHERE rfm_segment IS NOT NULL GROUP BY rfm_segment" ); // phpcs:ignore
            $by_seg = [];
            foreach ( $rfm_rows as $rr ) { $by_seg[ $rr->rfm_segment ] = (int) $rr->customers; }
            $rfm_total = array_sum( $by_seg );
            foreach ( brikpanel_ca_rfm_segment_labels() as $seg_key => $meta ) {
                $count = $by_seg[ $seg_key ] ?? 0;
                if ( $count === 0 ) { continue; }
                $rfm_distribution[] = [
                    'key'       => $seg_key,
                    'label'     => $meta['label'],
                    'color'     => $meta['color'],
                    'customers' => $count,
                    'share'     => $rfm_total > 0 ? round( $count / $rfm_total * 100, 1 ) : 0,
                ];
            }
        }

        // --- Previous period data (for deltas) ---
        $prev_total_sales   = brikpanel_get_total_revenue( $prev_start_gmt, $prev_end_gmt, $exclude_mp );
        $prev_order_count   = brikpanel_get_order_count( $prev_start_gmt, $prev_end_gmt, $exclude_mp );
        $prev_aov           = brikpanel_get_average_order_value( $prev_start_gmt, $prev_end_gmt, $exclude_mp );
        $prev_visitor_count = brikpanel_get_visitor_count( $prev_start_local, $prev_end_local );
        $prev_conversion    = $prev_visitor_count > 0 ? round( ( $prev_order_count / $prev_visitor_count ) * 100, 2 ) : 0;

        // Profit: Revenue − Cost of goods − Expenses, for the current and the
        // previous comparison period. Standalone — never depends on any ad
        // platform being connected.
        $profit_curr = $this->build_profit_block( $total_sales, $start_gmt, $end_gmt, $start_local, $end_local );
        $profit_prev = $this->build_profit_block( $prev_total_sales, $prev_start_gmt, $prev_end_gmt, $prev_start_local, $prev_end_local );
        $profit_curr['delta_revenue'] = $this->calc_delta( $profit_curr['revenue_raw'], $profit_prev['revenue_raw'] );
        $profit_curr['delta_net']     = $this->calc_delta( $profit_curr['net_raw'], $profit_prev['net_raw'] );

        $deltas = [
            'sales'      => $this->calc_delta( $total_sales, $prev_total_sales ),
            'orders'     => $this->calc_delta( $order_count, $prev_order_count ),
            'aov'        => $this->calc_delta( $aov, $prev_aov ),
            'visitors'   => $this->calc_delta( $visitor_count, $prev_visitor_count ),
            'conversion' => $this->calc_delta( $conversion, $prev_conversion ),
        ];

        $payload = [
            'total_sales'      => wc_price( $total_sales ),
            'total_sales_raw'  => $total_sales,
            'order_count'      => $order_count,
            // Display strings formatted with WooCommerce's OWN separators so
            // the whole KPI row matches the currency cards (e.g. "10.001" /
            // "0,62") regardless of the WP locale — under this store WC uses
            // "." thousands and "," decimals while the site locale is en_US,
            // so number_format_i18n() alone would still mismatch. Raw values
            // are kept untouched for charts/add-ons.
            'order_count_display'     => brikpanel_dash_format_count( $order_count ),
            'aov'              => wc_price( $aov ),
            'aov_raw'          => $aov,
            'visitor_count'    => $visitor_count,
            'visitor_count_display'   => brikpanel_dash_format_count( $visitor_count ),
            'conversion_rate'  => $conversion,
            'conversion_rate_display' => number_format(
                (float) $conversion,
                2,
                wc_get_price_decimal_separator(),
                wc_get_price_thousand_separator()
            ),
            'funnel'           => [
                'visitors' => $visitor_count,
                'products' => $product_views,
                'cart'     => $add_to_cart,
                'checkout' => $checkout_count,
                'orders'   => $order_count,
            ],
            'order_rates'      => $order_rates,
            'top_products'     => $top_products,
            'most_viewed'      => $most_viewed,
            'most_cart'        => $most_cart,
            'sales_over_time'      => $sales_over_time,
            'recent_orders'    => $recent_orders,
            'order_locations'  => $order_locations,
            'devices'          => $devices,
            'order_devices'    => $order_devices,
            'customer_types'     => $customer_types,
            'subscription_stats' => $subscription_stats,
            'low_stock'          => $low_stock,
            'returns'          => $returns_data,
            'deltas'           => $deltas,
            'profit'           => $profit_curr,
            'marketplace'      => $marketplace_analytics,
            'ltv_panel'        => $ltv_panel,
            'rfm_distribution' => $rfm_distribution,
            'period'           => $dates['period'],
        ];

        /**
         * Filter the dashboard data payload before it's cached + returned.
         *
         * Used by the Ad Platforms module to attach ad_spend / roas / net_profit
         * figures alongside the headline KPIs. Receives the date-range bounds
         * so subscribers can run their own queries against the same window.
         *
         * @since 3.0.0
         *
         * @param array  $payload          The full response payload.
         * @param array  $date_window      [start_local => Y-m-d H:i:s, end_local => Y-m-d H:i:s]
         * @param string $range            today | this_week | last_7_days | ...
         * @param float  $total_sales      The KPI Total Sales value already computed for $range.
         */
        $payload = apply_filters(
            'brikpanel_dashboard_data',
            $payload,
            [ 'start_local' => $start_local, 'end_local' => $end_local ],
            $range,
            (float) $total_sales
        );

        return $payload;
    }

    // =========================================================================
    // AJAX: LIVE VISITORS
    // =========================================================================

    public function ajax_dashboard_live() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $visitors = get_transient( 'brikpanel_live_visitors' );
        if ( ! is_array( $visitors ) ) {
            $visitors = [];
        }

        if ( ! defined( 'BRIKPANEL_VISITOR_TIMEOUT' ) ) {
            define( 'BRIKPANEL_VISITOR_TIMEOUT', 75 );
        }

        $limit_time      = time() - BRIKPANEL_VISITOR_TIMEOUT;
        $active_visitors = [];

        foreach ( $visitors as $data ) {
            if ( isset( $data['last_active'] ) && $data['last_active'] >= $limit_time ) {
                $active_visitors[] = $data;
            }
        }

        wp_send_json_success( $active_visitors );
    }

    // =========================================================================
    // DATE CALCULATION
    // =========================================================================

    private function calculate_dates( $range, $custom_start = null, $custom_end = null ) {
        $now_ts = wp_date( 'U' );

        switch ( $range ) {
            case 'yesterday':
                $start_local = wp_date( 'Y-m-d 00:00:00', strtotime( '-1 day', $now_ts ) );
                $end_local   = wp_date( 'Y-m-d 23:59:59', strtotime( '-1 day', $now_ts ) );
                $days_span   = 1;
                break;

            case '7days':
                $start_local = wp_date( 'Y-m-d 00:00:00', strtotime( '-7 days', $now_ts ) );
                $end_local   = wp_date( 'Y-m-d 23:59:59' );
                $days_span   = 7;
                break;

            case '30days':
                $start_local = wp_date( 'Y-m-d 00:00:00', strtotime( '-30 days', $now_ts ) );
                $end_local   = wp_date( 'Y-m-d 23:59:59' );
                $days_span   = 30;
                break;

            case 'custom':
                // Explicit args win (export passes them via GET); otherwise
                // fall back to the AJAX POST body the dashboard JS sends.
                $start_str   = $custom_start !== null
                    ? sanitize_text_field( $custom_start )
                    : ( isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : wp_date( 'Y-m-d' ) );
                $end_str     = $custom_end !== null
                    ? sanitize_text_field( $custom_end )
                    : ( isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : wp_date( 'Y-m-d' ) );
                // Guard against an inverted range (end before start).
                if ( strtotime( $end_str ) < strtotime( $start_str ) ) {
                    $tmp = $start_str; $start_str = $end_str; $end_str = $tmp;
                }
                $start_local = $start_str . ' 00:00:00';
                $end_local   = $end_str . ' 23:59:59';
                $days_span   = max( 1, (int) ( ( strtotime( $end_str ) - strtotime( $start_str ) ) / DAY_IN_SECONDS ) + 1 );
                break;

            default: // today
                $start_local = wp_date( 'Y-m-d 00:00:00' );
                $end_local   = wp_date( 'Y-m-d 23:59:59' );
                $days_span   = 1;
                break;
        }

        $start_gmt = get_gmt_from_date( $start_local );
        $end_gmt   = get_gmt_from_date( $end_local );

        // Previous period (same span, immediately before)
        $prev_end_ts     = strtotime( $start_local ) - 1;
        $prev_start_ts   = $prev_end_ts - ( $days_span * DAY_IN_SECONDS ) + 1;
        $prev_start_local = gmdate( 'Y-m-d 00:00:00', $prev_start_ts );
        $prev_end_local   = gmdate( 'Y-m-d 23:59:59', $prev_end_ts );
        $prev_start_gmt   = get_gmt_from_date( $prev_start_local );
        $prev_end_gmt     = get_gmt_from_date( $prev_end_local );

        // For visitor table queries (DATE type column, local dates Y-m-d)
        $start_local_date = substr( $start_local, 0, 10 );
        $end_local_date   = substr( $end_local, 0, 10 );
        $prev_start_local_date = substr( $prev_start_local, 0, 10 );
        $prev_end_local_date   = substr( $prev_end_local, 0, 10 );

        return [
            'start_gmt'   => $start_gmt,
            'end_gmt'     => $end_gmt,
            'start_local' => $start_local_date,
            'end_local'   => $end_local_date,
            // Period metadata — drives the on-screen "Showing …" subtitle and
            // the CSV export header so both always state the exact window.
            'period'      => $this->build_period_meta( $range, $start_local_date, $end_local_date, $days_span ),
            'prev'        => [
                'start_gmt'   => $prev_start_gmt,
                'end_gmt'     => $prev_end_gmt,
                'start_local' => $prev_start_local_date,
                'end_local'   => $prev_end_local_date,
            ],
        ];
    }

    /**
     * Human-readable description of the active date window.
     *
     * Returns the preset label, the localised From/To dates (using the
     * store's own date_format) and the duration in days. Consumed by the
     * dashboard JS (subtitle under the range presets) and the CSV export
     * header so the customer always sees *which* dates a report covers and
     * *how long* a span it is — e.g. "Last 30 Days · May 18 – Jun 17, 2026
     * · 30 days".
     *
     * @param string $range      today|yesterday|7days|30days|custom
     * @param string $start_date Y-m-d (local)
     * @param string $end_date   Y-m-d (local)
     * @param int    $days       Duration in days (inclusive)
     * @return array{range:string,label:string,from:string,to:string,from_iso:string,to_iso:string,days:int,text:string}
     */
    private function build_period_meta( $range, $start_date, $end_date, $days ) {
        $labels = [
            'today'     => __( 'Today', 'brikpanel' ),
            'yesterday' => __( 'Yesterday', 'brikpanel' ),
            '7days'     => __( 'Last 7 Days', 'brikpanel' ),
            '30days'    => __( 'Last 30 Days', 'brikpanel' ),
            'custom'    => __( 'Custom range', 'brikpanel' ),
        ];
        $label   = $labels[ $range ] ?? $labels['custom'];
        $fmt     = get_option( 'date_format' ) ?: 'M j, Y';
        $from    = wp_date( $fmt, strtotime( $start_date . ' 00:00:00' ) );
        $to      = wp_date( $fmt, strtotime( $end_date . ' 00:00:00' ) );
        $days    = max( 1, (int) $days );

        if ( $from === $to ) {
            $range_str = $from;
        } else {
            $range_str = $from . ' – ' . $to;
        }
        /* translators: %d: number of days. */
        $days_str = sprintf( _n( '%d day', '%d days', $days, 'brikpanel' ), $days );

        return [
            'range'    => $range,
            'label'    => $label,
            'from'     => $from,
            'to'       => $to,
            'from_iso' => $start_date,
            'to_iso'   => $end_date,
            'days'     => $days,
            // Pre-composed one-liner so the JS doesn't re-implement locale
            // formatting: "Last 30 Days · May 18 – Jun 17, 2026 · 30 days".
            'text'     => $label . ' · ' . $range_str . ' · ' . $days_str,
        ];
    }

    // =========================================================================
    // DELTA CALCULATION
    // =========================================================================

    private function calc_delta( $current, $previous ) {
        if ( $previous == 0 && $current == 0 ) {
            return 0;
        }
        if ( $previous == 0 ) {
            // No baseline to grow from. A flat "+100%" reads like real
            // growth and understates a jump from 0 to anything; null lets
            // the UI label it "New" instead of inventing a percentage.
            return null;
        }
        return round( ( ( $current - $previous ) / $previous ) * 100, 1 );
    }

    // =========================================================================
    // ORDER RATES
    // =========================================================================

    private function get_order_rates( $start_gmt, $end_gmt, $exclude_marketplace = false ) {
        $total = brikpanel_get_total_orders_count( $start_gmt, $end_gmt, $exclude_marketplace );

        if ( $total === 0 ) {
            return [
                'successful' => 0,
                'failed'     => 0,
                'refunded'   => 0,
                'cancelled'  => 0,
                'total'      => 0,
            ];
        }

        $successful = brikpanel_get_successful_order_count( $start_gmt, $end_gmt, $exclude_marketplace );
        $failed     = brikpanel_get_order_count_by_status( [ 'wc-failed' ], $start_gmt, $end_gmt, $exclude_marketplace );
        // Returns + refunds combined — covers the BrikPanel custom 'wc-return-draft'
        // status alongside WooCommerce's native 'wc-refunded'. Mirrors the figure
        // that used to be surfaced in the dedicated Returns & Refunds panel.
        $refunded   = brikpanel_get_order_count_by_status( [ 'wc-refunded', 'wc-return-draft' ], $start_gmt, $end_gmt, $exclude_marketplace );
        $cancelled  = brikpanel_get_order_count_by_status( [ 'wc-cancelled' ], $start_gmt, $end_gmt, $exclude_marketplace );

        return [
            'successful' => round( ( $successful / $total ) * 100, 1 ),
            'failed'     => round( ( $failed / $total ) * 100, 1 ),
            'refunded'   => round( ( $refunded / $total ) * 100, 1 ),
            'cancelled'  => round( ( $cancelled / $total ) * 100, 1 ),
            'total'      => $total,
        ];
    }

    // =========================================================================
    // TOP PRODUCTS (by quantity sold)
    // =========================================================================

    private function get_top_products( $start_gmt, $end_gmt, $exclude_marketplace = false ) {
        global $wpdb;

        $include_statuses    = [ 'wc-processing', 'wc-completed' ];
        $status_placeholders = implode( ', ', array_fill( 0, count( $include_statuses ), '%s' ) );
        $query_args          = $include_statuses;

        // Exclude orders placed by admin users
        $is_hpos   = $this->is_hpos();
        $exclusion = brikpanel_admin_order_exclusion_sql( $is_hpos, 'p.ID' );
        $mp_excl   = $exclude_marketplace
            ? brikpanel_marketplace_order_exclusion_sql( $is_hpos, $is_hpos ? 'o.id' : 'p.ID' )
            : [ 'sql' => '', 'args' => [] ];

        if ( $is_hpos ) {
            $admin_sql = str_replace( 'customer_id', 'o.customer_id', $exclusion['sql'] );
            $query_args = array_merge( $query_args, $exclusion['args'], $mp_excl['args'], [ $start_gmt, $end_gmt ] );
            // type='shop_order' excludes shop_order_refund rows in the lookup table
            // (their negative qty would silently subtract from each parent's total).
            // product_id > 0 drops orphaned line items whose product was deleted.
            $query = $wpdb->prepare(
                "SELECT p.product_id, SUM(p.product_qty) AS total_sold
                 FROM {$wpdb->prefix}wc_order_product_lookup p
                 INNER JOIN {$wpdb->prefix}wc_orders o ON p.order_id = o.id
                 WHERE o.type = 'shop_order'
                 AND o.status IN ({$status_placeholders}){$admin_sql}{$mp_excl['sql']}
                 AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
                 AND p.product_id > 0
                 GROUP BY p.product_id ORDER BY total_sold DESC LIMIT 5",
                $query_args
            );
        } else {
            $query_args = array_merge( $query_args, $exclusion['args'], $mp_excl['args'], [ $start_gmt, $end_gmt ] );
            // Group by parent product. Joining itemmeta on _product_id alone (not also
            // _variation_id) prevents variable products from being double-counted —
            // every variation purchase rolls up to its parent, matching HPOS semantics.
            $query = $wpdb->prepare(
                "SELECT m2.meta_value AS product_id, SUM(m1.meta_value) AS total_sold
                 FROM {$wpdb->posts} AS p
                 INNER JOIN {$wpdb->prefix}woocommerce_order_items AS oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS m1 ON oi.order_item_id = m1.order_item_id AND m1.meta_key = '_qty'
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS m2 ON oi.order_item_id = m2.order_item_id AND m2.meta_key = '_product_id'
                 WHERE p.post_type = 'shop_order'
                 AND p.post_status IN ({$status_placeholders}){$exclusion['sql']}{$mp_excl['sql']}
                 AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s
                 AND m2.meta_value > 0
                 GROUP BY m2.meta_value ORDER BY total_sold DESC LIMIT 5",
                $query_args
            );
        }

        $results = $wpdb->get_results( $query );
        if ( empty( $results ) ) {
            return [];
        }

        $product_ids  = wp_list_pluck( $results, 'product_id' );
        $products     = wc_get_products( [ 'include' => $product_ids, 'limit' => -1 ] );
        $products_map = [];
        foreach ( $products as $p ) {
            $products_map[ $p->get_id() ] = $p;
        }

        $data = [];
        foreach ( $results as $row ) {
            $product = isset( $products_map[ $row->product_id ] ) ? $products_map[ $row->product_id ] : null;
            if ( $product ) {
                $permalink = $product->get_permalink();
                $data[] = [
                    'name' => $product->get_name(),
                    'qty'  => (int) $row->total_sold,
                    'id'   => (int) $row->product_id,
                    'url'  => $permalink ? $permalink : '',
                ];
            }
        }
        return $data;
    }

    // =========================================================================
    // MOST VIEWED PAGES
    // =========================================================================

    private function get_most_viewed( $start_local, $end_local ) {
        global $wpdb;
        $table = $wpdb->prefix . 'brikpanel_visited_pages';

        $start_dt = $start_local . ' 00:00:00';
        $end_dt   = $end_local . ' 23:59:59';

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT page_id, SUM(visit_count) AS total_views
             FROM {$table}
             WHERE date_column >= %s AND date_column <= %s
             GROUP BY page_id
             ORDER BY total_views DESC LIMIT 5",
            $start_dt,
            $end_dt
        ) );

        if ( empty( $results ) ) {
            return [];
        }

        $page_ids = wp_list_pluck( $results, 'page_id' );
        _prime_post_caches( $page_ids, false, false );

        $data = [];
        foreach ( $results as $row ) {
            $title = get_the_title( $row->page_id );
            if ( $title ) {
                $permalink = get_permalink( $row->page_id );
                $data[] = [
                    'title' => $title,
                    'views' => (int) $row->total_views,
                    'id'    => (int) $row->page_id,
                    'url'   => $permalink ? $permalink : '',
                ];
            }
        }
        return $data;
    }

    // =========================================================================
    // MOST ADDED TO CART
    // =========================================================================

    private function get_most_cart( $start_local, $end_local ) {
        global $wpdb;
        $table = $wpdb->prefix . 'brikpanel_cart_tracking';

        $start_dt = $start_local . ' 00:00:00';
        $end_dt   = $end_local . ' 23:59:59';

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT product_id, SUM(cart_count) AS total_count
             FROM {$table}
             WHERE date_column >= %s AND date_column <= %s
             GROUP BY product_id
             ORDER BY total_count DESC LIMIT 5",
            $start_dt,
            $end_dt
        ) );

        if ( empty( $results ) ) {
            return [];
        }

        $product_ids  = wp_list_pluck( $results, 'product_id' );
        $products     = wc_get_products( [ 'include' => $product_ids, 'limit' => -1 ] );
        $products_map = [];
        foreach ( $products as $p ) {
            $products_map[ $p->get_id() ] = $p;
        }

        $data = [];
        foreach ( $results as $row ) {
            $product = isset( $products_map[ $row->product_id ] ) ? $products_map[ $row->product_id ] : null;
            if ( $product ) {
                $permalink = $product->get_permalink();
                $data[] = [
                    'name'  => $product->get_name(),
                    'count' => (int) $row->total_count,
                    'id'    => (int) $row->product_id,
                    'url'   => $permalink ? $permalink : '',
                ];
            }
        }
        return $data;
    }

    // =========================================================================
    // SALES OVER TIME (NEW - daily revenue breakdown for line chart)
    // =========================================================================

    private function get_sales_over_time( $start_gmt, $end_gmt, $exclude_marketplace = false ) {
        global $wpdb;

        $include_statuses    = [ 'wc-processing', 'wc-completed' ];
        $status_placeholders = implode( ', ', array_fill( 0, count( $include_statuses ), '%s' ) );

        $is_hpos   = $this->is_hpos();
        $exclusion = brikpanel_admin_order_exclusion_sql( $is_hpos, 'p.ID' );
        $mp_excl   = $exclude_marketplace
            ? brikpanel_marketplace_order_exclusion_sql( $is_hpos, $is_hpos ? 'id' : 'p.ID' )
            : [ 'sql' => '', 'args' => [] ];

        if ( $is_hpos ) {
            $admin_sql  = $exclusion['sql'];
            $query_args = array_merge( $include_statuses, $exclusion['args'], $mp_excl['args'], [ $start_gmt, $end_gmt ] );
            $query = $wpdb->prepare(
                "SELECT DATE(date_created_gmt) AS order_date,
                        SUM(total_amount) AS revenue,
                        COUNT(id) AS orders
                 FROM {$wpdb->prefix}wc_orders
                 WHERE type = 'shop_order'
                 AND status IN ({$status_placeholders}){$admin_sql}{$mp_excl['sql']}
                 AND date_created_gmt >= %s AND date_created_gmt <= %s
                 GROUP BY DATE(date_created_gmt)
                 ORDER BY order_date ASC",
                $query_args
            );
        } else {
            $query_args = array_merge( $include_statuses, $exclusion['args'], $mp_excl['args'], [ $start_gmt, $end_gmt ] );
            $query = $wpdb->prepare(
                "SELECT DATE(p.post_date_gmt) AS order_date,
                        SUM(pm.meta_value) AS revenue,
                        COUNT(p.ID) AS orders
                 FROM {$wpdb->posts} AS p
                 LEFT JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id
                 WHERE p.post_type = 'shop_order'
                 AND pm.meta_key = '_order_total'
                 AND p.post_status IN ({$status_placeholders}){$exclusion['sql']}{$mp_excl['sql']}
                 AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s
                 GROUP BY DATE(p.post_date_gmt)
                 ORDER BY order_date ASC",
                $query_args
            );
        }

        $results = $wpdb->get_results( $query );
        $data    = [];
        foreach ( $results as $row ) {
            $data[] = [
                'date'    => $row->order_date,
                'revenue' => (float) $row->revenue,
                'orders'  => (int) $row->orders,
            ];
        }
        return $data;
    }

    // =========================================================================
    // ORDER LOCATIONS (countries + cities for globe)
    // =========================================================================

    private function get_order_locations( $start_gmt, $end_gmt, $exclude_marketplace = false ) {
        global $wpdb;

        $include_statuses    = [ 'wc-processing', 'wc-completed' ];
        $status_placeholders = implode( ', ', array_fill( 0, count( $include_statuses ), '%s' ) );

        $is_hpos   = $this->is_hpos();
        $exclusion = brikpanel_admin_order_exclusion_sql( $is_hpos, 'p.ID' );
        $mp_excl   = $exclude_marketplace
            ? brikpanel_marketplace_order_exclusion_sql( $is_hpos, $is_hpos ? 'o.id' : 'p.ID' )
            : [ 'sql' => '', 'args' => [] ];

        // Customer count formula: dedupe by customer_id for logged-in users, by lowercased
        // billing email for guests, and fall back to the order id when an anonymous guest
        // has no email at all. Raw COUNT(DISTINCT customer_id) collapsed every guest to a
        // single "customer 0", which understated countries with many guest checkouts.
        if ( $is_hpos ) {
            $admin_sql   = str_replace( 'customer_id', 'o.customer_id', $exclusion['sql'] );
            $query_args  = array_merge( $include_statuses, $exclusion['args'], $mp_excl['args'], [ $start_gmt, $end_gmt ] );

            $customer_count_expr = "COUNT(DISTINCT
                IF(o.customer_id > 0,
                    CONCAT('u-', o.customer_id),
                    IF(ba.email IS NOT NULL AND ba.email <> '',
                        CONCAT('e-', LOWER(ba.email)),
                        CONCAT('o-', o.id))))";

            // Countries: small dataset — fetch all, sort/slice on client per active metric.
            $country_query = $wpdb->prepare(
                "SELECT ba.country AS code,
                        COUNT(DISTINCT o.id) AS order_count,
                        {$customer_count_expr} AS customer_count,
                        COALESCE(SUM(o.total_amount), 0) AS total_sales
                 FROM {$wpdb->prefix}wc_orders o
                 LEFT JOIN {$wpdb->prefix}wc_order_addresses ba ON o.id = ba.order_id AND ba.address_type = 'billing'
                 WHERE o.type = 'shop_order'
                 AND o.status IN ({$status_placeholders}){$admin_sql}{$mp_excl['sql']}
                 AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
                 AND ba.country IS NOT NULL AND ba.country != ''
                 GROUP BY ba.country",
                $query_args
            );

            // Cities: large dataset — run two LIMIT 10 passes (orders + customers) so both views
            // get the correct top-N regardless of how the metrics rank cities.
            $city_base = $wpdb->prepare(
                "SELECT ba.city AS city, ba.country AS code,
                        COUNT(DISTINCT o.id) AS order_count,
                        {$customer_count_expr} AS customer_count,
                        SUM(ol.product_qty) AS total_quantity
                 FROM {$wpdb->prefix}wc_orders o
                 LEFT JOIN {$wpdb->prefix}wc_order_addresses ba ON o.id = ba.order_id AND ba.address_type = 'billing'
                 LEFT JOIN {$wpdb->prefix}wc_order_product_lookup ol ON o.id = ol.order_id
                 WHERE o.type = 'shop_order'
                 AND o.status IN ({$status_placeholders}){$admin_sql}{$mp_excl['sql']}
                 AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
                 AND ba.city IS NOT NULL AND ba.city != ''
                 GROUP BY ba.city, ba.country",
                $query_args
            );
        } else {
            $query_args = array_merge( $include_statuses, $exclusion['args'], $mp_excl['args'], [ $start_gmt, $end_gmt ] );

            $customer_count_expr = "COUNT(DISTINCT
                IF(CAST(COALESCE(pm_cust.meta_value, '0') AS UNSIGNED) > 0,
                    CONCAT('u-', pm_cust.meta_value),
                    IF(pm_email.meta_value IS NOT NULL AND pm_email.meta_value <> '',
                        CONCAT('e-', LOWER(pm_email.meta_value)),
                        CONCAT('o-', p.ID))))";

            $country_query = $wpdb->prepare(
                "SELECT pm.meta_value AS code,
                        COUNT(DISTINCT p.ID) AS order_count,
                        {$customer_count_expr} AS customer_count,
                        CAST(COALESCE(SUM(CAST(oim.meta_value AS DECIMAL(10,2))), 0) AS DECIMAL(10,2)) AS total_sales
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_country'
                 LEFT JOIN {$wpdb->postmeta} pm_cust ON p.ID = pm_cust.post_id AND pm_cust.meta_key = '_customer_user'
                 LEFT JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_billing_email'
                 LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
                 LEFT JOIN {$wpdb->posts} oi ON p.ID = oi.post_parent AND oi.post_type = 'shop_order_item'
                 LEFT JOIN {$wpdb->postmeta} oim ON oi.ID = oim.post_id AND oim.meta_key = '_qty'
                 WHERE p.post_type = 'shop_order'
                 AND p.post_status IN ({$status_placeholders}){$exclusion['sql']}{$mp_excl['sql']}
                 AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s
                 AND pm.meta_value IS NOT NULL AND pm.meta_value != ''
                 GROUP BY pm.meta_value",
                $query_args
            );

            $city_base = $wpdb->prepare(
                "SELECT pm_city.meta_value AS city, pm_country.meta_value AS code,
                        COUNT(DISTINCT p.ID) AS order_count,
                        {$customer_count_expr} AS customer_count,
                        CAST(COALESCE(SUM(CAST(oim.meta_value AS UNSIGNED)), 0) AS UNSIGNED) AS total_quantity
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm_city ON p.ID = pm_city.post_id AND pm_city.meta_key = '_billing_city'
                 LEFT JOIN {$wpdb->postmeta} pm_country ON p.ID = pm_country.post_id AND pm_country.meta_key = '_billing_country'
                 LEFT JOIN {$wpdb->postmeta} pm_cust ON p.ID = pm_cust.post_id AND pm_cust.meta_key = '_customer_user'
                 LEFT JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_billing_email'
                 LEFT JOIN {$wpdb->posts} oi ON p.ID = oi.post_parent AND oi.post_type = 'shop_order_item'
                 LEFT JOIN {$wpdb->postmeta} oim ON oi.ID = oim.post_id AND oim.meta_key = '_qty'
                 WHERE p.post_type = 'shop_order'
                 AND p.post_status IN ({$status_placeholders}){$exclusion['sql']}{$mp_excl['sql']}
                 AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s
                 AND pm_city.meta_value IS NOT NULL AND pm_city.meta_value != ''
                 GROUP BY pm_city.meta_value, pm_country.meta_value",
                $query_args
            );
        }

        $country_results = $wpdb->get_results( $country_query );

        // Cities: dedupe-merge top 10 by orders with top 10 by customers.
        $cities_by_orders    = $wpdb->get_results( $city_base . ' ORDER BY order_count DESC LIMIT 10' );
        $cities_by_customers = $wpdb->get_results( $city_base . ' ORDER BY customer_count DESC, order_count DESC LIMIT 10' );

        $city_results = [];
        $seen_cities  = [];
        foreach ( array_merge( $cities_by_orders, $cities_by_customers ) as $row ) {
            $key = strtolower( (string) $row->city ) . '|' . (string) $row->code;
            if ( isset( $seen_cities[ $key ] ) ) {
                continue;
            }
            $seen_cities[ $key ] = true;
            $city_results[]      = $row;
        }

        $wc_countries = WC()->countries->get_countries();

        $countries = [];
        foreach ( $country_results as $row ) {
            $countries[] = [
                'code'      => $row->code,
                'name'      => isset( $wc_countries[ $row->code ] ) ? $wc_countries[ $row->code ] : $row->code,
                'count'     => (int) $row->order_count,
                'customers' => (int) ( $row->customer_count ?? 0 ),
                'total'     => html_entity_decode( wp_strip_all_tags( wc_price( (float) ( $row->total_sales ?? 0 ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
            ];
        }

        $cities = [];
        foreach ( $city_results as $row ) {
            $cities[] = [
                'name'      => $row->city,
                'country'   => $row->code,
                'count'     => (int) $row->order_count,
                'customers' => (int) ( $row->customer_count ?? 0 ),
                'quantity'  => (int) ( $row->total_quantity ?? 0 ),
            ];
        }

        return [
            'countries' => $countries,
            'cities'    => $cities,
        ];
    }

    // =========================================================================
    // MARKETPLACE ANALYTICS (BrikMarket)
    //
    // Computes per-marketplace sales/order/AOV breakdowns plus top categories
    // and products for orders imported via BrikMarket. Only called when the
    // BrikMarket plugin is active.
    //
    // The HPOS path uses wp_wc_orders + wp_wc_orders_meta (meta key
    // `_brksoft_marketplace`) and wp_wc_order_product_lookup. The legacy
    // path uses wp_posts + wp_postmeta and wp_woocommerce_order_items.
    // =========================================================================

    private function get_marketplace_analytics( $start_gmt, $end_gmt, $prev_start_gmt, $prev_end_gmt, $site_revenue ) {
        global $wpdb;

        $is_hpos             = $this->is_hpos();
        $include_statuses    = [ 'wc-processing', 'wc-completed' ];
        $status_placeholders = implode( ', ', array_fill( 0, count( $include_statuses ), '%s' ) );
        $meta_key            = brikpanel_marketplace_meta_key();

        // 1) Per-marketplace totals (current period).
        $rows_current = $this->mp_totals_by_marketplace( $start_gmt, $end_gmt, $is_hpos, $include_statuses, $status_placeholders, $meta_key );
        $rows_prev    = $this->mp_totals_by_marketplace( $prev_start_gmt, $prev_end_gmt, $is_hpos, $include_statuses, $status_placeholders, $meta_key );

        $prev_by_id = [];
        foreach ( $rows_prev as $r ) {
            $prev_by_id[ $r->marketplace_id ] = $r;
        }

        $total_orders  = 0;
        $total_revenue = 0.0;
        foreach ( $rows_current as $r ) {
            $total_orders  += (int) $r->orders;
            $total_revenue += (float) $r->revenue;
        }

        // 2) Per-marketplace top categories (top 3 each) — single query
        //    grouping by marketplace + category, then bucket in PHP. Saves N
        //    round trips compared with one query per marketplace.
        $cat_rows = $this->mp_categories_by_marketplace( $start_gmt, $end_gmt, $is_hpos, $include_statuses, $status_placeholders, $meta_key );

        $cat_by_mp     = [];
        $cat_overall   = [];
        foreach ( $cat_rows as $row ) {
            $mp_id   = (string) $row->marketplace_id;
            $cat_id  = (int) $row->term_id;
            $cat_nm  = (string) $row->term_name;
            $orders  = (int) $row->orders;
            $rev     = (float) $row->revenue;

            $cat_by_mp[ $mp_id ][] = [
                'id'      => $cat_id,
                'name'    => $cat_nm,
                'orders'  => $orders,
                'revenue' => $rev,
            ];

            if ( ! isset( $cat_overall[ $cat_id ] ) ) {
                $cat_overall[ $cat_id ] = [
                    'id'      => $cat_id,
                    'name'    => $cat_nm,
                    'orders'  => 0,
                    'revenue' => 0.0,
                ];
            }
            $cat_overall[ $cat_id ]['orders']  += $orders;
            $cat_overall[ $cat_id ]['revenue'] += $rev;
        }

        // 3) Marketplace top products (top 5 across all marketplaces).
        $top_products = $this->mp_top_products( $start_gmt, $end_gmt, $is_hpos, $include_statuses, $status_placeholders, $meta_key );

        // 4) Build per-marketplace payload sorted by revenue desc.
        $by_marketplace = [];
        foreach ( $rows_current as $r ) {
            $mp_id   = (string) $r->marketplace_id;
            $orders  = (int) $r->orders;
            $rev     = (float) $r->revenue;
            $aov     = $orders > 0 ? $rev / $orders : 0.0;
            $meta    = brikpanel_marketplace_meta( $mp_id );

            $prev_rev    = isset( $prev_by_id[ $mp_id ] ) ? (float) $prev_by_id[ $mp_id ]->revenue : 0.0;
            $prev_orders = isset( $prev_by_id[ $mp_id ] ) ? (int) $prev_by_id[ $mp_id ]->orders   : 0;

            $cats = $cat_by_mp[ $mp_id ] ?? [];
            usort( $cats, function ( $a, $b ) {
                if ( $a['revenue'] === $b['revenue'] ) {
                    return $b['orders'] <=> $a['orders'];
                }
                return $b['revenue'] <=> $a['revenue'];
            } );
            $cats = array_slice( $cats, 0, 3 );
            foreach ( $cats as &$c ) {
                $c['revenue_html'] = wc_price( $c['revenue'] );
            }
            unset( $c );

            $by_marketplace[] = [
                'id'             => $mp_id,
                'label'          => $meta['label'],
                'color'          => $meta['color'],
                'logo'           => $meta['logo'] ?? '',
                'orders'         => $orders,
                'revenue'        => $rev,
                'revenue_html'   => wc_price( $rev ),
                'aov'            => $aov,
                'aov_html'       => wc_price( $aov ),
                'orders_share'   => $total_orders > 0 ? round( $orders / $total_orders * 100, 1 ) : 0,
                'revenue_share'  => $total_revenue > 0 ? round( $rev / $total_revenue * 100, 1 ) : 0,
                'delta_revenue'  => $this->calc_delta( $rev, $prev_rev ),
                'delta_orders'   => $this->calc_delta( $orders, $prev_orders ),
                'top_categories' => array_values( $cats ),
            ];
        }
        usort( $by_marketplace, function ( $a, $b ) {
            return $b['revenue'] <=> $a['revenue'];
        } );

        // 5) Build top categories list (overall, top 8).
        usort( $cat_overall, function ( $a, $b ) {
            if ( $a['revenue'] === $b['revenue'] ) {
                return $b['orders'] <=> $a['orders'];
            }
            return $b['revenue'] <=> $a['revenue'];
        } );
        $cat_overall = array_slice( array_values( $cat_overall ), 0, 8 );
        foreach ( $cat_overall as &$c ) {
            $c['revenue_html'] = wc_price( $c['revenue'] );
            $c['share']        = $total_revenue > 0 ? round( $c['revenue'] / $total_revenue * 100, 1 ) : 0;
        }
        unset( $c );

        // 6) Aggregate totals (current + previous + share of total revenue).
        $prev_total_rev    = 0.0;
        $prev_total_orders = 0;
        foreach ( $rows_prev as $r ) {
            $prev_total_rev    += (float) $r->revenue;
            $prev_total_orders += (int) $r->orders;
        }
        $aov_current  = $total_orders > 0 ? $total_revenue / $total_orders : 0.0;
        $aov_previous = $prev_total_orders > 0 ? $prev_total_rev / $prev_total_orders : 0.0;

        // Combined revenue = site revenue (already excludes marketplace) + marketplace revenue.
        $combined_revenue      = (float) $site_revenue + $total_revenue;
        $combined_revenue_html = wc_price( $combined_revenue );
        $share_of_total_pct    = $combined_revenue > 0 ? round( $total_revenue / $combined_revenue * 100, 1 ) : 0;

        return [
            'totals' => [
                'orders'           => $total_orders,
                'revenue'          => $total_revenue,
                'revenue_html'     => wc_price( $total_revenue ),
                'aov'              => $aov_current,
                'aov_html'         => wc_price( $aov_current ),
                'share_total_pct'  => $share_of_total_pct,
                'site_revenue'          => (float) $site_revenue,
                'combined_revenue'      => $combined_revenue,
                'combined_revenue_html' => $combined_revenue_html,
            ],
            'deltas' => [
                'revenue' => $this->calc_delta( $total_revenue, $prev_total_rev ),
                'orders'  => $this->calc_delta( $total_orders, $prev_total_orders ),
                'aov'     => $this->calc_delta( $aov_current, $aov_previous ),
            ],
            'by_marketplace' => $by_marketplace,
            'categories'     => $cat_overall,
            'top_products'   => $top_products,
        ];
    }

    private function mp_totals_by_marketplace( $start_gmt, $end_gmt, $is_hpos, $include_statuses, $status_placeholders, $meta_key ) {
        global $wpdb;

        $exclusion = brikpanel_admin_order_exclusion_sql( $is_hpos, 'p.ID' );

        if ( $is_hpos ) {
            $admin_sql  = str_replace( 'customer_id', 'o.customer_id', $exclusion['sql'] );
            $args       = array_merge( [ $meta_key ], $include_statuses, $exclusion['args'], [ $start_gmt, $end_gmt ] );
            $sql        = $wpdb->prepare(
                "SELECT om.meta_value AS marketplace_id,
                        COUNT(o.id)          AS orders,
                        SUM(o.total_amount)  AS revenue
                 FROM {$wpdb->prefix}wc_orders o
                 INNER JOIN {$wpdb->prefix}wc_orders_meta om
                     ON o.id = om.order_id AND om.meta_key = %s
                 WHERE o.type = 'shop_order'
                 AND o.status IN ({$status_placeholders}){$admin_sql}
                 AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
                 GROUP BY om.meta_value",
                $args
            );
        } else {
            $args = array_merge( [ $meta_key ], $include_statuses, $exclusion['args'], [ $start_gmt, $end_gmt ] );
            $sql  = $wpdb->prepare(
                "SELECT pm.meta_value AS marketplace_id,
                        COUNT(p.ID)                            AS orders,
                        SUM(CAST(pm_total.meta_value AS DECIMAL(20,6))) AS revenue
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm
                     ON p.ID = pm.post_id AND pm.meta_key = %s
                 LEFT JOIN {$wpdb->postmeta} pm_total
                     ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
                 WHERE p.post_type = 'shop_order'
                 AND p.post_status IN ({$status_placeholders}){$exclusion['sql']}
                 AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s
                 GROUP BY pm.meta_value",
                $args
            );
        }

        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Build the order-header join + WHERE fragment shared by every marketplace
     * line-item aggregation query. Both HPOS and legacy code paths read line
     * items from the canonical `wc_order_items` / `wc_order_itemmeta` tables —
     * those exist in both modes. Only the order header table differs.
     *
     * Returns the SQL clauses + the ordered placeholder args, and the alias
     * used for the order header so callers can reference its meta join (`om`).
     *
     * @return array{from: string, where: string, order_alias: string, marketplace_alias: string, args: array}
     */
    private function mp_order_header_clause( $start_gmt, $end_gmt, $is_hpos, $include_statuses, $status_placeholders, $meta_key ) {
        global $wpdb;
        $exclusion = brikpanel_admin_order_exclusion_sql( $is_hpos, 'p.ID' );

        if ( $is_hpos ) {
            $admin_sql = str_replace( 'customer_id', 'o.customer_id', $exclusion['sql'] );
            $from = "{$wpdb->prefix}wc_orders o
                     INNER JOIN {$wpdb->prefix}wc_orders_meta om
                         ON o.id = om.order_id AND om.meta_key = %s
                     INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
                         ON o.id = oi.order_id AND oi.order_item_type = 'line_item'";
            $where = "o.type = 'shop_order'
                      AND o.status IN ({$status_placeholders}){$admin_sql}
                      AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s";
            $args  = array_merge( [ $meta_key ], $include_statuses, $exclusion['args'], [ $start_gmt, $end_gmt ] );
            return [
                'from'              => $from,
                'where'             => $where,
                'order_alias'       => 'o.id',
                'marketplace_alias' => 'om.meta_value',
                'args'              => $args,
            ];
        }

        $from = "{$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} om
                     ON p.ID = om.post_id AND om.meta_key = %s
                 INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
                     ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'";
        $where = "p.post_type = 'shop_order'
                  AND p.post_status IN ({$status_placeholders}){$exclusion['sql']}
                  AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s";
        $args  = array_merge( [ $meta_key ], $include_statuses, $exclusion['args'], [ $start_gmt, $end_gmt ] );
        return [
            'from'              => $from,
            'where'             => $where,
            'order_alias'       => 'p.ID',
            'marketplace_alias' => 'om.meta_value',
            'args'              => $args,
        ];
    }

    /**
     * Top categories per marketplace.
     *
     * Categories require a WC product to read taxonomy terms from. We try
     * two paths to resolve a `product_id` for each line item:
     *   1. The native `_product_id` itemmeta (set when brikmarket finds a
     *      matching WC product at import time).
     *   2. The `_marketplace_sku` itemmeta as a fallback — looked up against
     *      `wp_wc_product_meta_lookup.sku` so we still get categories for
     *      SKU-mapped marketplace items even if `_product_id` was never set.
     *
     * Items with neither a product_id nor a resolvable SKU are dropped from
     * the categories breakdown — there is no source of truth for their
     * taxonomy.
     */
    private function mp_categories_by_marketplace( $start_gmt, $end_gmt, $is_hpos, $include_statuses, $status_placeholders, $meta_key ) {
        global $wpdb;

        $clause = $this->mp_order_header_clause( $start_gmt, $end_gmt, $is_hpos, $include_statuses, $status_placeholders, $meta_key );

        // COALESCE: _product_id wins; otherwise resolve via SKU lookup.
        $sql = $wpdb->prepare(
            "SELECT {$clause['marketplace_alias']} AS marketplace_id,
                    tt.term_id                    AS term_id,
                    t.name                        AS term_name,
                    COUNT(DISTINCT {$clause['order_alias']}) AS orders,
                    COALESCE(SUM(CAST(im_total.meta_value AS DECIMAL(20,6))), 0) AS revenue
             FROM {$clause['from']}
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta im_pid
                 ON oi.order_item_id = im_pid.order_item_id AND im_pid.meta_key = '_product_id'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta im_sku
                 ON oi.order_item_id = im_sku.order_item_id AND im_sku.meta_key = '_marketplace_sku'
             LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup pml
                 ON im_sku.meta_value <> '' AND pml.sku = im_sku.meta_value
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta im_total
                 ON oi.order_item_id = im_total.order_item_id AND im_total.meta_key = '_line_total'
             INNER JOIN {$wpdb->term_relationships} tr
                 ON COALESCE(NULLIF(CAST(im_pid.meta_value AS UNSIGNED), 0), pml.product_id) = tr.object_id
             INNER JOIN {$wpdb->term_taxonomy} tt
                 ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_cat'
             INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             WHERE {$clause['where']}
             GROUP BY marketplace_id, tt.term_id",
            $clause['args']
        );

        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Top products per marketplace.
     *
     * Marketplace orders frequently arrive with line items that aren't mapped
     * to a WC product (`_product_id = 0`) — brikmarket stores the marketplace
     * product name on the line itself via `order_item_name` and adds the
     * `_marketplace_sku` itemmeta. To always show useful data, this query
     * groups by `order_item_name` (+ marketplace_id) instead of by product_id.
     *
     * If a product_id IS set, we surface it so the JS can deep-link to the
     * WC product page.
     */
    private function mp_top_products( $start_gmt, $end_gmt, $is_hpos, $include_statuses, $status_placeholders, $meta_key ) {
        global $wpdb;

        $clause = $this->mp_order_header_clause( $start_gmt, $end_gmt, $is_hpos, $include_statuses, $status_placeholders, $meta_key );

        $sql = $wpdb->prepare(
            "SELECT oi.order_item_name AS name,
                    {$clause['marketplace_alias']} AS marketplace_id,
                    SUM(CAST(im_qty.meta_value AS UNSIGNED))            AS qty,
                    SUM(CAST(im_total.meta_value AS DECIMAL(20,6)))     AS revenue,
                    MAX(CAST(im_pid.meta_value AS UNSIGNED))            AS product_id
             FROM {$clause['from']}
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta im_qty
                 ON oi.order_item_id = im_qty.order_item_id AND im_qty.meta_key = '_qty'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta im_total
                 ON oi.order_item_id = im_total.order_item_id AND im_total.meta_key = '_line_total'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta im_pid
                 ON oi.order_item_id = im_pid.order_item_id AND im_pid.meta_key = '_product_id'
             WHERE {$clause['where']}
             AND oi.order_item_name <> ''
             GROUP BY oi.order_item_name, marketplace_id
             ORDER BY qty DESC, revenue DESC
             LIMIT 5",
            $clause['args']
        );

        $rows = $wpdb->get_results( $sql );
        if ( empty( $rows ) ) {
            return [];
        }

        $out = [];
        foreach ( $rows as $row ) {
            $mp_id   = (string) $row->marketplace_id;
            $mp_meta = brikpanel_marketplace_meta( $mp_id );
            $out[]   = [
                'id'                 => (int) $row->product_id,
                'name'               => (string) $row->name,
                'qty'                => (int) $row->qty,
                'revenue'            => (float) $row->revenue,
                'revenue_html'       => wc_price( (float) $row->revenue ),
                'marketplace_id'     => $mp_id,
                'marketplace_label'  => $mp_meta['label'],
                'marketplace_color'  => $mp_meta['color'],
            ];
        }
        return $out;
    }

    // =========================================================================
    // RECENT ORDERS (last 5 orders)
    // =========================================================================

    private function get_recent_orders() {
        $admin_ids = brikpanel_get_admin_user_ids();

        // Push the admin-customer filter into the WC query so we don't load
        // 20 full WC_Order objects just to discard most of them. customer__not_in
        // is supported on both legacy and HPOS code paths.
        $args = [
            'limit'   => 5,
            'orderby' => 'date',
            'order'   => 'DESC',
            'type'    => 'shop_order',
        ];
        if ( ! empty( $admin_ids ) ) {
            $args['customer__not_in'] = array_map( 'intval', $admin_ids );
            $args['limit']            = 10; // small overshoot in case any admin slips past
        }

        $orders = wc_get_orders( $args );
        if ( count( $orders ) > 5 ) {
            $orders = array_slice( $orders, 0, 5 );
        }

        $data = [];
        foreach ( $orders as $order ) {
            $customer = ($order->get_billing_first_name() ?? '') . ' ' . ($order->get_billing_last_name() ?? '');
            $customer = trim( $customer );
            if ( empty( $customer ) ) {
                $customer = __( 'Guest', 'brikpanel' );
            }

            $source = $this->detect_order_source( $order );

            $data[] = [
                'id'       => $order->get_id(),
                'customer' => $customer,
                'status'   => $order->get_status(),
                'total'    => wc_price( $order->get_total() ),
                'date'     => wp_date( get_option( 'date_format' ), $order->get_date_created()->getTimestamp() ),
                'source'   => $source,
                'edit_url' => $order->get_edit_order_url(),
            ];
        }
        return $data;
    }

    // =========================================================================
    // ORDER SOURCE DETECTION
    // =========================================================================

    private function detect_order_source( $order ) {
        // BrikMarket marketplace meta keys (priority order)
        $marketplace_keys = [
            '_amz_order_id'                  => [ 'id' => 'amazon',      'label' => 'Amazon',      'color' => '#ff9900' ],
            '_brksoft_trendyol_order_number' => [ 'id' => 'trendyol',    'label' => 'Trendyol',    'color' => '#f27a1a' ],
            '_ty_order_number'               => [ 'id' => 'trendyol',    'label' => 'Trendyol',    'color' => '#f27a1a' ],
            '_hb_order_number'               => [ 'id' => 'hepsiburada', 'label' => 'Hepsiburada', 'color' => '#ff6000' ],
            '_n11_order_id'                  => [ 'id' => 'n11',         'label' => 'N11',         'color' => '#00b900' ],
            '_ozon_posting_number'           => [ 'id' => 'ozon',        'label' => 'Ozon',        'color' => '#005bff' ],
            '_brkoz_posting_number'          => [ 'id' => 'ozon',        'label' => 'Ozon',        'color' => '#005bff' ],
        ];

        // Check BrikMarket specific meta keys first
        foreach ( $marketplace_keys as $meta_key => $config ) {
            $value = $order->get_meta( $meta_key );
            if ( ! empty( $value ) ) {
                return [
                    'type'  => 'marketplace',
                    'id'    => $config['id'],
                    'label' => $config['label'],
                    'color' => $config['color'],
                ];
            }
        }

        // Check generic BrikMarket meta
        $mp_id = $order->get_meta( '_brksoft_marketplace' );
        if ( ! empty( $mp_id ) ) {
            $label = ucfirst( $mp_id );
            if ( class_exists( 'BrikMarket_Marketplace_Registry' ) ) {
                $marketplace = BrikMarket_Marketplace_Registry::get( $mp_id );
                if ( $marketplace ) {
                    $label = $marketplace->get_name();
                }
            }
            return [
                'type'  => 'marketplace',
                'id'    => $mp_id,
                'label' => $label,
                'color' => '#666666',
            ];
        }

        // WooCommerce order attribution (WC 8.4+)
        $source_type = $order->get_meta( '_wc_order_attribution_source_type' );
        $utm_source  = $order->get_meta( '_wc_order_attribution_utm_source' );

        if ( ! empty( $source_type ) ) {
            $label = '';
            $color = '#8a8a8a';

            switch ( $source_type ) {
                case 'organic':
                    $label = ! empty( $utm_source ) ? ucfirst( $utm_source ) : __( 'Organic', 'brikpanel' );
                    $color = '#1a8917';
                    break;
                case 'referral':
                    $label = ! empty( $utm_source ) ? ucfirst( $utm_source ) : __( 'Referral', 'brikpanel' );
                    $color = '#0073aa';
                    break;
                case 'utm':
                    $label = ! empty( $utm_source ) ? ucfirst( $utm_source ) : __( 'Campaign', 'brikpanel' );
                    $color = '#9b59b6';
                    break;
                case 'typein':
                    $label = __( 'Direct', 'brikpanel' );
                    $color = '#616161';
                    break;
                case 'admin':
                    $label = __( 'Admin', 'brikpanel' );
                    $color = '#303030';
                    break;
                default:
                    $label = ucfirst( str_replace( '_', ' ', $source_type ) );
                    break;
            }

            return [
                'type'  => 'attribution',
                'id'    => $source_type,
                'label' => $label,
                'color' => $color,
            ];
        }

        // No source detected
        return null;
    }

    // =========================================================================
    // EXCEL EXPORT  (admin-post.php?action=brikpanel_dashboard_export)
    // =========================================================================

    /**
     * Stream the current date-range report as a multi-tab .xlsx workbook.
     *
     * Built from the exact same payload the dashboard renders, so the file
     * carries *everything* on screen — one clean tab per section: Summary
     * (KPIs + profit + returns + LTV), Funnel, Order Status, Devices,
     * Customer Segments, Top Products, Most Viewed, Most Added to Cart,
     * Sales Over Time, Countries, Cities, Low Stock, Subscriptions (when
     * any), and Orders (every order in the window — the full record set).
     * A real workbook, not one stacked CSV, so each table is tidy, numbers
     * stay numeric, and it opens correctly in Excel / Google Sheets under
     * any locale.
     *
     * Security: requires `manage_woocommerce` + a valid nonce. Orders are
     * fetched in batches so large stores never exhaust memory.
     */
    public function handle_export() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to export dashboard data.', 'brikpanel' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'brikpanel_dashboard_export', 'brikpanel_export_nonce' );

        $allowed = [ 'today', 'yesterday', '7days', '30days', 'custom' ];
        $range   = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'today';
        if ( ! in_array( $range, $allowed, true ) ) {
            $range = 'today';
        }
        $custom_start = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : null;
        $custom_end   = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : null;
        // Reject anything that isn't a plain Y-m-d date so it can't reach the
        // date math as garbage.
        $valid_date = static function ( $d ) {
            return is_string( $d ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
        };
        if ( $range === 'custom' && ( ! $valid_date( $custom_start ) || ! $valid_date( $custom_end ) ) ) {
            $range        = 'today';
            $custom_start = null;
            $custom_end   = null;
        }

        // Single source of truth — the exact payload the dashboard renders,
        // so the workbook can never drift from the screen and every section
        // (devices, funnel, locations, LTV, RFM, segments, subscriptions,
        // low stock …) is included automatically.
        $d      = $this->build_dashboard_payload( $range, $custom_start, $custom_end );
        $period = $d['period'];

        require_once BRIKPANEL_PATH . 'includes/brikpanel-xlsx-writer.php';
        if ( ! class_exists( 'Brikpanel_XLSX_Writer' ) ) {
            wp_die( esc_html__( 'Export engine unavailable on this server.', 'brikpanel' ) );
        }

        $decimals = wc_get_price_decimals();
        // Real numbers (not strings) so the spreadsheet can sum/sort and the
        // viewer formats them per its own locale — no delimiter/decimal mess.
        $money = static function ( $v ) use ( $decimals ) {
            return round( (float) $v, $decimals );
        };
        // payload deltas are already %-vs-previous (number, 0, or null=New).
        $delta = static function ( $v ) {
            if ( $v === null ) {
                return __( 'New', 'brikpanel' );
            }
            return ( $v >= 0 ? '+' : '' ) . $v . '%';
        };
        // wc_price() values in the payload are HTML — flatten to plain text
        // ("$1,234.00") for a clean cell.
        $plain = static function ( $v ) {
            return trim( html_entity_decode( wp_strip_all_tags( (string) $v ), ENT_QUOTES, 'UTF-8' ) );
        };

        $B = Brikpanel_XLSX_Writer::S_BOLD;
        $H = Brikpanel_XLSX_Writer::S_HEADER;
        $T = Brikpanel_XLSX_Writer::S_TITLE;

        $currency = get_woocommerce_currency();
        // WooCommerce returns symbols as HTML entities (&#36;, &euro;,
        // &#8378; …). Decode to the real glyph ($, €, ₺) so the cell shows
        // the symbol, not the entity code.
        $cur_symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
        $cur_lbl    = sprintf( '%s (%s)', $currency, $cur_symbol );

        $profit  = $d['profit'];
        $funnel  = $d['funnel'];
        $rates   = $d['order_rates'];
        $returns = $d['returns'];
        $ltv     = $d['ltv_panel'];
        $fv      = (int) ( $funnel['visitors'] ?? 0 );
        $fpct    = static function ( $n ) use ( $fv ) {
            return $fv > 0 ? round( $n / $fv * 100, 1 ) . '%' : '—';
        };

        // ---------- Sheet 1: Summary (overview — mirrors the top of the dashboard) ----------
        $summary = [
            [ [ get_bloginfo( 'name' ) . ' — ' . __( 'BrikPanel Report', 'brikpanel' ), $T ] ],
            [ [ __( 'Website', 'brikpanel' ), $B ], home_url() ],
            [ [ __( 'Report period', 'brikpanel' ), $B ], $period['label'] ],
            [ [ __( 'From', 'brikpanel' ), $B ], $period['from'] ],
            [ [ __( 'To', 'brikpanel' ), $B ], $period['to'] ],
            /* translators: %d: number of days. */
            [ [ __( 'Duration', 'brikpanel' ), $B ], sprintf( _n( '%d day', '%d days', $period['days'], 'brikpanel' ), $period['days'] ) ],
            [ [ __( 'Generated', 'brikpanel' ), $B ], wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ],
            [ [ __( 'Currency', 'brikpanel' ), $B ], $cur_lbl ],
            [],
            [ [ __( 'Key Metrics', 'brikpanel' ), $T ] ],
            [
                [ __( 'Metric', 'brikpanel' ), $H ],
                [ __( 'Value', 'brikpanel' ), $H ],
                [ __( 'Change vs previous period', 'brikpanel' ), $H ],
            ],
            [ __( 'Total Sales', 'brikpanel' ), $money( $d['total_sales_raw'] ), $delta( $d['deltas']['sales'] ) ],
            [ __( 'Orders', 'brikpanel' ), (int) $d['order_count'], $delta( $d['deltas']['orders'] ) ],
            [ __( 'Avg. Order Value', 'brikpanel' ), $money( $d['aov_raw'] ), $delta( $d['deltas']['aov'] ) ],
            [ __( 'Visitors', 'brikpanel' ), (int) $d['visitor_count'], $delta( $d['deltas']['visitors'] ) ],
            [ __( 'Conversion Rate (%)', 'brikpanel' ), (float) $d['conversion_rate'], $delta( $d['deltas']['conversion'] ) ],
            [],
            [ [ __( 'Profit', 'brikpanel' ), $T ] ],
            [ [ __( 'Metric', 'brikpanel' ), $H ], [ sprintf( __( 'Amount (%s)', 'brikpanel' ), $currency ), $H ], [ __( 'Context', 'brikpanel' ), $H ] ],
            [ __( 'Revenue', 'brikpanel' ), $money( $profit['revenue_raw'] ), __( 'Same as Total Sales', 'brikpanel' ) ],
            /* translators: %s: percentage of revenue. */
            [ __( 'Cost of Goods', 'brikpanel' ), $money( $profit['cogs_raw'] ), sprintf( __( '%s%% of revenue', 'brikpanel' ), $profit['cogs_pct'] ) ],
            /* translators: %s: percentage of revenue. */
            [ __( 'Expenses', 'brikpanel' ), $money( $profit['expenses_raw'] ), sprintf( __( '%s%% of revenue', 'brikpanel' ), $profit['expenses_pct'] ) ],
            /* translators: %s: profit margin percentage. */
            [ __( 'Net Profit', 'brikpanel' ), $money( $profit['net_raw'] ), sprintf( __( '%s%% margin', 'brikpanel' ), $profit['margin'] ) ],
            [],
            [ [ __( 'Returns & Refunds', 'brikpanel' ), $T ] ],
            [ [ __( 'Returned / refunded orders', 'brikpanel' ), $B ], (int) $returns['count'] ],
            [ [ __( 'Total orders', 'brikpanel' ), $B ], (int) $returns['total'] ],
            [ [ __( 'Return & refund rate (%)', 'brikpanel' ), $B ], (float) $returns['rate'] ],
            [],
            [ [ __( 'Customer Lifetime Value (all-time)', 'brikpanel' ), $T ] ],
            [ [ __( 'Total customers', 'brikpanel' ), $B ], (int) $ltv['total_customers'] ],
            [ [ __( 'Average LTV', 'brikpanel' ), $B ], $plain( $ltv['avg_ltv'] ) ],
            [ [ __( 'Total LTV', 'brikpanel' ), $B ], $plain( $ltv['total_ltv'] ) ],
            [ [ __( 'Top customer LTV', 'brikpanel' ), $B ], $plain( $ltv['max_ltv'] ) ],
            [ [ __( 'Repeat customers', 'brikpanel' ), $B ], (int) $ltv['repeat_customers'] ],
            [ [ __( 'Repeat rate (%)', 'brikpanel' ), $B ], (float) $ltv['repeat_rate'] ],
            [],
            [ [ __( 'Note: the Orders tab lists every order placed in this period (all statuses). The “Orders” metric above counts paid orders only (processing + completed).', 'brikpanel' ), $B ] ],
        ];

        // ---------- Sheet 2: Conversion Funnel ----------
        $funnel_sheet = [
            [ [ __( 'Stage', 'brikpanel' ), $H ], [ __( 'Count', 'brikpanel' ), $H ], [ __( '% of visitors', 'brikpanel' ), $H ] ],
            [ __( 'Visitors', 'brikpanel' ), (int) $funnel['visitors'], $fpct( $funnel['visitors'] ) ],
            [ __( 'Product views', 'brikpanel' ), (int) $funnel['products'], $fpct( $funnel['products'] ) ],
            [ __( 'Add to cart', 'brikpanel' ), (int) $funnel['cart'], $fpct( $funnel['cart'] ) ],
            [ __( 'Checkout', 'brikpanel' ), (int) $funnel['checkout'], $fpct( $funnel['checkout'] ) ],
            [ __( 'Orders', 'brikpanel' ), (int) $funnel['orders'], $fpct( $funnel['orders'] ) ],
        ];

        // ---------- Sheet 3: Order Status ----------
        $status = [
            [ [ __( 'Status', 'brikpanel' ), $H ], [ __( 'Share (%)', 'brikpanel' ), $H ] ],
            [ __( 'Successful', 'brikpanel' ), (float) $rates['successful'] ],
            [ __( 'Failed', 'brikpanel' ), (float) $rates['failed'] ],
            [ __( 'Returns & Refunds', 'brikpanel' ), (float) $rates['refunded'] ],
            [ __( 'Cancelled', 'brikpanel' ), (float) $rates['cancelled'] ],
            [],
            [ [ __( 'Total orders', 'brikpanel' ), $B ], (int) $rates['total'] ],
        ];

        // ---------- Sheet 4: Devices (visitors vs orders) ----------
        $dev  = $d['devices'];
        $odev = $d['order_devices'];
        $devices_sheet = [
            [ [ __( 'Device', 'brikpanel' ), $H ], [ __( 'Visitors', 'brikpanel' ), $H ], [ __( 'Orders', 'brikpanel' ), $H ] ],
            [ __( 'Desktop', 'brikpanel' ), (int) $dev['desktop'], (int) $odev['desktop'] ],
            [ __( 'Mobile', 'brikpanel' ), (int) $dev['mobile'], (int) $odev['mobile'] ],
            [ __( 'Tablet', 'brikpanel' ), (int) $dev['tablet'], (int) $odev['tablet'] ],
            [ [ __( 'Total', 'brikpanel' ), $B ],
              (int) ( $dev['desktop'] + $dev['mobile'] + $dev['tablet'] ),
              (int) ( $odev['desktop'] + $odev['mobile'] + $odev['tablet'] ) ],
        ];

        // ---------- Sheet 5: Customer Segments (new vs repeat + RFM) ----------
        $ct = $d['customer_types'];
        $segments_sheet = [
            [ [ __( 'New vs Repeat (this period)', 'brikpanel' ), $T ] ],
            [ [ __( 'Type', 'brikpanel' ), $H ], [ __( 'Customers', 'brikpanel' ), $H ] ],
            [ __( 'New customers', 'brikpanel' ), (int) $ct['new'] ],
            [ __( 'Repeat customers', 'brikpanel' ), (int) $ct['repeat'] ],
            [],
            [ [ __( 'RFM Segments (all-time)', 'brikpanel' ), $T ] ],
            [ [ __( 'Segment', 'brikpanel' ), $H ], [ __( 'Customers', 'brikpanel' ), $H ], [ __( 'Share (%)', 'brikpanel' ), $H ] ],
        ];
        if ( empty( $d['rfm_distribution'] ) ) {
            $segments_sheet[] = [ __( 'Customer metrics will appear after the nightly recompute.', 'brikpanel' ), '', '' ];
        } else {
            foreach ( $d['rfm_distribution'] as $seg ) {
                $segments_sheet[] = [ $seg['label'], (int) $seg['customers'], (float) $seg['share'] ];
            }
        }

        // ---------- Sheet 6: Top Products ----------
        $products = [ [ [ __( 'Product', 'brikpanel' ), $H ], [ __( 'Qty Sold', 'brikpanel' ), $H ] ] ];
        if ( empty( $d['top_products'] ) ) {
            $products[] = [ __( 'No data for this period', 'brikpanel' ), '' ];
        } else {
            foreach ( $d['top_products'] as $tp ) {
                $products[] = [ $tp['name'], (int) $tp['qty'] ];
            }
        }

        // ---------- Sheet 7: Most Viewed ----------
        $viewed = [ [ [ __( 'Product', 'brikpanel' ), $H ], [ __( 'Views', 'brikpanel' ), $H ] ] ];
        if ( empty( $d['most_viewed'] ) ) {
            $viewed[] = [ __( 'No data for this period', 'brikpanel' ), '' ];
        } else {
            foreach ( $d['most_viewed'] as $mv ) {
                $viewed[] = [ $mv['title'], (int) $mv['views'] ];
            }
        }

        // ---------- Sheet 8: Most Added to Cart ----------
        $carted = [ [ [ __( 'Product', 'brikpanel' ), $H ], [ __( 'Cart Adds', 'brikpanel' ), $H ] ] ];
        if ( empty( $d['most_cart'] ) ) {
            $carted[] = [ __( 'No data for this period', 'brikpanel' ), '' ];
        } else {
            foreach ( $d['most_cart'] as $mc ) {
                $carted[] = [ $mc['name'], (int) $mc['count'] ];
            }
        }

        // ---------- Sheet 9: Sales Over Time (daily series) ----------
        $sot = [ [ [ __( 'Date', 'brikpanel' ), $H ], [ sprintf( __( 'Revenue (%s)', 'brikpanel' ), $currency ), $H ], [ __( 'Orders', 'brikpanel' ), $H ] ] ];
        if ( empty( $d['sales_over_time'] ) ) {
            $sot[] = [ __( 'No data for this period', 'brikpanel' ), '', '' ];
        } else {
            foreach ( $d['sales_over_time'] as $pt ) {
                $sot[] = [ $pt['date'], $money( $pt['revenue'] ), (int) $pt['orders'] ];
            }
        }

        // ---------- Sheet 10: Countries ----------
        $locs    = $d['order_locations'];
        $countries = [ [ [ __( 'Country', 'brikpanel' ), $H ], [ __( 'Orders', 'brikpanel' ), $H ], [ __( 'Customers', 'brikpanel' ), $H ], [ sprintf( __( 'Revenue (%s)', 'brikpanel' ), $currency ), $H ] ] ];
        if ( empty( $locs['countries'] ) ) {
            $countries[] = [ __( 'No data for this period', 'brikpanel' ), '', '', '' ];
        } else {
            foreach ( $locs['countries'] as $co ) {
                $countries[] = [ $co['name'], (int) $co['count'], (int) $co['customers'], $plain( $co['total'] ) ];
            }
        }

        // ---------- Sheet 11: Cities ----------
        $cities = [ [ [ __( 'City', 'brikpanel' ), $H ], [ __( 'Country', 'brikpanel' ), $H ], [ __( 'Orders', 'brikpanel' ), $H ], [ __( 'Customers', 'brikpanel' ), $H ], [ __( 'Items', 'brikpanel' ), $H ] ] ];
        if ( empty( $locs['cities'] ) ) {
            $cities[] = [ __( 'No data for this period', 'brikpanel' ), '', '', '', '' ];
        } else {
            foreach ( $locs['cities'] as $ci ) {
                $cities[] = [ $ci['name'], $ci['country'], (int) $ci['count'], (int) $ci['customers'], (int) $ci['quantity'] ];
            }
        }

        // ---------- Sheet 12: Low Stock ----------
        $lowstock = [ [ [ __( 'Product', 'brikpanel' ), $H ], [ __( 'SKU', 'brikpanel' ), $H ], [ __( 'Remaining', 'brikpanel' ), $H ] ] ];
        if ( empty( $d['low_stock'] ) ) {
            $lowstock[] = [ __( 'All products are sufficiently stocked', 'brikpanel' ), '', '' ];
        } else {
            foreach ( $d['low_stock'] as $ls ) {
                $lowstock[] = [ $ls['name'], (string) $ls['sku'], (int) $ls['stock'] ];
            }
        }

        // ---------- Sheet 13: Subscriptions (only when present) ----------
        $subs_sheet = null;
        if ( ! empty( $d['subscription_stats'] ) ) {
            $subs_sheet = [ [ [ __( 'Status', 'brikpanel' ), $H ], [ __( 'Count', 'brikpanel' ), $H ] ] ];
            foreach ( $d['subscription_stats'] as $ss ) {
                $subs_sheet[] = [ $ss['label'], (int) $ss['count'] ];
            }
        }

        // ---------- Final sheet: Orders (the full record set) ----------
        $start_local = $d['period']['from_iso'];
        $end_local   = $d['period']['to_iso'];
        $orders_rows = [ [
            [ __( 'Order #', 'brikpanel' ), $H ],
            [ __( 'Date', 'brikpanel' ), $H ],
            [ __( 'Customer', 'brikpanel' ), $H ],
            [ __( 'Email', 'brikpanel' ), $H ],
            [ __( 'Status', 'brikpanel' ), $H ],
            [ __( 'Items', 'brikpanel' ), $H ],
            [ sprintf( __( 'Total (%s)', 'brikpanel' ), $currency ), $H ],
            [ __( 'Source', 'brikpanel' ), $H ],
        ] ];

        $admin_ids   = brikpanel_get_admin_user_ids();
        $date_filter = $start_local . '...' . $end_local;
        $paged       = 1;
        $per_page    = 200;
        $date_fmt    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

        do {
            $args = [
                'limit'        => $per_page,
                'paged'        => $paged,
                'orderby'      => 'date',
                'order'        => 'DESC',
                'type'         => 'shop_order',
                'date_created' => $date_filter,
                'return'       => 'objects',
            ];
            if ( ! empty( $admin_ids ) ) {
                $args['customer__not_in'] = array_map( 'intval', $admin_ids );
            }
            $orders = wc_get_orders( $args );
            if ( empty( $orders ) ) {
                break;
            }
            foreach ( $orders as $order ) {
                $name = trim( ( $order->get_billing_first_name() ?? '' ) . ' ' . ( $order->get_billing_last_name() ?? '' ) );
                if ( $name === '' ) {
                    $name = __( 'Guest', 'brikpanel' );
                }
                $src = $this->detect_order_source( $order );
                $orders_rows[] = [
                    // Order # stays text — numbers may carry a store prefix.
                    (string) $order->get_order_number(),
                    wp_date( $date_fmt, $order->get_date_created() ? $order->get_date_created()->getTimestamp() : null ),
                    $name,
                    $order->get_billing_email(),
                    wc_get_order_status_name( $order->get_status() ),
                    (int) $order->get_item_count(),
                    $money( $order->get_total() ),
                    $src ? $src['label'] : __( 'Direct', 'brikpanel' ),
                ];
            }
            $paged++;
        } while ( count( $orders ) === $per_page );

        $order_total = count( $orders_rows ) - 1; // minus header row
        if ( $order_total === 0 ) {
            $orders_rows[] = [ __( 'No orders in this period', 'brikpanel' ) ];
        }

        $writer = new Brikpanel_XLSX_Writer();
        $writer->add_sheet( __( 'Summary', 'brikpanel' ), $summary, [ 1 => 30, 2 => 22, 3 => 24 ] );
        $writer->add_sheet( __( 'Funnel', 'brikpanel' ), $funnel_sheet, [ 1 => 18, 2 => 14, 3 => 16 ] );
        $writer->add_sheet( __( 'Order Status', 'brikpanel' ), $status, [ 1 => 22, 2 => 12 ] );
        $writer->add_sheet( __( 'Devices', 'brikpanel' ), $devices_sheet, [ 1 => 16, 2 => 14, 3 => 14 ] );
        $writer->add_sheet( __( 'Customer Segments', 'brikpanel' ), $segments_sheet, [ 1 => 28, 2 => 14, 3 => 14 ] );
        $writer->add_sheet( __( 'Top Products', 'brikpanel' ), $products, [ 1 => 40, 2 => 12 ] );
        $writer->add_sheet( __( 'Most Viewed', 'brikpanel' ), $viewed, [ 1 => 40, 2 => 12 ] );
        $writer->add_sheet( __( 'Most Added to Cart', 'brikpanel' ), $carted, [ 1 => 40, 2 => 12 ] );
        $writer->add_sheet( __( 'Sales Over Time', 'brikpanel' ), $sot, [ 1 => 16, 2 => 16, 3 => 12 ], true );
        $writer->add_sheet( __( 'Countries', 'brikpanel' ), $countries, [ 1 => 24, 2 => 12, 3 => 14, 4 => 16 ] );
        $writer->add_sheet( __( 'Cities', 'brikpanel' ), $cities, [ 1 => 22, 2 => 16, 3 => 12, 4 => 14, 5 => 10 ] );
        $writer->add_sheet( __( 'Low Stock', 'brikpanel' ), $lowstock, [ 1 => 40, 2 => 18, 3 => 12 ] );
        if ( $subs_sheet !== null ) {
            $writer->add_sheet( __( 'Subscriptions', 'brikpanel' ), $subs_sheet, [ 1 => 24, 2 => 12 ] );
        }
        $writer->add_sheet(
            /* translators: %d: number of orders. */
            sprintf( __( 'Orders (%d)', 'brikpanel' ), max( 0, $order_total ) ),
            $orders_rows,
            [ 1 => 14, 2 => 22, 3 => 26, 4 => 30, 5 => 16, 6 => 9, 7 => 14, 8 => 18 ],
            true // freeze the header row for the long list
        );

        $xlsx = $writer->build();
        if ( $xlsx === false ) {
            wp_die( esc_html__( 'Could not generate the Excel file. Please try again.', 'brikpanel' ) );
        }

        // Clean any buffered admin output so the download stream is pristine.
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $filename  = sprintf(
            'brikpanel-report_%s_%s_to_%s.xlsx',
            sanitize_file_name( $site_host ?: 'store' ),
            $period['from_iso'],
            $period['to_iso']
        );

        nocache_headers();
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $xlsx ) );
        echo $xlsx; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — binary xlsx, not HTML.
        exit;
    }
}

new Brikpanel_Dashboard();
