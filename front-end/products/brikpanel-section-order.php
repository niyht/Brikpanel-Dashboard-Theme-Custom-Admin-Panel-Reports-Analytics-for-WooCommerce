<?php
/**
 * Section order picker for the simplified product editor.
 *
 * Replaces the multiselect-based "Visible editor sections" setting with a
 * reorderable, toggle-able list. The saved value drives both visibility and
 * the order in which sections render inside the BrikPanel product editor.
 *
 * Two options are persisted:
 *   - brikpanel_pe_visible_sections (legacy, kept for backwards compat):
 *     a flat array of currently visible slugs in display order.
 *   - brikpanel_pe_section_order: a JSON-encoded ordered list of every known
 *     slug (visible + hidden). Lets us preserve a hidden section's position
 *     so it returns to its slot when the admin re-enables it.
 */

if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// HELPERS
// =============================================================================

if (!defined('BRIKPANEL_PE_SECTION_ORDER_OPTION')) {
    define('BRIKPANEL_PE_SECTION_ORDER_OPTION', 'brikpanel_pe_section_order');
}

/**
 * Canonical slug → label map for every section the simplified editor knows
 * how to render. The order of keys here is the factory-default display order.
 *
 * @return array<string,string>
 */
function brikpanel_pe_section_options() {
    return [
        'images'      => __('Product images', 'brikpanel'),
        'variations'  => __('Variations (sizes/colors)', 'brikpanel'),
        'pricing'     => __('Pricing', 'brikpanel'),
        'cogs'        => __('Cost of goods (COGS)', 'brikpanel'),
        'inventory'   => __('Inventory (stock & SKU)', 'brikpanel'),
        'gtin'        => __('GTIN / barcode (UPC, EAN, ISBN)', 'brikpanel'),
        'tax'         => __('Tax (status & class)', 'brikpanel'),
        'sold_individually' => __('Sold individually (limit 1 per order)', 'brikpanel'),
        'linked'      => __('Linked products (upsells & cross-sells)', 'brikpanel'),
        'advanced'    => __('Advanced (purchase note, reviews, menu order)', 'brikpanel'),
        'category'    => __('Category', 'brikpanel'),
        'tags'        => __('Tags', 'brikpanel'),
        'short_desc'  => __('Short description', 'brikpanel'),
        'description' => __('Product description', 'brikpanel'),
        'digital'     => __('Digital product (downloadable)', 'brikpanel'),
        'weight'      => __('Shipping weight', 'brikpanel'),
        'dimensions'  => __('Shipping dimensions', 'brikpanel'),
        'shipping_class' => __('Shipping class', 'brikpanel'),
        'seo'         => __('SEO settings', 'brikpanel'),
        'attributes'  => __('Product attributes (specs)', 'brikpanel'),
    ];
}

/**
 * Sections that are hidden out of the box. They still appear in the
 * "Additional product data sections" settings list so an admin can opt in,
 * but they stay off until then. Keeps the editor lean by default while
 * letting power users surface niche fields like the product GTIN/barcode.
 *
 * @return string[]
 */
function brikpanel_pe_section_default_hidden() {
    return ['gtin', 'tax', 'weight', 'dimensions', 'tags', 'shipping_class', 'sold_individually', 'linked', 'advanced'];
}

/**
 * Factory-default visible set (everything except the opt-in sections).
 *
 * @return string[]
 */
function brikpanel_pe_section_default_visible() {
    return array_values(array_diff(
        array_keys(brikpanel_pe_section_options()),
        brikpanel_pe_section_default_hidden()
    ));
}

/**
 * Resolve the user's full section order, including hidden ones.
 *
 * Falls back to default order seeded from any legacy multiselect value, then
 * appends any newly-introduced section slugs at the end so a plugin update
 * never silently drops them.
 *
 * @return string[]
 */
