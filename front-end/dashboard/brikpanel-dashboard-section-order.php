<?php
/**
 * BrikPanel — Dashboard section order & visibility picker.
 *
 * One Settings field that controls both the order in which the BrikPanel
 * dashboard sections render AND which sections render at all. Mirrors the
 * "Visible editor sections" picker used for the simplified product editor
 * (see front-end/products/brikpanel-section-order.php) so the admin sees the
 * same interaction pattern in both places.
 *
 * Two options are persisted:
 *   - brikpanel_dashboard_visible_sections: flat array of currently visible
 *     section keys in display order. Read at render time by
 *     Brikpanel_Dashboard::get_visible_sections().
 *   - brikpanel_dashboard_section_order: JSON-encoded ordered list of every
 *     known section key (visible + hidden). Lets us preserve a hidden
 *     section's slot so re-enabling it returns to its prior position. Read
 *     at render time by Brikpanel_Dashboard::resolve_section_order().
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'BRIKPANEL_DASHBOARD_SECTION_ORDER_OPTION' ) ) {
    define( 'BRIKPANEL_DASHBOARD_SECTION_ORDER_OPTION', 'brikpanel_dashboard_section_order' );
}

// =============================================================================
// HELPERS
// =============================================================================

/**
 * Section key → label map for every section the dashboard knows how to
 * render. Marketplace section is conditional, so we defer to the dashboard
 * class which already builds the list dynamically.
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
 * Factory-default section order. Honours the legacy
 * `brikpanel_dashboard_wp_widgets_position` toggle so installs upgrading from
 * pre-reorder versions don't see the WordPress widgets jump unexpectedly.
 *
 * @return string[]
 */
function brikpanel_dashboard_default_section_order() {
    $known  = array_keys( brikpanel_dashboard_section_label_map() );
    $wp_pos = get_option( 'brikpanel_dashboard_wp_widgets_position', 'bottom' );
    if ( $wp_pos === 'top' && in_array( 'wp_widgets', $known, true ) ) {
        $known = array_values( array_filter( $known, function ( $k ) { return $k !== 'wp_widgets'; } ) );
        array_unshift( $known, 'wp_widgets' );
    }
    return $known;
}

/**
 * Resolve the persisted full section order, including hidden ones.
 *
 * Falls back to the factory-default order when nothing is saved, and
 * appends any newly introduced section keys at the end so a plugin update
 * never silently drops them from the UI.
 *
 * @return string[]
 */
