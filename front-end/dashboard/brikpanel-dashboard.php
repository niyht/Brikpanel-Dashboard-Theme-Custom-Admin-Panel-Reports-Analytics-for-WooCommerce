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

class Brikpanel_Dashboard {

    private $is_hpos = null;

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
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=brikpanel-dashboard' ) );
        exit;
    }

    // =========================================================================
    // RENDER PAGE
    // =========================================================================

    public function render_page() {
        ?>
        <div id="brikpanel-dashboard" class="brikpanel-dashboard">
            <?php wp_nonce_field( 'brikpanel_dashboard_nonce', 'security' ); ?>

            <!-- Header -->
            <div class="brikpanel-dash-header">
                <h1><?php esc_html_e( 'Dashboard', 'brikpanel' ); ?></h1>
                <div class="brikpanel-dash-filters">
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

            <!-- Row: Order Locations Globe + Tables -->
            <div class="brikpanel-dash-row brikpanel-dash-row-2-1">
                <div class="brikpanel-dash-panel brikpanel-dash-globe-panel" id="globe-panel">
                    <div class="brikpanel-dash-globe-header">
                        <h2><?php esc_html_e( 'Order Locations', 'brikpanel' ); ?></h2>
                        <button class="brikpanel-dash-globe-theme-btn" id="globe-theme-toggle" type="button" title="<?php esc_attr_e( 'Toggle theme', 'brikpanel' ); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                        </button>
                    </div>
                    <div class="brikpanel-dash-globe-wrap" id="globe-container">
                        <canvas id="brikpanel-globe"></canvas>
                    </div>
                </div>
                <div class="brikpanel-dash-panel brikpanel-dash-locations-panel">
                    <h2><?php esc_html_e( 'Top Countries', 'brikpanel' ); ?></h2>
                    <div class="brikpanel-dash-table-wrap" id="top-countries-table">
                        <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                    </div>
                    <h2 class="brikpanel-dash-locations-h2"><?php esc_html_e( 'Top Cities', 'brikpanel' ); ?></h2>
                    <div class="brikpanel-dash-table-wrap" id="top-cities-table">
                        <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                    </div>
                </div>
            </div>

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

        </div>
        <?php
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

        // Calculate date ranges (local + GMT)
        $dates     = $this->calculate_dates( $range );
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

        // --- Current period data ---
        $total_sales   = brikpanel_get_total_revenue( $start_gmt, $end_gmt );
        $order_count   = brikpanel_get_order_count( $start_gmt, $end_gmt );
        $aov           = brikpanel_get_average_order_value( $start_gmt, $end_gmt );
        $visitor_count = brikpanel_get_visitor_count( $start_local, $end_local );
        $conversion    = $visitor_count > 0 ? round( ( $order_count / $visitor_count ) * 100, 2 ) : 0;

        // Funnel data (uses local dates for brikpanel_visitors table)
        $product_views  = brikpanel_get_product_view_count( $start_local, $end_local );
        $add_to_cart    = brikpanel_get_add_to_cart_count( $start_local, $end_local );
        $checkout_count = brikpanel_get_checkout_count( $start_local, $end_local );

        // Order rates
        $order_rates = $this->get_order_rates( $start_gmt, $end_gmt );

        // Top products, most viewed, most cart
        $top_products = $this->get_top_products( $start_gmt, $end_gmt );
        $most_viewed  = $this->get_most_viewed( $start_local, $end_local );
        $most_cart    = $this->get_most_cart( $start_local, $end_local );

        // Sales over time
        $sales_over_time = $this->get_sales_over_time( $start_gmt, $end_gmt );

        // Recent orders
        $recent_orders = $this->get_recent_orders();

        // Order locations (for globe)
        $order_locations = $this->get_order_locations( $start_gmt, $end_gmt );

        // --- Previous period data (for deltas) ---
        $prev_total_sales   = brikpanel_get_total_revenue( $prev_start_gmt, $prev_end_gmt );
        $prev_order_count   = brikpanel_get_order_count( $prev_start_gmt, $prev_end_gmt );
        $prev_aov           = brikpanel_get_average_order_value( $prev_start_gmt, $prev_end_gmt );
        $prev_visitor_count = brikpanel_get_visitor_count( $prev_start_local, $prev_end_local );
        $prev_conversion    = $prev_visitor_count > 0 ? round( ( $prev_order_count / $prev_visitor_count ) * 100, 2 ) : 0;

        $deltas = [
            'sales'      => $this->calc_delta( $total_sales, $prev_total_sales ),
            'orders'     => $this->calc_delta( $order_count, $prev_order_count ),
            'aov'        => $this->calc_delta( $aov, $prev_aov ),
            'visitors'   => $this->calc_delta( $visitor_count, $prev_visitor_count ),
            'conversion' => $this->calc_delta( $conversion, $prev_conversion ),
        ];

        wp_send_json_success( [
            'total_sales'      => wc_price( $total_sales ),
            'total_sales_raw'  => $total_sales,
            'order_count'      => $order_count,
            'aov'              => wc_price( $aov ),
            'aov_raw'          => $aov,
            'visitor_count'    => $visitor_count,
            'conversion_rate'  => $conversion,
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
            'deltas'           => $deltas,
        ] );
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

    private function calculate_dates( $range ) {
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
                $start_str   = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : wp_date( 'Y-m-d' );
                $end_str     = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : wp_date( 'Y-m-d' );
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
            'prev'        => [
                'start_gmt'   => $prev_start_gmt,
                'end_gmt'     => $prev_end_gmt,
                'start_local' => $prev_start_local_date,
                'end_local'   => $prev_end_local_date,
            ],
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
            return 100;
        }
        return round( ( ( $current - $previous ) / $previous ) * 100, 1 );
    }

    // =========================================================================
    // ORDER RATES
    // =========================================================================

    private function get_order_rates( $start_gmt, $end_gmt ) {
        $total = brikpanel_get_total_orders_count( $start_gmt, $end_gmt );

        if ( $total === 0 ) {
            return [
                'successful' => 0,
                'failed'     => 0,
                'refunded'   => 0,
                'cancelled'  => 0,
                'total'      => 0,
            ];
        }

        $successful = brikpanel_get_successful_order_count( $start_gmt, $end_gmt );
        $failed     = brikpanel_get_order_count_by_status( [ 'wc-failed' ], $start_gmt, $end_gmt );
        $refunded   = brikpanel_get_order_count_by_status( [ 'wc-refunded' ], $start_gmt, $end_gmt );
        $cancelled  = brikpanel_get_order_count_by_status( [ 'wc-cancelled' ], $start_gmt, $end_gmt );

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

    private function get_top_products( $start_gmt, $end_gmt ) {
        global $wpdb;

        $include_statuses    = [ 'wc-processing', 'wc-completed' ];
        $status_placeholders = implode( ', ', array_fill( 0, count( $include_statuses ), '%s' ) );
        $query_args          = $include_statuses;

        // Exclude orders placed by admin users
        $is_hpos   = $this->is_hpos();
        $exclusion = brikpanel_admin_order_exclusion_sql( $is_hpos, 'p.ID' );

        if ( $is_hpos ) {
            $admin_sql = str_replace( 'customer_id', 'o.customer_id', $exclusion['sql'] );
            $query_args = array_merge( $query_args, $exclusion['args'], [ $start_gmt, $end_gmt ] );
            $query = $wpdb->prepare(
                "SELECT p.product_id, SUM(p.product_qty) AS total_sold
                 FROM {$wpdb->prefix}wc_order_product_lookup p
                 INNER JOIN {$wpdb->prefix}wc_orders o ON p.order_id = o.id
                 WHERE o.status IN ({$status_placeholders}){$admin_sql}
                 AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
                 GROUP BY p.product_id ORDER BY total_sold DESC LIMIT 5",
                $query_args
            );
        } else {
            $query_args = array_merge( $query_args, $exclusion['args'], [ $start_gmt, $end_gmt ] );
            $query = $wpdb->prepare(
                "SELECT m2.meta_value AS product_id, SUM(m1.meta_value) AS total_sold
                 FROM {$wpdb->posts} AS p
                 INNER JOIN {$wpdb->prefix}woocommerce_order_items AS oi ON p.ID = oi.order_id
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS m1 ON oi.order_item_id = m1.order_item_id AND m1.meta_key = '_qty'
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS m2 ON oi.order_item_id = m2.order_item_id AND m2.meta_key IN ('_product_id','_variation_id')
                 WHERE p.post_type = 'shop_order'
                 AND p.post_status IN ({$status_placeholders}){$exclusion['sql']}
                 AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s
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
                $data[] = [
                    'name' => $product->get_name(),
                    'qty'  => (int) $row->total_sold,
                    'id'   => (int) $row->product_id,
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
                $data[] = [
                    'title' => $title,
                    'views' => (int) $row->total_views,
                    'id'    => (int) $row->page_id,
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
                $data[] = [
                    'name'  => $product->get_name(),
                    'count' => (int) $row->total_count,
                    'id'    => (int) $row->product_id,
                ];
            }
        }
        return $data;
    }

    // =========================================================================
    // SALES OVER TIME (NEW - daily revenue breakdown for line chart)
    // =========================================================================

    private function get_sales_over_time( $start_gmt, $end_gmt ) {
        global $wpdb;

        $include_statuses    = [ 'wc-processing', 'wc-completed' ];
        $status_placeholders = implode( ', ', array_fill( 0, count( $include_statuses ), '%s' ) );

        $is_hpos   = $this->is_hpos();
        $exclusion = brikpanel_admin_order_exclusion_sql( $is_hpos, 'p.ID' );

        if ( $is_hpos ) {
            $admin_sql  = str_replace( 'customer_id', 'customer_id', $exclusion['sql'] );
            $query_args = array_merge( $include_statuses, $exclusion['args'], [ $start_gmt, $end_gmt ] );
            $query = $wpdb->prepare(
                "SELECT DATE(date_created_gmt) AS order_date,
                        SUM(total_amount) AS revenue,
                        COUNT(id) AS orders
                 FROM {$wpdb->prefix}wc_orders
                 WHERE type = 'shop_order'
                 AND status IN ({$status_placeholders}){$admin_sql}
                 AND date_created_gmt >= %s AND date_created_gmt <= %s
                 GROUP BY DATE(date_created_gmt)
                 ORDER BY order_date ASC",
                $query_args
            );
        } else {
            $query_args = array_merge( $include_statuses, $exclusion['args'], [ $start_gmt, $end_gmt ] );
            $query = $wpdb->prepare(
                "SELECT DATE(p.post_date_gmt) AS order_date,
                        SUM(pm.meta_value) AS revenue,
                        COUNT(p.ID) AS orders
                 FROM {$wpdb->posts} AS p
                 LEFT JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id
                 WHERE p.post_type = 'shop_order'
                 AND pm.meta_key = '_order_total'
                 AND p.post_status IN ({$status_placeholders}){$exclusion['sql']}
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

    private function get_order_locations( $start_gmt, $end_gmt ) {
        global $wpdb;

        $include_statuses    = [ 'wc-processing', 'wc-completed' ];
        $status_placeholders = implode( ', ', array_fill( 0, count( $include_statuses ), '%s' ) );

        $is_hpos   = $this->is_hpos();
        $exclusion = brikpanel_admin_order_exclusion_sql( $is_hpos, 'p.ID' );

        if ( $is_hpos ) {
            $admin_sql   = str_replace( 'customer_id', 'o.customer_id', $exclusion['sql'] );
            $query_args  = array_merge( $include_statuses, $exclusion['args'], [ $start_gmt, $end_gmt ] );

            $country_query = $wpdb->prepare(
                "SELECT ba.country AS code, COUNT(DISTINCT o.id) AS order_count, COALESCE(SUM(o.total_amount), 0) AS total_sales
                 FROM {$wpdb->prefix}wc_orders o
                 LEFT JOIN {$wpdb->prefix}wc_order_addresses ba ON o.id = ba.order_id AND ba.address_type = 'billing'
                 WHERE o.type = 'shop_order'
                 AND o.status IN ({$status_placeholders}){$admin_sql}
                 AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
                 AND ba.country IS NOT NULL AND ba.country != ''
                 GROUP BY ba.country
                 ORDER BY order_count DESC LIMIT 10",
                $query_args
            );

            $city_query = $wpdb->prepare(
                "SELECT ba.city AS city, ba.country AS code, COUNT(o.id) AS order_count, SUM(ol.product_qty) AS total_quantity
                 FROM {$wpdb->prefix}wc_orders o
                 LEFT JOIN {$wpdb->prefix}wc_order_addresses ba ON o.id = ba.order_id AND ba.address_type = 'billing'
                 LEFT JOIN {$wpdb->prefix}wc_order_product_lookup ol ON o.id = ol.order_id
                 WHERE o.type = 'shop_order'
                 AND o.status IN ({$status_placeholders}){$admin_sql}
                 AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
                 AND ba.city IS NOT NULL AND ba.city != ''
                 GROUP BY ba.city, ba.country
                 ORDER BY order_count DESC LIMIT 10",
                $query_args
            );
        } else {
            $query_args = array_merge( $include_statuses, $exclusion['args'], [ $start_gmt, $end_gmt ] );

            $country_query = $wpdb->prepare(
                "SELECT pm.meta_value AS code, COUNT(p.ID) AS order_count, CAST(COALESCE(SUM(CAST(oim.meta_value AS UNSIGNED)), 0) AS UNSIGNED) AS total_quantity
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_country'
                 LEFT JOIN {$wpdb->posts} oi ON p.ID = oi.post_parent AND oi.post_type = 'shop_order_item'
                 LEFT JOIN {$wpdb->postmeta} oim ON oi.ID = oim.post_id AND oim.meta_key = '_qty'
                 WHERE p.post_type = 'shop_order'
                 AND p.post_status IN ({$status_placeholders}){$exclusion['sql']}
                 AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s
                 AND pm.meta_value IS NOT NULL AND pm.meta_value != ''
                 GROUP BY pm.meta_value
                 ORDER BY order_count DESC LIMIT 10",
                $query_args
            );

            $city_query = $wpdb->prepare(
                "SELECT pm_city.meta_value AS city, pm_country.meta_value AS code, COUNT(p.ID) AS order_count, CAST(COALESCE(SUM(CAST(oim.meta_value AS UNSIGNED)), 0) AS UNSIGNED) AS total_quantity
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm_city ON p.ID = pm_city.post_id AND pm_city.meta_key = '_billing_city'
                 LEFT JOIN {$wpdb->postmeta} pm_country ON p.ID = pm_country.post_id AND pm_country.meta_key = '_billing_country'
                 LEFT JOIN {$wpdb->posts} oi ON p.ID = oi.post_parent AND oi.post_type = 'shop_order_item'
                 LEFT JOIN {$wpdb->postmeta} oim ON oi.ID = oim.post_id AND oim.meta_key = '_qty'
                 WHERE p.post_type = 'shop_order'
                 AND p.post_status IN ({$status_placeholders}){$exclusion['sql']}
                 AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s
                 AND pm_city.meta_value IS NOT NULL AND pm_city.meta_value != ''
                 GROUP BY pm_city.meta_value, pm_country.meta_value
                 ORDER BY order_count DESC LIMIT 10",
                $query_args
            );
        }

        $country_results = $wpdb->get_results( $country_query );
        $city_results    = $wpdb->get_results( $city_query );

        $wc_countries = WC()->countries->get_countries();

        $countries = [];
        foreach ( $country_results as $row ) {
            $countries[] = [
                'code'     => $row->code,
                'name'     => isset( $wc_countries[ $row->code ] ) ? $wc_countries[ $row->code ] : $row->code,
                'count'    => (int) $row->order_count,
                'total'    => wc_price( (float) ( $row->total_sales ?? 0 ) ),
            ];
        }

        $cities = [];
        foreach ( $city_results as $row ) {
            $cities[] = [
                'name'     => $row->city,
                'country'  => $row->code,
                'count'    => (int) $row->order_count,
                'quantity' => (int) ( $row->total_quantity ?? 0 ),
            ];
        }

        return [
            'countries' => $countries,
            'cities'    => $cities,
        ];
    }

    // =========================================================================
    // RECENT ORDERS (last 5 orders)
    // =========================================================================

    private function get_recent_orders() {
        $admin_ids  = brikpanel_get_admin_user_ids();
        $all_orders = wc_get_orders( [
            'limit'   => 20,
            'orderby' => 'date',
            'order'   => 'DESC',
            'type'    => 'shop_order',
        ] );

        // Filter out orders placed by admin users
        $orders = [];
        foreach ( $all_orders as $order ) {
            if ( ! empty( $admin_ids ) && in_array( $order->get_customer_id(), $admin_ids, true ) ) {
                continue;
            }
            $orders[] = $order;
            if ( count( $orders ) >= 5 ) {
                break;
            }
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
}

new Brikpanel_Dashboard();
