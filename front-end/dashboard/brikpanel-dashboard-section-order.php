<?php
/**
 * BrikPanel: dashboard boxes, their order and which ones show.
 *
 * One Settings field, "Dashboard sections", controls both the order of the
 * BrikPanel dashboard boxes AND which boxes show at all. Since 3.3.32 it lists
 * every box on its own (Sales over time, Live visitors, Conversion funnel,
 * Order rates and so on); up to 3.3.31 it listed rows of two boxes
 * ("Conversion funnel + Order rates"). Boxes next to each other in the list
 * share a row on the dashboard (Brikpanel_Dashboard::plan_rows()).
 *
 * Two options are persisted:
 *   - brikpanel_dashboard_visible_sections: flat array of the ticked keys in
 *     display order.
 *   - brikpanel_dashboard_section_order: JSON-encoded ordered list of every
 *     known key (ticked + unticked), so an unticked box keeps its slot and
 *     comes back there when it is ticked again.
 *
 * A store that saved the list before 3.3.32 still holds row keys there.
 * Nothing rewrites them on update: brikpanel_dashboard_layout() reads such a
 * list exactly as 3.3.31 did and opens each row into its boxes, so the
 * dashboard looks the same until the list is saved again, and going back to
 * 3.3.31 before that finds the data as it left it.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'BRIKPANEL_DASHBOARD_SECTION_ORDER_OPTION' ) ) {
    define( 'BRIKPANEL_DASHBOARD_SECTION_ORDER_OPTION', 'brikpanel_dashboard_section_order' );
}

/**
 * Tell Import / Export about the dashboard section layout.
 *
 * These two options are written by the same save handler and mean nothing
 * apart: the ORDER used to travel (it happened to be on a hand-kept list) while
 * the VISIBILITY did not, so a cloned store showed the source's arrangement of
 * a different set of cards. They are registered together now, and they are both
 * cleared together, so the pair can never drift again.
 *
 * @param array $map Registry so far.
 * @return array
 */
add_filter( 'brikpanel_exportable_option_keys', 'brikpanel_dashboard_sections_register_export_keys' );
function brikpanel_dashboard_sections_register_export_keys( $map ) {
    $map[ BRIKPANEL_DASHBOARD_SECTION_ORDER_OPTION ] = [
        'class'   => 'portable',
        'group'   => 'dashboard',
        'type'    => 'json_string',
        'default' => '',
    ];
    $map['brikpanel_dashboard_visible_sections'] = [
        'class'    => 'portable',
        'group'    => 'dashboard',
        'sanitize' => 'brikpanel_dashboard_sanitize_import_visible_sections',
        'default'  => [],
    ];
    return $map;
}

/**
 * Clean an imported list of visible dashboard sections.
 *
 * Keys the target does not know are dropped rather than kept: unlike a role
 * rule, an unknown box here cannot narrow anything, it would just sit in the
 * list forever. The reader already treats an empty list as "show all", so
 * dropping everything is safe and means the same as never having saved. The
 * row keys of a file exported before 3.3.32 are known: the reader opens them
 * into their boxes.
 *
 * @param mixed $value
 * @return string[]
 */
function brikpanel_dashboard_sanitize_import_visible_sections( $value ) {
    if ( ! is_array( $value ) ) {
        return [];
    }
    $known = array_keys( brikpanel_dashboard_section_label_map() );
    if ( $known ) {
        $known = array_merge( $known, array_keys( brikpanel_dashboard_legacy_rows() ) );
    }
    $out = [];
    foreach ( $value as $slug ) {
        if ( ! is_string( $slug ) ) {
            continue;
        }
        $slug = sanitize_key( $slug );
        if ( '' !== $slug && ( ! $known || in_array( $slug, $known, true ) ) && ! in_array( $slug, $out, true ) ) {
            $out[] = $slug;
        }
    }
    return $out;
}

// =============================================================================
// HELPERS
// =============================================================================

/**
 * Box key → label for every box the dashboard knows how to render, in the
 * factory order. The marketplace box is conditional, so we defer to the
 * dashboard class, which builds the list.
 *
 * @return array<string,string>
 */
function brikpanel_dashboard_section_label_map() {
    if ( class_exists( 'Brikpanel_Dashboard' ) ) {
        return Brikpanel_Dashboard::get_section_labels();
    }
    return [];
}

/**
 * The rows the setting listed up to 3.3.31, in their factory order, each
 * with the boxes it holds now. Two of them shared a card when they stood next
 * to each other (brikpanel_dashboard_expand_rows()). The marketplace row
 * existed only while BrikMarket was active, as its box does now.
 *
 * @return array<string,string[]>
 */
