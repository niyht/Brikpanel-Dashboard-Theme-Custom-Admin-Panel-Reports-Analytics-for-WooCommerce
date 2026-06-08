<?php
/**
 * BrikPanel - AJAX Products List
 *
 * Replaces the default WooCommerce products list with a modern,
 * fully AJAX-powered interface with inline editing.
 *
 * @package BrikPanel
 * @since 1.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Brikpanel_Products_List {

    /**
     * Holds the active search term while the posts_search filter is attached.
     * Empty string means the SKU search clause must not be injected.
     */
    private static $sku_search_term = '';

    public function __construct() {
        if (get_option('brikpanel_modern_products_list', 'yes') !== 'yes') {
            return;
        }

        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_init', [$this, 'redirect_default_list']);

        // AJAX endpoints
        add_action('wp_ajax_brikpanel_fetch_products', [$this, 'ajax_fetch_products']);
        add_action('wp_ajax_brikpanel_quick_edit_product', [$this, 'ajax_quick_edit']);
        add_action('wp_ajax_brikpanel_bulk_action_products', [$this, 'ajax_bulk_action']);
        add_action('wp_ajax_brikpanel_delete_product', [$this, 'ajax_delete_product']);
        add_action('wp_ajax_brikpanel_toggle_status', [$this, 'ajax_toggle_status']);
        add_action('wp_ajax_brikpanel_toggle_featured', [$this, 'ajax_toggle_featured']);
        add_action('wp_ajax_brikpanel_get_variation_attributes', [$this, 'ajax_get_variation_attributes']);
        add_action('wp_ajax_brikpanel_get_variations', [$this, 'ajax_get_variations']);
        add_action('wp_ajax_brikpanel_save_variation', [$this, 'ajax_save_variation']);
        add_action('wp_ajax_brikpanel_bulk_job_prepare', [$this, 'ajax_bulk_job_prepare']);
        add_action('wp_ajax_brikpanel_bulk_job_process', [$this, 'ajax_bulk_job_process']);
        add_action('wp_ajax_brikpanel_bulk_job_cancel',  [$this, 'ajax_bulk_job_cancel']);
        add_action('wp_ajax_brikpanel_pl_save_columns',  [$this, 'ajax_save_columns']);
        add_action('wp_ajax_brikpanel_save_product_order', [$this, 'ajax_save_product_order']);

        // Export selected products as CSV
        add_action('admin_post_brikpanel_export_selected_products', [$this, 'handle_export_selected']);
    }

    // =========================================================================
    // COLUMN VISIBILITY (per-user preference)
    // =========================================================================

    const USER_COLUMNS_META = 'brikpanel_products_visible_columns';

    /**
     * Ordered column definition consumed by the "Columns" dropdown and the
     * table renderer. Keep keys stable — they are persisted per-user via
     * user_meta. The `checkbox` and `actions` columns are intentionally
     * omitted; they are structural and always visible.
     */
    public static function get_column_defs() {
        $defs = [
            'image'    => ['label' => __('Image', 'brikpanel'),    'default' => true],
            'name'     => ['label' => __('Product', 'brikpanel'),  'default' => true, 'locked' => true],
            'sku'      => ['label' => __('SKU', 'brikpanel'),      'default' => true],
            'global_unique_id' => ['label' => __('GTIN', 'brikpanel'), 'default' => false],
            'price'    => ['label' => __('Price', 'brikpanel'),    'default' => true],
            'cogs'     => ['label' => __('Cost', 'brikpanel'),     'default' => false],
            'stock'    => ['label' => __('Stock', 'brikpanel'),    'default' => true],
            'category' => ['label' => __('Category', 'brikpanel'), 'default' => true],
            'status'   => ['label' => __('Status', 'brikpanel'),   'default' => true],
        ];

        // Surface columns contributed by SEO / 3rd-party plugins (Yoast, Rank
        // Math, ASE, etc.) in the Columns dropdown so they can be toggled.
        // Keys are the original column IDs from the manage_{post_type}_posts_columns
        // filter; collisions with native keys keep the native definition.
        //
        // Restricted to AJAX context: many plugins register their column
        // callbacks only during edit.php / wp_doing_ajax(), and replaying
        // those filters on a non-edit admin page can trigger PHP notices
        // from callbacks that assume an edit-screen baseline. The Columns
        // dropdown picks up extras dynamically from the AJAX response on
        // the JS side, so omitting them here costs nothing.
        //
        // Default OFF: newly-installed plugins should not silently widen the
        // table without consent — users opt them in from the Columns popover.
        if (class_exists('Brikpanel_ASE_Bridge') && wp_doing_ajax()) {
            $extras = Brikpanel_ASE_Bridge::get_extra_columns('product');
            foreach ($extras as $col_id => $label) {
                if (isset($defs[$col_id])) {
                    continue;
                }
                $defs[$col_id] = [
                    'label'   => ($label !== '' ? $label : $col_id),
                    'default' => false,
                    'extra'   => true,
                ];
            }
        }

        return apply_filters('brikpanel_products_columns', $defs, get_current_user_id());
    }

    /**
     * Returns a map of column_id → bool for the current user, filling in
     * defaults for any column not explicitly set. Locked columns are
     * always forced to visible.
     */
    public static function get_user_columns($user_id = 0) {
        if (!$user_id) $user_id = get_current_user_id();
        $defs  = self::get_column_defs();
        $saved = get_user_meta($user_id, self::USER_COLUMNS_META, true);
        if (!is_array($saved)) $saved = [];
        $out = [];
        foreach ($defs as $id => $def) {
            if (!empty($def['locked'])) {
                $out[$id] = true;
                continue;
            }
            $out[$id] = array_key_exists($id, $saved)
                ? (bool) $saved[$id]
                : !empty($def['default']);
        }
        return $out;
    }

    public function ajax_save_columns() {
        check_ajax_referer('brikpanel_products_list_nonce', 'security');
        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')], 403);
        }
        $raw = isset($_POST['columns']) && is_array($_POST['columns']) ? $_POST['columns'] : [];
        $defs = self::get_column_defs();
        $clean = [];
        foreach ($defs as $id => $def) {
            if (!empty($def['locked'])) continue;
            $clean[$id] = !empty($raw[$id]) && $raw[$id] !== 'false' && $raw[$id] !== '0';
        }
        update_user_meta(get_current_user_id(), self::USER_COLUMNS_META, $clean);
        wp_send_json_success(['columns' => self::get_user_columns()]);
    }

    // Conservative defaults sized for shared hosting (low memory_limit and
    // short max_execution_time). Filterable per-job via brikpanel_bulk_batch_size.
    // Update touches WooCommerce object cache + variations per product, so it
    // is the heaviest path; fast-delete uses raw SQL so can run wider batches.
    const BULK_BATCH_UPDATE      = 20;
    const BULK_BATCH_DELETE      = 20;
    const BULK_BATCH_DELETE_FAST = 100;
    const BULK_MAX_IDS           = 50000;
    const BULK_JOB_TRANSIENT     = 'brikpanel_bulk_job_';
    const BULK_JOB_TTL           = HOUR_IN_SECONDS;

    // =========================================================================
    // PAGE REGISTRATION & REDIRECT
    // =========================================================================

    public function register_page() {
        $hook = add_submenu_page(
            '',
            __('Products', 'brikpanel'),
            '',
            'edit_products',
            'brikpanel-products',
            [$this, 'render_page']
        );

        if ($hook) {
            add_action('load-' . $hook, function () {
                global $title;
                $title = __('Products', 'brikpanel');
            });
        }
    }

    public function redirect_default_list() {
        global $pagenow;

        if (!current_user_can('edit_products')) {
            return;
        }

        if ($pagenow === 'edit.php' && isset($_GET['post_type']) && sanitize_key($_GET['post_type']) === 'product') {
            // Don't redirect if it's a specific sub-action, sub-page, or taxonomy page
            if (!empty($_GET['action']) || !empty($_GET['action2']) || !empty($_GET['page']) || !empty($_GET['taxonomy'])) {
                return;
            }
            wp_safe_redirect(admin_url('admin.php?page=brikpanel-products'));
            exit;
        }
    }

    // =========================================================================
    // STOCK HELPERS
    // =========================================================================

    /**
     * Aggregate stock information consumed by the list and quick-edit
     * payloads. Handles simple + variable products uniformly so the JS
     * layer can decide between a quantity badge and a status badge without
     * knowing product-type specifics.
     */
    private static function compute_stock_info($product) {
        $qty          = null;
        $manage_stock = false;
        $backorders   = false;

        if ($product->is_type('variable')) {
            $total = 0;
            foreach ($product->get_children() as $cid) {
                $v = wc_get_product($cid);
                if (!$v) continue;
                if ($v->get_manage_stock()) {
                    $manage_stock = true;
                    $total += (int) $v->get_stock_quantity();
                }
                if ($v->backorders_allowed()) {
                    $backorders = true;
                }
            }
            $qty = $manage_stock ? $total : null;
        } else {
            $manage_stock = (bool) $product->get_manage_stock();
            $qty          = $manage_stock ? $product->get_stock_quantity() : null;
            $backorders   = (bool) $product->backorders_allowed();
        }

        return [
            'qty'          => $qty,
            'manage_stock' => $manage_stock,
            'backorders'   => $backorders,
        ];
    }

    /**
     * Build the per-row Cost of goods cell payload. Mirrors the cost
     * resolution used by the dashboard profit math so the column never
     * disagrees with Net profit's view of the same product:
     *   - simple → its own `_brikpanel_cogs` meta
     *   - variable → range across variations that have a cost. Variations
     *     with no cost on file inherit the parent's value (WC native COGS
     *     behaviour); when neither side has a cost the row is blank.
     *
     * Returns a `partial` flag when some variations are missing a cost so
     * the JS can render a quiet inline warning marker without us hard-coding
     * markup down here.
     *
     * @param WC_Product $product
     * @return array{value:string,html:string,partial:bool,missing:int,total:int}
     */
    private static function compute_cogs_display($product) {
        global $wpdb;
        $muted = '<span class="brikpanel-pl-text-muted">&mdash;</span>';
        $blank = [
            'value'   => '',
            'html'    => $muted,
            'partial' => false,
            'missing' => 0,
            'total'   => 0,
        ];

        if ($product->is_type('variable')) {
            $children = $product->get_children();
            $total    = count($children);
            if ($total === 0) {
                return $blank;
            }
            $placeholders = implode(',', array_fill(0, $total, '%d'));
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key = '_brikpanel_cogs' AND post_id IN ($placeholders)",
                    ...$children
                )
            );
            // phpcs:enable
            $by_id = [];
            foreach ((array) $rows as $r) {
                $by_id[(int) $r->post_id] = (string) $r->meta_value;
            }
            // "Set" means the meta row exists. Explicit 0 (free sample, comp
            // item, digital good with no per-unit cost) is a deliberate
            // answer from the merchant, not an absence — count it as set.
            $parent_meta = (string) get_post_meta($product->get_id(), '_brikpanel_cogs', true);
            $parent_val  = $parent_meta !== '' ? (float) $parent_meta : null;

            $values  = [];
            $missing = 0;
            foreach ($children as $cid) {
                $raw = $by_id[(int) $cid] ?? '';
                if ($raw !== '') {
                    $values[] = (float) $raw;
                } elseif ($parent_val !== null) {
                    $values[] = $parent_val;
                } else {
                    $missing++;
                }
            }
            if (empty($values)) {
                return $blank;
            }
            $min  = min($values);
            $max  = max($values);
            $html = ($min === $max) ? wc_price($min) : (wc_price($min) . ' &ndash; ' . wc_price($max));
            return [
                'value'   => (string) $min,
                'html'    => $html,
                'partial' => $missing > 0,
                'missing' => $missing,
                'total'   => $total,
            ];
        }

        $raw = (string) get_post_meta($product->get_id(), '_brikpanel_cogs', true);
        if ($raw === '') {
            return $blank;
        }
        return [
            'value'   => $raw,
            'html'    => wc_price($raw),
            'partial' => false,
            'missing' => 0,
            'total'   => 1,
        ];
    }

    /**
     * Builds the Global Unique ID (GTIN/UPC/EAN/ISBN) cell payload.
     *
     * Simple products and variable products that carry the value on the
     * parent show it directly. For variable products whose GTIN lives on the
     * variations, the distinct values are collected: a single shared value is
     * shown as-is, multiple values collapse into a "N GTINs" badge with the
     * full list in a tooltip.
     *
     * @param WC_Product $product
     * @return array{value:string,tooltip:string,multi:bool}
     */
    private static function compute_global_unique_id_display($product) {
        $value = trim((string) $product->get_global_unique_id());

        if ($value === '' && $product->is_type('variable')) {
            $found = [];
            foreach ($product->get_children() as $cid) {
                $v = wc_get_product($cid);
                if (!$v) continue;
                $vid = trim((string) $v->get_global_unique_id());
                if ($vid !== '') {
                    $found[$vid] = true;
                }
            }
            $found = array_keys($found);

            if (count($found) === 1) {
                return ['value' => $found[0], 'tooltip' => '', 'multi' => false];
            }
            if (count($found) > 1) {
                return [
                    'value'   => sprintf(
                        /* translators: %d: number of distinct variation GTINs. */
                        _n('%d GTIN', '%d GTINs', count($found), 'brikpanel'),
                        count($found)
                    ),
                    'tooltip' => implode(', ', $found),
                    'multi'   => true,
                ];
            }
            return ['value' => '', 'tooltip' => '', 'multi' => false];
        }

        return ['value' => $value, 'tooltip' => '', 'multi' => false];
    }

    /**
     * Returns a list of downloads as plain arrays so they can be JSON-encoded
     * and sent to the JS layer. Mirrors the shape consumed by the product
     * editor's `state.downloads` array (id/name/file).
     */
    private static function serialize_downloads($product) {
        $out = [];
        if (!$product || !$product->is_downloadable()) {
            return $out;
        }
        foreach ((array) $product->get_downloads() as $dl) {
            if (!is_object($dl)) continue;
            $out[] = [
                'id'   => method_exists($dl, 'get_id')   ? (string) $dl->get_id()   : '',
                'name' => method_exists($dl, 'get_name') ? (string) $dl->get_name() : '',
                'file' => method_exists($dl, 'get_file') ? (string) $dl->get_file() : '',
            ];
        }
        return $out;
    }

    /**
     * Returns the list of custom product taxonomies eligible for the quick-edit
     * drawer. Empty when the feature is disabled. Built-in `product_cat` /
     * `product_tag` and attribute taxonomies (`pa_*`) are excluded — the first
     * pair has dedicated UI and the second is handled through variations.
     *
     * @return array Map of taxonomy_slug => WP_Taxonomy object.
     */
    public static function get_quick_edit_custom_taxonomies() {
        if (!function_exists('brikpanel_qe_is_field_visible') || !brikpanel_qe_is_field_visible('custom_taxonomies')) {
            return [];
        }
        $attribute_taxonomies = function_exists('wc_get_attribute_taxonomy_names')
            ? (array) wc_get_attribute_taxonomy_names()
            : [];
        $out = [];
        $all = get_object_taxonomies('product', 'objects');
        foreach ($all as $name => $tax) {
            if (empty($tax->show_ui)) continue;
            if (in_array($name, ['product_cat', 'product_tag'], true)) continue;
            if (in_array($name, $attribute_taxonomies, true)) continue;
            // Respect capability so editors without rights don't see fields
            // they cannot save.
            if (!empty($tax->cap->assign_terms) && !current_user_can($tax->cap->assign_terms)) continue;
            $out[$name] = $tax;
        }
        return apply_filters('brikpanel_qe_custom_taxonomies', $out);
    }

    /**
     * Renders a checkbox list for an arbitrary product taxonomy inside the
     * quick-edit drawer. Mirrors the markup conventions used by the built-in
     * categories / tags blocks so the existing `filterTermList()` JS handles
     * both hierarchical and flat modes without modification.
     */
    private function render_qe_taxonomy_checklist($terms, $hierarchical, $parent = 0, $depth = 0, $cb_class = 'bpl-qe-tax-cb') {
        if (!$hierarchical) {
            if (empty($terms)) {
                echo '<p class="brikpanel-pl-qe-term-empty">' . esc_html__('No terms found.', 'brikpanel') . '</p>';
                return;
            }
            foreach ($terms as $t) {
                echo '<label class="brikpanel-pl-qe-term-item" data-name="' . esc_attr(mb_strtolower($t->name)) . '">';
                echo '<input type="checkbox" class="' . esc_attr($cb_class) . '" value="' . esc_attr($t->term_id) . '"> ' . esc_html($t->name);
                echo '</label>';
            }
            return;
        }

        $children = [];
        foreach ($terms as $t) {
            if ((int) $t->parent === (int) $parent) {
                $children[] = $t;
            }
        }
        if (empty($children)) {
            if ($depth === 0) {
                echo '<p class="brikpanel-pl-qe-term-empty">' . esc_html__('No terms found.', 'brikpanel') . '</p>';
            }
            return;
        }
        $class = $parent === 0 ? 'brikpanel-pl-qe-term-tree' : 'brikpanel-pl-qe-term-children';
        echo '<ul class="' . esc_attr($class) . '">';
        foreach ($children as $t) {
            echo '<li data-name="' . esc_attr(mb_strtolower($t->name)) . '" class="brikpanel-pl-qe-term-depth-' . esc_attr($depth) . '">';
            echo '<label class="brikpanel-pl-qe-term-item"><input type="checkbox" class="' . esc_attr($cb_class) . '" value="' . esc_attr($t->term_id) . '"> ' . esc_html($t->name) . '</label>';
            $this->render_qe_taxonomy_checklist($terms, true, $t->term_id, $depth + 1, $cb_class);
            echo '</li>';
        }
        echo '</ul>';
    }

    /**
     * Returns the taxonomies eligible for bulk term assignment, keyed by slug.
     * Always exposes product_cat and product_tag; brands and other custom
     * product taxonomies are folded in via the same allowlist + capability
     * gate used by the quick-edit drawer so users only see what they can save.
     *
     * @return array Map of taxonomy_slug => WP_Taxonomy object.
     */
    public static function get_bulk_taxonomies() {
        $out = [];
        foreach (['product_cat', 'product_tag'] as $slug) {
            $tax = get_taxonomy($slug);
            if ($tax) {
                $out[$slug] = $tax;
            }
        }

        // Brands and other custom product taxonomies. Unlike the quick-edit
        // drawer this is NOT gated behind the QE `custom_taxonomies` toggle —
        // bulk edit is its own surface and brand bulk editing should always be
        // available. Attribute taxonomies (`pa_*`) stay out (handled through
        // variations) and capability is still enforced per taxonomy.
        $attribute_taxonomies = function_exists('wc_get_attribute_taxonomy_names')
            ? (array) wc_get_attribute_taxonomy_names()
            : [];
        foreach (get_object_taxonomies('product', 'objects') as $name => $tax) {
            if (isset($out[$name])) continue;
            if (empty($tax->show_ui)) continue;
            if (in_array($name, $attribute_taxonomies, true)) continue;
            if (!empty($tax->cap->assign_terms) && !current_user_can($tax->cap->assign_terms)) continue;
            $out[$name] = $tax;
        }

        return apply_filters('brikpanel_bulk_taxonomies', $out);
    }

    /**
     * Outputs the shared <optgroup> set for the bulk-update action dropdowns.
     * Used for both the "By scope" and "Selected products" tabs so price,
     * stock, shipping and organization (categories / tags / brands) actions
     * stay in sync. Taxonomy actions are encoded as `tax_<set|add>__<slug>`.
     */
    private function render_bulk_action_options() {
        $weight_unit = get_option('woocommerce_weight_unit', 'kg');
        $dim_unit    = get_option('woocommerce_dimension_unit', 'cm');
        ?>
        <optgroup label="<?php esc_attr_e('Price', 'brikpanel'); ?>">
            <option value="set_regular_price"><?php esc_html_e('Set regular price', 'brikpanel'); ?></option>
            <option value="set_sale_price"><?php esc_html_e('Set sale price', 'brikpanel'); ?></option>
            <option value="sale_from_regular_percent"><?php esc_html_e('Sale from regular price %', 'brikpanel'); ?></option>
            <option value="increase_price_percent"><?php esc_html_e('Increase prices by %', 'brikpanel'); ?></option>
            <option value="decrease_price_percent"><?php esc_html_e('Decrease prices by %', 'brikpanel'); ?></option>
            <option value="remove_sale_price"><?php esc_html_e('Remove sale price', 'brikpanel'); ?></option>
        </optgroup>
        <optgroup label="<?php esc_attr_e('Stock', 'brikpanel'); ?>">
            <option value="set_stock"><?php esc_html_e('Set stock quantity', 'brikpanel'); ?></option>
            <option value="increase_stock"><?php esc_html_e('Increase stock by', 'brikpanel'); ?></option>
        </optgroup>
        <optgroup label="<?php esc_attr_e('Shipping', 'brikpanel'); ?>">
            <option value="set_weight" data-unit="<?php echo esc_attr($weight_unit); ?>"><?php esc_html_e('Set weight', 'brikpanel'); ?></option>
            <option value="set_length" data-unit="<?php echo esc_attr($dim_unit); ?>"><?php esc_html_e('Set length', 'brikpanel'); ?></option>
            <option value="set_width" data-unit="<?php echo esc_attr($dim_unit); ?>"><?php esc_html_e('Set width', 'brikpanel'); ?></option>
            <option value="set_height" data-unit="<?php echo esc_attr($dim_unit); ?>"><?php esc_html_e('Set height', 'brikpanel'); ?></option>
        </optgroup>
        <optgroup label="<?php esc_attr_e('Organization', 'brikpanel'); ?>">
            <?php foreach (self::get_bulk_taxonomies() as $slug => $tax) :
                $label = isset($tax->labels->name) ? $tax->labels->name : $slug;
                ?>
                <option value="tax_set__<?php echo esc_attr($slug); ?>" data-taxonomy="<?php echo esc_attr($slug); ?>">
                    <?php
                    /* translators: %s: taxonomy label (e.g. Categories, Tags, Brands) */
                    echo esc_html(sprintf(__('Set %s', 'brikpanel'), $label));
                    ?>
                </option>
                <option value="tax_add__<?php echo esc_attr($slug); ?>" data-taxonomy="<?php echo esc_attr($slug); ?>">
                    <?php
                    /* translators: %s: taxonomy label (e.g. Categories, Tags, Brands) */
                    echo esc_html(sprintf(__('Add to %s', 'brikpanel'), $label));
                    ?>
                </option>
            <?php endforeach; ?>
        </optgroup>
        <?php
    }

    /**
     * Outputs the shared term-picker pool used by taxonomy bulk actions. One
     * collapsible checkbox list per bulk taxonomy; JS reveals the matching one
     * when a `tax_*` action is selected and reads the checked `.bpl-bulk-term-cb`
     * values on apply. Hidden by default and kept outside the tab panels so a
     * single set of controls serves both update tabs.
     */
    private function render_bulk_term_pickers() {
        foreach (self::get_bulk_taxonomies() as $slug => $tax) {
            $is_hierarchical = !empty($tax->hierarchical);
            $label           = isset($tax->labels->name) ? $tax->labels->name : $slug;
            /* translators: %s: taxonomy label */
            $placeholder     = sprintf(__('Search %s...', 'brikpanel'), $label);
            $list_classes    = 'brikpanel-pl-qe-term-list bpl-bulk-term-list' . ($is_hierarchical ? '' : ' brikpanel-pl-qe-term-list-flat');

            $terms = get_terms([
                'taxonomy'   => $slug,
                'hide_empty' => false,
                'orderby'    => 'name',
            ]);
            if (is_wp_error($terms)) {
                $terms = [];
            }
            ?>
            <div class="bpl-bulk-term-picker" data-taxonomy="<?php echo esc_attr($slug); ?>" style="display:none;">
                <div class="brikpanel-pl-qe-term-wrap">
                    <input type="text" class="brikpanel-pl-qe-term-search bpl-bulk-term-search" placeholder="<?php echo esc_attr($placeholder); ?>">
                    <div class="<?php echo esc_attr($list_classes); ?>">
                        <?php $this->render_qe_taxonomy_checklist($terms, $is_hierarchical, 0, 0, 'bpl-bulk-term-cb'); ?>
                    </div>
                </div>
            </div>
            <?php
        }
    }

    /**
     * Renders a hierarchical checkbox tree for product categories inside the
     * quick-edit drawer. Inputs have no `name` attribute; the JS layer reads
     * the checked values via the `.bpl-qe-cat-cb` selector when saving.
     */
    private function render_qe_category_checklist($categories, $parent = 0, $depth = 0) {
        $children = [];
        foreach ($categories as $cat) {
            if ((int) $cat->parent === (int) $parent) {
                $children[] = $cat;
            }
        }
        if (empty($children)) {
            if ($depth === 0) {
                echo '<p class="brikpanel-pl-qe-term-empty">' . esc_html__('No categories found.', 'brikpanel') . '</p>';
            }
            return;
        }

        $class = $parent === 0 ? 'brikpanel-pl-qe-term-tree' : 'brikpanel-pl-qe-term-children';
        echo '<ul class="' . esc_attr($class) . '">';
        foreach ($children as $cat) {
            echo '<li data-name="' . esc_attr(mb_strtolower($cat->name)) . '" class="brikpanel-pl-qe-term-depth-' . esc_attr($depth) . '">';
            echo '<label class="brikpanel-pl-qe-term-item"><input type="checkbox" class="bpl-qe-cat-cb" value="' . esc_attr($cat->term_id) . '"> ' . esc_html($cat->name) . '</label>';
            $this->render_qe_category_checklist($categories, $cat->term_id, $depth + 1);
            echo '</li>';
        }
        echo '</ul>';
    }

    /**
     * Renders a single quick-edit drawer field by slug.
     *
     * Each field block is self-contained and tagged with `data-qe-slug` so
     * the visibility config can render them in any order. Simple-only fields
     * carry `brikpanel-pl-qe-simple-only` so the drawer can hide them when
     * opening a variable product without rebuilding the DOM.
     *
     * @param string $slug    One of the slugs in brikpanel_qe_field_options().
     * @param array  $context Pre-resolved data shared across fields:
     *                       currency, tags, categories, custom_taxonomies,
     *                       custom_taxonomy_terms.
     */
    private function render_qe_field($slug, array $context) {
        $simple_only_set = function_exists('brikpanel_qe_simple_only_fields')
            ? array_flip(brikpanel_qe_simple_only_fields())
            : [];
        $simple_class = isset($simple_only_set[$slug]) ? ' brikpanel-pl-qe-simple-only' : '';

        switch ($slug) {
            case 'name':
                ?>
                <div class="brikpanel-pl-qe-field<?php echo esc_attr($simple_class); ?>" data-qe-slug="name">
                    <label for="bpl-qe-name"><?php esc_html_e('Product name', 'brikpanel'); ?></label>
                    <input type="text" id="bpl-qe-name">
                </div>
                <?php
                break;

            case 'pricing':
                $currency = isset($context['currency']) ? $context['currency'] : '';
                ?>
                <div class="brikpanel-pl-qe-row<?php echo esc_attr($simple_class); ?>" data-qe-slug="pricing">
                    <div class="brikpanel-pl-qe-field">
                        <label for="bpl-qe-price"><?php esc_html_e('Price', 'brikpanel'); ?></label>
                        <div class="brikpanel-pl-input-group">
                            <span class="brikpanel-pl-input-prefix"><?php echo esc_html($currency); ?></span>
                            <input type="text" id="bpl-qe-price">
                        </div>
                    </div>
                    <div class="brikpanel-pl-qe-field">
                        <label for="bpl-qe-sale-price"><?php esc_html_e('Sale price', 'brikpanel'); ?></label>
                        <div class="brikpanel-pl-input-group">
                            <span class="brikpanel-pl-input-prefix"><?php echo esc_html($currency); ?></span>
                            <input type="text" id="bpl-qe-sale-price">
                        </div>
                    </div>
                </div>
                <?php
                break;

            case 'cogs':
                $currency = isset($context['currency']) ? $context['currency'] : '';
                ?>
                <div class="brikpanel-pl-qe-field<?php echo esc_attr($simple_class); ?>" data-qe-slug="cogs">
                    <label for="bpl-qe-cogs"><?php esc_html_e('Cost of goods', 'brikpanel'); ?></label>
                    <div class="brikpanel-pl-input-group">
                        <span class="brikpanel-pl-input-prefix"><?php echo esc_html($currency); ?></span>
                        <input type="text" id="bpl-qe-cogs" inputmode="decimal" autocomplete="off">
                    </div>
                </div>
                <?php
                break;

            case 'inventory':
                ?>
                <div class="brikpanel-pl-qe-row brikpanel-pl-qe-row-2<?php echo esc_attr($simple_class); ?>" data-qe-slug="inventory">
                    <div class="brikpanel-pl-qe-field">
                        <label for="bpl-qe-stock"><?php esc_html_e('Stock quantity', 'brikpanel'); ?></label>
                        <input type="number" id="bpl-qe-stock" min="0">
                    </div>
                    <div class="brikpanel-pl-qe-field">
                        <label for="bpl-qe-sku"><?php esc_html_e('SKU', 'brikpanel'); ?></label>
                        <input type="text" id="bpl-qe-sku">
                    </div>
                </div>
                <?php
                break;

            case 'stock_status':
                ?>
                <div class="brikpanel-pl-qe-field<?php echo esc_attr($simple_class); ?>" data-qe-slug="stock_status">
                    <label><?php esc_html_e('Availability', 'brikpanel'); ?></label>
                    <div class="brikpanel-pl-toggle-group" role="radiogroup" id="bpl-qe-stock-status-toggle">
                        <button type="button" class="brikpanel-pl-toggle-opt is-active" data-value="instock" role="radio" aria-checked="true">
                            <span class="brikpanel-pl-toggle-dot instock"></span><?php esc_html_e('In stock', 'brikpanel'); ?>
                        </button>
                        <button type="button" class="brikpanel-pl-toggle-opt" data-value="outofstock" role="radio" aria-checked="false">
                            <span class="brikpanel-pl-toggle-dot outofstock"></span><?php esc_html_e('Out of stock', 'brikpanel'); ?>
                        </button>
                        <button type="button" class="brikpanel-pl-toggle-opt" data-value="onbackorder" role="radio" aria-checked="false">
                            <span class="brikpanel-pl-toggle-dot onbackorder"></span><?php esc_html_e('On backorder', 'brikpanel'); ?>
                        </button>
                    </div>
                    <input type="hidden" id="bpl-qe-stock-status" value="instock">
                </div>
                <?php
                break;

            case 'digital':
                ?>
                <div class="brikpanel-pl-qe-field brikpanel-pl-qe-digital<?php echo esc_attr($simple_class); ?>" data-qe-slug="digital">
                    <div class="brikpanel-pl-toggle-row">
                        <span class="brikpanel-pl-toggle-label">
                            <?php esc_html_e('Virtual (no shipping)', 'brikpanel'); ?>
                            <small class="brikpanel-pl-toggle-help"><?php esc_html_e('Service or intangible product — no physical shipping.', 'brikpanel'); ?></small>
                        </span>
                        <label class="brikpanel-pl-switch">
                            <input type="checkbox" id="bpl-qe-virtual-toggle">
                            <span class="brikpanel-pl-switch-slider"></span>
                        </label>
                    </div>
                    <div class="brikpanel-pl-toggle-row">
                        <span class="brikpanel-pl-toggle-label">
                            <?php esc_html_e('Digital (downloadable)', 'brikpanel'); ?>
                            <small class="brikpanel-pl-toggle-help"><?php esc_html_e('Customers receive download links after purchase.', 'brikpanel'); ?></small>
                        </span>
                        <label class="brikpanel-pl-switch">
                            <input type="checkbox" id="bpl-qe-digital-toggle">
                            <span class="brikpanel-pl-switch-slider"></span>
                        </label>
                    </div>
                    <div class="brikpanel-pl-collapse" id="bpl-qe-digital-section">
                        <div>
                            <div class="brikpanel-pl-downloads" id="bpl-qe-downloads-list"></div>
                            <button type="button" class="brikpanel-pl-btn secondary small" id="bpl-qe-add-download">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                <?php esc_html_e('Add downloadable file', 'brikpanel'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                <?php
                break;

            case 'status':
                ?>
                <div class="brikpanel-pl-qe-field" data-qe-slug="status">
                    <label for="bpl-qe-status"><?php esc_html_e('Status', 'brikpanel'); ?></label>
                    <select id="bpl-qe-status" class="brikpanel-pl-select">
                        <option value="publish"><?php esc_html_e('Published', 'brikpanel'); ?></option>
                        <option value="draft"><?php esc_html_e('Draft', 'brikpanel'); ?></option>
                        <option value="private"><?php esc_html_e('Private', 'brikpanel'); ?></option>
                    </select>
                </div>
                <?php
                break;

            case 'categories':
                $categories = isset($context['categories']) ? $context['categories'] : [];
                ?>
                <div class="brikpanel-pl-qe-field" data-qe-slug="categories">
                    <label><?php esc_html_e('Categories', 'brikpanel'); ?></label>
                    <div class="brikpanel-pl-qe-term-wrap">
                        <input type="text" class="brikpanel-pl-qe-term-search" id="bpl-qe-cat-search" placeholder="<?php esc_attr_e('Search categories...', 'brikpanel'); ?>">
                        <div class="brikpanel-pl-qe-term-list" id="bpl-qe-cat-list">
                            <?php $this->render_qe_category_checklist($categories); ?>
                        </div>
                    </div>
                </div>
                <?php
                break;

            case 'tags':
                $tags = isset($context['tags']) ? $context['tags'] : [];
                ?>
                <div class="brikpanel-pl-qe-field" data-qe-slug="tags">
                    <label><?php esc_html_e('Tags', 'brikpanel'); ?></label>
                    <div class="brikpanel-pl-qe-term-wrap">
                        <input type="text" class="brikpanel-pl-qe-term-search" id="bpl-qe-tag-search" placeholder="<?php esc_attr_e('Search tags...', 'brikpanel'); ?>">
                        <div class="brikpanel-pl-qe-term-list brikpanel-pl-qe-term-list-flat" id="bpl-qe-tag-list">
                            <?php if (empty($tags)) : ?>
                                <p class="brikpanel-pl-qe-term-empty"><?php esc_html_e('No tags found.', 'brikpanel'); ?></p>
                            <?php else : ?>
                                <?php foreach ($tags as $tag) : ?>
                                    <label class="brikpanel-pl-qe-term-item" data-name="<?php echo esc_attr(mb_strtolower($tag->name)); ?>">
                                        <input type="checkbox" class="bpl-qe-tag-cb" value="<?php echo esc_attr($tag->term_id); ?>"> <?php echo esc_html($tag->name); ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php
                break;

            case 'custom_taxonomies':
                $custom_taxonomies     = isset($context['custom_taxonomies']) ? $context['custom_taxonomies'] : [];
                $custom_taxonomy_terms = isset($context['custom_taxonomy_terms']) ? $context['custom_taxonomy_terms'] : [];
                foreach ($custom_taxonomies as $tax_slug => $tax_object) :
                    $is_hierarchical = !empty($tax_object->hierarchical);
                    $label_text      = isset($tax_object->labels->name) ? $tax_object->labels->name : $tax_slug;
                    /* translators: %s: taxonomy label */
                    $placeholder     = sprintf(__('Search %s...', 'brikpanel'), $label_text);
                    $list_classes    = 'brikpanel-pl-qe-term-list bpl-qe-tax-list' . ($is_hierarchical ? '' : ' brikpanel-pl-qe-term-list-flat');
                    ?>
                    <div class="brikpanel-pl-qe-field" data-qe-slug="custom_taxonomies" data-qe-taxonomy="<?php echo esc_attr($tax_slug); ?>">
                        <label><?php echo esc_html($label_text); ?></label>
                        <div class="brikpanel-pl-qe-term-wrap">
                            <input type="text" class="brikpanel-pl-qe-term-search bpl-qe-tax-search" data-taxonomy="<?php echo esc_attr($tax_slug); ?>" placeholder="<?php echo esc_attr($placeholder); ?>">
                            <div class="<?php echo esc_attr($list_classes); ?>" data-taxonomy="<?php echo esc_attr($tax_slug); ?>" id="bpl-qe-tax-list-<?php echo esc_attr($tax_slug); ?>">
                                <?php $this->render_qe_taxonomy_checklist(isset($custom_taxonomy_terms[$tax_slug]) ? $custom_taxonomy_terms[$tax_slug] : [], $is_hierarchical); ?>
                            </div>
                        </div>
                    </div>
                    <?php
                endforeach;
                break;

            case 'featured':
                // Rendered in the drawer header (not the body). No-op here so
                // the renderer loop can still iterate over the canonical
                // visible list without a special-case branch.
                break;
        }
    }

    // =========================================================================
    // RENDER PAGE
    // =========================================================================

    public function render_page() {
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name']);
        if (is_wp_error($categories)) {
            $categories = [];
        }
        $tags = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false, 'orderby' => 'name']);
        if (is_wp_error($tags)) {
            $tags = [];
        }

        // Custom taxonomies (Brands and other third-party product taxonomies).
        // Disabled by default; opt-in via `brikpanel_qe_custom_taxonomies`.
        $custom_taxonomies = self::get_quick_edit_custom_taxonomies();
        $custom_taxonomy_terms = [];
        foreach ($custom_taxonomies as $tax_slug => $tax_object) {
            $terms = get_terms([
                'taxonomy'   => $tax_slug,
                'hide_empty' => false,
                'orderby'    => 'name',
            ]);
            $custom_taxonomy_terms[$tax_slug] = is_wp_error($terms) ? [] : $terms;
        }

        $currency     = get_woocommerce_currency_symbol();
        $column_defs  = self::get_column_defs();
        $column_state = self::get_user_columns();
        $table_attrs  = '';
        foreach ($column_state as $col_id => $visible) {
            if ($visible) {
                continue;
            }
            // Native columns hide via `data-hide-<id>` + matching CSS rule.
            // Extra (3rd-party) columns have dynamic IDs, so JS toggles a
            // `bpl-col-hidden` class on their <th>/<td> instead.
            if (empty($column_defs[$col_id]['extra'])) {
                $table_attrs .= ' data-hide-' . esc_attr($col_id) . '="1"';
            }
        }

        // Count products by status
        $counts = wp_count_posts('product');
        $total     = isset($counts->publish) ? (int) $counts->publish : 0;
        $draft     = isset($counts->draft) ? (int) $counts->draft : 0;
        $private_c = isset($counts->private) ? (int) $counts->private : 0;
        $trash     = isset($counts->trash) ? (int) $counts->trash : 0;
        $all_count = $total + $draft + $private_c;
        ?>
        <div class="wrap">
        <div class="brikpanel-pl" id="brikpanel-products-list">

            <!-- Header -->
            <div class="brikpanel-pl-header">
                <div class="brikpanel-pl-header-left">
                    <h1><?php esc_html_e('Products', 'brikpanel'); ?></h1>
                    <span class="brikpanel-pl-count" id="bpl-total-count"><?php echo esc_html($all_count); ?></span>
                </div>
                <div class="brikpanel-pl-header-right">
                    <div class="brikpanel-pl-search-wrap">
                        <svg class="brikpanel-pl-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                        <input type="text" id="bpl-search" class="brikpanel-pl-search" placeholder="<?php esc_attr_e('Search products...', 'brikpanel'); ?>">
                    </div>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=product&page=product_importer')); ?>" class="brikpanel-pl-btn secondary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        <?php esc_html_e('Import', 'brikpanel'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=product&page=product_exporter')); ?>" class="brikpanel-pl-btn secondary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <?php esc_html_e('Export', 'brikpanel'); ?>
                    </a>
                    <button type="button" class="brikpanel-pl-btn secondary" id="bpl-bulk-update-btn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        <?php esc_html_e('Bulk update', 'brikpanel'); ?>
                    </button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=brikpanel-product-editor')); ?>" class="brikpanel-pl-btn primary" id="bpl-add-new">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <?php esc_html_e('Add product', 'brikpanel'); ?>
                    </a>
                </div>
            </div>

            <!-- Filters Bar -->
            <div class="brikpanel-pl-filters">
                <div class="brikpanel-pl-tabs">
                    <button class="brikpanel-pl-tab active" data-status="any">
                        <?php esc_html_e('All', 'brikpanel'); ?>
                        <span class="brikpanel-pl-tab-count" data-count="all"><?php echo esc_html($all_count); ?></span>
                    </button>
                    <button class="brikpanel-pl-tab" data-status="publish">
                        <?php esc_html_e('Published', 'brikpanel'); ?>
                        <span class="brikpanel-pl-tab-count" data-count="publish"><?php echo esc_html($total); ?></span>
                    </button>
                    <button class="brikpanel-pl-tab" data-status="draft">
                        <?php esc_html_e('Draft', 'brikpanel'); ?>
                        <span class="brikpanel-pl-tab-count" data-count="draft"><?php echo esc_html($draft); ?></span>
                    </button>
                    <?php if ($private_c > 0) : ?>
                    <button class="brikpanel-pl-tab" data-status="private">
                        <?php esc_html_e('Private', 'brikpanel'); ?>
                        <span class="brikpanel-pl-tab-count" data-count="private"><?php echo esc_html($private_c); ?></span>
                    </button>
                    <?php endif; ?>
                    <?php if ($trash > 0) : ?>
                    <button class="brikpanel-pl-tab" data-status="trash">
                        <?php esc_html_e('Trash', 'brikpanel'); ?>
                        <span class="brikpanel-pl-tab-count" data-count="trash"><?php echo esc_html($trash); ?></span>
                    </button>
                    <?php endif; ?>
                </div>
                <div class="brikpanel-pl-filter-group">
                    <select id="bpl-cat-filter" class="brikpanel-pl-select">
                        <option value=""><?php esc_html_e('All categories', 'brikpanel'); ?></option>
                        <?php foreach ($categories as $cat) : ?>
                            <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?> (<?php echo esc_html($cat->count); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <select id="bpl-stock-filter" class="brikpanel-pl-select">
                        <option value=""><?php esc_html_e('All stock', 'brikpanel'); ?></option>
                        <option value="instock"><?php esc_html_e('In stock', 'brikpanel'); ?></option>
                        <option value="outofstock"><?php esc_html_e('Out of stock', 'brikpanel'); ?></option>
                        <option value="lowstock"><?php esc_html_e('Low stock', 'brikpanel'); ?></option>
                    </select>
                    <?php
                    // Product types: pull all `product_type` terms so third-party
                    // types (subscription, booking, bundle, etc.) registered by
                    // other plugins surface automatically. wc_get_product_types()
                    // gives nicely translated labels for the four core types.
                    $core_type_labels = function_exists('wc_get_product_types') ? wc_get_product_types() : [];
                    $type_terms = get_terms([
                        'taxonomy'   => 'product_type',
                        'hide_empty' => false,
                    ]);
                    if (!is_wp_error($type_terms) && !empty($type_terms)) : ?>
                    <select id="bpl-type-filter" class="brikpanel-pl-select">
                        <option value=""><?php esc_html_e('All types', 'brikpanel'); ?></option>
                        <?php foreach ($type_terms as $type_term) :
                            $label = isset($core_type_labels[$type_term->slug])
                                ? $core_type_labels[$type_term->slug]
                                : ucfirst($type_term->name);
                            ?>
                            <option value="<?php echo esc_attr($type_term->slug); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <select id="bpl-featured-filter" class="brikpanel-pl-select">
                        <option value=""><?php esc_html_e('All products', 'brikpanel'); ?></option>
                        <option value="yes"><?php esc_html_e('Featured only', 'brikpanel'); ?></option>
                        <option value="no"><?php esc_html_e('Not featured', 'brikpanel'); ?></option>
                    </select>
                    <select id="bpl-sort" class="brikpanel-pl-select">
                        <option value="date-desc"><?php esc_html_e('Newest first', 'brikpanel'); ?></option>
                        <option value="date-asc"><?php esc_html_e('Oldest first', 'brikpanel'); ?></option>
                        <option value="title-asc"><?php esc_html_e('Name A-Z', 'brikpanel'); ?></option>
                        <option value="title-desc"><?php esc_html_e('Name Z-A', 'brikpanel'); ?></option>
                        <option value="price-asc"><?php esc_html_e('Price low-high', 'brikpanel'); ?></option>
                        <option value="price-desc"><?php esc_html_e('Price high-low', 'brikpanel'); ?></option>
                        <option value="menu-asc"><?php esc_html_e('Custom order', 'brikpanel'); ?></option>
                    </select>
                    <button type="button" class="brikpanel-pl-btn secondary" id="bpl-sort-toggle" aria-pressed="false" title="<?php esc_attr_e('Drag products to reorder them in the storefront', 'brikpanel'); ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                        <span class="brikpanel-pl-sort-toggle-label"><?php esc_html_e('Sort', 'brikpanel'); ?></span>
                    </button>
                    <div class="brikpanel-pl-columns-menu" id="bpl-columns-menu">
                        <button type="button" class="brikpanel-pl-btn secondary brikpanel-pl-columns-btn" id="bpl-columns-btn" aria-haspopup="true" aria-expanded="false">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg>
                            <?php esc_html_e('Columns', 'brikpanel'); ?>
                        </button>
                        <div class="brikpanel-pl-columns-popover" id="bpl-columns-popover" role="menu" hidden>
                            <?php
                            $has_extra = false;
                            foreach ($column_defs as $col_id => $def) :
                                $locked  = !empty($def['locked']);
                                $checked = !empty($column_state[$col_id]);
                                $extra   = !empty($def['extra']);
                                if ($extra && !$has_extra) :
                                    $has_extra = true; ?>
                                    <div class="brikpanel-pl-columns-divider" role="separator" aria-hidden="true">
                                        <?php esc_html_e('Plugin columns', 'brikpanel'); ?>
                                    </div>
                                <?php endif; ?>
                                <label class="brikpanel-pl-columns-item<?php echo $locked ? ' is-locked' : ''; ?><?php echo $extra ? ' is-extra' : ''; ?>">
                                    <input type="checkbox" data-col="<?php echo esc_attr($col_id); ?>"
                                        <?php if ($extra) echo ' data-extra="1"'; ?>
                                        <?php checked($checked); ?>
                                        <?php disabled($locked); ?>>
                                    <span><?php echo esc_html($def['label']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bulk Actions Bar (hidden by default) -->
            <div class="brikpanel-pl-bulk-bar" id="bpl-bulk-bar" style="display:none;">
                <div class="brikpanel-pl-bulk-left">
                    <span id="bpl-selected-count">0</span> <?php esc_html_e('selected', 'brikpanel'); ?>
                    <button type="button" class="brikpanel-pl-bulk-link" id="bpl-select-all-btn"><?php esc_html_e('Select all', 'brikpanel'); ?></button>
                    <button type="button" class="brikpanel-pl-bulk-link" id="bpl-deselect-all-btn"><?php esc_html_e('Deselect all', 'brikpanel'); ?></button>
                </div>
                <div class="brikpanel-pl-bulk-right">
                    <button type="button" class="brikpanel-pl-btn secondary small" id="bpl-bulk-export">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <?php esc_html_e('Export selected', 'brikpanel'); ?>
                    </button>
                    <button type="button" class="brikpanel-pl-btn secondary small" id="bpl-bulk-publish"><?php esc_html_e('Publish', 'brikpanel'); ?></button>
                    <button type="button" class="brikpanel-pl-btn secondary small" id="bpl-bulk-draft"><?php esc_html_e('Set as draft', 'brikpanel'); ?></button>
                    <button type="button" class="brikpanel-pl-btn danger small" id="bpl-bulk-trash"><?php esc_html_e('Move to trash', 'brikpanel'); ?></button>
                    <button type="button" class="brikpanel-pl-btn danger small" id="bpl-bulk-delete-perm">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                        <?php esc_html_e('Delete permanently', 'brikpanel'); ?>
                    </button>
                </div>
            </div>

            <!-- Products Table -->
            <div class="brikpanel-pl-card">
                <div class="brikpanel-pl-progress" id="bpl-progress"></div>
                <div class="brikpanel-pl-sort-hint" id="bpl-sort-hint" hidden>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <span><?php esc_html_e('Drag the handle on the left of each row to set the order products appear in your storefront. Changes are saved automatically.', 'brikpanel'); ?></span>
                    <button type="button" class="brikpanel-pl-btn primary small" id="bpl-sort-done"><?php esc_html_e('Done sorting', 'brikpanel'); ?></button>
                </div>
                <div class="brikpanel-pl-table-wrap">
                    <table class="brikpanel-pl-table" id="bpl-table"<?php echo $table_attrs; // already-escaped attrs ?>>
                        <thead>
                            <tr>
                                <th class="brikpanel-pl-th-handle" aria-hidden="true"></th>
                                <th class="brikpanel-pl-th-check">
                                    <input type="checkbox" id="bpl-check-all" class="brikpanel-pl-checkbox">
                                </th>
                                <th class="brikpanel-pl-th-image brikpanel-pl-col brikpanel-pl-col-image"></th>
                                <th class="brikpanel-pl-th-name brikpanel-pl-col brikpanel-pl-col-name"><?php esc_html_e('Product', 'brikpanel'); ?></th>
                                <th class="brikpanel-pl-th-sku brikpanel-pl-col brikpanel-pl-col-sku"><?php esc_html_e('SKU', 'brikpanel'); ?></th>
                                <th class="brikpanel-pl-th-guid brikpanel-pl-col brikpanel-pl-col-global_unique_id"><?php esc_html_e('GTIN', 'brikpanel'); ?></th>
                                <th class="brikpanel-pl-th-price brikpanel-pl-col brikpanel-pl-col-price"><?php esc_html_e('Price', 'brikpanel'); ?></th>
                                <th class="brikpanel-pl-th-cogs brikpanel-pl-col brikpanel-pl-col-cogs"><?php esc_html_e('Cost', 'brikpanel'); ?></th>
                                <th class="brikpanel-pl-th-stock brikpanel-pl-col brikpanel-pl-col-stock"><?php esc_html_e('Stock', 'brikpanel'); ?></th>
                                <th class="brikpanel-pl-th-cat brikpanel-pl-col brikpanel-pl-col-category"><?php esc_html_e('Category', 'brikpanel'); ?></th>
                                <th class="brikpanel-pl-th-status brikpanel-pl-col brikpanel-pl-col-status"><?php esc_html_e('Status', 'brikpanel'); ?></th>
                                <th class="brikpanel-pl-th-actions"></th>
                            </tr>
                        </thead>
                        <tbody id="bpl-table-body">
                            <tr class="brikpanel-pl-loading-row">
                                <td colspan="12">
                                    <div class="brikpanel-pl-spinner"></div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="brikpanel-pl-pagination" id="bpl-pagination"></div>
            </div>

            <!-- Quick Edit Drawer -->
            <?php
            $qe_visible_fields = function_exists('brikpanel_qe_get_visible_fields_ordered')
                ? brikpanel_qe_get_visible_fields_ordered()
                : ['name', 'pricing', 'inventory', 'stock_status', 'digital', 'status', 'categories', 'tags'];
            $qe_visible_set    = array_flip($qe_visible_fields);
            $featured_star_on  = isset($qe_visible_set['featured']);
            ?>
            <div class="brikpanel-pl-drawer-overlay" id="bpl-drawer-overlay"></div>
            <div class="brikpanel-pl-drawer" id="bpl-drawer">
                <div class="brikpanel-pl-drawer-header">
                    <div class="brikpanel-pl-drawer-title">
                        <h3><?php esc_html_e('Quick edit', 'brikpanel'); ?></h3>
                        <?php if ($featured_star_on) : ?>
                            <button type="button" class="brikpanel-pl-qe-featured-star" id="bpl-qe-featured-star" aria-pressed="false" title="<?php esc_attr_e('Mark as featured', 'brikpanel'); ?>">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                                </svg>
                                <span class="screen-reader-text"><?php esc_html_e('Mark as featured', 'brikpanel'); ?></span>
                            </button>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="brikpanel-pl-drawer-close" id="bpl-drawer-close">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="brikpanel-pl-drawer-body">
                    <input type="hidden" id="bpl-qe-id">
                    <?php
                    // Variations editor is mutually exclusive with the
                    // simple-only fields. Emit it inline at the position the
                    // first simple-only slug occupies so a variable product
                    // sees the variation list right where pricing/stock/etc.
                    // would have been. Falls back to the end if no simple-
                    // only slugs are currently visible.
                    $qe_simple_only_set = function_exists('brikpanel_qe_simple_only_fields')
                        ? array_flip(brikpanel_qe_simple_only_fields())
                        : [];
                    $variations_emitted = false;
                    foreach ($qe_visible_fields as $qe_slug) {
                        if (!$variations_emitted && isset($qe_simple_only_set[$qe_slug])) {
                            echo '<div id="bpl-qe-variations" style="display:none;"></div>';
                            $variations_emitted = true;
                        }
                        $this->render_qe_field($qe_slug, [
                            'currency'              => $currency,
                            'tags'                  => $tags,
                            'categories'            => $categories,
                            'custom_taxonomies'     => $custom_taxonomies,
                            'custom_taxonomy_terms' => $custom_taxonomy_terms,
                        ]);
                    }
                    if (!$variations_emitted) {
                        echo '<div id="bpl-qe-variations" style="display:none;"></div>';
                    }
                    ?>
                </div>
                <div class="brikpanel-pl-drawer-footer">
                    <button type="button" class="brikpanel-pl-btn secondary" id="bpl-qe-cancel"><?php esc_html_e('Cancel', 'brikpanel'); ?></button>
                    <button type="button" class="brikpanel-pl-btn primary" id="bpl-qe-save"><?php esc_html_e('Save changes', 'brikpanel'); ?></button>
                </div>
            </div>

            <!-- Bulk Update Modal -->
            <div class="brikpanel-pl-modal-overlay" id="bpl-bulk-modal-overlay">
                <div class="brikpanel-pl-modal" id="bpl-bulk-modal">
                    <div class="brikpanel-pl-modal-header">
                        <h3><?php esc_html_e('Bulk update', 'brikpanel'); ?></h3>
                        <button type="button" class="brikpanel-pl-modal-close" id="bpl-bulk-modal-close">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="brikpanel-pl-modal-body">
                        <!-- Tabs -->
                        <div class="brikpanel-pl-modal-tabs">
                            <button class="brikpanel-pl-modal-tab active" data-tab="bpl-bulk-tab-cat"><?php esc_html_e('By scope', 'brikpanel'); ?></button>
                            <button class="brikpanel-pl-modal-tab" data-tab="bpl-bulk-tab-sel"><?php esc_html_e('Selected products', 'brikpanel'); ?></button>
                            <button class="brikpanel-pl-modal-tab brikpanel-pl-modal-tab-danger" data-tab="bpl-bulk-tab-del"><?php esc_html_e('Bulk delete', 'brikpanel'); ?></button>
                        </div>

                        <!-- Tab: By Scope -->
                        <div class="brikpanel-pl-modal-tab-content active" id="bpl-bulk-tab-cat">
                            <div class="brikpanel-pl-modal-field">
                                <label><?php esc_html_e('Scope', 'brikpanel'); ?> *</label>
                                <select id="bpl-bulk-scope" class="brikpanel-pl-select full">
                                    <option value="category"><?php esc_html_e('All products in a category', 'brikpanel'); ?></option>
                                    <option value="all"><?php esc_html_e('All products in the store', 'brikpanel'); ?></option>
                                </select>
                            </div>
                            <div class="brikpanel-pl-modal-field" id="bpl-bulk-cat-wrap">
                                <label><?php esc_html_e('Category', 'brikpanel'); ?> *</label>
                                <select id="bpl-bulk-cat" class="brikpanel-pl-select full">
                                    <option value=""><?php esc_html_e('— Select category —', 'brikpanel'); ?></option>
                                    <?php foreach ($categories as $cat) : ?>
                                        <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?> (<?php echo esc_html($cat->count); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="brikpanel-pl-modal-row">
                                <div class="brikpanel-pl-modal-field grow">
                                    <label><?php esc_html_e('Action', 'brikpanel'); ?></label>
                                    <select id="bpl-bulk-action-cat" class="brikpanel-pl-select full">
                                        <?php $this->render_bulk_action_options(); ?>
                                    </select>
                                </div>
                                <div class="brikpanel-pl-modal-field bpl-bulk-value-wrap" id="bpl-bulk-value-cat-wrap" style="width:120px;">
                                    <label><?php esc_html_e('Value', 'brikpanel'); ?></label>
                                    <div class="brikpanel-pl-input-group">
                                        <input type="text" id="bpl-bulk-value-cat" placeholder="0">
                                        <span class="brikpanel-pl-input-suffix bpl-bulk-unit" style="display:none;"></span>
                                    </div>
                                </div>
                            </div>
                            <!-- Variation attribute filter -->
                            <div id="bpl-bulk-varfilter-cat">
                                <div class="brikpanel-pl-modal-divider"></div>
                                <div class="brikpanel-pl-modal-field-label"><?php esc_html_e('Variation filter (optional)', 'brikpanel'); ?></div>
                                <div class="brikpanel-pl-modal-row">
                                    <div class="brikpanel-pl-modal-field grow">
                                        <select id="bpl-bulk-attr-key" class="brikpanel-pl-select full">
                                            <option value=""><?php esc_html_e('All products / variations', 'brikpanel'); ?></option>
                                        </select>
                                    </div>
                                    <div class="brikpanel-pl-modal-field grow">
                                        <select id="bpl-bulk-attr-val" class="brikpanel-pl-select full" disabled>
                                            <option value=""><?php esc_html_e('Select attribute first', 'brikpanel'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                <p class="brikpanel-pl-modal-hint"><?php esc_html_e('Use the variation filter to update only specific variations, e.g. only "Size: XL".', 'brikpanel'); ?></p>
                            </div>
                        </div>

                        <!-- Tab: Selected Products -->
                        <div class="brikpanel-pl-modal-tab-content" id="bpl-bulk-tab-sel">
                            <p class="brikpanel-pl-modal-info" id="bpl-bulk-sel-info"></p>
                            <div class="brikpanel-pl-modal-row">
                                <div class="brikpanel-pl-modal-field grow">
                                    <label><?php esc_html_e('Action', 'brikpanel'); ?></label>
                                    <select id="bpl-bulk-action-sel" class="brikpanel-pl-select full">
                                        <?php $this->render_bulk_action_options(); ?>
                                    </select>
                                </div>
                                <div class="brikpanel-pl-modal-field bpl-bulk-value-wrap" id="bpl-bulk-value-sel-wrap" style="width:120px;">
                                    <label><?php esc_html_e('Value', 'brikpanel'); ?></label>
                                    <div class="brikpanel-pl-input-group">
                                        <input type="text" id="bpl-bulk-value-sel" placeholder="0">
                                        <span class="brikpanel-pl-input-suffix bpl-bulk-unit" style="display:none;"></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Shared term picker pool (revealed for taxonomy actions) -->
                        <div id="bpl-bulk-term-region" style="display:none;">
                            <div class="brikpanel-pl-modal-field-label" id="bpl-bulk-term-label"><?php esc_html_e('Terms', 'brikpanel'); ?></div>
                            <?php $this->render_bulk_term_pickers(); ?>
                            <p class="brikpanel-pl-modal-hint"><?php esc_html_e('Terms apply at the product level. For variable products they are set on the parent product.', 'brikpanel'); ?></p>
                        </div>

                        <!-- Tab: Bulk Delete -->
                        <div class="brikpanel-pl-modal-tab-content" id="bpl-bulk-tab-del">
                            <div class="brikpanel-pl-modal-danger-banner">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                <?php esc_html_e('This action cannot be undone when permanent delete is enabled.', 'brikpanel'); ?>
                            </div>
                            <div class="brikpanel-pl-modal-field">
                                <label><?php esc_html_e('Delete mode', 'brikpanel'); ?></label>
                                <select id="bpl-del-mode" class="brikpanel-pl-select full">
                                    <option value="selected"><?php esc_html_e('Selected products only', 'brikpanel'); ?></option>
                                    <option value="category"><?php esc_html_e('All products in a category', 'brikpanel'); ?></option>
                                    <option value="all"><?php esc_html_e('All products in the store', 'brikpanel'); ?></option>
                                </select>
                            </div>
                            <div class="brikpanel-pl-modal-field" id="bpl-del-cat-wrap" style="display:none;">
                                <label><?php esc_html_e('Category', 'brikpanel'); ?></label>
                                <select id="bpl-del-cat" class="brikpanel-pl-select full">
                                    <option value=""><?php esc_html_e('— Select category —', 'brikpanel'); ?></option>
                                    <?php foreach ($categories as $cat) : ?>
                                        <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?> (<?php echo esc_html($cat->count); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="brikpanel-pl-modal-field">
                                <label class="brikpanel-pl-modal-check">
                                    <input type="checkbox" id="bpl-del-permanent">
                                    <?php esc_html_e('Permanently delete (skip trash)', 'brikpanel'); ?>
                                </label>
                            </div>
                            <div class="brikpanel-pl-modal-field">
                                <label class="brikpanel-pl-modal-check">
                                    <input type="checkbox" id="bpl-del-images">
                                    <?php esc_html_e('Also delete product images', 'brikpanel'); ?>
                                </label>
                            </div>
                            <div class="brikpanel-pl-modal-divider"></div>
                            <div class="brikpanel-pl-modal-field-label"><?php esc_html_e('Also wipe taxonomies (optional)', 'brikpanel'); ?></div>
                            <div class="bpl-del-tax-grid">
                                <label class="brikpanel-pl-modal-check">
                                    <input type="checkbox" id="bpl-del-cats">
                                    <?php esc_html_e('Categories', 'brikpanel'); ?>
                                </label>
                                <label class="brikpanel-pl-modal-check">
                                    <input type="checkbox" id="bpl-del-tags">
                                    <?php esc_html_e('Tags', 'brikpanel'); ?>
                                </label>
                                <label class="brikpanel-pl-modal-check">
                                    <input type="checkbox" id="bpl-del-attrs">
                                    <?php esc_html_e('Attributes', 'brikpanel'); ?>
                                </label>
                                <label class="brikpanel-pl-modal-check">
                                    <input type="checkbox" id="bpl-del-brands">
                                    <?php esc_html_e('Brands', 'brikpanel'); ?>
                                </label>
                            </div>
                            <p class="brikpanel-pl-modal-hint"><?php esc_html_e('When checked, ALL terms in the selected taxonomies are removed after products are deleted — not only those linked to the deleted products.', 'brikpanel'); ?></p>
                            <div class="brikpanel-pl-modal-divider"></div>
                            <div class="brikpanel-pl-modal-field">
                                <label class="brikpanel-pl-modal-check">
                                    <input type="checkbox" id="bpl-del-fast">
                                    <strong><?php esc_html_e('⚡ Fast delete (advanced)', 'brikpanel'); ?></strong>
                                </label>
                                <p class="brikpanel-pl-modal-hint bpl-fast-hint"><?php esc_html_e('Deletes products directly from the database in seconds. Use for very large stores (5k+ products).', 'brikpanel'); ?></p>
                            </div>
                            <div class="brikpanel-pl-modal-danger-banner bpl-fast-warning" id="bpl-del-fast-warning" style="display:none;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                <div>
                                    <strong><?php esc_html_e('Fast mode bypasses plugin hooks:', 'brikpanel'); ?></strong>
                                    <ul class="bpl-fast-warning-list">
                                        <li><?php esc_html_e('Always permanent — no trash, no undo.', 'brikpanel'); ?></li>
                                        <li><?php esc_html_e('Image files stay in uploads/ as orphans (media library entries are removed but the files on disk are not).', 'brikpanel'); ?></li>
                                        <li><?php esc_html_e('SEO, search index, cache and analytics plugins will not be notified and may show stale data until re-indexed.', 'brikpanel'); ?></li>
                                        <li><?php esc_html_e('Third-party custom tables tied to products will not be cleaned.', 'brikpanel'); ?></li>
                                    </ul>
                                </div>
                            </div>
                            <p class="brikpanel-pl-modal-info" id="bpl-del-sel-info" style="display:none;"></p>
                        </div>

                    </div>
                    <div class="brikpanel-pl-modal-footer">
                        <button type="button" class="brikpanel-pl-btn secondary" id="bpl-bulk-modal-cancel"><?php esc_html_e('Cancel', 'brikpanel'); ?></button>
                        <button type="button" class="brikpanel-pl-btn primary" id="bpl-bulk-modal-apply"><?php esc_html_e('Apply', 'brikpanel'); ?></button>
                    </div>
                </div>
            </div>

            <!-- Bulk Progress Modal -->
            <div class="bpl-progress-backdrop" id="bpl-progress-backdrop" aria-hidden="true">
                <div class="bpl-progress-card" role="dialog" aria-modal="true" aria-labelledby="bpl-progress-title">
                    <div class="bpl-progress-header">
                        <h3 id="bpl-progress-title"><?php esc_html_e('Processing...', 'brikpanel'); ?></h3>
                    </div>
                    <div class="bpl-progress-body">
                        <div class="bpl-progress-bar" aria-hidden="true">
                            <div class="bpl-progress-bar-fill" id="bpl-progress-fill"></div>
                        </div>
                        <div class="bpl-progress-stats">
                            <span id="bpl-progress-stats-text">0 / 0</span>
                            <span id="bpl-progress-percent">0%</span>
                        </div>
                        <p class="bpl-progress-errors" id="bpl-progress-errors" hidden></p>
                    </div>
                    <div class="bpl-progress-footer">
                        <button type="button" class="brikpanel-pl-btn secondary" id="bpl-progress-cancel"><?php esc_html_e('Cancel', 'brikpanel'); ?></button>
                        <button type="button" class="brikpanel-pl-btn primary" id="bpl-progress-done" hidden><?php esc_html_e('Done', 'brikpanel'); ?></button>
                    </div>
                </div>
            </div>

            <!-- Toast Container -->
            <div class="brikpanel-pl-toast-container" id="bpl-toast-container"></div>

        </div>
        </div>
        <?php
    }

    // =========================================================================
    // AJAX: FETCH PRODUCTS
    // =========================================================================

    /**
     * Extend the default WP_Query search so it also matches products by SKU.
     *
     * WordPress core only searches post_title/content/excerpt. WooCommerce
     * stores the SKU in the `_sku` post meta, so without this a merchant
     * cannot locate a product by typing its SKU. We OR an additional clause
     * onto core's generated search SQL that matches:
     *   1. A product whose own `_sku` LIKEs the term, and
     *   2. A product that has a variation whose `_sku` LIKEs the term
     *      (so variable products surface when a child SKU is searched).
     *
     * The injected SQL is fully prepared/escaped; the LIKE value is wrapped
     * with $wpdb->esc_like() so user input cannot break out of the pattern.
     *
     * @param string   $search   The core-generated search SQL fragment.
     * @param WP_Query  $wp_query The query being filtered.
     * @return string
     */
    public static function filter_search_include_sku($search, $wp_query) {
        global $wpdb;

        $term = self::$sku_search_term;
        if ($term === '' || $search === '') {
            return $search;
        }

        $like = '%' . $wpdb->esc_like($term) . '%';

        $sku_clause = $wpdb->prepare(
            "{$wpdb->posts}.ID IN (
                SELECT pm.post_id FROM {$wpdb->postmeta} pm
                WHERE pm.meta_key = '_sku' AND pm.meta_value LIKE %s
            ) OR {$wpdb->posts}.ID IN (
                SELECT v.post_parent FROM {$wpdb->posts} v
                INNER JOIN {$wpdb->postmeta} vpm ON vpm.post_id = v.ID
                WHERE v.post_type = 'product_variation'
                  AND vpm.meta_key = '_sku' AND vpm.meta_value LIKE %s
            )",
            $like,
            $like
        );

        // Core builds $search as " AND (( ...title/content... ))". Strip the
        // leading " AND " and re-wrap so the SKU match is ORed with the
        // original title/content group rather than ANDed.
        $core = preg_replace('/^\s*AND\s+/', '', $search);
        if ($core === null || $core === '') {
            return $search;
        }

        return " AND ( ({$core}) OR ({$sku_clause}) )";
    }

    public function ajax_fetch_products() {
        check_ajax_referer('brikpanel_products_list_nonce', 'security');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $page     = max(1, intval($_POST['page'] ?? 1));
        $per_page = max(1, min(100, intval($_POST['per_page'] ?? 20)));
        $search   = sanitize_text_field($_POST['search'] ?? '');
        $status   = sanitize_key($_POST['status'] ?? 'any');
        $category = intval($_POST['category'] ?? 0);
        $stock_filter = sanitize_key($_POST['stock_filter'] ?? '');
        $product_type = sanitize_key($_POST['product_type'] ?? '');
        $featured     = sanitize_key($_POST['featured'] ?? '');
        $sort     = sanitize_text_field($_POST['sort'] ?? 'date-desc');

        // Parse sort
        $sort_parts = explode('-', $sort);
        $orderby = $sort_parts[0] ?? 'date';
        $order   = strtoupper($sort_parts[1] ?? 'DESC');

        if (!in_array($orderby, ['date', 'title', 'price', 'menu'], true)) {
            $orderby = 'date';
        }
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }

        $statuses = ['any'];
        if ($status === 'publish') {
            $statuses = ['publish'];
        } elseif ($status === 'draft') {
            $statuses = ['draft'];
        } elseif ($status === 'private') {
            $statuses = ['private'];
        } elseif ($status === 'trash') {
            $statuses = ['trash'];
        } else {
            $statuses = ['publish', 'draft', 'private'];
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => $statuses,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => $orderby === 'price' ? 'meta_value_num' : $orderby,
            'order'          => $order,
        ];

        if ($orderby === 'price') {
            $args['meta_key'] = '_price';
        } elseif ($orderby === 'menu') {
            // Custom storefront order: products without an explicit
            // menu_order (defaults to 0) tie-break by title so the list is
            // deterministic and matches WC frontend ordering.
            $args['orderby'] = ['menu_order' => 'ASC', 'title' => 'ASC'];
            unset($args['order']);
        }

        if ($search) {
            $args['s'] = $search;
            // The default WP_Query `s` only scans title/content/excerpt. Extend
            // it to also match a product's own SKU and, for variable products,
            // the SKU of any of its variations (returns the parent product).
            self::$sku_search_term = $search;
            add_filter('posts_search', [__CLASS__, 'filter_search_include_sku'], 10, 2);
        }

        // Build tax_query incrementally so category + type + featured can
        // co-exist; WP_Query ANDs the clauses together when relation is AND
        // (the default with 2+ clauses).
        $tax_query = [];

        if ($category > 0) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $category,
            ];
        }

        if ($product_type !== '') {
            $tax_query[] = [
                'taxonomy' => 'product_type',
                'field'    => 'slug',
                'terms'    => [$product_type],
            ];
        }

        if ($featured === 'yes') {
            $tax_query[] = [
                'taxonomy' => 'product_visibility',
                'field'    => 'name',
                'terms'    => ['featured'],
                'operator' => 'IN',
            ];
        } elseif ($featured === 'no') {
            $tax_query[] = [
                'taxonomy' => 'product_visibility',
                'field'    => 'name',
                'terms'    => ['featured'],
                'operator' => 'NOT IN',
            ];
        }

        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }

        // Stock filter via meta query
        if ($stock_filter === 'outofstock') {
            $args['meta_query'][] = [
                'key'     => '_stock_status',
                'value'   => 'outofstock',
                'compare' => '=',
            ];
        } elseif ($stock_filter === 'instock') {
            $args['meta_query'][] = [
                'key'     => '_stock_status',
                'value'   => 'instock',
                'compare' => '=',
            ];
        } elseif ($stock_filter === 'lowstock') {
            $low_stock_amount = absint(get_option('woocommerce_notify_low_stock_amount', 2));
            $args['meta_query'][] = [
                'relation' => 'AND',
                [
                    'key'     => '_manage_stock',
                    'value'   => 'yes',
                    'compare' => '=',
                ],
                [
                    'key'     => '_stock',
                    'value'   => $low_stock_amount,
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ],
                [
                    'key'     => '_stock',
                    'value'   => 0,
                    'compare' => '>',
                    'type'    => 'NUMERIC',
                ],
            ];
        }

        $query = new WP_Query($args);

        // Scope the SKU search strictly to the query above.
        if ($search) {
            remove_filter('posts_search', [__CLASS__, 'filter_search_include_sku'], 10);
            self::$sku_search_term = '';
        }

        $products = [];

        // Resolve once per request: third-party (ASE etc.) extra columns
        // contributed via manage_{post_type}_posts_columns filter.
        $extra_columns = class_exists('Brikpanel_ASE_Bridge')
            ? Brikpanel_ASE_Bridge::get_extra_columns('product')
            : [];

        // Custom taxonomies surfaced in the quick-edit drawer. Resolved once
        // per request so the per-product loop only iterates over the keys.
        $qe_custom_taxonomies = array_keys(self::get_quick_edit_custom_taxonomies());

        foreach ($query->posts as $post) {
            $product = wc_get_product($post->ID);
            if (!$product) continue;

            $image_id  = $product->get_image_id();
            $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');

            $stock_info = self::compute_stock_info($product);
            $stock_qty  = $stock_info['qty'];

            $cats = wp_get_post_terms($post->ID, 'product_cat', ['fields' => 'all']);
            $cat_names = [];
            $cat_ids   = [];
            if (!is_wp_error($cats)) {
                foreach ($cats as $cat) {
                    // $cat->name is already HTML-encoded by WP's sanitize_term_field()
                    // (display context). Decode here so JS escHtml() doesn't double-encode it.
                    $cat_names[] = html_entity_decode($cat->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $cat_ids[]   = $cat->term_id;
                }
            }

            $tag_terms = wp_get_post_terms($post->ID, 'product_tag', ['fields' => 'all']);
            $tag_ids   = [];
            if (!is_wp_error($tag_terms)) {
                foreach ($tag_terms as $tg) {
                    $tag_ids[] = (int) $tg->term_id;
                }
            }

            // Collect selected term IDs for each opt-in custom taxonomy
            // (Brands etc.) so the drawer can prime its checkboxes without
            // a second roundtrip when the user clicks "Edit".
            $custom_taxonomy_ids = [];
            if (!empty($qe_custom_taxonomies)) {
                foreach ($qe_custom_taxonomies as $tax_slug) {
                    $tax_term_ids = wp_get_post_terms($post->ID, $tax_slug, ['fields' => 'ids']);
                    $custom_taxonomy_ids[$tax_slug] = is_wp_error($tax_term_ids)
                        ? []
                        : array_map('intval', $tax_term_ids);
                }
            }

            // Render extra column cells contributed by ASE / other plugins.
            $extra_cells = [];
            if ($extra_columns) {
                foreach ($extra_columns as $col_id => $col_label) {
                    $extra_cells[$col_id] = Brikpanel_ASE_Bridge::render_cell('product', $col_id, $post->ID);
                }
            }

            $extra_actions = class_exists('Brikpanel_ASE_Bridge')
                ? Brikpanel_ASE_Bridge::get_row_actions($post)
                : [];

            // Always resolve the cell — the cost is one extra postmeta read
            // for simple products and one short IN query for variables. Keeps
            // toggling the column on instantly populated without a refetch.
            $cogs_payload = self::compute_cogs_display($product);

            $products[] = [
                'id'             => $post->ID,
                'name'           => $product->get_name() ?? '',
                'sku'            => $product->get_sku() ?? '',
                'global_unique_id' => self::compute_global_unique_id_display($product),
                'regular_price'  => $product->get_regular_price(),
                'sale_price'     => $product->get_sale_price(),
                'price_html'     => $product->get_price_html(),
                'cogs'           => $cogs_payload,
                'cogs_value'     => (string) get_post_meta($post->ID, '_brikpanel_cogs', true),
                'stock'          => $stock_qty,
                'stock_status'   => $product->get_stock_status(),
                'manage_stock'   => $stock_info['manage_stock'],
                'backorders'     => $stock_info['backorders'],
                'status'         => $post->post_status,
                'image'          => $image_url,
                'categories'     => $cat_names,
                'category_ids'   => $cat_ids,
                'tag_ids'        => $tag_ids,
                'custom_taxonomies' => (object) $custom_taxonomy_ids,
                'type'           => $product->get_type(),
                'is_featured'    => $product->is_featured(),
                'is_downloadable' => $product->is_downloadable(),
                'is_virtual'     => $product->is_virtual(),
                'downloads'      => self::serialize_downloads($product),
                'edit_url'       => admin_url('admin.php?page=brikpanel-product-editor&product_id=' . $post->ID),
                'view_url'       => get_permalink($post->ID),
                'extra_cells'    => (object) $extra_cells,
                'extra_actions'  => $extra_actions,
            ];
        }

        // Refresh counts
        $counts = wp_count_posts('product');
        $publish_count = isset($counts->publish) ? (int) $counts->publish : 0;
        $draft_count   = isset($counts->draft) ? (int) $counts->draft : 0;
        $private_count = isset($counts->private) ? (int) $counts->private : 0;
        $trash_count   = isset($counts->trash) ? (int) $counts->trash : 0;

        // Visibility state for the extra (3rd-party) columns. Resolved here
        // because AJAX context has those plugin hooks registered (some, like
        // SEOPress, only register during wp_doing_ajax() / edit.php).
        $extra_state = [];
        if (!empty($extra_columns)) {
            $col_state_now = self::get_user_columns();
            foreach ($extra_columns as $col_id => $col_label) {
                $extra_state[$col_id] = !empty($col_state_now[$col_id]);
            }
        }

        wp_send_json_success([
            'products'      => $products,
            'total'         => (int) $query->found_posts,
            'pages'         => (int) $query->max_num_pages,
            'page'          => $page,
            'extra_columns' => (object) $extra_columns,
            'extra_columns_state' => (object) $extra_state,
            'counts'        => [
                'all'     => $publish_count + $draft_count + $private_count,
                'publish' => $publish_count,
                'draft'   => $draft_count,
                'private' => $private_count,
                'trash'   => $trash_count,
            ],
        ]);
    }

    // =========================================================================
    // AJAX: SAVE PRODUCT ORDER (drag-to-sort)
    // =========================================================================

    /**
     * Persists the storefront display order set by drag-and-drop in the
     * products list. Receives an ordered array of product IDs from the
     * current page; assigns each one a menu_order starting at the page's
     * offset (page-1 * per_page) so order is preserved across pages.
     *
     * Mirrors the contract of WooCommerce's native sortable list: only the
     * IDs visible on the page are reflowed, and untouched products keep
     * their existing menu_order — collisions are tie-broken by title in
     * ajax_fetch_products(), so the global ordering remains stable.
     *
     * Direct UPDATE on wp_posts (no wp_update_post) because (1) menu_order
     * does not need post-meta side effects, (2) avoids triggering
     * save_post hooks that could rebuild expensive indexes / clear caches
     * on every drag, and (3) matches what WC_Admin_Post_Types does.
     */
    public function ajax_save_product_order() {
        check_ajax_referer('brikpanel_products_list_nonce', 'security');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')], 403);
        }

        $raw_ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
        $ids = [];
        foreach ($raw_ids as $id) {
            $id = absint($id);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        if (empty($ids)) {
            wp_send_json_error(['message' => __('No products to reorder.', 'brikpanel')]);
        }

        // Cap at the bulk-job ceiling: same input class as the bulk update
        // pipeline, same defensive bound. A single page should never come
        // close to this in practice.
        if (count($ids) > self::BULK_MAX_IDS) {
            wp_send_json_error(['message' => __('Too many products to reorder in a single request.', 'brikpanel')]);
        }

        $base = max(0, intval($_POST['base'] ?? 0));

        global $wpdb;
        $updated = 0;

        // Buffer any incidental output triggered by cache-purge hooks on
        // 3rd-party plugins (e.g. WP Rocket's missing-table notices) so
        // the JSON response isn't corrupted by stray HTML.
        ob_start();

        foreach ($ids as $idx => $id) {
            $new_order = $base + $idx;

            // Verify the post is actually a product the user can edit
            // before mutating menu_order — prevents passing arbitrary post
            // IDs from the client.
            $post = get_post($id);
            if (!$post || $post->post_type !== 'product') {
                continue;
            }
            if (!current_user_can('edit_post', $id)) {
                continue;
            }

            $result = $wpdb->update(
                $wpdb->posts,
                ['menu_order' => $new_order],
                ['ID' => $id],
                ['%d'],
                ['%d']
            );

            if ($result !== false) {
                clean_post_cache($id);
                $updated++;
            }
        }

        // Storefront product loops cache the ordered ID set; bust it so
        // the new order is visible on the front-end immediately.
        if (function_exists('wc_delete_product_transients')) {
            foreach ($ids as $id) {
                wc_delete_product_transients($id);
            }
        }

        ob_end_clean();

        wp_send_json_success([
            'updated' => $updated,
            'message' => __('Order saved.', 'brikpanel'),
        ]);
    }

    // =========================================================================
    // AJAX: QUICK EDIT
    // =========================================================================

    public function ajax_quick_edit() {
        check_ajax_referer('brikpanel_products_list_nonce', 'security');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        $product    = wc_get_product($product_id);

        if (!$product) {
            wp_send_json_error(['message' => __('Product not found.', 'brikpanel')]);
        }

        // Update fields that were sent
        if (isset($_POST['name'])) {
            $name = sanitize_text_field($_POST['name']);
            if ($name) {
                $product->set_name($name);
            }
        }

        if (isset($_POST['regular_price'])) {
            $product->set_regular_price(wc_format_decimal(sanitize_text_field($_POST['regular_price'])));
        }

        if (isset($_POST['sale_price'])) {
            $sale = sanitize_text_field($_POST['sale_price']);
            $product->set_sale_price($sale !== '' ? wc_format_decimal($sale) : '');
        }

        if (isset($_POST['stock']) && $_POST['stock'] !== '') {
            $stock = intval($_POST['stock']);
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock);
            // If stock_status is not explicitly sent (e.g. inline row edit),
            // auto-derive it from the quantity so the row badge stays consistent.
            if (!isset($_POST['stock_status'])) {
                $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
            }
        } elseif (isset($_POST['stock']) && $_POST['stock'] === '') {
            $product->set_manage_stock(false);
        }

        if (isset($_POST['stock_status'])) {
            $stock_status = sanitize_key($_POST['stock_status']);
            if (in_array($stock_status, ['instock', 'outofstock', 'onbackorder'], true)) {
                $product->set_stock_status($stock_status);
                $product->set_backorders($stock_status === 'onbackorder' ? 'yes' : 'no');
            }
        }

        if (isset($_POST['sku'])) {
            try {
                $product->set_sku(sanitize_text_field($_POST['sku']));
            } catch (\Exception $e) {
                wp_send_json_error(['message' => $e->getMessage()]);
            }
        }

        if (isset($_POST['status'])) {
            $status = sanitize_key($_POST['status']);
            if (in_array($status, ['publish', 'draft', 'private'], true)) {
                wp_update_post(['ID' => $product_id, 'post_status' => $status]);
            }
        }

        // Categories: prefer the drawer's CSV payload (which round-trips empty
        // selections so "deselect all" actually clears the taxonomy). Fall
        // back to the legacy array or single-id payloads for older callers.
        if (isset($_POST['category_ids_csv'])) {
            $csv = sanitize_text_field(wp_unslash($_POST['category_ids_csv']));
            $cat_ids = $csv === '' ? [] : array_values(array_unique(array_filter(array_map('intval', explode(',', $csv)))));
            wp_set_object_terms($product_id, $cat_ids, 'product_cat');
        } elseif (isset($_POST['category_ids']) && is_array($_POST['category_ids'])) {
            $cat_ids = array_values(array_unique(array_filter(array_map('intval', $_POST['category_ids']))));
            wp_set_object_terms($product_id, $cat_ids, 'product_cat');
        } elseif (isset($_POST['category_id'])) {
            $cat_id = intval($_POST['category_id']);
            if ($cat_id > 0) {
                wp_set_object_terms($product_id, [$cat_id], 'product_cat');
            } else {
                wp_set_object_terms($product_id, [], 'product_cat');
            }
        }

        if (isset($_POST['tag_ids_csv'])) {
            $csv = sanitize_text_field(wp_unslash($_POST['tag_ids_csv']));
            $tag_ids_in = $csv === '' ? [] : array_values(array_unique(array_filter(array_map('intval', explode(',', $csv)))));
            wp_set_object_terms($product_id, $tag_ids_in, 'product_tag');
        } elseif (isset($_POST['tag_ids']) && is_array($_POST['tag_ids'])) {
            $tag_ids_in = array_values(array_unique(array_filter(array_map('intval', $_POST['tag_ids']))));
            wp_set_object_terms($product_id, $tag_ids_in, 'product_tag');
        }

        // Custom product taxonomies (Brands, etc.). The drawer sends one CSV
        // per taxonomy under `custom_tax_ids[<slug>]` so empty selections still
        // round-trip and "deselect all" actually clears the assignment. Only
        // slugs in the enabled allowlist are honoured to keep the endpoint
        // narrow even if a stale client sends extras.
        if (isset($_POST['custom_tax_ids']) && is_array($_POST['custom_tax_ids'])) {
            $allowed_custom_taxes = self::get_quick_edit_custom_taxonomies();
            foreach ($_POST['custom_tax_ids'] as $raw_slug => $raw_csv) {
                $tax_slug = sanitize_key($raw_slug);
                if ($tax_slug === '' || !isset($allowed_custom_taxes[$tax_slug])) {
                    continue;
                }
                $tax_object = $allowed_custom_taxes[$tax_slug];
                if (!empty($tax_object->cap->assign_terms) && !current_user_can($tax_object->cap->assign_terms)) {
                    continue;
                }
                $csv = sanitize_text_field(wp_unslash($raw_csv));
                $term_ids = $csv === ''
                    ? []
                    : array_values(array_unique(array_filter(array_map('intval', explode(',', $csv)))));
                wp_set_object_terms($product_id, $term_ids, $tax_slug);
            }
        }

        // Virtual / Digital product. Only mutate when the drawer explicitly
        // sends one of these flags so callers that don't surface the type UI
        // (legacy inline edits) leave the flags untouched. The drawer hides
        // these toggles for variable products entirely — that case is handled
        // through the full editor where per-variation propagation happens.
        if ((isset($_POST['is_downloadable']) || isset($_POST['is_virtual'])) && !$product->is_type('variable')) {
            $is_downloadable = !empty($_POST['is_downloadable']);
            $is_virtual      = !empty($_POST['is_virtual']) || $is_downloadable;
            $product->set_downloadable($is_downloadable);
            $product->set_virtual($is_virtual);
            if ($is_downloadable) {
                $downloads_json = isset($_POST['downloads']) ? wp_unslash($_POST['downloads']) : '[]';
                $downloads_data = json_decode($downloads_json, true);
                $download_objects = [];
                if (is_array($downloads_data)) {
                    foreach ($downloads_data as $d) {
                        $file = esc_url_raw($d['file'] ?? '');
                        $name = sanitize_text_field($d['name'] ?? '');
                        if (!$file) continue;
                        $download = new WC_Product_Download();
                        $dl_id = !empty($d['id']) ? sanitize_text_field($d['id']) : wp_generate_uuid4();
                        $download->set_id($dl_id);
                        $download->set_name($name ?: basename($file));
                        $download->set_file($file);
                        $download_objects[] = $download;
                    }
                }
                $product->set_downloads($download_objects);
                $product->set_download_limit(-1);
                $product->set_download_expiry(-1);
            } else {
                $product->set_downloads([]);
            }
        }

        // COGS — saved to _brikpanel_cogs for BrikPanel reports (and mirrored
        // to WC 9.5+ native COGS when available). Only touched when the drawer
        // explicitly sent the field; the JS omits the key when the COGS row is
        // hidden, so absence here means "the drawer never rendered this
        // control" and we must leave any existing cost on file alone. Mirrors
        // the simplified product editor's save logic.
        if (array_key_exists('cogs_value', $_POST)) {
            $cogs_raw     = sanitize_text_field($_POST['cogs_value']);
            $cogs_decimal = $cogs_raw !== '' ? wc_format_decimal($cogs_raw) : '';
            if ($cogs_decimal !== '') {
                update_post_meta($product->get_id(), '_brikpanel_cogs', $cogs_decimal);
            } else {
                delete_post_meta($product->get_id(), '_brikpanel_cogs');
            }
            if (method_exists($product, 'set_cogs_value')) {
                $product->set_cogs_value($cogs_decimal !== '' ? $cogs_decimal : null);
            }
        }

        $product->save();

        // If the caller explicitly requested a stock_status, enforce it after
        // save. WC core's WC_Product::validate_props() auto-syncs stock_status
        // from quantity during save (stock > 0 → "instock"), which would
        // override the user's choice. Writing the meta directly bypasses the
        // prop-sync logic so the explicit value sticks. We also fire
        // `woocommerce_product_set_stock_status` so any listeners that depend
        // on the change still see it.
        if (isset($_POST['stock_status'])) {
            $requested_status = sanitize_key($_POST['stock_status']);
            if (in_array($requested_status, ['instock', 'outofstock', 'onbackorder'], true)) {
                global $wpdb;
                $backorders = $requested_status === 'onbackorder' ? 'yes' : 'no';

                // Collect every post that must mirror the requested status.
                // Variable products: storefront availability is derived from
                // the *variations*, and WC's deferred `do_deferred_product_sync`
                // on shutdown reads `wc_product_meta_lookup.stock_status` to
                // recompute the parent — updating only the parent meta
                // without the children (and without the lookup table) leaves
                // the shop out of sync with what the admin just clicked.
                $targets = [$product_id];
                if ($product->is_type('variable')) {
                    foreach ($product->get_children() as $child_id) {
                        $targets[] = (int) $child_id;
                    }
                }

                foreach ($targets as $pid) {
                    update_post_meta($pid, '_stock_status', $requested_status);
                    update_post_meta($pid, '_backorders', $backorders);
                    // Keep the meta lookup table in lockstep so WC's sync
                    // check and product-query filters both see the new
                    // value.
                    $wpdb->update(
                        $wpdb->wc_product_meta_lookup,
                        ['stock_status' => $requested_status],
                        ['product_id'   => $pid],
                        ['%s'],
                        ['%d']
                    );
                    clean_post_cache($pid);
                    wp_cache_delete('product-' . $pid, 'products');
                    wc_delete_product_transients($pid);
                    do_action('woocommerce_product_set_stock_status', $pid, $requested_status, wc_get_product($pid));
                }
            }
        }

        // Return updated product data
        $product = wc_get_product($product_id);
        $image_id  = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');

        $cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'all']);
        $cat_names = [];
        $cat_ids   = [];
        if (!is_wp_error($cats)) {
            foreach ($cats as $cat) {
                $cat_names[] = html_entity_decode($cat->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $cat_ids[]   = $cat->term_id;
            }
        }

        $tag_terms_qe = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'all']);
        $tag_ids_qe   = [];
        if (!is_wp_error($tag_terms_qe)) {
            foreach ($tag_terms_qe as $tg) {
                $tag_ids_qe[] = (int) $tg->term_id;
            }
        }

        // Refresh custom taxonomy assignments so the client cache stays in
        // lockstep with the database after the save.
        $custom_taxonomy_ids_qe = [];
        $qe_custom_taxonomies_after = array_keys(self::get_quick_edit_custom_taxonomies());
        foreach ($qe_custom_taxonomies_after as $tax_slug) {
            $tax_term_ids = wp_get_post_terms($product_id, $tax_slug, ['fields' => 'ids']);
            $custom_taxonomy_ids_qe[$tax_slug] = is_wp_error($tax_term_ids)
                ? []
                : array_map('intval', $tax_term_ids);
        }

        $stock_info_qe = self::compute_stock_info($product);

        wp_send_json_success([
            'message' => __('Product updated!', 'brikpanel'),
            'product' => [
                'id'              => $product_id,
                'name'            => $product->get_name() ?? '',
                'sku'             => $product->get_sku() ?? '',
                'regular_price'   => $product->get_regular_price(),
                'sale_price'      => $product->get_sale_price(),
                'price_html'      => $product->get_price_html(),
                'cogs'            => self::compute_cogs_display($product),
                'cogs_value'      => (string) get_post_meta($product_id, '_brikpanel_cogs', true),
                'stock'           => $stock_info_qe['qty'],
                'stock_status'    => $product->get_stock_status(),
                'manage_stock'    => $stock_info_qe['manage_stock'],
                'backorders'      => $stock_info_qe['backorders'],
                'status'          => get_post_status($product_id),
                'image'           => $image_url,
                'categories'      => $cat_names,
                'category_ids'    => $cat_ids,
                'tag_ids'         => $tag_ids_qe,
                'custom_taxonomies' => (object) $custom_taxonomy_ids_qe,
                'type'            => $product->get_type(),
                'is_downloadable' => $product->is_downloadable(),
                'is_virtual'      => $product->is_virtual(),
                'downloads'       => self::serialize_downloads($product),
                'edit_url'        => admin_url('admin.php?page=brikpanel-product-editor&product_id=' . $product_id),
                'view_url'        => get_permalink($product_id),
            ],
        ]);
    }

    // =========================================================================
    // AJAX: BULK ACTION
    // =========================================================================

    public function ajax_bulk_action() {
        check_ajax_referer('brikpanel_products_list_nonce', 'security');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $action      = sanitize_key($_POST['bulk_action'] ?? '');
        $product_ids = array_map('intval', (array) ($_POST['product_ids'] ?? []));

        if (empty($product_ids) || !$action) {
            wp_send_json_error(['message' => __('Invalid request.', 'brikpanel')]);
        }

        // Buffer everything wp_delete_post / wp_trash_post (and any third-party
        // hooks like cache plugins) might echo. A stray PHP notice or DB error
        // ahead of wp_send_json_* poisons the JSON body and breaks the client
        // success path.
        ob_start();

        $count = 0;
        foreach ($product_ids as $pid) {
            if (!$pid || !current_user_can('edit_post', $pid)) continue;

            switch ($action) {
                case 'publish':
                    wp_update_post(['ID' => $pid, 'post_status' => 'publish']);
                    $count++;
                    break;
                case 'draft':
                    wp_update_post(['ID' => $pid, 'post_status' => 'draft']);
                    $count++;
                    break;
                case 'trash':
                    wp_trash_post($pid);
                    $count++;
                    break;
                case 'restore':
                    wp_untrash_post($pid);
                    $count++;
                    break;
                case 'delete':
                    if (current_user_can('delete_post', $pid)) {
                        wp_delete_post($pid, true);
                        $count++;
                    }
                    break;
            }
        }

        // Bust wp_count_posts() cache so the silent fetch that follows this
        // request returns fresh tab counters. WP normally invalidates these
        // via _transition_post_status, but persistent object caches and some
        // hosting setups miss the invalidation, leaving the user staring at
        // stale "Trash 6" badges after the rows themselves have updated.
        if (function_exists('_count_posts_cache_key')) {
            $cache_keys = [
                _count_posts_cache_key('product', 'readable'),
                _count_posts_cache_key('product', ''),
            ];
            foreach (array_unique(array_filter($cache_keys)) as $cache_key) {
                wp_cache_delete($cache_key, 'counts');
            }
        }

        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: number of products affected */
                __('%d products updated.', 'brikpanel'),
                $count
            ),
            'count' => $count,
        ]);
    }

    // =========================================================================
    // AJAX: DELETE PRODUCT
    // =========================================================================

    public function ajax_delete_product() {
        check_ajax_referer('brikpanel_products_list_nonce', 'security');

        $product_id = intval($_POST['product_id'] ?? 0);

        if (!$product_id || !current_user_can('delete_post', $product_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $status = get_post_status($product_id);

        // Capture any output emitted by delete/trash hooks (cache plugins,
        // PHP notices) so it cannot corrupt the JSON response body.
        ob_start();

        if ($status === 'trash') {
            wp_delete_post($product_id, true);
            $message = __('Product permanently deleted.', 'brikpanel');
        } else {
            wp_trash_post($product_id);
            $message = __('Product moved to trash.', 'brikpanel');
        }

        // Same count-cache busting as ajax_bulk_action — keep tab badges in
        // sync when persistent object caches miss the core invalidation.
        if (function_exists('_count_posts_cache_key')) {
            $cache_keys = [
                _count_posts_cache_key('product', 'readable'),
                _count_posts_cache_key('product', ''),
            ];
            foreach (array_unique(array_filter($cache_keys)) as $cache_key) {
                wp_cache_delete($cache_key, 'counts');
            }
        }

        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        wp_send_json_success(['message' => $message]);
    }

    // =========================================================================
    // AJAX: TOGGLE STATUS
    // =========================================================================

    public function ajax_toggle_status() {
        check_ajax_referer('brikpanel_products_list_nonce', 'security');

        $product_id = intval($_POST['product_id'] ?? 0);

        if (!$product_id || !current_user_can('edit_post', $product_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $current = get_post_status($product_id);
        $new_status = $current === 'publish' ? 'draft' : 'publish';

        wp_update_post(['ID' => $product_id, 'post_status' => $new_status]);

        $label = $new_status === 'publish' ? __('Published', 'brikpanel') : __('Draft', 'brikpanel');

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %s: new status label */
                __('Status changed to %s.', 'brikpanel'),
                $label
            ),
            'status' => $new_status,
        ]);
    }

    /**
     * Inline featured-product toggle from the products list star icon.
     * Backed by the same `featured` term in `product_visibility` taxonomy
     * that powers the editor header star, WC core, and the storefront
     * "featured" widgets — single source of truth.
     */
    public function ajax_toggle_featured() {
        check_ajax_referer('brikpanel_products_list_nonce', 'security');

        $product_id = intval($_POST['product_id'] ?? 0);

        if (!$product_id || !current_user_can('edit_post', $product_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['message' => __('Product not found.', 'brikpanel')]);
        }

        $new_state = !$product->is_featured();
        $product->set_featured($new_state);
        $product->save();

        wp_send_json_success([
            'is_featured' => $new_state,
            'message'     => $new_state
                ? __('Marked as featured.', 'brikpanel')
                : __('Removed from featured.', 'brikpanel'),
        ]);
    }

    // =========================================================================
    // AJAX: BULK JOBS (batched prepare/process/cancel)
    // =========================================================================

    private static $bulk_update_actions = [
        'set_regular_price',
        'set_sale_price',
        'increase_price_percent',
        'decrease_price_percent',
        'sale_from_regular_percent',
        'set_stock',
        'increase_stock',
        'remove_sale_price',
        'set_weight',
        'set_length',
        'set_width',
        'set_height',
    ];

    /**
     * PREPARE phase — resolves target parent product IDs once,
     * stores the job in a transient, returns the job ID + total.
     */
    public function ajax_bulk_job_prepare() {
        check_ajax_referer('brikpanel_products_list_nonce', 'security');

        $job_type = sanitize_key($_POST['job_type'] ?? '');
        if (!in_array($job_type, ['update', 'delete'], true)) {
            wp_send_json_error(['message' => __('Invalid job type.', 'brikpanel')]);
        }

        $required_cap = $job_type === 'delete' ? 'delete_products' : 'edit_products';
        if (!current_user_can($required_cap)) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $mode = sanitize_key($_POST['mode'] ?? 'selected');

        // Detect taxonomy-purge intent early so we can allow an empty
        // product set when the user only wants to wipe taxonomies.
        $has_tax_purge = $job_type === 'delete' && (
            ($_POST['delete_cats']   ?? '0') === '1' ||
            ($_POST['delete_tags']   ?? '0') === '1' ||
            ($_POST['delete_attrs']  ?? '0') === '1' ||
            ($_POST['delete_brands'] ?? '0') === '1'
        );

        // Resolve parent product IDs.
        $ids = [];
        if ($mode === 'selected') {
            $raw = sanitize_text_field($_POST['selected_ids'] ?? '');
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
            if (empty($ids) && !$has_tax_purge) {
                wp_send_json_error(['message' => __('No products selected.', 'brikpanel')]);
            }
        } elseif ($mode === 'category') {
            $cat_id = intval($_POST['category'] ?? 0);
            if ($cat_id < 1) {
                if (!$has_tax_purge) {
                    wp_send_json_error(['message' => __('Please select a category.', 'brikpanel')]);
                }
            } else {
                $statuses = $job_type === 'delete'
                    ? ['publish', 'draft', 'pending', 'private', 'trash']
                    : 'publish';
                $ids = get_posts([
                    'post_type'      => 'product',
                    'post_status'    => $statuses,
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'tax_query'      => [[
                        'taxonomy' => 'product_cat',
                        'field'    => 'term_id',
                        'terms'    => $cat_id,
                    ]],
                    'no_found_rows'  => true,
                ]);
            }
        } elseif ($mode === 'all' && $job_type === 'delete') {
            $ids = get_posts([
                'post_type'      => 'product',
                'post_status'    => ['publish', 'draft', 'pending', 'private', 'trash'],
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
        } elseif ($mode === 'all' && $job_type === 'update') {
            $ids = get_posts([
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
        } else {
            wp_send_json_error(['message' => __('Invalid mode.', 'brikpanel')]);
        }

        if (empty($ids) && !$has_tax_purge) {
            wp_send_json_error(['message' => __('No products found.', 'brikpanel')]);
        }

        // Hard cap to keep the job transient and per-batch slicing bounded on
        // very large stores. Operators on huge catalogs can raise via filter.
        $max_ids = (int) apply_filters('brikpanel_bulk_max_ids', self::BULK_MAX_IDS, $job_type);
        if (count($ids) > $max_ids) {
            $ids = array_slice($ids, 0, $max_ids);
        }

        // Collect and validate params per job type.
        $params = [];
        if ($job_type === 'update') {
            $action_raw = sanitize_text_field(wp_unslash($_POST['bulk_action'] ?? ''));

            // Taxonomy actions are encoded as `tax_<set|add>__<taxonomy_slug>`.
            if (preg_match('/^tax_(set|add)__([a-z0-9_\-]+)$/i', $action_raw, $m)) {
                $tax_op   = strtolower($m[1]);
                $taxonomy = sanitize_key($m[2]);
                $allowed  = self::get_bulk_taxonomies();
                if (!isset($allowed[$taxonomy])) {
                    wp_send_json_error(['message' => __('Invalid taxonomy.', 'brikpanel')]);
                }
                $tax_obj = $allowed[$taxonomy];
                if (is_object($tax_obj) && !empty($tax_obj->cap->assign_terms) && !current_user_can($tax_obj->cap->assign_terms)) {
                    wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
                }
                $term_csv = sanitize_text_field(wp_unslash($_POST['term_ids'] ?? ''));
                $term_ids = $term_csv === ''
                    ? []
                    : array_values(array_unique(array_filter(array_map('intval', explode(',', $term_csv)))));
                if ($tax_op === 'add' && empty($term_ids)) {
                    wp_send_json_error(['message' => __('Please select at least one term to add.', 'brikpanel')]);
                }
                $params = [
                    'action'   => 'taxonomy',
                    'tax_op'   => $tax_op,
                    'taxonomy' => $taxonomy,
                    'term_ids' => $term_ids,
                ];
            } else {
                $action = sanitize_key($action_raw);
                if (!in_array($action, self::$bulk_update_actions, true)) {
                    wp_send_json_error(['message' => __('Invalid action.', 'brikpanel')]);
                }
                $params = [
                    'action'   => $action,
                    'value'    => sanitize_text_field($_POST['value'] ?? ''),
                    'attr_key' => sanitize_text_field($_POST['attr_key'] ?? ''),
                    'attr_val' => sanitize_text_field($_POST['attr_val'] ?? ''),
                ];
            }
        } else { // delete
            $fast = ($_POST['fast'] ?? '0') === '1';
            $params = [
                'permanent'     => $fast ? true : (($_POST['permanent'] ?? '0') === '1'),
                'delete_images' => $fast ? false : (($_POST['delete_images'] ?? '0') === '1'),
                'fast'          => $fast,
                'delete_cats'   => ($_POST['delete_cats'] ?? '0') === '1',
                'delete_tags'   => ($_POST['delete_tags'] ?? '0') === '1',
                'delete_attrs'  => ($_POST['delete_attrs'] ?? '0') === '1',
                'delete_brands' => ($_POST['delete_brands'] ?? '0') === '1',
            ];
        }

        $job_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid('', true));
        $payload = [
            'type'       => $job_type,
            'ids'        => array_values(array_map('intval', $ids)),
            'params'     => $params,
            'created_by' => get_current_user_id(),
            'created_at' => time(),
        ];

        set_transient(self::BULK_JOB_TRANSIENT . $job_id, $payload, self::BULK_JOB_TTL);

        $is_fast    = $job_type === 'delete' && !empty($params['fast']);
        $batch_size = $job_type === 'delete'
            ? ($is_fast ? self::BULK_BATCH_DELETE_FAST : self::BULK_BATCH_DELETE)
            : self::BULK_BATCH_UPDATE;
        $batch_size = (int) apply_filters('brikpanel_bulk_batch_size', $batch_size, $job_type);

        wp_send_json_success([
            'job_id'     => $job_id,
            'total'      => count($payload['ids']),
            'batch_size' => max(1, $batch_size),
            'type'       => $job_type,
        ]);
    }

    /**
     * PROCESS phase — loads the job transient, slices the next batch,
     * runs the per-product logic, returns progress.
     */
    public function ajax_bulk_job_process() {
        check_ajax_referer('brikpanel_products_list_nonce', 'security');

        $job_id = sanitize_text_field($_POST['job_id'] ?? '');
        if (!preg_match('/^[a-f0-9\-]{32,36}$/', $job_id)) {
            wp_send_json_error(['message' => __('Invalid job id.', 'brikpanel')]);
        }

        $payload = get_transient(self::BULK_JOB_TRANSIENT . $job_id);
        if (!is_array($payload) || empty($payload['type']) || !isset($payload['ids'])) {
            wp_send_json_error(['message' => __('Job not found or expired.', 'brikpanel'), 'expired' => true]);
        }

        if ((int) $payload['created_by'] !== get_current_user_id()) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $job_type = $payload['type'];
        $required_cap = $job_type === 'delete' ? 'delete_products' : 'edit_products';
        if (!current_user_can($required_cap)) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $total  = count($payload['ids']);
        $offset = max(0, intval($_POST['offset'] ?? 0));

        $is_fast    = $job_type === 'delete' && !empty($payload['params']['fast']);
        $batch_size = $job_type === 'delete'
            ? ($is_fast ? self::BULK_BATCH_DELETE_FAST : self::BULK_BATCH_DELETE)
            : self::BULK_BATCH_UPDATE;
        $batch_size = (int) apply_filters('brikpanel_bulk_batch_size', $batch_size, $job_type);
        $batch_size = max(1, $batch_size);

        $slice = array_slice($payload['ids'], $offset, $batch_size);

        // Try to extend time limit but don't depend on it — many shared hosts
        // disable set_time_limit entirely (disable_functions or safe_mode
        // residue). Batch sizes are tuned to fit a 30s window without it.
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        wp_suspend_cache_addition(true);
        wp_defer_term_counting(true);

        if ($job_type === 'update') {
            $result = $this->process_update_batch($slice, $payload['params']);
        } elseif ($is_fast) {
            $result = $this->process_delete_batch_fast($slice);
        } else {
            $result = $this->process_delete_batch($slice, $payload['params']);
        }

        wp_defer_term_counting(false);
        wp_suspend_cache_addition(false);

        $next_offset = $offset + count($slice);
        $done        = $next_offset >= $total;

        if ($done) {
            delete_transient(self::BULK_JOB_TRANSIENT . $job_id);
            wc_delete_product_transients();
            if ($job_type === 'delete') {
                $this->purge_taxonomies($payload['params']);
            }
            if ($is_fast) {
                $this->fast_delete_finalize();
            }
        }

        wp_send_json_success([
            'processed'   => $result['processed'],
            'next_offset' => $next_offset,
            'total'       => $total,
            'done'        => $done,
            'errors'      => $result['errors'],
        ]);
    }

    /**
     * CANCEL — delete the job transient so the client loop stops.
     */
    public function ajax_bulk_job_cancel() {
        check_ajax_referer('brikpanel_products_list_nonce', 'security');

        $job_id = sanitize_text_field($_POST['job_id'] ?? '');
        if (!preg_match('/^[a-f0-9\-]{32,36}$/', $job_id)) {
            wp_send_json_error(['message' => __('Invalid job id.', 'brikpanel')]);
        }

        $payload = get_transient(self::BULK_JOB_TRANSIENT . $job_id);
        if (is_array($payload) && (int) ($payload['created_by'] ?? 0) === get_current_user_id()) {
            delete_transient(self::BULK_JOB_TRANSIENT . $job_id);
        }

        wp_send_json_success(['cancelled' => true]);
    }

    // =========================================================================
    // EXPORT SELECTED PRODUCTS (CSV download)
    // =========================================================================

    /**
     * Stream a CSV file containing only the selected product IDs.
     * Triggered via admin-post so the browser receives a real file download.
     */
    public function handle_export_selected() {
        if (!wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'brikpanel_export_selected')) {
            wp_die(__('Invalid security token.', 'brikpanel'), 403);
        }

        if (!current_user_can('export')) {
            wp_die(__('Permission denied.', 'brikpanel'), 403);
        }

        $raw = sanitize_text_field($_REQUEST['product_ids'] ?? '');
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));

        if (empty($ids)) {
            wp_die(__('No products selected.', 'brikpanel'));
        }

        // CSV columns — mirrors WooCommerce core exporter essentials
        $columns = [
            'ID',
            'Type',
            'SKU',
            'Name',
            'Published',
            'Short description',
            'Description',
            'Regular price',
            'Sale price',
            'Stock status',
            'Stock',
            'Weight',
            'Length',
            'Width',
            'Height',
            'Categories',
            'Tags',
            'Images',
        ];

        $filename = 'brikpanel-products-export-' . gmdate('Y-m-d-His') . '.csv';

        // Headers for CSV download
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // UTF-8 BOM for Excel compatibility
        fwrite($output, "\xEF\xBB\xBF");

        // Header row
        fputcsv($output, $columns);

        // Process in chunks to keep memory low
        $chunk_size = 50;
        $chunks = array_chunk($ids, $chunk_size);

        foreach ($chunks as $chunk) {
            $products = wc_get_products([
                'include' => $chunk,
                'limit'   => $chunk_size,
                'orderby' => 'include',
            ]);

            foreach ($products as $product) {
                /** @var WC_Product $product */
                $type = $product->get_type();

                // Categories
                $cat_ids = $product->get_category_ids();
                $cats    = [];
                foreach ($cat_ids as $cid) {
                    $term = get_term($cid, 'product_cat');
                    if ($term && !is_wp_error($term)) {
                        $cats[] = $term->name;
                    }
                }

                // Tags
                $tag_ids = $product->get_tag_ids();
                $tags    = [];
                foreach ($tag_ids as $tid) {
                    $term = get_term($tid, 'product_tag');
                    if ($term && !is_wp_error($term)) {
                        $tags[] = $term->name;
                    }
                }

                // Images
                $image_urls = [];
                $main_img   = wp_get_attachment_url($product->get_image_id());
                if ($main_img) {
                    $image_urls[] = $main_img;
                }
                $gallery = $product->get_gallery_image_ids();
                foreach ($gallery as $gid) {
                    $url = wp_get_attachment_url($gid);
                    if ($url) {
                        $image_urls[] = $url;
                    }
                }

                // Price handling for variable products
                $regular_price = $product->get_regular_price();
                $sale_price    = $product->get_sale_price();

                $row = [
                    $product->get_id(),
                    $type,
                    $product->get_sku(),
                    $product->get_name(),
                    $product->get_status() === 'publish' ? 1 : 0,
                    $product->get_short_description(),
                    $product->get_description(),
                    $regular_price,
                    $sale_price,
                    $product->get_stock_status(),
                    $product->get_stock_quantity(),
                    $product->get_weight(),
                    $product->get_length(),
                    $product->get_width(),
                    $product->get_height(),
                    implode(', ', $cats),
                    implode(', ', $tags),
                    implode(', ', $image_urls),
                ];

                fputcsv($output, $row);
            }

            // Free memory between chunks
            unset($products);
        }

        fclose($output);
        exit;
    }

    /**
     * Per-batch worker for bulk update (price/stock).
     * Extracted from the old ajax_bulk_update() loop so it can run in AJAX chunks.
     */
    private function process_update_batch(array $parent_ids, array $params) {
        $action   = $params['action'] ?? '';
        $value    = $params['value'] ?? '';
        $attr_key = $params['attr_key'] ?? '';
        $attr_val = $params['attr_val'] ?? '';

        $is_price = in_array($action, [
            'set_regular_price', 'set_sale_price',
            'increase_price_percent', 'decrease_price_percent',
            'remove_sale_price', 'sale_from_regular_percent',
        ], true);
        $is_taxonomy = ($action === 'taxonomy');
        $is_shipping = in_array($action, ['set_weight', 'set_length', 'set_width', 'set_height'], true);
        $has_attr_filter = ($attr_key !== '' && $attr_val !== '');

        $processed = 0;
        $errors    = [];
        $synced    = [];

        foreach ($parent_ids as $pid) {
            try {
                // Taxonomy assignment is post-level — categories, tags and brands
                // live on the parent product, never on individual variations.
                if ($is_taxonomy) {
                    $target_id = $pid;
                    $maybe = wc_get_product($pid);
                    if ($maybe && $maybe->is_type('variation')) {
                        $target_id = $maybe->get_parent_id();
                    }
                    $this->apply_taxonomy_action($target_id, $params);
                    $processed++;
                    continue;
                }

                $product = wc_get_product($pid);
                if (!$product) continue;

                // Weight/dimensions are stored on the product post (the parent
                // default for variable products); variations inherit when their
                // own value is empty, so we set the parent and leave overrides
                // intact.
                if ($is_shipping) {
                    $this->apply_bulk_action($product, $action, $value);
                    $product->save();
                    $processed++;
                    continue;
                }

                if ($product->get_type() === 'variable') {
                    if ($is_price || $has_attr_filter) {
                        foreach ($product->get_children() as $vid) {
                            $v = wc_get_product($vid);
                            if (!$v) continue;
                            if ($has_attr_filter) {
                                $match = false;
                                foreach ($v->get_attributes() as $k => $vl) {
                                    if ($k === $attr_key && $vl === $attr_val) { $match = true; break; }
                                }
                                if (!$match) continue;
                            }
                            $this->apply_bulk_action($v, $action, $value);
                            $v->save();
                            $processed++;
                        }
                        $synced[$pid] = true;
                    } else {
                        if ($product->get_manage_stock()) {
                            $this->apply_bulk_action($product, $action, $value);
                            $product->save();
                            $processed++;
                        } else {
                            foreach ($product->get_children() as $vid) {
                                $v = wc_get_product($vid);
                                if (!$v) continue;
                                $this->apply_bulk_action($v, $action, $value);
                                $v->save();
                                $processed++;
                            }
                            $synced[$pid] = true;
                        }
                    }
                } else {
                    if ($has_attr_filter) continue;
                    $this->apply_bulk_action($product, $action, $value);
                    $product->save();
                    $processed++;
                    if ($product->is_type('variation')) {
                        $synced[$product->get_parent_id()] = true;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = ['id' => $pid, 'message' => $e->getMessage()];
            }
        }

        // Sync variable parents touched in this batch.
        foreach (array_keys($synced) as $parent_id) {
            try {
                WC_Product_Variable::sync($parent_id);
            } catch (\Throwable $e) {
                $errors[] = ['id' => $parent_id, 'message' => $e->getMessage()];
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    /**
     * Fast-mode delete: direct SQL DELETE across product tables.
     * Bypasses WordPress/WooCommerce hooks — third-party plugins
     * (SEO, search, cache, analytics) will not be notified. Image
     * files are NOT removed from the filesystem. Much faster than
     * wp_delete_post for very large stores.
     */
    private function process_delete_batch_fast(array $parent_ids) {
        global $wpdb;

        $parent_ids = array_values(array_filter(array_map('intval', $parent_ids)));
        if (empty($parent_ids)) {
            return ['processed' => 0, 'errors' => []];
        }

        $parent_list = implode(',', $parent_ids);

        // Expand variations (children of variable parents).
        $variation_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product_variation'
             AND post_parent IN ($parent_list)"
        );
        $variation_ids = array_map('intval', $variation_ids);

        $all_ids  = array_merge($parent_ids, $variation_ids);
        $all_list = implode(',', $all_ids);

        $errors = [];
        $run    = function ($sql) use ($wpdb, &$errors) {
            $ok = $wpdb->query($sql);
            if ($ok === false && $wpdb->last_error) {
                $errors[] = ['id' => 0, 'message' => $wpdb->last_error];
            }
        };

        // Core WP tables.
        $run("DELETE FROM {$wpdb->posts} WHERE ID IN ($all_list)");
        $run("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($all_list)");
        $run("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($all_list)");

        // Product reviews (comments) + their meta.
        $run("DELETE cm FROM {$wpdb->commentmeta} cm
              INNER JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID
              WHERE c.comment_post_ID IN ($all_list)");
        $run("DELETE FROM {$wpdb->comments} WHERE comment_post_ID IN ($all_list)");

        // WooCommerce lookup tables (guarded by existence checks).
        $meta_lookup = $wpdb->prefix . 'wc_product_meta_lookup';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $meta_lookup)) === $meta_lookup) {
            $run("DELETE FROM {$meta_lookup} WHERE product_id IN ($all_list)");
        }
        $attr_lookup = $wpdb->prefix . 'wc_product_attributes_lookup';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $attr_lookup)) === $attr_lookup) {
            $run("DELETE FROM {$attr_lookup}
                  WHERE product_id IN ($all_list)
                  OR product_or_parent_id IN ($all_list)");
        }

        return ['processed' => count($parent_ids), 'errors' => $errors];
    }

    /**
     * Wipe product taxonomies the user opted into when the delete job
     * finishes. Each flag is independent. Empty terms or missing
     * taxonomies are skipped silently.
     */
    private function purge_taxonomies(array $params) {
        if (!empty($params['delete_cats'])) {
            $this->purge_taxonomy_terms('product_cat');
        }
        if (!empty($params['delete_tags'])) {
            $this->purge_taxonomy_terms('product_tag');
        }
        if (!empty($params['delete_brands'])) {
            $brand_taxonomies = ['product_brand', 'pwb-brand', 'yith_product_brand', 'pa_brand'];
            foreach ($brand_taxonomies as $tax) {
                if (taxonomy_exists($tax)) {
                    $this->purge_taxonomy_terms($tax);
                }
            }
        }
        if (!empty($params['delete_attrs'])) {
            if (function_exists('wc_get_attribute_taxonomies')) {
                $attrs = wc_get_attribute_taxonomies();
                foreach ($attrs as $attr) {
                    $tax = function_exists('wc_attribute_taxonomy_name')
                        ? wc_attribute_taxonomy_name($attr->attribute_name)
                        : 'pa_' . $attr->attribute_name;
                    if (taxonomy_exists($tax)) {
                        $this->purge_taxonomy_terms($tax);
                    }
                    if (function_exists('wc_delete_attribute')) {
                        wc_delete_attribute((int) $attr->attribute_id);
                    }
                }
            }
        }
    }

    /**
     * Delete every term inside a taxonomy using WP's wp_delete_term,
     * so hooks and term_relationships cleanup still fire. Used by
     * purge_taxonomies() — one-shot call at job completion.
     */
    private function purge_taxonomy_terms($taxonomy) {
        if (!taxonomy_exists($taxonomy)) {
            return;
        }
        $term_ids = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'fields'     => 'ids',
        ]);
        if (is_wp_error($term_ids) || empty($term_ids)) {
            return;
        }
        foreach ($term_ids as $tid) {
            wp_delete_term((int) $tid, $taxonomy);
        }
    }

    /**
     * Run once after a fast-delete job finishes — recompute term counts
     * for product taxonomies (direct SQL skipped the normal hooks).
     */
    private function fast_delete_finalize() {
        $taxonomies = ['product_cat', 'product_tag'];
        foreach ($taxonomies as $tax) {
            if (!taxonomy_exists($tax)) continue;
            $term_ids = get_terms([
                'taxonomy'   => $tax,
                'hide_empty' => false,
                'fields'     => 'ids',
            ]);
            if (!empty($term_ids) && !is_wp_error($term_ids)) {
                wp_update_term_count_now($term_ids, $tax);
            }
        }
        wp_cache_flush();
    }

    /**
     * Per-batch worker for bulk delete (trash or permanent).
     * Extracted from the old ajax_bulk_delete() loop.
     */
    private function process_delete_batch(array $parent_ids, array $params) {
        $permanent   = !empty($params['permanent']);
        $delete_imgs = !empty($params['delete_images']);

        $processed = 0;
        $errors    = [];

        foreach ($parent_ids as $pid) {
            try {
                if (!current_user_can('delete_post', $pid)) continue;

                if ($delete_imgs) {
                    $thumb_id = get_post_thumbnail_id($pid);
                    if ($thumb_id) wp_delete_attachment($thumb_id, true);
                    $gallery = get_post_meta($pid, '_product_image_gallery', true);
                    if ($gallery) {
                        foreach (explode(',', $gallery) as $att_id) {
                            $att_id = (int) $att_id;
                            if ($att_id > 0) wp_delete_attachment($att_id, true);
                        }
                    }
                }

                if ($permanent) {
                    $product = wc_get_product($pid);
                    if ($product && $product->get_type() === 'variable') {
                        foreach ($product->get_children() as $vid) {
                            if ($delete_imgs) {
                                $vimg = get_post_thumbnail_id($vid);
                                if ($vimg) wp_delete_attachment($vimg, true);
                            }
                            wp_delete_post($vid, true);
                        }
                    }
                    wp_delete_post($pid, true);
                } else {
                    wp_trash_post($pid);
                }
                $processed++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $pid, 'message' => $e->getMessage()];
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    private function apply_bulk_action(&$product, $action, $value) {
        switch ($action) {
            case 'set_regular_price':
                $product->set_regular_price(wc_format_decimal($value));
                break;
            case 'set_sale_price':
                $product->set_sale_price($value === '' ? '' : wc_format_decimal($value));
                break;
            case 'increase_price_percent':
                $rp = (float) $product->get_regular_price();
                if ($rp > 0) $product->set_regular_price(round($rp * (1 + floatval($value) / 100), 2));
                $sp = (float) $product->get_sale_price();
                if ($sp > 0) $product->set_sale_price(round($sp * (1 + floatval($value) / 100), 2));
                break;
            case 'decrease_price_percent':
                $rp = (float) $product->get_regular_price();
                if ($rp > 0) $product->set_regular_price(round($rp * (1 - floatval($value) / 100), 2));
                $sp = (float) $product->get_sale_price();
                if ($sp > 0) $product->set_sale_price(round($sp * (1 - floatval($value) / 100), 2));
                break;
            case 'sale_from_regular_percent':
                $rp = (float) $product->get_regular_price();
                if ($rp > 0) {
                    $product->set_sale_price(round($rp * (1 - floatval($value) / 100), 2));
                }
                break;
            case 'set_stock':
                $product->set_manage_stock(true);
                $product->set_stock_quantity(intval($value));
                break;
            case 'increase_stock':
                $product->set_manage_stock(true);
                $product->set_stock_quantity((int) $product->get_stock_quantity() + intval($value));
                break;
            case 'remove_sale_price':
                $product->set_sale_price('');
                break;
            case 'set_weight':
                $product->set_weight($value === '' ? '' : wc_format_decimal($value));
                break;
            case 'set_length':
                $product->set_length($value === '' ? '' : wc_format_decimal($value));
                break;
            case 'set_width':
                $product->set_width($value === '' ? '' : wc_format_decimal($value));
                break;
            case 'set_height':
                $product->set_height($value === '' ? '' : wc_format_decimal($value));
                break;
        }
    }

    /**
     * Assigns (replace or append) taxonomy terms to a single product.
     * Used by the bulk update job for categories, tags and brands. Empty
     * term sets are allowed for "replace" so a bulk action can clear terms.
     */
    private function apply_taxonomy_action($product_id, array $params) {
        $taxonomy = $params['taxonomy'] ?? '';
        $term_ids = isset($params['term_ids']) ? array_map('intval', (array) $params['term_ids']) : [];
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return;
        }
        $append = (($params['tax_op'] ?? 'set') === 'add');
        wp_set_object_terms((int) $product_id, $term_ids, $taxonomy, $append);
    }

    // =========================================================================
    // AJAX: GET VARIATIONS (for inline/drawer editing)
    // =========================================================================

    public function ajax_get_variations() {
        check_ajax_referer('brikpanel_products_list_nonce', 'security');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        $product    = wc_get_product($product_id);

        if (!$product || $product->get_type() !== 'variable') {
            wp_send_json_error(['message' => __('Not a variable product.', 'brikpanel')]);
        }

        $variations = [];
        foreach ($product->get_children() as $var_id) {
            $v = wc_get_product($var_id);
            if (!$v || !$v->exists()) continue;

            $attrs = $v->get_attributes();
            $parts = [];
            foreach ($attrs as $key => $val) {
                if ($val === '') { $parts[] = __('Any', 'brikpanel'); continue; }
                $tax = str_starts_with($key, 'pa_') ? $key : 'pa_' . $key;
                if (taxonomy_exists($tax)) {
                    $term = get_term_by('slug', $val, $tax);
                    $parts[] = $term ? html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $val;
                } else {
                    $parts[] = $val;
                }
            }

            $variations[] = [
                'id'            => $v->get_id(),
                'name'          => implode(' / ', $parts) ?: ('#' . $v->get_id()),
                'sku'           => $v->get_sku() ?? '',
                'regular_price' => $v->get_regular_price(),
                'sale_price'    => $v->get_sale_price(),
                'stock'         => $v->get_manage_stock() ? $v->get_stock_quantity() : null,
                'stock_status'  => $v->get_stock_status() ?: 'instock',
                'manage_stock'  => $v->get_manage_stock(),
                'sale_from'     => $v->get_date_on_sale_from() ? $v->get_date_on_sale_from()->date('Y-m-d') : '',
                'sale_to'       => $v->get_date_on_sale_to()   ? $v->get_date_on_sale_to()->date('Y-m-d')   : '',
                // Always emit as a JSON string. WC's get_cogs_value() returns
                // a float (e.g. 0.0 for a saved zero cost); without the cast
                // the JSON ends up `cogs_value: 0` and the drawer's input
                // populator collapses falsy numbers to empty, which made a
                // saved "0" cost look unsaved on reopen.
                'cogs_value'    => (string) (method_exists($v, 'get_cogs_value')
                    ? ($v->get_cogs_value() ?? get_post_meta($v->get_id(), '_brikpanel_cogs', true))
                    : get_post_meta($v->get_id(), '_brikpanel_cogs', true)),
            ];
        }

        wp_send_json_success(['variations' => $variations]);
    }

    // =========================================================================
    // AJAX: SAVE SINGLE VARIATION
    // =========================================================================

    public function ajax_save_variation() {
        check_ajax_referer('brikpanel_products_list_nonce', 'security');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $var_id = intval($_POST['variation_id'] ?? 0);
        $v = wc_get_product($var_id);

        if (!$v || !$v->is_type('variation')) {
            wp_send_json_error(['message' => __('Variation not found.', 'brikpanel')]);
        }

        if (isset($_POST['regular_price'])) {
            $v->set_regular_price(wc_format_decimal(sanitize_text_field($_POST['regular_price'])));
        }
        if (isset($_POST['sale_price'])) {
            $val = sanitize_text_field($_POST['sale_price']);
            $v->set_sale_price($val !== '' ? wc_format_decimal($val) : '');
        }

        // Sale schedule dates (YYYY-MM-DD). Empty / invalid clears the date.
        if (isset($_POST['sale_from'])) {
            $raw = sanitize_text_field($_POST['sale_from']);
            $v->set_date_on_sale_from(preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : null);
        }
        if (isset($_POST['sale_to'])) {
            $raw = sanitize_text_field($_POST['sale_to']);
            $v->set_date_on_sale_to(preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : null);
        }

        $has_stock_qty = isset($_POST['stock']) && $_POST['stock'] !== '';
        if ($has_stock_qty) {
            $v->set_manage_stock(true);
            $v->set_stock_quantity(intval($_POST['stock']));
            if (!isset($_POST['stock_status'])) {
                // Legacy inline edits that only touch quantity still auto-derive.
                $v->set_stock_status(intval($_POST['stock']) > 0 ? 'instock' : 'outofstock');
            }
        }
        if (isset($_POST['stock_status'])) {
            $requested_status = sanitize_key($_POST['stock_status']);
            if (in_array($requested_status, ['instock', 'outofstock', 'onbackorder'], true)) {
                $v->set_stock_status($requested_status);
                $v->set_backorders($requested_status === 'onbackorder' ? 'yes' : 'no');
            }
        }

        // Per-variation COGS — mirrors the simple-product handler. Saved to
        // _brikpanel_cogs for BrikPanel reports and to WC native COGS when
        // available. Only touched when the drawer sent the field; absence
        // here means the COGS column was hidden in the drawer and we must
        // not wipe the existing cost.
        if (array_key_exists('cogs_value', $_POST)) {
            $cogs_raw     = sanitize_text_field($_POST['cogs_value']);
            $cogs_decimal = $cogs_raw !== '' ? wc_format_decimal($cogs_raw) : '';
            if ($cogs_decimal !== '') {
                update_post_meta($var_id, '_brikpanel_cogs', $cogs_decimal);
            } else {
                delete_post_meta($var_id, '_brikpanel_cogs');
            }
            if (method_exists($v, 'set_cogs_value')) {
                $v->set_cogs_value($cogs_decimal !== '' ? $cogs_decimal : null);
            }
        }

        $v->save();

        // WC core's validate_props() forcibly resets stock_status to "instock"
        // when stock > 0 during save(). When the caller explicitly requested a
        // different status, re-apply it via direct meta write so the override
        // survives. (Same pattern we use for simple products.)
        if (isset($_POST['stock_status'])) {
            $requested_status = sanitize_key($_POST['stock_status']);
            if (in_array($requested_status, ['instock', 'outofstock', 'onbackorder'], true)
                && get_post_meta($var_id, '_stock_status', true) !== $requested_status) {
                update_post_meta($var_id, '_stock_status', $requested_status);
                wp_cache_delete('product-' . $var_id, 'products');
                clean_post_cache($var_id);
                do_action('woocommerce_product_set_stock_status', $var_id, $requested_status, wc_get_product($var_id));
            }
        }

        WC_Product_Variable::sync($v->get_parent_id());

        $fresh = wc_get_product($var_id);
        wp_send_json_success([
            'message'   => __('Variation updated!', 'brikpanel'),
            'variation' => [
                'id'            => $var_id,
                'regular_price' => $fresh->get_regular_price(),
                'sale_price'    => $fresh->get_sale_price(),
                'stock'         => $fresh->get_manage_stock() ? $fresh->get_stock_quantity() : null,
                'stock_status'  => $fresh->get_stock_status(),
                'sale_from'     => $fresh->get_date_on_sale_from() ? $fresh->get_date_on_sale_from()->date('Y-m-d') : '',
                'sale_to'       => $fresh->get_date_on_sale_to()   ? $fresh->get_date_on_sale_to()->date('Y-m-d')   : '',
            ],
        ]);
    }

    // =========================================================================
    // AJAX: GET VARIATION ATTRIBUTES (for bulk filter)
    // =========================================================================

    public function ajax_get_variation_attributes() {
        check_ajax_referer('brikpanel_products_list_nonce', 'security');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $cat_id = intval($_POST['category'] ?? 0);

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];
        if ($cat_id > 0) {
            $args['tax_query'] = [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id]];
        }

        $ids = get_posts($args);
        $attr_map = [];

        foreach ($ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product || $product->get_type() !== 'variable') continue;
            foreach ($product->get_children() as $var_id) {
                $v = wc_get_product($var_id);
                if (!$v) continue;
                foreach ($v->get_attributes() as $key => $val) {
                    if ($val === '') continue;
                    $tax = str_starts_with($key, 'pa_') ? $key : 'pa_' . $key;
                    $label = wc_attribute_label($key);
                    if (!$label || $label === $key) {
                        $label = ucfirst(str_replace(['pa_', '-', '_'], ['', ' ', ' '], $key));
                    }
                    if (taxonomy_exists($tax)) {
                        $term = get_term_by('slug', $val, $tax);
                        $display = $term ? html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $val;
                    } else {
                        $display = $val;
                    }
                    $attr_map[$key]['label'] = $label;
                    $attr_map[$key]['values'][$val] = $display;
                }
            }
        }

        $result = [];
        foreach ($attr_map as $key => $data) {
            $vals = [];
            foreach ($data['values'] as $slug => $name) {
                $vals[] = ['slug' => $slug, 'name' => $name];
            }
            usort($vals, function ($a, $b) { return strcmp($a['name'], $b['name']); });
            $result[] = ['key' => $key, 'label' => $data['label'], 'values' => $vals];
        }

        wp_send_json_success($result);
    }
}

new Brikpanel_Products_List();