function brikpanel_pe_get_section_order() {
    $known  = array_keys(brikpanel_pe_section_options());
    $stored = get_option(BRIKPANEL_PE_SECTION_ORDER_OPTION, '');
    $order  = [];

    if (is_string($stored) && $stored !== '') {
        $decoded = json_decode($stored, true);
        if (is_array($decoded)) {
            foreach ($decoded as $slug) {
                if (is_string($slug) && in_array($slug, $known, true) && !in_array($slug, $order, true)) {
                    $order[] = $slug;
                }
            }
        }
    }

    if (empty($order)) {
        // Seed from any legacy multiselect value so existing installs do not
        // shuffle when this feature first ships.
        $legacy = get_option('brikpanel_pe_visible_sections');
        if (is_array($legacy)) {
            foreach ($legacy as $slug) {
                if (is_string($slug) && in_array($slug, $known, true) && !in_array($slug, $order, true)) {
                    $order[] = $slug;
                }
            }
        }
    }

    foreach ($known as $slug) {
        if (!in_array($slug, $order, true)) {
            $order[] = $slug;
        }
    }
    return $order;
}

/**
 * Resolve the visible section slugs in display order.
 *
 * @return string[]
 */
function brikpanel_pe_get_visible_sections_ordered() {
    $known       = array_keys(brikpanel_pe_section_options());
    $visible_raw = get_option('brikpanel_pe_visible_sections');
    if (!is_array($visible_raw)) {
        $visible_raw = brikpanel_pe_section_default_visible();
    }
    $visible_set = [];
    foreach ($visible_raw as $slug) {
        if (is_string($slug) && in_array($slug, $known, true)) {
            $visible_set[$slug] = true;
        }
    }
    $out = [];
    foreach (brikpanel_pe_get_section_order() as $slug) {
        if (!empty($visible_set[$slug])) {
            $out[] = $slug;
        }
    }
    return $out;
}

// =============================================================================
// SETTINGS PAGE: CUSTOM FIELD TYPE
// =============================================================================

/**
 * Render the section order field. Wired up via
 * `add_action('woocommerce_admin_field_brikpanel_section_order', ...)`.
 *
 * @param array $field The settings field definition.
 */