function brikpanel_dashboard_legacy_rows() {
    $rows = [
        'profit'                => [ 'profit' ],
        'kpis'                  => [ 'kpis' ],
        'marketplace_analytics' => [ 'marketplace_analytics' ],
        'sales_live'            => [ 'sales', 'live' ],
        'funnel_rates'          => [ 'funnel', 'rates' ],
        'locations'             => [ 'locations' ],
        'products_orders'       => [ 'best_sellers', 'recent_orders' ],
        'views_cart'            => [ 'most_viewed', 'most_added' ],
        'devices'               => [ 'visitors', 'customers' ],
        'customer_segments'     => [ 'segments' ],
        'stock_returns'         => [ 'low_stock', 'ltv' ],
        'subscriptions'         => [ 'subscriptions' ],
        'wp_widgets'            => [ 'wp_widgets' ],
    ];
    if ( ! ( function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active() ) ) {
        unset( $rows['marketplace_analytics'] );
    }
    return $rows;
}

/**
 * Box key → the 3.3.31 row it was part of.
 *
 * @return array<string,string>
 */
function brikpanel_dashboard_box_rows() {
    $out = [];
    foreach ( brikpanel_dashboard_legacy_rows() as $row => $boxes ) {
        foreach ( $boxes as $box ) {
            $out[ $box ] = $row;
        }
    }
    return $out;
}

/**
 * Put wp_widgets first when the old `brikpanel_dashboard_wp_widgets_position`
 * toggle says so, so installs upgrading from pre-reorder versions don't see
 * the WordPress widgets jump.
 *
 * @param string[] $keys
 * @return string[]
 */
function brikpanel_dashboard_wp_widgets_first( array $keys ) {
    if ( 'top' === get_option( 'brikpanel_dashboard_wp_widgets_position', 'bottom' ) && in_array( 'wp_widgets', $keys, true ) ) {
        $keys = array_values( array_diff( $keys, [ 'wp_widgets' ] ) );
        array_unshift( $keys, 'wp_widgets' );
    }
    return $keys;
}

/**
 * Factory-default box order (also what "Reset to default" restores).
 *
 * @return string[]
 */
function brikpanel_dashboard_default_section_order() {
    return brikpanel_dashboard_wp_widgets_first( array_keys( brikpanel_dashboard_section_label_map() ) );
}

/**
 * Slot every known key missing from a saved order into its factory position
 * rather than at the very end. A user who customised their layout still keeps
 * that order; a brand-new flagship box shows up where it was designed to sit,
 * not buried at the bottom where nobody would find it.
 *
 * @param string[] $order   Saved order, known keys only.
 * @param string[] $known   Every known key.
 * @param string[] $factory Factory order.
 * @return string[]
 */
function brikpanel_dashboard_slot_new_keys( array $order, array $known, array $factory ) {
    foreach ( $known as $slug ) {
        if ( in_array( $slug, $order, true ) ) {
            continue;
        }
        $factory_idx = array_search( $slug, $factory, true );
        if ( false === $factory_idx ) {
            $order[] = $slug;
            continue;
        }
        // Insert just before the first present key that sits AFTER this one in
        // the factory order; fall back to append.
        $insert_at = count( $order );
        for ( $i = $factory_idx + 1; $i < count( $factory ); $i++ ) {
            $pos = array_search( $factory[ $i ], $order, true );
            if ( false !== $pos ) {
                $insert_at = $pos;
                break;
            }
        }
        array_splice( $order, $insert_at, 0, [ $slug ] );
    }
    return $order;
}

/**
 * Keep the allowed keys of an order once each, then add the required ones
 * that are missing at the end.
 *
 * @param mixed    $order
 * @param string[] $allowed
 * @param string[] $required
 * @return string[]
 */
function brikpanel_dashboard_allowlist_order( $order, array $allowed, array $required ) {
    $clean = [];
    foreach ( (array) $order as $key ) {
        if ( is_string( $key ) && in_array( $key, $allowed, true ) && ! in_array( $key, $clean, true ) ) {
            $clean[] = $key;
        }
    }
    foreach ( $required as $key ) {
        if ( ! in_array( $key, $clean, true ) ) {
            $clean[] = $key;
        }
    }
    return $clean;
}

/**
 * A 3.3.31 row order read the way 3.3.31 read it: known rows once each, the
 * factory order when nothing usable is saved, rows that are new since the
 * save slotted at their factory place.
 *
 * @param string[] $stored Decoded saved order.
 * @return string[]
 */
function brikpanel_dashboard_legacy_row_order( array $stored ) {
    $known   = array_keys( brikpanel_dashboard_legacy_rows() );
    $factory = brikpanel_dashboard_wp_widgets_first( $known );
    $order   = [];
    foreach ( $stored as $slug ) {
        if ( is_string( $slug ) && in_array( $slug, $known, true ) && ! in_array( $slug, $order, true ) ) {
            $order[] = $slug;
        }
    }
    if ( empty( $order ) ) {
        $order = $factory;
    }
    return brikpanel_dashboard_slot_new_keys( $order, $known, $factory );
}

