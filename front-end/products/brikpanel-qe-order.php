<?php
/**
 * Quick Edit drawer field order + visibility picker.
 *
 * Mirrors the simplified product editor's section-order picker
 * (see front-end/products/brikpanel-section-order.php). The admin reorders
 * and toggles individual quick-edit fields from one card; the resolver
 * returns the visible slugs in display order for the drawer renderer.
 *
 * Two options are persisted:
 *   - brikpanel_qe_field_order: a JSON-encoded ordered list of every known
 *     slug (visible + hidden). Preserves a hidden field's position so it
 *     returns to its slot when the admin re-enables it.
 *   - brikpanel_qe_visible_fields: a flat array of currently-visible slugs
 *     in display order. Kept separate from the order option so the resolver
 *     can answer "is X visible?" in O(1) without parsing JSON.
 *
 * Legacy options migrated on first load (one-shot, flagged):
 *   - brikpanel_qe_custom_taxonomies → controls `custom_taxonomies` visibility
 *   - brikpanel_show_featured_star   → controls `featured` visibility
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('BRIKPANEL_QE_ORDER_OPTION')) {
    define('BRIKPANEL_QE_ORDER_OPTION', 'brikpanel_qe_field_order');
}
if (!defined('BRIKPANEL_QE_VISIBLE_OPTION')) {
    define('BRIKPANEL_QE_VISIBLE_OPTION', 'brikpanel_qe_visible_fields');
}

// =============================================================================
// HELPERS
// =============================================================================

/**
 * Canonical slug → label map for every field the quick-edit drawer knows
 * how to render. The order of keys here is the factory-default display order.
 *
 * @return array<string,string>
 */
function brikpanel_qe_field_options() {
    return [
        'name'              => __('Product name', 'brikpanel'),
        'pricing'           => __('Pricing (regular & sale)', 'brikpanel'),
        'cogs'              => __('Cost of goods (COGS)', 'brikpanel'),
        'inventory'         => __('Inventory (stock & SKU)', 'brikpanel'),
        'stock_status'      => __('Availability (stock status)', 'brikpanel'),
        'digital'           => __('Virtual / Digital product (downloadable)', 'brikpanel'),
        'status'            => __('Publish status', 'brikpanel'),
        'categories'        => __('Categories', 'brikpanel'),
        'tags'              => __('Tags', 'brikpanel'),
        'custom_taxonomies' => __('Custom taxonomies (Brands, etc.)', 'brikpanel'),
        'featured'          => __('Featured product star', 'brikpanel'),
    ];
}

/**
 * Fields that only apply to simple products. When a variable product is
 * opened in the drawer these slugs are hidden client-side and the variation
 * editor takes their place. Reorder still works because the JS toggle is
 * per-field, not on a parent wrapper.
 *
 * @return string[]
 */
function brikpanel_qe_simple_only_fields() {
    // `cogs` is intentionally NOT here: for variable products the COGS
    // control renders as a per-variation column inside the variations
    // editor instead of a parent-level field. The variations renderer
    // gates that column on the same `cogs` slug visibility.
    return ['pricing', 'cogs', 'inventory', 'stock_status', 'digital'];
}

/**
 * Fields that live in the drawer header (not the body). Currently just
 * `featured` — the star button next to the "Quick edit" title.
 *
 * @return string[]
 */
function brikpanel_qe_header_fields() {
    return ['featured'];
}

/**
 * Fields hidden out of the box. The two legacy opt-in options
 * (custom taxonomies, featured star) keep the same default — admins
 * opt in from the new panel exactly as they did before.
 *
 * @return string[]
 */
function brikpanel_qe_field_default_hidden() {
    return ['tags', 'custom_taxonomies', 'featured'];
}

/**
 * Factory-default visible set (everything except the opt-in fields).
 *
 * @return string[]
 */
function brikpanel_qe_field_default_visible() {
    return array_values(array_diff(
        array_keys(brikpanel_qe_field_options()),
        brikpanel_qe_field_default_hidden()
    ));
}

/**
 * Resolve the user's full field order, including hidden ones.
 *
 * Newly-introduced slugs are appended at the end so a plugin update never
 * silently drops them. Mirrors brikpanel_pe_get_section_order().
 *
 * @return string[]
 */
