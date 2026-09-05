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

    // Per-user memory of the last date range picked on the dashboard. Stored
    // with update_user_option() so it is per user AND per site on multisite:
    // one admin's "Last 30 Days" habit never leaks into another's screen, and
    // each store in a network keeps its own preference.
    const RANGE_PREF_OPTION = 'brikpanel_dash_range';

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

        // Invalidate the cached catalog counts whenever a product or variation
        // is created, published, unpublished, trashed, restored or deleted —
        // through ANY path: the product editor, Quick Edit, bulk actions,
        // adding/removing variations, CSV import, REST or WP-CLI.
        //
        // We deliberately hook WordPress core rather than the WooCommerce
        // product CRUD actions: adding a variation does not fire
        // `woocommerce_update_product`, and removing one force-deletes the
        // variation post (skipping `woocommerce_delete_product`), so those
        // never busted the cache and the Variations / Sellable figures went
        // stale for up to 5 minutes. `transition_post_status` catches every
        // status write via wp_insert_post; `before_delete_post` catches the
        // force-deletes (like variation removal) that skip the trash step.
        add_action( 'transition_post_status', [ $this, 'bust_catalog_counts_on_transition' ], 10, 3 );
        add_action( 'before_delete_post', [ $this, 'bust_catalog_counts_on_delete' ], 10, 1 );
    }

    /**
     * Date ranges the dashboard accepts. Anything outside this list is
     * rejected on both the read and the write side of the preference.
     *
     * @return string[]
     */
    public static function allowed_ranges() {
        return [ 'today', 'yesterday', '7days', '30days', '90days', 'custom' ];
    }

    /**
     * Is this a real Y-m-d calendar date?
     *
     * The shape check alone is not enough: "2026-13-45" matches the pattern
     * but strtotime() cannot parse it, and the date math then falls back to
     * the epoch, turning one bad value into a decades-wide table scan. Every
     * entry point that accepts a custom date (AJAX, export, the stored
     * preference) goes through here.
     *
     * @param mixed $value
     * @return bool
     */
    public static function is_valid_ymd( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
            return false;
        }
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }

    /**
     * The current user's remembered date range.
     *
     * Always returns a usable, fully validated shape. A corrupted or
     * half-written preference silently degrades to "today" rather than
     * feeding an unchecked value into the query builder.
     *
     * @return array{range:string,start:string,end:string}
     */
    public static function get_range_preference() {
        $fallback = [ 'range' => 'today', 'start' => '', 'end' => '' ];

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return $fallback;
        }

        $pref = get_user_option( self::RANGE_PREF_OPTION, $user_id );
        if ( ! is_array( $pref ) ) {
            return $fallback;
        }

        $range = isset( $pref['range'] ) ? sanitize_key( $pref['range'] ) : '';
        if ( ! in_array( $range, self::allowed_ranges(), true ) ) {
            return $fallback;
        }

        $start = ( isset( $pref['start'] ) && self::is_valid_ymd( $pref['start'] ) ) ? $pref['start'] : '';
        $end   = ( isset( $pref['end'] ) && self::is_valid_ymd( $pref['end'] ) ) ? $pref['end'] : '';

        // A remembered custom range is only meaningful with both ends intact.
        if ( 'custom' === $range && ( '' === $start || '' === $end ) ) {
            return $fallback;
        }

        return [ 'range' => $range, 'start' => $start, 'end' => $end ];
    }

    /**
     * Remember the range the user just looked at.
     *
     * Called from the data endpoint (so every selection is captured on the
     * request it already makes, with no extra round-trip) and deliberately
     * skips the write when nothing changed, which is the common case: the
     * dashboard refetches on every load and on every tab refocus.
     *
     * @param string      $range One of allowed_ranges().
     * @param string|null $start Y-m-d, custom range only.
     * @param string|null $end   Y-m-d, custom range only.
     */
    public static function save_range_preference( $range, $start = null, $end = null ) {
        $user_id = get_current_user_id();
        if ( ! $user_id || ! in_array( $range, self::allowed_ranges(), true ) ) {
            return;
        }

        $is_custom = ( 'custom' === $range );
        if ( $is_custom && ( ! self::is_valid_ymd( $start ) || ! self::is_valid_ymd( $end ) ) ) {
            return; // Nothing worth remembering.
        }

        $pref = [
            'range' => $range,
            'start' => $is_custom ? $start : '',
            'end'   => $is_custom ? $end : '',
        ];

        if ( self::get_range_preference() === $pref ) {
            return;
        }

        update_user_option( $user_id, self::RANGE_PREF_OPTION, $pref );
    }

    /**
     * Delete the cached catalog counters so the inventory summary line
     * recomputes on the next dashboard view. Cheap; accepts any hook args.
     */
    public function bust_catalog_counts() {
        delete_transient( 'brikpanel_catalog_counts' );
    }

    /**
     * Bust the cache on any product/variation status change. Runs on every
     * post save site-wide, so it bails immediately for unrelated post types.
     */
    public function bust_catalog_counts_on_transition( $new_status, $old_status, $post ) {
        if ( $post instanceof WP_Post
            && ( $post->post_type === 'product' || $post->post_type === 'product_variation' ) ) {
            $this->bust_catalog_counts();
        }
    }

    /**
     * Bust the cache when a product/variation is permanently deleted (e.g. a
     * variation removed in the editor, which force-deletes without trashing).
     * before_delete_post fires while the row still exists, so the type is
     * still resolvable.
     */
    public function bust_catalog_counts_on_delete( $post_id ) {
        $type = get_post_type( $post_id );
        if ( $type === 'product' || $type === 'product_variation' ) {
            $this->bust_catalog_counts();
        }
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
            'devices'           => __( 'Visitors by device + Customer types + Traffic sources', 'brikpanel' ),
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
                        <span class="brikpanel-dash-header-suffix"><?php esc_html_e( 'With Marketplace', 'brikpanel' ); ?></span>
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
                                <span class="brikpanel-dash-copy-help-body"><?php esc_html_e( 'Bundles your store’s key data — KPIs, profit and margins, cost of goods, ad spend, expenses, top products and categories, customers and settings — into a single Markdown report and copies it to your clipboard. Paste it into ChatGPT, Claude or any AI tool to get instant analysis, insights and recommendations about your store.', 'brikpanel' ); ?></span>
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
                    <?php
                    // The remembered range decides which preset renders active,
                    // so the button state matches the data on first paint —
                    // marking "Today" here and correcting it from JS would flash
                    // the wrong selection on every load.
                    $bp_saved_range = self::get_range_preference();
                    $bp_presets     = [
                        'today'     => __( 'Today', 'brikpanel' ),
                        'yesterday' => __( 'Yesterday', 'brikpanel' ),
                        '7days'     => __( 'Last 7 Days', 'brikpanel' ),
                        '30days'    => __( 'Last 30 Days', 'brikpanel' ),
                        '90days'    => __( 'Last 90 Days', 'brikpanel' ),
                        'custom'    => __( 'Custom', 'brikpanel' ),
                    ];
                    ?>
                    <div class="brikpanel-dash-range-wrap">
                        <div class="brikpanel-dash-presets">
                            <?php foreach ( $bp_presets as $bp_key => $bp_label ) : ?>
                                <button class="brikpanel-dash-preset<?php echo ( $bp_saved_range['range'] === $bp_key ) ? ' active' : ''; ?>" data-range="<?php echo esc_attr( $bp_key ); ?>"><?php echo esc_html( $bp_label ); ?></button>
                            <?php endforeach; ?>
                        </div>
                        <div class="brikpanel-dash-custom-range"<?php echo ( 'custom' === $bp_saved_range['range'] ) ? '' : ' style="display:none;"'; ?>>
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
                <?php $this->render_scope_hint(); ?>
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

            /**
             * Fires after all dashboard sections, at the bottom of the dashboard
             * content. Used to render the dismissible BrikMentor early-access card.
             *
             * @since 3.1.28
             */
            do_action( 'brikpanel_dashboard_after_sections' );
            ?>

        </div>
        <?php
    }

    // =========================================================================
    // HELP HINTS
    // =========================================================================

    /**
     * Human-readable date the plugin first started collecting data. Empty
     * string when the activation timestamp was never recorded (very old
     * installs that predate the option).
     *
     * @return string
     */
    private function activation_label() {
        static $label = null;
        if ( null !== $label ) {
            return $label;
        }
        $ts    = (int) get_option( 'brikpanel_activated_at', 0 );
        $label = $ts ? date_i18n( get_option( 'date_format' ) ?: 'M j, Y', $ts ) : '';
        return $label;
    }

    /**
     * Render a small "?" help icon with an on-hover / on-focus tooltip,
     * reusing the shared dashboard hint styling.
     *
     * @param string $title Short bold heading (plain text).
     * @param string $body  Explanation. Allows <br> and <strong> only.
     * @param string $align 'start' (tooltip opens rightward, default) or 'end'
     *                      (opens leftward, for right-most elements).
     */
    private function render_hint( $title, $body, $align = 'start' ) {
        $modifier = ( 'end' === $align ) ? ' brikpanel-dash-hint--end' : '';
        ?>
        <span class="brikpanel-dash-hint<?php echo esc_attr( $modifier ); ?>" tabindex="0" role="button" aria-label="<?php echo esc_attr( $title ); ?>">
            <svg class="brikpanel-dash-hint-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <span class="brikpanel-dash-hint-tip" role="tooltip">
                <span class="brikpanel-dash-hint-title"><?php echo esc_html( $title ); ?></span>
                <span class="brikpanel-dash-hint-body"><?php echo wp_kses( $body, [ 'br' => [], 'strong' => [] ] ); ?></span>
            </span>
        </span>
        <?php
    }

    /**
     * Tooltip shown next to BrikPanel-tracked metrics (Visitors, Conversion
     * rate, the funnel). These start collecting only once the plugin is active,
     * so a fresh install reads low or empty here even though the store has
     * order history. Spells that out so the figures are not mistaken for a bug.
     */
    private function render_tracking_hint( $align = 'start' ) {
        $date  = $this->activation_label();
        $title = __( 'Where this data comes from', 'brikpanel' );
        if ( '' !== $date ) {
            $body = sprintf(
                /* translators: %s: date BrikPanel was activated. */
                __( 'BrikPanel started measuring visitors and conversions on %s, the day it was activated. There is no visitor history before that date, so a recent install can read low or empty here. Your sales and orders are not affected. Those use your full WooCommerce history.', 'brikpanel' ),
                '<strong>' . esc_html( $date ) . '</strong>'
            );
        } else {
            $body = __( 'BrikPanel measures visitors and conversions from the day it was activated. There is no visitor history before that, so a recent install can read low or empty here. Your sales and orders are not affected. Those use your full WooCommerce history.', 'brikpanel' );
        }
        $this->render_hint( $title, $body, $align );
    }

    /**
     * Master "about these numbers" tooltip shown under the header. Explains the
     * two data sources (full order history vs tracking-from-activation) and the
     * deliberate exclusion of admin-placed orders, so store owners understand
     * why a test order of their own does not move the totals.
     */
    private function render_scope_hint() {
        $date  = $this->activation_label();
        $title = __( 'About these numbers', 'brikpanel' );

        $lines   = [];
        $lines[] = __( 'Sales, orders, profit and customer figures use your full WooCommerce order history.', 'brikpanel' );
        if ( '' !== $date ) {
            $lines[] = sprintf(
                /* translators: %s: date BrikPanel was activated. */
                __( 'Visitors, conversion rate and the funnel are measured by BrikPanel from %s, the day it was activated, so they have no earlier history.', 'brikpanel' ),
                '<strong>' . esc_html( $date ) . '</strong>'
            );
        } else {
            $lines[] = __( 'Visitors, conversion rate and the funnel are measured by BrikPanel from the day it was activated, so they have no earlier history.', 'brikpanel' );
        }
        $lines[] = __( 'Orders placed by store administrators are left out of every figure, so your own test orders do not change the totals.', 'brikpanel' );

        $this->render_hint( $title, implode( '<br><br>', $lines ) );
    }

    /**
     * Tooltip explaining that orders placed by store administrators are
     * excluded from the figure it sits next to (revenue / total sales). Stops
     * owners thinking a test order of their own has gone missing.
     */
    private function render_admin_excluded_hint() {
        $this->render_hint(
            __( 'Why this can look low', 'brikpanel' ),
            __( 'Orders placed by store administrators are not counted here, so test orders you place on your own admin account do not change this figure. Real customer orders are always included.', 'brikpanel' )
        );
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
                    <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Visitors', 'brikpanel' ); ?><?php $this->render_tracking_hint(); ?></span>
                    <span class="brikpanel-dash-card-value" id="card-visitors">--</span>
                    <span class="brikpanel-dash-card-delta" id="delta-visitors"></span>
                </div>
                <div class="brikpanel-dash-card" data-metric="conversion">
                    <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Conversion Rate', 'brikpanel' ); ?><?php $this->render_tracking_hint( 'end' ); ?></span>
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
        // Per-field display preferences. Cost of goods and Expenses cards can
        // be hidden by stores that do not track them; Revenue and Net profit
        // always render. Defensive default (show) when the helper is absent.
        $has_pref      = function_exists( 'brikpanel_dashboard_profit_field_enabled' );
        $show_cogs     = ! $has_pref || brikpanel_dashboard_profit_field_enabled( 'cogs' );
        $show_expenses = ! $has_pref || brikpanel_dashboard_profit_field_enabled( 'expenses' );
        $returns_on    = ! $has_pref || brikpanel_dashboard_profit_field_enabled( 'returns' );

        // Revenue is paid orders for the period, optionally net of refunds, with
        // tax and shipping included and admin orders excluded.
        $rev_body = $returns_on
            ? __( 'The total of all paid orders for the selected dates (Processing and Completed by default), with tax and shipping included and any customer refunds in the period subtracted. Orders placed by store administrators are left out so your own test orders do not change it. You can change which statuses count under Settings, then Analytics.', 'brikpanel' )
            : __( 'The total of all paid orders for the selected dates (Processing and Completed by default), with tax and shipping included. Orders placed by store administrators are left out so your own test orders do not change it. You can change which statuses count under Settings, then Analytics.', 'brikpanel' );
        ?>
            <!-- Profit -->
            <?php $profit_cols = 2 + ( $show_cogs ? 1 : 0 ) + ( $show_expenses ? 1 : 0 ); ?>
            <div class="brikpanel-dash-profit" id="brikpanel-profit-section">
                <div class="brikpanel-dash-cards brikpanel-dash-cards-profit bp-profit-cols-<?php echo (int) $profit_cols; ?>" id="brikpanel-profit-cards">
                    <div class="brikpanel-dash-card" data-metric="profit_revenue" id="profit-revenue-card">
                        <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Revenue', 'brikpanel' ); ?><?php
                            $this->render_hint( __( 'How Revenue is calculated', 'brikpanel' ), $rev_body ); ?></span>
                        <span class="brikpanel-dash-card-value" id="card-profit-revenue">--</span>
                        <span class="brikpanel-dash-card-delta" id="delta-profit-revenue"></span>
                        <button type="button" class="brikpanel-dash-bd-toggle" id="profit-rev-bd-toggle"
                                aria-expanded="false" aria-controls="profit-rev-bd-collapse" hidden
                                title="<?php esc_attr_e( 'Show revenue breakdown', 'brikpanel' ); ?>"
                                aria-label="<?php esc_attr_e( 'Show revenue breakdown', 'brikpanel' ); ?>">
                            <svg class="brikpanel-dash-bd-chevron" width="14" height="14" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                 stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </button>
                        <div class="brikpanel-dash-bd-collapse" id="profit-rev-bd-collapse">
                            <div class="brikpanel-dash-bd-inner">
                                <div class="brikpanel-dash-bd-list" id="profit-revenue-breakdown"></div>
                            </div>
                        </div>
                    </div>
                    <?php if ( $show_cogs ) : ?>
                    <div class="brikpanel-dash-card" data-metric="profit_cogs">
                        <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Cost of Goods', 'brikpanel' ); ?><?php
                            $this->render_hint(
                                __( 'How Cost of Goods is calculated', 'brikpanel' ),
                                __( 'The "Cost of goods" you set on each product, multiplied by the quantity sold in paid orders for the period. Variations use their own cost and fall back to the parent product. Any product with no cost set counts as zero, which overstates Net profit, so fill those in for an accurate margin.', 'brikpanel' )
                            ); ?></span>
                        <span class="brikpanel-dash-card-value" id="card-profit-cogs">--</span>
                        <span class="brikpanel-dash-card-delta brikpanel-dash-card-delta-static" id="delta-profit-cogs"></span>
                    </div>
                    <?php endif; ?>
                    <?php if ( $show_expenses ) : ?>
                    <div class="brikpanel-dash-card" data-metric="profit_expenses" id="profit-expenses-card">
                        <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Expenses', 'brikpanel' ); ?><?php
                            $this->render_hint(
                                __( 'What Expenses includes', 'brikpanel' ),
                                __( 'Operating costs for the period: order tax, ad spend from connected ad platforms (store currency only), payment processing fees charged by the gateway, supplier and stock costs from received purchase orders, plus anything logged in the Expenses module. Open the breakdown to see each part.', 'brikpanel' )
                            ); ?></span>
                        <span class="brikpanel-dash-card-value" id="card-profit-expenses">--</span>
                        <span class="brikpanel-dash-card-delta brikpanel-dash-card-delta-static" id="delta-profit-expenses"></span>
                        <button type="button" class="brikpanel-dash-bd-add" id="profit-exp-add"
                                title="<?php esc_attr_e( 'Add expense', 'brikpanel' ); ?>"
                                aria-label="<?php esc_attr_e( 'Add expense', 'brikpanel' ); ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </button>
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
                    <?php endif; ?>
                    <div class="brikpanel-dash-card" data-metric="profit_net">
                        <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Net Profit', 'brikpanel' ); ?><?php
                            $this->render_hint(
                                __( 'How Net Profit is calculated', 'brikpanel' ),
                                __( 'Revenue minus Cost of goods minus Expenses. This is what is left after the cost of what you sold and your operating costs for the period. A negative figure means a loss.', 'brikpanel' )
                            ); ?></span>
                        <span class="brikpanel-dash-card-value" id="card-profit-net">--</span>
                        <span class="brikpanel-dash-card-delta" id="delta-profit-net"></span>
                    </div>
                </div>
                <?php if ( $show_expenses ) { $this->render_add_expense_modal(); $this->render_remove_expense_modal(); } ?>
            </div>
        <?php
    }

    /**
     * Quick "Add expense" modal shown from the Profit > Expenses card. Posts to
     * the Expenses module's own save endpoint so a recurring entry created here
     * is materialised across periods exactly like one added on the full
     * Operational Expenses page. All copy is PHP-translated; the only JS-driven
     * strings (status messages) come from the localised i18n bag.
     */
    private function render_add_expense_modal() {
        $cats     = class_exists( 'Brikpanel_Expenses' ) ? Brikpanel_Expenses::categories() : [];
        $can_group = class_exists( 'Brikpanel_Expenses' )
            && method_exists( 'Brikpanel_Expenses', 'render_parent_category_picker' );
        $currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';
        $today    = current_time( 'Y-m-d' );
        ?>
        <div class="brikpanel-exp-modal" id="brikpanel-exp-modal" hidden>
            <div class="brikpanel-exp-modal-overlay" data-exp-close></div>
            <div class="brikpanel-exp-modal-card" role="dialog" aria-modal="true" aria-labelledby="brikpanel-exp-modal-title">
                <div class="brikpanel-exp-modal-head">
                    <h2 id="brikpanel-exp-modal-title"><?php esc_html_e( 'Add expense', 'brikpanel' ); ?></h2>
                    <button type="button" class="brikpanel-exp-modal-x" data-exp-close aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>
                <div class="brikpanel-exp-modal-body">
                    <div class="brikpanel-exp-field">
                        <label for="brikpanel-exp-kind"><?php esc_html_e( 'Type', 'brikpanel' ); ?></label>
                        <select id="brikpanel-exp-kind">
                            <option value="fixed"><?php esc_html_e( 'Fixed amount', 'brikpanel' ); ?></option>
                            <option value="percent"><?php esc_html_e( 'Percentage of revenue', 'brikpanel' ); ?></option>
                            <?php // Same English source string as the Expenses page on purpose: the Sheets resolve_kind() English arm matches on it. ?>
                            <option value="per_order"><?php esc_html_e( 'Cost per order', 'brikpanel' ); ?></option>
                        </select>
                    </div>
                    <div class="brikpanel-exp-field">
                        <label for="brikpanel-exp-amount" id="brikpanel-exp-amount-label"><?php esc_html_e( 'Amount', 'brikpanel' ); ?></label>
                        <div class="brikpanel-exp-input-group">
                            <?php if ( '' !== $currency ) : ?><span class="brikpanel-exp-prefix" id="brikpanel-exp-prefix"><?php echo esc_html( $currency ); ?></span><?php endif; ?>
                            <input type="number" id="brikpanel-exp-amount" step="0.01" min="0" inputmode="decimal" autocomplete="off">
                            <span class="brikpanel-exp-suffix" id="brikpanel-exp-suffix" hidden>%</span>
                        </div>
                    </div>
                    <?php
                    // "Applies to" sits right after Amount because it qualifies it.
                    // Guarded like $can_group above: when the Expenses class is not
                    // loaded the field is omitted entirely and the quick-add simply
                    // degrades to fixed/percent rather than offering a control the
                    // server could not read back.
                    if ( class_exists( 'Brikpanel_Expenses' ) && method_exists( 'Brikpanel_Expenses', 'shipping_class_options' ) ) :
                        $exp_shipping_classes = Brikpanel_Expenses::shipping_class_options();
                    ?>
                    <div class="brikpanel-exp-field" id="brikpanel-exp-scope-field" hidden>
                        <label for="brikpanel-exp-scope"><?php echo esc_html( _x( 'Applies to', 'which orders a per-order cost is charged on', 'brikpanel' ) ); ?></label>
                        <select id="brikpanel-exp-scope">
                            <option value=""><?php esc_html_e( 'Every order', 'brikpanel' ); ?></option>
                            <option value="free_shipping"><?php esc_html_e( 'Orders shipped free', 'brikpanel' ); ?></option>
                            <?php if ( $exp_shipping_classes ) : ?>
                                <optgroup label="<?php esc_attr_e( 'Shipping class', 'brikpanel' ); ?>">
                                    <?php foreach ( $exp_shipping_classes as $sc_id => $sc_name ) : ?>
                                        <option value="shipping_class:<?php echo (int) $sc_id; ?>"><?php echo esc_html( $sc_name ); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="brikpanel-exp-field">
                        <label for="brikpanel-exp-category"><?php esc_html_e( 'Title', 'brikpanel' ); ?></label>
                        <input type="text" id="brikpanel-exp-category" list="brikpanel-exp-cats" autocomplete="off"
                               placeholder="<?php esc_attr_e( 'e.g. Rent, Salaries, Credit card commission', 'brikpanel' ); ?>">
                        <datalist id="brikpanel-exp-cats">
                            <?php foreach ( $cats as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>"></option><?php endforeach; ?>
                        </datalist>
                    </div>
                    <?php // Naming the cost comes first, then what it belongs to: the second question only makes sense once the first is answered. ?>
                    <?php if ( $can_group ) : ?>
                    <div class="brikpanel-exp-field">
                        <label for="brikpanel-exp-parent-category"><?php echo esc_html( _x( 'Part of', 'the expense this cost is filed under', 'brikpanel' ) ); ?> <span class="brikpanel-exp-optional"><?php esc_html_e( 'optional', 'brikpanel' ); ?></span></label>
                        <?php Brikpanel_Expenses::render_parent_category_picker( 'brikpanel-exp-parent-category' ); ?>
                        <p class="brikpanel-exp-hint"><?php esc_html_e( 'Shows this cost under one you already have. Amounts stay separate.', 'brikpanel' ); ?></p>
                    </div>
                    <?php endif; ?>
                    <div class="brikpanel-exp-row2" id="brikpanel-exp-row2">
                        <div class="brikpanel-exp-field">
                            <label for="brikpanel-exp-date" id="brikpanel-exp-date-label"><?php esc_html_e( 'Date', 'brikpanel' ); ?></label>
                            <input type="date" id="brikpanel-exp-date" value="<?php echo esc_attr( $today ); ?>">
                        </div>
                        <div class="brikpanel-exp-field" id="brikpanel-exp-recurring-field">
                            <label for="brikpanel-exp-recurring"><?php esc_html_e( 'Repeats', 'brikpanel' ); ?></label>
                            <select id="brikpanel-exp-recurring">
                                <option value="none"><?php esc_html_e( 'One-time', 'brikpanel' ); ?></option>
                                <option value="monthly"><?php esc_html_e( 'Monthly', 'brikpanel' ); ?></option>
                                <option value="weekly"><?php esc_html_e( 'Weekly', 'brikpanel' ); ?></option>
                                <option value="yearly"><?php esc_html_e( 'Yearly', 'brikpanel' ); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="brikpanel-exp-field">
                        <label for="brikpanel-exp-desc"><?php esc_html_e( 'Note (optional)', 'brikpanel' ); ?></label>
                        <input type="text" id="brikpanel-exp-desc" autocomplete="off"
                               placeholder="<?php esc_attr_e( 'What is this for?', 'brikpanel' ); ?>">
                    </div>
                    <p class="brikpanel-exp-recurring-hint" id="brikpanel-exp-recurring-hint" hidden><?php
                        esc_html_e( 'A repeating expense is counted automatically in every period from this date onward.', 'brikpanel' ); ?></p>
                    <p class="brikpanel-exp-recurring-hint" id="brikpanel-exp-percent-hint" hidden><?php
                        esc_html_e( 'A percentage cost is applied to your revenue in every period from this date onward (great for card or marketplace commission).', 'brikpanel' ); ?></p>
                    <p class="brikpanel-exp-recurring-hint" id="brikpanel-exp-per-order-hint" hidden><?php
                        esc_html_e( 'A per-order cost is charged once for every matching order in the period you are viewing (great for packaging, or the courier fee on orders you ship free).', 'brikpanel' ); ?></p>
                    <div class="brikpanel-exp-msg" id="brikpanel-exp-msg" role="alert" hidden></div>
                </div>
                <div class="brikpanel-exp-modal-foot">
                    <button type="button" class="brikpanel-exp-btn brikpanel-exp-btn-secondary" data-exp-close><?php esc_html_e( 'Cancel', 'brikpanel' ); ?></button>
                    <button type="button" class="brikpanel-exp-btn brikpanel-exp-btn-primary" id="brikpanel-exp-save"><?php esc_html_e( 'Save expense', 'brikpanel' ); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Confirmation shown by the Remove control on a Profit > Expenses breakdown
     * line. Deliberately empty: the title, the summary and the choices are all
     * written by the server, which is the only side that knows whether a line is
     * one entry or a whole repeating expense. Reuses the quick-add modal's
     * styling; its own close hook is `data-expdel-close` so the two dialogs
     * never answer each other's clicks.
     */
    private function render_remove_expense_modal() {
        ?>
        <div class="brikpanel-exp-modal" id="brikpanel-expdel-modal" hidden>
            <div class="brikpanel-exp-modal-overlay" data-expdel-close></div>
            <div class="brikpanel-exp-modal-card brikpanel-expdel-card" role="dialog" aria-modal="true" aria-labelledby="brikpanel-expdel-title">
                <div class="brikpanel-exp-modal-head">
                    <h2 id="brikpanel-expdel-title"><?php esc_html_e( 'Remove this expense?', 'brikpanel' ); ?></h2>
                    <button type="button" class="brikpanel-exp-modal-x" data-expdel-close aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>
                <div class="brikpanel-exp-modal-body">
                    <p class="brikpanel-expdel-body" id="brikpanel-expdel-body"></p>
                    <p class="brikpanel-expdel-note" id="brikpanel-expdel-note" hidden></p>
                    <div class="brikpanel-expdel-scopes" id="brikpanel-expdel-scopes" role="radiogroup"
                         aria-label="<?php esc_attr_e( 'What to remove', 'brikpanel' ); ?>"></div>
                    <div class="brikpanel-exp-msg" id="brikpanel-expdel-msg" role="alert" hidden></div>
                </div>
                <div class="brikpanel-exp-modal-foot">
                    <button type="button" class="brikpanel-exp-btn brikpanel-exp-btn-secondary" data-expdel-close><?php esc_html_e( 'Cancel', 'brikpanel' ); ?></button>
                    <button type="button" class="brikpanel-exp-btn brikpanel-exp-btn-danger" id="brikpanel-expdel-confirm"><?php esc_html_e( 'Remove', 'brikpanel' ); ?></button>
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
                    <h2><?php esc_html_e( 'Conversion Funnel', 'brikpanel' ); ?><?php $this->render_tracking_hint(); ?></h2>
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
                        <button class="brikpanel-loc-tab brikpanel-device-tab" data-device-view="sources" type="button">
                            <?php esc_html_e( 'Sources', 'brikpanel' ); ?>
                        </button>
                    </div>
                </div>
                <div id="brikpanel-device-breakdown">
                    <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                </div>
                <div id="brikpanel-source-referrers" class="brikpanel-source-referrers" style="display:none;">
                    <h3 class="brikpanel-sources-subhead"><?php esc_html_e( 'Top Referrers', 'brikpanel' ); ?></h3>
                    <div id="brikpanel-top-referrers"></div>
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
            AND status IN (" . brikpanel_paid_statuses_sql() . ")",
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

        $statuses = brikpanel_paid_order_statuses();
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

    /**
     * Channel set for the Traffic Sources card, in display order. Each visit is
     * bucketed into exactly one of these by brikpanel_classify_traffic_source().
     */
    private function traffic_source_channels(): array {
        return [ 'direct', 'search', 'social', 'referral', 'paid', 'email' ];
    }

    /**
     * Aggregate visitor counts per traffic-source channel from
     * wp_brikpanel_referrers for a date range. Returns every known channel
     * (zero-filled) so the front end can decide what to show.
     *
     * @return array<string,int> channel => hits
     */
    private function get_traffic_source_breakdown( string $start_local, string $end_local ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'brikpanel_referrers';

        $counts = array_fill_keys( $this->traffic_source_channels(), 0 );

        // Table may not exist yet on installs that haven't run the 3.1.46 upgrade.
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ) { // phpcs:ignore
            return $counts;
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT channel, COALESCE(SUM(hits), 0) AS hits
             FROM {$table}
             WHERE date_column BETWEEN %s AND %s
             GROUP BY channel",
            $start_local,
            $end_local
        ) );

        foreach ( (array) $rows as $row ) {
            if ( isset( $counts[ $row->channel ] ) ) {
                $counts[ $row->channel ] = (int) $row->hits;
            }
        }

        return $counts;
    }

    /**
     * Top referring domains (external only) for a date range — the detail list
     * shown alongside the channel bars on the Traffic Sources card.
     *
     * @return array<int,array{host:string,channel:string,hits:int}>
     */
    private function get_top_referrers( string $start_local, string $end_local, int $limit = 8 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'brikpanel_referrers';

        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ) { // phpcs:ignore
            return [];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT host, channel, SUM(hits) AS hits
             FROM {$table}
             WHERE date_column BETWEEN %s AND %s
               AND host <> ''
             GROUP BY host, channel
             ORDER BY hits DESC
             LIMIT %d",
            $start_local,
            $end_local,
            $limit
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[] = [
                'host'    => (string) $row->host,
                'channel' => (string) $row->channel,
                'hits'    => (int) $row->hits,
            ];
        }
        return $out;
    }

    public function render_section_stock_returns() {
        $catalog = $this->get_catalog_counts();
        // Compact catalog-size line shown under the Low Stock heading. Three
        // plural-aware parts joined with a middot: products (catalog entries),
        // variations, and sellable items (purchasable products + variations,
        // i.e. counting variable products by their variations, not as one).
        $catalog_parts = array(
            sprintf(
                /* translators: %s: number of published products */
                _n( '%s product', '%s products', $catalog['products'], 'brikpanel' ),
                number_format_i18n( $catalog['products'] )
            ),
            sprintf(
                /* translators: %s: number of product variations */
                _n( '%s variation', '%s variations', $catalog['variations'], 'brikpanel' ),
                number_format_i18n( $catalog['variations'] )
            ),
            sprintf(
                /* translators: %s: number of purchasable items (products + variations) */
                _n( '%s sellable item', '%s sellable items', $catalog['sellable'], 'brikpanel' ),
                number_format_i18n( $catalog['sellable'] )
            ),
            sprintf(
                /* translators: %s: total number of stock units on hand across the store */
                _n( '%s unit in stock', '%s units in stock', $catalog['stock_units'], 'brikpanel' ),
                number_format_i18n( $catalog['stock_units'] )
            ),
        );
        ?>
            <!-- Row: Low Stock + Customer Lifetime Value -->
            <div class="brikpanel-dash-row brikpanel-dash-row-1-1">
                <div class="brikpanel-dash-panel">
                    <h2><?php esc_html_e( 'Low Stock', 'brikpanel' ); ?></h2>
                    <p class="brikpanel-dash-inv-line" title="<?php esc_attr_e( 'Sellable items counts each variable product by its purchasable variations, not as a single product. Units in stock is the total on-hand quantity across all stock-managed products.', 'brikpanel' ); ?>">
                        <?php echo esc_html( implode( '  ·  ', $catalog_parts ) ); ?>
                    </p>
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

    /**
     * Catalog size counters for the inventory summary tiles.
     *
     * WooCommerce spreads a store's "product count" across two post types:
     * a variable product is a single `product` row plus N `product_variation`
     * rows, and only the variations are actually purchasable. wp_count_posts()
     * only ever sees the parent rows, so a store owner has no single place to
     * see how many sellable items the catalog really holds. We compute three
     * figures directly in SQL (cheap COUNTs, cached briefly for big stores):
     *
     *   - products   : published `product` entries (the "normal" catalog count)
     *   - variations : enabled variations under published parents
     *   - sellable   : purchasable SKUs = non-variable products + variations
     *   - stock_units: total on-hand units (SUM of _stock for managed items)
     *
     * @return array{products:int,variations:int,variable:int,sellable:int,stock_units:int}
     */
    private function get_catalog_counts(): array {
        $cached = get_transient( 'brikpanel_catalog_counts' );
        // Require the full current key set: a transient written by an older
        // build (before stock_units existed) is treated as a miss so the
        // view never reads an undefined key.
        if ( is_array( $cached ) && isset( $cached['stock_units'] ) ) {
            return $cached;
        }

        global $wpdb;

        // Published parent products (simple, variable, grouped, external, ...).
        $product_counts = wp_count_posts( 'product' );
        $products = isset( $product_counts->publish ) ? (int) $product_counts->publish : 0;

        // Enabled variations belonging to a published parent. A disabled
        // variation is stored with post_status = 'private', so requiring
        // 'publish' on the variation row counts only purchasable ones.
        $variations = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} v
             INNER JOIN {$wpdb->posts} p ON v.post_parent = p.ID
             WHERE v.post_type = 'product_variation'
               AND v.post_status = 'publish'
               AND p.post_type = 'product'
               AND p.post_status = 'publish'"
        );

        // Published products of type `variable` (their parent row is not
        // itself purchasable — the variations replace it in the SKU total).
        $variable = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'
               AND tt.taxonomy = 'product_type'
               AND t.slug = 'variable'"
        );

        // Sellable SKUs: every non-variable product counts once, and each
        // variable product is replaced by its purchasable variations. So a
        // variable product with no enabled variations is genuinely not
        // sellable and correctly contributes 0 — the total can legitimately
        // sit below the plain product count in a catalog full of incomplete
        // variable products. `variable` is always a subset of `products`
        // (both are published), so the subtraction can't go negative; the
        // max(0, …) is only defensive against inconsistent data.
        $sellable = max( 0, $products - $variable ) + $variations;

        // Total on-hand inventory: the sum of `_stock` across every
        // stock-managed, published product/variation. This answers a
        // different question than the counts above — not "how many SKUs"
        // but "how many physical units are on the shelf" (a store with 3
        // products can hold thousands of units). Only rows whose owner has
        // `_manage_stock = yes` carry a meaningful `_stock`, so unmanaged
        // products are excluded. Counting both `product` and
        // `product_variation` does not double-count, because in WooCommerce
        // a variable parent and its variations never both manage stock at
        // the same time — stock lives on the parent OR on each variation.
        //
        // Value format is the subtlety here: WooCommerce stores `_stock` as
        // a DECIMAL string, so a stock of 37 is persisted as "37.000000".
        // The REGEXP therefore has to allow an optional fractional part
        // (`^-?[0-9]+([.][0-9]+)?$` — the dot is a character class so no
        // PHP/SQL backslash escaping is involved); a naive integer-only
        // pattern silently drops every managed row and undercounts the
        // store, which is exactly the bug in the naive version. CAST … AS
        // SIGNED then truncates toward zero, matching WooCommerce's own
        // default `wc_stock_amount()` (intval) so this total agrees exactly
        // with what get_stock_quantity() reports per item. The REGEXP still
        // rejects blank/non-numeric meta that CAST would otherwise coerce
        // to 0.
        $stock_units = (int) $wpdb->get_var(
            "SELECT SUM(CAST(pm.meta_value AS SIGNED))
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->postmeta} ms ON pm.post_id = ms.post_id AND ms.meta_key = '_manage_stock'
             INNER JOIN {$wpdb->posts} p     ON pm.post_id = p.ID
             WHERE pm.meta_key = '_stock'
               AND ms.meta_value = 'yes'
               AND p.post_status = 'publish'
               AND p.post_type IN ('product','product_variation')
               AND pm.meta_value REGEXP '^-?[0-9]+([.][0-9]+)?$'"
        );

        $result = [
            'products'    => $products,
            'variations'  => $variations,
            'variable'    => $variable,
            'sellable'    => $sellable,
            'stock_units' => $stock_units,
        ];

        // Short cache: counts change rarely relative to dashboard views and the
        // joins can be non-trivial on very large catalogs.
        set_transient( 'brikpanel_catalog_counts', $result, 5 * MINUTE_IN_SECONDS );

        return $result;
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
        // Preserve user-selected order. Each widget is additionally gated by its
        // own audience rule (Everyone / Admins only / Specific roles) so the
        // owner can keep sensitive widgets like Site Health limited by role.
        $chosen = [];
        foreach ( $selected as $widget_id ) {
            if ( ! isset( $widgets[ $widget_id ] ) ) {
                continue;
            }
            if ( function_exists( 'brikpanel_dashboard_widget_audience_allows' )
                && ! brikpanel_dashboard_widget_audience_allows( $widget_id ) ) {
                continue;
            }
            $chosen[ $widget_id ] = $widgets[ $widget_id ];
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
    /**
     * Order expense lines into the two-level list the card draws: every
     * top-level line followed immediately by the lines filed under it.
     *
     * Nothing is summed. The parent line keeps its own amount and the children
     * keep theirs, which is the whole point — filing one expense under another
     * is a visual convenience, not an accounting operation. The lines therefore
     * still add up to the Expenses figure exactly once.
     *
     * Titles are matched case-insensitively so "Marketing" and "marketing" are
     * the same parent, mirroring the picker's own de-duplication.
     *
     * A child whose parent is not itself a line in this window (an expense from
     * before this feature was filed under a plain grouping name, or a parent
     * dated outside the range) is not dropped: its parent name is emitted as a
     * label-only row with no amount, so the child is never orphaned or silently
     * promoted to top level.
     *
     * @param array<int,array{title:string,parent:string,row:array}> $flat Lines in display order.
     * @return array<int,array> Breakdown rows, `depth` 0 or 1.
     */
    private static function nest_expense_lines( array $flat ): array {
        $fold = static function ( $s ) {
            $s = trim( (string) $s );
            return brikpanel_strtolower( $s );
        };

        // Which folded titles exist as a top-level line here — the only things a
        // child can actually attach to.
        $tops = [];
        foreach ( $flat as $line ) {
            if ( '' === trim( (string) $line['parent'] ) ) {
                $tops[ $fold( $line['title'] ) ] = true;
            }
        }

        // Children bucketed under the parent they name; anything naming a
        // non-existent parent lands in $orphans under its raw stored spelling.
        $children = [];
        $orphans  = [];
        // Costs whose computed parent is absent this period; drawn flat, after
        // everything that did find its place.
        $stray    = [];
        foreach ( $flat as $line ) {
            $parent = trim( (string) $line['parent'] );
            if ( '' === $parent || $fold( $parent ) === $fold( $line['title'] ) ) {
                continue; // top level, or a legacy row filed under itself
            }
            $row = $line['row'];
            // Scope the Remove handle to this parent as STORED: two parents may
            // legitimately hold the same title ("Fees" under Marketing and under
            // Banking) and removing one must not take the other with it.
            if ( isset( $row['del']['type'] ) && 'cat' === $row['del']['type'] ) {
                $row['del']['group'] = $parent;
            }
            $row['depth'] = 1;
            if ( isset( $tops[ $fold( $parent ) ] ) ) {
                $children[ $fold( $parent ) ][] = $row;
                continue;
            }
            // A computed line the merchant filed this under is simply not on the
            // card this period: shipping costs switched off, or no tax / no ad
            // spend in the window. Draw the cost as an ordinary top-level line
            // rather than inventing a heading for a figure that is not there.
            // Its amount is in Net profit either way; only the grouping is lost.
            // A parent the merchant TYPED still gets the heading below, so an
            // expense filed under a name that no longer exists stays visible as
            // a group instead of quietly flattening.
            if ( class_exists( 'Brikpanel_Expenses' ) && Brikpanel_Expenses::is_builtin_parent( $parent ) ) {
                // Depth only. The `del` handle KEEPS its group: two costs can
                // share a title under different parents, and drawing one of them
                // flat must not turn its Remove control into "delete every row
                // called this". Only the indent is lost, never the scope.
                $row['depth'] = 0;
                $stray[]      = $row;
                continue;
            }
            $orphans[ $parent ][] = $row;
        }

        $out = [];
        foreach ( $flat as $line ) {
            if ( '' !== trim( (string) $line['parent'] ) && $fold( $line['parent'] ) !== $fold( $line['title'] ) ) {
                continue; // drawn under its parent below
            }
            $row          = $line['row'];
            $row['depth'] = 0;
            $out[]        = $row;
            $key          = $fold( $line['title'] );
            if ( ! empty( $children[ $key ] ) ) {
                foreach ( $children[ $key ] as $child ) {
                    $out[] = $child;
                }
                unset( $children[ $key ] );
            }
        }

        // Costs whose computed parent is not on the card this period.
        foreach ( $stray as $row ) {
            $out[] = $row;
        }

        // Label-only headers for parents that have no line of their own here.
        // `raw` 0 and no `amount` tell the browser to draw the name alone, so it
        // never contributes to the percentages.
        foreach ( $orphans as $name => $rows ) {
            $out[] = [
                'key'    => 'label',
                'label'  => (string) $name,
                'raw'    => 0.0,
                'depth'  => 0,
                'del'    => [ 'type' => 'group', 'group' => (string) $name ],
            ];
            foreach ( $rows as $child ) {
                $out[] = $child;
            }
        }

        return $out;
    }

    private function build_profit_block( $revenue, $start_gmt, $end_gmt, $start_local, $end_local, $exclude_marketplace = false ) {
        $s = brikpanel_profit_snapshot( $revenue, $start_gmt, $end_gmt, $start_local, $end_local, $exclude_marketplace );

        // Expenses breakdown. External costs (ad spend, tax) keep their fixed
        // translated labels; manual expenses are listed by their OWN category
        // (Salaries, Rent, Shipping carriers, …) instead of a single "Other"
        // lump, so owners see where the money actually went. The purchase-order
        // category is relabelled to the friendly "Supplier / stock"; a blank
        // category falls back to "Other".
        $fixed_labels = [
            'google_ads' => __( 'Google Ads', 'brikpanel' ),
            'meta_ads'   => __( 'Meta Ads', 'brikpanel' ),
            'tax'        => __( 'Tax', 'brikpanel' ),
        ];
        // Shipping is opt-in and gated on the setting HERE as well as in
        // brikpanel_profit_shipping_cost(). Not redundant: this whole payload is
        // served from a transient, so an amount computed while the feature was
        // on can outlive the toggle and would otherwise still draw a row the
        // merchant has switched off. Like Tax and ad spend it is computed, not a
        // stored expense row, so it renders without a Remove control.
        // Payment fees sit between Tax and Shipping cost, matching the order the
        // snapshot's `breakdown` declares. Gated on the same setting the
        // component itself checks, and for the same reason as shipping below.
        if ( function_exists( 'brikpanel_payment_fees_enabled' ) && brikpanel_payment_fees_enabled() ) {
            $fixed_labels['payment_fees'] = __( 'Payment fees', 'brikpanel' );
        }
        if ( function_exists( 'brikpanel_shipping_cost_enabled' ) && brikpanel_shipping_cost_enabled() ) {
            $fixed_labels['shipping'] = __( 'Shipping cost', 'brikpanel' );
        }
        // The computed lines go through the SAME nesting pass as the stored
        // expenses rather than being printed ahead of it, which is what lets a
        // merchant file "Bulky box" under "Shipping cost". Their `title` is the
        // stable key an expense's parent_category holds, never the translated
        // label, so the link survives a change of admin language. Order is
        // unchanged: nest_expense_lines() walks $flat in order and these are
        // first. No `del`, so they still render without a Remove control.
        $bp_key = class_exists( 'Brikpanel_Expenses' ) ? Brikpanel_Expenses::BUILTIN_PARENT_PREFIX : '__brikpanel:';
        $flat   = [];
        foreach ( $fixed_labels as $key => $label ) {
            $amount = (float) ( $s['breakdown'][ $key ] ?? 0 );
            if ( $amount <= 0 ) {
                continue; // hide empty components to keep the card clean
            }
            $flat[] = [
                'title'  => $bp_key . $key,
                'parent' => '',
                'row'    => [
                    'key'    => $key,
                    'label'  => $label,
                    'amount' => wc_price( $amount ),
                    'raw'    => $amount,
                ],
            ];
        }

        // `del` is an opaque handle the Remove control posts back untouched. It
        // carries the RAW category, never the label: "Other" and "Supplier /
        // stock" are display names, and the browser must not have to know how to
        // translate them back. Rows without a `del` key (ad spend, tax) are
        // computed from other sources and simply render no Remove control.
        $po_category = (string) get_option( 'brikpanel_po_expense_category', 'Inventory' );

        // Label for one stored title. Kept as a closure because both the flat
        // and the nested path below need exactly the same rules.
        $cat_label = function ( $cat ) use ( $po_category ) {
            if ( '' === $cat ) {
                return __( 'Other', 'brikpanel' );
            }
            if ( $cat === $po_category ) {
                return __( 'Supplier / stock', 'brikpanel' );
            }
            return $cat; // user-defined title, shown as stored
        };

        // Manual and percentage expenses, drawn as a two-level list: an expense
        // filed under another one renders indented directly beneath it.
        //
        // Nesting is presentation ONLY. Nothing is subtotalled or merged: every
        // line, parent or child, shows its own amount and its own share of
        // revenue, so the lines still sum to the Expenses figure exactly once.
        // A parent is simply an ordinary expense that other expenses name.
        //
        // `expense_lines` is empty on an install whose schema has not been
        // upgraded yet, in which case the old flat category map is the fallback.
        $lines = (array) ( $s['expense_lines'] ?? [] );

        // One entry per line to be drawn, in the order the card shows them:
        // the computed lines seeded above, then manual titles by amount desc,
        // then percentage and per-order costs — the order the card has always
        // used, so nesting reshuffles nothing. NOT reset here: the computed
        // lines are already in $flat and dropping them would take every
        // "filed under Shipping cost" grouping with them.
        if ( ! empty( $lines ) ) {
            foreach ( $lines as $line ) {
                $amount = (float) ( $line['amount'] ?? 0 );
                if ( $amount <= 0 ) {
                    continue;
                }
                $title = (string) ( $line['title'] ?? '' );
                $flat[] = [
                    'title'  => $title,
                    'parent' => (string) ( $line['parent'] ?? '' ),
                    'row'    => [
                        'key'    => 'cat',
                        'label'  => $cat_label( $title ),
                        'amount' => wc_price( $amount ),
                        'raw'    => $amount,
                        'del'    => [ 'type' => 'cat', 'cat' => $title ],
                    ],
                ];
            }
        } else {
            foreach ( (array) ( $s['expense_categories'] ?? [] ) as $cat => $amount ) {
                $amount = (float) $amount;
                if ( $amount <= 0 ) {
                    continue;
                }
                $flat[] = [
                    'title'  => (string) $cat,
                    'parent' => '',
                    'row'    => [
                        'key'    => 'cat',
                        'label'  => $cat_label( (string) $cat ),
                        'amount' => wc_price( $amount ),
                        'raw'    => $amount,
                        'del'    => [ 'type' => 'cat', 'cat' => (string) $cat ],
                    ],
                ];
            }
        }

        // Percentage-based costs (card commission etc.). The rate is shown in
        // the label so the figure is self-explanatory; the amount is that rate
        // applied to the period's revenue.
        foreach ( (array) ( $s['percent_expenses'] ?? [] ) as $pe ) {
            $amount = (float) ( $pe['amount'] ?? 0 );
            if ( $amount <= 0 ) {
                continue;
            }
            $pe_id = (int) ( $pe['id'] ?? 0 );
            if ( $pe_id <= 0 ) {
                continue; // no row to point at, never render a control with no target
            }
            $rate_str = rtrim( rtrim( number_format( (float) ( $pe['rate'] ?? 0 ), 2, '.', '' ), '0' ), '.' );
            $flat[] = [
                'title'  => (string) ( $pe['title'] ?? '' ),
                'parent' => (string) ( $pe['parent'] ?? '' ),
                'row'    => [
                    'key'    => 'percent',
                    'label'  => (string) ( $pe['title'] ?? '' ) . ' (' . $rate_str . '%)',
                    'amount' => wc_price( $amount ),
                    'raw'    => $amount,
                    'del'    => [ 'type' => 'percent', 'id' => $pe_id ],
                ],
            ];
        }

        // Per-order costs (packaging, a courier's flat fee on free-shipped
        // orders, a bulky surcharge). The label carries the unit price AND the
        // order count so the figure explains itself: 2.40 × 137 = 328.80 is
        // auditable at a glance, which the percentage line cannot manage because
        // revenue is not on the card. The scope is deliberately NOT in the
        // label, because it would roughly double the longest line on a ~260px card, and
        // scope_label is already in the payload if that changes.
        foreach ( (array) ( $s['per_order_expenses'] ?? [] ) as $po ) {
            $amount = (float) ( $po['amount'] ?? 0 );
            if ( $amount <= 0 ) {
                continue;
            }
            $po_id = (int) ( $po['id'] ?? 0 );
            if ( $po_id <= 0 ) {
                continue; // no row to point at, never render a control with no target
            }
            $po_orders = (int) ( $po['orders'] ?? 0 );
            // wc_price() returns HTML with entities (&pound;), and the browser
            // writes this label with textContent, so it MUST be decoded to plain
            // text here or the merchant reads "&pound;2.40". The `amount` key
            // below is a different story: that one is rendered with innerHTML, so
            // it keeps its markup exactly like every other row.
            $po_unit = html_entity_decode( wp_strip_all_tags( wc_price( (float) ( $po['unit'] ?? 0 ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            /* translators: 1: name of the cost, 2: money amount charged per order, 3: number of orders it was charged on. */
            $po_label = sprintf(
                _n( '%1$s (%2$s × %3$d order)', '%1$s (%2$s × %3$d orders)', $po_orders, 'brikpanel' ),
                (string) ( $po['title'] ?? '' ),
                $po_unit,
                $po_orders
            );
            $flat[] = [
                'title'  => (string) ( $po['title'] ?? '' ),
                'parent' => (string) ( $po['parent'] ?? '' ),
                'row'    => [
                    'key'    => 'per_order',
                    'label'  => $po_label,
                    'amount' => wc_price( $amount ),
                    'raw'    => $amount,
                    'del'    => [ 'type' => 'per_order', 'id' => $po_id ],
                ],
            ];
        }

        $breakdown = self::nest_expense_lines( $flat );

        // Per-field display preferences (BrikPanel ▸ Settings ▸ Dashboard).
        // Returns is the only toggle that also changes the math: because it
        // nets the Revenue top line (which must reconcile with Total Sales),
        // hiding it would otherwise leave an unexplained gap, so turning it off
        // means "ignore returns entirely". Cost of goods and Expenses always
        // feed Net profit (they only affect the Net card, never the shared
        // Revenue line); their toggle is display-only so the Net figure can
        // never be quietly overstated by hiding a card.
        $returns_on  = ! function_exists( 'brikpanel_dashboard_profit_field_enabled' ) || brikpanel_dashboard_profit_field_enabled( 'returns' );
        $coupons_on  = function_exists( 'brikpanel_dashboard_profit_field_enabled' ) && brikpanel_dashboard_profit_field_enabled( 'coupons' );

        $gross    = (float) $s['revenue_raw'];
        $returns  = (float) $s['returns_raw'];
        $coupons  = (float) $s['coupons_raw'];
        $cogs     = (float) $s['cogs_raw'];
        $expenses = (float) $s['expenses_total_raw'];

        $rev_raw = $returns_on ? ( $gross - $returns ) : $gross; // figure shown on the Revenue card
        $net_raw = $rev_raw - $cogs - $expenses;

        $pctf       = function ( $part ) use ( $rev_raw ) {
            return $rev_raw > 0 ? round( ( $part / $rev_raw ) * 100, 1 ) : 0.0;
        };
        $cogs_pct     = $pctf( $cogs );
        $expenses_pct = $pctf( $expenses );
        $margin       = $pctf( $net_raw );

        // Revenue breakdown: Gross − Returns = the shown Revenue, with Coupons
        // as an informational line (the discount is already inside the order
        // totals, so it is never subtracted again). Only surfaced when there is
        // something to show, so a clean store keeps the card minimal. `type`
        // drives how the JS renders the sign: base / deduct / info.
        $rev_breakdown = [];
        if ( ( $returns_on && $returns > 0 ) || ( $coupons_on && $coupons > 0 ) ) {
            $rev_breakdown[] = [
                'key'    => 'gross',
                'label'  => __( 'Gross sales', 'brikpanel' ),
                'amount' => wc_price( $gross ),
                'raw'    => $gross,
                'type'   => 'base',
            ];
            if ( $returns_on && $returns > 0 ) {
                $rev_breakdown[] = [
                    'key'    => 'returns',
                    'label'  => __( 'Returns', 'brikpanel' ),
                    'amount' => wc_price( $returns ),
                    'raw'    => $returns,
                    'type'   => 'deduct',
                ];
            }
            if ( $coupons_on && $coupons > 0 ) {
                $rev_breakdown[] = [
                    'key'    => 'coupons',
                    'label'  => __( 'Coupons (already in totals)', 'brikpanel' ),
                    'amount' => wc_price( $coupons ),
                    'raw'    => $coupons,
                    'type'   => 'info',
                ];
            }
        }

        return [
            'revenue'       => wc_price( $rev_raw ),
            'revenue_raw'   => $rev_raw,
            'gross_revenue' => wc_price( $gross ),
            'gross_revenue_raw' => $gross,
            'returns'       => wc_price( $returns ),
            'returns_raw'   => $returns,
            'returns_on'    => $returns_on,
            'coupons'       => wc_price( $coupons ),
            'coupons_raw'   => $coupons,
            'revenue_breakdown' => $rev_breakdown,
            'cogs'          => wc_price( $cogs ),
            'cogs_raw'      => $cogs,
            'cogs_pct'      => $cogs_pct,
            'has_cogs'      => $s['has_cogs'],
            'cogs_incomplete'    => $s['cogs_incomplete'],
            'cogs_missing_lines' => $s['cogs_missing_lines'],
            'cogs_coverage_pct'  => $s['cogs_coverage_pct'],
            'cogs_missing_products' => $s['cogs_missing_products'] ?? [],
            'expenses'      => wc_price( $expenses ),
            'expenses_raw'  => $expenses,
            'expenses_pct'  => $expenses_pct,
            // A component of `expenses_raw`, not an extra deduction. Surfaced
            // on its own so the Excel export can spell it out.
            'shipping_cost_raw' => (float) ( $s['shipping_cost_raw'] ?? 0 ),
            // Likewise a component of `expenses_raw`, surfaced for the same reason.
            'per_order_total_raw' => (float) ( $s['per_order_total_raw'] ?? 0 ),
            // Same again for the real gateway fees, plus the two data-quality
            // counts behind them. `missing` is expected and harmless (an order
            // paid by bank transfer has no processor and no fee); `unconverted`
            // means fees exist that could NOT be converted to the store currency
            // and are therefore absent from the total, so the figure understates.
            'payment_fees_raw'          => (float) ( $s['payment_fees_raw'] ?? 0 ),
            'payment_fees_missing'      => (int) ( $s['payment_fees_missing'] ?? 0 ),
            'payment_fees_unconverted'  => (int) ( $s['payment_fees_unconverted'] ?? 0 ),
            'payment_fees_coverage_pct' => (float) ( $s['payment_fees_coverage_pct'] ?? 0 ),
            'breakdown'     => $breakdown,
            // The exact window these lines were summed over, echoed back by the
            // Remove control. Recomputing "last 30 days" in the browser could
            // land on a different day, and the selected range may have changed
            // between rendering a line and clicking it.
            'window'        => [
                'from' => substr( (string) $start_local, 0, 10 ),
                'to'   => substr( (string) $end_local, 0, 10 ),
            ],
            'net'           => wc_price( $net_raw ),
            'net_raw'       => $net_raw,
            'margin'        => $margin,
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

        // Roll any recurring-expense templates forward to today before the
        // payload (and its cache) are built, so the Profit section reflects the
        // current period's costs. Runs at most a few times a day (transient
        // gated) and busts the dashboard cache itself when it adds rows.
        if ( class_exists( 'Brikpanel_Expenses' ) ) {
            Brikpanel_Expenses::materialize_due();
        }

        $range = isset( $_POST['range'] ) ? sanitize_key( $_POST['range'] ) : 'today';

        // A custom range is identified by its start/end dates, not just the
        // literal "custom". Validate them up front so they can both seed the
        // cache key and be passed explicitly to the payload builder.
        $custom_start = null;
        $custom_end   = null;
        if ( 'custom' === $range ) {
            $cs = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
            $ce = isset( $_POST['end_date'] )   ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) )   : '';
            $custom_start = self::is_valid_ymd( $cs ) ? $cs : null;
            $custom_end   = self::is_valid_ymd( $ce ) ? $ce : null;
        }

        // Remember the selection so a refresh, or a trip to another screen and
        // back, returns to this period instead of snapping to "Today". Written
        // BEFORE the cache check below, because a cached payload short-circuits
        // the rest of this method and the preference must be captured either way.
        self::save_range_preference( $range, $custom_start, $custom_end );

        // Whole-response cache. The dashboard runs ~28 SQL queries per render
        // (KPIs + funnel + 4 chart sections + LTV + RFM + locations + …), so on
        // weak hosts the cold render can take 5-15s. With caching, only the
        // first hit pays that price; range toggles, navigation back-and-forth,
        // and other admins on the same store all serve from the transient.
        // Cache busts automatically on new orders / status changes (registered
        // in __construct), so freshly-placed orders show up immediately.
        $cache_ver = brikpanel_data_cache_ver();
        $exclude_mp_for_key = ( function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active() ) ? 1 : 0;
        // The custom dates MUST be part of the cache identity. Without them every
        // custom range collides on a single transient, so a second selection
        // (e.g. a narrow range picked right after a wide one) serves the first
        // selection's cached payload and the dashboard appears stuck.
        $range_key = ( 'custom' === $range )
            ? 'custom_' . (string) $custom_start . '_' . (string) $custom_end
            : $range;
        // The shipping-cost setting changes Net profit, Expenses and the margin,
        // so it belongs in the cache identity. Its update_option hook busts the
        // caches, but only the Analytics settings section ever posts it — a
        // WP-CLI write, an importer or another section's save would leave a
        // payload from the opposite setting in place for the full TTL, which
        // reads as "the setting does nothing".
        $shipping_for_key = ( function_exists( 'brikpanel_shipping_cost_enabled' ) && brikpanel_shipping_cost_enabled() ) ? 1 : 0;
        // The payment-fees toggle moves the same three figures, so it earns a
        // segment of its own for exactly the reason spelled out above.
        $fees_for_key = ( function_exists( 'brikpanel_payment_fees_enabled' ) && brikpanel_payment_fees_enabled() ) ? 1 : 0;
        $cache_key = 'bp_dash_' . $cache_ver . '_' . $range_key . '_mp' . $exclude_mp_for_key . '_sc' . $shipping_for_key . '_pf' . $fees_for_key;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $cached );
        }

        $payload = $this->build_dashboard_payload( $range, $custom_start, $custom_end );

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

        // When BrikMarket is active, the traffic-funnel KPIs (orders, AOV,
        // conversion, visitors) stay ON-SITE only — marketplace-imported orders
        // have no on-site visit, so counting them would distort the conversion
        // rate and per-visitor averages. The HEADLINE FINANCIALS are the
        // opposite: merchants want their marketplace turnover reflected in the
        // money figures, so Revenue / Total Sales / Cost of goods / Net profit
        // COUNT marketplace orders (combined site + marketplace view).
        $exclude_mp = function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active();

        // --- Current period data ---
        // Site-only revenue: feeds the conversion funnel and the marketplace
        // breakdown card (which adds marketplace revenue on top to show the
        // combined split — passing the combined figure there would double-count).
        $site_sales    = brikpanel_get_total_revenue( $start_gmt, $end_gmt, $exclude_mp );
        // Headline revenue: combined site + marketplace. The Gelir / Total Sales
        // card and the Profit section both read this. When BrikMarket is
        // inactive $exclude_mp is false, so this equals $site_sales and nothing
        // changes for single-channel stores.
        $total_sales   = brikpanel_get_total_revenue( $start_gmt, $end_gmt, false );
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

        // Traffic sources (uses local dates for the brikpanel_referrers table):
        // channel breakdown bars + top external referrers detail list.
        $sources       = $this->get_traffic_source_breakdown( $start_local, $end_local );
        $top_referrers = $this->get_top_referrers( $start_local, $end_local );

        // New vs repeat customer breakdown (uses WC analytics table, UTC dates)
        $customer_types = $this->get_customer_type_breakdown( $start_gmt, $end_gmt );

        // Subscription status distribution (date-independent — always current)
        $subscription_stats = $this->get_subscription_stats();

        // Low stock (always current, date-independent)
        $low_stock = $this->get_low_stock_products();

        // Returns & refunds (date-dependent)
        $return_count = brikpanel_get_order_count_by_status( array_values( array_unique( array_merge( brikpanel_refunded_order_statuses(), [ 'wc-return-draft' ] ) ) ), $start_gmt, $end_gmt, $exclude_mp );
        $total_orders = brikpanel_get_total_orders_count( $start_gmt, $end_gmt, $exclude_mp );
        $return_rate  = $total_orders > 0 ? round( ( $return_count / $total_orders ) * 100, 1 ) : 0;
        $returns_data = [
            'count' => $return_count,
            'total' => $total_orders,
            'rate'  => $return_rate,
        ];

        // Marketplace analytics (BrikMarket only)
        // Pass the SITE-only revenue: get_marketplace_analytics adds marketplace
        // revenue on top to compute the combined split, so handing it the
        // already-combined headline figure would double-count marketplace.
        $marketplace_analytics = $exclude_mp
            ? $this->get_marketplace_analytics( $start_gmt, $end_gmt, $prev_start_gmt, $prev_end_gmt, $site_sales )
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
        // Combined (site + marketplace) to match the current-period headline, so
        // the Total Sales / Net profit deltas compare like with like.
        $prev_total_sales   = brikpanel_get_total_revenue( $prev_start_gmt, $prev_end_gmt, false );
        $prev_order_count   = brikpanel_get_order_count( $prev_start_gmt, $prev_end_gmt, $exclude_mp );
        $prev_aov           = brikpanel_get_average_order_value( $prev_start_gmt, $prev_end_gmt, $exclude_mp );
        $prev_visitor_count = brikpanel_get_visitor_count( $prev_start_local, $prev_end_local );
        $prev_conversion    = $prev_visitor_count > 0 ? round( ( $prev_order_count / $prev_visitor_count ) * 100, 2 ) : 0;

        // Profit: Revenue − Cost of goods − Expenses, for the current and the
        // previous comparison period. Standalone — never depends on any ad
        // platform being connected.
        // Combined basis (exclude_marketplace = false): revenue already includes
        // marketplace, so Cost of goods / tax / returns must too, otherwise the
        // headline revenue would be netted against a site-only cost and the
        // margin would be wrong. This is what makes the "permanent loss after
        // entering costs" symptom go away on marketplace stores.
        $profit_curr = $this->build_profit_block( $total_sales, $start_gmt, $end_gmt, $start_local, $end_local, false );
        $profit_prev = $this->build_profit_block( $prev_total_sales, $prev_start_gmt, $prev_end_gmt, $prev_start_local, $prev_end_local, false );
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
            'sources'          => $sources,
            'top_referrers'    => $top_referrers,
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

            // N-1, not N: the window ENDS at the end of today, so today is already
            // one of the N days. Going back a full N landed on N+1 calendar days
            // ("Last 7 Days" covered 8), which both inflated every total and
            // compared an 8-day current window against the 7-day previous one
            // built from $days_span below, biasing every delta on the page.
            case '7days':
                $start_local = wp_date( 'Y-m-d 00:00:00', strtotime( '-6 days', $now_ts ) );
                $end_local   = wp_date( 'Y-m-d 23:59:59' );
                $days_span   = 7;
                break;

            case '30days':
                $start_local = wp_date( 'Y-m-d 00:00:00', strtotime( '-29 days', $now_ts ) );
                $end_local   = wp_date( 'Y-m-d 23:59:59' );
                $days_span   = 30;
                break;

            case '90days':
                $start_local = wp_date( 'Y-m-d 00:00:00', strtotime( '-89 days', $now_ts ) );
                $end_local   = wp_date( 'Y-m-d 23:59:59' );
                $days_span   = 90;
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
                // Last line of defence for the date math. A value that looks
                // like a date but is not one ("2026-13-45") makes strtotime()
                // return false, which the arithmetic below reads as the epoch
                // which means a decades-wide scan of every order in the store. Callers
                // already validate what they pass in; this also covers the
                // legacy $_POST fallback directly above.
                if ( ! self::is_valid_ymd( $start_str ) ) {
                    $start_str = wp_date( 'Y-m-d' );
                }
                if ( ! self::is_valid_ymd( $end_str ) ) {
                    $end_str = wp_date( 'Y-m-d' );
                }
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
            '90days'    => __( 'Last 90 Days', 'brikpanel' ),
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
        // Returns + refunds combined — the merchant's configured refund bucket
        // (defaults to WooCommerce's native 'wc-refunded') plus the BrikPanel
        // custom 'wc-return-draft' status. Mirrors the figure that used to be
        // surfaced in the dedicated Returns & Refunds panel.
        $refunded   = brikpanel_get_order_count_by_status( array_values( array_unique( array_merge( brikpanel_refunded_order_statuses(), [ 'wc-return-draft' ] ) ) ), $start_gmt, $end_gmt, $exclude_marketplace );
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

        $include_statuses    = brikpanel_paid_order_statuses();
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

    /**
     * Number of rows pulled before resolving titles.
     *
     * Both "most" cards drop any row whose post or product no longer resolves
     * (deleted, or moved to the trash). Selecting exactly five and then
     * filtering meant a card could show three lines and read as "that is all
     * the traffic there was". Over-fetching and trimming afterwards keeps the
     * list full whenever there is enough surviving data to fill it.
     */
    const MOST_CARD_FETCH = 40;

    private function get_most_viewed( $start_local, $end_local ) {
        global $wpdb;
        $table = $wpdb->prefix . 'brikpanel_visited_pages';

        $start_dt = $start_local . ' 00:00:00';
        $end_dt   = $end_local . ' 23:59:59';

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT page_id, object_type, SUM(visit_count) AS total_views
             FROM {$table}
             WHERE date_column >= %s AND date_column <= %s
             GROUP BY page_id, object_type
             ORDER BY total_views DESC LIMIT %d",
            $start_dt,
            $end_dt,
            self::MOST_CARD_FETCH
        ) );

        if ( empty( $results ) ) {
            return [];
        }

        // Rows written before 3.2.41 have no object_type of their own and take
        // the column default, so anything unrecognised is treated as a post.
        $post_ids = [];
        foreach ( $results as $row ) {
            if ( 'term' !== $row->object_type ) {
                $post_ids[] = (int) $row->page_id;
            }
        }
        if ( $post_ids ) {
            _prime_post_caches( $post_ids, false, false );
        }

        $data = [];
        foreach ( $results as $row ) {
            if ( count( $data ) >= 5 ) {
                break;
            }

            $id = (int) $row->page_id;

            if ( 'term' === $row->object_type ) {
                $term = get_term( $id );
                if ( ! $term || is_wp_error( $term ) ) {
                    continue;
                }
                $title = $term->name;
                $link  = get_term_link( $term );
                $url   = is_wp_error( $link ) ? '' : $link;
            } else {
                $title = get_the_title( $id );
                if ( ! $title ) {
                    continue;
                }
                $permalink = get_permalink( $id );
                $url       = $permalink ? $permalink : '';
            }

            $data[] = [
                'title' => $title,
                'views' => (int) $row->total_views,
                'id'    => $id,
                'url'   => $url,
            ];
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
             ORDER BY total_count DESC LIMIT %d",
            $start_dt,
            $end_dt,
            self::MOST_CARD_FETCH
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
            if ( count( $data ) >= 5 ) {
                break;
            }
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

        $include_statuses    = brikpanel_paid_order_statuses();
        $status_placeholders = implode( ', ', array_fill( 0, count( $include_statuses ), '%s' ) );

        $is_hpos   = $this->is_hpos();
        $exclusion = brikpanel_admin_order_exclusion_sql( $is_hpos, 'p.ID' );
        $mp_excl   = $exclude_marketplace
            ? brikpanel_marketplace_order_exclusion_sql( $is_hpos, $is_hpos ? 'id' : 'p.ID' )
            : [ 'sql' => '', 'args' => [] ];

        // The stored column is UTC but the selected window is a LOCAL day range,
        // so grouping by DATE(date_created_gmt) splits one local day across two
        // UTC dates: a single-day selection drew TWO points, the first stamped
        // with yesterday. The KPI cards were right all along — only these labels
        // were wrong — which reads as "the card is showing two days of data".
        //
        // MySQL cannot do the conversion for us: CONVERT_TZ() with a named zone
        // needs the mysql.time_zone_* tables, which are empty on most hosts and
        // make it return NULL — that would blank the chart instead of shifting
        // it. A single fixed offset would be wrong across a DST change.
        //
        // So bucket at 15 minutes here (pure string formatting of the stored UTC
        // value, no timezone involved) and fold those buckets into local days in
        // PHP below. Every real UTC offset is a whole multiple of 15 minutes, so
        // a bucket can never straddle a local midnight — this is exact for
        // whole-hour, half-hour (+05:30) and quarter-hour (+05:45) zones alike,
        // and DST-correct because each bucket is converted on its own timestamp.
        //
        // The %% are deliberate: these strings go through $wpdb->prepare(), which
        // consumes a single % as a placeholder and would mangle DATE_FORMAT's
        // %Y/%m/%d/%H into nothing.
        $bucket_hpos   = "CONCAT(DATE_FORMAT(date_created_gmt, '%%Y-%%m-%%d %%H:'), LPAD(FLOOR(MINUTE(date_created_gmt)/15)*15, 2, '0'))";
        $bucket_legacy = "CONCAT(DATE_FORMAT(p.post_date_gmt, '%%Y-%%m-%%d %%H:'), LPAD(FLOOR(MINUTE(p.post_date_gmt)/15)*15, 2, '0'))";

        if ( $is_hpos ) {
            $admin_sql  = $exclusion['sql'];
            $fx         = brikpanel_base_total_sql( true, "{$wpdb->prefix}wc_orders.id", 'total_amount' );
            $query_args = array_merge( $include_statuses, $exclusion['args'], $mp_excl['args'], [ $start_gmt, $end_gmt ] );
            $query = $wpdb->prepare(
                "SELECT {$bucket_hpos} AS bucket_utc,
                        SUM({$fx['expr']}) AS revenue,
                        COUNT({$wpdb->prefix}wc_orders.id) AS orders
                 FROM {$wpdb->prefix}wc_orders{$fx['join']}
                 WHERE type = 'shop_order'
                 AND status IN ({$status_placeholders}){$admin_sql}{$mp_excl['sql']}
                 AND date_created_gmt >= %s AND date_created_gmt <= %s
                 GROUP BY bucket_utc
                 ORDER BY bucket_utc ASC",
                $query_args
            );
        } else {
            $fx         = brikpanel_base_total_sql( false, 'p.ID', 'pm.meta_value' );
            $query_args = array_merge( $include_statuses, $exclusion['args'], $mp_excl['args'], [ $start_gmt, $end_gmt ] );
            $query = $wpdb->prepare(
                "SELECT {$bucket_legacy} AS bucket_utc,
                        SUM({$fx['expr']}) AS revenue,
                        COUNT(p.ID) AS orders
                 FROM {$wpdb->posts} AS p
                 LEFT JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id{$fx['join']}
                 WHERE p.post_type = 'shop_order'
                 AND pm.meta_key = '_order_total'
                 AND p.post_status IN ({$status_placeholders}){$exclusion['sql']}{$mp_excl['sql']}
                 AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s
                 GROUP BY bucket_utc
                 ORDER BY bucket_utc ASC",
                $query_args
            );
        }

        $results = $wpdb->get_results( $query );

        $utc   = new DateTimeZone( 'UTC' );
        $local = wp_timezone();
        $by_day = [];
        foreach ( $results as $row ) {
            try {
                $when = new DateTimeImmutable( (string) $row->bucket_utc, $utc );
            } catch ( Exception $e ) {
                continue; // unparseable stamp: drop the bucket rather than the chart
            }
            $day = $when->setTimezone( $local )->format( 'Y-m-d' );
            if ( ! isset( $by_day[ $day ] ) ) {
                $by_day[ $day ] = [ 'revenue' => 0.0, 'orders' => 0 ];
            }
            $by_day[ $day ]['revenue'] += (float) $row->revenue;
            $by_day[ $day ]['orders']  += (int) $row->orders;
        }
        ksort( $by_day ); // Y-m-d sorts chronologically as a string

        $data = [];
        foreach ( $by_day as $day => $totals ) {
            $data[] = [
                'date'    => $day,
                'revenue' => $totals['revenue'],
                'orders'  => $totals['orders'],
            ];
        }
        return $data;
    }

    // =========================================================================
    // ORDER LOCATIONS (countries + cities for globe)
    // =========================================================================

    private function get_order_locations( $start_gmt, $end_gmt, $exclude_marketplace = false ) {
        global $wpdb;

        $include_statuses    = brikpanel_paid_order_statuses();
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
            $fx_loc = brikpanel_base_total_sql( true, 'o.id', 'o.total_amount', 'bpfxloc' );
            $country_query = $wpdb->prepare(
                "SELECT ba.country AS code,
                        COUNT(DISTINCT o.id) AS order_count,
                        {$customer_count_expr} AS customer_count,
                        COALESCE(SUM({$fx_loc['expr']}), 0) AS total_sales
                 FROM {$wpdb->prefix}wc_orders o
                 LEFT JOIN {$wpdb->prefix}wc_order_addresses ba ON o.id = ba.order_id AND ba.address_type = 'billing'{$fx_loc['join']}
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
        $include_statuses    = brikpanel_paid_order_statuses();
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
            // Convert each marketplace order to the store base currency before
            // summing, exactly like the headline Revenue KPI does, so the
            // marketplace card and the combined headline reconcile on a
            // multi-currency store (raw total_amount would mix currencies).
            $fx         = brikpanel_base_total_sql( true, 'o.id', 'o.total_amount', 'bpfx' );
            $args       = array_merge( [ $meta_key ], $include_statuses, $exclusion['args'], [ $start_gmt, $end_gmt ] );
            $sql        = $wpdb->prepare(
                "SELECT om.meta_value AS marketplace_id,
                        COUNT(o.id)          AS orders,
                        SUM({$fx['expr']})   AS revenue
                 FROM {$wpdb->prefix}wc_orders o
                 INNER JOIN {$wpdb->prefix}wc_orders_meta om
                     ON o.id = om.order_id AND om.meta_key = %s{$fx['join']}
                 WHERE o.type = 'shop_order'
                 AND o.status IN ({$status_placeholders}){$admin_sql}
                 AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
                 GROUP BY om.meta_value",
                $args
            );
        } else {
            $fx   = brikpanel_base_total_sql( false, 'p.ID', 'pm_total.meta_value', 'bpfx' );
            $args = array_merge( [ $meta_key ], $include_statuses, $exclusion['args'], [ $start_gmt, $end_gmt ] );
            $sql  = $wpdb->prepare(
                "SELECT pm.meta_value AS marketplace_id,
                        COUNT(p.ID)        AS orders,
                        SUM(CAST({$fx['expr']} AS DECIMAL(20,6))) AS revenue
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm
                     ON p.ID = pm.post_id AND pm.meta_key = %s
                 LEFT JOIN {$wpdb->postmeta} pm_total
                     ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'{$fx['join']}
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

            // Show each order in the currency it was actually placed in, not
            // the store base currency. When the order is in a foreign currency
            // and we can resolve a base-currency value, expose it as a small
            // secondary figure (~ base) so the merchant can still compare.
            $order_currency = $order->get_currency();
            $base_currency  = brikpanel_base_currency();
            $total_base     = null;
            if ( strtoupper( (string) $order_currency ) !== strtoupper( (string) $base_currency ) ) {
                $resolved = brikpanel_resolve_order_base_total( $order );
                if ( null !== $resolved['base_total'] ) {
                    $total_base = wc_price( $resolved['base_total'] );
                }
            }

            $data[] = [
                'id'         => $order->get_id(),
                'customer'   => $customer,
                'status'     => $order->get_status(),
                'total'      => wc_price( $order->get_total(), [ 'currency' => $order_currency ] ),
                'total_base' => $total_base,
                'date'       => wp_date( get_option( 'date_format' ), $order->get_date_created()->getTimestamp() ),
                'source'     => $source,
                'edit_url'   => $order->get_edit_order_url(),
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

        $range = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'today';
        if ( ! in_array( $range, self::allowed_ranges(), true ) ) {
            $range = 'today';
        }
        $custom_start = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : null;
        $custom_end   = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : null;
        // Reject anything that isn't a real Y-m-d calendar date so it can't
        // reach the date math as garbage.
        if ( $range === 'custom' && ( ! self::is_valid_ymd( $custom_start ) || ! self::is_valid_ymd( $custom_end ) ) ) {
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
            // Called out separately because it is already inside Expenses above:
            // without this line the total simply grows with no visible reason.
            // Omitted entirely on stores that never enabled shipping costs, so
            // the sheet does not carry a permanent zero row.
            ...( ( (float) ( $profit['shipping_cost_raw'] ?? 0 ) ) > 0
                ? [ [ __( 'of which Shipping Cost', 'brikpanel' ), $money( $profit['shipping_cost_raw'] ), __( 'Included in Expenses', 'brikpanel' ) ] ]
                : [] ),
            // Same reasoning: already inside Expenses, omitted when unused.
            ...( ( (float) ( $profit['per_order_total_raw'] ?? 0 ) ) > 0
                ? [ [ __( 'of which Cost per order', 'brikpanel' ), $money( $profit['per_order_total_raw'] ), __( 'Included in Expenses', 'brikpanel' ) ] ]
                : [] ),
            // Same reasoning again. Absent on stores whose gateway records no
            // fee, rather than sitting there as a permanent zero.
            ...( ( (float) ( $profit['payment_fees_raw'] ?? 0 ) ) > 0
                ? [ [ __( 'of which Payment fees', 'brikpanel' ), $money( $profit['payment_fees_raw'] ), __( 'Included in Expenses', 'brikpanel' ) ] ]
                : [] ),
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