/**
 * The ticked rows of a 3.3.31 list, read the way 3.3.31 read them. Empty or
 * missing means "show all". A row missing from the saved order is new since
 * the save (a flagship section shipped in an update) and shows; a row that
 * is in the saved order but not ticked was hidden on purpose and stays hidden.
 *
 * @param mixed    $saved        Saved visible list.
 * @param string[] $stored_order Decoded saved order.
 * @return string[]
 */
function brikpanel_dashboard_legacy_row_visible( $saved, array $stored_order ) {
    $default = array_keys( brikpanel_dashboard_legacy_rows() );
    if ( ! is_array( $saved ) || empty( $saved ) ) {
        return $default;
    }
    // A box key in a row list (a file mixing both) ticks its row.
    $box_rows = brikpanel_dashboard_box_rows();
    $mapped   = [];
    foreach ( $saved as $slug ) {
        if ( ! is_string( $slug ) ) {
            continue;
        }
        $mapped[] = ( ! in_array( $slug, $default, true ) && isset( $box_rows[ $slug ] ) ) ? $box_rows[ $slug ] : $slug;
    }
    $visible = array_values( array_unique( array_intersect( $mapped, $default ) ) );
    if ( empty( $visible ) ) {
        return $default;
    }
    $known_at_save = array_values( array_filter( $stored_order, 'is_string' ) );
    if ( $known_at_save ) {
        foreach ( $default as $slug ) {
            if ( ! in_array( $slug, $known_at_save, true ) && ! in_array( $slug, $visible, true ) ) {
                $visible[] = $slug;
            }
        }
    }
    return $visible;
}

/**
 * Open 3.3.31 rows into their boxes, keeping how they looked: products_orders
 * and views_cart shared one Products card with three tabs, and devices and
 * customer_segments one Customers card, when both were ticked and stood next
 * to each other among the ticked rows (in either order; a hidden row between
 * them did not count). Then Best sellers comes first with the two lists right
 * after it, and Customer segments right after Customers, so the dashboard
 * joins them the same way (Brikpanel_Dashboard::plan_rows()). Box keys pass
 * through as they are.
 *
 * @param string[] $keys    Row (or box) keys in order.
 * @param string[] $visible Ticked row (or box) keys.
 * @return array{0:string[],1:string[]} Box order, ticked boxes.
 */
function brikpanel_dashboard_expand_rows( array $keys, array $visible ) {
    $rows  = brikpanel_dashboard_legacy_rows();
    $on    = array_fill_keys( array_values( array_filter( $visible, 'is_string' ) ), true );
    $shown = [];
    foreach ( $keys as $key ) {
        if ( is_string( $key ) && isset( $on[ $key ] ) ) {
            $shown[] = $key;
        }
    }
    $next_to = function ( $a, $b ) use ( $shown ) {
        $ia = array_search( $a, $shown, true );
        $ib = array_search( $b, $shown, true );
        return false !== $ia && false !== $ib && 1 === abs( $ia - $ib );
    };
    $merge_products  = $next_to( 'products_orders', 'views_cart' );
    $merge_customers = $next_to( 'devices', 'customer_segments' );

    $order = [];
    foreach ( $keys as $key ) {
        if ( ! is_string( $key ) ) {
            continue;
        }
        if ( ( 'views_cart' === $key && $merge_products ) || ( 'customer_segments' === $key && $merge_customers ) ) {
            continue;
        }
        if ( 'products_orders' === $key && $merge_products ) {
            $boxes = [ 'best_sellers', 'most_viewed', 'most_added', 'recent_orders' ];
        } elseif ( 'devices' === $key && $merge_customers ) {
            $boxes = [ 'visitors', 'customers', 'segments' ];
        } else {
            $boxes = isset( $rows[ $key ] ) ? $rows[ $key ] : [ $key ];
        }
        foreach ( $boxes as $box ) {
            if ( ! in_array( $box, $order, true ) ) {
                $order[] = $box;
            }
        }
    }

    $box_rows = brikpanel_dashboard_box_rows();
    $ticked   = [];
    foreach ( $order as $box ) {
        if ( isset( $on[ $box ] ) || ( isset( $box_rows[ $box ] ) && isset( $on[ $box_rows[ $box ] ] ) ) ) {
            $ticked[] = $box;
        }
    }
    return [ $order, $ticked ];
}

/**
 * Row keys among box keys (an old snippet, part of an old import) opened into
 * their boxes, each box once.
 *
 * @param mixed $keys
 * @return string[]
 */
function brikpanel_dashboard_open_rows( $keys ) {
    $rows = brikpanel_dashboard_legacy_rows();
    $out  = [];
    foreach ( (array) $keys as $key ) {
        if ( ! is_string( $key ) ) {
            continue;
        }
        foreach ( isset( $rows[ $key ] ) ? $rows[ $key ] : [ $key ] as $box ) {
            if ( ! in_array( $box, $out, true ) ) {
                $out[] = $box;
            }
        }
    }
    return $out;
}

