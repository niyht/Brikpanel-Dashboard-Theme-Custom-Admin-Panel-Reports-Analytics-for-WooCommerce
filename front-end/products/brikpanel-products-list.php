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
        add_action('wp_ajax_brikpanel_bulk_update_products', [$this, 'ajax_bulk_update']);
        add_action('wp_ajax_brikpanel_get_variation_attributes', [$this, 'ajax_get_variation_attributes']);
        add_action('wp_ajax_brikpanel_get_variations', [$this, 'ajax_get_variations']);
        add_action('wp_ajax_brikpanel_save_variation', [$this, 'ajax_save_variation']);
        add_action('wp_ajax_brikpanel_bulk_delete_products', [$this, 'ajax_bulk_delete']);
    }

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
    // RENDER PAGE
    // =========================================================================

    public function render_page() {
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name']);
        if (is_wp_error($categories)) {
            $categories = [];
        }

        $currency = get_woocommerce_currency_symbol();

        // Count products by status
        $counts = wp_count_posts('product');
        $total     = isset($counts->publish) ? (int) $counts->publish : 0;
        $draft     = isset($counts->draft) ? (int) $counts->draft : 0;
        $trash     = isset($counts->trash) ? (int) $counts->trash : 0;
        $all_count = $total + $draft;
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
                    <select id="bpl-sort" class="brikpanel-pl-select">
                        <option value="date-desc"><?php esc_html_e('Newest first', 'brikpanel'); ?></option>
                        <option value="date-asc"><?php esc_html_e('Oldest first', 'brikpanel'); ?></option>
                        <option value="title-asc"><?php esc_html_e('Name A-Z', 'brikpanel'); ?></option>
                        <option value="title-desc"><?php esc_html_e('Name Z-A', 'brikpanel'); ?></option>
                        <option value="price-asc"><?php esc_html_e('Price low-high', 'brikpanel'); ?></option>
                        <option value="price-desc"><?php esc_html_e('Price high-low', 'brikpanel'); ?></option>
                    </select>
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
                    <button type="button" class="brikpanel-pl-btn secondary small" id="bpl-bulk-publish"><?php esc_html_e('Publish', 'brikpanel'); ?></button>
                    <button type="button" class="brikpanel-pl-btn secondary small" id="bpl-bulk-draft"><?php esc_html_e('Set as draft', 'brikpanel'); ?></button>
                    <button type="button" class="brikpanel-pl-btn danger small" id="bpl-bulk-trash"><?php esc_html_e('Move to trash', 'brikpanel'); ?></button>
                </div>
            </div>

            <!-- Products Table -->
            <div class="brikpanel-pl-card">
                <div class="brikpanel-pl-table-wrap">
                    <table class="brikpanel-pl-table" id="bpl-table">
                        <thead>
                            <tr>
                                <th class="brikpanel-pl-th-check">
                                    <input type="checkbox" id="bpl-check-all" class="brikpanel-pl-checkbox">
                                </th>
                                <th class="brikpanel-pl-th-image"></th>
                                <th class="brikpanel-pl-th-name"><?php esc_html_e('Product', 'brikpanel'); ?></th>
                                <th class="brikpanel-pl-th-sku"><?php esc_html_e('SKU', 'brikpanel'); ?></th>
                                <th class="brikpanel-pl-th-price"><?php esc_html_e('Price', 'brikpanel'); ?></th>
                                <th class="brikpanel-pl-th-stock"><?php esc_html_e('Stock', 'brikpanel'); ?></th>
                                <th class="brikpanel-pl-th-cat"><?php esc_html_e('Category', 'brikpanel'); ?></th>
                                <th class="brikpanel-pl-th-status"><?php esc_html_e('Status', 'brikpanel'); ?></th>
                                <th class="brikpanel-pl-th-actions"></th>
                            </tr>
                        </thead>
                        <tbody id="bpl-table-body">
                            <tr class="brikpanel-pl-loading-row">
                                <td colspan="9">
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
            <div class="brikpanel-pl-drawer-overlay" id="bpl-drawer-overlay"></div>
            <div class="brikpanel-pl-drawer" id="bpl-drawer">
                <div class="brikpanel-pl-drawer-header">
                    <h3><?php esc_html_e('Quick edit', 'brikpanel'); ?></h3>
                    <button type="button" class="brikpanel-pl-drawer-close" id="bpl-drawer-close">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="brikpanel-pl-drawer-body">
                    <input type="hidden" id="bpl-qe-id">
                    <div class="brikpanel-pl-qe-field">
                        <label for="bpl-qe-name"><?php esc_html_e('Product name', 'brikpanel'); ?></label>
                        <input type="text" id="bpl-qe-name">
                    </div>
                    <!-- Simple product fields -->
                    <div id="bpl-qe-simple-fields">
                        <div class="brikpanel-pl-qe-row">
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
                        <div class="brikpanel-pl-qe-row">
                            <div class="brikpanel-pl-qe-field">
                                <label for="bpl-qe-stock"><?php esc_html_e('Stock', 'brikpanel'); ?></label>
                                <input type="number" id="bpl-qe-stock" min="0">
                            </div>
                            <div class="brikpanel-pl-qe-field">
                                <label for="bpl-qe-sku"><?php esc_html_e('SKU', 'brikpanel'); ?></label>
                                <input type="text" id="bpl-qe-sku">
                            </div>
                        </div>
                    </div>
                    <!-- Variations (loaded via AJAX for variable products) -->
                    <div id="bpl-qe-variations" style="display:none;"></div>
                    <div class="brikpanel-pl-qe-row">
                        <div class="brikpanel-pl-qe-field">
                            <label for="bpl-qe-status"><?php esc_html_e('Status', 'brikpanel'); ?></label>
                            <select id="bpl-qe-status" class="brikpanel-pl-select">
                                <option value="publish"><?php esc_html_e('Published', 'brikpanel'); ?></option>
                                <option value="draft"><?php esc_html_e('Draft', 'brikpanel'); ?></option>
                            </select>
                        </div>
                        <div class="brikpanel-pl-qe-field">
                            <label for="bpl-qe-cat"><?php esc_html_e('Category', 'brikpanel'); ?></label>
                            <select id="bpl-qe-cat" class="brikpanel-pl-select">
                                <option value=""><?php esc_html_e('— None —', 'brikpanel'); ?></option>
                                <?php foreach ($categories as $cat) : ?>
                                    <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
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
                            <button class="brikpanel-pl-modal-tab active" data-tab="bpl-bulk-tab-cat"><?php esc_html_e('By category', 'brikpanel'); ?></button>
                            <button class="brikpanel-pl-modal-tab" data-tab="bpl-bulk-tab-sel"><?php esc_html_e('Selected products', 'brikpanel'); ?></button>
                            <button class="brikpanel-pl-modal-tab brikpanel-pl-modal-tab-danger" data-tab="bpl-bulk-tab-del"><?php esc_html_e('Bulk delete', 'brikpanel'); ?></button>
                        </div>

                        <!-- Tab: By Category -->
                        <div class="brikpanel-pl-modal-tab-content active" id="bpl-bulk-tab-cat">
                            <div class="brikpanel-pl-modal-field">
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
                                    </select>
                                </div>
                                <div class="brikpanel-pl-modal-field" style="width:100px;">
                                    <label><?php esc_html_e('Value', 'brikpanel'); ?></label>
                                    <input type="text" id="bpl-bulk-value-cat" placeholder="0">
                                </div>
                            </div>
                            <!-- Variation attribute filter -->
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

                        <!-- Tab: Selected Products -->
                        <div class="brikpanel-pl-modal-tab-content" id="bpl-bulk-tab-sel">
                            <p class="brikpanel-pl-modal-info" id="bpl-bulk-sel-info"></p>
                            <div class="brikpanel-pl-modal-row">
                                <div class="brikpanel-pl-modal-field grow">
                                    <label><?php esc_html_e('Action', 'brikpanel'); ?></label>
                                    <select id="bpl-bulk-action-sel" class="brikpanel-pl-select full">
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
                                    </select>
                                </div>
                                <div class="brikpanel-pl-modal-field" style="width:100px;">
                                    <label><?php esc_html_e('Value', 'brikpanel'); ?></label>
                                    <input type="text" id="bpl-bulk-value-sel" placeholder="0">
                                </div>
                            </div>
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
                            <p class="brikpanel-pl-modal-info" id="bpl-del-sel-info" style="display:none;"></p>
                        </div>

                    </div>
                    <div class="brikpanel-pl-modal-footer">
                        <button type="button" class="brikpanel-pl-btn secondary" id="bpl-bulk-modal-cancel"><?php esc_html_e('Cancel', 'brikpanel'); ?></button>
                        <button type="button" class="brikpanel-pl-btn primary" id="bpl-bulk-modal-apply"><?php esc_html_e('Apply', 'brikpanel'); ?></button>
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
        $sort     = sanitize_text_field($_POST['sort'] ?? 'date-desc');

        // Parse sort
        $sort_parts = explode('-', $sort);
        $orderby = $sort_parts[0] ?? 'date';
        $order   = strtoupper($sort_parts[1] ?? 'DESC');

        if (!in_array($orderby, ['date', 'title', 'price'], true)) {
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
        } elseif ($status === 'trash') {
            $statuses = ['trash'];
        } else {
            $statuses = ['publish', 'draft'];
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
        }

        if ($search) {
            $args['s'] = $search;
        }

        if ($category > 0) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $category,
                ],
            ];
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
        $products = [];

        foreach ($query->posts as $post) {
            $product = wc_get_product($post->ID);
            if (!$product) continue;

            $image_id  = $product->get_image_id();
            $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');

            $stock_qty = null;
            if ($product->is_type('variable')) {
                $has_managed = false;
                $stock_qty = 0;
                foreach ($product->get_children() as $cid) {
                    $v = wc_get_product($cid);
                    if ($v && $v->get_manage_stock()) {
                        $has_managed = true;
                        $stock_qty += (int) $v->get_stock_quantity();
                    }
                }
                if (!$has_managed) {
                    $stock_qty = null;
                }
            } else {
                $stock_qty = $product->get_manage_stock() ? $product->get_stock_quantity() : null;
            }

            $cats = wp_get_post_terms($post->ID, 'product_cat', ['fields' => 'all']);
            $cat_names = [];
            $cat_ids   = [];
            if (!is_wp_error($cats)) {
                foreach ($cats as $cat) {
                    $cat_names[] = $cat->name;
                    $cat_ids[]   = $cat->term_id;
                }
            }

            $products[] = [
                'id'            => $post->ID,
                'name'          => $product->get_name() ?? '',
                'sku'           => $product->get_sku() ?? '',
                'regular_price' => $product->get_regular_price(),
                'sale_price'    => $product->get_sale_price(),
                'price_html'    => $product->get_price_html(),
                'stock'         => $stock_qty,
                'stock_status'  => $product->get_stock_status(),
                'status'        => $post->post_status,
                'image'         => $image_url,
                'categories'    => $cat_names,
                'category_ids'  => $cat_ids,
                'type'          => $product->get_type(),
                'edit_url'      => admin_url('admin.php?page=brikpanel-product-editor&product_id=' . $post->ID),
                'view_url'      => get_permalink($post->ID),
            ];
        }

        // Refresh counts
        $counts = wp_count_posts('product');
        $publish_count = isset($counts->publish) ? (int) $counts->publish : 0;
        $draft_count   = isset($counts->draft) ? (int) $counts->draft : 0;
        $trash_count   = isset($counts->trash) ? (int) $counts->trash : 0;

        wp_send_json_success([
            'products'   => $products,
            'total'      => (int) $query->found_posts,
            'pages'      => (int) $query->max_num_pages,
            'page'       => $page,
            'counts'     => [
                'all'     => $publish_count + $draft_count,
                'publish' => $publish_count,
                'draft'   => $draft_count,
                'trash'   => $trash_count,
            ],
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

        if (isset($_POST['stock'])) {
            $stock = intval($_POST['stock']);
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock);
            $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
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
            if (in_array($status, ['publish', 'draft'], true)) {
                wp_update_post(['ID' => $product_id, 'post_status' => $status]);
            }
        }

        if (isset($_POST['category_id'])) {
            $cat_id = intval($_POST['category_id']);
            if ($cat_id > 0) {
                wp_set_object_terms($product_id, [$cat_id], 'product_cat');
            } else {
                wp_set_object_terms($product_id, [], 'product_cat');
            }
        }

        $product->save();

        // Return updated product data
        $product = wc_get_product($product_id);
        $image_id  = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');

        $cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'all']);
        $cat_names = [];
        $cat_ids   = [];
        if (!is_wp_error($cats)) {
            foreach ($cats as $cat) {
                $cat_names[] = $cat->name;
                $cat_ids[]   = $cat->term_id;
            }
        }

        wp_send_json_success([
            'message' => __('Product updated!', 'brikpanel'),
            'product' => [
                'id'            => $product_id,
                'name'          => $product->get_name() ?? '',
                'sku'           => $product->get_sku() ?? '',
                'regular_price' => $product->get_regular_price(),
                'sale_price'    => $product->get_sale_price(),
                'price_html'    => $product->get_price_html(),
                'stock'         => $product->get_manage_stock() ? $product->get_stock_quantity() : null,
                'stock_status'  => $product->get_stock_status(),
                'status'        => get_post_status($product_id),
                'image'         => $image_url,
                'categories'    => $cat_names,
                'category_ids'  => $cat_ids,
                'type'          => $product->get_type(),
                'edit_url'      => admin_url('admin.php?page=brikpanel-product-editor&product_id=' . $product_id),
                'view_url'      => get_permalink($product_id),
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

        if ($status === 'trash') {
            wp_delete_post($product_id, true);
            $message = __('Product permanently deleted.', 'brikpanel');
        } else {
            wp_trash_post($product_id);
            $message = __('Product moved to trash.', 'brikpanel');
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
    // =========================================================================
    // AJAX: BULK UPDATE (price/stock operations)
    // =========================================================================

    public function ajax_bulk_update() {
        check_ajax_referer('brikpanel_products_list_nonce', 'security');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $mode      = sanitize_key($_POST['mode'] ?? 'selected');
        $action    = sanitize_key($_POST['bulk_action'] ?? '');
        $value     = sanitize_text_field($_POST['value'] ?? '');
        $cat_id    = intval($_POST['category'] ?? 0);
        $attr_key  = sanitize_text_field($_POST['attr_key'] ?? '');
        $attr_val  = sanitize_text_field($_POST['attr_val'] ?? '');

        $allowed = ['set_regular_price', 'set_sale_price', 'increase_price_percent', 'decrease_price_percent', 'sale_from_regular_percent', 'set_stock', 'increase_stock', 'remove_sale_price'];
        if (!in_array($action, $allowed, true)) {
            wp_send_json_error(['message' => __('Invalid action.', 'brikpanel')]);
        }

        // Get product IDs
        $ids = [];
        if ($mode === 'selected') {
            $raw = sanitize_text_field($_POST['selected_ids'] ?? '');
            $ids = array_filter(array_map('intval', explode(',', $raw)));
            if (empty($ids)) {
                wp_send_json_error(['message' => __('No products selected.', 'brikpanel')]);
            }
        } elseif ($mode === 'category') {
            if ($cat_id < 1) {
                wp_send_json_error(['message' => __('Please select a category.', 'brikpanel')]);
            }
            $ids = get_posts([
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'tax_query'      => [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id]],
            ]);
        }

        if (empty($ids)) {
            wp_send_json_error(['message' => __('No products found.', 'brikpanel')]);
        }

        $is_price      = in_array($action, ['set_regular_price', 'set_sale_price', 'increase_price_percent', 'decrease_price_percent', 'remove_sale_price', 'sale_from_regular_percent'], true);
        $has_attr_filter = ($attr_key !== '' && $attr_val !== '');
        $count  = 0;
        $synced = [];

        foreach ($ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) continue;

            if ($product->get_type() === 'variable') {
                if ($is_price || $has_attr_filter) {
                    foreach ($product->get_children() as $vid) {
                        $v = wc_get_product($vid);
                        if (!$v) continue;
                        if ($has_attr_filter) {
                            $v_attrs = $v->get_attributes();
                            $match = false;
                            foreach ($v_attrs as $k => $vl) {
                                if ($k === $attr_key && $vl === $attr_val) { $match = true; break; }
                            }
                            if (!$match) continue;
                        }
                        $this->apply_bulk_action($v, $action, $value);
                        $v->save();
                        $count++;
                    }
                    $synced[$pid] = true;
                } else {
                    if ($product->get_manage_stock()) {
                        $this->apply_bulk_action($product, $action, $value);
                        $product->save();
                        $count++;
                    } else {
                        foreach ($product->get_children() as $vid) {
                            $v = wc_get_product($vid);
                            if (!$v) continue;
                            $this->apply_bulk_action($v, $action, $value);
                            $v->save();
                            $count++;
                        }
                    }
                }
            } else {
                if ($has_attr_filter) continue;
                $this->apply_bulk_action($product, $action, $value);
                $product->save();
                $count++;
                if ($product->is_type('variation')) {
                    $synced[$product->get_parent_id()] = true;
                }
            }
        }

        foreach (array_keys($synced) as $pid) {
            WC_Product_Variable::sync($pid);
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: number of products/variations updated */
                __('%d products updated successfully.', 'brikpanel'),
                $count
            ),
            'count' => $count,
        ]);
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
        }
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
                    $parts[] = $term ? $term->name : $val;
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
                'manage_stock'  => $v->get_manage_stock(),
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
        if (isset($_POST['stock'])) {
            $v->set_manage_stock(true);
            $v->set_stock_quantity(intval($_POST['stock']));
            $v->set_stock_status(intval($_POST['stock']) > 0 ? 'instock' : 'outofstock');
        }

        $v->save();
        WC_Product_Variable::sync($v->get_parent_id());

        wp_send_json_success(['message' => __('Variation updated!', 'brikpanel')]);
    }

    // =========================================================================
    // AJAX: BULK DELETE PRODUCTS
    // =========================================================================

    public function ajax_bulk_delete() {
        check_ajax_referer('brikpanel_products_list_nonce', 'security');

        if (!current_user_can('delete_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $mode        = sanitize_key($_POST['mode'] ?? '');
        $cat_id      = intval($_POST['category'] ?? 0);
        $permanent   = ($_POST['permanent'] ?? '0') === '1';
        $delete_imgs = ($_POST['delete_images'] ?? '0') === '1';

        $ids = [];
        if ($mode === 'selected') {
            $raw = sanitize_text_field($_POST['selected_ids'] ?? '');
            $ids = array_filter(array_map('intval', explode(',', $raw)));
        } elseif ($mode === 'category') {
            if ($cat_id < 1) {
                wp_send_json_error(['message' => __('Please select a category.', 'brikpanel')]);
            }
            $ids = get_posts([
                'post_type'      => 'product',
                'post_status'    => ['publish', 'draft', 'pending', 'private', 'trash'],
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'tax_query'      => [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id]],
            ]);
        } elseif ($mode === 'all') {
            $ids = get_posts([
                'post_type'      => 'product',
                'post_status'    => ['publish', 'draft', 'pending', 'private', 'trash'],
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]);
        }

        if (empty($ids)) {
            wp_send_json_error(['message' => __('No products found.', 'brikpanel')]);
        }

        set_time_limit(0);
        $count = 0;

        foreach ($ids as $pid) {
            if (!current_user_can('delete_post', $pid)) continue;

            if ($delete_imgs) {
                $thumb_id = get_post_thumbnail_id($pid);
                if ($thumb_id) wp_delete_attachment($thumb_id, true);
                $gallery = get_post_meta($pid, '_product_image_gallery', true);
                if ($gallery) {
                    foreach (explode(',', $gallery) as $att_id) {
                        wp_delete_attachment((int) $att_id, true);
                    }
                }
            }

            if ($permanent) {
                // Also delete variations
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
            $count++;
        }

        wc_delete_product_transients();

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: number of products deleted */
                $permanent
                    ? __('%d products permanently deleted.', 'brikpanel')
                    : __('%d products moved to trash.', 'brikpanel'),
                $count
            ),
            'count' => $count,
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
                        $display = $term ? $term->name : $val;
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