function brikpanel_dashboard_get_section_order() {
    $known  = array_keys( brikpanel_dashboard_section_label_map() );
    $stored = get_option( BRIKPANEL_DASHBOARD_SECTION_ORDER_OPTION, '' );
    $order  = [];

    if ( is_string( $stored ) && $stored !== '' ) {
        $decoded = json_decode( $stored, true );
        if ( is_array( $decoded ) ) {
            foreach ( $decoded as $slug ) {
                if ( is_string( $slug ) && in_array( $slug, $known, true ) && ! in_array( $slug, $order, true ) ) {
                    $order[] = $slug;
                }
            }
        }
    }

    if ( empty( $order ) ) {
        $order = brikpanel_dashboard_default_section_order();
    }

    // Slot any newly introduced section into its factory-default position
    // rather than dumping it at the very end. A user who customised their
    // layout still keeps that order; a brand-new flagship section (e.g.
    // Profit) shows up where it was designed to sit, not buried at the
    // bottom where nobody would find it.
    $factory = brikpanel_dashboard_default_section_order();
    foreach ( $known as $slug ) {
        if ( in_array( $slug, $order, true ) ) {
            continue;
        }
        $factory_idx = array_search( $slug, $factory, true );
        if ( false === $factory_idx ) {
            $order[] = $slug;
            continue;
        }
        // Find the first already-present section that sits AFTER this one in
        // the factory order and insert just before it; fall back to append.
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

// =============================================================================
// SETTINGS PAGE: CUSTOM FIELD TYPE
// =============================================================================

/**
 * Render the dashboard section order field. Wired up via
 * `add_action('woocommerce_admin_field_brikpanel_dashboard_section_order', ...)`.
 *
 * @param array $field WC settings field definition.
 */
function brikpanel_render_dashboard_section_order_field( $field ) {
    $labels = brikpanel_dashboard_section_label_map();
    if ( empty( $labels ) ) {
        return;
    }
    $order       = brikpanel_dashboard_get_section_order();
    $known_keys  = array_keys( $labels );
    $visible_raw = get_option( 'brikpanel_dashboard_visible_sections' );

    // Empty/missing visibility option means "show all" — the dashboard's own
    // contract (see Brikpanel_Dashboard::get_visible_sections). Mirror it
    // here so the checkboxes don't all start unchecked on a fresh install.
    if ( ! is_array( $visible_raw ) || empty( $visible_raw ) ) {
        $visible_set = array_flip( $known_keys );
    } else {
        $intersect = array_values( array_intersect( $visible_raw, $known_keys ) );
        $visible_set = empty( $intersect ) ? array_flip( $known_keys ) : array_flip( $intersect );
    }

    $title    = ! empty( $field['name'] ) ? esc_html( $field['name'] ) : '';
    $tooltip  = ! empty( $field['desc_tip'] ) && ! empty( $field['desc'] ) ? wc_help_tip( $field['desc'] ) : '';
    $help     = ( empty( $field['desc_tip'] ) && ! empty( $field['desc'] ) ) ? $field['desc'] : '';
    $defaults = brikpanel_dashboard_default_section_order();
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <label><?php echo $title; ?></label>
            <?php echo $tooltip; ?>
        </th>
        <td class="forminp">
            <div class="brikpanel-section-order brikpanel-dash-section-order" id="brikpanel-dashboard-section-order" data-default-order="<?php echo esc_attr( wp_json_encode( $defaults ) ); ?>">
                <?php if ( $help !== '' ) : ?>
                    <p class="brikpanel-section-order-help"><?php echo esc_html( $help ); ?></p>
                <?php endif; ?>
                <ul class="brikpanel-section-order-list" role="list">
                    <?php foreach ( $order as $slug ) :
                        if ( ! isset( $labels[ $slug ] ) ) continue;
                        $is_visible = isset( $visible_set[ $slug ] );
                        ?>
                        <li class="brikpanel-section-order-row<?php echo $is_visible ? '' : ' is-hidden-section'; ?>" data-slug="<?php echo esc_attr( $slug ); ?>">
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
                <input type="hidden" id="brikpanel_dashboard_section_order_json" name="brikpanel_dashboard_section_order_json" value="<?php echo esc_attr( wp_json_encode( $order ) ); ?>">
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
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: .5rem .75rem;
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
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
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

                function refresh() {
                    var rows = list.querySelectorAll('.brikpanel-section-order-row');
                    var order = [];
                    rows.forEach(function (row, idx) {
                        order.push(row.getAttribute('data-slug'));
                        var up   = row.querySelector('.brikpanel-section-order-up');
                        var down = row.querySelector('.brikpanel-section-order-down');
                        if (up)   up.disabled   = idx === 0;
                        if (down) down.disabled = idx === rows.length - 1;
                    });
                    hidden.value = JSON.stringify(order);
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
                    refresh();
                });

                list.addEventListener('change', function (e) {
                    var cb = e.target.closest('.brikpanel-section-order-checkbox');
                    if (!cb) return;
                    var row = cb.closest('.brikpanel-section-order-row');
                    if (!row) return;
                    row.classList.toggle('is-hidden-section', !cb.checked);
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
                        list.querySelectorAll('.brikpanel-section-order-row').forEach(function (row) {
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
                        refresh();
                    });
                }

                refresh();
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
 * Persist dashboard section order + visibility when the BrikPanel WC settings
 * tab is submitted. Runs at priority 11 — after the default
 * `woocommerce_update_options()` pass so its sibling option writes don't race
 * with this one.
 */
add_action( 'woocommerce_update_options_brikpanel', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    if ( ! isset( $_POST['brikpanel_dashboard_section_order_json'] ) ) {
        return;
    }
    $known = array_keys( brikpanel_dashboard_section_label_map() );
    if ( empty( $known ) ) {
        return;
    }

    $raw     = wp_unslash( $_POST['brikpanel_dashboard_section_order_json'] );
    $decoded = json_decode( $raw, true );
    if ( ! is_array( $decoded ) ) {
        return;
    }
    $order = [];
    foreach ( $decoded as $slug ) {
        if ( is_string( $slug ) && in_array( $slug, $known, true ) && ! in_array( $slug, $order, true ) ) {
            $order[] = $slug;
        }
    }
    foreach ( $known as $slug ) {
        if ( ! in_array( $slug, $order, true ) ) {
            $order[] = $slug;
        }
    }
    update_option( BRIKPANEL_DASHBOARD_SECTION_ORDER_OPTION, wp_json_encode( $order ), false );

    $visibility_raw = isset( $_POST['brikpanel_dashboard_section_visibility'] ) && is_array( $_POST['brikpanel_dashboard_section_visibility'] )
        ? wp_unslash( $_POST['brikpanel_dashboard_section_visibility'] )
        : [];
    $visible_set = [];
    foreach ( $visibility_raw as $slug ) {
        if ( is_string( $slug ) && in_array( $slug, $known, true ) ) {
            $visible_set[ $slug ] = true;
        }
    }
    $visible = [];
    foreach ( $order as $slug ) {
        if ( ! empty( $visible_set[ $slug ] ) ) {
            $visible[] = $slug;
        }
    }
    update_option( 'brikpanel_dashboard_visible_sections', $visible, false );
}, 11 );