/**
 * The dashboard layout from the two saved options: every known box in order,
 * and the ticked ones. Pure apart from the two filters (and the labels and
 * the wp_widgets toggle it reads), so the layout test can feed it any saved
 * state.
 *
 * A list saved before 3.3.32 holds row keys: it is read exactly as 3.3.31 read
 * it, both filters included, which still get row keys there, and then opened
 * into boxes. A list saved since holds box keys; the filters get box keys, and
 * a row key one of them returns opens into its boxes.
 *
 * @param mixed $stored_order   Saved order (a JSON string).
 * @param mixed $stored_visible Saved visible list (an array, or false when never saved).
 * @param array $args           'filters' => true on the dashboard itself.
 * @return array{order:string[],visible:string[],mode:string}
 */
function brikpanel_dashboard_resolve_layout( $stored_order, $stored_visible, array $args = [] ) {
    $filters  = ! empty( $args['filters'] );
    $known    = array_keys( brikpanel_dashboard_section_label_map() );
    $rows     = brikpanel_dashboard_legacy_rows();
    $row_keys = array_keys( $rows );
    $box_only = array_values( array_diff( $known, $row_keys ) );
    $split    = array_values( array_diff( $row_keys, $known ) );

    $o = [];
    if ( is_string( $stored_order ) && '' !== $stored_order ) {
        $decoded = json_decode( $stored_order, true );
        if ( is_array( $decoded ) ) {
            $o = array_values( array_filter( $decoded, 'is_string' ) );
        }
    }
    $v = is_array( $stored_visible ) ? array_values( array_filter( $stored_visible, 'is_string' ) ) : [];

    $box_mode = (bool) array_intersect( $o, $box_only )
        || ( ! array_intersect( $o, $split ) && array_intersect( $v, $box_only ) );

    if ( ! $box_mode ) {
        $row_order   = brikpanel_dashboard_legacy_row_order( $o );
        $row_visible = brikpanel_dashboard_legacy_row_visible( $stored_visible, $o );
        if ( $filters ) {
            $allowed = array_merge( $row_keys, $known );
            /**
             * Filter the order of the dashboard sections.
             *
             * Gets row keys while the saved list is one from before 3.3.32
             * (sales_live, funnel_rates, products_orders, views_cart, devices,
             * customer_segments, stock_returns, ...) and box keys once it was
             * saved since (sales, live, funnel, rates, best_sellers,
             * most_viewed, most_added, recent_orders, visitors, customers,
             * segments, low_stock, ltv, ...). Row keys in the result always
             * open into their boxes.
             *
             * @param string[] $order Keys in order, hidden ones included.
             * @param array    $known Known keys (as keys).
             */
            $filtered  = apply_filters( 'brikpanel_dashboard_section_order', $row_order, array_fill_keys( $row_keys, true ) );
            $row_order = brikpanel_dashboard_allowlist_order( is_array( $filtered ) ? $filtered : $row_keys, $allowed, $row_keys );
            /**
             * Filter the visible dashboard sections. Same keys as
             * `brikpanel_dashboard_section_order`.
             *
             * @param string[] $visible Keys that should render.
             * @param string[] $default All known keys.
             */
            $filtered    = apply_filters( 'brikpanel_dashboard_visible_sections', $row_visible, $row_keys );
            $row_visible = is_array( $filtered ) ? array_values( array_intersect( $filtered, $allowed ) ) : $row_keys;
        }
        list( $order, $visible ) = brikpanel_dashboard_expand_rows( $row_order, $row_visible );
    } else {
        $factory = brikpanel_dashboard_default_section_order();
        $saved   = array_values( array_intersect( brikpanel_dashboard_open_rows( $o ), $known ) );
        $order   = brikpanel_dashboard_slot_new_keys( $saved ? $saved : $factory, $known, $factory );

        $visible = array_values( array_intersect( brikpanel_dashboard_open_rows( $v ), $known ) );
        if ( ! $visible ) {
            $visible = $known;
        } elseif ( $saved ) {
            // A box missing from the saved order is new since the save: show it.
            foreach ( $known as $box ) {
                if ( ! in_array( $box, $saved, true ) && ! in_array( $box, $visible, true ) ) {
                    $visible[] = $box;
                }
            }
        }
        if ( $filters ) {
            /** This filter is documented above. */
            $filtered = apply_filters( 'brikpanel_dashboard_section_order', $order, array_fill_keys( $known, true ) );
            $order    = brikpanel_dashboard_allowlist_order( brikpanel_dashboard_open_rows( is_array( $filtered ) ? $filtered : $known ), $known, $known );
            /** This filter is documented above. */
            $filtered = apply_filters( 'brikpanel_dashboard_visible_sections', $visible, $known );
            $visible  = is_array( $filtered ) ? array_values( array_intersect( brikpanel_dashboard_open_rows( $filtered ), $known ) ) : $known;
        }
    }

    $order = brikpanel_dashboard_allowlist_order( $order, $known, $known );
    $on    = array_fill_keys( $visible, true );
    $shown = [];
    foreach ( $order as $key ) {
        if ( isset( $on[ $key ] ) ) {
            $shown[] = $key;
        }
    }
    return [
        'order'   => $order,
        'visible' => $shown,
        'mode'    => $box_mode ? 'box' : 'legacy',
    ];
}

