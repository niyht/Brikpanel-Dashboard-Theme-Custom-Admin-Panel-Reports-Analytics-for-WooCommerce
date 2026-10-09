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
     * An integer count with the store's separators, so KPI counts line up
     * with the currency cards on the same row. The whole plugin follows this
     * rule now (brikpanel_number(), field test E2).
     *
     * @param int|float $value
     * @return string
     */
    function brikpanel_dash_format_count( $value ) {
        return brikpanel_number( $value );
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

    // Per-user flag (update_user_option, so per site on multisite): this user
    // closed the "Your store is ready for its first order" guide.
    const GUIDE_DISMISSED_OPTION = 'brikpanel_new_store_guide_dismissed';

    // Per-user memory of the tab last opened in each tabbed card, stored like
    // the range above (per user and per site). Only these cards and tabs are
    // kept; a tab the card does not have falls back to its first one.
    const TAB_PREF_OPTION = 'brikpanel_dash_tabs';
    const TAB_KEYS        = [
        'sales'    => [ 'r', 'o', 'aov' ],
        'products' => [ 'sold', 'viewed', 'cart' ],
        'visitors' => [ 'devices', 'sources', 'campaigns' ],
    ];

    // The Most viewed pages window loads this many rows at a time; the Excel
    // sheet lists at most PAGES_SHEET_MAX pages.
    const PAGES_PER_LOAD  = 50;
    const PAGES_SHEET_MAX = 5000;

    // How long the store-wide order facts behind the empty states and the
    // guide are kept. The key carries the data version, so any order event
    // starts a fresh copy before this runs out.
    const STORE_STATE_TTL = 300;

    // How far back the "Last order: <day>" lookup of an empty card reads. The
    // newest paid order is found by walking orders newest first, and a store
    // selling mostly on marketplaces can have a long run of orders the site
    // cards leave out; the floor keeps that walk to one year of orders. An
    // older last order is not named ("No paid orders in this period.").
    const LAST_PAID_FLOOR_DAYS = 365;

    // Sales over time lists every day of the range, sales or not. A custom
    // range longer than this (ten years) keeps only the days with sales.
    const SALES_FILL_MAX_DAYS = 3660;

    // The sales chart draws the previous period under the current one up to
    // this many days; a longer custom range shows the current period alone.
    const SALES_PREV_MAX_DAYS = 366;

    // How long the live card's "Today so far" figures are kept. The key
    // carries the data version, so a new order starts a fresh copy at once.
    const TODAY_TTL = 60;

    // 15-minute sales buckets already read in this request, by window. The
    // sales chart, its previous period and "Today so far" share them.
    private $sales_buckets = [];

    // The WordPress dashboard widgets this user gets, once worked out
    // (embedded_wp_widgets()).
    private $wp_widgets_chosen = null;

    // Dashboard boxes that take a whole row of their own markup ('block'), or
    // a row of their own as a single card ('solo'). Every other box is a card
    // that shares its row with the next one (plan_rows()).
    const BOX_KIND = [
        'profit'                => 'block',
        'kpis'                  => 'block',
        'marketplace_analytics' => 'block',
        'locations'             => 'block',
        'subscriptions'         => 'block',
        'wp_widgets'            => 'block',
        'segments'              => 'solo',
    ];

    // Cards drawn wider or narrower than half a row next to each other.
    const BOX_WIDTH = [
        'sales' => 'wide',
        'live'  => 'narrow',
    ];

    // A row that holds exactly what one of the 3.3.31 rows held keeps that
    // row's name in data-bp-dv-section, so a store's own CSS keeps working.
    const LEGACY_ROW_ATTR = [
        'sales+live'                 => 'sales_live',
        'funnel+rates'               => 'funnel_rates',
        'best_sellers+recent_orders' => 'products_orders',
        'most_viewed+most_added'     => 'views_cart',
        'visitors+customers'         => 'devices',
        'segments'                   => 'customer_segments',
        'low_stock+ltv'              => 'stock_returns',
    ];

    // The Customers card draws the ten segments of Customer Analytics
    // (brikpanel_ca_rfm_segment_labels()) as three groups: customers to keep,
    // customers who need a nudge, customers slipping away. A segment missing
    // here falls into "attention".
    const RFM_GROUPS = [
        'champions'          => 'loyal',
        'loyal'              => 'loyal',
        'potential_loyalist' => 'loyal',
        'new'                => 'attention',
        'need_attention'     => 'attention',
        'about_to_sleep'     => 'attention',
        'at_risk'            => 'risk',
        'cant_lose'          => 'risk',
        'hibernating'        => 'risk',
        'lost'               => 'risk',
    ];

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
        // Closing the new-store guide (per user).
        add_action( 'wp_ajax_brikpanel_dash_guide_dismiss', [ $this, 'ajax_guide_dismiss' ] );
        // The tab a user opens in a card (per user).
        add_action( 'wp_ajax_brikpanel_dash_save_tab', [ $this, 'ajax_save_tab' ] );
        // The Products and pages window: a list of the period, a load at a time.
        add_action( 'wp_ajax_brikpanel_dash_list', [ $this, 'ajax_list' ] );
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
     * The tab the current user last opened in each tabbed card. Only known
     * cards and tabs come back, so a corrupted value never reaches the markup.
     *
     * @return array<string,string> Card => tab key.
     */
    public static function get_tab_preferences() {
        $user_id = get_current_user_id();
        $pref    = $user_id ? get_user_option( self::TAB_PREF_OPTION, $user_id ) : false;
        if ( ! is_array( $pref ) ) {
            return [];
        }
        $out = [];
        foreach ( self::TAB_KEYS as $card => $keys ) {
            if ( isset( $pref[ $card ] ) && is_string( $pref[ $card ] ) && in_array( $pref[ $card ], $keys, true ) ) {
                $out[ $card ] = $pref[ $card ];
            }
        }
        return $out;
    }

    /**
     * The tab a card opens on: the one this user last opened when the card
     * has it, otherwise the card's first tab.
     *
     * @param string   $card     Key of TAB_KEYS.
     * @param string[] $has      Tabs this card has.
     * @param string   $fallback The card's first tab.
     * @return string
     */
    public static function tab_for( $card, array $has, $fallback ) {
        $pref = self::get_tab_preferences();
        return ( isset( $pref[ $card ] ) && in_array( $pref[ $card ], $has, true ) ) ? $pref[ $card ] : $fallback;
    }

    /**
     * Remember the tabs the current user just opened. Unknown cards and tabs
     * are ignored, and nothing is written when nothing changed.
     *
     * @param array $tabs Card => tab key.
     * @return void
     */
    public static function save_tab_preferences( array $tabs ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }
        $current = self::get_tab_preferences();
        $next    = $current;
        foreach ( $tabs as $card => $key ) {
            $card = is_string( $card ) ? sanitize_key( $card ) : '';
            $key  = is_string( $key ) ? sanitize_key( $key ) : '';
            if ( isset( self::TAB_KEYS[ $card ] ) && in_array( $key, self::TAB_KEYS[ $card ], true ) ) {
                $next[ $card ] = $key;
            }
        }
        if ( $next === $current ) {
            return;
        }
        update_user_option( $user_id, self::TAB_PREF_OPTION, $next );
    }

    /**
     * A date range sent by the browser, checked like the Excel export does:
     * a range outside allowed_ranges(), or a custom range without two real
     * dates, becomes "today".
     *
     * @param array $src Request values (range, start_date, end_date).
     * @return array{range:string,start:?string,end:?string}
     */
    private function request_range( array $src ) {
        $range = isset( $src['range'] ) && is_string( $src['range'] ) ? sanitize_key( wp_unslash( $src['range'] ) ) : 'today';
        if ( ! in_array( $range, self::allowed_ranges(), true ) ) {
            $range = 'today';
        }
        $start = null;
        $end   = null;
        if ( 'custom' === $range ) {
            $start = isset( $src['start_date'] ) && is_string( $src['start_date'] ) ? sanitize_text_field( wp_unslash( $src['start_date'] ) ) : '';
            $end   = isset( $src['end_date'] ) && is_string( $src['end_date'] ) ? sanitize_text_field( wp_unslash( $src['end_date'] ) ) : '';
            if ( ! self::is_valid_ymd( $start ) || ! self::is_valid_ymd( $end ) ) {
                $range = 'today';
                $start = null;
                $end   = null;
            }
        }
        return [ 'range' => $range, 'start' => $start, 'end' => $end ];
    }

    /**
     * Delete the cached catalog counters so the inventory summary line
     * recomputes on the next dashboard view. Cheap; accepts any hook args.
     */
    public function bust_catalog_counts() {
        // Coalesced per request, same pattern as brikpanel_bust_data_caches():
        // a variable product save moves the parent and every variation through
        // transition_post_status, one delete each.
        static $busted = false;
        if ( $busted ) {
            if ( ! has_action( 'shutdown', [ __CLASS__, 'delete_catalog_counts' ] ) ) {
                add_action( 'shutdown', [ __CLASS__, 'delete_catalog_counts' ], PHP_INT_MAX );
            }
            return;
        }
        $busted = true;
        self::delete_catalog_counts();
    }

    /**
     * Drop the cached catalog counts. Use bust_catalog_counts().
     *
     * @return void
     */
    public static function delete_catalog_counts() {
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

    /**
     * Registers the dashboard under WordPress's Dashboard menu, as a row only
     * access plugins see (includes/brikpanel-screen-menu.php).
     */
    public function register_page() {
        $hooks = function_exists( 'brikpanel_add_screen_page' )
            ? brikpanel_add_screen_page( 'index.php', __( 'Dashboard', 'brikpanel' ), 'manage_woocommerce', 'brikpanel-dashboard', [ $this, 'render_page' ] )
            : array_filter( [ add_submenu_page( '', __( 'Dashboard', 'brikpanel' ), '', 'manage_woocommerce', 'brikpanel-dashboard', [ $this, 'render_page' ] ) ] );

        foreach ( $hooks as $hook ) {
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
     * Every box the dashboard can show, keyed for the "Dashboard sections"
     * setting, with its label there, in the factory order. Since 3.3.32 the
     * setting lists boxes one by one; up to 3.3.31 it listed rows of two
     * (brikpanel_dashboard_legacy_rows() in brikpanel-dashboard-section-order.php
     * reads those saved lists). The marketplace box exists only while
     * BrikMarket is active, so it never shows up as a phantom option.
     *
     * @return array<string,string> Box key => label.
     */
    public static function get_section_labels() {
        $labels = [
            'profit'        => __( 'Profit (Revenue, Cost of goods, Net profit)', 'brikpanel' ),
            'kpis'          => __( 'KPI cards (Sales, Orders, AOV, Visitors, Conversion)', 'brikpanel' ),
            'sales'         => __( 'Sales over time', 'brikpanel' ),
            'live'          => __( 'Live visitors', 'brikpanel' ),
            'funnel'        => __( 'Conversion funnel', 'brikpanel' ),
            'rates'         => __( 'Order rates', 'brikpanel' ),
            'locations'     => __( 'Order locations globe + Top countries/cities', 'brikpanel' ),
            'best_sellers'  => __( 'Best sellers', 'brikpanel' ),
            'most_viewed'   => __( 'Most viewed pages', 'brikpanel' ),
            'most_added'    => __( 'Most added to cart', 'brikpanel' ),
            'recent_orders' => __( 'Recent orders', 'brikpanel' ),
            'visitors'      => __( 'Visitors (devices, sources, campaigns)', 'brikpanel' ),
            'customers'     => __( 'Customers (new and returning)', 'brikpanel' ),
            'segments'      => __( 'Customer segments (RFM)', 'brikpanel' ),
            'low_stock'     => __( 'Low stock', 'brikpanel' ),
            'ltv'           => __( 'Customer lifetime value', 'brikpanel' ),
            'subscriptions' => __( 'Subscriptions', 'brikpanel' ),
            'wp_widgets'    => __( 'WordPress dashboard widgets', 'brikpanel' ),
        ];
        if ( function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active() ) {
            // Right after the store cards, so the marketplace breakdown sits
            // next to the (now site-only) headline numbers it separates out.
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
     * How a box sits on the dashboard: 'block' (a whole row of its own
     * markup), 'solo' (a card alone in its row) or 'half' (a card that shares
     * its row with the next one).
     *
     * @param string $key Box key.
     * @return string
     */
    public static function box_kind( $key ) {
        return isset( self::BOX_KIND[ $key ] ) ? self::BOX_KIND[ $key ] : 'half';
    }

    /**
     * The rows the dashboard draws, in order. Pure: the settings list and the
     * layout test use it too.
     *
     * Cards join first, on the ticked list: Best sellers takes Most viewed
     * pages and Most added to cart in as tabs while they come right after it,
     * and the Customers card takes Customer segments in when it comes right
     * after it. A box that prints nothing on this request (`$silent`) still
     * stands between two cards for that, as its row did up to 3.3.31. Then
     * blocks take their own row, a lone Customer segments card takes a row of
     * its own, and the other cards go two to a row in list order; a card left
     * without a partner fills the row alone.
     *
     * @param string[] $order   Box keys in their order.
     * @param string[] $visible Ticked box keys.
     * @param string[] $silent  Box keys that print nothing on this request.
     * @return array[] Each row: ['block' => key] or ['class' => '21'|'12'|'11'|'1',
     *                 'section' => legacy row name or '', 'cards' => [card, ...]];
     *                 a card is ['box' => key, 'keys' => [box keys], 'views' => [...]
     *                 (products), 'blocks' => [...] (customers), 'width' => ...].
     */
    public static function plan_rows( array $order, array $visible, array $silent = [] ) {
        $on    = array_fill_keys( array_map( 'strval', $visible ), true );
        $shown = [];
        foreach ( $order as $key ) {
            if ( is_string( $key ) && isset( $on[ $key ] ) && ! in_array( $key, $shown, true ) ) {
                $shown[] = $key;
            }
        }

        $units = [];
        $count = count( $shown );
        for ( $i = 0; $i < $count; $i++ ) {
            $key = $shown[ $i ];
            if ( 'best_sellers' === $key ) {
                $unit = [ 'box' => 'products', 'keys' => [ $key ], 'views' => [ 'sold' ], 'kind' => 'half', 'width' => '' ];
                while ( $i + 1 < $count && in_array( $shown[ $i + 1 ], [ 'most_viewed', 'most_added' ], true ) ) {
                    $i++;
                    $unit['keys'][]  = $shown[ $i ];
                    $unit['views'][] = 'most_viewed' === $shown[ $i ] ? 'viewed' : 'cart';
                }
                $units[] = $unit;
            } elseif ( 'most_viewed' === $key || 'most_added' === $key ) {
                $units[] = [ 'box' => 'products', 'keys' => [ $key ], 'views' => [ 'most_viewed' === $key ? 'viewed' : 'cart' ], 'kind' => 'half', 'width' => '' ];
            } elseif ( 'customers' === $key ) {
                $unit = [ 'box' => 'customers', 'keys' => [ $key ], 'blocks' => [ 'types' ], 'kind' => 'half', 'width' => '' ];
                if ( $i + 1 < $count && 'segments' === $shown[ $i + 1 ] ) {
                    $i++;
                    $unit['keys'][]   = 'segments';
                    $unit['blocks'][] = 'segments';
                }
                $units[] = $unit;
            } elseif ( 'segments' === $key ) {
                $units[] = [ 'box' => 'customers', 'keys' => [ $key ], 'blocks' => [ 'segments' ], 'kind' => 'solo', 'width' => '' ];
            } else {
                $units[] = [
                    'box'   => $key,
                    'keys'  => [ $key ],
                    'kind'  => self::box_kind( $key ),
                    'width' => isset( self::BOX_WIDTH[ $key ] ) ? self::BOX_WIDTH[ $key ] : '',
                ];
            }
        }

        $quiet   = array_fill_keys( array_map( 'strval', $silent ), true );
        $rows    = [];
        $pending = null;
        foreach ( $units as $unit ) {
            if ( isset( $quiet[ $unit['keys'][0] ] ) ) {
                continue;
            }
            if ( 'half' !== $unit['kind'] ) {
                if ( null !== $pending ) {
                    $rows[]  = self::plan_row( [ $pending ] );
                    $pending = null;
                }
                $rows[] = 'block' === $unit['kind'] ? [ 'block' => $unit['box'] ] : self::plan_row( [ $unit ] );
                continue;
            }
            if ( null === $pending ) {
                $pending = $unit;
                continue;
            }
            $rows[]  = self::plan_row( [ $pending, $unit ] );
            $pending = null;
        }
        if ( null !== $pending ) {
            $rows[] = self::plan_row( [ $pending ] );
        }
        return $rows;
    }

    /**
     * One row of cards: its column class and, when it holds exactly what a
     * 3.3.31 row held, that row's name.
     *
     * @param array[] $cards One or two cards from plan_rows().
     * @return array
     */
    private static function plan_row( array $cards ) {
        $class = '1';
        if ( 2 === count( $cards ) ) {
            $class = '11';
            if ( 'wide' === $cards[0]['width'] && 'narrow' === $cards[1]['width'] ) {
                $class = '21';
            } elseif ( 'narrow' === $cards[0]['width'] && 'wide' === $cards[1]['width'] ) {
                $class = '12';
            }
        }
        $lead = [];
        foreach ( $cards as $card ) {
            $lead[] = $card['keys'][0];
        }
        $lead = implode( '+', $lead );
        return [
            'class'   => $class,
            'section' => isset( self::LEGACY_ROW_ATTR[ $lead ] ) ? self::LEGACY_ROW_ATTR[ $lead ] : '',
            'cards'   => $cards,
        ];
    }

    /**
     * Ticked boxes that print nothing for this request: Subscriptions without
     * WooCommerce Subscriptions, and the WordPress widgets when none of the
     * picked ones is for this user.
     *
     * @param array $visible Ticked box keys (as keys).
     * @return string[]
     */
    private function silent_boxes( array $visible ) {
        $silent = [];
        if ( ! class_exists( 'WC_Subscriptions' ) ) {
            $silent[] = 'subscriptions';
        }
        if ( isset( $visible['wp_widgets'] ) && ! $this->embedded_wp_widgets() ) {
            $silent[] = 'wp_widgets';
        }
        return $silent;
    }

    /**
     * Print the planned rows (plan_rows()).
     *
     * @param array[] $rows
     * @return void
     */
    private function render_rows( array $rows ) {
        foreach ( $rows as $row ) {
            if ( isset( $row['block'] ) ) {
                $this->render_block( $row['block'] );
                continue;
            }
            $attr = '' !== $row['section'] ? ' data-bp-dv-section="' . esc_attr( $row['section'] ) . '"' : '';
            echo "\n" . '            <section class="bp-dv-row bp-dv-row--' . esc_attr( $row['class'] ) . '"' . $attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both parts escaped above
            foreach ( $row['cards'] as $card ) {
                $this->render_card( $card );
            }
            echo "\n" . '            </section>' . "\n";
        }
    }

    /**
     * The Products and pages window: the whole list of a card's open tab
     * (Best sellers, Most viewed pages or Most added to cart) for the period,
     * opened from the card's "View all" button. Printed inside the dashboard
     * so it takes the dashboard's type and colours, and only when a Products
     * card is on it. The browser's own dialog keeps it above the top bar,
     * keeps the keyboard inside it and closes it on Esc
     * (brikpanel-dashboard.js initPagesWindow()).
     *
     * @param array[] $rows From plan_rows().
     * @return void
     */
    private function render_pages_window( array $rows ) {
        $has_lists = false;
        foreach ( $rows as $row ) {
            foreach ( isset( $row['cards'] ) ? $row['cards'] : [] as $card ) {
                if ( 'products' === $card['box'] ) {
                    $has_lists = true;
                }
            }
        }
        if ( ! $has_lists ) {
            return;
        }
        ?>
            <dialog class="bp-dv-pages" id="bp-dv-pages" aria-labelledby="bp-dv-pages-title">
                <div class="bp-dv-pages-box">
                    <div class="bp-dv-pages-head">
                        <div class="bp-dv-pages-titles">
                            <h2 class="bp-dv-pages-title" id="bp-dv-pages-title"><?php esc_html_e( 'Most viewed pages', 'brikpanel' ); ?></h2>
                            <p class="bp-dv-pages-sub" data-bp-dv-slot="pages-sub"></p>
                        </div>
                        <button type="button" class="bp-dv-pages-x" data-bp-dv-pages-close aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        </button>
                    </div>
                    <div class="bp-dv-pages-tools">
                        <input type="search" class="brikpanel-control brikpanel-control--quiet bp-dv-pages-q" data-bp-dv-slot="pages-q" placeholder="<?php esc_attr_e( 'Search pages', 'brikpanel' ); ?>" aria-label="<?php esc_attr_e( 'Search pages', 'brikpanel' ); ?>" maxlength="100" autocomplete="off">
                    </div>
                    <div class="bp-dv-pages-body" data-bp-dv-slot="pages-body">
                        <ul class="bp-dv-plist bp-dv-pages-list" data-bp-dv-slot="pages-list"></ul>
                        <p class="brikpanel-dash-empty bp-dv-pages-note" data-bp-dv-slot="pages-note" role="status" hidden></p>
                        <div class="bp-dv-pages-foot">
                            <button type="button" class="brikpanel-btn brikpanel-btn--secondary bp-dv-pages-more" data-bp-dv-slot="pages-more" hidden><?php esc_html_e( 'Show more', 'brikpanel' ); ?></button>
                        </div>
                    </div>
                </div>
            </dialog>
        <?php
    }

    /**
     * A box with its own markup and its own row.
     *
     * @param string $key Box key.
     * @return void
     */
    private function render_block( $key ) {
        switch ( $key ) {
            case 'profit':
                $this->render_section_profit();
                break;
            case 'kpis':
                $this->render_section_kpis();
                break;
            case 'marketplace_analytics':
                $this->render_section_marketplace_analytics();
                break;
            case 'locations':
                $this->render_section_locations();
                break;
            case 'subscriptions':
                $this->render_section_subscriptions();
                break;
            case 'wp_widgets':
                $this->render_embedded_wp_widgets();
                break;
        }
    }

    /**
     * One card of a row.
     *
     * @param array $card From plan_rows().
     * @return void
     */
    private function render_card( array $card ) {
        switch ( $card['box'] ) {
            case 'products':
                $this->render_products_card( $card['views'] );
                break;
            case 'customers':
                $this->render_customers_card( $card['blocks'] );
                break;
            case 'sales':
                $this->render_box_sales();
                break;
            case 'live':
                $this->render_box_live();
                break;
            case 'funnel':
                $this->render_box_funnel();
                break;
            case 'rates':
                $this->render_box_rates();
                break;
            case 'recent_orders':
                $this->render_box_recent_orders();
                break;
            case 'visitors':
                $this->render_box_visitors();
                break;
            case 'low_stock':
                $this->render_box_low_stock();
                break;
            case 'ltv':
                $this->render_box_ltv();
                break;
        }
    }

    /**
     * "Customize" at the end of the date line: opens the "Dashboard sections"
     * list in Settings. Only for someone who can open those settings
     * (the multisite lock and "settings for administrators only" included).
     *
     * @return void
     */
    private function render_customize_link() {
        $can = function_exists( 'brikpanel_user_can_open_settings' )
            ? brikpanel_user_can_open_settings()
            : current_user_can( 'manage_woocommerce' );
        if ( ! $can ) {
            return;
        }
        $url = add_query_arg(
            [
                'page'    => 'wc-settings',
                'tab'     => 'brikpanel',
                'section' => 'dashboard',
            ],
            admin_url( 'admin.php' )
        ) . '#bp-jump-brikpanel-dashboard-section-order';
        ?>
                <a class="brikpanel-btn brikpanel-btn--link brikpanel-dash-customize" href="<?php echo esc_url( $url ); ?>">
                    <svg class="brikpanel-dash-customize-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    <span><?php echo esc_html_x( 'Customize', 'link on the dashboard date line to the dashboard layout settings', 'brikpanel' ); ?></span>
                </a>
        <?php
    }

    public function render_page() {
        // Which boxes, in which order (brikpanel-dashboard-section-order.php
        // reads both the box lists saved since 3.3.32 and the row lists saved
        // before), then the rows they make.
        $layout  = function_exists( 'brikpanel_dashboard_layout' )
            ? brikpanel_dashboard_layout( true )
            : [ 'order' => array_keys( self::get_section_labels() ), 'visible' => array_keys( self::get_section_labels() ) ];
        $visible = array_flip( $layout['visible'] );
        $rows    = self::plan_rows( $layout['order'], $layout['visible'], $this->silent_boxes( $visible ) );
        ?>
        <div id="brikpanel-dashboard" class="brikpanel-dashboard brikpanel-shell__page">
            <?php
            // The cards lay out by the width the dashboard has (bp-dv-lt*
            // classes, brikpanel-dashboard.css): measured here, before the
            // first paint, and kept up to date by the dashboard script.
            wp_print_inline_script_tag( '(function(){var r=document.getElementById("brikpanel-dashboard");if(!r||!window.getComputedStyle){return;}var s=getComputedStyle(r),w=r.clientWidth-(parseFloat(s.paddingLeft)||0)-(parseFloat(s.paddingRight)||0);[[1300,"bp-dv-lt1300"],[1000,"bp-dv-lt1000"],[920,"bp-dv-lt920"],[800,"bp-dv-lt800"],[560,"bp-dv-lt560"]].forEach(function(b){r.classList.toggle(b[1],w<=b[0]);});})();' );
            ?>
            <?php wp_nonce_field( 'brikpanel_dashboard_nonce', 'security' ); ?>

            <?php
            // The header gives way in its own order, measured
            // (front-end/shared/brikpanel-fit-row.js, CLAUDE.md "Başlık satırı
            // kuralı"): one line while it fits; then the ad platforms link and
            // Export Excel fold into "More actions"; then the date buttons take
            // their own row, then an even 3 x 2 grid; then the buttons go under
            // the title; Copy everything's label goes to its icon last. On a
            // phone the buttons stacked at uneven widths and the date buttons
            // wrapped ragged (field test C6).
            // Only the title's own text is checked for "one line": with
            // BrikMarket on, "With marketplace" is a second line under it by
            // design, and checking the whole <h1> counted that as a broken
            // title, so every level failed and the header took its phone form
            // at any width (3.3.24 to 3.3.27).
            $bp_dash_header_fit = [
                'title'  => 'h1 > .brikpanel-dash-title-text',
                'lines'  => [ '' ],
                'levels' => [
                    '',
                    'is-fold',
                    [ 'cls' => 'is-fold is-two-rows', 'lines' => [ '.brikpanel-dash-header-main', '.brikpanel-dash-presets' ] ],
                    [ 'cls' => 'is-fold is-two-rows is-preset-grid', 'lines' => [ '.brikpanel-dash-header-main' ] ],
                    [ 'cls' => 'is-fold is-two-rows is-preset-grid is-stacked-main', 'lines' => [ '.brikpanel-dash-actions' ] ],
                    [ 'cls' => 'is-fold is-two-rows is-preset-grid is-stacked-main is-icon-copy', 'lines' => [ '.brikpanel-dash-actions' ] ],
                ],
            ];
            ?>
            <!-- Header -->
            <div class="brikpanel-dash-header" id="brikpanel-dash-header" data-bp-fit-row="<?php echo esc_attr( wp_json_encode( $bp_dash_header_fit ) ); ?>">
                <?php
                // Fit as soon as the header opens (the helper is printed in <head>).
                wp_print_inline_script_tag( 'if(window.brikpanelFitRow){window.brikpanelFitRow.auto(document.getElementById("brikpanel-dash-header"));}' );
                ?>
                <div class="brikpanel-dash-header-main">
                    <h1>
                        <span class="brikpanel-dash-title-text"><?php esc_html_e( 'Dashboard', 'brikpanel' ); ?></span>
                        <?php if ( function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active() ) : ?>
                            <span class="brikpanel-dash-header-suffix"><?php esc_html_e( 'With marketplace', 'brikpanel' ); ?></span>
                        <?php endif; ?>
                    </h1>
                    <div class="brikpanel-dash-actions">
                        <div class="brikpanel-dash-copy-wrap">
                            <button type="button" class="brikpanel-dash-copy-summary" id="brikpanel-copy-summary">
                                <span class="brikpanel-dash-copy-icon" aria-hidden="true">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                </span>
                                <span class="brikpanel-dash-copy-label" data-bp-fit-labels="<?php echo esc_attr( wp_json_encode( [ __( 'Copy everything', 'brikpanel' ), __( 'Collecting data…', 'brikpanel' ), __( 'Copied to clipboard!', 'brikpanel' ), __( 'Failed, try again', 'brikpanel' ) ] ) ); ?>"><?php esc_html_e( 'Copy everything', 'brikpanel' ); ?></span>
                                <span class="brikpanel-dash-copy-progress" aria-hidden="true"><span></span></span>
                            </button>
                            <span class="brikpanel-dash-copy-help" data-bp-tip tabindex="0" role="button" aria-expanded="false"
                                  aria-label="<?php esc_attr_e( 'What does “Copy everything” do?', 'brikpanel' ); ?>"
                                  aria-describedby="brikpanel-copy-help-body">
                                <svg class="brikpanel-dash-copy-help-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                                </svg>
                                <span class="brikpanel-dash-copy-help-tip brikpanel-tip" role="tooltip">
                                    <span class="brikpanel-dash-copy-help-title"><?php esc_html_e( 'Copy everything', 'brikpanel' ); ?></span>
                                    <span class="brikpanel-dash-copy-help-body" id="brikpanel-copy-help-body"><?php esc_html_e( 'Bundles your store’s key data (KPIs, profit and margins, cost of goods, ad spend, expenses, top products and categories, customers and settings) into a single Markdown report and copies it to your clipboard. Paste it into ChatGPT, Claude or any AI tool to get instant analysis, insights and recommendations about your store.', 'brikpanel' ); ?></span>
                                </span>
                            </span>
                        </div>
                        <div class="brikpanel-overflow">
                            <?php
                            if ( function_exists( 'brikpanel_overflow_trigger' ) ) {
                                brikpanel_overflow_trigger( 'brikpanel-dash-more' );
                            }
                            ?>
                            <div class="brikpanel-overflow__menu" id="brikpanel-dash-more">
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
                                <button type="button" class="brikpanel-dash-export" id="brikpanel-export-xlsx"
                                        title="<?php esc_attr_e( 'Download the selected period as an Excel workbook (opens in Excel / Google Sheets)', 'brikpanel' ); ?>">
                                    <span class="brikpanel-dash-export-icon" aria-hidden="true">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                    </span>
                                    <span class="brikpanel-dash-export-label" data-bp-fit-labels="<?php echo esc_attr( wp_json_encode( [ __( 'Export Excel', 'brikpanel' ), __( 'Preparing…', 'brikpanel' ) ] ) ); ?>"><?php esc_html_e( 'Export Excel', 'brikpanel' ); ?></span>
                                </button>
                                <?php
                                // "Keep screen on": phones only, in the folded menu.
                                // front-end/dashboard/brikpanel-wake-lock.js shows it
                                // where the browser can keep the screen awake. Parked
                                // with the phone app (brikpanel_phone_app_enabled()).
                                if ( brikpanel_phone_app_enabled() ) :
                                ?>
                                <button type="button" class="brikpanel-wake-toggle" id="brikpanel-wake-toggle" aria-pressed="false" hidden>
                                    <span class="brikpanel-wake-toggle__icon" aria-hidden="true">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"></path></svg>
                                    </span>
                                    <span class="brikpanel-wake-toggle__label"><?php esc_html_e( 'Keep screen on', 'brikpanel' ); ?></span>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
                // The remembered range decides which preset renders active,
                // so the button state matches the data on first paint —
                // marking "Today" here and correcting it from JS would flash
                // the wrong selection on every load.
                $bp_saved_range = self::get_range_preference();
                $bp_presets     = [
                    'today'     => __( 'Today', 'brikpanel' ),
                    'yesterday' => __( 'Yesterday', 'brikpanel' ),
                    '7days'     => __( 'Last 7 days', 'brikpanel' ),
                    '30days'    => __( 'Last 30 days', 'brikpanel' ),
                    '90days'    => __( 'Last 90 days', 'brikpanel' ),
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

            <div class="brikpanel-dash-period" id="brikpanel-dash-period" aria-live="polite">
                <span class="brikpanel-dash-period-icon" aria-hidden="true">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                </span>
                <span class="brikpanel-dash-period-text"><?php esc_html_e( 'Loading…', 'brikpanel' ); ?></span>
                <?php $this->render_scope_hint(); ?>
                <?php $this->render_customize_link(); ?>
            </div>
            <?php brikpanel_header_end(); ?>

            <?php $this->render_new_store_guide( isset( $visible['profit'] ) ); ?>

            <?php
            /**
             * Fires right after the new-store guide, above the first section.
             * The survey card prints here. It runs after the guide on purpose:
             * when the guide finds the store already has orders, its turn
             * passes on in the same request (includes/brikpanel-asks.php).
             *
             * @since 3.3.32
             */
            do_action( 'brikpanel_dashboard_before_sections' );

            $this->render_rows( $rows );
            $this->render_pages_window( $rows );

            /**
             * Fires after all dashboard sections, at the bottom of the dashboard
             * content. Used to render the dismissible BrikMentor card.
             *
             * @since 3.1.28
             */
            do_action( 'brikpanel_dashboard_after_sections' );
            ?>

        </div>
        <?php
    }

    // =========================================================================
    // NEW STORE GUIDE (field test F1)
    // =========================================================================

    /**
     * "Your store is ready for its first order": what to set up before the
     * first sale, shown above the cards while the store has never had a real
     * order (administrators' own orders do not count, like everywhere on the
     * dashboard). Each step is listed only when this user can do it, and is
     * ticked once it is done. Closing it hides it for this user for good.
     *
     * @param bool $profit_visible Whether the Profit section, and with it the
     *                             quick "Add expense" window, is on this page.
     */
    private function render_new_store_guide( $profit_visible ) {
        // One ask at a time (includes/brikpanel-asks.php): the guide takes its
        // turn after the welcome tour like every other card on this page.
        if ( ! function_exists( 'brikpanel_ask_allows' ) || ! brikpanel_ask_allows( 'guide' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $user_id = get_current_user_id();
        if ( ! $user_id || get_user_option( self::GUIDE_DISMISSED_OPTION, $user_id ) ) {
            return;
        }
        // Only the cheap facts: the latest paid day lookups belong to the data
        // request, not to the page's first paint.
        $state = $this->store_basics();
        if ( ! empty( $state['has_any_order'] ) ) {
            // Not a new store after all: the turn passes on at once, so the
            // next card can take this same spot on this page view.
            brikpanel_ask_retire( 'guide' );
            return;
        }

        $steps = [];
        if ( current_user_can( 'edit_products' ) ) {
            $catalog = $this->get_catalog_counts();
            $steps[] = [
                'label' => __( 'Add your first product', 'brikpanel' ),
                'done'  => (int) $catalog['products'] > 0,
                'url'   => admin_url( 'admin.php?page=brikpanel-product-editor' ),
            ];
            $steps[] = [
                'label' => __( 'Enter the cost of each product, so Net profit is right', 'brikpanel' ),
                'done'  => $this->guide_has_costs(),
                'url'   => admin_url( 'edit.php?post_type=product' ),
            ];
        }
        if ( class_exists( 'Brikpanel_Expenses' ) ) {
            // The quick window only exists while the Expenses card is on the
            // page; otherwise the step opens the Expenses screen.
            $quick_add = $profit_visible
                && ( ! function_exists( 'brikpanel_dashboard_profit_field_enabled' ) || brikpanel_dashboard_profit_field_enabled( 'expenses' ) );
            $steps[] = [
                'label'  => __( 'Add your monthly expenses', 'brikpanel' ),
                'done'   => $this->guide_has_expenses(),
                'url'    => $quick_add ? '' : admin_url( 'admin.php?page=' . Brikpanel_Expenses::PAGE_SLUG ),
                'action' => $quick_add ? 'add-expense' : '',
            ];
        }
        // Same gate and target as the "Connect ad accounts" header link.
        if ( class_exists( 'Brikpanel_Ads_Tokens' )
            && function_exists( 'brikpanel_ads_module_is_enabled' )
            && brikpanel_ads_module_is_enabled() ) {
            $steps[] = [
                'label' => __( 'Connect your ad accounts to see ad spend and ROAS', 'brikpanel' ),
                'done'  => Brikpanel_Ads_Tokens::is_connected( 'google_ads' ) || Brikpanel_Ads_Tokens::is_connected( 'meta_ads' ),
                'url'   => admin_url( 'admin.php?page=brikpanel-ad-platforms' ),
            ];
        }

        $admin_orders = (int) ( $state['admin_orders'] ?? 0 );
        brikpanel_ask_shown( 'guide' );
        ?>
        <section class="brikpanel-dash-guide" id="brikpanel-dash-guide" aria-labelledby="brikpanel-dash-guide-title"
                 data-nonce="<?php echo esc_attr( wp_create_nonce( 'brikpanel_dash_guide_dismiss' ) ); ?>">
            <div class="brikpanel-dash-guide__head">
                <div class="brikpanel-dash-guide__intro">
                    <h2 class="brikpanel-dash-guide__title" id="brikpanel-dash-guide-title"><?php esc_html_e( 'Your store is ready for its first order', 'brikpanel' ); ?></h2>
                    <p class="brikpanel-dash-guide__lead"><?php esc_html_e( 'This page fills in by itself once orders and visitors arrive. These steps make your profit right from the first sale.', 'brikpanel' ); ?></p>
                </div>
                <button type="button" class="brikpanel-dash-guide__close" aria-label="<?php esc_attr_e( 'Hide this guide', 'brikpanel' ); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>

            <?php if ( $steps ) : ?>
            <ol class="brikpanel-dash-guide__steps">
                <?php foreach ( $steps as $step ) : ?>
                    <?php
                    $done   = ! empty( $step['done'] );
                    $action = $step['action'] ?? '';
                    $mark   = $done
                        ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="20 6 9 17 4 12"></polyline></svg>'
                        : '';
                    $chev   = '<svg class="brikpanel-dash-guide__chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="9 18 15 12 9 6"></polyline></svg>';
                    ?>
                    <li class="brikpanel-dash-guide__step<?php echo $done ? ' is-done' : ''; ?>">
                        <?php if ( $done ) : ?>
                            <span class="brikpanel-dash-guide__item">
                                <span class="brikpanel-dash-guide__mark"><?php echo $mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
                                <span class="brikpanel-dash-guide__label">
                                    <span class="screen-reader-text"><?php
                                        /* translators: %s: a finished step of the new store guide, e.g. "Add your first product". Read by screen readers only. */
                                        echo esc_html( sprintf( _x( 'Done: %s', 'new store guide step', 'brikpanel' ), $step['label'] ) );
                                    ?></span><span aria-hidden="true"><?php echo esc_html( $step['label'] ); ?></span>
                                </span>
                            </span>
                        <?php elseif ( '' !== $action ) : ?>
                            <?php
                            // The step is done in place (an expense added from the
                            // quick window), so the page carries the same
                            // screen reader sentence the done row above prints.
                            /* translators: %s: a finished step of the new store guide, e.g. "Add your first product". Read by screen readers only. */
                            $done_label = sprintf( _x( 'Done: %s', 'new store guide step', 'brikpanel' ), $step['label'] );
                            ?>
                            <button type="button" class="brikpanel-dash-guide__item" data-bp-guide-action="<?php echo esc_attr( $action ); ?>" data-bp-done-label="<?php echo esc_attr( $done_label ); ?>">
                                <span class="brikpanel-dash-guide__mark" aria-hidden="true"></span>
                                <span class="brikpanel-dash-guide__label"><?php echo esc_html( $step['label'] ); ?></span>
                                <?php echo $chev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
                            </button>
                        <?php else : ?>
                            <a class="brikpanel-dash-guide__item" href="<?php echo esc_url( $step['url'] ); ?>">
                                <span class="brikpanel-dash-guide__mark" aria-hidden="true"></span>
                                <span class="brikpanel-dash-guide__label"><?php echo esc_html( $step['label'] ); ?></span>
                                <?php echo $chev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
            <?php endif; ?>

            <div class="brikpanel-dash-guide__foot">
                <p class="brikpanel-dash-guide__note">
                    <?php esc_html_e( 'Orders you place while logged in as an administrator are not counted, so your own test orders do not show here. To try it, log out and place a test order.', 'brikpanel' ); ?>
                    <?php if ( $admin_orders > 0 ) : ?>
                        <span class="brikpanel-dash-guide__admin"><?php
                            echo esc_html( sprintf(
                                /* translators: %s: number of orders, already formatted (e.g. 3). */
                                _n(
                                    '%s order was placed from an administrator account, so it is not counted.',
                                    '%s orders were placed from administrator accounts, so they are not counted.',
                                    $admin_orders,
                                    'brikpanel'
                                ),
                                brikpanel_number( $admin_orders )
                            ) );
                        ?></span>
                    <?php endif; ?>
                </p>
                <a class="brikpanel-dash-guide__demo" href="<?php echo esc_url( 'https://code.brksoft.com/wp-admin/' ); ?>" target="_blank" rel="noopener noreferrer">
                    <span><?php esc_html_e( 'See the dashboard with data in the live preview', 'brikpanel' ); ?></span>
                    <span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'brikpanel' ); ?></span>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7 17 17 7"></path><polyline points="8 7 17 7 17 16"></polyline></svg>
                </a>
            </div>
        </section>
        <?php
    }

    /**
     * Whether any product or variation has a cost above zero, in any of the
     * meta keys BrikPanel reads a cost from. One indexed lookup that stops at
     * the first hit; only asked while the new-store guide is on screen.
     *
     * @return bool
     */
    private function guide_has_costs() {
        global $wpdb;

        $keys = function_exists( 'brikpanel_cogs_meta_keys' )
            ? brikpanel_cogs_meta_keys()
            : [ '_cogs_total_value', '_brikpanel_cogs' ];
        $keys = array_values( array_filter( (array) $keys, 'is_string' ) );
        if ( ! $keys ) {
            return false;
        }
        $placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

        return (bool) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only, table names from $wpdb.
            "SELECT 1
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key IN ({$placeholders})
               AND pm.meta_value <> ''
               AND CAST(pm.meta_value AS DECIMAL(20,4)) > 0
               AND p.post_type IN ('product', 'product_variation')
               AND p.post_status NOT IN ('trash', 'auto-draft')
             LIMIT 1",
            $keys
        ) );
    }

    /**
     * Whether any expense has been entered (Operational Expenses, the quick
     * window on the Profit card, recurring rows, supplier costs).
     *
     * @return bool
     */
    private function guide_has_expenses() {
        global $wpdb;
        $table = $wpdb->prefix . Brikpanel_Expenses::TABLE;
        return (bool) $wpdb->get_var( "SELECT 1 FROM {$table} LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb, no input.
    }

    /**
     * AJAX: close the new-store guide for the current user.
     */
    public function ajax_guide_dismiss() {
        if ( ! check_ajax_referer( 'brikpanel_dash_guide_dismiss', 'security', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }
        update_user_option( get_current_user_id(), self::GUIDE_DISMISSED_OPTION, 1 );
        if ( function_exists( 'brikpanel_ask_closed' ) ) {
            brikpanel_ask_closed( 'guide' );
        }
        wp_send_json_success();
    }

    /**
     * AJAX: remember the tab the current user just opened (tabs[card]=key).
     */
    public function ajax_save_tab() {
        if ( ! check_ajax_referer( 'brikpanel_dashboard_nonce', 'security', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }
        // Every card and tab is checked against TAB_KEYS in save_tab_preferences().
        $tabs = ( isset( $_POST['tabs'] ) && is_array( $_POST['tabs'] ) ) ? wp_unslash( $_POST['tabs'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        self::save_tab_preferences( array_slice( $tabs, 0, count( self::TAB_KEYS ), true ) );
        wp_send_json_success( [ 'tabs' => self::get_tab_preferences() ] );
    }

    /**
     * AJAX: one load of the Products and pages window (list, search, offset):
     * Best sellers ('sold'), Most viewed pages ('viewed') or Most added to
     * cart ('cart'), the same lists the cards and the Excel sheets read.
     */
    public function ajax_list() {
        if ( ! check_ajax_referer( 'brikpanel_dashboard_nonce', 'security', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }
        $kind = isset( $_POST['list'] ) && is_string( $_POST['list'] ) ? sanitize_key( wp_unslash( $_POST['list'] ) ) : '';
        if ( ! in_array( $kind, [ 'sold', 'viewed', 'cart' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Unknown list.' ], 400 );
        }
        $range  = $this->request_range( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
        $dates  = $this->calculate_dates( $range['range'], $range['start'], $range['end'] );
        $search = isset( $_POST['search'] ) && is_string( $_POST['search'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['search'] ) ) ) : '';
        $search = brikpanel_substr( $search, 0, 100 );
        $offset = isset( $_POST['offset'] ) ? min( self::PAGES_SHEET_MAX, absint( $_POST['offset'] ) ) : 0;
        $args   = [
            'limit'  => self::PAGES_PER_LOAD,
            'offset' => $offset,
            'search' => $search,
            'count'  => 0 === $offset,
        ];

        $rows = [];
        if ( 'viewed' === $kind ) {
            $list = $this->most_viewed_query( $dates['start_local'], $dates['end_local'], $args );
            foreach ( $list['rows'] as $row ) {
                $rows[] = [ 'key' => $row['key'], 'title' => $row['title'], 'type' => $row['type'], 'value' => $row['views'], 'url' => $row['url'] ];
            }
        } else {
            $args['exclude_marketplace'] = function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active();
            $list = $this->products_list_query( $kind, $dates, $args );
            foreach ( $list['rows'] as $row ) {
                $rows[] = [ 'key' => $row['key'], 'title' => $row['title'], 'type' => '', 'value' => $row['value'], 'url' => $row['url'] ];
            }
        }
        wp_send_json_success(
            [
                'list'     => $kind,
                'rows'     => $rows,
                'total'    => $list['total'],
                'has_more' => $list['has_more'],
                'offset'   => $offset,
                'next'     => $offset + self::PAGES_PER_LOAD,
            ]
        );
    }

    // =========================================================================
    // STORE STATE (why a card is empty)
    // =========================================================================

    /**
     * The two store facts the page itself needs, for the new-store guide:
     * whether the store ever had an order, and how many administrators' test
     * orders were left out while it has none. One LIMIT 1 query, plus a COUNT
     * only while there is no other order. Cached under the shared data
     * version, which every order event bumps.
     *
     * - has_any_order: an order of any status except drafts and the trash,
     *                  site and marketplace alike, so a store selling only on
     *                  marketplaces is never "new". Administrators' orders
     *                  are left out, like everywhere on the dashboard
     *                  (brikpanel_admin_order_exclusion_sql()).
     * - admin_orders:  orders administrators placed, counted only while the
     *                  store has no other order (0 otherwise).
     *
     * @return array{has_any_order:bool,admin_orders:int}
     */
    private function store_basics() {
        static $basics = null;
        if ( null !== $basics ) {
            return $basics;
        }

        $cache_key = 'bp_dash_store_b1_' . brikpanel_data_cache_ver() . ( $this->is_hpos() ? '_h' : '_p' );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['has_any_order'], $cached['admin_orders'] ) ) {
            $basics = $cached;
            return $basics;
        }

        global $wpdb;
        $q      = $this->store_order_sql();
        $basics = [
            'has_any_order' => false,
            'admin_orders'  => 0,
        ];

        // Placeholders only below; table and column names are fixed in
        // store_order_sql().
        $basics['has_any_order'] = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$q['from']} WHERE {$q['where']} AND {$q['status']} NOT IN ({$q['drafts_in']}){$q['not_admin']['sql']} LIMIT 1",
            array_merge( $q['drafts'], $q['not_admin']['args'] )
        ) );

        // Only administrators' test orders so far: say how many, so the owner
        // knows why the dashboard still reads empty.
        if ( ! $basics['has_any_order'] && $q['admins'] ) {
            $only = $this->store_admin_sql( $q['is_hpos'], $q['admins'], true );
            $basics['admin_orders'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$q['from']} WHERE {$q['where']} AND {$q['status']} NOT IN ({$q['drafts_in']}){$only['sql']}",
                array_merge( $q['drafts'], $only['args'] )
            ) );
        }

        $ttl = function_exists( 'brikpanel_cache_ttl' ) ? brikpanel_cache_ttl( self::STORE_STATE_TTL ) : self::STORE_STATE_TTL;
        set_transient( $cache_key, $basics, $ttl );

        return $basics;
    }

    /**
     * Store-wide facts the empty cards explain themselves with, for the data
     * request: store_basics() plus the paid order and visit facts below.
     * Cached under the shared data version, which every order event bumps.
     *
     * Every order figure leaves out orders placed by administrators, like the
     * rest of the dashboard (brikpanel_admin_order_exclusion_sql()).
     *
     * - has_paid_orders:    a paid order (brikpanel_paid_order_statuses()),
     *                       at any time.
     * - last_paid_day_site: local Y-m-d of the latest paid order the site
     *                       cards count (marketplace orders left out while
     *                       BrikMarket is active, as in Sales over time),
     *                       within the last LAST_PAID_FLOOR_DAYS days, or null.
     * - last_paid_day_all:  the same with marketplace orders, or null.
     * - has_any_visit:      visitor tracking ever counted a visit, so an empty
     *                       window can tell "none yet" from "none this time".
     *
     * @return array{has_any_order:bool,admin_orders:int,has_paid_orders:bool,last_paid_day_site:?string,last_paid_day_all:?string,has_any_visit:bool}
     */
    private function store_state() {
        static $state = null;
        if ( null !== $state ) {
            return $state;
        }

        $mp_active = function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active();
        $cache_key = 'bp_dash_store_f2_' . brikpanel_data_cache_ver() . ( $this->is_hpos() ? '_h' : '_p' ) . ( $mp_active ? '_m' : '' );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['has_any_order'], $cached['admin_orders'], $cached['has_any_visit'] ) ) {
            $state = $cached;
            return $state;
        }

        $basics = $this->store_basics();
        $state  = array_merge(
            $basics,
            $this->compute_paid_state( $mp_active, ! empty( $basics['has_any_order'] ) ),
            [ 'has_any_visit' => $this->store_has_any_visit() ]
        );
        $ttl = function_exists( 'brikpanel_cache_ttl' ) ? brikpanel_cache_ttl( self::STORE_STATE_TTL ) : self::STORE_STATE_TTL;
        set_transient( $cache_key, $state, $ttl );

        return $state;
    }

    /**
     * The order table, columns and filters the store facts read, for the
     * storage in use (HPOS wc_orders as alias o, else posts as alias p).
     *
     * @return array{is_hpos:bool,admins:int[],from:string,where:string,status:string,date:string,sort:string,id:string,drafts:string[],drafts_in:string,not_admin:array{sql:string,args:int[]}}
     */
    private function store_order_sql() {
        global $wpdb;

        $is_hpos = $this->is_hpos();
        $admins  = array_values( array_filter( array_map( 'intval', (array) brikpanel_get_admin_user_ids() ) ) );
        $drafts  = [ 'auto-draft', 'wc-checkout-draft', 'trash' ];

        if ( $is_hpos ) {
            $q = [
                'from'   => "{$wpdb->prefix}wc_orders o",
                'where'  => "o.type = 'shop_order'",
                'status' => 'o.status',
                'date'   => 'o.date_created_gmt',
                'sort'   => 'o.date_created_gmt',
                'id'     => 'o.id',
            ];
        } else {
            $q = [
                'from'   => "{$wpdb->posts} p",
                'where'  => "p.post_type = 'shop_order'",
                'status' => 'p.post_status',
                'date'   => 'p.post_date_gmt',
                // The posts index covers the local post_date; it runs in the
                // same order as post_date_gmt.
                'sort'   => 'p.post_date',
                'id'     => 'p.ID',
            ];
        }

        $q['is_hpos']   = $is_hpos;
        $q['admins']    = $admins;
        $q['drafts']    = $drafts;
        $q['drafts_in'] = implode( ', ', array_fill( 0, count( $drafts ), '%s' ) );
        $q['not_admin'] = $this->store_admin_sql( $is_hpos, $admins, false );

        return $q;
    }

    /**
     * The paid order facts of store_state(). Only asked by the data request,
     * never while the page renders.
     *
     * The latest paid day is looked for within the last LAST_PAID_FLOOR_DAYS
     * days only, one status at a time: with a single status and a date floor
     * on the indexed date column, the (type, status, date) index hands back
     * the newest row first and the walk ends at the floor. Without the floor,
     * a store whose recent orders are all marketplace orders would read every
     * one of them looking for a site order.
     *
     * @param bool $mp_active     BrikMarket is active (marketplace orders exist as a separate channel).
     * @param bool $has_any_order store_basics() found an order.
     * @return array{has_paid_orders:bool,last_paid_day_site:?string,last_paid_day_all:?string}
     */
    private function compute_paid_state( $mp_active, $has_any_order ) {
        global $wpdb;

        $state = [
            'has_paid_orders'    => false,
            'last_paid_day_site' => null,
            'last_paid_day_all'  => null,
        ];
        if ( ! $has_any_order ) {
            return $state;
        }

        $q         = $this->store_order_sql();
        $paid      = array_values( array_filter( (array) brikpanel_paid_order_statuses(), 'is_string' ) );
        $not_admin = $q['not_admin'];
        if ( ! $paid ) {
            return $state;
        }

        // The floor on the column the index sorts by: UTC on HPOS, the same
        // moment in the store's time on the posts table (post_date is local).
        $floor_utc = gmdate( 'Y-m-d H:i:s', time() - self::LAST_PAID_FLOOR_DAYS * DAY_IN_SECONDS );
        $floor     = $q['is_hpos'] ? $floor_utc : get_date_from_gmt( $floor_utc, 'Y-m-d H:i:s' );

        // Placeholders only below; table and column names are fixed in
        // store_order_sql().
        $latest_paid = static function ( $extra_sql, array $extra_args ) use ( $wpdb, $q, $paid, $not_admin, $floor ) {
            $best = '';
            foreach ( $paid as $paid_status ) {
                $found = (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT {$q['date']} FROM {$q['from']} WHERE {$q['where']} AND {$q['status']} = %s AND {$q['sort']} >= %s{$not_admin['sql']}{$extra_sql} ORDER BY {$q['sort']} DESC LIMIT 1",
                    array_merge( [ $paid_status, $floor ], $not_admin['args'], $extra_args )
                ) );
                if ( $found > $best ) {
                    $best = $found;
                }
            }
            if ( '' === $best ) {
                return null;
            }
            $day = brikpanel_local_day( $best );
            return '' !== $day ? $day : null;
        };

        $state['last_paid_day_all'] = $latest_paid( '', [] );

        if ( null !== $state['last_paid_day_all'] ) {
            $state['has_paid_orders'] = true;
        } else {
            // Nothing paid within the floor: whether anything was ever paid,
            // which only needs the first row.
            $pp = implode( ', ', array_fill( 0, count( $paid ), '%s' ) );
            $state['has_paid_orders'] = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$q['from']} WHERE {$q['where']} AND {$q['status']} IN ({$pp}){$not_admin['sql']} LIMIT 1",
                array_merge( $paid, $not_admin['args'] )
            ) );
        }

        $state['last_paid_day_site'] = $state['last_paid_day_all'];
        if ( null !== $state['last_paid_day_all'] && $mp_active ) {
            $mp = brikpanel_marketplace_order_exclusion_sql( $q['is_hpos'], $q['id'] );
            $state['last_paid_day_site'] = $latest_paid( (string) $mp['sql'], (array) $mp['args'] );
        }

        return $state;
    }

    /**
     * Whether visitor tracking ever counted a visit on this store. The
     * visitors table keeps daily counters, a small table, and the read stops
     * at the first day with a visit.
     *
     * @return bool
     */
    private function store_has_any_visit() {
        global $wpdb;
        $table = $wpdb->prefix . 'brikpanel_visitors';
        return (bool) $wpdb->get_var( "SELECT 1 FROM {$table} WHERE visitor_count > 0 LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb, no input.
    }

    /**
     * Administrator filter for the store facts (store_basics(),
     * store_state()): leave administrators' orders
     * out ($only = false, the same orders brikpanel_admin_order_exclusion_sql()
     * leaves out), or keep only those ($only = true). Correlated on the legacy
     * store so a LIMIT 1 query stops at its first row.
     *
     * @param bool  $is_hpos Orders live in wc_orders (alias o), else posts (alias p).
     * @param int[] $admins  Administrator user IDs.
     * @param bool  $only    Keep only administrators' orders.
     * @return array{sql:string,args:int[]}
     */
    private function store_admin_sql( $is_hpos, array $admins, $only ) {
        if ( ! $admins ) {
            return $only ? [ 'sql' => ' AND 1 = 0', 'args' => [] ] : [ 'sql' => '', 'args' => [] ];
        }
        $placeholders = implode( ', ', array_fill( 0, count( $admins ), '%d' ) );

        if ( $is_hpos ) {
            return [
                'sql'  => ' AND o.customer_id ' . ( $only ? 'IN' : 'NOT IN' ) . " ({$placeholders})",
                'args' => $admins,
            ];
        }

        global $wpdb;
        return [
            'sql'  => ' AND ' . ( $only ? 'EXISTS' : 'NOT EXISTS' ) . " (SELECT 1 FROM {$wpdb->postmeta} bpcu WHERE bpcu.post_id = p.ID AND bpcu.meta_key = '_customer_user' AND bpcu.meta_value IN ({$placeholders}))",
            'args' => $admins,
        ];
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
        $label = $ts ? wp_date( brikpanel_date_format(), $ts ) : '';
        return $label;
    }

    /**
     * Render a small "?" help icon with a tooltip that opens on hover, focus
     * or a tap, reusing the shared dashboard hint styling. The bubble is
     * placed by front-end/shared/brikpanel-tip.js, which keeps it on screen.
     *
     * @param string $title Short bold heading (plain text).
     * @param string $body  Explanation. Allows <br> and <strong> only.
     * @param string $align 'start' (the bubble hangs from the icon's start
     *                      edge, default) or 'end' (from its end edge, for
     *                      right-most elements).
     */
    private function render_hint( $title, $body, $align = 'start' ) {
        $body_id = wp_unique_id( 'brikpanel-hint-' );
        ?>
        <span class="brikpanel-dash-hint" data-bp-tip="<?php echo esc_attr( 'end' === $align ? 'end' : 'start' ); ?>" tabindex="0" role="button" aria-expanded="false" aria-label="<?php echo esc_attr( $title ); ?>" aria-describedby="<?php echo esc_attr( $body_id ); ?>">
            <svg class="brikpanel-dash-hint-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <span class="brikpanel-dash-hint-tip brikpanel-tip" role="tooltip">
                <span class="brikpanel-dash-hint-title"><?php echo esc_html( $title ); ?></span>
                <span class="brikpanel-dash-hint-body" id="<?php echo esc_attr( $body_id ); ?>"><?php echo wp_kses( $body, [ 'br' => [], 'strong' => [] ] ); ?></span>
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
        // How a visitor is counted since 3.3.30 (the person check).
        $body .= '<br><br>' . esc_html__( 'A visitor is counted once a day, after they move the mouse, tap, scroll or press a key on your store. Bots, your staff and pages nobody looked at are left out.', 'brikpanel' );
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
        // One row of store cards, six with the ROAS card of a connected ad
        // platform, laid out by the shared tiles helper (6, 3 + 3 or 2 + 2 + 2,
        // the widest that fits). Each card has a small line of the period
        // beside its figure (brikpanel-dashboard-viz.js).
        $cards = [
            'total_sales' => [ __( 'Total sales', 'brikpanel' ), 'card-total-sales', 'delta-total-sales', 'r' ],
            'orders'      => [ __( 'Orders', 'brikpanel' ), 'card-orders', 'delta-orders', 'o' ],
            'aov'         => [ __( 'Avg. order value', 'brikpanel' ), 'card-aov', 'delta-aov', 'aov' ],
            'visitors'    => [ __( 'Visitors', 'brikpanel' ), 'card-visitors', 'delta-visitors', 'v' ],
            'conversion'  => [ __( 'Conversion rate', 'brikpanel' ), 'card-conversion', 'delta-conversion', 'conv' ],
        ];
        ?>
            <!-- Summary Cards -->
            <div class="bp-dv-kpis" id="brikpanel-kpi-cards" data-bp-tiles>
                <?php foreach ( $cards as $metric => $card ) : ?>
                <div class="brikpanel-dash-card bp-dv-kpi" data-metric="<?php echo esc_attr( $metric ); ?>">
                    <span class="brikpanel-dash-card-label"><span class="brikpanel-dash-card-label-text"><?php echo esc_html( $card[0] ); ?></span><?php
                        if ( 'visitors' === $metric ) {
                            $this->render_tracking_hint();
                        } elseif ( 'conversion' === $metric ) {
                            $this->render_tracking_hint( 'end' );
                        }
                    ?></span>
                    <span class="bp-dv-kpi-main">
                        <span class="brikpanel-dash-card-value" id="<?php echo esc_attr( $card[1] ); ?>">--</span>
                        <span class="bp-dv-spark" data-spark="<?php echo esc_attr( $card[3] ); ?>" aria-hidden="true"></span>
                    </span>
                    <?php if ( 'orders' === $metric ) : ?>
                        <?php // Units sold share the change line, so the card keeps the height of its neighbours. ?>
                        <span class="brikpanel-dash-card-foot">
                            <span class="brikpanel-dash-card-delta" id="delta-orders"></span>
                            <span class="brikpanel-dash-card-items" id="card-items-sold" hidden></span>
                        </span>
                    <?php else : ?>
                        <span class="brikpanel-dash-card-delta" id="<?php echo esc_attr( $card[2] ); ?>"></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
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
        // "Tax in the Profit section" (Settings, Dashboard): inside Expenses,
        // taken out of Revenue and Expenses, or kept in Revenue.
        $tax_mode       = function_exists( 'brikpanel_profit_tax_mode' ) ? brikpanel_profit_tax_mode() : 'expenses';
        $tax_in_revenue = 'revenue' === $tax_mode;

        // Revenue is paid orders for the period, optionally net of refunds, with
        // tax and shipping included and admin orders excluded. Each place the
        // setting can put the tax gets its own wording, so the explanation
        // never describes a figure the card is not showing.
        if ( 'excluded' === $tax_mode ) {
            $rev_body = $returns_on
                ? __( 'The total of all paid orders for the selected dates (Processing and Completed by default), with shipping included, tax taken out and any customer refunds in the period subtracted. Orders placed by store administrators are left out so your own test orders do not change it. Tax is taken out because of the "Tax in the Profit section" setting, so it is not in Expenses either.', 'brikpanel' )
                : __( 'The total of all paid orders for the selected dates (Processing and Completed by default), with shipping included and tax taken out. Orders placed by store administrators are left out so your own test orders do not change it. Tax is taken out because of the "Tax in the Profit section" setting, so it is not in Expenses either.', 'brikpanel' );
            $exp_body = __( 'Operating costs for the period: ad spend from connected ad platforms (store currency only), payment processing fees charged by the gateway, supplier and stock costs from received purchase orders, plus anything logged in the Expenses module. Tax is not here because it is already taken out of Revenue. Open the breakdown to see each part.', 'brikpanel' );
        } else {
            $rev_body = $returns_on
                ? __( 'The total of all paid orders for the selected dates (Processing and Completed by default), with tax and shipping included and any customer refunds in the period subtracted. Orders placed by store administrators are left out so your own test orders do not change it. You can change which statuses count under Settings, then Analytics.', 'brikpanel' )
                : __( 'The total of all paid orders for the selected dates (Processing and Completed by default), with tax and shipping included. Orders placed by store administrators are left out so your own test orders do not change it. You can change which statuses count under Settings, then Analytics.', 'brikpanel' );
            $exp_body = $tax_in_revenue
                ? __( 'Operating costs for the period: ad spend from connected ad platforms (store currency only), payment processing fees charged by the gateway, supplier and stock costs from received purchase orders, plus anything logged in the Expenses module. Tax is not here because it is shown under Revenue and taken off Net profit. Open the breakdown to see each part.', 'brikpanel' )
                : __( 'Operating costs for the period: order tax, ad spend from connected ad platforms (store currency only), payment processing fees charged by the gateway, supplier and stock costs from received purchase orders, plus anything logged in the Expenses module. Open the breakdown to see each part.', 'brikpanel' );
        }
        ?>
            <!-- Profit -->
            <?php
            $profit_cols = 2 + ( $show_cogs ? 1 : 0 ) + ( $show_expenses ? 1 : 0 );
            ?>
            <div class="brikpanel-dash-profit" id="brikpanel-profit-section">
                <div class="brikpanel-dash-cards brikpanel-dash-cards-profit bp-profit-cols-<?php echo (int) $profit_cols; ?>" id="brikpanel-profit-cards" data-bp-tiles>
                    <div class="brikpanel-dash-card bp-dv-kpi" data-metric="profit_revenue" id="profit-revenue-card">
                        <div class="brikpanel-dash-card-head">
                            <span class="brikpanel-dash-card-label"><span class="brikpanel-dash-card-label-text"><?php esc_html_e( 'Revenue', 'brikpanel' ); ?></span><?php
                                $this->render_hint( __( 'How Revenue is calculated', 'brikpanel' ), $rev_body ); ?></span>
                            <span class="brikpanel-dash-card-tools">
                                <button type="button" class="brikpanel-dash-bd-toggle" id="profit-rev-bd-toggle"
                                        aria-expanded="false" aria-controls="profit-rev-bd-collapse" hidden
                                        title="<?php esc_attr_e( 'Show revenue breakdown', 'brikpanel' ); ?>"
                                        aria-label="<?php esc_attr_e( 'Show revenue breakdown', 'brikpanel' ); ?>">
                                    <svg class="brikpanel-dash-bd-chevron" width="14" height="14" viewBox="0 0 24 24"
                                         fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                         stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                </button>
                            </span>
                        </div>
                        <span class="brikpanel-dash-card-value" id="card-profit-revenue">--</span>
                        <span class="brikpanel-dash-card-delta" id="delta-profit-revenue"></span>
                        <div class="brikpanel-dash-bd-collapse" id="profit-rev-bd-collapse">
                            <div class="brikpanel-dash-bd-inner">
                                <div class="brikpanel-dash-bd-list" id="profit-revenue-breakdown"></div>
                            </div>
                        </div>
                    </div>
                    <?php if ( $show_cogs ) : ?>
                    <div class="brikpanel-dash-card bp-dv-kpi" data-metric="profit_cogs">
                        <div class="brikpanel-dash-card-head">
                            <span class="brikpanel-dash-card-label"><span class="brikpanel-dash-card-label-text"><?php esc_html_e( 'Cost of goods', 'brikpanel' ); ?></span><?php
                                $this->render_hint(
                                    __( 'How cost of goods is calculated', 'brikpanel' ),
                                    __( 'The "Cost of goods" you set on each product, multiplied by the quantity sold in paid orders for the period. Variations use their own cost and fall back to the parent product. Any product with no cost set counts as zero, which overstates Net profit, so fill those in for an accurate margin.', 'brikpanel' )
                                ); ?></span>
                        </div>
                        <span class="brikpanel-dash-card-value" id="card-profit-cogs">--</span>
                        <span class="brikpanel-dash-card-delta brikpanel-dash-card-delta-static" id="delta-profit-cogs"></span>
                    </div>
                    <?php endif; ?>
                    <?php if ( $show_expenses ) : ?>
                    <div class="brikpanel-dash-card bp-dv-kpi" data-metric="profit_expenses" id="profit-expenses-card">
                        <div class="brikpanel-dash-card-head">
                            <span class="brikpanel-dash-card-label"><span class="brikpanel-dash-card-label-text"><?php esc_html_e( 'Expenses', 'brikpanel' ); ?></span><?php
                                $this->render_hint( __( 'What Expenses includes', 'brikpanel' ), $exp_body ); ?></span>
                            <span class="brikpanel-dash-card-tools">
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
                            </span>
                        </div>
                        <span class="brikpanel-dash-card-value" id="card-profit-expenses">--</span>
                        <span class="brikpanel-dash-card-delta brikpanel-dash-card-delta-static" id="delta-profit-expenses"></span>
                        <div class="brikpanel-dash-bd-collapse" id="profit-bd-collapse">
                            <div class="brikpanel-dash-bd-inner">
                                <div class="brikpanel-dash-bd-list" id="profit-expenses-breakdown"></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="brikpanel-dash-card bp-dv-kpi" data-metric="profit_net">
                        <div class="brikpanel-dash-card-head">
                            <span class="brikpanel-dash-card-label"><span class="brikpanel-dash-card-label-text"><?php esc_html_e( 'Net profit', 'brikpanel' ); ?></span><?php
                                $this->render_hint(
                                    __( 'How net profit is calculated', 'brikpanel' ),
                                    $tax_in_revenue
                                        ? __( 'Revenue minus Cost of goods, Tax and Expenses. This is what is left after the cost of what you sold, the tax and your operating costs for the period. A negative figure means a loss.', 'brikpanel' )
                                        : __( 'Revenue minus Cost of goods minus Expenses. This is what is left after the cost of what you sold and your operating costs for the period. A negative figure means a loss.', 'brikpanel' )
                                ); ?></span>
                        </div>
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
                    <div class="brikpanel-exp-field brikpanel-field">
                        <label for="brikpanel-exp-kind"><?php esc_html_e( 'Type', 'brikpanel' ); ?></label>
                        <select class="brikpanel-control" id="brikpanel-exp-kind">
                            <option value="fixed"><?php esc_html_e( 'Fixed amount', 'brikpanel' ); ?></option>
                            <option value="percent"><?php esc_html_e( 'Percentage of revenue', 'brikpanel' ); ?></option>
                            <?php // Same English source string as the Expenses page on purpose: the Sheets resolve_kind() English arm matches on it. ?>
                            <option value="per_order"><?php esc_html_e( 'Cost per order', 'brikpanel' ); ?></option>
                        </select>
                    </div>
                    <div class="brikpanel-exp-field brikpanel-field">
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
                    <div class="brikpanel-exp-field brikpanel-field" id="brikpanel-exp-scope-field" hidden>
                        <label for="brikpanel-exp-scope"><?php echo esc_html( _x( 'Applies to', 'which orders a per-order cost is charged on', 'brikpanel' ) ); ?></label>
                        <select class="brikpanel-control" id="brikpanel-exp-scope">
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
                    <div class="brikpanel-exp-field brikpanel-field">
                        <label for="brikpanel-exp-category"><?php esc_html_e( 'Title', 'brikpanel' ); ?></label>
                        <input type="text" class="brikpanel-control" id="brikpanel-exp-category" list="brikpanel-exp-cats" autocomplete="off"
                               placeholder="<?php esc_attr_e( 'e.g. Rent, Salaries, Credit card commission', 'brikpanel' ); ?>">
                        <datalist id="brikpanel-exp-cats">
                            <?php foreach ( $cats as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>"></option><?php endforeach; ?>
                        </datalist>
                    </div>
                    <?php // Naming the cost comes first, then what it belongs to: the second question only makes sense once the first is answered. ?>
                    <?php if ( $can_group ) : ?>
                    <div class="brikpanel-exp-field brikpanel-field">
                        <label for="brikpanel-exp-parent-category"><?php echo esc_html( _x( 'Part of', 'the expense this cost is filed under', 'brikpanel' ) ); ?> <span class="brikpanel-exp-optional"><?php esc_html_e( 'optional', 'brikpanel' ); ?></span></label>
                        <?php Brikpanel_Expenses::render_parent_category_picker( 'brikpanel-exp-parent-category' ); ?>
                        <p class="brikpanel-exp-hint"><?php esc_html_e( 'Shows this cost under one you already have. Amounts stay separate.', 'brikpanel' ); ?></p>
                    </div>
                    <?php endif; ?>
                    <div class="brikpanel-exp-row2" id="brikpanel-exp-row2">
                        <div class="brikpanel-exp-field brikpanel-field">
                            <label for="brikpanel-exp-date" id="brikpanel-exp-date-label"><?php esc_html_e( 'Date', 'brikpanel' ); ?></label>
                            <input type="date" class="brikpanel-control" id="brikpanel-exp-date" value="<?php echo esc_attr( $today ); ?>">
                        </div>
                        <div class="brikpanel-exp-field brikpanel-field" id="brikpanel-exp-recurring-field">
                            <label for="brikpanel-exp-recurring"><?php esc_html_e( 'Repeats', 'brikpanel' ); ?></label>
                            <select class="brikpanel-control" id="brikpanel-exp-recurring">
                                <option value="none"><?php esc_html_e( 'One-time', 'brikpanel' ); ?></option>
                                <option value="monthly"><?php esc_html_e( 'Monthly', 'brikpanel' ); ?></option>
                                <option value="weekly"><?php esc_html_e( 'Weekly', 'brikpanel' ); ?></option>
                                <option value="yearly"><?php esc_html_e( 'Yearly', 'brikpanel' ); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="brikpanel-exp-field brikpanel-field">
                        <label for="brikpanel-exp-desc"><?php esc_html_e( 'Note (optional)', 'brikpanel' ); ?></label>
                        <input type="text" class="brikpanel-control" id="brikpanel-exp-desc" autocomplete="off"
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
                        <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Marketplace sales', 'brikpanel' ); ?></span>
                        <span class="brikpanel-dash-card-value" id="card-mp-sales">--</span>
                        <span class="brikpanel-dash-card-delta" id="delta-mp-sales"></span>
                    </div>
                    <div class="brikpanel-dash-card">
                        <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Marketplace orders', 'brikpanel' ); ?></span>
                        <span class="brikpanel-dash-card-value" id="card-mp-orders">--</span>
                        <span class="brikpanel-dash-card-delta" id="delta-mp-orders"></span>
                    </div>
                    <div class="brikpanel-dash-card">
                        <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Avg. marketplace order', 'brikpanel' ); ?></span>
                        <span class="brikpanel-dash-card-value" id="card-mp-aov">--</span>
                        <span class="brikpanel-dash-card-delta" id="delta-mp-aov"></span>
                    </div>
                    <div class="brikpanel-dash-card">
                        <span class="brikpanel-dash-card-label"><?php esc_html_e( 'Share of total revenue', 'brikpanel' ); ?></span>
                        <span class="brikpanel-dash-card-value" id="card-mp-share">--</span>
                        <span class="brikpanel-dash-card-delta brikpanel-dash-card-delta-static" id="delta-mp-share"></span>
                    </div>
                </div>

                <!-- Per-marketplace breakdown -->
                <div class="brikpanel-dash-row brikpanel-dash-row-1-1">
                    <div class="brikpanel-dash-panel">
                        <h2><?php esc_html_e( 'Revenue by marketplace', 'brikpanel' ); ?></h2>
                        <div class="brikpanel-dash-mp-list" id="brikpanel-mp-list">
                            <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                        </div>
                    </div>
                    <div class="brikpanel-dash-panel">
                        <h2><?php esc_html_e( 'Marketplace share', 'brikpanel' ); ?></h2>
                        <div class="brikpanel-dash-chart-wrap brikpanel-dash-chart-short">
                            <canvas id="brikpanel-mp-share-chart"></canvas>
                        </div>
                        <p class="brikpanel-dash-empty brikpanel-dash-chart-empty" hidden></p>
                    </div>
                </div>

                <!-- Top categories + per-marketplace categories -->
                <div class="brikpanel-dash-row brikpanel-dash-row-1-1">
                    <div class="brikpanel-dash-panel">
                        <h2><?php esc_html_e( 'Top categories from marketplaces', 'brikpanel' ); ?></h2>
                        <div class="brikpanel-dash-table-wrap" id="brikpanel-mp-categories">
                            <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                        </div>
                    </div>
                    <div class="brikpanel-dash-panel">
                        <h2><?php esc_html_e( 'Top marketplace products', 'brikpanel' ); ?></h2>
                        <div class="brikpanel-dash-table-wrap" id="brikpanel-mp-products">
                            <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        <?php
    }

    /**
     * A card's quiet "View all ›" style link, or nothing without an address.
     *
     * @param string $url   Address (already resolved; '' prints nothing).
     * @param string $label Link text.
     * @return void
     */
    private function card_link( $url, $label ) {
        if ( '' === (string) $url ) {
            return;
        }
        printf(
            '<a class="brikpanel-btn brikpanel-btn--link bp-dv-more" href="%1$s">%2$s<svg class="bp-dv-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="9 6 15 12 9 18"></polyline></svg></a>',
            esc_url( $url ),
            esc_html( $label )
        );
    }

    /**
     * A row of tabs in a card's head: one line that scrolls inside itself on a
     * narrow card (the shared scroll strip), keyboard and screen-reader ready
     * (brikpanelDashViz.tabs). Each tab shows the panel it controls.
     *
     * @param string $label    What the row switches, for screen readers.
     * @param array  $tabs     key => [ label, panel id ].
     * @param string $selected Key of the first tab shown.
     * @return void
     */
    private function tab_row( $label, array $tabs, $selected ) {
        ?>
        <div class="bp-dv-tabs" role="tablist" aria-label="<?php echo esc_attr( $label ); ?>" data-bp-strip>
            <?php foreach ( $tabs as $key => $tab ) : $on = ( $key === $selected ); ?>
                <button type="button" class="bp-dv-tab" role="tab" data-bp-dv-tab="<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo $on ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $tab[1] ); ?>"<?php echo $on ? '' : ' tabindex="-1"'; ?>><?php echo esc_html( $tab[0] ); ?></button>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Sales over time. The cards below print without a row around them:
     * render_rows() puts them two to a row (plan_rows()).
     */
    private function render_box_sales() {
        $mp_active = function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active();
        $chart_id  = wp_unique_id( 'bp-dv-sales-chart-' );
        ?>
                <article class="bp-dv-card bp-dv-sales" data-bp-dv="sales" aria-labelledby="bp-dv-sales-title">
                    <div class="bp-dv-card-h">
                        <h2 class="bp-dv-title" id="bp-dv-sales-title"><?php esc_html_e( 'Sales over time', 'brikpanel' ); ?></h2>
                        <div class="bp-dv-aside">
                            <?php
                            $this->tab_row(
                                __( 'Sales over time', 'brikpanel' ),
                                [
                                    'r'   => [ __( 'Revenue', 'brikpanel' ), $chart_id ],
                                    'o'   => [ __( 'Orders', 'brikpanel' ), $chart_id ],
                                    'aov' => [ __( 'Avg. order value', 'brikpanel' ), $chart_id ],
                                ],
                                self::tab_for( 'sales', self::TAB_KEYS['sales'], 'r' )
                            );
                            ?>
                        </div>
                    </div>
                    <div class="bp-dv-sales-top">
                        <div class="bp-dv-sales-total">
                            <b class="bp-dv-sales-figure" data-bp-dv-slot="total">--</b>
                            <span class="bp-dv-delta" data-bp-dv-slot="delta"></span>
                            <span class="bp-dv-muted" data-bp-dv-slot="cmp"></span>
                        </div>
                        <div class="bp-dv-legend" aria-hidden="true">
                            <span><i class="bp-dv-legend-cur"></i><span data-bp-dv-slot="cur-label"></span></span>
                            <span><i class="bp-dv-legend-prev"></i><span data-bp-dv-slot="prev-label"></span></span>
                        </div>
                    </div>
                    <?php if ( $mp_active ) : ?>
                        <p class="bp-dv-note"><?php esc_html_e( 'Website orders only', 'brikpanel' ); ?></p>
                    <?php endif; ?>
                    <div class="bp-dv-chart" id="<?php echo esc_attr( $chart_id ); ?>" role="tabpanel" tabindex="0" aria-labelledby="bp-dv-sales-title"></div>
                    <?php // Takes the chart's place, with the reason, when there is nothing to draw. ?>
                    <p class="brikpanel-dash-empty bp-dv-empty" data-bp-dv-slot="empty" hidden></p>
                    <span class="screen-reader-text" aria-live="polite" data-bp-dv-slot="live"></span>
                </article>
        <?php
    }

    private function render_box_live() {
        ?>
                <article class="bp-dv-card bp-dv-live" data-bp-dv="live" aria-labelledby="bp-dv-live-title">
                    <div class="bp-dv-card-h">
                        <h2 class="bp-dv-title" id="bp-dv-live-title"><?php esc_html_e( 'Live visitors', 'brikpanel' ); ?></h2>
                        <div class="bp-dv-aside">
                            <span class="bp-dv-live-pill" id="live-count" hidden><i aria-hidden="true"></i><span data-bp-dv-slot="count"></span></span>
                        </div>
                    </div>
                    <div class="bp-dv-live-body" id="live-visitors-list" data-bp-dv-slot="body"></div>
                    <span class="screen-reader-text" aria-live="polite" data-bp-dv-slot="hours-live"></span>
                </article>
        <?php
    }

    private function render_box_funnel() {
        ?>
                <article class="bp-dv-card bp-dv-funnel" data-bp-dv="funnel">
                    <div class="bp-dv-card-h">
                        <h2 class="bp-dv-title"><?php esc_html_e( 'Conversion funnel', 'brikpanel' ); ?><?php $this->render_tracking_hint(); ?></h2>
                        <div class="bp-dv-aside" data-bp-dv-slot="conv"></div>
                    </div>
                    <div class="bp-dv-card-body">
                        <div class="bp-dv-flow" data-bp-dv-slot="flow"></div>
                        <p class="brikpanel-dash-empty bp-dv-empty" data-bp-dv-slot="empty" hidden></p>
                    </div>
                    <?php // Carts left in this period, one line under the funnel (filled by the script). ?>
                    <p class="brikpanel-dash-cartab" id="brikpanel-dash-cartab" hidden>
                        <a class="brikpanel-dash-cartab-label" href="<?php echo esc_url( function_exists( 'brikpanel_module_url' ) ? brikpanel_module_url( 'brikpanel-abandoned-carts' ) : admin_url( 'admin.php?page=brikpanel-abandoned-carts' ) ); ?>"><?php esc_html_e( 'Abandoned carts', 'brikpanel' ); ?></a>
                    </p>
                </article>
        <?php
    }

    private function render_box_rates() {
        ?>
                <article class="bp-dv-card bp-dv-rates" data-bp-dv="rates">
                    <div class="bp-dv-card-h">
                        <h2 class="bp-dv-title"><?php esc_html_e( 'Order rates', 'brikpanel' ); ?></h2>
                        <div class="bp-dv-aside">
                            <span data-bp-dv-slot="orders"></span>
                            <span class="brikpanel-dash-rates-items" id="rates-items-sold" hidden></span>
                        </div>
                    </div>
                    <div class="bp-dv-card-body">
                        <p class="bp-dv-lead" data-bp-dv-slot="lead"><?php esc_html_e( 'Out of every 100 orders', 'brikpanel' ); ?></p>
                        <div class="bp-dv-rates-box" data-bp-dv-slot="box"></div>
                        <p class="brikpanel-dash-empty bp-dv-empty" data-bp-dv-slot="empty" hidden></p>
                    </div>
                </article>
        <?php
    }

    public function render_section_locations() {
        ?>
            <!-- Row: Order Locations Globe + Tables -->
            <div class="brikpanel-dash-row brikpanel-dash-row-2-1">
                <div class="brikpanel-dash-panel brikpanel-dash-globe-panel" id="globe-panel">
                    <div class="brikpanel-dash-globe-header">
                        <div class="brikpanel-dash-globe-title-group">
                            <h2 id="globe-panel-title"><?php esc_html_e( 'Order locations', 'brikpanel' ); ?></h2>
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
                    <?php // Beside the globe box, never inside it: the globe writes its size onto that box. ?>
                    <p class="brikpanel-dash-empty brikpanel-dash-globe-empty" hidden></p>
                </div>
                <div class="brikpanel-dash-panel brikpanel-dash-locations-panel">
                    <h2 id="loc-panel-countries-title"><?php esc_html_e( 'Top countries', 'brikpanel' ); ?></h2>
                    <div class="brikpanel-dash-table-wrap" id="top-countries-table">
                        <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                    </div>
                    <h2 class="brikpanel-dash-locations-h2" id="loc-panel-cities-title"><?php esc_html_e( 'Top cities', 'brikpanel' ); ?></h2>
                    <div class="brikpanel-dash-table-wrap" id="top-cities-table">
                        <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                    </div>
                </div>
            </div>
        <?php
    }

    private function render_box_recent_orders() {
        ?>
                <article class="bp-dv-card bp-dv-orders" data-bp-dv="orders">
                    <div class="bp-dv-card-h">
                        <h2 class="bp-dv-title"><?php esc_html_e( 'Recent orders', 'brikpanel' ); ?></h2>
                        <div class="bp-dv-aside"><?php $this->card_link( $this->is_hpos() ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' ), __( 'View all', 'brikpanel' ) ); ?></div>
                    </div>
                    <div class="bp-dv-card-body bp-dv-list-body" data-bp-dv-slot="list"></div>
                </article>
        <?php
    }

    /**
     * A Products card: one list (its name as the title) or several as tabs.
     * Best sellers takes Most viewed pages and Most added to cart in as tabs
     * when they come right after it on the dashboard (plan_rows()).
     *
     * @param string[] $views 'sold', 'viewed', 'cart'.
     * @return void
     */
    private function render_products_card( array $views ) {
        $labels = [
            'sold'   => __( 'Best sellers', 'brikpanel' ),
            // Every page of the store, not only products: the shop page and
            // the home page are on this list too.
            'viewed' => __( 'Most viewed pages', 'brikpanel' ),
            'cart'   => __( 'Most added to cart', 'brikpanel' ),
        ];
        if ( 1 === count( $views ) ) {
            $title = $labels[ $views[0] ];
        } elseif ( in_array( 'viewed', $views, true ) ) {
            $title = __( 'Products and pages', 'brikpanel' );
        } else {
            $title = __( 'Products', 'brikpanel' );
        }
        $ids = [];
        foreach ( $views as $view ) {
            $ids[ $view ] = wp_unique_id( 'bp-dv-products-' . $view . '-' );
        }
        $tabs = [];
        foreach ( $views as $view ) {
            $tabs[ $view ] = [ $labels[ $view ], $ids[ $view ] ];
        }
        // The tab this user opened last time, when this card has it.
        $selected = count( $views ) > 1 ? self::tab_for( 'products', $views, $views[0] ) : $views[0];
        ?>
        <article class="bp-dv-card bp-dv-products" data-bp-dv="products" data-views="<?php echo esc_attr( implode( ' ', $views ) ); ?>">
            <div class="bp-dv-card-h">
                <h2 class="bp-dv-title"><?php echo esc_html( $title ); ?></h2>
                <?php if ( count( $views ) > 1 ) : ?>
                    <div class="bp-dv-aside"><?php $this->tab_row( $title, $tabs, $selected ); ?></div>
                <?php endif; ?>
            </div>
            <?php foreach ( $views as $view ) : ?>
                <div class="bp-dv-panel bp-dv-list-body" id="<?php echo esc_attr( $ids[ $view ] ); ?>" data-bp-dv-view="<?php echo esc_attr( $view ); ?>"<?php echo count( $views ) > 1 ? ' role="tabpanel"' : ''; ?><?php echo $view !== $selected ? ' hidden' : ''; ?>></div>
            <?php endforeach; ?>
            <?php
            // "View all" opens the window with the open tab's whole list
            // (render_pages_window()) once the period has more than the card
            // shows; the script names it and shows it for the open tab.
            ?>
            <div class="bp-dv-card-foot bp-dv-foot-pages">
                <button type="button" class="brikpanel-btn brikpanel-btn--link bp-dv-more bp-dv-pages-open" aria-haspopup="dialog" hidden><span data-bp-dv-slot="pages-label"></span><svg class="bp-dv-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="9 6 15 12 9 18"></polyline></svg></button>
            </div>
        </article>
        <?php
    }

    /**
     * The "About campaigns" help bubble, beside the campaign list in the Top
     * campaigns tab and in the Sources tab's campaign column.
     *
     * @return void
     */
    private function render_campaigns_hint() {
        // A campaign's orders and revenue come from WooCommerce and exist
        // without any visit counted here.
        $this->render_hint(
            __( 'About campaigns', 'brikpanel' ),
            __( 'Orders and revenue come from WooCommerce\'s order attribution: each order counts for the last campaign link its customer came through, even when that visit was before this period.', 'brikpanel' )
                . '<br><br>'
                . __( 'Conversion rate is those orders divided by the visits that arrived from the campaign link in this period, counted once a day per visitor. Visits follow your visitor tracking and cookie consent settings, and a dash means none were counted.', 'brikpanel' ),
            'end'
        );
    }

    /**
     * The Visitors card: devices, traffic sources and top campaigns.
     */
    private function render_box_visitors() {
        $ids = [
            'devices'   => wp_unique_id( 'bp-dv-visitors-devices-' ),
            'sources'   => wp_unique_id( 'bp-dv-visitors-sources-' ),
            'campaigns' => wp_unique_id( 'bp-dv-visitors-campaigns-' ),
        ];
        // The tab this user opened last time.
        $selected = self::tab_for( 'visitors', self::TAB_KEYS['visitors'], 'devices' );
        $hide     = static function ( $view ) use ( $selected ) {
            return $view !== $selected ? ' hidden' : '';
        };
        ?>
            <article class="bp-dv-card bp-dv-visitors" data-bp-dv="visitors">
                <div class="bp-dv-card-h">
                    <h2 class="bp-dv-title"><?php esc_html_e( 'Visitors', 'brikpanel' ); ?></h2>
                    <div class="bp-dv-aside">
                        <?php
                        $this->tab_row(
                            __( 'Visitors', 'brikpanel' ),
                            [
                                'devices'   => [ __( 'Devices', 'brikpanel' ), $ids['devices'] ],
                                'sources'   => [ __( 'Sources', 'brikpanel' ), $ids['sources'] ],
                                'campaigns' => [ __( 'Top campaigns', 'brikpanel' ), $ids['campaigns'] ],
                            ],
                            $selected
                        );
                        ?>
                    </div>
                </div>
                <div class="bp-dv-panel" id="<?php echo esc_attr( $ids['devices'] ); ?>" role="tabpanel" data-bp-dv-view="devices"<?php echo $hide( 'devices' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute. ?>>
                    <div class="bp-dv-dev-blk">
                        <div class="bp-dv-sub"><span><?php esc_html_e( 'Visitors by device', 'brikpanel' ); ?></span></div>
                        <div data-bp-dv-slot="dev-visitors"></div>
                    </div>
                    <div class="bp-dv-dev-blk">
                        <div class="bp-dv-sub"><span><?php esc_html_e( 'Orders by device', 'brikpanel' ); ?></span></div>
                        <div data-bp-dv-slot="dev-orders"></div>
                    </div>
                </div>
                <div class="bp-dv-panel" id="<?php echo esc_attr( $ids['sources'] ); ?>" role="tabpanel" data-bp-dv-view="sources"<?php echo $hide( 'sources' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute. ?>>
                    <div class="bp-dv-src2">
                        <div><h3 class="bp-dv-h3"><?php esc_html_e( 'Channels', 'brikpanel' ); ?></h3><div data-bp-dv-slot="channels"></div></div>
                        <div data-bp-dv-slot="referrers-wrap"><h3 class="bp-dv-h3"><?php esc_html_e( 'Top referrers', 'brikpanel' ); ?></h3><div data-bp-dv-slot="referrers"></div></div>
                        <?php
                        // On a wide card (is-src3) the campaigns join Sources as a
                        // third column and the Top campaigns tab steps aside; a
                        // narrower card keeps the tab, so the card never grows.
                        ?>
                        <div class="bp-dv-src-camps">
                            <h3 class="bp-dv-h3"><?php esc_html_e( 'Top campaigns', 'brikpanel' ); ?><?php $this->render_campaigns_hint(); ?></h3>
                            <div data-bp-dv-slot="campaigns-col"></div>
                        </div>
                    </div>
                </div>
                <div class="bp-dv-panel" id="<?php echo esc_attr( $ids['campaigns'] ); ?>" role="tabpanel" data-bp-dv-view="campaigns"<?php echo $hide( 'campaigns' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute. ?>>
                    <div class="bp-dv-sub">
                        <span><?php esc_html_e( 'Orders from campaign links', 'brikpanel' ); ?></span>
                        <?php $this->render_campaigns_hint(); ?>
                    </div>
                    <div data-bp-dv-slot="campaigns"></div>
                </div>
            </article>
        <?php
        // Wide enough for three columns: decided here, before the first paint,
        // and kept up to date by fitVisitors() in brikpanel-dashboard.js (same
        // 740px). A card opening on Top campaigns shows Sources instead.
        wp_print_inline_script_tag( '(function(){var s=document.currentScript,c=s&&s.previousElementSibling;if(!c||!window.getComputedStyle||!c.classList.contains("bp-dv-visitors")){return;}var y=getComputedStyle(c),w=c.clientWidth-(parseFloat(y.paddingLeft)||0)-(parseFloat(y.paddingRight)||0);if(w<740){return;}c.classList.add("is-src3");var t=c.querySelector(\'[data-bp-dv-tab="campaigns"]\'),o=c.querySelector(\'[data-bp-dv-tab="sources"]\');if(!t||!o||t.getAttribute("aria-selected")!=="true"){return;}[t,o].forEach(function(b){var on=b===o,p=document.getElementById(b.getAttribute("aria-controls"));b.setAttribute("aria-selected",on?"true":"false");if(on){b.removeAttribute("tabindex");}else{b.setAttribute("tabindex","-1");}if(p){p.hidden=!on;}});})();' );
    }

    /**
     * The Customers card: new and returning, the segments in three groups, or both.
     * Customer segments joins the Customers card when it comes right after it
     * on the dashboard; on its own it is a card of its own row (plan_rows()).
     *
     * @param string[] $blocks 'types', 'segments'.
     * @return void
     */
    private function render_customers_card( array $blocks ) {
        $ca_url = function_exists( 'brikpanel_module_url' )
            ? brikpanel_module_url( 'brikpanel-customer-analytics', [], '', false )
            : admin_url( 'admin.php?page=brikpanel-customer-analytics' );
        ?>
        <article class="bp-dv-card bp-dv-customers" data-bp-dv="customers" data-blocks="<?php echo esc_attr( implode( ' ', $blocks ) ); ?>">
            <div class="bp-dv-card-h">
                <h2 class="bp-dv-title"><?php echo esc_html( in_array( 'types', $blocks, true ) ? __( 'Customers', 'brikpanel' ) : __( 'Segments', 'brikpanel' ) ); ?></h2>
                <div class="bp-dv-aside"><?php $this->card_link( (string) $ca_url, __( 'Customer Analytics', 'brikpanel' ) ); ?></div>
            </div>
            <div class="bp-dv-card-body bp-dv-cust-body">
                <?php if ( in_array( 'types', $blocks, true ) ) : ?>
                    <div class="bp-dv-blk" data-block="types">
                        <div class="bp-dv-blk-h"><span><?php esc_html_e( 'New and returning', 'brikpanel' ); ?></span><span class="bp-dv-muted" data-bp-dv-slot="types-meta"></span></div>
                        <div data-bp-dv-slot="types"></div>
                    </div>
                <?php endif; ?>
                <?php if ( in_array( 'segments', $blocks, true ) ) : ?>
                    <div class="bp-dv-blk" data-block="segments">
                        <div class="bp-dv-blk-h">
                            <span><?php echo esc_html( in_array( 'types', $blocks, true ) ? __( 'Segments', 'brikpanel' ) : __( 'Out of every 100 customers', 'brikpanel' ) ); ?></span>
                            <span class="bp-dv-muted bp-dv-blk-meta">
                                <?php if ( in_array( 'types', $blocks, true ) ) : ?><span><?php esc_html_e( 'Out of every 100 customers', 'brikpanel' ); ?></span><?php endif; ?>
                                <span data-bp-dv-slot="seg-total"></span>
                            </span>
                        </div>
                        <div data-bp-dv-slot="segments"></div>
                    </div>
                <?php endif; ?>
            </div>
        </article>
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
                        ON pm.post_id = p.ID AND pm.meta_key = '_customer_user_agent' AND " . brikpanel_sql_first_meta_guard( 'post', 'pm' ) . "
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

    private function render_box_low_stock() {
        $catalog = $this->get_catalog_counts();
        // Compact catalog-size line under the Low stock heading, joined with a
        // middot: products (catalog entries), variations, sellable items
        // (purchasable products + variations, counting a variable product by
        // its variations) and units on hand. Units are left out when no
        // product tracks stock: "0 units in stock" there read as "empty
        // shelves" right above "all products are sufficiently stocked" (field
        // test E3). No line at all without products; the card says why.
        $catalog_parts = array();
        if ( $catalog['products'] > 0 ) {
            $catalog_parts[] = brikpanel_safe_sprintf(
                /* translators: %s: number of published products */
                _n( '%s product', '%s products', $catalog['products'], 'brikpanel' ),
                brikpanel_number( $catalog['products'] )
            );
            $catalog_parts[] = brikpanel_safe_sprintf(
                /* translators: %s: number of product variations */
                _n( '%s variation', '%s variations', $catalog['variations'], 'brikpanel' ),
                brikpanel_number( $catalog['variations'] )
            );
            $catalog_parts[] = brikpanel_safe_sprintf(
                /* translators: %s: number of purchasable items (products + variations) */
                _n( '%s sellable item', '%s sellable items', $catalog['sellable'], 'brikpanel' ),
                brikpanel_number( $catalog['sellable'] )
            );
            if ( $catalog['managed'] > 0 ) {
                $catalog_parts[] = brikpanel_safe_sprintf(
                    /* translators: %s: total number of stock units on hand across the store */
                    _n( '%s unit in stock', '%s units in stock', $catalog['stock_units'], 'brikpanel' ),
                    brikpanel_number( $catalog['stock_units'] )
                );
            }
        }
        ?>
                <article class="bp-dv-card bp-dv-stock" data-bp-dv="stock">
                    <div class="bp-dv-card-h">
                        <h2 class="bp-dv-title"><?php esc_html_e( 'Low stock', 'brikpanel' ); ?></h2>
                    </div>
                    <?php if ( $catalog_parts ) : ?>
                    <p class="brikpanel-dash-inv-line" title="<?php esc_attr_e( 'Sellable items counts each variable product by its purchasable variations, not as a single product. Units in stock is the total on-hand quantity across all stock-managed products.', 'brikpanel' ); ?>">
                        <?php echo esc_html( implode( '  ·  ', $catalog_parts ) ); ?>
                    </p>
                    <?php endif; ?>
                    <div class="brikpanel-dash-table-wrap" id="low-stock-table">
                        <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                    </div>
                </article>
        <?php
    }

    private function render_box_ltv() {
        $ca_url = function_exists( 'brikpanel_module_url' )
            ? brikpanel_module_url( 'brikpanel-customer-analytics', [], '', false )
            : admin_url( 'admin.php?page=brikpanel-customer-analytics' );
        ?>
                <article class="bp-dv-card bp-dv-ltv" data-bp-dv="ltv">
                    <div class="bp-dv-card-h">
                        <h2 class="bp-dv-title"><?php esc_html_e( 'Customer lifetime value', 'brikpanel' ); ?></h2>
                        <div class="bp-dv-aside"><?php $this->card_link( (string) $ca_url, __( 'View details', 'brikpanel' ) ); ?></div>
                    </div>
                    <div id="brikpanel-ltv-panel">
                        <p class="brikpanel-dash-empty"><?php esc_html_e( 'Loading...', 'brikpanel' ); ?></p>
                    </div>
                </article>
        <?php
    }

    /**
     * What the Low stock card says when no product is running low. It used to
     * say "All products are sufficiently stocked" whatever the reason, also
     * with no products, with stock tracking off everywhere, and next to
     * fifteen products that had run out, which the list leaves out (field
     * test E3). Plain text plus an optional link, rendered with textContent.
     *
     * @param array $catalog get_catalog_counts().
     * @return array{text:string, link:string, url:string}
     */
    private function low_stock_empty_state( array $catalog ) {
        $out = array( 'text' => '', 'link' => '', 'url' => '' );
        if ( $catalog['products'] < 1 ) {
            $out['text'] = __( 'No products yet.', 'brikpanel' );
        } elseif ( 'no' === get_option( 'woocommerce_manage_stock', 'yes' ) ) {
            $out['text'] = __( 'Stock management is turned off in WooCommerce, so stock levels are not tracked.', 'brikpanel' );
            if ( current_user_can( 'manage_woocommerce' ) ) {
                $out['link'] = __( 'Inventory settings', 'brikpanel' );
                $out['url']  = admin_url( 'admin.php?page=wc-settings&tab=products&section=inventory' );
            }
        } elseif ( $catalog['managed'] < 1 ) {
            $out['text'] = __( 'None of your products track their stock quantity, so none can run low.', 'brikpanel' );
        } else {
            $out['text'] = __( 'No product is running low.', 'brikpanel' );
        }
        if ( $catalog['out_of_stock'] > 0 ) {
            $out['link'] = brikpanel_safe_sprintf(
                /* translators: %s: number of products that are out of stock. */
                _n( '%s product is out of stock', '%s products are out of stock', $catalog['out_of_stock'], 'brikpanel' ),
                brikpanel_number( $catalog['out_of_stock'] )
            );
            $out['url']  = brikpanel_out_of_stock_url();
        }
        return $out;
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
             INNER JOIN {$wpdb->postmeta} pm_manage   ON p.ID = pm_manage.post_id   AND pm_manage.meta_key = '_manage_stock' AND " . brikpanel_sql_first_meta_guard( 'post', 'pm_manage' ) . "
             INNER JOIN {$wpdb->postmeta} pm_stock    ON p.ID = pm_stock.post_id    AND pm_stock.meta_key = '_stock' AND " . brikpanel_sql_first_meta_guard( 'post', 'pm_stock' ) . "
             LEFT  JOIN {$wpdb->postmeta} pm_threshold ON p.ID = pm_threshold.post_id AND pm_threshold.meta_key = '_low_stock_amount' AND " . brikpanel_sql_first_meta_guard( 'post', 'pm_threshold' ) . "
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
            // Name and (for a variation) its options, plain text, no SKU:
            // the table has its own SKU column (field test E3).
            $name = brikpanel_product_label( $product );

            // For variations the link should point at the parent product
            // editor (variations don't have their own edit screen).
            $edit_id  = $row->post_type === 'product_variation'
                ? (int) wp_get_post_parent_id( $row->ID )
                : (int) $row->ID;
            $edit_url = $edit_id ? admin_url( 'post.php?post=' . $edit_id . '&action=edit' ) : '';

            // The card shows a variation as its product's name with the
            // options on a quieter line under it ("Classic Hoodie" / "Black,
            // S"); `name` stays the one-line label for the Excel export.
            $title   = $name;
            $variant = '';
            if ( 'product_variation' === $row->post_type && function_exists( 'wc_get_formatted_variation' ) ) {
                $parent = wc_get_product( $product->get_parent_id() );
                if ( $parent ) {
                    $title   = brikpanel_plain_label( $parent->get_name() );
                    $variant = brikpanel_plain_label( wc_get_formatted_variation( $product, true, false, false ) );
                }
            }

            $products[] = [
                'id'       => (int) $row->ID,
                'name'     => $name,
                'title'    => $title,
                'variant'  => $variant,
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
     *   - managed    : published products/variations that track stock
     *   - out_of_stock : published products marked out of stock (the set the
     *                  top bar counts and brikpanel_out_of_stock_url() lists)
     *
     * @return array{products:int,variations:int,variable:int,sellable:int,stock_units:int,managed:int,out_of_stock:int}
     */
    private function get_catalog_counts(): array {
        $cached = get_transient( 'brikpanel_catalog_counts' );
        // Require the full current key set: a transient written by an older
        // build (before managed/out_of_stock existed) is treated as a miss so
        // the view never reads an undefined key.
        if ( is_array( $cached ) && isset( $cached['stock_units'], $cached['managed'], $cached['out_of_stock'] ) ) {
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
        // Only stock on hand: an item on backorder carries a negative _stock,
        // which used to be subtracted from everyone else's units.
        // COUNT(*) over the same rows is how many items track stock at all.
        $stock_row = $wpdb->get_row(
            "SELECT SUM(GREATEST(CAST(pm.meta_value AS SIGNED), 0)) AS units, COUNT(*) AS managed
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->postmeta} ms ON pm.post_id = ms.post_id AND ms.meta_key = '_manage_stock' AND " . brikpanel_sql_first_meta_guard( 'post', 'ms' ) . "
             INNER JOIN {$wpdb->posts} p     ON pm.post_id = p.ID
             WHERE pm.meta_key = '_stock'
               AND " . brikpanel_sql_first_meta_guard( 'post', 'pm' ) . "
               AND ms.meta_value = 'yes'
               AND p.post_status = 'publish'
               AND p.post_type IN ('product','product_variation')
               AND pm.meta_value REGEXP '^-?[0-9]+([.][0-9]+)?$'"
        );
        $stock_units = $stock_row ? (int) $stock_row->units : 0;
        $managed     = $stock_row ? (int) $stock_row->managed : 0;

        // Out-of-stock published products, counted like the top bar does
        // (the parent row of a variable product is out of stock when all of
        // its variations are).
        $out_of_stock = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} ss ON ss.post_id = p.ID AND ss.meta_key = '_stock_status' AND " . brikpanel_sql_first_meta_guard( 'post', 'ss' ) . "
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'
               AND ss.meta_value = 'outofstock'"
        );

        $result = [
            'products'     => $products,
            'variations'   => $variations,
            'variable'     => $variable,
            'sellable'     => $sellable,
            'stock_units'  => $stock_units,
            'managed'      => $managed,
            'out_of_stock' => $out_of_stock,
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
     * The WordPress dashboard widgets this user gets on the BrikPanel
     * dashboard: the ones picked in Settings, in that order, each passing its
     * own audience rule (Everyone / Admins only / Specific roles) so the owner
     * can keep sensitive widgets like Site Health limited by role. Worked out
     * once per request: the row planner asks before the widgets render.
     *
     * @return array<string,array> Widget id => widget.
     */
    private function embedded_wp_widgets() {
        if ( null !== $this->wp_widgets_chosen ) {
            return $this->wp_widgets_chosen;
        }
        $this->wp_widgets_chosen = [];
        $selected = (array) get_option( 'brikpanel_dashboard_wp_widgets', [] );
        if ( empty( $selected ) || ! function_exists( 'brikpanel_collect_dashboard_widgets' ) ) {
            return $this->wp_widgets_chosen;
        }
        $widgets = brikpanel_collect_dashboard_widgets();
        foreach ( $selected as $widget_id ) {
            if ( ! is_string( $widget_id ) || ! isset( $widgets[ $widget_id ] ) ) {
                continue;
            }
            if ( function_exists( 'brikpanel_dashboard_widget_audience_allows' )
                && ! brikpanel_dashboard_widget_audience_allows( $widget_id ) ) {
                continue;
            }
            $this->wp_widgets_chosen[ $widget_id ] = $widgets[ $widget_id ];
        }
        return $this->wp_widgets_chosen;
    }

    /**
     * Render user-selected WordPress dashboard widgets inside the BrikPanel
     * dashboard, styled as BrikPanel cards in a responsive 3-column grid.
     */
    public function render_embedded_wp_widgets() {
        $chosen = $this->embedded_wp_widgets();
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

        // "Tax in the Profit section" (Settings, Dashboard). Inside Expenses
        // (default): Revenue is what customers paid, tax included, and the tax
        // is one of the Expenses lines. Taken out: Revenue is shown without the
        // tax and Expenses no longer carries it. Kept in Revenue: Revenue keeps
        // the tax and names the amount under its figure, Expenses drop it and
        // Net profit takes it off. Net profit comes out the same in all three.
        $tax_mode       = function_exists( 'brikpanel_profit_tax_mode' ) ? brikpanel_profit_tax_mode() : 'expenses';
        $tax_excluded   = 'excluded' === $tax_mode;
        $tax_in_revenue = 'revenue' === $tax_mode;

        // Expenses breakdown. External costs (ad spend, tax) keep their fixed
        // translated labels; manual expenses are listed by their OWN category
        // (Salaries, Rent, Shipping carriers, …) instead of a single "Other"
        // lump, so owners see where the money actually went. The purchase-order
        // category is relabelled to the friendly "Supplier / stock"; a blank
        // category falls back to "Other".
        $fixed_labels = [
            'google_ads' => __( 'Google Ads', 'brikpanel' ),
            'meta_ads'   => __( 'Meta Ads', 'brikpanel' ),
        ];
        if ( 'expenses' === $tax_mode ) {
            $fixed_labels['tax'] = __( 'Tax', 'brikpanel' );
        }
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
            $flat[] = [
                'title'  => (string) ( $pe['title'] ?? '' ),
                'parent' => (string) ( $pe['parent'] ?? '' ),
                'row'    => [
                    'key'    => 'percent',
                    'label'  => (string) ( $pe['title'] ?? '' ) . ' (' . brikpanel_percent( (float) ( $pe['rate'] ?? 0 ), 2 ) . ')',
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
        $tax      = (float) ( $s['tax_raw'] ?? 0 );

        $rev_raw = $returns_on ? ( $gross - $returns ) : $gross; // figure shown on the Revenue card
        if ( $tax_excluded ) {
            // Moved, not dropped: the same amount leaves both sides, so
            // Revenue − Cost of goods − Expenses lands on the same Net profit.
            $rev_raw  -= $tax;
            $expenses -= $tax;
        } elseif ( $tax_in_revenue ) {
            // Out of Expenses only; Net profit takes it off instead:
            // Revenue − Cost of goods − Tax − Expenses.
            $expenses -= $tax;
        }
        $net_raw = $rev_raw - $cogs - $expenses - ( $tax_in_revenue ? $tax : 0 );

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
        if ( ( $returns_on && $returns > 0 ) || ( $coupons_on && $coupons > 0 ) || ( $tax_excluded && $tax > 0 ) ) {
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
            // Taken out of Revenue by the setting: the one place the tax still
            // shows, so the smaller Revenue figure explains itself.
            if ( $tax_excluded && $tax > 0 ) {
                $rev_breakdown[] = [
                    'key'    => 'tax',
                    'label'  => __( 'Tax', 'brikpanel' ),
                    'amount' => wc_price( $tax ),
                    'raw'    => $tax,
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
            // Whether the setting took tax out of Revenue and Expenses above,
            // or kept it in Revenue. In that case `tax_note` is the line under
            // the Revenue figure, plain text ready for textContent.
            'tax_excluded'   => $tax_excluded,
            'tax_in_revenue' => $tax_in_revenue,
            'tax_note'       => ( $tax_in_revenue && $tax > 0 )
                /* translators: %s: tax amount, for example "$837.56". */
                ? sprintf( __( 'Includes %s tax', 'brikpanel' ), brikpanel_money_text( $tax ) )
                : '',
            'tax_raw'        => $tax,
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
            'texts'         => $this->profit_texts( $s, $rev_raw, $cogs_pct, $expenses_pct, $margin ),
        ];
    }

    /**
     * The sentences of the profit cards, finished here: the counts pick their
     * plural form with _n() (the browser has no plural rules; Russian and
     * Polish have three forms), and a percentage is one placeholder filled by
     * brikpanel_percent(), so each language puts the sign where it belongs.
     * They used to be English templates with "%d" and "% of revenue" pasted
     * together in the browser: "0% gelirin" in Turkish (field test E9).
     *
     * A share of revenue is left empty when there was no revenue: it is not
     * 0% of nothing.
     *
     * @param array $s            Profit summary.
     * @param float $rev_raw      Revenue shown on the card.
     * @param float $cogs_pct     Cost of goods, % of revenue.
     * @param float $expenses_pct Expenses, % of revenue.
     * @param float $margin       Net profit, % of revenue.
     * @return array<string,string>
     */
    private function profit_texts( array $s, $rev_raw, $cogs_pct, $expenses_pct, $margin ) {
        $share = static function ( $pct ) use ( $rev_raw ) {
            return $rev_raw > 0
                /* translators: %s: a percentage of revenue, already formatted with its percent sign, e.g. "12.5%". */
                ? brikpanel_safe_sprintf( __( '%s of revenue', 'brikpanel' ), brikpanel_percent( $pct ) )
                : '';
        };

        $missing_lines = (int) ( $s['cogs_missing_lines'] ?? 0 );
        $missing_prods = is_array( $s['cogs_missing_products'] ?? null ) ? count( $s['cogs_missing_products'] ) : 0;
        $unconverted   = (int) ( $s['payment_fees_unconverted'] ?? 0 );
        $fees_missing  = (int) ( $s['payment_fees_missing'] ?? 0 );
        $fees_raw      = (float) ( $s['payment_fees_raw'] ?? 0 );

        $fees_tip = '';
        if ( $unconverted > 0 ) {
            $fees_tip = brikpanel_safe_sprintf(
                /* translators: %s: number of orders. */
                _n(
                    'Processing fees on %s order are in a currency with no exchange rate, so they are not counted. Add a rate to include them.',
                    'Processing fees on %s orders are in a currency with no exchange rate, so they are not counted. Add a rate to include them.',
                    $unconverted,
                    'brikpanel'
                ),
                brikpanel_number( $unconverted )
            );
        } elseif ( $fees_missing > 0 && $fees_raw > 0 ) {
            $fees_tip = brikpanel_safe_sprintf(
                /* translators: %s: number of orders. */
                _n(
                    '%s order has no payment fee recorded, so processing costs are only counted on the rest.',
                    '%s orders have no payment fee recorded, so processing costs are only counted on the rest.',
                    $fees_missing,
                    'brikpanel'
                ),
                brikpanel_number( $fees_missing )
            );
        } elseif ( $fees_missing > 0 ) {
            $fees_tip = __( 'Payment fees are turned on, but none of the orders in this period record a processing fee. Your payment gateway may not store one, so this cost is not included.', 'brikpanel' );
        }

        return [
            'cogs_share'     => $share( $cogs_pct ),
            'expenses_share' => $share( $expenses_pct ),
            'margin_share'   => $share( $margin ),
            'cogs_partial'   => $missing_lines > 0
                ? brikpanel_safe_sprintf(
                    /* translators: %s: number of sold items. */
                    _n( 'Cost missing on %s item, profit overstated', 'Cost missing on %s items, profit overstated', $missing_lines, 'brikpanel' ),
                    brikpanel_number( $missing_lines )
                )
                : '',
            'estimate_tip'   => $missing_lines > 0
                ? brikpanel_safe_sprintf(
                    /* translators: %s: number of sold items. */
                    _n(
                        '%s sold item has no cost set. Add its “Cost of goods” so Net profit is accurate.',
                        '%s sold items have no cost set. Add their “Cost of goods” so Net profit is accurate.',
                        $missing_lines,
                        'brikpanel'
                    ),
                    brikpanel_number( $missing_lines )
                )
                : '',
            'cogs_missing_aria' => $missing_prods > 0
                ? brikpanel_safe_sprintf(
                    /* translators: %s: number of products. */
                    _n( '%s product is missing a cost', '%s products are missing a cost', $missing_prods, 'brikpanel' ),
                    brikpanel_number( $missing_prods )
                )
                : '',
            'fees_tip'       => $fees_tip,
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
        // Where tax sits in the Profit section moves Revenue, Expenses and every
        // share of revenue, so it is part of the identity for the same reason:
        // e (inside Expenses), x (taken out), r (kept in Revenue).
        $tax_keys    = [ 'expenses' => 'e', 'excluded' => 'x', 'revenue' => 'r' ];
        $tax_mode    = function_exists( 'brikpanel_profit_tax_mode' ) ? brikpanel_profit_tax_mode() : 'expenses';
        $tax_for_key = $tax_keys[ $tax_mode ] ?? 'e';
        // The payload carries text in the requesting admin's language (month
        // names in the Recent Orders dates and chart labels, "Guest"), so
        // admins who use different languages must not share one copy.
        $locale_for_key = sanitize_key( function_exists( 'determine_locale' ) ? determine_locale() : get_locale() );
        // "f2": the payload carries the store state behind the empty cards and
        // a zero-filled sales series since field test F, and the store state
        // says whether a visit was ever counted since f2; an older copy lacks
        // those. "f3": abandoned carts and top campaigns joined the payload.
        // "g1": the redesigned cards (October 2026) read the sales series with
        // its previous period and hours, order-rate counts, segment groups,
        // the low-stock product and options lines and the order times. "g3":
        // Most viewed pages lists only pages a shopper can open, and the three
        // Products and pages lists carry their counts (most_viewed_total,
        // top_products_total, most_cart_total).
        $cache_key = 'bp_dash_g3_' . $cache_ver . '_' . $range_key . '_mp' . $exclude_mp_for_key . '_sc' . $shipping_for_key . '_pf' . $fees_for_key . '_tx' . $tax_for_key . '_' . $locale_for_key;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( $this->with_tracking_state( $this->with_today( $cached ) ) );
        }

        $payload = $this->build_dashboard_payload( $range, $custom_start, $custom_end );
        // Store-wide facts (any order ever, the latest paid day, any visit
        // ever): they change with orders, like the rest of this cached copy.
        $payload['store'] = $this->store_state();

        $ttl = function_exists( 'brikpanel_cache_ttl' )
            ? brikpanel_cache_ttl( self::CACHE_TTL )
            : self::CACHE_TTL;
        set_transient( $cache_key, $payload, $ttl );

        wp_send_json_success( $this->with_tracking_state( $this->with_today( $payload ) ) );
    }

    /**
     * Add the live card's "Today so far" block. Kept out of the cached copy
     * (it has its own, shorter cache) and out of the Excel export.
     *
     * @param mixed $payload Dashboard payload.
     * @return mixed
     */
    private function with_today( $payload ) {
        if ( ! is_array( $payload ) ) {
            return $payload;
        }
        try {
            $payload['today'] = $this->today_block();
        } catch ( Throwable $e ) {
            // The card falls back to its plain empty sentence.
            $payload['today'] = null;
        }
        return $payload;
    }

    /**
     * Add the visitor tracking settings the empty visitor cards explain
     * themselves with. Read on every response, outside the cached copy: a
     * settings change busts nothing, and the cards must not keep blaming a
     * setting that was just changed.
     *
     * @param mixed $payload Dashboard payload.
     * @return mixed
     */
    private function with_tracking_state( $payload ) {
        if ( ! is_array( $payload ) ) {
            return $payload;
        }
        $payload['tracking'] = [
            'enabled' => function_exists( 'brikpanel_frontend_tracking_enabled' ) ? brikpanel_frontend_tracking_enabled() : true,
            'consent' => function_exists( 'brikpanel_consent_required' ) && brikpanel_consent_required(),
        ];
        // The abandoned-carts line shows only while carts are collected. Read
        // here, on every response: switching collection off busts nothing.
        $payload['abandoned_carts_on'] = class_exists( 'Brikpanel_Cart_Abandonment' ) && Brikpanel_Cart_Abandonment::is_enabled();
        // The server's clock, so "12 min ago" does not depend on the
        // viewer's computer clock.
        $payload['now'] = time();
        return $payload;
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
        // Same orders as $order_count, so it reads as part of the Orders card.
        $items_sold    = $this->get_items_sold( $start_gmt, $end_gmt, $exclude_mp );
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

        // Sales over time, every local day of the range (days without sales at 0)
        $sales_over_time = $this->get_sales_over_time( $start_gmt, $end_gmt, $exclude_mp, $start_local, $end_local );
        // The same sales for the chart and the store cards' small lines: hour
        // by hour for a single day, with the previous period and daily visitors.
        $sales_series = $this->build_sales_series( $dates, $exclude_mp );

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
        // Campaigns: orders and revenue from WooCommerce's order attribution,
        // visits from BrikPanel's own campaign counter.
        $top_campaigns = $this->get_campaigns( $start_gmt, $end_gmt, $start_local, $end_local, 5, $exclude_mp );

        // Carts first left in this period, compared with the previous one.
        $abandoned_carts = $this->get_abandoned_carts( $start_gmt, $end_gmt, $prev_start_gmt, $prev_end_gmt );

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
                    // The Customers card shows the segments in three groups.
                    'group'     => self::RFM_GROUPS[ $seg_key ] ?? 'attention',
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
            // Units in those paid orders. The label is shown as it comes
            // (Orders card, Order Rates head); '' when nothing was sold.
            'items_sold'       => $items_sold,
            'items_sold_label' => $this->items_sold_label( $items_sold ),
            'aov'              => wc_price( $aov ),
            'aov_raw'          => $aov,
            'visitor_count'    => $visitor_count,
            'visitor_count_display'   => brikpanel_dash_format_count( $visitor_count ),
            'conversion_rate'  => $conversion,
            // Finished text: store separators, the percent sign where the
            // viewer's language writes it ("%1.25" in Turkish).
            'conversion_rate_pct' => brikpanel_percent( (float) $conversion, 2, false ),
            'funnel'           => [
                'visitors' => $visitor_count,
                'products' => $product_views,
                'cart'     => $add_to_cart,
                'checkout' => $checkout_count,
                'orders'   => $order_count,
            ],
            'order_rates'      => $order_rates,
            'top_products'     => $top_products['rows'],
            // Products of the period in all: the cards' "View all" button.
            'top_products_total' => $top_products['total'],
            'most_viewed'      => $most_viewed['rows'],
            // Pages of the period in all: the card's "View all" link.
            'most_viewed_total' => $most_viewed['total'],
            'most_cart'        => $most_cart['rows'],
            'most_cart_total'  => $most_cart['total'],
            'sales_over_time'      => $sales_over_time,
            'sales_series'     => $sales_series,
            'recent_orders'    => $recent_orders,
            'order_locations'  => $order_locations,
            'devices'          => $devices,
            'order_devices'    => $order_devices,
            'sources'          => $sources,
            'top_referrers'    => $top_referrers,
            'top_campaigns'    => $top_campaigns,
            'abandoned_carts'  => $abandoned_carts,
            'customer_types'     => $customer_types,
            'subscription_stats' => $subscription_stats,
            'low_stock'          => $low_stock,
            // What to say instead of the table when nothing is running low.
            'low_stock_empty'    => empty( $low_stock ) ? $this->low_stock_empty_state( $this->get_catalog_counts() ) : null,
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
        // Same nonce and capability as the dashboard page and its data: a shop
        // manager sees the page, so the card on it fills in for them too.
        if ( ! check_ajax_referer( 'brikpanel_dashboard_nonce', 'security', false ) ) {
            wp_send_json_error( 'Invalid nonce.' );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // One rule for every reader of the Live list (ping timeout, idle limit,
        // traffic source setting): back-end/live/brikpanel-live.php. Without
        // that module nothing writes the list, so there is nobody to show.
        $visitors = function_exists( 'brikpanel_live_active_visitors' ) ? brikpanel_live_active_visitors() : [];
        // Page names are looked up here, not in the shared read: the top bar
        // only counts the rows.
        if ( $visitors && function_exists( 'brikpanel_live_page_names' ) ) {
            $visitors = brikpanel_live_page_names( $visitors );
        }
        // Seconds since the visitor last did something, on the server's
        // clock: the card sorts by it and writes "3 min ago".
        $now = time();
        foreach ( $visitors as $i => $row ) {
            $last = isset( $row['interacted_at'] ) ? (int) $row['interacted_at'] : ( isset( $row['last_active'] ) ? (int) $row['last_active'] : $now );
            $visitors[ $i ]['ago'] = max( 0, $now - $last );
        }
        wp_send_json_success( $visitors );
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
            '7days'     => __( 'Last 7 days', 'brikpanel' ),
            '30days'    => __( 'Last 30 days', 'brikpanel' ),
            '90days'    => __( 'Last 90 days', 'brikpanel' ),
            'custom'    => __( 'Custom range', 'brikpanel' ),
        ];
        $label   = $labels[ $range ] ?? $labels['custom'];
        $fmt     = brikpanel_date_format();
        // $start_date / $end_date are already SITE-LOCAL Y-m-d. strtotime()
        // reads them as UTC and wp_date() then adds the site offset on top, so
        // every store west of UTC printed a caption one day behind the window
        // the query above actually ran. The query is correct; only this label
        // was double-converting.
        $from    = brikpanel_local_label_date( $start_date, $fmt );
        $to      = brikpanel_local_label_date( $end_date, $fmt );
        $days    = max( 1, (int) $days );

        if ( $from === $to ) {
            $range_str = $from;
        } else {
            /* translators: Separator between the two dates of a date range, with spaces, e.g. " to " in "Sep 1 to Sep 22". */
            $range_str = $from . _x( ' to ', 'date range separator', 'brikpanel' ) . $to;
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

    /**
     * Carts first left in the period, for the line under the funnel: how many,
     * their value, how many were bought back since, and the change in the
     * count against the previous period (more carts left is the bad
     * direction, which the screen colours accordingly).
     *
     * @return array|null Null without the abandoned-carts module or its table.
     */
    private function get_abandoned_carts( $start_gmt, $end_gmt, $prev_start_gmt, $prev_end_gmt ) {
        if ( ! class_exists( 'Brikpanel_Cart_Abandonment' ) || ! method_exists( 'Brikpanel_Cart_Abandonment', 'period_stats' ) ) {
            return null;
        }
        $current = Brikpanel_Cart_Abandonment::period_stats( $start_gmt, $end_gmt );
        if ( null === $current ) {
            return null;
        }
        $previous = Brikpanel_Cart_Abandonment::period_stats( $prev_start_gmt, $prev_end_gmt );
        $prev_n   = $previous ? (int) $previous['count'] : 0;

        return [
            'count'      => (int) $current['count'],
            'prev_count' => $prev_n,
            'delta'      => $this->calc_delta( (int) $current['count'], $prev_n ),
            'value'      => $this->cart_value_text( $current['totals'] ),
            'recovered'  => (int) $current['recovered'],
            'rate'       => $current['count'] > 0 ? round( $current['recovered'] / $current['count'] * 100, 1 ) : null,
        ];
    }

    /**
     * The value of a set of carts as one line of text. Carts keep the currency
     * they were filled in: the store's currency and any with a manual exchange
     * rate (Settings, Currency) are added up in the store's currency, any other
     * follows as its own amount ("$1,200.00 + €80.00"), as on the Abandoned
     * Carts page.
     *
     * @param array<string,float> $totals Amount per currency code ('' = the store's).
     * @return string Plain text.
     */
    private function cart_value_text( array $totals ) {
        $base     = function_exists( 'brikpanel_base_currency' ) ? strtoupper( (string) brikpanel_base_currency() ) : strtoupper( (string) get_option( 'woocommerce_currency' ) );
        $in_base  = 0.0;
        $has_base = false;
        $others   = [];
        foreach ( $totals as $currency => $amount ) {
            $currency = strtoupper( (string) $currency );
            if ( '' === $currency || $currency === $base ) {
                $in_base += (float) $amount;
                $has_base = true;
                continue;
            }
            $factor = function_exists( 'brikpanel_manual_fx_factor' ) ? (float) brikpanel_manual_fx_factor( $currency ) : 0.0;
            if ( $factor > 0 ) {
                $in_base += (float) $amount * $factor;
                $has_base = true;
                continue;
            }
            $others[ $currency ] = (float) $amount;
        }
        $parts = [];
        if ( $has_base || ! $others ) {
            $parts[] = brikpanel_money_text( $in_base );
        }
        foreach ( $others as $currency => $amount ) {
            $parts[] = brikpanel_money_text( $amount, [ 'currency' => $currency ] );
        }
        return implode( ' + ', $parts );
    }

    /**
     * The order meta key WooCommerce keeps an order-attribution field under.
     * WooCommerce lets the prefix be changed with a filter, so it is asked.
     *
     * @param string $field Field name, e.g. 'utm_campaign'.
     * @return string
     */
    private function wc_attribution_meta_key( $field ) {
        $prefix = (string) apply_filters( 'wc_order_attribution_tracking_field_prefix', 'wc_order_attribution_' );
        $prefix = trim( $prefix, '_' );
        $key    = '_' . ( '' !== $prefix ? $prefix . '_' : '' ) . $field;
        return preg_match( '/^[A-Za-z0-9_\-]{1,255}$/', $key ) ? $key : '_wc_order_attribution_' . $field;
    }

    /**
     * Campaigns of the period, the ones that brought most revenue first.
     *
     * Orders and revenue: paid orders WooCommerce credited to a campaign (its
     * order attribution keeps the last campaign link the customer came
     * through), on the same basis as the Orders card: admin-placed orders out,
     * marketplace orders out while BrikMarket is on, totals in the store's
     * currency. Visits: BrikPanel's own count of visits that landed on a
     * campaign link. The two are matched by name, ignoring case.
     *
     * @param int $limit Rows to return (the dashboard shows 5, Excel all).
     * @return array<int, array{name:string, orders:int, revenue:float, revenue_text:string, visits:int, conversion:float|null}>
     */
    private function get_campaigns( $start_gmt, $end_gmt, $start_local, $end_local, $limit = 5, $exclude_marketplace = false ) {
        global $wpdb;

        $statuses     = brikpanel_paid_order_statuses();
        $placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
        $meta_key     = $this->wc_attribution_meta_key( 'utm_campaign' );
        $is_hpos      = $this->is_hpos();
        $exclusion    = brikpanel_admin_order_exclusion_sql( $is_hpos, 'p.ID' );
        $mp_excl      = $exclude_marketplace
            ? brikpanel_marketplace_order_exclusion_sql( $is_hpos, $is_hpos ? 'o.id' : 'p.ID' )
            : [ 'sql' => '', 'args' => [] ];
        $args         = array_merge( $statuses, $exclusion['args'], $mp_excl['args'], [ $start_gmt, $end_gmt ] );

        if ( $is_hpos ) {
            $admin_sql = str_replace( 'customer_id', 'o.customer_id', $exclusion['sql'] );
            $fx        = brikpanel_base_total_sql( true, 'o.id', 'o.total_amount', 'bpfxcmp' );
            $sql       = "SELECT cm.meta_value AS campaign, COUNT(*) AS orders, COALESCE(SUM({$fx['expr']}), 0) AS revenue
                 FROM {$wpdb->prefix}wc_orders o"
                . brikpanel_sql_single_meta_join( 'order', 'cm', 'o.id', $meta_key, '', 'INNER' )
                . "{$fx['join']}
                 WHERE o.type = 'shop_order'
                 AND o.status IN ({$placeholders}){$admin_sql}{$mp_excl['sql']}
                 AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
                 AND cm.meta_value <> '' AND cm.meta_value <> '(none)'
                 GROUP BY cm.meta_value
                 ORDER BY revenue DESC
                 LIMIT 500";
        } else {
            $fx  = brikpanel_base_total_sql( false, 'p.ID', 'CAST(pm_total.meta_value AS DECIMAL(20,4))', 'bpfxcmp' );
            $sql = "SELECT cm.meta_value AS campaign, COUNT(*) AS orders, COALESCE(SUM({$fx['expr']}), 0) AS revenue
                 FROM {$wpdb->posts} p"
                . brikpanel_sql_single_meta_join( 'post', 'cm', 'p.ID', $meta_key, '', 'INNER' )
                . brikpanel_sql_single_meta_join( 'post', 'pm_total', 'p.ID', '_order_total' )
                . "{$fx['join']}
                 WHERE p.post_type = 'shop_order'
                 AND p.post_status IN ({$placeholders}){$exclusion['sql']}{$mp_excl['sql']}
                 AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s
                 AND cm.meta_value <> '' AND cm.meta_value <> '(none)'
                 GROUP BY cm.meta_value
                 ORDER BY revenue DESC
                 LIMIT 500";
        }
        $order_rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $visit_rows = [];
        $table      = $wpdb->prefix . 'brikpanel_campaign_visits';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
            $visit_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT campaign, SUM(hits) AS hits FROM {$table} WHERE date_column BETWEEN %s AND %s GROUP BY campaign ORDER BY hits DESC LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $start_local,
                $end_local
            ) );
        }

        $normalize = function_exists( 'brikpanel_campaign_normalize' )
            ? 'brikpanel_campaign_normalize'
            : static function ( $raw ) { return trim( (string) $raw ); };
        $lower     = function_exists( 'brikpanel_strtolower' ) ? 'brikpanel_strtolower' : 'strtolower';

        $by_key = [];
        foreach ( (array) $order_rows as $row ) {
            $name = $normalize( $row->campaign );
            if ( '' === $name ) {
                continue;
            }
            $key = $lower( $name );
            if ( ! isset( $by_key[ $key ] ) ) {
                $by_key[ $key ] = [ 'name' => $name, 'orders' => 0, 'revenue' => 0.0, 'visits' => 0 ];
            }
            $by_key[ $key ]['orders']  += (int) $row->orders;
            $by_key[ $key ]['revenue'] += (float) $row->revenue;
        }
        foreach ( (array) $visit_rows as $row ) {
            $name = $normalize( $row->campaign );
            if ( '' === $name ) {
                continue;
            }
            $key = $lower( $name );
            if ( ! isset( $by_key[ $key ] ) ) {
                $by_key[ $key ] = [ 'name' => $name, 'orders' => 0, 'revenue' => 0.0, 'visits' => 0 ];
            }
            $by_key[ $key ]['visits'] += (int) $row->hits;
        }

        $rows = array_values( $by_key );
        usort( $rows, static function ( $a, $b ) {
            if ( $a['revenue'] !== $b['revenue'] ) {
                return $b['revenue'] <=> $a['revenue'];
            }
            if ( $a['orders'] !== $b['orders'] ) {
                return $b['orders'] <=> $a['orders'];
            }
            return $b['visits'] <=> $a['visits'];
        } );
        if ( $limit > 0 ) {
            $rows = array_slice( $rows, 0, $limit );
        }

        foreach ( $rows as &$row ) {
            $row['revenue']      = round( $row['revenue'], 2 );
            $row['revenue_text'] = $row['orders'] > 0 ? brikpanel_money_text( $row['revenue'] ) : '';
            // Capped at 100%: an order can be credited to a campaign visit made
            // before the period, so a period can hold more orders than visits.
            $row['conversion']   = $row['visits'] > 0 ? min( 100, round( $row['orders'] / $row['visits'] * 100, 1 ) ) : null;
        }
        unset( $row );

        return $rows;
    }

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
        // Divided by the size of the baseline: net profit can be a loss, and a
        // negative divisor turned a loss of 7,300 becoming a profit of 10,537
        // into "down 244%".
        return round( ( ( $current - $previous ) / abs( $previous ) ) * 100, 1 );
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
                'counts'     => [ 'successful' => 0, 'failed' => 0, 'refunded' => 0, 'cancelled' => 0 ],
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
            // The same groups as order counts: the 100 squares of the Order
            // rates card are shared out from these (the shares above stay
            // for the Excel export).
            'counts'     => [
                'successful' => (int) $successful,
                'failed'     => (int) $failed,
                'refunded'   => (int) $refunded,
                'cancelled'  => (int) $cancelled,
            ],
        ];
    }

    // =========================================================================
    // ITEMS SOLD (Orders card, Order Rates head)
    // =========================================================================

    /**
     * Units sold in paid orders inside [$start_gmt, $end_gmt].
     *
     * Counted on the same orders as the Orders card (brikpanel_get_order_count):
     * paid statuses, store administrators' own orders left out and, on a
     * BrikMarket store, marketplace-imported orders too, so the two figures sit
     * side by side without disagreeing. Quantities are read from the order's
     * own line items, which WooCommerce writes together with the order under
     * both storage modes. Its analytics lookup table is filled later by a
     * background job, so a brand-new order would bust the dashboard cache and
     * be cached without its items. Simple products and variations count alike:
     * each line item carries its own quantity.
     *
     * @param string $start_gmt           Y-m-d H:i:s (UTC)
     * @param string $end_gmt             Y-m-d H:i:s (UTC)
     * @param bool   $exclude_marketplace Match the Orders card's site-only basis.
     * @return int
     */
    private function get_items_sold( $start_gmt, $end_gmt, $exclude_marketplace = false ) {
        global $wpdb;

        $statuses = brikpanel_paid_order_statuses();
        $sp       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
        $is_hpos  = $this->is_hpos();
        $order_id = $is_hpos ? 'o.id' : 'p.ID';

        $items = "INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
                ON oi.order_id = {$order_id} AND oi.order_item_type = 'line_item'
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty
                ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'";

        if ( $is_hpos ) {
            $sql  = "SELECT COALESCE(SUM(CAST(qty.meta_value AS DECIMAL(20,4))), 0)
                FROM {$wpdb->prefix}wc_orders o
                {$items}
                WHERE o.type = 'shop_order' AND o.status IN ($sp)
                  AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s";
            $excl = brikpanel_admin_order_exclusion_sql( true );
            // The HPOS fragment names a bare customer_id; pin it to the orders
            // table as get_top_products() does.
            $excl['sql'] = str_replace( 'customer_id', 'o.customer_id', (string) $excl['sql'] );
        } else {
            $sql  = "SELECT COALESCE(SUM(CAST(qty.meta_value AS DECIMAL(20,4))), 0)
                FROM {$wpdb->posts} p
                {$items}
                WHERE p.post_type = 'shop_order' AND p.post_status IN ($sp)
                  AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s";
            $excl = brikpanel_admin_order_exclusion_sql( false, 'p.ID' );
        }
        $args = array_merge( $statuses, [ $start_gmt, $end_gmt ] );

        if ( ! empty( $excl['sql'] ) ) {
            $sql .= $excl['sql'];
            $args = array_merge( $args, $excl['args'] );
        }
        if ( $exclude_marketplace ) {
            $mp = brikpanel_marketplace_order_exclusion_sql( $is_hpos, $order_id );
            if ( ! empty( $mp['sql'] ) ) {
                $sql .= $mp['sql'];
                $args = array_merge( $args, $mp['args'] );
            }
        }

        return (int) round( (float) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only, table names from $wpdb.
    }

    /**
     * "5,361 items sold": the count in WooCommerce's own separators (like the
     * Orders figure beside it) inside the plural form the admin's language
     * needs, which only the server can pick. '' when nothing was sold.
     *
     * @param int $items Units sold.
     * @return string
     */
    private function items_sold_label( $items ) {
        $items = (int) $items;
        if ( $items <= 0 ) {
            return '';
        }
        /* translators: %s: number of items sold in the selected period, already formatted (e.g. 5,361). */
        return sprintf( _n( '%s item sold', '%s items sold', $items, 'brikpanel' ), brikpanel_dash_format_count( $items ) );
    }

    // =========================================================================
    // TOP PRODUCTS (by quantity sold)
    // =========================================================================

    /**
     * The Best sellers card: its first five products and how many products
     * sold in the period (the card's "View all" button).
     *
     * @param string $start_gmt           Y-m-d H:i:s (UTC).
     * @param string $end_gmt             Y-m-d H:i:s (UTC).
     * @param bool   $exclude_marketplace Leave BrikMarket orders out.
     * @return array{rows:array,total:int}
     */
    private function get_top_products( $start_gmt, $end_gmt, $exclude_marketplace = false ) {
        $list = $this->products_list_query(
            'sold',
            [ 'start_gmt' => $start_gmt, 'end_gmt' => $end_gmt ],
            [ 'limit' => 5, 'count' => true, 'exclude_marketplace' => $exclude_marketplace ]
        );
        $rows = [];
        foreach ( $list['rows'] as $row ) {
            $rows[] = [ 'name' => $row['title'], 'qty' => $row['value'], 'id' => $row['id'], 'url' => $row['url'] ];
        }
        return [ 'rows' => $rows, 'total' => (int) $list['total'] ];
    }

    /**
     * Units sold per product in the period's paid orders, as SQL to wrap:
     * admins' own orders and (with BrikMarket) marketplace orders left out,
     * like every site card. Every variation counts for its parent product.
     *
     * @param string $start_gmt           Y-m-d H:i:s (UTC).
     * @param string $end_gmt             Y-m-d H:i:s (UTC).
     * @param bool   $exclude_marketplace Leave BrikMarket orders out.
     * @return array{sql:string,args:array} Columns product_id, total.
     */
    private function sold_totals_sql( $start_gmt, $end_gmt, $exclude_marketplace ) {
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
            $admin_sql  = str_replace( 'customer_id', 'o.customer_id', $exclusion['sql'] );
            $query_args = array_merge( $query_args, $exclusion['args'], $mp_excl['args'], [ $start_gmt, $end_gmt ] );
            // type='shop_order' excludes shop_order_refund rows in the lookup table
            // (their negative qty would silently subtract from each parent's total).
            // product_id > 0 drops orphaned line items whose product was deleted.
            $sql = "SELECT p.product_id AS product_id, SUM(p.product_qty) AS total
                 FROM {$wpdb->prefix}wc_order_product_lookup p
                 INNER JOIN {$wpdb->prefix}wc_orders o ON p.order_id = o.id
                 WHERE o.type = 'shop_order'
                 AND o.status IN ({$status_placeholders}){$admin_sql}{$mp_excl['sql']}
                 AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
                 AND p.product_id > 0
                 GROUP BY p.product_id";
        } else {
            $query_args = array_merge( $query_args, $exclusion['args'], $mp_excl['args'], [ $start_gmt, $end_gmt ] );
            // Group by parent product. Joining itemmeta on _product_id alone (not also
            // _variation_id) prevents variable products from being double-counted —
            // every variation purchase rolls up to its parent, matching HPOS semantics.
            $sql = "SELECT m2.meta_value AS product_id, SUM(m1.meta_value) AS total
                 FROM {$wpdb->posts} AS p
                 INNER JOIN {$wpdb->prefix}woocommerce_order_items AS oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS m1 ON oi.order_item_id = m1.order_item_id AND m1.meta_key = '_qty'
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS m2 ON oi.order_item_id = m2.order_item_id AND m2.meta_key = '_product_id'
                 WHERE p.post_type = 'shop_order'
                 AND p.post_status IN ({$status_placeholders}){$exclusion['sql']}{$mp_excl['sql']}
                 AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s
                 AND m2.meta_value > 0
                 GROUP BY m2.meta_value";
        }
        return [ 'sql' => $sql, 'args' => $query_args ];
    }

    /**
     * Products of a period, most first: Best sellers (units sold) or Most
     * added to cart (cart adds the tracker counted). The card, its "View all"
     * window and the Excel sheet read this one list.
     *
     * The figures are added up per product first, then the products are
     * joined to their post: a product moved to the trash or deleted drops out,
     * as wc_get_products() left it out of the cards before (drafts, pending
     * and private products stay, like there).
     *
     * @param string $kind  'sold' or 'cart'.
     * @param array  $dates From calculate_dates(): start_gmt and end_gmt for
     *                      'sold', start_local and end_local for 'cart'.
     * @param array  $args {
     *     @type int    $limit               Rows to return (1 to PAGES_SHEET_MAX).
     *     @type int    $offset              Rows to skip.
     *     @type string $search              Part of a product name.
     *     @type bool   $count               Also count every matching product.
     *     @type bool   $exclude_marketplace 'sold': leave BrikMarket orders out.
     * }
     * @return array{rows:array,total:?int,has_more:bool}
     */
    private function products_list_query( $kind, array $dates, array $args = [] ) {
        global $wpdb;
        $limit  = max( 1, min( self::PAGES_SHEET_MAX, (int) ( $args['limit'] ?? 5 ) ) );
        $offset = max( 0, min( self::PAGES_SHEET_MAX, (int) ( $args['offset'] ?? 0 ) ) );
        $search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

        if ( 'sold' === $kind ) {
            $inner = $this->sold_totals_sql( $dates['start_gmt'], $dates['end_gmt'], ! empty( $args['exclude_marketplace'] ) );
        } else {
            $inner = [
                'sql'  => "SELECT product_id, SUM(cart_count) AS total
                           FROM {$wpdb->prefix}brikpanel_cart_tracking
                           WHERE date_column >= %s AND date_column <= %s
                           GROUP BY product_id",
                'args' => [ $dates['start_local'] . ' 00:00:00', $dates['end_local'] . ' 23:59:59' ],
            ];
        }
        $statuses = array_keys( get_post_statuses() );
        $from     = "FROM ( {$inner['sql']} ) a
                     INNER JOIN {$wpdb->posts} pr ON pr.ID = a.product_id AND pr.post_type = 'product'
                     AND pr.post_status IN (" . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
        $params   = array_merge( $inner['args'], $statuses );
        $where    = '';
        if ( '' !== $search ) {
            $where    = 'WHERE pr.post_title LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $search ) . '%';
        }

        // One row more than asked tells whether there is a next load.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table names from $wpdb, every value a placeholder.
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.product_id, a.total {$from} {$where}
                 ORDER BY a.total DESC, a.product_id ASC
                 LIMIT %d OFFSET %d",
                array_merge( $params, [ $limit + 1, $offset ] )
            )
        );
        $total = null;
        if ( ! empty( $args['count'] ) ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from} {$where}", $params ) );
        }
        // phpcs:enable

        $results  = is_array( $results ) ? $results : [];
        $has_more = count( $results ) > $limit;
        $results  = array_slice( $results, 0, $limit );
        $ids      = array_map( 'intval', wp_list_pluck( $results, 'product_id' ) );
        if ( $ids ) {
            _prime_post_caches( $ids, false, false );
        }

        $data = [];
        foreach ( $results as $row ) {
            $id        = (int) $row->product_id;
            $permalink = get_permalink( $id );
            $data[]    = [
                'key'   => 'product:' . $id,
                // The product's own name, like WC_Product::get_name(): no
                // "Private:" prefix from the_title.
                'title' => brikpanel_plain_name( (string) get_post_field( 'post_title', $id, 'raw' ) ),
                'value' => (int) round( (float) $row->total ),
                'id'    => $id,
                'url'   => $permalink ? $permalink : '',
            ];
        }

        return [ 'rows' => $data, 'total' => $total, 'has_more' => $has_more ];
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

    /**
     * The Most viewed pages card: its first five pages and how many pages the
     * period has in all (the "View all" link of the card).
     *
     * @param string $start_local Y-m-d.
     * @param string $end_local   Y-m-d.
     * @return array{rows:array,total:int}
     */
    private function get_most_viewed( $start_local, $end_local ) {
        $list = $this->most_viewed_query( $start_local, $end_local, [ 'limit' => 5, 'count' => true ] );
        return [ 'rows' => $list['rows'], 'total' => (int) $list['total'] ];
    }

    /**
     * Post types, post statuses and taxonomies a shopper can open. The page
     * lists keep only these: the same rule the tracker applies when it counts
     * a view (brikpanel_view_target_is_public()), so a page moved to the trash,
     * a draft or a private page drops out of the lists.
     *
     * @return array{types:string[],statuses:string[],taxonomies:string[]}
     */
    private static function viewable_objects() {
        static $objects = null;
        if ( null === $objects ) {
            $objects = [
                'types'      => array_values( array_filter( get_post_types( [], 'names' ), 'is_post_type_viewable' ) ),
                'statuses'   => array_values( array_filter( get_post_stati( [], 'names' ), 'is_post_status_viewable' ) ),
                'taxonomies' => array_values( array_filter( get_taxonomies( [], 'names' ), 'is_taxonomy_viewable' ) ),
            ];
        }
        return $objects;
    }

    /**
     * Pages of a period, most viewed first: the Most viewed pages card, its
     * window and the Excel sheet all read this one list.
     *
     * Views are added up per page first, then the pages are joined to their
     * post or term, so a long period costs one pass over its view rows and one
     * lookup per page. Rows written before 3.2.41 have no object_type of their
     * own and take the column default, so anything but 'term' is a post.
     *
     * @param string $start_local Y-m-d.
     * @param string $end_local   Y-m-d.
     * @param array  $args {
     *     @type int    $limit  Rows to return (1 to PAGES_SHEET_MAX).
     *     @type int    $offset Rows to skip.
     *     @type string $search Part of a page title or category name.
     *     @type bool   $count  Also count every matching page.
     * }
     * @return array{rows:array,total:?int,has_more:bool}
     */
    private function most_viewed_query( $start_local, $end_local, array $args = [] ) {
        global $wpdb;
        $limit   = max( 1, min( self::PAGES_SHEET_MAX, (int) ( $args['limit'] ?? 5 ) ) );
        $offset  = max( 0, min( self::PAGES_SHEET_MAX, (int) ( $args['offset'] ?? 0 ) ) );
        $search  = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
        $objects = self::viewable_objects();
        $table   = $wpdb->prefix . 'brikpanel_visited_pages';
        $in      = static function ( array $list ) {
            return implode( ',', array_fill( 0, count( $list ), '%s' ) );
        };

        $params = [ $start_local . ' 00:00:00', $end_local . ' 23:59:59' ];
        $from   = "FROM ( SELECT page_id, object_type, SUM(visit_count) AS total_views
                            FROM {$table}
                            WHERE date_column >= %s AND date_column <= %s
                            GROUP BY page_id, object_type ) v
                   LEFT JOIN {$wpdb->posts} p ON v.object_type <> 'term' AND p.ID = v.page_id
                   LEFT JOIN {$wpdb->terms} t ON v.object_type = 'term' AND t.term_id = v.page_id";

        $post_ok = '0 = 1';
        if ( $objects['types'] && $objects['statuses'] ) {
            $post_ok = "( p.ID IS NOT NULL AND p.post_title <> '' AND p.post_type IN (" . $in( $objects['types'] ) . ') AND p.post_status IN (' . $in( $objects['statuses'] ) . ') )';
            $params  = array_merge( $params, $objects['types'], $objects['statuses'] );
        }
        $term_ok = '0 = 1';
        if ( $objects['taxonomies'] ) {
            $term_ok = "( t.term_id IS NOT NULL AND EXISTS ( SELECT 1 FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id = t.term_id AND tt.taxonomy IN (" . $in( $objects['taxonomies'] ) . ') ) )';
            $params  = array_merge( $params, $objects['taxonomies'] );
        }
        $where = "WHERE ( {$post_ok} OR {$term_ok} )";
        if ( '' !== $search ) {
            // Category names are stored with "&amp;" for "&", titles are not.
            $where   .= ' AND ( p.post_title LIKE %s OR t.name LIKE %s OR t.name LIKE %s )';
            $params[] = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = '%' . $wpdb->esc_like( esc_html( $search ) ) . '%';
        }

        // One row more than asked tells whether there is a next load.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table names from $wpdb, every value a placeholder.
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.page_id, v.object_type, v.total_views {$from} {$where}
                 ORDER BY v.total_views DESC, v.object_type ASC, v.page_id ASC
                 LIMIT %d OFFSET %d",
                array_merge( $params, [ $limit + 1, $offset ] )
            )
        );
        $total = null;
        if ( ! empty( $args['count'] ) ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from} {$where}", $params ) );
        }
        // phpcs:enable

        $results  = is_array( $results ) ? $results : [];
        $has_more = count( $results ) > $limit;
        $results  = array_slice( $results, 0, $limit );

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
            $id = (int) $row->page_id;
            if ( 'term' === $row->object_type ) {
                $term = get_term( $id );
                if ( ! $term || is_wp_error( $term ) ) {
                    continue;
                }
                $tax   = get_taxonomy( $term->taxonomy );
                $title = brikpanel_plain_name( $term->name );
                $link  = get_term_link( $term );
                $url   = is_wp_error( $link ) ? '' : $link;
                $type  = $tax ? $tax->labels->singular_name : '';
            } else {
                // get_the_title() is display HTML: "&" comes back as "&#038;",
                // " - " as "&#8211;". The lists write the title as text.
                $title = brikpanel_plain_label( get_the_title( $id ) );
                if ( ! $title ) {
                    continue;
                }
                $permalink = get_permalink( $id );
                $url       = $permalink ? $permalink : '';
                $pto       = get_post_type_object( get_post_type( $id ) );
                $type      = $pto ? $pto->labels->singular_name : '';
            }
            $data[] = [
                'key'   => ( 'term' === $row->object_type ? 'term:' : 'post:' ) . $id,
                'title' => $title,
                'type'  => brikpanel_plain_label( (string) $type ),
                'views' => (int) $row->total_views,
                'id'    => $id,
                'url'   => $url,
            ];
        }

        return [ 'rows' => $data, 'total' => $total, 'has_more' => $has_more ];
    }

    // =========================================================================
    // MOST ADDED TO CART
    // =========================================================================

    /**
     * The Most added to cart card: its first five products and how many
     * products were added to a cart in the period (the card's "View all").
     *
     * @param string $start_local Y-m-d.
     * @param string $end_local   Y-m-d.
     * @return array{rows:array,total:int}
     */
    private function get_most_cart( $start_local, $end_local ) {
        $list = $this->products_list_query(
            'cart',
            [ 'start_local' => $start_local, 'end_local' => $end_local ],
            [ 'limit' => 5, 'count' => true ]
        );
        $rows = [];
        foreach ( $list['rows'] as $row ) {
            $rows[] = [ 'name' => $row['title'], 'count' => $row['value'], 'id' => $row['id'], 'url' => $row['url'] ];
        }
        return [ 'rows' => $rows, 'total' => (int) $list['total'] ];
    }

    // =========================================================================
    // SALES OVER TIME (NEW - daily revenue breakdown for line chart)
    // =========================================================================

    /**
     * Paid revenue and orders per local day of the window.
     *
     * @param string $start_gmt           Y-m-d H:i:s (UTC).
     * @param string $end_gmt             Y-m-d H:i:s (UTC).
     * @param bool   $exclude_marketplace Leave BrikMarket orders out.
     * @param string $start_day           Local Y-m-d the window starts on. With
     *                                    $end_day, every day in between is
     *                                    listed, days without sales at 0.
     * @param string $end_day             Local Y-m-d the window ends on.
     * @return array<int,array{date:string,revenue:float,orders:int}>
     */
    private function get_sales_over_time( $start_gmt, $end_gmt, $exclude_marketplace = false, $start_day = '', $end_day = '' ) {
        $by_day = $this->fold_sales_buckets( $this->sales_bucket_rows( $start_gmt, $end_gmt, $exclude_marketplace ), 'day' );

        // Every day of the range, sales or not: the line used to jump over
        // quiet days and stop at the last day with a sale (field test F3).
        foreach ( $this->local_days( $start_day, $end_day ) as $key ) {
            if ( ! isset( $by_day[ $key ] ) ) {
                $by_day[ $key ] = [ 'revenue' => 0.0, 'orders' => 0 ];
            }
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

    /**
     * Every local Y-m-d from $start_day to $end_day, or nothing when the
     * range is unreadable or longer than SALES_FILL_MAX_DAYS.
     *
     * The window's local Y-m-d strings are stepped as plain calendar days in
     * UTC, where every day is 24 hours: stepping them in the store's zone
     * repeats or skips a day where daylight saving starts at midnight.
     *
     * @param string $start_day Local Y-m-d.
     * @param string $end_day   Local Y-m-d.
     * @return string[]
     */
    private function local_days( $start_day, $end_day ) {
        if ( ! self::is_valid_ymd( $start_day ) || ! self::is_valid_ymd( $end_day ) || $start_day > $end_day ) {
            return [];
        }
        $out = [];
        try {
            $utc    = new DateTimeZone( 'UTC' );
            $cursor = new DateTimeImmutable( $start_day, $utc );
            $last   = new DateTimeImmutable( $end_day, $utc );
            $span   = (int) $cursor->diff( $last )->days;
            if ( $span > self::SALES_FILL_MAX_DAYS ) {
                return [];
            }
            $step = new DateInterval( 'P1D' );
            for ( $i = 0; $i <= $span; $i++ ) {
                $out[]  = $cursor->format( 'Y-m-d' );
                $cursor = $cursor->add( $step );
            }
        } catch ( Exception $e ) {
            return []; // Unreadable date: keep the days with sales only.
        }
        return $out;
    }

    /**
     * Paid revenue and orders of a window in 15-minute UTC buckets, read once
     * per request and window.
     *
     * @param string $start_gmt           Y-m-d H:i:s (UTC).
     * @param string $end_gmt             Y-m-d H:i:s (UTC).
     * @param bool   $exclude_marketplace Leave BrikMarket orders out.
     * @return object[] Rows with bucket_utc ("Y-m-d H:i"), revenue, orders, oldest first.
     */
    private function sales_bucket_rows( $start_gmt, $end_gmt, $exclude_marketplace = false ) {
        $cache_key = $start_gmt . '|' . $end_gmt . '|' . ( $exclude_marketplace ? 1 : 0 );
        if ( isset( $this->sales_buckets[ $cache_key ] ) ) {
            return $this->sales_buckets[ $cache_key ];
        }

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
                 LEFT JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total' AND " . brikpanel_sql_first_meta_guard( 'post', 'pm' ) . "{$fx['join']}
                 WHERE p.post_type = 'shop_order'
                 AND pm.meta_key = '_order_total'
                 AND p.post_status IN ({$status_placeholders}){$exclusion['sql']}{$mp_excl['sql']}
                 AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s
                 GROUP BY bucket_utc
                 ORDER BY bucket_utc ASC",
                $query_args
            );
        }

        $rows = (array) $wpdb->get_results( $query );

        $this->sales_buckets[ $cache_key ] = $rows;
        return $rows;
    }

    /**
     * Folds 15-minute UTC buckets into local days ("Y-m-d") or local hours
     * ("Y-m-d H"). Each bucket is converted on its own timestamp, so the
     * result is exact in every zone and across daylight saving: the hour that
     * repeats when clocks go back adds up, the skipped hour stays empty.
     *
     * @param object[] $rows sales_bucket_rows().
     * @param string   $unit 'day' or 'hour'.
     * @return array<string,array{revenue:float,orders:int}>
     */
    private function fold_sales_buckets( array $rows, $unit ) {
        $utc    = new DateTimeZone( 'UTC' );
        $local  = wp_timezone();
        $format = 'hour' === $unit ? 'Y-m-d H' : 'Y-m-d';
        $out    = [];
        foreach ( $rows as $row ) {
            try {
                $when = new DateTimeImmutable( (string) $row->bucket_utc, $utc );
            } catch ( Exception $e ) {
                continue; // unparseable stamp: drop the bucket rather than the chart
            }
            $key = $when->setTimezone( $local )->format( $format );
            if ( ! isset( $out[ $key ] ) ) {
                $out[ $key ] = [ 'revenue' => 0.0, 'orders' => 0 ];
            }
            $out[ $key ]['revenue'] += (float) $row->revenue;
            $out[ $key ]['orders']  += (int) $row->orders;
        }
        return $out;
    }

    /**
     * The 24 local hours of one day from folded hour buckets: {r, o} per
     * hour, null for an hour after $upto (not reached yet).
     *
     * @param array  $by_hour fold_sales_buckets( …, 'hour' ).
     * @param string $day     Local Y-m-d.
     * @param int    $upto    Last hour to fill (23 for a finished day).
     * @return array<int,array{r:float,o:int}|null>
     */
    private function day_hours( array $by_hour, $day, $upto = 23 ) {
        $out = [];
        for ( $h = 0; $h < 24; $h++ ) {
            if ( $h > $upto ) {
                $out[] = null;
                continue;
            }
            $key   = $day . ' ' . str_pad( (string) $h, 2, '0', STR_PAD_LEFT );
            $out[] = [
                'r' => isset( $by_hour[ $key ] ) ? round( $by_hour[ $key ]['revenue'], 4 ) : 0.0,
                'o' => isset( $by_hour[ $key ] ) ? (int) $by_hour[ $key ]['orders'] : 0,
            ];
        }
        return $out;
    }

    /**
     * The sales chart's series: the current period day by day (hour by hour
     * for a single day) with the previous period of the same length under
     * it, daily visitors for the store cards' small lines and, while
     * BrikMarket is active, revenue from every channel for the Total sales
     * card (the chart itself counts website orders only, like before).
     *
     * @param array $dates      calculate_dates().
     * @param bool  $exclude_mp BrikMarket is active.
     * @return array{unit:string,basis:string,cur:array,prev:array,today:?int,now_hour:?int}
     */
    private function build_sales_series( array $dates, $exclude_mp ) {
        $first = (string) $dates['start_local'];
        $last  = (string) $dates['end_local'];
        $prev  = (array) $dates['prev'];
        $now   = new DateTimeImmutable( 'now', wp_timezone() );
        $today = $now->format( 'Y-m-d' );
        $unit  = $first === $last ? 'hour' : 'day';
        $days  = $this->local_days( $first, $last );
        $span  = count( $days );

        $cur_rows  = $this->sales_bucket_rows( $dates['start_gmt'], $dates['end_gmt'], $exclude_mp );
        $with_prev = $span > 0 && $span <= self::SALES_PREV_MAX_DAYS;
        $prev_rows = $with_prev ? $this->sales_bucket_rows( $prev['start_gmt'], $prev['end_gmt'], $exclude_mp ) : [];

        $out = [
            'unit'     => $unit,
            'basis'    => $exclude_mp ? 'site' : 'all',
            'cur'      => [],
            'prev'     => [],
            'today'    => null,
            'now_hour' => null,
        ];

        if ( 'hour' === $unit ) {
            $is_today = $first === $today;
            $upto     = $is_today ? (int) $now->format( 'G' ) : 23;
            foreach ( $this->day_hours( $this->fold_sales_buckets( $cur_rows, 'hour' ), $first, $upto ) as $h => $x ) {
                $out['cur'][] = null === $x ? null : [ 't' => $first . ' ' . str_pad( (string) $h, 2, '0', STR_PAD_LEFT ) . ':00', 'r' => $x['r'], 'o' => $x['o'] ];
            }
            if ( $with_prev ) {
                $prev_day = (string) $prev['start_local'];
                foreach ( $this->day_hours( $this->fold_sales_buckets( $prev_rows, 'hour' ), $prev_day ) as $h => $x ) {
                    $out['prev'][] = [ 't' => $prev_day . ' ' . str_pad( (string) $h, 2, '0', STR_PAD_LEFT ) . ':00', 'r' => $x['r'], 'o' => $x['o'] ];
                }
            }
            if ( $is_today ) {
                $out['now_hour'] = $upto;
            }
            return $out;
        }

        // Day by day. A window too long to list every day keeps the days with
        // sales, like the table under it.
        $cur_by = $this->fold_sales_buckets( $cur_rows, 'day' );
        if ( ! $days ) {
            $days = array_keys( $cur_by );
            sort( $days );
        }

        $visits = $this->visitor_series( $first, $last );
        $all_by = $exclude_mp ? $this->fold_sales_buckets( $this->sales_bucket_rows( $dates['start_gmt'], $dates['end_gmt'], false ), 'day' ) : null;

        foreach ( $days as $i => $day ) {
            $point = [
                't' => $day,
                'r' => isset( $cur_by[ $day ] ) ? round( $cur_by[ $day ]['revenue'], 4 ) : 0.0,
                'o' => isset( $cur_by[ $day ] ) ? (int) $cur_by[ $day ]['orders'] : 0,
                'v' => isset( $visits[ $day ] ) ? (int) $visits[ $day ] : 0,
            ];
            if ( null !== $all_by ) {
                $point['ra'] = isset( $all_by[ $day ] ) ? round( $all_by[ $day ]['revenue'], 4 ) : 0.0;
            }
            $out['cur'][] = $point;
            if ( $day === $today ) {
                $out['today'] = $i;
            }
        }

        if ( $with_prev ) {
            $prev_by = $this->fold_sales_buckets( $prev_rows, 'day' );
            foreach ( $this->local_days( (string) $prev['start_local'], (string) $prev['end_local'] ) as $day ) {
                $out['prev'][] = [
                    't' => $day,
                    'r' => isset( $prev_by[ $day ] ) ? round( $prev_by[ $day ]['revenue'], 4 ) : 0.0,
                    'o' => isset( $prev_by[ $day ] ) ? (int) $prev_by[ $day ]['orders'] : 0,
                ];
            }
        }

        return $out;
    }

    /**
     * Visitors per local day, from the same daily counters (and the same
     * filter) as the Visitors card, so the days add up to the card.
     *
     * @param string $start_day Local Y-m-d.
     * @param string $end_day   Local Y-m-d.
     * @return array<string,int>
     */
    private function visitor_series( $start_day, $end_day ) {
        global $wpdb;
        if ( ! self::is_valid_ymd( $start_day ) || ! self::is_valid_ymd( $end_day ) ) {
            return [];
        }
        $table = $wpdb->prefix . 'brikpanel_visitors';
        $rows  = $wpdb->get_results( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb, no input.
            "SELECT date_column AS d, SUM(visitor_count) AS v FROM {$table} WHERE date_column BETWEEN %s AND %s GROUP BY date_column",
            $start_day,
            $end_day
        ) );
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ substr( (string) $row->d, 0, 10 ) ] = (int) $row->v;
        }
        return $out;
    }

    /**
     * "Today so far" for the live card: today's visitors, paid orders and
     * revenue, sales by hour and the newest paid order. Website orders only
     * while BrikMarket is active, like the sales chart. Sent with every data
     * response (never in the Excel export) and kept TODAY_TTL seconds.
     *
     * @return array
     */
    private function today_block() {
        $mp_active = function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active();
        $now       = new DateTimeImmutable( 'now', wp_timezone() );
        $day       = $now->format( 'Y-m-d' );
        $cache_key = 'bp_dash_today_t1_' . brikpanel_data_cache_ver() . '_' . $now->format( 'Ymd' ) . ( $this->is_hpos() ? '_h' : '_p' ) . ( $mp_active ? '_m' : '' );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['day'], $cached['hours'] ) && $cached['day'] === $day ) {
            return $cached;
        }

        $upto  = (int) $now->format( 'G' );
        $rows  = $this->sales_bucket_rows( get_gmt_from_date( $day . ' 00:00:00' ), get_gmt_from_date( $day . ' 23:59:59' ), $mp_active );
        $hours = $this->day_hours( $this->fold_sales_buckets( $rows, 'hour' ), $day, $upto );

        $orders  = 0;
        $revenue = 0.0;
        foreach ( $hours as $x ) {
            if ( null !== $x ) {
                $orders  += $x['o'];
                $revenue += $x['r'];
            }
        }

        $block = [
            'day'        => $day,
            'built_at'   => time(),
            'now_hour'   => $upto,
            'basis'      => $mp_active ? 'site' : 'all',
            'visitors'   => (int) brikpanel_get_visitor_count( $day, $day ),
            'orders'     => $orders,
            'revenue'    => round( $revenue, 4 ),
            'hours'      => $hours,
            'last_order' => $this->latest_paid_order( $mp_active ),
        ];

        $ttl = function_exists( 'brikpanel_cache_ttl' ) ? brikpanel_cache_ttl( self::TODAY_TTL ) : self::TODAY_TTL;
        set_transient( $cache_key, $block, $ttl );
        return $block;
    }

    /**
     * The newest paid order of the last LAST_PAID_FLOOR_DAYS days, without
     * administrators' orders (and marketplace orders when asked), for the
     * live card's "Last order" line. One indexed lookup per paid status, like
     * compute_paid_state().
     *
     * @param bool $exclude_marketplace Leave BrikMarket orders out.
     * @return array{number:string,ts:int,edit_url:string}|null
     */
    private function latest_paid_order( $exclude_marketplace ) {
        global $wpdb;

        $q    = $this->store_order_sql();
        $paid = array_values( array_filter( (array) brikpanel_paid_order_statuses(), 'is_string' ) );
        if ( ! $paid ) {
            return null;
        }

        $floor_utc = gmdate( 'Y-m-d H:i:s', time() - self::LAST_PAID_FLOOR_DAYS * DAY_IN_SECONDS );
        $floor     = $q['is_hpos'] ? $floor_utc : get_date_from_gmt( $floor_utc, 'Y-m-d H:i:s' );
        $mp        = $exclude_marketplace
            ? brikpanel_marketplace_order_exclusion_sql( $q['is_hpos'], $q['id'] )
            : [ 'sql' => '', 'args' => [] ];

        $best = null;
        foreach ( $paid as $paid_status ) {
            // Placeholders only; table and column names are fixed in store_order_sql().
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT {$q['id']} AS id, {$q['date']} AS d FROM {$q['from']} WHERE {$q['where']} AND {$q['status']} = %s AND {$q['sort']} >= %s{$q['not_admin']['sql']}{$mp['sql']} ORDER BY {$q['sort']} DESC LIMIT 1",
                array_merge( [ $paid_status, $floor ], $q['not_admin']['args'], (array) $mp['args'] )
            ) );
            if ( $row && ( null === $best || (string) $row->d > (string) $best->d ) ) {
                $best = $row;
            }
        }
        if ( null === $best ) {
            return null;
        }

        $order = wc_get_order( (int) $best->id );
        if ( ! $order instanceof WC_Order ) {
            return null;
        }
        $created = $order->get_date_created();
        return [
            'number'   => (string) $order->get_order_number(),
            'ts'       => $created ? (int) $created->getTimestamp() : (int) strtotime( (string) $best->d . ' UTC' ),
            'edit_url' => (string) $order->get_edit_order_url(),
        ];
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

            // Sales come from the order total (base-currency snapshot when one
            // exists), exactly like the HPOS branch. This used to sum `_qty`
            // from posts of type 'shop_order_item', which WooCommerce never
            // creates, so every country showed 0 sales on a non-HPOS store.
            // Each meta join is pinned to one row so a duplicate cannot
            // multiply an order.
            $fx_loc = brikpanel_base_total_sql( false, 'p.ID', 'CAST(pm_total.meta_value AS DECIMAL(20,4))', 'bpfxloc' );
            $country_query = $wpdb->prepare(
                "SELECT pm.meta_value AS code,
                        COUNT(DISTINCT p.ID) AS order_count,
                        {$customer_count_expr} AS customer_count,
                        COALESCE(SUM({$fx_loc['expr']}), 0) AS total_sales
                 FROM {$wpdb->posts} p
                 " . brikpanel_sql_single_meta_join( 'post', 'pm', 'p.ID', '_billing_country' ) . "
                 " . brikpanel_sql_single_meta_join( 'post', 'pm_cust', 'p.ID', '_customer_user' ) . "
                 " . brikpanel_sql_single_meta_join( 'post', 'pm_email', 'p.ID', '_billing_email' ) . "
                 " . brikpanel_sql_single_meta_join( 'post', 'pm_total', 'p.ID', '_order_total' ) . "{$fx_loc['join']}
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
                        SUM(ol.product_qty) AS total_quantity
                 FROM {$wpdb->posts} p
                 " . brikpanel_sql_single_meta_join( 'post', 'pm_city', 'p.ID', '_billing_city' ) . "
                 " . brikpanel_sql_single_meta_join( 'post', 'pm_country', 'p.ID', '_billing_country' ) . "
                 " . brikpanel_sql_single_meta_join( 'post', 'pm_cust', 'p.ID', '_customer_user' ) . "
                 " . brikpanel_sql_single_meta_join( 'post', 'pm_email', 'p.ID', '_billing_email' ) . "
                 LEFT JOIN {$wpdb->prefix}wc_order_product_lookup ol ON p.ID = ol.order_id
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
                // WooCommerce's list holds entities ("Cura&ccedil;ao").
                'name'      => isset( $wc_countries[ $row->code ] ) ? brikpanel_plain_name( $wc_countries[ $row->code ] ) : $row->code,
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
            $cat_nm  = brikpanel_plain_name( (string) $row->term_name );
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
                     ON o.id = om.order_id AND om.meta_key = %s AND " . brikpanel_sql_first_meta_guard( 'order', 'om' ) . "{$fx['join']}
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
                     ON p.ID = pm.post_id AND pm.meta_key = %s AND " . brikpanel_sql_first_meta_guard( 'post', 'pm' ) . "
                 LEFT JOIN {$wpdb->postmeta} pm_total
                     ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total' AND " . brikpanel_sql_first_meta_guard( 'post', 'pm_total' ) . "{$fx['join']}
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
                        AND " . brikpanel_sql_first_meta_guard( 'order', 'om' ) . "
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
                    AND " . brikpanel_sql_first_meta_guard( 'post', 'om' ) . "
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
        //
        // A SKU is not unique in the lookup table (a trashed product, a stale
        // row), so the lookup join is pinned to ONE live product, the way
        // wc_get_product_id_by_sku() resolves it; joining every match used to
        // count the same line once per product sharing the SKU. A SKU that
        // belongs to a variation resolves to its parent, because categories
        // live on the parent and the line was otherwise dropped.
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
                AND pml.product_id = (
                    SELECT MIN(l.product_id)
                      FROM {$wpdb->prefix}wc_product_meta_lookup l
                     INNER JOIN {$wpdb->posts} lp ON lp.ID = l.product_id AND lp.post_status <> 'trash'
                     WHERE l.sku = im_sku.meta_value
                )
             LEFT JOIN {$wpdb->posts} pml_post ON pml_post.ID = pml.product_id
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta im_total
                 ON oi.order_item_id = im_total.order_item_id AND im_total.meta_key = '_line_total'
             INNER JOIN {$wpdb->term_relationships} tr
                 ON COALESCE(NULLIF(CAST(im_pid.meta_value AS UNSIGNED), 0), NULLIF(pml_post.post_parent, 0), pml.product_id) = tr.object_id
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
                // Order item name: may carry "&amp;" and TranslatePress markup.
                'name'               => brikpanel_plain_label( (string) $row->name ),
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
        global $wpdb;

        // The five newest orders, administrators' test orders left out like
        // everywhere else on the dashboard. wc_get_orders() has no "customer
        // not in" query var (it silently ignored one), so the IDs come from
        // the same filter the store facts use (store_order_sql()) and only
        // those five orders are loaded.
        $q   = $this->store_order_sql();
        $ids = $wpdb->get_col( $wpdb->prepare(
            // Placeholders only; table and column names are fixed in store_order_sql().
            "SELECT {$q['id']} FROM {$q['from']} WHERE {$q['where']} AND {$q['status']} NOT IN ({$q['drafts_in']}){$q['not_admin']['sql']} ORDER BY {$q['sort']} DESC, {$q['id']} DESC LIMIT 5",
            array_merge( $q['drafts'], $q['not_admin']['args'] )
        ) );

        $orders = [];
        foreach ( (array) $ids as $id ) {
            $order = wc_get_order( (int) $id );
            if ( $order instanceof WC_Order ) {
                $orders[] = $order;
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

            $created = $order->get_date_created();

            // Units on the order (sum of line item quantities). Shown small
            // under the total, so the row keeps its two-line height.
            $item_count = (int) $order->get_item_count();

            $data[] = [
                'id'         => $order->get_id(),
                // Display only. A sequential-order-number plugin can make this
                // differ from the ID; the row's link still uses the ID.
                'number'     => (string) $order->get_order_number(),
                'customer'   => $customer,
                // The script shows WooCommerce's own name for it
                // (brikpanelDashboard.i18n.status_labels).
                'status'     => $order->get_status(),
                'total'      => wc_price( $order->get_total(), [ 'currency' => $order_currency ] ),
                'total_base' => $total_base,
                // Shown under the order number: the store's date format with
                // a short month name, so it keeps to one line in the card.
                'date'       => $created ? wp_date( brikpanel_short_date_format(), $created->getTimestamp() ) : '',
                // The card writes day and time from this in the store's clock.
                'ts'         => $created ? (int) $created->getTimestamp() : 0,
                'items'      => $item_count,
                /* translators: %s: number of items on one order, already formatted. */
                'items_label' => $item_count > 0 ? sprintf( _n( '%s item', '%s items', $item_count, 'brikpanel' ), brikpanel_dash_format_count( $item_count ) ) : '',
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
        // Text, not a number: the sign and "New" are part of it. The percent
        // sign goes where the language writes it (field test E9).
        $delta = static function ( $v ) {
            if ( $v === null ) {
                return __( 'New', 'brikpanel' );
            }
            return ( $v > 0 ? '+' : '' ) . brikpanel_percent( (float) $v, 1 );
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
        // The Revenue row says what the card says under its figure.
        $rev_netted  = ! empty( $profit['returns_on'] ) && (float) ( $profit['returns_raw'] ?? 0 ) > 0;
        $rev_no_tax  = ! empty( $profit['tax_excluded'] ) && (float) ( $profit['tax_raw'] ?? 0 ) > 0;
        $rev_has_tax = ! empty( $profit['tax_in_revenue'] ) && (float) ( $profit['tax_raw'] ?? 0 ) > 0;
        if ( $rev_has_tax ) {
            $rev_context = __( 'Includes tax', 'brikpanel' );
        } elseif ( $rev_netted && $rev_no_tax ) {
            $rev_context = __( 'Net of returns and tax', 'brikpanel' );
        } elseif ( $rev_no_tax ) {
            $rev_context = __( 'Excluding tax', 'brikpanel' );
        } elseif ( $rev_netted ) {
            $rev_context = __( 'Net of returns', 'brikpanel' );
        } else {
            $rev_context = __( 'Same as total sales', 'brikpanel' );
        }
        $funnel  = $d['funnel'];
        $rates   = $d['order_rates'];
        $returns = $d['returns'];
        $ltv     = $d['ltv_panel'];
        $fv      = (int) ( $funnel['visitors'] ?? 0 );
        $fpct    = static function ( $n ) use ( $fv ) {
            return $fv > 0 ? brikpanel_percent( $n / $fv * 100, 1 ) : '—';
        };
        // Share of revenue as the card says it; empty when there was no revenue.
        $of_revenue = static function ( $pct ) use ( $profit ) {
            return (float) ( $profit['revenue_raw'] ?? 0 ) > 0
                /* translators: %s: a percentage of revenue, already formatted with its percent sign, e.g. "12.5%". */
                ? brikpanel_safe_sprintf( __( '%s of revenue', 'brikpanel' ), brikpanel_percent( (float) $pct ) )
                : '';
        };

        // ---------- Sheet 1: Summary (overview, mirrors the top of the dashboard) ----------
        $summary = [
            [ [ get_bloginfo( 'name' ) . ' · ' . __( 'BrikPanel report', 'brikpanel' ), $T ] ],
            [ [ __( 'Website', 'brikpanel' ), $B ], home_url() ],
            [ [ __( 'Report period', 'brikpanel' ), $B ], $period['label'] ],
            [ [ __( 'From', 'brikpanel' ), $B ], $period['from'] ],
            [ [ __( 'To', 'brikpanel' ), $B ], $period['to'] ],
            /* translators: %d: number of days. */
            [ [ __( 'Duration', 'brikpanel' ), $B ], sprintf( _n( '%d day', '%d days', $period['days'], 'brikpanel' ), $period['days'] ) ],
            [ [ __( 'Generated', 'brikpanel' ), $B ], wp_date( brikpanel_datetime_format() ) ],
            [ [ __( 'Currency', 'brikpanel' ), $B ], $cur_lbl ],
            [],
            [ [ __( 'Key metrics', 'brikpanel' ), $T ] ],
            [
                [ __( 'Metric', 'brikpanel' ), $H ],
                [ __( 'Value', 'brikpanel' ), $H ],
                [ __( 'Change vs previous period', 'brikpanel' ), $H ],
            ],
            [ __( 'Total sales', 'brikpanel' ), $money( $d['total_sales_raw'] ), $delta( $d['deltas']['sales'] ) ],
            [ __( 'Orders', 'brikpanel' ), (int) $d['order_count'], $delta( $d['deltas']['orders'] ) ],
            // Same paid orders as the row above; the dashboard shows no change for it.
            [ __( 'Items sold', 'brikpanel' ), (int) ( $d['items_sold'] ?? 0 ), '' ],
            [ __( 'Avg. order value', 'brikpanel' ), $money( $d['aov_raw'] ), $delta( $d['deltas']['aov'] ) ],
            [ __( 'Visitors', 'brikpanel' ), (int) $d['visitor_count'], $delta( $d['deltas']['visitors'] ) ],
            [ __( 'Conversion rate (%)', 'brikpanel' ), (float) $d['conversion_rate'], $delta( $d['deltas']['conversion'] ) ],
            [],
            [ [ __( 'Profit', 'brikpanel' ), $T ] ],
            [ [ __( 'Metric', 'brikpanel' ), $H ], [ sprintf( __( 'Amount (%s)', 'brikpanel' ), $currency ), $H ], [ __( 'Context', 'brikpanel' ), $H ] ],
            [ __( 'Revenue', 'brikpanel' ), $money( $profit['revenue_raw'] ), $rev_context ],
            // Taken out of Revenue (and Expenses) by the setting: listed so the
            // lower Revenue figure explains itself, as it does on the card.
            ...( ( ! empty( $profit['tax_excluded'] ) && (float) ( $profit['tax_raw'] ?? 0 ) > 0 )
                ? [ [ __( 'Tax', 'brikpanel' ), $money( $profit['tax_raw'] ), __( 'Taken out of Revenue', 'brikpanel' ) ] ]
                : [] ),
            [ __( 'Cost of goods', 'brikpanel' ), $money( $profit['cogs_raw'] ), $of_revenue( $profit['cogs_pct'] ) ],
            // Kept in Revenue by the setting: its own line, because Net profit
            // takes it off while Expenses no longer carry it.
            ...( $rev_has_tax
                ? [ [ __( 'Tax', 'brikpanel' ), $money( $profit['tax_raw'] ), __( 'In Revenue, taken off Net profit', 'brikpanel' ) ] ]
                : [] ),
            [ __( 'Expenses', 'brikpanel' ), $money( $profit['expenses_raw'] ), $of_revenue( $profit['expenses_pct'] ) ],
            // Called out separately because it is already inside Expenses above:
            // without this line the total simply grows with no visible reason.
            // Omitted entirely on stores that never enabled shipping costs, so
            // the sheet does not carry a permanent zero row.
            ...( ( (float) ( $profit['shipping_cost_raw'] ?? 0 ) ) > 0
                ? [ [ __( 'of which Shipping cost', 'brikpanel' ), $money( $profit['shipping_cost_raw'] ), __( 'Included in expenses', 'brikpanel' ) ] ]
                : [] ),
            // Same reasoning: already inside Expenses, omitted when unused.
            ...( ( (float) ( $profit['per_order_total_raw'] ?? 0 ) ) > 0
                ? [ [ __( 'of which Cost per order', 'brikpanel' ), $money( $profit['per_order_total_raw'] ), __( 'Included in expenses', 'brikpanel' ) ] ]
                : [] ),
            // Same reasoning again. Absent on stores whose gateway records no
            // fee, rather than sitting there as a permanent zero.
            ...( ( (float) ( $profit['payment_fees_raw'] ?? 0 ) ) > 0
                ? [ [ __( 'of which Payment fees', 'brikpanel' ), $money( $profit['payment_fees_raw'] ), __( 'Included in expenses', 'brikpanel' ) ] ]
                : [] ),
            [
                __( 'Net profit', 'brikpanel' ),
                $money( $profit['net_raw'] ),
                (float) ( $profit['revenue_raw'] ?? 0 ) > 0
                    /* translators: %s: profit margin, already formatted with its percent sign, e.g. "12.5%". */
                    ? brikpanel_safe_sprintf( __( '%s margin', 'brikpanel' ), brikpanel_percent( (float) $profit['margin'] ) )
                    : '',
            ],
            [],
            [ [ __( 'Returns & refunds', 'brikpanel' ), $T ] ],
            [ [ __( 'Returned / refunded orders', 'brikpanel' ), $B ], (int) $returns['count'] ],
            [ [ __( 'Total orders', 'brikpanel' ), $B ], (int) $returns['total'] ],
            [ [ __( 'Return & refund rate (%)', 'brikpanel' ), $B ], (float) $returns['rate'] ],
            [],
            [ [ __( 'Customer lifetime value (all-time)', 'brikpanel' ), $T ] ],
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

        // Carts first left in this period: the line under the funnel on screen.
        // The bidi marks that keep a price whole on a right-to-left screen are
        // left out of the cell.
        if ( ! empty( $d['abandoned_carts'] ) && class_exists( 'Brikpanel_Cart_Abandonment' ) && Brikpanel_Cart_Abandonment::is_enabled() ) {
            $ac             = $d['abandoned_carts'];
            $funnel_sheet[] = [];
            $funnel_sheet[] = [ [ __( 'Abandoned carts', 'brikpanel' ), $T ] ];
            $funnel_sheet[] = [ [ __( 'Metric', 'brikpanel' ), $H ], [ __( 'Value', 'brikpanel' ), $H ], [ __( 'Change vs previous period', 'brikpanel' ), $H ] ];
            $funnel_sheet[] = [ __( 'Abandoned carts', 'brikpanel' ), (int) $ac['count'], $delta( $ac['delta'] ) ];
            $funnel_sheet[] = [ __( 'Cart value', 'brikpanel' ), trim( (string) preg_replace( '/[\x{200E}\x{200F}\x{2066}-\x{2069}]/u', '', (string) $ac['value'] ) ) ];
            $funnel_sheet[] = [ __( 'Recovered carts', 'brikpanel' ), (int) $ac['recovered'] ];
            $funnel_sheet[] = [ __( 'Recovery rate (%)', 'brikpanel' ), null === $ac['rate'] ? '' : (float) $ac['rate'] ];
        }

        // ---------- Campaigns: every campaign of the period ----------
        // The dashboard lists the top five; the workbook lists them all.
        $cmp_dates       = $this->calculate_dates( $range, $custom_start, $custom_end );
        $cmp_rows        = $this->get_campaigns( $cmp_dates['start_gmt'], $cmp_dates['end_gmt'], $cmp_dates['start_local'], $cmp_dates['end_local'], 0, function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active() );
        $campaigns_sheet = [ [
            [ __( 'Campaign', 'brikpanel' ), $H ],
            [ __( 'Visits', 'brikpanel' ), $H ],
            [ __( 'Orders', 'brikpanel' ), $H ],
            [ __( 'Conversion rate (%)', 'brikpanel' ), $H ],
            /* translators: %s: store currency code, e.g. USD. */
            [ sprintf( __( 'Revenue (%s)', 'brikpanel' ), $currency ), $H ],
        ] ];
        if ( empty( $cmp_rows ) ) {
            $campaigns_sheet[] = [ __( 'No data for this period', 'brikpanel' ), '' ];
        } else {
            foreach ( $cmp_rows as $cmp ) {
                $campaigns_sheet[] = [ $cmp['name'], (int) $cmp['visits'], (int) $cmp['orders'], null === $cmp['conversion'] ? '' : (float) $cmp['conversion'], $money( $cmp['revenue'] ) ];
            }
        }

        // ---------- Sheet 3: Order Status ----------
        $status = [
            [ [ __( 'Status', 'brikpanel' ), $H ], [ __( 'Share (%)', 'brikpanel' ), $H ] ],
            [ __( 'Successful', 'brikpanel' ), (float) $rates['successful'] ],
            [ __( 'Failed', 'brikpanel' ), (float) $rates['failed'] ],
            [ __( 'Returns & refunds', 'brikpanel' ), (float) $rates['refunded'] ],
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
            [ [ __( 'RFM segments (all-time)', 'brikpanel' ), $T ] ],
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
        // Every product of the period (up to PAGES_SHEET_MAX), not only the
        // five on the card: the same list as the card's "View all" window.
        $sheet_dates = $this->calculate_dates( $range, $custom_start, $custom_end );
        $products    = [ [ [ __( 'Product', 'brikpanel' ), $H ], [ __( 'Qty sold', 'brikpanel' ), $H ] ] ];
        $sold_list   = $this->products_list_query(
            'sold',
            $sheet_dates,
            [ 'limit' => self::PAGES_SHEET_MAX, 'exclude_marketplace' => function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active() ]
        );
        if ( empty( $sold_list['rows'] ) ) {
            $products[] = [ __( 'No data for this period', 'brikpanel' ), '' ];
        } else {
            foreach ( $sold_list['rows'] as $tp ) {
                $products[] = [ $tp['title'], (int) $tp['value'] ];
            }
        }

        // ---------- Sheet 7: Most Viewed ----------
        // Every page of the period (up to PAGES_SHEET_MAX), not only the five
        // on the card: the same list as the card's "View all" window.
        $viewed      = [ [ [ _x( 'Page', 'column header: a page of the store', 'brikpanel' ), $H ], [ __( 'Type', 'brikpanel' ), $H ], [ __( 'Views', 'brikpanel' ), $H ] ] ];
        $viewed_list = $this->most_viewed_query( $period['from_iso'], $period['to_iso'], [ 'limit' => self::PAGES_SHEET_MAX ] );
        if ( empty( $viewed_list['rows'] ) ) {
            $viewed[] = [ __( 'No data for this period', 'brikpanel' ), '', '' ];
        } else {
            foreach ( $viewed_list['rows'] as $mv ) {
                $viewed[] = [ $mv['title'], $mv['type'], (int) $mv['views'] ];
            }
        }

        // ---------- Sheet 8: Most Added to Cart ----------
        $carted    = [ [ [ __( 'Product', 'brikpanel' ), $H ], [ __( 'Cart adds', 'brikpanel' ), $H ] ] ];
        $cart_list = $this->products_list_query( 'cart', $sheet_dates, [ 'limit' => self::PAGES_SHEET_MAX ] );
        if ( empty( $cart_list['rows'] ) ) {
            $carted[] = [ __( 'No data for this period', 'brikpanel' ), '' ];
        } else {
            foreach ( $cart_list['rows'] as $mc ) {
                $carted[] = [ $mc['title'], (int) $mc['value'] ];
            }
        }

        // ---------- Sheet 9: Sales Over Time (daily series) ----------
        $sot = [ [ [ __( 'Date', 'brikpanel' ), $H ], [ sprintf( __( 'Revenue (%s)', 'brikpanel' ), $currency ), $H ], [ __( 'Orders', 'brikpanel' ), $H ] ] ];
        // The chart's series lists every day of the range; the sheet keeps
        // listing only the days with orders, as it always has.
        $sot_days = array_filter( (array) $d['sales_over_time'], static function ( $pt ) {
            return (int) ( $pt['orders'] ?? 0 ) > 0;
        } );
        if ( empty( $sot_days ) ) {
            $sot[] = [ __( 'No data for this period', 'brikpanel' ), '', '' ];
        } else {
            foreach ( $sot_days as $pt ) {
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
            $ls_empty   = is_array( $d['low_stock_empty'] ?? null ) ? $d['low_stock_empty'] : $this->low_stock_empty_state( $this->get_catalog_counts() );
            // The card's sentence, plus the out-of-stock count its link carries.
            $ls_line    = $ls_empty['text'];
            if ( '' !== $ls_empty['link'] && false !== strpos( $ls_empty['url'], 'outofstock' ) ) {
                $ls_line .= ' ' . $ls_empty['link'] . '.';
            }
            $lowstock[] = [ $ls_line, '', '' ];
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
        $date_fmt    = brikpanel_datetime_format();

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
            $orders = wc_get_orders( $args );
            if ( empty( $orders ) ) {
                break;
            }
            foreach ( $orders as $order ) {
                // Administrators' test orders stay out, as in the summary
                // sheet. wc_get_orders() has no query var for this, so it is
                // filtered here; paging still follows the unfiltered page.
                if ( $admin_ids && in_array( (int) $order->get_customer_id(), $admin_ids, true ) ) {
                    continue;
                }
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
        $writer->add_sheet( __( 'Order status', 'brikpanel' ), $status, [ 1 => 22, 2 => 12 ] );
        $writer->add_sheet( __( 'Devices', 'brikpanel' ), $devices_sheet, [ 1 => 16, 2 => 14, 3 => 14 ] );
        $writer->add_sheet( __( 'Campaigns', 'brikpanel' ), $campaigns_sheet, [ 1 => 32, 2 => 10, 3 => 10, 4 => 18, 5 => 16 ] );
        $writer->add_sheet( __( 'Customer segments', 'brikpanel' ), $segments_sheet, [ 1 => 28, 2 => 14, 3 => 14 ] );
        $writer->add_sheet( __( 'Top products', 'brikpanel' ), $products, [ 1 => 40, 2 => 12 ] );
        $writer->add_sheet( __( 'Most viewed', 'brikpanel' ), $viewed, [ 1 => 40, 2 => 16, 3 => 12 ] );
        $writer->add_sheet( __( 'Most added to cart', 'brikpanel' ), $carted, [ 1 => 40, 2 => 12 ] );
        $writer->add_sheet( __( 'Sales over time', 'brikpanel' ), $sot, [ 1 => 16, 2 => 16, 3 => 12 ], true );
        $writer->add_sheet( __( 'Countries', 'brikpanel' ), $countries, [ 1 => 24, 2 => 12, 3 => 14, 4 => 16 ] );
        $writer->add_sheet( __( 'Cities', 'brikpanel' ), $cities, [ 1 => 22, 2 => 16, 3 => 12, 4 => 14, 5 => 10 ] );
        $writer->add_sheet( __( 'Low stock', 'brikpanel' ), $lowstock, [ 1 => 40, 2 => 18, 3 => 12 ] );
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