function brikpanel_render_section_order_field($field) {
    $options     = brikpanel_pe_section_options();
    $order       = brikpanel_pe_get_section_order();
    $visible     = brikpanel_pe_get_visible_sections_ordered();
    $visible_set = array_flip($visible);

    $title    = !empty($field['name']) ? esc_html($field['name']) : '';
    $tooltip  = !empty($field['desc_tip']) && !empty($field['desc']) ? wc_help_tip($field['desc']) : '';
    $help     = (empty($field['desc_tip']) && !empty($field['desc'])) ? $field['desc'] : '';
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <label><?php echo $title; ?></label>
            <?php echo $tooltip; ?>
        </th>
        <td class="forminp">
            <div class="brikpanel-section-order" id="brikpanel-section-order" data-default-order="<?php echo esc_attr(wp_json_encode(array_keys(brikpanel_pe_section_options()))); ?>" data-default-hidden="<?php echo esc_attr(wp_json_encode(array_values(brikpanel_pe_section_default_hidden()))); ?>">
                <?php if ($help !== '') : ?>
                    <p class="brikpanel-section-order-help"><?php echo esc_html($help); ?></p>
                <?php endif; ?>
                <ul class="brikpanel-section-order-list" role="list">
                    <?php foreach ($order as $slug) :
                        if (!isset($options[$slug])) continue;
                        $is_visible = isset($visible_set[$slug]);
                        ?>
                        <li class="brikpanel-section-order-row<?php echo $is_visible ? '' : ' is-hidden-section'; ?>" data-slug="<?php echo esc_attr($slug); ?>">
                            <label class="brikpanel-section-order-toggle">
                                <input type="checkbox" class="brikpanel-section-order-checkbox" name="brikpanel_pe_section_visibility[]" value="<?php echo esc_attr($slug); ?>" <?php checked($is_visible); ?>>
                                <span class="brikpanel-section-order-title"><?php echo esc_html($options[$slug]); ?></span>
                            </label>
                            <div class="brikpanel-section-order-actions">
                                <button type="button" class="brikpanel-section-order-btn brikpanel-section-order-up" aria-label="<?php esc_attr_e('Move up', 'brikpanel'); ?>" title="<?php esc_attr_e('Move up', 'brikpanel'); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="18 15 12 9 6 15"/></svg>
                                </button>
                                <button type="button" class="brikpanel-section-order-btn brikpanel-section-order-down" aria-label="<?php esc_attr_e('Move down', 'brikpanel'); ?>" title="<?php esc_attr_e('Move down', 'brikpanel'); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                                </button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="brikpanel-section-order-footer">
                    <button type="button" class="brikpanel-section-order-reset" id="brikpanel-section-order-reset">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                        <?php esc_html_e('Reset to default', 'brikpanel'); ?>
                    </button>
                </div>
                <input type="hidden" id="brikpanel_pe_section_order_json" name="brikpanel_pe_section_order_json" value="<?php echo esc_attr(wp_json_encode($order)); ?>">
            </div>
            <style>
            .brikpanel-section-order {
                max-width: 560px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            .brikpanel-section-order-help {
                margin: 0 0 .625rem;
                color: #616161;
                font-size: .8125rem;
                line-height: 1.5;
            }
            .brikpanel-section-order-list {
                margin: 0;
                padding: 0;
                list-style: none;
                background: #ffffff;
                border: 1px solid #e3e3e3;
                border-radius: .5rem;
                overflow: hidden;
            }
            .brikpanel-section-order-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: .5rem .75rem;
                border-top: 1px solid #f0f0f0;
                background: #ffffff;
                gap: .75rem;
                transition: background .15s ease;
            }
            .brikpanel-section-order-row:first-child {
                border-top: 0;
            }
            .brikpanel-section-order-row:hover {
                background: #fafafa;
            }
            .brikpanel-section-order-row.is-hidden-section .brikpanel-section-order-title {
                color: #8a8a8a;
                text-decoration: line-through;
                text-decoration-color: #c8c8c8;
            }
            .brikpanel-section-order-toggle {
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
            .brikpanel-section-order-checkbox {
                margin: 0 !important;
                width: 14px;
                height: 14px;
                border-radius: 3px;
                cursor: pointer;
                flex-shrink: 0;
            }
            .brikpanel-section-order-checkbox:focus {
                box-shadow: 0 0 0 2px rgba(48, 48, 48, .15);
            }
            .brikpanel-section-order-title {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .brikpanel-section-order-actions {
                display: flex;
                gap: 2px;
                flex-shrink: 0;
            }
            .brikpanel-section-order-btn {
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
            .brikpanel-section-order-btn:hover:not(:disabled) {
                background: #f4f4f4;
                color: #303030;
                border-color: #c8c8c8;
            }
            .brikpanel-section-order-btn:focus {
                outline: none;
                box-shadow: 0 0 0 2px rgba(48, 48, 48, .15);
            }
            .brikpanel-section-order-btn:disabled {
                opacity: .35;
                cursor: not-allowed;
            }
            .brikpanel-section-order-footer {
                display: flex;
                justify-content: flex-end;
                margin-top: .5rem;
            }
            .brikpanel-section-order-reset {
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
            .brikpanel-section-order-reset:hover {
                color: #303030;
                background: #f4f4f4;
            }
            .brikpanel-section-order-reset:focus {
                outline: none;
                box-shadow: 0 0 0 2px rgba(48, 48, 48, .15);
            }
            </style>
            <script>
            (function () {
                var root = document.getElementById('brikpanel-section-order');
                if (!root || root.dataset.bpInit === '1') return;
                root.dataset.bpInit = '1';

                var list   = root.querySelector('.brikpanel-section-order-list');
                var hidden = root.querySelector('#brikpanel_pe_section_order_json');
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

                var resetBtn = root.querySelector('#brikpanel-section-order-reset');
                if (resetBtn) {
                    resetBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        var defaults;
                        try { defaults = JSON.parse(root.dataset.defaultOrder || '[]'); }
                        catch (err) { defaults = []; }
                        if (!Array.isArray(defaults) || defaults.length === 0) return;
                        var defaultHidden;
                        try { defaultHidden = JSON.parse(root.dataset.defaultHidden || '[]'); }
                        catch (err2) { defaultHidden = []; }
                        if (!Array.isArray(defaultHidden)) defaultHidden = [];
                        var rowMap = {};
                        list.querySelectorAll('.brikpanel-section-order-row').forEach(function (row) {
                            rowMap[row.getAttribute('data-slug')] = row;
                        });
                        defaults.forEach(function (slug) {
                            var row = rowMap[slug];
                            if (!row) return;
                            list.appendChild(row);
                            var visible = defaultHidden.indexOf(slug) === -1;
                            var cb = row.querySelector('.brikpanel-section-order-checkbox');
                            if (cb) cb.checked = visible;
                            row.classList.toggle('is-hidden-section', !visible);
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
add_action('woocommerce_admin_field_brikpanel_section_order', 'brikpanel_render_section_order_field');

// =============================================================================
// SETTINGS PAGE: SAVE
// =============================================================================

/**
 * Persist the section order + visibility when the BrikPanel WC settings tab
 * is submitted. Runs at priority 11 — after the default
 * `woocommerce_update_options()` pass so its sibling option writes do not
 * race with this one.
 */
add_action('woocommerce_update_options_brikpanel', function () {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    if (!isset($_POST['brikpanel_pe_section_order_json'])) {
        return;
    }
    $known = array_keys(brikpanel_pe_section_options());

    $raw     = wp_unslash($_POST['brikpanel_pe_section_order_json']);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return;
    }
    $order = [];
    foreach ($decoded as $slug) {
        if (is_string($slug) && in_array($slug, $known, true) && !in_array($slug, $order, true)) {
            $order[] = $slug;
        }
    }
    foreach ($known as $slug) {
        if (!in_array($slug, $order, true)) {
            $order[] = $slug;
        }
    }
    update_option(BRIKPANEL_PE_SECTION_ORDER_OPTION, wp_json_encode($order), false);

    $visibility_raw = isset($_POST['brikpanel_pe_section_visibility']) && is_array($_POST['brikpanel_pe_section_visibility'])
        ? wp_unslash($_POST['brikpanel_pe_section_visibility'])
        : [];
    $visible_set = [];
    foreach ($visibility_raw as $slug) {
        if (is_string($slug) && in_array($slug, $known, true)) {
            $visible_set[$slug] = true;
        }
    }
    $visible = [];
    foreach ($order as $slug) {
        if (!empty($visible_set[$slug])) {
            $visible[] = $slug;
        }
    }
    update_option('brikpanel_pe_visible_sections', $visible, false);
}, 11);

// =============================================================================
// ONE-TIME MIGRATION: backfill new section slugs into existing installs
// =============================================================================

/**
 * Some sections (currently: `cogs`) were always rendered before they became
 * user-toggleable. Existing installs already have a saved visible/order pair
 * that pre-dates the toggle, so a fresh upgrade would either hide the section
 * outright (visibility array missing the slug) or render it at the wrong
 * position (order array missing the slug → appended to the end by the
 * resolver). Both are silent breakage.
 *
 * We backfill once per slug, keyed on a per-slug option flag so future
 * additions can reuse the same pattern without re-running old backfills.
 * Each entry declares the slug + the anchor slug it should appear after when
 * the saved order does not contain it yet.
 */
add_action('admin_init', function () {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $backfills = [
        'cogs' => 'pricing',
    ];
    $known = array_keys(brikpanel_pe_section_options());
    foreach ($backfills as $slug => $anchor) {
        $flag = 'brikpanel_pe_section_backfilled_' . $slug;
        if (get_option($flag) === 'yes') {
            continue;
        }
        if (!in_array($slug, $known, true)) {
            continue;
        }
        // Visibility: only mutate when the option already exists (existing
        // install). Brand-new installs fall through to the default-visible
        // resolver and don't need a backfill.
        $visible = get_option('brikpanel_pe_visible_sections');
        if (is_array($visible) && !in_array($slug, $visible, true)) {
            $visible[] = $slug;
            update_option('brikpanel_pe_visible_sections', array_values($visible), false);
        }
        // Order: insert right after the anchor so the card stays where
        // existing users expect to find it. JSON-encoded list of slugs.
        $order_raw = get_option(BRIKPANEL_PE_SECTION_ORDER_OPTION, '');
        if (is_string($order_raw) && $order_raw !== '') {
            $decoded = json_decode($order_raw, true);
            if (is_array($decoded) && !in_array($slug, $decoded, true)) {
                $idx = array_search($anchor, $decoded, true);
                if ($idx !== false) {
                    array_splice($decoded, $idx + 1, 0, $slug);
                } else {
                    $decoded[] = $slug;
                }
                update_option(BRIKPANEL_PE_SECTION_ORDER_OPTION, wp_json_encode(array_values($decoded)), false);
            }
        }
        update_option($flag, 'yes', false);
    }
});