function brikpanel_qe_get_field_order() {
    $known  = array_keys(brikpanel_qe_field_options());
    $stored = get_option(BRIKPANEL_QE_ORDER_OPTION, '');
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

    foreach ($known as $slug) {
        if (!in_array($slug, $order, true)) {
            $order[] = $slug;
        }
    }
    return $order;
}

/**
 * Resolve the visible field slugs in display order.
 *
 * @return string[]
 */
function brikpanel_qe_get_visible_fields_ordered() {
    $known       = array_keys(brikpanel_qe_field_options());
    $visible_raw = get_option(BRIKPANEL_QE_VISIBLE_OPTION, null);
    if (!is_array($visible_raw)) {
        $visible_raw = brikpanel_qe_field_default_visible();
    }
    $visible_set = [];
    foreach ($visible_raw as $slug) {
        if (is_string($slug) && in_array($slug, $known, true)) {
            $visible_set[$slug] = true;
        }
    }
    $out = [];
    foreach (brikpanel_qe_get_field_order() as $slug) {
        if (!empty($visible_set[$slug])) {
            $out[] = $slug;
        }
    }
    return $out;
}

/**
 * O(1) lookup: is the given quick-edit field currently visible?
 * Cached per-request to avoid re-parsing options on every renderer that
 * needs to ask (drawer, products list star, editor star).
 *
 * @param string $slug
 * @return bool
 */
function brikpanel_qe_is_field_visible($slug) {
    static $cache = null;
    if ($cache === null) {
        $cache = array_flip(brikpanel_qe_get_visible_fields_ordered());
    }
    return isset($cache[$slug]);
}

// =============================================================================
// SETTINGS PAGE: CUSTOM FIELD TYPE
// =============================================================================

/**
 * Render the quick-edit field order field. Wired up via
 * `add_action('woocommerce_admin_field_brikpanel_qe_field_order', ...)`.
 *
 * @param array $field The settings field definition.
 */