/**
 * The saved dashboard layout (brikpanel_dashboard_resolve_layout()).
 *
 * @param bool $for_render True on the dashboard itself: runs the two filters.
 * @return array{order:string[],visible:string[],mode:string}
 */
function brikpanel_dashboard_layout( $for_render = false ) {
    return brikpanel_dashboard_resolve_layout(
        get_option( BRIKPANEL_DASHBOARD_SECTION_ORDER_OPTION, '' ),
        get_option( 'brikpanel_dashboard_visible_sections' ),
        [ 'filters' => (bool) $for_render ]
    );
}

/**
 * Every known box in its saved order, hidden ones included.
 *
 * @return string[]
 */
function brikpanel_dashboard_get_section_order() {
    $layout = brikpanel_dashboard_layout();
    return $layout['order'];
}

// =============================================================================
// PROFIT SECTION — PER-FIELD VISIBILITY
// =============================================================================

/**
 * Optional fields inside the dashboard Profit section that the merchant can
 * hide. Revenue and Net profit are always shown (they are the whole point of
 * the section); everything here is a deduction or an informational line a
 * store may not care about (e.g. a shop that never sets Cost of goods, or one
 * with no taxes configured).
 *
 * Key → human label, label kept short for the checkbox list.
 *
 * @return array<string,string>
 */
function brikpanel_dashboard_profit_field_labels() {
    return [
        'cogs'     => __( 'Cost of goods', 'brikpanel' ),
        'expenses' => __( 'Expenses (taxes, ad spend, supplier costs)', 'brikpanel' ),
        'returns'  => __( 'Returns (refunds)', 'brikpanel' ),
        'coupons'  => __( 'Coupons (discounts given)', 'brikpanel' ),
    ];
}

/**
 * Which Profit-section fields are currently enabled for display.
 *
 * Contract mirrors the section visibility option: a never-configured store
 * shows everything, while an explicit empty selection hides every optional
 * field (Revenue + Net profit still render). Hiding a field is display-only —
 * the underlying figure is still subtracted from Net profit where it is a real
 * cost, so the number on the card stays truthful regardless of what is shown.
 *
 * @return array<string,bool> Map of enabled field key => true.
 */
function brikpanel_dashboard_profit_fields() {
    $all = array_keys( brikpanel_dashboard_profit_field_labels() );
    $val = get_option( 'brikpanel_dashboard_profit_fields', false );

    if ( false === $val ) {
        return array_fill_keys( $all, true ); // never configured → show all
    }
    if ( ! is_array( $val ) ) {
        $val = [];
    }
    $set = [];
    foreach ( $val as $k ) {
        if ( in_array( $k, $all, true ) ) {
            $set[ $k ] = true;
        }
    }
    return $set;
}

/**
 * Whether a single Profit-section field is enabled for display.
 *
 * @param string $key One of cogs|expenses|returns|coupons.
 * @return bool
 */
function brikpanel_dashboard_profit_field_enabled( $key ) {
    $set = brikpanel_dashboard_profit_fields();
    return ! empty( $set[ $key ] );
}

// =============================================================================
// SETTINGS PAGE: CUSTOM FIELD TYPE
// =============================================================================

/**
 * Render the dashboard section order field. Wired up via
 * `add_action('woocommerce_admin_field_brikpanel_dashboard_section_order', ...)`.
 *
 * One row per box. A line on the inline-start side joins the rows that land
 * on the same dashboard row, worked out by the script below with the rules of
 * Brikpanel_Dashboard::plan_rows(), so moving or ticking a box shows at once
 * which boxes will sit side by side.
 *
 * @param array $field WC settings field definition.
 */
function brikpanel_render_dashboard_section_order_field( $field ) {
    $labels = brikpanel_dashboard_section_label_map();
    if ( empty( $labels ) ) {
        return;
    }
    $layout      = brikpanel_dashboard_layout();
    $visible_set = array_flip( $layout['visible'] );

    // Boxes that print nothing on this store (they never take a dashboard
    // row, so they never split a pair either).
    $silent = [];
    if ( ! class_exists( 'WC_Subscriptions' ) ) {
        $silent['subscriptions'] = true;
    }
    if ( ! array_filter( (array) get_option( 'brikpanel_dashboard_wp_widgets', [] ) ) ) {
        $silent['wp_widgets'] = true;
    }

    $title    = ! empty( $field['name'] ) ? esc_html( $field['name'] ) : '';
    $tooltip  = ! empty( $field['desc_tip'] ) && ! empty( $field['desc'] ) ? wc_help_tip( $field['desc'] ) : '';
    $help     = ( empty( $field['desc_tip'] ) && ! empty( $field['desc'] ) ) ? $field['desc'] : '';
    $defaults = brikpanel_dashboard_default_section_order();
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <label><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?></label>
            <?php echo $tooltip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip() escapes ?>
        </th>
        <td class="forminp">
            <div class="brikpanel-section-order brikpanel-dash-section-order" id="brikpanel-dashboard-section-order" data-default-order="<?php echo esc_attr( wp_json_encode( $defaults ) ); ?>">
                <?php if ( $help !== '' ) : ?>
                    <p class="brikpanel-section-order-help"><?php echo esc_html( $help ); ?></p>
                <?php endif; ?>
                <ul class="brikpanel-section-order-list" role="list">
                    <?php foreach ( $layout['order'] as $slug ) :
                        if ( ! isset( $labels[ $slug ] ) ) continue;
                        $is_visible = isset( $visible_set[ $slug ] );
                        $kind       = class_exists( 'Brikpanel_Dashboard' ) ? Brikpanel_Dashboard::box_kind( $slug ) : 'half';
                        ?>
                        <li class="brikpanel-section-order-row<?php echo $is_visible ? '' : ' is-hidden-section'; ?>" data-slug="<?php echo esc_attr( $slug ); ?>" data-kind="<?php echo esc_attr( $kind ); ?>"<?php echo isset( $silent[ $slug ] ) ? ' data-silent="1"' : ''; ?>>
                            <label class="brikpanel-section-order-toggle">
                                <input type="checkbox" class="brikpanel-section-order-checkbox" name="brikpanel_dashboard_section_visibility[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $is_visible ); ?>>
                                <span class="brikpanel-section-order-title"><?php echo esc_html( $labels[ $slug ] ); ?></span>
                            </label>
                            <div class="brikpanel-section-order-actions">
                                <button type="button" class="brikpanel-section-order-btn brikpanel-section-order-up" aria-label="<?php esc_attr_e( 'Move up', 'brikpanel' ); ?>" title="<?php esc_attr_e( 'Move up', 'brikpanel' ); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="18 15 12 9 6 15"/></svg>
                                </button>
                                <button type="button" class="brikpanel-section-order-btn brikpanel-section-order-down" aria-label="<?php esc_attr_e( 'Move down', 'brikpanel' ); ?>" title="<?php esc_attr_e( 'Move down', 'brikpanel' ); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                                </button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="brikpanel-section-order-footer">
                    <button type="button" class="brikpanel-section-order-reset" id="brikpanel-dashboard-section-order-reset">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                        <?php esc_html_e( 'Reset to default', 'brikpanel' ); ?>
                    </button>
                </div>
                <input type="hidden" id="brikpanel_dashboard_section_order_json" name="brikpanel_dashboard_section_order_json" value="<?php echo esc_attr( wp_json_encode( $layout['order'] ) ); ?>">
            </div>
            <style>
            .brikpanel-dash-section-order {
                max-width: 560px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-help {
                margin: 0 0 .625rem;
                color: #616161;
                font-size: .8125rem;
                line-height: 1.5;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-list {
                margin: 0;
                padding: 0;
                list-style: none;
                background: #ffffff;
                border: 1px solid #e3e3e3;
                border-radius: .5rem;
                overflow: hidden;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-row {
                position: relative;
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding-block: .5rem;
                padding-inline: 1.375rem .75rem;
                border-top: 1px solid #f0f0f0;
                background: #ffffff;
                gap: .75rem;
                transition: background .15s ease;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-row:first-child {
                border-top: 0;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-row:hover {
                background: #fafafa;
            }
            /* The line that joins the rows landing on one dashboard row. */
            .brikpanel-dash-section-order .brikpanel-section-order-row.is-pair-start::before,
            .brikpanel-dash-section-order .brikpanel-section-order-row.is-pair-mid::before,
            .brikpanel-dash-section-order .brikpanel-section-order-row.is-pair-end::before {
                content: "";
                position: absolute;
                inset-inline-start: .5rem;
                width: .375rem;
                box-sizing: border-box;
                border: 0 solid #8a8a8a;
                border-inline-start-width: 2px;
                pointer-events: none;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-row.is-pair-start::before {
                top: 50%;
                bottom: -1px;
                border-top-width: 2px;
                border-start-start-radius: .3125rem;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-row.is-pair-mid::before {
                top: -1px;
                bottom: -1px;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-row.is-pair-end::before {
                top: -1px;
                bottom: 50%;
                border-bottom-width: 2px;
                border-end-start-radius: .3125rem;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-row.is-hidden-section .brikpanel-section-order-title {
                color: #8a8a8a;
                text-decoration: line-through;
                text-decoration-color: #c8c8c8;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-toggle {
                display: flex;
                align-items: center;
                gap: .625rem;
                cursor: pointer;
                flex: 1;
                min-width: 0;
                font-size: .8125rem;
                font-weight: 550;
                color: #303030;
                line-height: 1.4;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-checkbox {
                margin: 0 !important;
                width: 14px;
                height: 14px;
                border-radius: 3px;
                cursor: pointer;
                flex-shrink: 0;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-checkbox:focus {
                box-shadow: 0 0 0 2px rgba(48, 48, 48, .15);
            }
            .brikpanel-dash-section-order .brikpanel-section-order-title {
                min-width: 0;
                overflow-wrap: anywhere;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-actions {
                display: flex;
                gap: 2px;
                flex-shrink: 0;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 26px;
                height: 26px;
                padding: 0;
                background: #ffffff;
                color: #616161;
                border: 1px solid #e3e3e3;
                border-radius: .375rem;
                cursor: pointer;
                transition: all .15s ease;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-btn:hover:not(:disabled) {
                background: #f4f4f4;
                color: #303030;
                border-color: #c8c8c8;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-btn:focus {
                outline: none;
                box-shadow: 0 0 0 2px rgba(48, 48, 48, .15);
            }
            .brikpanel-dash-section-order .brikpanel-section-order-btn:disabled {
                opacity: .35;
                cursor: not-allowed;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-footer {
                display: flex;
                justify-content: flex-end;
                margin-top: .5rem;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-reset {
                display: inline-flex;
                align-items: center;
                gap: .375rem;
                padding: .375rem .625rem;
                background: transparent;
                color: #616161;
                border: 0;
                border-radius: .375rem;
                font-size: .75rem;
                font-weight: 550;
                cursor: pointer;
                transition: color .15s ease, background .15s ease;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-reset:hover {
                color: #303030;
                background: #f4f4f4;
            }
            .brikpanel-dash-section-order .brikpanel-section-order-reset:focus {
                outline: none;
                box-shadow: 0 0 0 2px rgba(48, 48, 48, .15);
            }
            </style>
            <script>
            (function () {
                var root = document.getElementById('brikpanel-dashboard-section-order');
                if (!root || root.dataset.bpInit === '1') return;
                root.dataset.bpInit = '1';

                var list   = root.querySelector('.brikpanel-section-order-list');
                var hidden = root.querySelector('#brikpanel_dashboard_section_order_json');
                if (!list || !hidden) return;

                function rowsOf() {
                    return Array.prototype.slice.call(list.querySelectorAll('.brikpanel-section-order-row'));
                }

                // Brikpanel_Dashboard::plan_rows() on the ticked rows: join the
                // cards, skip the boxes that print nothing, pair the others two
                // by two, and draw a line along the rows of each pair.
                function markPairs() {
                    var rows = rowsOf();
                    rows.forEach(function (row) {
                        row.classList.remove('is-pair-start', 'is-pair-mid', 'is-pair-end');
                    });
                    var shown = rows.filter(function (row) {
                        var cb = row.querySelector('.brikpanel-section-order-checkbox');
                        return cb && cb.checked;
                    });
                    var units = [];
                    for (var i = 0; i < shown.length; i++) {
                        var slug = shown[i].getAttribute('data-slug');
                        var unit = {
                            first: shown[i],
                            last: shown[i],
                            kind: shown[i].getAttribute('data-kind') || 'half',
                            silent: shown[i].hasAttribute('data-silent')
                        };
                        if (slug === 'best_sellers') {
                            while (i + 1 < shown.length && /^(most_viewed|most_added)$/.test(shown[i + 1].getAttribute('data-slug'))) {
                                i++;
                                unit.last = shown[i];
                            }
                        } else if (slug === 'customers' && i + 1 < shown.length && shown[i + 1].getAttribute('data-slug') === 'segments') {
                            i++;
                            unit.last = shown[i];
                        }
                        units.push(unit);
                    }
                    var pending = null;
                    units.forEach(function (unit) {
                        if (unit.silent) return;
                        if (unit.kind !== 'half') {
                            pending = null;
                            return;
                        }
                        if (!pending) {
                            pending = unit;
                            return;
                        }
                        var from = rows.indexOf(pending.first);
                        var to   = rows.indexOf(unit.last);
                        rows[from].classList.add('is-pair-start');
                        rows[to].classList.add('is-pair-end');
                        for (var k = from + 1; k < to; k++) {
                            rows[k].classList.add('is-pair-mid');
                        }
                        pending = null;
                    });
                }

                // notify: tell WooCommerce the form changed (its "leave page?"
                // warning only listens to form fields, and an arrow is not one).
                function refresh(notify) {
                    var rows = rowsOf();
                    var order = [];
                    rows.forEach(function (row, idx) {
                        order.push(row.getAttribute('data-slug'));
                        var up   = row.querySelector('.brikpanel-section-order-up');
                        var down = row.querySelector('.brikpanel-section-order-down');
                        if (up)   up.disabled   = idx === 0;
                        if (down) down.disabled = idx === rows.length - 1;
                    });
                    hidden.value = JSON.stringify(order);
                    markPairs();
                    if (notify) {
                        hidden.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }

                list.addEventListener('click', function (e) {
                    var btn = e.target.closest('.brikpanel-section-order-up, .brikpanel-section-order-down');
                    if (!btn) return;
                    var row = btn.closest('.brikpanel-section-order-row');
                    if (!row) return;
                    e.preventDefault();
                    if (btn.classList.contains('brikpanel-section-order-up')) {
                        var prev = row.previousElementSibling;
                        if (prev) list.insertBefore(row, prev);
                    } else {
                        var next = row.nextElementSibling;
                        if (next) list.insertBefore(next, row);
                    }
                    refresh(true);
                    // Keep the focus on the moved row's arrow when there is one.
                    if (!btn.disabled) btn.focus();
                });

                list.addEventListener('change', function (e) {
                    var cb = e.target.closest('.brikpanel-section-order-checkbox');
                    if (!cb) return;
                    var row = cb.closest('.brikpanel-section-order-row');
                    if (!row) return;
                    row.classList.toggle('is-hidden-section', !cb.checked);
                    markPairs();
                });

                var resetBtn = root.querySelector('#brikpanel-dashboard-section-order-reset');
                if (resetBtn) {
                    resetBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        var defaults;
                        try { defaults = JSON.parse(root.dataset.defaultOrder || '[]'); }
                        catch (err) { defaults = []; }
                        if (!Array.isArray(defaults) || defaults.length === 0) return;
                        var rowMap = {};
                        rowsOf().forEach(function (row) {
                            rowMap[row.getAttribute('data-slug')] = row;
                        });
                        defaults.forEach(function (slug) {
                            var row = rowMap[slug];
                            if (!row) return;
                            list.appendChild(row);
                            var cb = row.querySelector('.brikpanel-section-order-checkbox');
                            if (cb) cb.checked = true;
                            row.classList.remove('is-hidden-section');
                        });
                        refresh(true);
                    });
                }

                refresh(false);
            })();
            </script>
        </td>
    </tr>
    <?php
}
add_action( 'woocommerce_admin_field_brikpanel_dashboard_section_order', 'brikpanel_render_dashboard_section_order_field' );

// =============================================================================
// SETTINGS PAGE: SAVE
// =============================================================================

/**
 * Keys from a form post: sanitized, allowed ones only, each once.
 *
 * @param mixed    $keys
 * @param string[] $allowed
 * @return string[]
 */
function brikpanel_dashboard_clean_posted_keys( $keys, array $allowed ) {
    $out = [];
    foreach ( (array) $keys as $key ) {
        if ( ! is_string( $key ) ) {
            continue;
        }
        $key = sanitize_key( $key );
        if ( in_array( $key, $allowed, true ) && ! in_array( $key, $out, true ) ) {
            $out[] = $key;
        }
    }
    return $out;
}

/**
 * Persist the dashboard box order + visibility when the BrikPanel WC settings
 * tab is submitted. WooCommerce checked its settings nonce before this runs,
 * and the BrikPanel settings lock has already let the user in. Runs at
 * priority 11, after the default `woocommerce_update_options()` pass, so its
 * sibling option writes don't race with this one.
 *
 * A settings tab opened before the 3.3.32 update and saved after it still
 * posts row keys: they open into their boxes the way the dashboard showed them.
 */
function brikpanel_dashboard_save_section_layout() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    if ( ! isset( $_POST['brikpanel_dashboard_section_order_json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified its settings nonce
        return;
    }
    $known = array_keys( brikpanel_dashboard_section_label_map() );
    if ( empty( $known ) ) {
        return;
    }
    $allowed = array_merge( $known, array_keys( brikpanel_dashboard_legacy_rows() ) );

    $decoded = json_decode( (string) wp_unslash( $_POST['brikpanel_dashboard_section_order_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- JSON, every key sanitized below
    if ( ! is_array( $decoded ) ) {
        return;
    }
    $posted_order   = brikpanel_dashboard_clean_posted_keys( $decoded, $allowed );
    $posted_visible = isset( $_POST['brikpanel_dashboard_section_visibility'] ) && is_array( $_POST['brikpanel_dashboard_section_visibility'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
        ? brikpanel_dashboard_clean_posted_keys( wp_unslash( $_POST['brikpanel_dashboard_section_visibility'] ), $allowed ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- sanitized per key
        : [];

    list( $order, $ticked ) = brikpanel_dashboard_expand_rows( $posted_order, $posted_visible );
    $order = brikpanel_dashboard_allowlist_order( $order, $known, $known );
    $on    = array_fill_keys( $ticked, true );
    $visible = [];
    foreach ( $order as $key ) {
        if ( isset( $on[ $key ] ) ) {
            $visible[] = $key;
        }
    }
    update_option( BRIKPANEL_DASHBOARD_SECTION_ORDER_OPTION, wp_json_encode( $order ), false );
    update_option( 'brikpanel_dashboard_visible_sections', $visible, false );
}
add_action( 'woocommerce_update_options_brikpanel', 'brikpanel_dashboard_save_section_layout', 11 );