function brikpanel_render_qe_field_order_field($field) {
    $options       = brikpanel_qe_field_options();
    $order         = brikpanel_qe_get_field_order();
    $visible       = brikpanel_qe_get_visible_fields_ordered();
    $visible_set   = array_flip($visible);
    $simple_only   = array_flip(brikpanel_qe_simple_only_fields());
    $header_fields = array_flip(brikpanel_qe_header_fields());

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
            <div class="brikpanel-qe-order" id="brikpanel-qe-order" data-default-order="<?php echo esc_attr(wp_json_encode(array_keys(brikpanel_qe_field_options()))); ?>" data-default-hidden="<?php echo esc_attr(wp_json_encode(array_values(brikpanel_qe_field_default_hidden()))); ?>">
                <?php if ($help !== '') : ?>
                    <p class="brikpanel-qe-order-help"><?php echo esc_html($help); ?></p>
                <?php endif; ?>
                <ul class="brikpanel-qe-order-list" role="list">
                    <?php foreach ($order as $slug) :
                        if (!isset($options[$slug])) continue;
                        $is_visible = isset($visible_set[$slug]);
                        $tags_html  = '';
                        if (isset($simple_only[$slug])) {
                            $tags_html .= '<span class="brikpanel-qe-order-tag" title="' . esc_attr__('Only shown when editing a simple product. Variable products show the variation editor instead.', 'brikpanel') . '">' . esc_html__('Simple only', 'brikpanel') . '</span>';
                        }
                        if (isset($header_fields[$slug])) {
                            $tags_html .= '<span class="brikpanel-qe-order-tag" title="' . esc_attr__('Renders in the drawer header next to the title (and matching icons in the products list and editor).', 'brikpanel') . '">' . esc_html__('Header', 'brikpanel') . '</span>';
                        }
                        ?>
                        <li class="brikpanel-qe-order-row<?php echo $is_visible ? '' : ' is-hidden-section'; ?>" data-slug="<?php echo esc_attr($slug); ?>">
                            <label class="brikpanel-qe-order-toggle">
                                <input type="checkbox" class="brikpanel-qe-order-checkbox" name="brikpanel_qe_field_visibility[]" value="<?php echo esc_attr($slug); ?>" <?php checked($is_visible); ?>>
                                <span class="brikpanel-qe-order-title"><?php echo esc_html($options[$slug]); ?></span>
                                <?php if ($tags_html !== '') : ?>
                                    <span class="brikpanel-qe-order-tags"><?php echo $tags_html; ?></span>
                                <?php endif; ?>
                            </label>
                            <div class="brikpanel-qe-order-actions">
                                <button type="button" class="brikpanel-qe-order-btn brikpanel-qe-order-up" aria-label="<?php esc_attr_e('Move up', 'brikpanel'); ?>" title="<?php esc_attr_e('Move up', 'brikpanel'); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="18 15 12 9 6 15"/></svg>
                                </button>
                                <button type="button" class="brikpanel-qe-order-btn brikpanel-qe-order-down" aria-label="<?php esc_attr_e('Move down', 'brikpanel'); ?>" title="<?php esc_attr_e('Move down', 'brikpanel'); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                                </button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="brikpanel-qe-order-footer">
                    <button type="button" class="brikpanel-qe-order-reset" id="brikpanel-qe-order-reset">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                        <?php esc_html_e('Reset to default', 'brikpanel'); ?>
                    </button>
                </div>
                <input type="hidden" id="brikpanel_qe_field_order_json" name="brikpanel_qe_field_order_json" value="<?php echo esc_attr(wp_json_encode($order)); ?>">
            </div>
            <style>
            .brikpanel-qe-order {
                max-width: 560px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            .brikpanel-qe-order-help {
                margin: 0 0 .625rem;
                color: #616161;
                font-size: .8125rem;
                line-height: 1.5;
            }
            .brikpanel-qe-order-list {
                margin: 0;
                padding: 0;
                list-style: none;
                background: #ffffff;
                border: 1px solid #e3e3e3;
                border-radius: .5rem;
                overflow: hidden;
            }
            .brikpanel-qe-order-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: .5rem .75rem;
                border-top: 1px solid #f0f0f0;
                background: #ffffff;
                gap: .75rem;
                transition: background .15s ease;
            }
            .brikpanel-qe-order-row:first-child {
                border-top: 0;
            }
            .brikpanel-qe-order-row:hover {
                background: #fafafa;
            }
            .brikpanel-qe-order-row.is-hidden-section .brikpanel-qe-order-title {
                color: #8a8a8a;
                text-decoration: line-through;
                text-decoration-color: #c8c8c8;
            }
            .brikpanel-qe-order-toggle {
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
            .brikpanel-qe-order-checkbox {
                margin: 0 !important;
                width: 14px;
                height: 14px;
                border-radius: 3px;
                cursor: pointer;
                flex-shrink: 0;
            }
            .brikpanel-qe-order-checkbox:focus {
                box-shadow: 0 0 0 2px rgba(48, 48, 48, .15);
            }
            .brikpanel-qe-order-title {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .brikpanel-qe-order-tags {
                display: inline-flex;
                gap: .25rem;
                margin-left: auto;
                padding-right: .375rem;
                flex-shrink: 0;
            }
            .brikpanel-qe-order-tag {
                display: inline-block;
                padding: 1px 6px;
                background: #f1f1f1;
                color: #616161;
                border-radius: 999px;
                font-size: .6875rem;
                font-weight: 550;
                line-height: 1.4;
                white-space: nowrap;
                cursor: help;
            }
            .brikpanel-qe-order-row.is-hidden-section .brikpanel-qe-order-tag {
                opacity: .55;
            }
            .brikpanel-qe-order-actions {
                display: flex;
                gap: 2px;
                flex-shrink: 0;
            }
            .brikpanel-qe-order-btn {
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
            .brikpanel-qe-order-btn:hover:not(:disabled) {
                background: #f4f4f4;
                color: #303030;
                border-color: #c8c8c8;
            }
            .brikpanel-qe-order-btn:focus {
                outline: none;
                box-shadow: 0 0 0 2px rgba(48, 48, 48, .15);
            }
            .brikpanel-qe-order-btn:disabled {
                opacity: .35;
                cursor: not-allowed;
            }
            .brikpanel-qe-order-footer {
                display: flex;
                justify-content: flex-end;
                margin-top: .5rem;
            }
            .brikpanel-qe-order-reset {
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
            .brikpanel-qe-order-reset:hover {
                color: #303030;
                background: #f4f4f4;
            }
            .brikpanel-qe-order-reset:focus {
                outline: none;
                box-shadow: 0 0 0 2px rgba(48, 48, 48, .15);
            }
            </style>
            <script>
            (function () {
                var root = document.getElementById('brikpanel-qe-order');
                if (!root || root.dataset.bpInit === '1') return;
                root.dataset.bpInit = '1';

                var list   = root.querySelector('.brikpanel-qe-order-list');
                var hidden = root.querySelector('#brikpanel_qe_field_order_json');
                if (!list || !hidden) return;

                function refresh() {
                    var rows = list.querySelectorAll('.brikpanel-qe-order-row');
                    var order = [];
                    rows.forEach(function (row, idx) {
                        order.push(row.getAttribute('data-slug'));
                        var up   = row.querySelector('.brikpanel-qe-order-up');
                        var down = row.querySelector('.brikpanel-qe-order-down');
                        if (up)   up.disabled   = idx === 0;
                        if (down) down.disabled = idx === rows.length - 1;
                    });
                    hidden.value = JSON.stringify(order);
                }

                list.addEventListener('click', function (e) {
                    var btn = e.target.closest('.brikpanel-qe-order-up, .brikpanel-qe-order-down');
                    if (!btn) return;
                    var row = btn.closest('.brikpanel-qe-order-row');
                    if (!row) return;
                    e.preventDefault();
                    if (btn.classList.contains('brikpanel-qe-order-up')) {
                        var prev = row.previousElementSibling;
                        if (prev) list.insertBefore(row, prev);
                    } else {
                        var next = row.nextElementSibling;
                        if (next) list.insertBefore(next, row);
                    }
                    refresh();
                });

                list.addEventListener('change', function (e) {
                    var cb = e.target.closest('.brikpanel-qe-order-checkbox');
                    if (!cb) return;
                    var row = cb.closest('.brikpanel-qe-order-row');
                    if (!row) return;
                    row.classList.toggle('is-hidden-section', !cb.checked);
                });

                var resetBtn = root.querySelector('#brikpanel-qe-order-reset');
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
                        list.querySelectorAll('.brikpanel-qe-order-row').forEach(function (row) {
                            rowMap[row.getAttribute('data-slug')] = row;
                        });
                        defaults.forEach(function (slug) {
                            var row = rowMap[slug];
                            if (!row) return;
                            list.appendChild(row);
                            var visible = defaultHidden.indexOf(slug) === -1;
                            var cb = row.querySelector('.brikpanel-qe-order-checkbox');
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
add_action('woocommerce_admin_field_brikpanel_qe_field_order', 'brikpanel_render_qe_field_order_field');

// =============================================================================
// SETTINGS PAGE: SAVE
// =============================================================================

/**
 * Persist the field order + visibility when the BrikPanel WC settings tab
 * is submitted. Priority 11 so it runs after the default
 * woocommerce_update_options() pass.
 */
add_action('woocommerce_update_options_brikpanel', function () {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    if (!isset($_POST['brikpanel_qe_field_order_json'])) {
        return;
    }
    $known = array_keys(brikpanel_qe_field_options());

    $raw     = wp_unslash($_POST['brikpanel_qe_field_order_json']);
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
    update_option(BRIKPANEL_QE_ORDER_OPTION, wp_json_encode($order), false);

    $visibility_raw = isset($_POST['brikpanel_qe_field_visibility']) && is_array($_POST['brikpanel_qe_field_visibility'])
        ? wp_unslash($_POST['brikpanel_qe_field_visibility'])
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
    update_option(BRIKPANEL_QE_VISIBLE_OPTION, $visible, false);

    // Keep the two legacy boolean options in lockstep so any code path that
    // still reads them (third-party integrations, snapshot tools) sees the
    // same answer as brikpanel_qe_is_field_visible(). We treat the new
    // visibility list as the source of truth.
    update_option('brikpanel_qe_custom_taxonomies', in_array('custom_taxonomies', $visible, true) ? 'yes' : 'no');
    update_option('brikpanel_show_featured_star', in_array('featured', $visible, true) ? 'yes' : 'no');
}, 11);

// =============================================================================
// ONE-TIME MIGRATION
// =============================================================================

/**
 * Seed the new visibility list from the two legacy boolean options the
 * first time an admin loads wp-admin after this module ships. Runs once
 * per site (flag option).
 *
 * The new option's *absence* already maps to the default visible set, so
 * fresh installs need no migration. We only mutate when at least one of
 * the legacy options was explicitly opted in, otherwise the defaults
 * resolver returns the correct answer on its own.
 */
add_action('admin_init', function () {
    if (get_option('brikpanel_qe_field_order_migrated_v1') === 'yes') {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    // If the admin already has a saved visibility list, the migration is
    // already done (or was bypassed by direct option writes). Mark and exit.
    $existing = get_option(BRIKPANEL_QE_VISIBLE_OPTION, null);
    if (is_array($existing)) {
        update_option('brikpanel_qe_field_order_migrated_v1', 'yes', false);
        return;
    }

    $custom_tax_on = get_option('brikpanel_qe_custom_taxonomies', 'no') === 'yes';
    $featured_on   = get_option('brikpanel_show_featured_star', 'no') === 'yes';

    // Both legacy options are off → no opt-ins to preserve; the default
    // resolver will return the right answer. Just flag & exit.
    if (!$custom_tax_on && !$featured_on) {
        update_option('brikpanel_qe_field_order_migrated_v1', 'yes', false);
        return;
    }

    $visible = brikpanel_qe_field_default_visible();
    if ($custom_tax_on && !in_array('custom_taxonomies', $visible, true)) {
        $visible[] = 'custom_taxonomies';
    }
    if ($featured_on && !in_array('featured', $visible, true)) {
        $visible[] = 'featured';
    }
    // Re-project into canonical order.
    $known   = array_keys(brikpanel_qe_field_options());
    $set     = array_flip($visible);
    $visible = [];
    foreach ($known as $slug) {
        if (isset($set[$slug])) {
            $visible[] = $slug;
        }
    }

    update_option(BRIKPANEL_QE_VISIBLE_OPTION, $visible, false);
    update_option('brikpanel_qe_field_order_migrated_v1', 'yes', false);
});

/**
 * Per-slug backfill for newly introduced quick-edit fields. When a plugin
 * release adds a new slug whose factory default is "visible", existing
 * installs already have a saved visibility list that pre-dates the slug —
 * a fresh upgrade would silently render the new field as hidden, which
 * users read as "the feature is broken." We backfill once per slug, keyed
 * on a per-slug option flag so future additions reuse the same pattern.
 *
 * Each entry declares the slug + the anchor slug it should appear after
 * in the saved order when the array does not contain it yet (preserves
 * any reordering the admin has done elsewhere).
 */
add_action('admin_init', function () {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $backfills = [
        // `cogs` (Cost of goods) — introduced after the QE field-order
        // settings module shipped. Default-visible, anchored after pricing
        // so it lands next to the price card.
        'cogs' => 'pricing',
    ];
    $known = array_keys(brikpanel_qe_field_options());
    foreach ($backfills as $slug => $anchor) {
        $flag = 'brikpanel_qe_field_backfilled_' . $slug;
        if (get_option($flag) === 'yes') {
            continue;
        }
        if (!in_array($slug, $known, true)) {
            continue;
        }

        // Visibility list — only mutate when the option already exists.
        // Brand-new installs fall through to the default-visible resolver
        // and don't need a backfill.
        $visible = get_option(BRIKPANEL_QE_VISIBLE_OPTION);
        if (is_array($visible) && !in_array($slug, $visible, true)) {
            $visible[] = $slug;
            update_option(BRIKPANEL_QE_VISIBLE_OPTION, array_values($visible), false);
        }

        // Order — insert right after the anchor so the field lands in the
        // expected slot. JSON-encoded list of slugs.
        $order_raw = get_option(BRIKPANEL_QE_ORDER_OPTION, '');
        if (is_string($order_raw) && $order_raw !== '') {
            $decoded = json_decode($order_raw, true);
            if (is_array($decoded) && !in_array($slug, $decoded, true)) {
                $idx = array_search($anchor, $decoded, true);
                if ($idx !== false) {
                    array_splice($decoded, $idx + 1, 0, $slug);
                } else {
                    $decoded[] = $slug;
                }
                update_option(BRIKPANEL_QE_ORDER_OPTION, wp_json_encode(array_values($decoded)), false);
            }
        }

        update_option($flag, 'yes', false);
    }
});
